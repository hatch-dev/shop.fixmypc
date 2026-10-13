<?php

namespace App\Http\Controllers;

use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Services\ProductUpgradeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * The storefront's view of the upgrade engine.
 *
 * Two endpoints and no logic: everything is answered by
 * ProductUpgradeService, so the page, the basket and the checkout cannot come
 * to three different conclusions about whether an upgrade is available and
 * what it costs.
 *
 * Outside the auth group on purpose, like the product page itself. A guest
 * gets upgrades priced as a guest; an account gets them priced against its own
 * bands, because the service is handed whoever is asking.
 */
class ProductUpgradesController extends Controller
{
    /**
     * The upgrades on offer for one selected variant.
     *
     * The quantity matters and is not decoration: a business account crossing
     * a band boundary changes what both sides of the difference cost, so the
     * answer is only true for the quantity it was asked about.
     */
    public function forVariant(Request $request, $id)
    {
        try {
            $product = Product::where('id', $id)
                ->where('status', Config::get('constants.status.PUBLIC'))
                ->first();

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($request->header('language')));
            }

            $inventory = $this->selectedVariant($request, $product);

            if (is_null($inventory)) {
                // Not an error: a product page that has not settled on a
                // variation yet simply has no upgrades to show.
                return response()->json(new Response($request->token, ['groups' => []]));
            }

            $service = new ProductUpgradeService();

            $groups = $service->forVariant(
                $product, $inventory, Auth::user(), $request->input('quantity', 1)
            );

            return response()->json(new Response($request->token, [
                'product_id' => (int)$product->id,
                'inventory_id' => (int)$inventory->id,
                'quantity' => max(1, (int)$request->input('quantity', 1)),
                'groups' => $groups,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Would this upgrade be accepted?
     *
     * The page calls this before it commits to a switch. It is not the guard
     * -- the cart and the order revalidate the same way, through the same
     * service -- but it is what lets the page refuse in place, with a reason,
     * rather than letting an add-to-cart fail.
     */
    public function validateUpgrade(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            $product = Product::where('id', $id)
                ->where('status', Config::get('constants.status.PUBLIC'))
                ->first();

            if (is_null($product)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $current = $this->selectedVariant($request, $product);

            $service = new ProductUpgradeService();

            $result = $service->validate(
                $product,
                $current,
                $request->input('target_inventory_id'),
                Auth::user(),
                $request->input('quantity', 1)
            );

            if (!$result['ok']) {
                return response()->json(Validation::error(
                    $request->token, self::message($result['reason'], $lang), 'upgrade'
                ));
            }

            return response()->json(new Response($request->token, [
                'valid' => true,
                'option' => $result['option'],
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The variant the caller says they are on, proved to belong to the product.
     *
     * Falls back to the best-stocked one so a page that has not chosen yet
     * still gets a sensible answer rather than nothing.
     */
    private function selectedVariant(Request $request, $product)
    {
        if ($request->filled('inventory_id')) {
            return UpdatedInventory::where('product_id', $product->id)
                ->where('id', (int)$request->input('inventory_id'))
                ->first();
        }

        return UpdatedInventory::where('product_id', $product->id)
            ->orderByDesc('quantity')
            ->first();
    }

    /** A refusal reason, in words a customer can act on. */
    public static function message($reason, $lang = null)
    {
        $keys = [
            ProductUpgradeService::REFUSE_NO_PRODUCT => 'lang.nothing_found',
            ProductUpgradeService::REFUSE_NO_VARIANT => 'lang.upgrade_no_variant',
            ProductUpgradeService::REFUSE_FOREIGN_VARIANT => 'lang.upgrade_no_variant',
            ProductUpgradeService::REFUSE_SAME_VARIANT => 'lang.upgrade_unchanged',
            ProductUpgradeService::REFUSE_NOT_AN_UPGRADE => 'lang.upgrade_not_available',
            ProductUpgradeService::REFUSE_UNAVAILABLE => 'lang.upgrade_out_of_stock',
            ProductUpgradeService::REFUSE_QUANTITY => 'lang.upgrade_quantity',
        ];

        return __($keys[$reason] ?? 'lang.upgrade_not_available', [], $lang);
    }
}
