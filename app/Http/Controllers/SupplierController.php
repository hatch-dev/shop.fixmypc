<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\PhoneRules;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\SupplierBid;
use App\Models\SupplierNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Procurement -> Suppliers.
 *
 * Who we ask to source a product, and which categories they can source. A
 * supplier is matched to an order line by category: see the procurement block
 * in OrdersController.
 *
 * Every action gates on a supplier.* permission. It used to gate on nothing
 * at all -- the routes sit behind auth:admin and there is no permission
 * middleware in this application, so authorisation is the controller's job
 * and skipping it meant any admin token, vendor included, could read, edit
 * and delete every supplier in the shop.
 */
class SupplierController extends ControllerHelper
{
    /**
     * Only what the screens use: the list prints a comma-joined set of
     * titles and the edit form's multiselect tracks by id. Loading the whole
     * Category model meant every supplier row carried each category's full
     * meta description and keyword list -- kilobytes per row, for a label.
     */
    private function categoryColumns()
    {
        return function ($query) {
            $query->select('categories.id', 'categories.title');
        };
    }

    /** Ordering comes from the query string, so it is whitelisted. */
    const SORTABLE = ['name', 'email', 'phone', 'created_at', 'updated_at'];

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'supplier.view')) {
                return $can;
            }

            $orderby = in_array($request->orderby, self::SORTABLE, true)
                ? $request->orderby
                : 'created_at';

            $orderbyType = strtolower((string)$request->orderbyType) === 'asc' ? 'asc' : 'desc';

            /*
             * Capped. per_page arrived from the client unbounded, so a single
             * request could ask for every supplier and every category of each.
             */
            $perPage = (int)($request->per_page ?? Config::get('constants.api.PAGINATION'));
            $perPage = max(1, min($perPage, 100));

            $search = $request->q ?? null;

            $query = Supplier::with(['categories' => $this->categoryColumns()]);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('categories', function ($catQuery) use ($search) {
                        $catQuery->where('title', 'like', "%{$search}%");
                    });
                });
            }

            // Ties fall back to id so paging cannot swap rows between pages.
            $suppliers = $query->orderBy($orderby, $orderbyType)
                ->orderBy('suppliers.id', 'asc')
                ->paginate($perPage);

            return response()->json(new Response($request->token, $suppliers));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'supplier.view')) {
            return $can;
        }

        $supplier = Supplier::with(['categories' => $this->categoryColumns()])->find($id);

        if (!$supplier) {
            return response()->json(new Response($request->token, [], 404, 'Supplier not found'));
        }

        return response()->json(new Response($request->token, $supplier));
    }

    /**
     * Procurement -> Sourcing.
     *
     * Every order with lines still to be sourced, and for each line the
     * suppliers who quoted on it.
     *
     * Grouped by ORDER, not by line: a basket can hold several procurement
     * products, and a screen that listed each line separately repeated the
     * same order number down the page and never showed what the order as a
     * whole still owed. Paginated on orders for the same reason -- an
     * order's lines must never split across two pages.
     *
     * This replaced a second, read-only "Requests" screen that showed the
     * same rows arranged by supplier. Everything it displayed is here, next
     * to the decision it informs.
     */
    public function sourcing(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'supplier.view')) {
                return $can;
            }

            $perPage = (int)($request->per_page ?? Config::get('constants.api.PAGINATION'));
            $perPage = max(1, min($perPage, 100));

            $orders = SupplierNotification::query()
                ->select(
                    'order_id',
                    DB::raw('MAX(created_at) as asked_at'),
                    DB::raw('COUNT(DISTINCT CONCAT(product_id, "-", COALESCE(inventory_id, 0))) as line_count')
                )
                ->groupBy('order_id');

            if ($request->filled('order_id')) {
                $orders->where('order_id', (int)$request->order_id);
            }

            /*
             * Find an order by its number. The number lives on orders, so the
             * match is resolved there and fed back as ids -- joining the
             * grouped query to orders would change what it groups by.
             */
            if ($request->filled('q')) {
                $search = trim($request->q);

                $orders->whereIn('order_id', Order::withTrashed()
                    ->where('order', 'like', "%{$search}%")
                    ->pluck('id'));
            }

            /* Orders this supplier was asked about, quoted on, or won. */
            if ($request->filled('supplier_id')) {
                $orders->whereIn('order_id', SupplierNotification::query()
                    ->where('supplier_id', (int)$request->supplier_id)
                    ->pluck('order_id'));
            }

            /*
             * Where the order stands, expressed as what is still true of it
             * rather than a stored column -- state here is derived from the
             * quotes, so a stored copy would drift the moment one changed.
             *
             *   needs_quote  a line nobody has answered on yet
             *   needs_award  a line answered but not yet given to anyone
             *   unpaid       awarded, and the supplier is still owed
             *   settled      every line awarded and every award paid
             */
            $unawarded = function ($query) {
                $query->select(DB::raw(1))
                    ->from('supplier_notifications as sn')
                    ->whereColumn('sn.order_id', 'supplier_notifications.order_id')
                    ->whereNotExists(function ($bid) {
                        $bid->select(DB::raw(1))
                            ->from('supplier_bids as sb')
                            ->whereColumn('sb.order_id', 'sn.order_id')
                            ->whereColumn('sb.product_id', 'sn.product_id')
                            ->whereRaw('COALESCE(sb.inventory_id, 0) = COALESCE(sn.inventory_id, 0)')
                            ->where('sb.status', SupplierBid::ACCEPTED);
                    });
            };

            $owing = function ($query) {
                $query->select(DB::raw(1))
                    ->from('supplier_bids as sb')
                    ->whereColumn('sb.order_id', 'supplier_notifications.order_id')
                    ->where('sb.status', SupplierBid::ACCEPTED)
                    ->whereNull('sb.paid_at');
            };

            switch ($request->state) {

                case 'needs_quote':
                    $orders->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('supplier_notifications as sn')
                            ->whereColumn('sn.order_id', 'supplier_notifications.order_id')
                            ->whereNotExists(function ($bid) {
                                $bid->select(DB::raw(1))
                                    ->from('supplier_bids as sb')
                                    ->whereColumn('sb.order_id', 'sn.order_id')
                                    ->whereColumn('sb.product_id', 'sn.product_id')
                                    ->whereRaw('COALESCE(sb.inventory_id, 0) = COALESCE(sn.inventory_id, 0)');
                            });
                    });
                    break;

                case 'needs_award':
                    $orders->whereExists($unawarded);
                    break;

                case 'unpaid':
                    $orders->whereExists($owing);
                    break;

                case 'settled':
                    $orders->whereNotExists($unawarded)->whereNotExists($owing);
                    break;
            }

            $data = $orders->orderByRaw('MAX(created_at) desc')->paginate($perPage);

            $onPage = collect($data->items());

            if ($onPage->isEmpty()) {
                return response()->json(new Response($request->token, [
                    'orders' => $data,
                    'suppliers' => Supplier::whereIn('id', SupplierNotification::query()
                            ->distinct()->pluck('supplier_id'))
                        ->orderBy('name')
                        ->get(['id', 'name']),
                ]));
            }

            $orderIds = $onPage->pluck('order_id')->all();

            $orderNumbers = Order::withTrashed()->whereIn('id', $orderIds)->pluck('order', 'id');

            /*
             * Every notified line for the orders on this page. This is what
             * says a line needs sourcing at all -- a bid cannot exist without
             * one, but a line with no replies yet still belongs on the board.
             */
            $notified = SupplierNotification::whereIn('order_id', $orderIds)
                ->get(['order_id', 'product_id', 'inventory_id', 'supplier_id', 'created_at'])
                ->groupBy(function ($row) {
                    return $row->order_id . ':' . $row->product_id . ':' . (int)$row->inventory_id;
                });

            $products = Product::whereIn('id', $notified->flatten()->pluck('product_id')->unique()->all())
                ->get(['id', 'title', 'image'])
                ->each(function ($product) {
                    // is_new and collection_background_image cost a query each.
                    $product->setAppends([]);
                })
                ->keyBy('id');

            $sold = OrderedProduct::whereIn('order_id', $orderIds)
                ->get(['order_id', 'product_id', 'inventory_id', 'quantity', 'selling', 'purchased', 'tax_inclusive'])
                ->keyBy(function ($row) {
                    return $row->order_id . ':' . $row->product_id . ':' . (int)$row->inventory_id;
                });

            $bids = SupplierBid::whereIn('order_id', $orderIds)
                ->get()
                ->groupBy(function ($bid) {
                    return $bid->order_id . ':' . $bid->product_id . ':' . (int)$bid->inventory_id;
                });

            $suppliers = Supplier::whereIn('id', $bids->flatten()->pluck('supplier_id')
                    ->merge($notified->flatten()->pluck('supplier_id'))->unique()->all())
                ->get(['id', 'name', 'email', 'country_code', 'phone'])
                ->keyBy('id');

            $expiresAfter = \App\Http\Controllers\BidController::LINK_TTL_DAYS;

            foreach ($data as $order) {

                $order->order_number = $orderNumbers[$order->order_id] ?? null;
                $order->asked_at = \Carbon\Carbon::parse($order->asked_at, config('app.timezone'))->toJSON();
                $order->expired = now()->diffInDays($order->asked_at) > $expiresAfter;

                $lines = [];

                /* Each distinct line this order had suppliers asked about. */
                foreach ($notified as $key => $rows) {

                    if ((int)$rows->first()->order_id !== (int)$order->order_id) {
                        continue;
                    }

                    $first = $rows->first();
                    $soldRow = isset($sold[$key]) ? $sold[$key] : null;

                    /*
                     * A margin only reads across where both sides carry VAT
                     * the same way: a supplier quotes ex-VAT, and a line sold
                     * VAT-inclusive holds the VAT inside `selling`.
                     */
                    $comparable = $soldRow && !$soldRow->tax_inclusive;
                    $soldPrice = $soldRow ? Pricing::round((float)$soldRow->selling) : null;
                    $quantity = $soldRow ? (int)$soldRow->quantity : null;

                    $lineBids = isset($bids[$key]) ? $bids[$key] : collect();

                    /*
                     * Cheapest first, so the decision is the top row unless
                     * there is a reason to look past it. A rejected quote
                     * sinks: kept for the record, not for comparison.
                     */
                    $quotes = $lineBids
                        ->sortBy(function ($bid) {
                            return [$bid->status === SupplierBid::REJECTED ? 1 : 0, (float)$bid->price];
                        })
                        ->values()
                        ->map(function ($bid) use ($suppliers, $quantity, $comparable, $soldPrice) {
                            return [
                                'id' => $bid->id,
                                'supplier' => $suppliers[$bid->supplier_id] ?? null,
                                'price' => Pricing::round((float)$bid->price),
                                'grade' => $bid->grade,
                                'status' => $bid->status,
                                'quoted_at' => optional($bid->updated_at)->toJSON(),
                                'payable' => $bid->payableFor($quantity ?: 1),
                                'margin' => ($comparable && $soldPrice !== null)
                                    ? Pricing::round($soldPrice - (float)$bid->price)
                                    : null,
                                'paid_at' => optional($bid->paid_at)->toJSON(),
                                'paid_amount' => $bid->paid_amount !== null
                                    ? Pricing::round((float)$bid->paid_amount)
                                    : null,
                                'payment_method' => $bid->payment_method,
                                'payment_reference' => $bid->payment_reference,
                            ];
                        });

                    $accepted = $quotes->firstWhere('status', SupplierBid::ACCEPTED);

                    $lines[] = [
                        'key' => $key,
                        'product_id' => $first->product_id,
                        'inventory_id' => $first->inventory_id,
                        'product' => $products[$first->product_id] ?? null,
                        'quantity' => $quantity,
                        'sold_price' => $soldPrice,
                        'cost_price' => $soldRow && $soldRow->purchased !== null
                            ? Pricing::round((float)$soldRow->purchased)
                            : null,
                        'margin_comparable' => (bool)$comparable,
                        'asked_count' => $rows->pluck('supplier_id')->unique()->count(),
                        'quotes' => $quotes,
                        'accepted_quote_id' => $accepted ? $accepted['id'] : null,
                        'awarded_to' => $accepted && $accepted['supplier'] ? $accepted['supplier']['name'] : null,
                        'settled' => $accepted ? (bool)$accepted['paid_at'] : false,
                        'payable' => ($accepted && !$accepted['paid_at']) ? $accepted['payable'] : null,
                    ];
                }

                $order->lines = $lines;

                /*
                 * What the order as a whole still needs. This is the reason
                 * for grouping: a basket of four procurement products is one
                 * piece of work, and "two still to award, 310 to pay" is the
                 * sentence somebody actually needs.
                 */
                $order->line_count = count($lines);
                $order->awarded_count = count(array_filter($lines, function ($l) { return $l['accepted_quote_id'] !== null; }));
                $order->quoted_count = count(array_filter($lines, function ($l) { return count($l['quotes']) > 0; }));
                $order->settled_count = count(array_filter($lines, function ($l) { return $l['settled']; }));
                $order->total_payable = Pricing::round(array_sum(array_map(function ($l) {
                    return $l['payable'] ?: 0;
                }, $lines)));
            }

            return response()->json(new Response($request->token, [
                'orders' => $data,

                /*
                 * Only suppliers who have actually been asked about
                 * something -- a filter offering names that can never match
                 * is a filter that looks broken.
                 */
                'suppliers' => Supplier::whereIn('id', SupplierNotification::query()
                        ->distinct()->pluck('supplier_id'))
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Take a quote, or turn one down.
     *
     * Accepting is the moment procurement commits money, so three things
     * happen together or not at all:
     *
     *   1. this bid becomes the accepted one
     *   2. every other quote for the SAME LINE is rejected -- one supplier
     *      sources a line, and leaving rivals "pending" invites a second
     *      acceptance and a second invoice
     *   3. the agreed price is written onto the order line as its real cost,
     *      with the previous value kept on the bid so this can be undone
     *
     * In a transaction, with the line's bids locked, because two admins on
     * the same screen would otherwise each accept a different supplier.
     */
    public function decideQuote(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'procurement.decide')) {
            return $can;
        }

        $rules = [
            'decision' => ['required', Rule::in([SupplierBid::ACCEPTED, SupplierBid::REJECTED])],
            'reason'   => 'nullable|string|max:255',
        ];

        if ($error = Validation::validationMessage($request, $rules, 'form', [
            'decision.in' => 'A quote can only be accepted or rejected.',
        ])) {
            return response()->json($this->onOneField($error));
        }

        $bid = SupplierBid::find($id);

        if (!$bid) {
            return response()->json(new Response($request->token, [], 404, 'Quote not found'));
        }

        if ($bid->isPaid()) {
            return response()->json(Validation::fieldError(
                $request->token,
                'decision',
                'That quote has already been paid. Reverse the payment before changing the decision.'
            ));
        }

        try {
            DB::transaction(function () use ($bid, $request) {

                // Lock every quote for this line, this one included.
                $siblings = SupplierBid::where('order_id', $bid->order_id)
                    ->where('product_id', $bid->product_id)
                    ->where(function ($query) use ($bid) {
                        $bid->inventory_id
                            ? $query->where('inventory_id', $bid->inventory_id)
                            : $query->whereNull('inventory_id');
                    })
                    ->lockForUpdate()
                    ->get();

                $orderLine = OrderedProduct::where('order_id', $bid->order_id)
                    ->where('product_id', $bid->product_id)
                    ->where(function ($query) use ($bid) {
                        $bid->inventory_id
                            ? $query->where('inventory_id', $bid->inventory_id)
                            : $query->whereNull('inventory_id');
                    })
                    ->first();

                if ($request->decision === SupplierBid::ACCEPTED) {

                    foreach ($siblings as $sibling) {
                        if ($sibling->id === $bid->id) {
                            continue;
                        }

                        /*
                         * A rival that was previously accepted has to give the
                         * order line's cost back before this one overwrites it,
                         * or the original figure is lost.
                         */
                        if ($sibling->isAccepted()) {
                            $this->restoreLineCost($sibling, $orderLine);
                        }

                        $sibling->update([
                            'status' => SupplierBid::REJECTED,
                            'decided_at' => now(),
                            'decided_by' => $this->user->id ?? null,
                            'decline_reason' => 'Another quote was accepted.',
                        ]);
                    }

                    $costBefore = $bid->cost_before;

                    if ($orderLine && !$bid->isAccepted()) {
                        // Only capture the pre-decision cost once.
                        $costBefore = $orderLine->purchased;

                        $orderLine->update(['purchased' => $bid->price]);
                    }

                    $bid->update([
                        'status' => SupplierBid::ACCEPTED,
                        'decided_at' => now(),
                        'decided_by' => $this->user->id ?? null,
                        'decline_reason' => null,
                        'cost_before' => $costBefore,
                    ]);

                    return;
                }

                // Rejecting the quote the line's cost came from puts it back.
                if ($bid->isAccepted()) {
                    $this->restoreLineCost($bid, $orderLine);
                }

                $bid->update([
                    'status' => SupplierBid::REJECTED,
                    'decided_at' => now(),
                    'decided_by' => $this->user->id ?? null,
                    'decline_reason' => $request->reason,
                    'cost_before' => null,
                ]);
            });

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

        return response()->json(new Response(
            $request->token,
            $bid->fresh(),
            200,
            $request->decision === SupplierBid::ACCEPTED
                ? 'Quote accepted'
                : 'Quote rejected'
        ));
    }

    /**
     * Put back the cost this bid overwrote when it was accepted.
     *
     * Silent when nothing was captured: a bid accepted before this column
     * existed, or a line that has since been removed, has nothing to restore
     * and must not write a null over a real figure.
     */
    private function restoreLineCost(SupplierBid $bid, $orderLine)
    {
        if (!$orderLine || $bid->cost_before === null) {
            return;
        }

        $orderLine->update(['purchased' => $bid->cost_before]);
    }

    /**
     * Record that a supplier has been paid, or undo that record.
     *
     * This settles a liability in the books; it does not move money. The
     * gateways this shop has configured take card payments from customers --
     * paying a supplier is a bank transfer made outside this system, so what
     * belongs here is the note of what was paid, how, and against which
     * reference.
     *
     * Gated on its own permission: choosing a supplier and signing off that
     * they have been paid are different rights, and the second is the one
     * that touches money.
     */
    public function payQuote(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'procurement.pay')) {
            return $can;
        }

        $bid = SupplierBid::find($id);

        if (!$bid) {
            return response()->json(new Response($request->token, [], 404, 'Quote not found'));
        }

        // Undo: the payment was recorded in error, or refunded.
        if ($request->boolean('reverse')) {
            $bid->update([
                'paid_at' => null,
                'paid_by' => null,
                'paid_amount' => null,
                'payment_method' => null,
                'payment_reference' => null,
            ]);

            return response()->json(new Response($request->token, $bid->fresh(), 200, 'Payment record removed'));
        }

        /*
         * Only a quote we have actually taken. Paying a supplier whose quote
         * was never accepted is either a mistake or a decision nobody
         * recorded, and both want fixing before the money is booked.
         */
        if (!$bid->isAccepted()) {
            return response()->json(Validation::fieldError(
                $request->token,
                'amount',
                'Accept this quote before recording a payment against it.'
            ));
        }

        if ($bid->isPaid()) {
            return response()->json(Validation::fieldError(
                $request->token,
                'amount',
                'This quote is already recorded as paid.'
            ));
        }

        $quantity = (int)OrderedProduct::where('order_id', $bid->order_id)
            ->where('product_id', $bid->product_id)
            ->where(function ($query) use ($bid) {
                $bid->inventory_id
                    ? $query->where('inventory_id', $bid->inventory_id)
                    : $query->whereNull('inventory_id');
            })
            ->value('quantity');

        $rules = [
            /*
             * Defaults to the quote for the whole line, but is editable: what
             * leaves the bank is what gets recorded, and a supplier invoice
             * can differ from the quote by a delivery charge or a rounding.
             */
            'amount'    => 'required|numeric|min:0.01|max:9999999.99',
            'method'    => ['required', Rule::in(SupplierBid::PAYMENT_METHODS)],
            'reference' => 'nullable|string|max:120',
            'paid_on'   => 'nullable|date|before_or_equal:today',
        ];

        if ($error = Validation::validationMessage($request, $rules, 'form', [
            'method.in' => 'Choose how the supplier was paid.',
            'paid_on.before_or_equal' => 'A payment cannot be dated in the future.',
        ])) {
            return response()->json($this->onOneField($error));
        }

        $bid->update([
            'paid_at' => $request->paid_on ? \Carbon\Carbon::parse($request->paid_on) : now(),
            'paid_by' => $this->user->id ?? null,
            'paid_amount' => $request->amount,
            'payment_method' => $request->method,
            'payment_reference' => $request->reference,
        ]);

        return response()->json(new Response(
            $request->token,
            ['bid' => $bid->fresh(), 'quoted_payable' => $bid->payableFor($quantity ?: 1)],
            200,
            'Payment recorded'
        ));
    }

    /**
     * Collapse `categories.0` onto `categories`.
     *
     * The validator names the element it refused, but the form has one
     * control for the whole set and anchors it `data-field="categories"`.
     * DataPage matches those names exactly, so an indexed key has nowhere to
     * go and the message falls back to the summary box at the top -- next to
     * the picker is where it belongs.
     */
    private function onOneField($error)
    {
        if (!isset($error->data['fields']) || !is_array($error->data['fields'])) {
            return $error;
        }

        $collapsed = [];

        foreach ($error->data['fields'] as $field => $messages) {
            $name = strpos($field, '.') === false ? $field : strstr($field, '.', true);

            $collapsed[$name] = array_values(array_unique(
                array_merge(isset($collapsed[$name]) ? $collapsed[$name] : [], (array)$messages)
            ));
        }

        $data = $error->data;
        $data['fields'] = $collapsed;
        $error->data = $data;

        return $error;
    }

    /**
     * ISO 3166-1 alpha-2 => dialling code, from the same resource file the
     * address forms use.
     *
     * The server derives the dialling code from the country rather than
     * accepting the browser's, so the two can never disagree on a stored
     * row -- and so the list of acceptable countries is one thing, defined
     * once.
     */
    private function dialCodes()
    {
        static $codes;

        if ($codes === null) {
            $path = base_path('storage/resources/phones.json');

            $raw = is_readable($path)
                ? (json_decode(file_get_contents($path), true) ?: [])
                : [];

            /*
             * Re-keyed to real ISO codes, because that file calls the UK
             * "UK" and every other resource here calls it "GB" -- see
             * PhoneRules::ISO_ALIASES.
             */
            $codes = [];

            foreach ($raw as $iso => $dial) {
                $iso = PhoneRules::normaliseIso($iso);

                if (in_array($iso, PhoneRules::RETIRED, true)) {
                    continue;
                }

                $codes[$iso] = PhoneRules::normaliseDial($dial);
            }
        }

        return $codes;
    }

    /**
     * The categories a supplier can be assigned to.
     *
     * Here rather than borrowed from elsewhere because the edit form used to
     * fill its dropdown from product/with-categories -- an endpoint that
     * serialises every product with two appended per-row attributes, so a
     * list of category names cost hundreds of queries. It also gated on
     * category.view, a permission the procurement screens have no reason to
     * require.
     */
    public function categories(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'supplier.view')) {
            return $can;
        }

        /*
         * Top level only. Procurement matches a supplier to an order line
         * through ProductCategory, which records the categories a product
         * sits in -- and a supplier who covers "Mobile" is telling us they
         * can source anything under it. Offering the subcategories as well
         * invites a mapping that looks narrower than it is and answers the
         * same orders anyway.
         */
        $categories = Category::whereNull('parent')
            ->orderBy('title')
            ->get(['id', 'title']);

        return response()->json(new Response($request->token, [
            'categories' => $categories,

            /*
             * The phone rules travel with the picker so the form checks a
             * number the same way the server will, instead of keeping its
             * own copy that drifts.
             */
            'phone_rules'   => PhoneRules::all(),
            'phone_default' => PhoneRules::DEFAULT_RANGE,
            'iso_aliases'   => PhoneRules::ISO_ALIASES,
            'extra_names'   => PhoneRules::EXTRA_NAMES,
            'retired'       => PhoneRules::RETIRED,

            /*
             * Dialling codes come from here already normalised, so the
             * picker never has to show "+1(268)".
             */
            'dial_codes'    => $this->dialCodes(),
        ]));
    }

    /**
     * Create a supplier, or edit one that exists.
     *
     * Two permissions, because they are two different rights: creating a
     * supplier is not the same as rewriting the contact details of one
     * already receiving procurement requests.
     */
    public function action(Request $request, $id = null)
    {
        if ($can = Utils::userCan($this->user, $id ? 'supplier.edit' : 'supplier.create')) {
            return $can;
        }

        /*
         * An id that names nothing is a failed edit, not a create.
         * updateOrCreate(['id' => $id], ...) quietly made a brand new
         * supplier under a fresh id and reported "updated successfully",
         * so a stale tab could silently populate the list with duplicates.
         */
        $supplier = null;

        if ($id) {
            $supplier = Supplier::find($id);

            if (!$supplier) {
                return response()->json(new Response($request->token, [], 404, 'Supplier not found'));
            }
        }

        /*
         * Everything the form offers is checked here, not only on the form.
         * The screen used to check "is it non-empty?" in the browser and the
         * server took whatever arrived: a one-character name, a phone with no
         * digits in it, a subcategory, two kilobytes of address.
         *
         * Reported through Validation::validationMessage, which answers 201
         * with a `fields` map. That is the shape DataPage reads to put each
         * message against the control that caused it -- $request->validate()
         * throws a 422 that nothing in the admin panel knows how to unpack,
         * which is why this form has never shown a server-side error.
         */
        $request->merge([
            'name'    => is_string($request->name) ? trim($request->name) : $request->name,
            'email'   => is_string($request->email) ? trim($request->email) : $request->email,
            'address' => is_string($request->address) ? trim($request->address) : $request->address,
            'country' => is_string($request->country) ? strtoupper(trim($request->country)) : $request->country,
        ]);

        $rules = [
            // A name of one character is a slip, not a supplier.
            'name'         => 'required|string|min:2|max:255',
            'email'        => [
                'required', 'email', 'max:255',
                Rule::unique('suppliers', 'email')->ignore($supplier ? $supplier->id : null),
            ],
            'country'      => ['required', 'string', 'size:2', Rule::in(array_keys($this->dialCodes()))],
            'phone'        => 'required|string|max:20',
            'address'      => 'required|string|min:5|max:2000',
            'categories'   => 'required|array|min:1|max:50',
            /*
             * Top level only, matching what the picker offers. Without this
             * the picker is a suggestion: a hand-made request could map a
             * supplier to a subcategory, which procurement would then never
             * match, because a product records the top-level categories it
             * sits in.
             */
            'categories.*' => ['required', Rule::exists('categories', 'id')->whereNull('parent')],
        ];

        $messages = [
            'name.min'            => 'Give the supplier a name of at least 2 characters.',
            'email.unique'        => 'Another supplier already uses that email address.',
            'country.required'    => 'Choose the country this number belongs to.',
            'country.in'          => 'Choose a country from the list.',
            'country.size'        => 'Choose a country from the list.',
            'address.min'         => 'Give an address of at least 5 characters.',
            'categories.required' => 'Choose at least one category.',
            'categories.*.exists' => 'Pick top-level categories only.',
        ];

        if ($error = Validation::validationMessage($request, $rules, 'form', $messages)) {
            return response()->json($this->onOneField($error));
        }

        /*
         * The dialling code comes from the country rather than from the
         * browser. They are two halves of one picker, and a row where they
         * disagree is not something to store and puzzle over later.
         */
        $dialCode = $this->dialCodes()[$request->country];

        /*
         * And the number has to be one that country could have -- and one
         * TwilioService can build an E.164 number from, since that is what
         * the procurement WhatsApp is actually sent to.
         */
        if ($problem = PhoneRules::problem($request->phone, $dialCode, $request->country)) {
            return response()->json(Validation::fieldError($request->token, 'phone', $problem));
        }

        $values = [
            'name'         => $request->name,
            'email'        => $request->email,
            'country'      => $request->country,
            'country_code' => $dialCode,
            // Stored as the country dials it: digits, no trunk prefix.
            'phone'        => PhoneRules::nationalDigits($request->phone),
            'address'      => $request->address,
        ];

        try {
            DB::transaction(function () use (&$supplier, $values, $request) {

                if ($supplier) {
                    $supplier->update($values);
                } else {
                    $supplier = Supplier::create($values);
                }

                $supplier->categories()->sync($request->categories ?? []);
            });

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

        return response()->json(new Response(
            $request->token,
            $supplier->load(['categories' => $this->categoryColumns()]),
            200,
            $id ? 'Supplier updated successfully' : 'Supplier created successfully'
        ));
    }

    /**
     * Remove a supplier and everything that only existed because of them.
     *
     * supplier_categories cascades by foreign key, but supplier_notifications
     * has neither a key nor any cleanup, so deleting a supplier used to leave
     * its procurement requests behind -- and the WhatsApp links pointing at
     * them kept resolving, because those rows are exactly what BidController
     * checks a link against. Their bids go with them: a price from a supplier
     * we no longer deal with is not a price.
     */
    public function delete(Request $request, $id)
    {
        if ($can = Utils::userCan($this->user, 'supplier.delete')) {
            return $can;
        }

        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json(new Response($request->token, [], 404, 'Supplier not found'));
        }

        try {
            DB::transaction(function () use ($supplier) {
                SupplierBid::where('supplier_id', $supplier->id)->delete();
                SupplierNotification::where('supplier_id', $supplier->id)->delete();

                $supplier->categories()->detach();
                $supplier->delete();
            });

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

        return response()->json(new Response($request->token, [], 200, 'Supplier deleted successfully'));
    }
}
