<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\BusinessProductPricing;
use App\Models\Product;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

/**
 * The quantity bands business and approved-trade accounts buy at.
 *
 * These rows decide what a customer is charged, so the screen that writes them
 * is held to the same standard as the rest of the catalogue: staff need the
 * product permissions to reach it, the bands are validated before they are
 * stored, and the price each band charges is derived here rather than taken
 * from the browser.
 */
class BusinessProductsController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $search = $request->q;
            $orderType = $request->type ?? 'desc';

            switch ($request->orderby) {
                case 'title':
                    $orderBy = 'products.title';
                    break;

                case 'created_at':
                default:
                    $orderBy = 'business_product_pricing.created_at';
                    break;
            }

            $query = BusinessProductPricing::select(
                'business_product_pricing.product_id',
                DB::raw('MIN(business_product_pricing.id) as id'),
                'products.title',
                DB::raw('COUNT(*) as total_ranges')
            )
            ->join('products', 'products.id', '=', 'business_product_pricing.product_id');

            if ($search) {
                $query = $query->where('products.title', 'LIKE', "%{$search}%");
            }

            $query->groupBy('business_product_pricing.product_id', 'products.title');

            $query->orderBy($orderBy, $orderType);

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function find(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.view')) {
                return $can;
            }

            $pricing = BusinessProductPricing::where('product_id', $id)
                ->orderBy('min_qty')
                ->get();

            return response()->json(new Response($request->token, [
                'product_id' => $id,
                'pricing' => $this->present($pricing)
            ]));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.edit')) {
                return $can;
            }

            $productId = $request->product_id;

            if (!$productId || !Product::where('id', $productId)->exists()) {
                return response()->json(Validation::error($request->token,
                    'Choose a product for these prices.'
                ));
            }

            $rows = $request->input('pricing');

            if (!is_array($rows)) {
                $rows = [];
            }

            if ($error = $this->bandError($rows)) {
                return response()->json(Validation::error($request->token, $error));
            }

            /*
             * The ladder is reconciled row by row, and the whole reconcile has
             * to stand or fall together. Without the transaction a failed write
             * left the product with a half-written ladder and the screen
             * reporting success.
             *
             * Rows are matched by quantity order and edited in place rather
             * than dropped and re-inserted. Turning "21-30" into "21 and over"
             * is the commonest edit this screen will see, and it is a change of
             * maximum on one band, not a new band: re-creating the row would
             * reset created_at, which is what the ladder list is sorted by, so
             * every price correction jumped a product to the top of the list.
             */
            DB::transaction(function () use ($id, $productId, $rows) {
                if ($id && $id != $productId) {
                    BusinessProductPricing::where('product_id', $id)->delete();
                }

                $existing = BusinessProductPricing::where('product_id', $productId)
                    ->orderBy('min_qty')
                    ->orderBy('id')
                    ->get()
                    ->values();

                // The screen sends rows in the order they were typed; matching
                // them up by quantity keeps an edited band on its own row.
                $wanted = collect($rows)->sortBy(function ($row) {
                    return (int)$row['min'];
                })->values();

                foreach ($wanted as $position => $row) {
                    $band = $existing->get($position) ?: new BusinessProductPricing();

                    $band->fill([
                        'product_id' => $productId,
                        'min_qty' => (int)$row['min'],
                        // Blank, missing or 0 is "and over"; see
                        // BusinessProductPricing::coversQuantity.
                        'max_qty' => (int)($row['max'] ?? 0),
                        'wholesale_price' => (float)($row['wholesale_price'] ?? 0),
                        'discount_type' => ($row['discount_type'] ?? 'fixed') === 'percentage'
                            ? 'percentage'
                            : 'fixed',
                        'discount_value' => (float)($row['discount_value'] ?? 0),
                    ]);

                    // Never the browser's figure: it is what the customer pays.
                    $band->final_price = $band->computedFinalPrice();

                    $band->save();
                }

                // Bands the admin deleted: everything past the end of the new
                // ladder. Removing a row leaves the rest re-matched above, so
                // this only ever takes the surplus.
                $surplus = $existing->slice($wanted->count())->pluck('id')->all();

                if (count($surplus)) {
                    BusinessProductPricing::whereIn('id', $surplus)->delete();
                }
            });

            $pricing = BusinessProductPricing::where('product_id', $productId)
                ->orderBy('min_qty')
                ->get();

            return response()->json(new Response($request->token, [
                'product_id' => $productId,
                'pricing' => $this->present($pricing)
            ]));

        } catch (\Exception $ex) {

            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            if ($can = Utils::userCan($this->user, 'product.delete')) {
                return $can;
            }

            $ids = explode(",", $id);

            foreach ($ids as $productId) {
                BusinessProductPricing::where('product_id', $productId)->delete();
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(
                Validation::error($request->token, $ex->getMessage())
            );
        }
    }

    /**
     * Why this set of bands cannot be stored, or null when it can.
     *
     * Four structural rules, each of which is a price the shop would otherwise
     * get wrong rather than a matter of taste:
     *
     *   overlap    two rows both covering quantity 10 leave the price
     *              depending on row order. Pricing resolves that by charging
     *              the cheaper, but the shop should not be guessing at all.
     *
     *   open-ended a band with no maximum swallows every quantity above its
     *              minimum, so it can only be the last one. "5 and over"
     *              followed by "11-20" is not two bands, it is one band and a
     *              row that never applies.
     *
     *   one of them  a second open-ended band is the same fault twice over.
     *
     *   gap        21-30 followed by 32-and-over reads as a ladder and behaves
     *              as a cliff: nothing covers 31, and Pricing falls back to
     *              the retail price the moment no band matches, so the 31st
     *              unit costs more than the 30th. That is the defect this
     *              whole feature exists to close, and it is a typo away at
     *              every save, so it is refused here rather than left to
     *              BusinessPricingLadderDataTest to notice afterwards.
     *
     * A gap *below* the first band is deliberate and allowed: a ladder that
     * starts at 5 is saying bulk rates begin at five units, and 1-4 paying
     * retail is the point.
     */
    private function bandError(array $rows)
    {
        $bands = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;

            if (!isset($row['min']) || $row['min'] === '' || (int)$row['min'] < 1) {
                return "Row {$line}: the minimum quantity must be at least 1.";
            }

            $min = (int)$row['min'];
            // 0 is the admin screen's way of writing an open-ended top band.
            $max = (int)($row['max'] ?? 0);

            if ($max > 0 && $max < $min) {
                return "Row {$line}: the maximum quantity is below the minimum.";
            }

            if ((float)($row['wholesale_price'] ?? 0) < 0
                || (float)($row['discount_value'] ?? 0) < 0) {
                return "Row {$line}: prices and discounts cannot be negative.";
            }

            if (($row['discount_type'] ?? 'fixed') === 'percentage'
                && (float)($row['discount_value'] ?? 0) > 100) {
                return "Row {$line}: a percentage discount cannot exceed 100.";
            }

            $bands[] = [
                'line' => $line,
                'min' => $min,
                'max' => $max,
                'open' => $max <= 0,
            ];
        }

        if ($error = $this->openEndedError($bands)) {
            return $error;
        }

        if ($error = $this->overlapError($bands)) {
            return $error;
        }

        return $this->gapError($bands);
    }

    /**
     * At most one open-ended band, and it has to be the highest one.
     *
     * Checked before overlaps because an open-ended band that is not last
     * overlaps everything above it, and "row 2 overlaps row 1" tells an admin
     * nothing about what to do. Naming the actual rule does.
     */
    private function openEndedError(array $bands)
    {
        $open = array_values(array_filter($bands, function ($band) {
            return $band['open'];
        }));

        if (count($open) === 0) {
            return null;
        }

        if (count($open) > 1) {
            return "Rows {$open[0]['line']} and {$open[1]['line']} both have no maximum "
                . 'quantity. Only the final band can be open-ended.';
        }

        $top = $open[0];

        foreach ($bands as $band) {
            if ($band['min'] > $top['min']) {
                return "Row {$top['line']} has no maximum quantity, so it covers every "
                    . "order of {$top['min']} or more and row {$band['line']} could never "
                    . 'apply. An open-ended band must be the final one: give row '
                    . "{$top['line']} a maximum quantity, or remove the bands above it.";
            }
        }

        return null;
    }

    private function overlapError(array $bands)
    {
        foreach ($bands as $i => $band) {
            foreach (array_slice($bands, 0, $i) as $other) {
                $otherMax = $other['open'] ? PHP_INT_MAX : $other['max'];
                $thisMax = $band['open'] ? PHP_INT_MAX : $band['max'];

                if ($band['min'] <= $otherMax && $other['min'] <= $thisMax) {
                    return "Row {$band['line']}: this quantity range overlaps row {$other['line']}.";
                }
            }
        }

        return null;
    }

    /**
     * No quantity between the bottom of the ladder and the top may fall
     * through. Runs on a copy sorted by minimum quantity, because the screen
     * sends rows in the order they were typed, not in quantity order.
     */
    private function gapError(array $bands)
    {
        usort($bands, function ($a, $b) {
            return $a['min'] <=> $b['min'];
        });

        for ($i = 1; $i < count($bands); $i++) {
            $previous = $bands[$i - 1];
            $current = $bands[$i];

            // The previous band is bounded here: an open-ended one is the last
            // band by the time this runs, so it has no successor to gap from.
            $expected = $previous['max'] + 1;

            if ($current['min'] > $expected) {
                $missingTo = $current['min'] - 1;
                $missing = $expected === $missingTo
                    ? "{$expected} unit" . ($expected === 1 ? '' : 's')
                    : "{$expected}-{$missingTo} units";

                return "Row {$current['line']} starts at {$current['min']} but row "
                    . "{$previous['line']} ends at {$previous['max']}, so an order of "
                    . "{$missing} would be charged the full retail price. Bands must "
                    . 'run on from one another without a gap.';
            }
        }

        return null;
    }

    private function present($pricing)
    {
        return $pricing->map(function ($item) {
            return [
                'min' => $item->min_qty,
                'max' => $item->max_qty,
                'wholesale_price' => $item->wholesale_price,
                'discount_type' => $item->discount_type,
                'discount_value' => $item->discount_value,
                'final_price' => $item->final_price,
            ];
        });
    }
}
