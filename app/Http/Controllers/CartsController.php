<?php

namespace App\Http\Controllers;

use App\Models\GuestUser;
use App\Models\Helper\CartMerge;
use App\Models\Helper\BasketNotice;
use App\Models\Helper\BasketUpkeep;
use App\Models\Helper\CartHold;
use App\Models\Helper\CheckoutStock;
use App\Models\Helper\CartTrace;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use App\Models\Helper\BundleDiscount;
use App\Models\Helper\Pricing;
use App\Models\Helper\Shipping;
use App\Models\Helper\Membership;
use App\Models\Product;
use App\Models\Setting;
use App\Models\UpdatedInventory;
use App\Models\User;
use App\Models\Cart;
use App\Models\BusinessProductPricing;
use App\Models\UserAddress;
use App\Services\BundleService;
use App\Services\CrossSellService;
use App\Services\ProductUpgradeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

class CartsController extends ControllerHelper
{
    public function byUser(Request $request)
    {
        try {
            $lang = $request->header('language');
			
            $query = Cart::query();

            if ($lang) {

                $query = $query->with(['updated_inventory.inventory_attributes.attribute_value.attribute' =>
                    function ($query) use ($lang) {
                        $query->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                            $join->on('al.attribute_id', '=', 'attributes.id');
                            $join->where('al.lang', $lang);
                        })
                            ->select('attributes.*', 'al.title');
                    }]);

                $query = $query->with(['updated_inventory.inventory_attributes.attribute_value' =>
                    function ($query) use ($lang) {

                        $query->leftJoin('attribute_value_langs as avl',
                            function ($join) use ($lang) {
                                $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                                $join->where('avl.lang', $lang);
                            })
                            ->select('attribute_values.*', 'avl.title');
                    }]);
			

                $query = $query->with(['flash_product' => function ($query) use ($lang) {
                    $query->with(['product_images' => function($query){
                        $query->with(['attributes' => function($query){}]);
                    }]);
					

                    $query->with(['shipping_rule' => function($query){
                        $query->with(['shipping_places' => function($query){
                            $query->with(['shipping_rule' => function($query){
                            }]);
                        }]);
                    }]);
					
                    /*
                     * This joined upsell_products on pl.product_id - the
                     * product_langs alias, which is not joined until the line
                     * below and is the wrong table besides. MySQL answered
                     * every localised basket with "Unknown column
                     * 'pl.product_id' in 'on clause'", the controller's catch
                     * turned that into "Something went wrong", and the cart
                     * page showed the error screen - but only for a shopper on
                     * a non-default language, since that is when the Language
                     * header (and this whole branch) comes into play.
                     *
                     * The join FrontendController already uses for the same
                     * column.
                     */
                    $query = $query->leftJoin('upsell_products', function ($join) {
                        $join->on('products.id', '=', 'upsell_products.product_id');
                    });

                    $query->leftJoin('product_langs as pl', function ($join) use ($lang) {

                        $join->on('pl.product_id', '=', 'products.id');
                        $join->where('pl.lang', $lang);

                    })->with(['bundle_deal' => function ($query) use ($lang) {

                            $query->leftJoin('bundle_deal_langs as pcl', function ($join) use ($lang) {
                                $join->on('pcl.bundle_deal_id', '=', 'bundle_deals.id');
                                $join->where('pcl.lang', $lang);
                            })
                                ->select('bundle_deals.*', 'pcl.title');

                        }])
                        /*
                         * COALESCE, because a product with no row in
                         * product_langs for this language selected pl.title as
                         * NULL - and the basket, the shipping page and the
                         * order summary all rendered that as the literal
                         * "null" ("1 x null"). Only 1 of 122 products here has
                         * a French title, so the untranslated name is the one
                         * worth showing.
                         */
                        /*
                         * excludeVAT rides along, because it decides whether
                         * the price is quoted net or gross and the checkout
                         * summary reads it to work out the VAT. It was absent
                         * from this branch only — so a shopper on a non-default
                         * language was shown a basket with no VAT on it while
                         * the same basket in English had VAT, and the server
                         * charged whichever the product actually says.
                         */
                        ->select('products.id', 'products.bundle_deal_id',
                            \DB::raw('COALESCE(pl.title, products.title) as title'), 'products.slug',
                            'products.selling', 'products.offered', 'products.tax_rule_id',
                            'products.excludeVAT',
                            'products.image', 'products.shipping_rule_id',
                            'flash_sale_products.price',
                            'flash_sale_products.discount_type as flash_discount_type',
                            'flash_sale_products.discount_value as flash_discount_value',
                            'flash_sales.end_time', 'upsell_products.price as upsell_price');
                }]);

            } else {

                $query = $query->with(['flash_product' => function ($query) use ($lang) {
                    $query->with(['product_images' => function($query){
                        $query->with(['attributes' => function($query){}]);
                    }]);
			
                    $query->with(['shipping_rule' => function($query){
                        $query->with(['shipping_places' => function($query){
                            $query->with(['shipping_rule' => function($query){}]);
                        }]);
                    }]);
                }]);
				
				
				
                $query = $query->with(['updated_inventory' => function ($query) {
                    $query->with(['inventory_attributes' => function ($query){
                        $query->with(['attribute_value' => function ($query){
                            $query->with(['attribute']);
                        }]);
                    }]);
                }]);
            }


            if($this->user && !$request->user_token){

                $query = $query->where('admin_id', $this->user->id);

            } else if ($request->user('user')) {

                $query = $query->where('user_id', $request->user('user')->id);

            } else if($request->user_token){

                $query = $query->where('user_token', $request->user_token);

            } else {

                return response()->json(Validation::errorLang($lang));
            }

            $query = $query->with('shipping_place.shipping_rule');
            // original_price rides along so repriceForCustomer can tell whether
            // the stored figures have drifted without a query per line.
            $query = $query->select('id', 'product_id', 'user_id', 'inventory_id', 'quantity',
                    'selected', 'shipping_place_id', 'shipping_type', 'price as upsell_price',
                    'original_price as stored_original_price', 'added_price', 'buy_now');
            $data = $query->get();
			
					 // MANUALLY ADD UPSELL PRODUCTS AFTER GETTING THE DATA
        $productIds = $data->pluck('product_id')->filter()->unique()->toArray();
        
        if (!empty($productIds)) {
            // Get upsell_ids from products
            $productsWithUpsell = \DB::table('products')
                ->whereIn('id', $productIds)
                ->whereNotNull('upsell_id')
                ->pluck('upsell_id', 'id')
                ->toArray();
            
            $upsellIds = array_values($productsWithUpsell);
            
            if (!empty($upsellIds)) {
                // Get all upsell products
                $allUpsellProducts = \DB::table('upsell_products as up')
                    ->join('products as p', 'up.product_id', '=', 'p.id')
                    ->whereIn('up.upsell_id', $upsellIds)
                    ->select('up.upsell_id', 'up.price', 'p.id', 'p.title', 'p.image', 'p.selling', 'p.offered')
                    ->get()
                    ->groupBy('upsell_id');
                
                // Assign upsell products to each cart item
                foreach ($data as $cartItem) {
                    if (isset($productsWithUpsell[$cartItem->product_id])) {
                        $upsellId = $productsWithUpsell[$cartItem->product_id];
						$cartItem->upsell_id=$upsellId;
                        $cartItem->upsell_products = $allUpsellProducts->get($upsellId, []);
                    }
                }
            }
        }
		
		
            $this->repriceForCustomer($request, $data);
            $this->priceShippingByWeight($data);
            $this->applyMemberShipping($request, $data);

            /*
             * Whatever changed this basket without the shopper - emptied after
             * 20 idle minutes, a line cut back, guest items added at sign-in -
             * rides along with every basket read, so the next page to load the
             * basket can say so (BasketNotice). The lines stay under data.
             */
            $owner = CartHold::ownerFromRequest($request);
            $payload = (array)new Response($request->token, $data);
            $payload['notices'] = $owner ? BasketNotice::pending($owner[0], $owner[1]) : [];

            return response()->json($payload);


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Re-price every line against the catalogue and the caller's entitlement.
     *
     * The unit price was banked in carts.price when the line was written, and
     * nothing revisited it. That is wrong for every event that changes the
     * answer after the fact, all of which happen with items already in a
     * basket: a trade application is approved, an account is suspended, a
     * flash sale starts or ends, staff edit a quantity band, or a guest basket
     * is merged onto a business account at sign-in and keeps the retail prices
     * it was built with.
     *
     * Checkout has always priced from the catalogue rather than from these
     * columns, so the drift was silent and one-directional: the customer was
     * shown one number and charged another. Deciding it here as well means the
     * basket, the checkout summary and the money taken all come from
     * Pricing::unitPrice and cannot disagree.
     *
     * The refreshed figure is written back so that carts.price stays a true
     * record rather than a fossil of whatever was right when the line was
     * added.
     */
    /**
     * The delivery charge on the basket lines, priced for what the order
     * weighs.
     *
     * /checkout and the payment page take the delivery charge from the
     * line's shipping_place price, and the card is charged from that figure,
     * so it has to be the one Shipping::quote gives the order: the band for
     * the weight of the lines being bought, not the row's flat price. A row
     * without weight bands is left as it is. When the order is too heavy
     * for the row, deliverable is false and placing the order refuses it.
     */
    private function priceShippingByWeight($rows)
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return;
        }

        // the same lines Cart::forCheckout picks: a Buy Now line, else the ticked ones
        $buyNow = $rows->filter(function ($row) { return (int)$row->buy_now === 1; });
        $buying = $buyNow->isNotEmpty()
            ? $buyNow
            : $rows->filter(function ($row) {
                return (int)$row->buy_now === 0
                    && (int)$row->selected === (int)Config::get('constants.status.PUBLIC');
            });

        $weight = Shipping::linesWeight($buying);

        foreach ($rows as $row) {
            $place = $row->shipping_place;

            if (!$place || (int)$row->shipping_type !== Shipping::deliveryType()
                || !Shipping::weightRates($place->weight_rates)) {
                continue;
            }

            $price = Shipping::deliveryPrice($place, $weight);

            if (!is_null($price)) {
                $place->price = $price;
            }

            $place->setAttribute('order_weight', $weight);
            $place->setAttribute('deliverable', !is_null($price));
        }
    }

    /**
     * A member's delivery is free (Helper\Membership): the delivery rows on
     * the basket are priced at 0, which is the figure /checkout reads and the
     * order charges. Collection keeps its price. Measured on the lines being
     * bought, priced as the order prices them.
     */
    private function applyMemberShipping(Request $request, $rows)
    {
        $user = $request->user('user');
        $rows = collect($rows);

        if (!$user || $rows->isEmpty()) {
            return;
        }

        if (!Membership::freeShippingFor($user, Membership::checkoutLines($user)['subtotal'])) {
            return;
        }

        foreach ($rows as $row) {
            $place = $row->shipping_place;

            if (!$place || (int)$row->shipping_type !== Shipping::deliveryType()) {
                continue;
            }

            $place->price = 0;
            $place->setAttribute('member_free_shipping', true);
        }
    }

    private function repriceForCustomer(Request $request, $rows)
    {
        if (is_null($rows) || count($rows) === 0) {
            return;
        }

        /*
         * Stock as this shopper sees it: what nobody holds, plus the units
         * their own basket holds (CartHold). The quantity control's maximum
         * reads this, and without the basket's own units a shopper holding the
         * last one would see it as sold out on their own line.
         */
        $owner = CartHold::ownerFromRequest($request);
        $ownHeld = $owner ? CartHold::heldMap($owner[0], $owner[1]) : [];

        foreach ($rows as $row) {
            $held = $ownHeld[$row->inventory_id] ?? 0;

            if ($held > 0 && $row->updated_inventory) {
                $row->updated_inventory->quantity = (int)$row->updated_inventory->quantity + $held;
            }
        }

        $this->markAvailability($rows);

        $customer = $request->user('user');

        // One query for every band the customer is entitled to, not one per line.
        $tiers = BusinessProductPricing::tierMap(
            $customer, collect($rows)->pluck('product_id')
        );

        foreach ($rows as $row) {

            $product = $row->flash_product ?? null;

            if (is_null($product)) {
                continue;
            }

            $quantity = (int)$row->quantity;

            // Read the stored figures before they are overwritten below.
            $storedPrice = (float)$row->upsell_price;
            $storedOriginal = (float)$row->stored_original_price;

            $unitPrice = Pricing::unitPrice(
                $product, $row->updated_inventory, $quantity,
                $tiers->get($row->product_id)
            );

            // Same call the order makes when it freezes actual_price, so the
            // basket, the checkout summary and the order cannot disagree.
            $originalPrice = Pricing::listPrice(
                $product, $row->updated_inventory, $unitPrice
            );

            /*
             * upsell_price is the alias byUser already gave carts.price, and
             * the basket tile reads it. Keeping it pointed at the freshly
             * decided figure is what actually corrects the display; the two
             * explicit keys below are the unambiguous names for anything
             * written from here on.
             */
            $row->upsell_price = $unitPrice;
            $row->unit_price = $unitPrice;
            $row->original_price = $originalPrice;
            $row->line_total = Pricing::round($unitPrice * $quantity);

            /*
             * The line split for VAT, decided here rather than in the browser.
             *
             * CheckoutRight.vue was working the tax out for itself from the
             * product's rule and its excludeVAT flag — a fifth copy of the
             * ladder, on the one screen where being wrong means charging the
             * wrong amount. It now reads these, so the basket, the summary and
             * the order all come from Pricing and cannot disagree.
             *
             * Whether the unit price is net or gross depends on the product;
             * both conventions exist in this catalogue.
             */
            $taxRule = $product->tax_rules ?? null;
            $rate = Pricing::vatRate($taxRule);
            $inclusive = Pricing::isVatInclusive($product);

            $unitSplit = Pricing::vatBreakdown($unitPrice, $rate, $inclusive);

            /*
             * Units the customer actually pays for: a "buy X get Y free"
             * bundle does not tax the free ones (QA audit ORD-03), which is
             * the same rule the order applies when it charges.
             */
            $freeUnits = Pricing::bundleFreeQuantity($product, $quantity);
            $payableQty = max(0, $quantity - (int)$freeUnits);

            $lineSplit = Pricing::vatBreakdown(
                Pricing::round($unitPrice * $payableQty), $rate, $inclusive
            );

            $row->vat_rate = $unitSplit['rate'];
            $row->vat_inclusive = $unitSplit['inclusive'];
            $row->unit_ex_vat = $unitSplit['ex'];
            $row->unit_vat = $unitSplit['vat'];
            $row->unit_inc_vat = $unitSplit['inc'];
            $row->line_ex_vat = $lineSplit['ex'];
            $row->line_vat = $lineSplit['vat'];
            $row->line_inc_vat = $lineSplit['inc'];

            /*
             * The list price of this line, stated gross.
             *
             * `original_price` is in whichever convention the product is
             * quoted in, so a summary cannot add it up and call the result
             * inc-VAT. Split here by the same helper, so the basket can lead
             * with "Products (Inc-VAT)" and have it mean the same thing for
             * the ex-VAT lines as for the rest. Same basis as the order
             * (OrderSummary::for), which measures the list against the full
             * quantity and lets the discount carry anything a bundle gave away.
             */
            $listSplit = Pricing::vatBreakdown(
                Pricing::round($originalPrice * $quantity), $rate, $inclusive
            );

            $row->line_list_inc_vat = $listSplit['inc'];

            /*
             * The same list total stated two more ways, because the summary
             * needs all three and none of them may be derived in the browser.
             *
             *   line_list      as the product is quoted: 699 x 3 = 2,097.00 for
             *                  an ex-VAT line, 1,000.00 for an inc-VAT one.
             *                  This is what the "Original Products" list shows
             *                  beside each name, and it is deliberately NOT
             *                  summed with its neighbours — two conventions do
             *                  not add up, which is the whole point of stating
             *                  the basis next to it.
             *
             *   line_list_ex_vat  the same figure normalised to net, which IS
             *                  addable across conventions. 1,000 inc-VAT
             *                  becomes 813.01, and the basket's original net
             *                  total comes out at 2,910.01 rather than the
             *                  2,910.01-vs-3,097.00 confusion of adding a gross
             *                  figure to a net one.
             *
             * Normalising first and aggregating second is the rule the whole
             * of this method follows; these two just make it visible to the
             * summary as well as to the total.
             */
            $row->line_list = Pricing::round($originalPrice * $quantity);
            $row->line_list_ex_vat = $listSplit['ex'];

            /*
             * "The price of this item has changed": the unit price
             * the shopper chose the line at (added_price, which only moves
             * when they act) against the price now. Shown both ways, a drop
             * as much as a rise, for as long as the line is in the basket.
             * carts.price cannot answer this - it is rewritten just below.
             */
            $addedPrice = is_null($row->added_price) ? null : (float)$row->added_price;

            $row->price_changed_from = !is_null($addedPrice) && abs($addedPrice - $unitPrice) >= 0.005
                ? Pricing::round($addedPrice)
                : null;

            // Working column, not part of the contract with the storefront.
            unset($row->stored_original_price);

            // Only touch the database when a stored figure has actually drifted,
            // or a line from before added_price needs its baseline (no notice).
            if (abs($storedPrice - $unitPrice) >= 0.005
                || abs($storedOriginal - $originalPrice) >= 0.005
                || is_null($addedPrice)
            ) {
                $update = [
                    'price' => $unitPrice,
                    'original_price' => $originalPrice,
                ];

                if (is_null($addedPrice)) {
                    $update['added_price'] = $unitPrice;
                    $row->added_price = $unitPrice;
                }

                Cart::where('id', $row->id)->update($update);
            }
        }
    }


    /** At or below this many units a line says "Only N left in stock". */
    const LOW_STOCK_AT = 5;

    /**
     * Whether each basket line can be bought as it stands: "Only 2 left",
     * "Currently unavailable" and "Only 1 available".
     *
     * A reservation covers what the basket holds, but a line can still sit in
     * a basket while its item is unpublished, or after its reservation ran out
     * and the stock went elsewhere. The basket used to find out only at the shipping
     * step or when the order was refused; these let it say so on the line and
     * keep the shopper from starting a checkout that cannot succeed.
     *
     *   availability        in_stock | low_stock | short | out_of_stock
     *                       | backorder | unavailable
     *   available_quantity  units that can be bought now; null when the
     *                       variant takes backorders (no limit)
     *
     * Stock is updated_inventory.quantity as repriceForCustomer leaves it, so
     * the units this shopper's own basket has reserved count as theirs.
     */
    private function markAvailability($rows)
    {
        $statuses = Product::whereIn('id', collect($rows)->pluck('product_id')->unique()->all())
            ->pluck('status', 'id');

        $public = (int)Config::get('constants.status.PUBLIC');

        foreach ($rows as $row) {
            $inventory = $row->updated_inventory;
            $wanted = (int)$row->quantity;

            if (!$inventory || (int)($statuses[$row->product_id] ?? 0) !== $public) {
                $row->availability = 'unavailable';
                $row->available_quantity = 0;
                continue;
            }

            $available = (int)$inventory->quantity;

            if ((int)$inventory->is_active === 1) {
                $row->availability = $available >= $wanted ? 'in_stock' : 'backorder';
                $row->available_quantity = null;
                continue;
            }

            $row->available_quantity = max(0, $available);

            if ($available <= 0) {
                $row->availability = 'out_of_stock';
            } else if ($available < $wanted) {
                $row->availability = 'short';
            } else if ($available <= self::LOW_STOCK_AT) {
                $row->availability = 'low_stock';
            } else {
                $row->availability = 'in_stock';
            }
        }
    }


    /**
     * Buy Now: a checkout of one item that leaves the basket alone
     * (2026-10-09).
     *
     * It used to write an ordinary basket row and untick every other line, so
     * an abandoned Buy Now stayed in the basket - and went into the shopper's
     * account at sign-in - overwrote the quantity of a line they already had,
     * and left the rest of the basket unticked for good. Now it writes a
     * separate line flagged buy_now, replacing any earlier one, and checkout
     * reads that line instead of the ticked basket for as long as it exists
     * (Cart::scopeForCheckout). The line goes when the order is placed, when
     * the shopper goes back to the basket (cancelBuyNow), or after 30 idle
     * minutes (BasketUpkeep).
     */
    public function buyNow(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::cart($request);
            if ($validate) {
                return response()->json($validate);
            }

            if (is_null($this->ownerScope($request, Cart::query()))) {
                return response()->json(Validation::errorLang($lang));
            }

            $inventory = UpdatedInventory::where('id', $request->inventory_id)
                ->where('product_id', $request->product_id)
                ->first();

            if (is_null($inventory)) {
                return response()->json(Validation::error(
                    $request->token, __('lang.nothing_found', [], $lang)
                ));
            }

            if ($stockError = $this->stockError($inventory, $request->quantity, $lang)) {
                return response()->json(Validation::error($request->token, $stockError));
            }

            $cart = \DB::transaction(function () use ($request, $inventory) {

                // One Buy Now at a time: the newest replaces any earlier one.
                $this->ownerScope($request, Cart::query())->where('buy_now', 1)->delete();

                return $this->createCartRow($request, $inventory, [
                    'selected' => Config::get('constants.status.PUBLIC'),
                    'buy_now' => 1,
                ]);
            });

            return response()->json(new Response($request->token, $cart));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Can the caller's checkout be bought right now? Asked by the payment
     * page before it charges a card through a gateway that takes the money
     * before the order exists (SumUp). A line whose reservation ran out can
     * have sold out since it was added; finding out after the charge left the
     * shopper paid-for with no order. Same rule and same refusal shape as the
     * order endpoint (CheckoutStock), so the page shows it the same way.
     */
    public function checkoutCheck(Request $request)
    {
        try {
            $lang = $request->header('language');

            $scope = $this->ownerScope($request, Cart::query());

            if (is_null($scope)) {
                return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
            }

            $lines = $scope->forCheckout()->with('product')->with('updated_inventory')->get();

            if ($lines->isEmpty()) {
                return response()->json(Validation::error($request->token, __('lang.no_cart', [], $lang)));
            }

            $refusals = CheckoutStock::refusals(
                $lines,
                optional($request->user('user'))->id,
                $request->user_token,
                $lang
            );

            if ($refusals) {
                return response()->json(Validation::error($request->token, $refusals, 'product'));
            }

            return response()->json(new Response($request->token, ['lines' => $lines->count()]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** The shopper has read these basket notices: stop showing them. */
    public function dismissNotices(Request $request)
    {
        $owner = CartHold::ownerFromRequest($request);

        $ids = is_array($request->input('ids')) ? $request->input('ids') : null;

        $removed = $owner ? BasketNotice::dismiss($owner[0], $owner[1], $ids) : 0;

        return response()->json(new Response($request->token, ['removed' => (int)$removed]));
    }

    /**
     * "Add them back": put the lines of an expired basket back in it.
     *
     * Each line goes through the same add the cross-sell and bundle buttons
     * use - the variant must still belong to the product, the quantity must
     * be in stock, the price is today's - so a line that cannot come back is
     * reported, not forced in. The notice is dismissed either way, and the
     * reservation middleware holds the restored units once this returns.
     */
    public function restoreNotice(Request $request)
    {
        try {
            $lang = $request->header('language');
            $owner = CartHold::ownerFromRequest($request);
            $notice = $owner ? BasketNotice::find($owner[0], $owner[1], $request->input('id')) : null;

            if (!$notice || $notice['kind'] !== BasketNotice::EXPIRED) {
                return response()->json(Validation::error($request->token, __('lang.nothing_found', [], $lang)));
            }

            $restored = [];
            $refused = [];

            foreach ($notice['lines'] ?? [] as $line) {
                $result = $this->addCrossSellLine(
                    $request, (int)$line['product_id'], (int)$line['inventory_id'], max(1, (int)$line['quantity']), $lang
                );

                if (isset($result['error'])) {
                    $refused[] = ['title' => $line['title'] ?? 'Item', 'reason' => $result['error']];
                } else {
                    $restored[] = $line['title'] ?? 'Item';
                }
            }

            BasketNotice::dismiss($owner[0], $owner[1], [$notice['id']]);

            return response()->json(new Response($request->token, [
                'restored' => $restored,
                'refused' => $refused,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Drop the caller's Buy Now line, if any. The basket page calls this when
     * it opens: a shopper who went back to the basket has left that one-item
     * checkout, and a forgotten Buy Now must not take over the next checkout
     * they start from the basket. Nothing in the basket itself changes.
     */
    public function cancelBuyNow(Request $request)
    {
        try {
            $scope = $this->ownerScope($request, Cart::query());

            $removed = $scope ? $scope->where('buy_now', 1)->delete() : 0;

            return response()->json(new Response($request->token, ['removed' => (int)$removed]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function action(Request $request)
    {

        try {

            $lang = $request->header('language');

            $validate = Validation::cartDelta($request);
            if ($validate) {
                return response()->json($validate);
            }

            $q = Cart::query();


            if($this->user && !$request->user_token){

                $q = $q->where('admin_id', $this->user->id);

            } else if ($request->user('user')) {

                $q = $q->where('user_id', $request->user('user')->id);

            } else if($request->user_token){

                $setting = Setting::select('guest_checkout')->first();
                if(!$setting->guest_checkout){
                    return response()->json(Validation::unauthorized());
                }

                $q = $q->where('user_token', $request->user_token);

            }else {

                return response()->json(Validation::errorLang($lang));
            }


            $q = $q->where('product_id', $request->product_id);
            $q = $q->where('inventory_id', $request->inventory_id);
            // The basket line, never a Buy Now checkout of the same variant.
            $q = $q->where('buy_now', 0);

            /*
             * Locked, not merely read.
             *
             * carts now carries a unique index per owner, product and variant,
             * so two adds of the same line racing each other would have the
             * second one fail its insert rather than fold into the first.
             * Selecting for update takes the gap lock on that index, which
             * makes the second request wait and then find the row the first one
             * wrote — the "+1 twice gives 2, not an error" behaviour a
             * double-clicked add-to-cart button depends on.
             */
            return \DB::transaction(function () use ($request, $q, $lang) {

                $existingCart = $q->lockForUpdate()->first();

                /*
                 * The variant has to belong to the product being added.
                 *
                 * Both ids come from the request and were never tested against
                 * each other, so a line could pair any product with any
                 * variant -- and serverSidePricing would then charge that
                 * variant's price against this product's tax rule and stock.
                 */
                $inventory = UpdatedInventory::where('id', $request->inventory_id)
                    ->where('product_id', $request->product_id)
                    ->first();

                if (is_null($inventory)) {
                    return response()->json(Validation::error(
                        $request->token, __('lang.nothing_found', [], $lang)
                    ));
                }

                if ($existingCart) {

                    $updatedQuantity = $existingCart->quantity + $request->quantity;

                    /*
                     * STK-02 still holds — a stored line may never go negative or
                     * past the cap — but the guard belongs here, on the resulting
                     * total, because the request itself carries a delta and "-" on
                     * the last unit is a legitimate -1.
                     */
                    if ($updatedQuantity < 1) {
                        return response()->json(Validation::error($request->token, __('lang.cart_quantity_minimum', [], $lang)));
                    }

                    if ($updatedQuantity > Validation::MAX_LINE_QUANTITY) {
                        return response()->json(Validation::error($request->token, __('lang.cart_quantity_maximum', ['max' => Validation::MAX_LINE_QUANTITY], $lang)));
                    }

                    /*
                     * Stock only limits going up. A line can sit above what
                     * is left ("Only 2 available") once its reservation has
                     * run out; "-" is how the shopper fixes that, and
                     * refusing it because stock is short left the line stuck.
                     */
                    if ((int)$request->quantity > 0
                        && $stockError = $this->stockError($inventory, $updatedQuantity, $lang)) {
                        return response()->json(Validation::error($request->token, $stockError));
                    }

                    /*
                     * Re-price on the new quantity. A business account crossing a
                     * band boundary with "+" would otherwise keep the unit price
                     * banked when the line was created, so the cart showed one
                     * figure and checkout — which prices from the catalogue —
                     * charged another.
                     */

                    $pricing = $this->serverSidePricing(
                        $request->product_id, $inventory,
                        $updatedQuantity, $request->user('user')
                    );

                    Cart::where('id', $existingCart->id)->update([
                        'quantity' => $updatedQuantity,
                        'price' => $pricing['price'],
                        'original_price' => $pricing['original_price'],
                        // the price the shopper chose it at ("price changed" notice)
                        'added_price' => $pricing['price'],
                    ]);

                    $existingCart->quantity = $updatedQuantity;
                    $existingCart->price = $pricing['price'];
                    $existingCart->original_price = $pricing['original_price'];
                    $cart = $existingCart;

                } else {

                    /*
                     * QA audit STK-01. This branch had no stock check at all — the
                     * one above only guarded a merge into an existing row — so a
                     * first add of 100 units against a 5-unit line went straight
                     * through to checkout.
                     */
                    if ($request->quantity < 1) {
                        return response()->json(Validation::error($request->token, __('lang.cart_quantity_minimum', [], $lang)));
                    }

                    if ($stockError = $this->stockError($inventory, $request->quantity, $lang)) {
                        return response()->json(Validation::error($request->token, $stockError));
                    }

                    $cart = $this->createCartRow($request, $inventory);
                }

                /*
                 * Answer with the figures this line was priced at, under the
                 * same names the basket read uses.
                 *
                 * The storefront used to take only `quantity` from this
                 * response and keep the unit price it already had, so a
                 * business account stepping across a band boundary saw the
                 * previous band's rate multiplied by the new quantity. The
                 * server had already decided the right number; nothing carried
                 * it across.
                 */
                $cart->unit_price = Pricing::round((float)$cart->price);
                $cart->upsell_price = $cart->unit_price;
                $cart->original_price = Pricing::round((float)$cart->original_price);
                $cart->line_total = Pricing::round(
                    $cart->unit_price * (int)$cart->quantity
                );

                /*
                 * The VAT split for that price, under the same names byUser
                 * answers with.
                 *
                 * Without it this response carried a new unit price and no
                 * split, so a caller showing both had a gross figure left over
                 * from the previous quantity sitting next to a net one from
                 * this quantity. The two endpoints return the same row shape;
                 * they should describe it the same way.
                 */
                $this->attachVatSplit($cart, $request->product_id);

                return response()->json(new Response($request->token, $cart));
            });

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Move a basket line onto a better variant of the same product.
     *
     * This is what makes an upgrade an upgrade rather than a second purchase:
     * the line does not gain a sibling, it CHANGES. The 128GB becomes the
     * 256GB at the quantity already on it, and a basket can never end up
     * holding both.
     *
     * Everything is re-proved here rather than trusted from the page: the
     * target has to belong to the same product, still be offered as an upgrade
     * of what the line currently holds, still be buyable, and still have
     * enough stock for the quantity on the line. All four can change between
     * the page rendering and the click.
     *
     * The whole thing runs in one transaction with the line locked, so two
     * clicks cannot each read the line and each write a replacement.
     */
    public function switchVariant(Request $request)
    {
        try {
            $lang = $request->header('language');

            $scope = $this->ownerScope($request, Cart::query());

            if (is_null($scope)) {
                return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
            }

            // Switching a variant changes a basket line, never a Buy Now checkout.
            $scope->inBasket();

            return \DB::transaction(function () use ($request, $scope, $lang) {
                return $this->performSwitch($request, $scope, $lang);
            });

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** The switch itself, inside the transaction opened by switchVariant(). */
    private function performSwitch(Request $request, $scope, $lang)
    {
        /*
         * The line being moved. Named by cart id where the caller has one --
         * the basket page knows which row it is offering to change. Otherwise
         * it is whichever line holds this product, which is what the product
         * page means when it upgrades something already in the basket.
         */
        $current = (clone $scope)
            ->where('product_id', (int)$request->input('product_id'))
            ->when($request->filled('cart_id'), function ($query) use ($request) {
                return $query->where('id', (int)$request->input('cart_id'));
            })
            ->lockForUpdate()
            ->first();

        if (is_null($current)) {
            return response()->json(Validation::error(
                $request->token, __('lang.nothing_found', [], $lang)
            ));
        }

        $product = Product::find($current->product_id);
        $currentInventory = UpdatedInventory::find($current->inventory_id);
        $quantity = (int)$current->quantity;

        $service = new ProductUpgradeService();

        $result = $service->validate(
            $product,
            $currentInventory,
            $request->input('inventory_id'),
            $request->user('user'),
            $quantity
        );

        if (!$result['ok']) {
            return response()->json(Validation::error(
                $request->token,
                ProductUpgradesController::message($result['reason'], $lang),
                'upgrade'
            ));
        }

        $target = UpdatedInventory::find((int)$request->input('inventory_id'));

        if ($stockError = $this->stockError($target, $quantity, $lang)) {
            return response()->json(Validation::error($request->token, $stockError));
        }

        return $this->moveLineTo($request, $scope, $current, $target, $quantity, $lang);
    }

    /**
     * Point the line at the new variant, merging if the basket already holds it.
     *
     * Merging rather than inserting is both what keeps one line per owner,
     * product and variant, and what the shopper means: upgrading a 128GB when
     * a 256GB is already in the basket gives two of the 256GB, not a duplicate
     * row.
     */
    private function moveLineTo(Request $request, $scope, $current, $target, $quantity, $lang)
    {
        $existing = (clone $scope)
            ->where('product_id', $current->product_id)
            ->where('inventory_id', $target->id)
            ->where('id', '!=', $current->id)
            ->lockForUpdate()
            ->first();

        $newQuantity = $quantity + (int)($existing->quantity ?? 0);

        if ($newQuantity > Validation::MAX_LINE_QUANTITY) {
            return response()->json(Validation::error(
                $request->token,
                __('lang.cart_quantity_maximum', ['max' => Validation::MAX_LINE_QUANTITY], $lang)
            ));
        }

        if ($existing && $stockError = $this->stockError($target, $newQuantity, $lang)) {
            return response()->json(Validation::error($request->token, $stockError));
        }

        $line = $existing ?: $current;

        $pricing = $this->serverSidePricing(
            $current->product_id, $target, $newQuantity, $request->user('user')
        );

        /*
         * price and original_price are guarded on the model (QA audit
         * CART-01), so they are written explicitly rather than mass-assigned.
         * A voucher was applied against the old line total, so it is cleared
         * and re-earned rather than carried onto a different price.
         */
        $line->forceFill([
            'inventory_id' => $target->id,
            'quantity' => $newQuantity,
            'price' => $pricing['price'],
            'original_price' => $pricing['original_price'],
            // the price the shopper chose it at ("price changed" notice)
            'added_price' => $pricing['price'],
            'voucher_code' => null,
            'voucher_discount' => 0,
        ])->save();

        // Only when the merge target was a different row.
        $merged = $existing && (int)$existing->id !== (int)$current->id;

        if ($merged) {
            $current->delete();
        }

        $line->unit_price = Pricing::round((float)$line->price);
        $line->upsell_price = $line->unit_price;
        $line->original_price = Pricing::round((float)$line->original_price);
        $line->line_total = Pricing::round($line->unit_price * $newQuantity);

        $this->attachVatSplit($line, $current->product_id);

        return response()->json(new Response($request->token, [
            'cart' => $line,
            'removed_cart_id' => $merged ? (int)$current->id : null,
            'quantity' => $newQuantity,
        ]));
    }


    public function updateShipping(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::shippingCart($request);

            if ($validate){
                return response()->json($validate);
            }

            $cartArrz = [];

            $cartIds = [];

            foreach ($request->cart as $i) {
                array_push($cartIds, $i['cart']);

                // Only a collection point is ever read from this; see
                // requestedPickupPoint(). The row charged is decided below.
                $v['shipping_place_id'] = data_get($i, 'shipping_place.id');

                $v['shipping_type'] = $i['shipping_type'] ?? null;
                $v['cart'] = $i['cart'];
                array_push($cartArrz, $v);
            }

            /*
             * QA audit CART-03. This read and then wrote whatever cart ids the
             * request named, so shipping method and cost could be changed on
             * another shopper's basket.
             */
            $ownedCarts = $this->ownerScope($request, Cart::query());

            if (is_null($ownedCarts)) {
                return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
            }

            $carts = $ownedCarts->forCheckout()->whereIn('id', $cartIds)
                ->with('product')
                ->with('updated_inventory')
                ->get();

            // Only ids we actually own may reach the update below.
            $ownedIds = $carts->pluck('id')->all();

            $cartArrz = array_values(array_filter($cartArrz, function ($v) use ($ownedIds) {
                return in_array((int)$v['cart'], array_map('intval', $ownedIds), true);
            }));

            /*
             * Published and in stock for the quantity asked - not merely "stock
             * above zero", which let a 3-unit line through against 1 left. The
             * same rule the order and the pre-payment check use.
             */
            $cartError = CheckoutStock::refusals(
                $carts,
                optional($request->user('user'))->id,
                $request->user_token,
                $lang
            );

            if (count($cartError) > 0) {
                return response()->json(Validation::error($request->token, $cartError, 'product'));
            }



            /*
             * Click-and-collect. Nothing is being delivered, so no address is
             * asked for — but the store still has to be able to reach the
             * customer when the order is ready to pick up. The address record
             * was previously the only place a guest ever supplied a name, email
             * and phone, so for a collection order those are captured on their
             * own and kept on the guest record until the order is created.
             */
            /*
             * One way of receiving the order, for the whole order. Delivery is
             * charged once per order now, so a basket that is half posted and
             * half collected has no single price - and a type other than 1 or 2
             * used to be charged nothing at all.
             */
            $shippingType = $this->requestedShippingType($request, $cartArrz);

            if (!Shipping::isValidType($shippingType)) {
                return response()->json(Validation::error($request->token,
                    'Choose whether to have your order delivered or to collect it.'
                ));
            }

            $deliveryAddress = null;

            if ((int)$shippingType === Shipping::pickupType()) {

                if ($contactError = $this->storeCollectionContact($request, $lang)) {
                    return response()->json($contactError);
                }

            } else if ($this->user && !$request->user_token) {

                // Point of sale: the till works on behalf of the customer it
                // selected, so their address is the one to deliver to.
                if ($request->selected_address) {
                    $deliveryAddress = UserAddress::find($request->selected_address);
                }

            } else if($request->user('user') && $request->selected_address) {

                // The address must belong to the caller (QA audit CART-03).
                $userAddress = UserAddress::where('id', $request->selected_address)
                    ->where('user_id', $request->user('user')->id)
                    ->first();

                if (is_null($userAddress)) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                $deliveryAddress = $userAddress;

                User::where('id', $request->user('user')->id)
                    ->update(['default_address' => $request->selected_address]);

            } else if($request->user_token && $request->selected_address){

                $userAddress = UserAddress::where('id', $request->selected_address)
                    ->where('user_token', $request->user_token)
                    ->first();

                // Was dereferenced unguarded, and without the ownership clause
                // any guest could adopt another guest's address.
                if (is_null($userAddress)) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                $deliveryAddress = $userAddress;

                GuestUser::where('user_token', $request->user_token)
                    ->update([
                        'name' => $userAddress->name,
                        'default_address' => $request->selected_address
                    ]);
            }

            if ((int)$shippingType === Shipping::deliveryType() && !$deliveryAddress) {
                $deliveryAddress = $this->savedDeliveryAddress($request);
            }

            if ((int)$shippingType === Shipping::deliveryType() && !$deliveryAddress) {
                return response()->json(Validation::error($request->token,
                    __('lang.select_address', [], $lang)
                ));
            }

            /*
             * The row and its price are decided here, from the address - never
             * taken from the request, which used to name any shipping row it
             * liked, including another country's cheaper one.
             */
            $quote = Shipping::quote(
                $shippingType,
                optional($deliveryAddress)->country,
                optional($deliveryAddress)->state,
                $this->requestedPickupPoint($request, $cartArrz),
                $carts->pluck('product'),
                Shipping::linesWeight($carts)
            );

            if (!$quote['ok']) {
                return response()->json($this->shippingRefusal($request, $quote, $carts));
            }

            $placeId = $quote['place']->id;

            \DB::transaction(function () use ($cartArrz, $placeId, $shippingType) {
                foreach ($cartArrz as $key => $value) {
                    Cart::where('id', '=', $value['cart'])->update([
                            'shipping_place_id' => $placeId,
                            'shipping_type' => (int)$shippingType
                        ]
                    );
                }
            });



            $query = Cart::query();
            $query = $query->with('flash_product.shipping_rule.shipping_places');
            /*
             * tax_rules rides along because the repricing below reads the
             * product's own rule for the VAT split; without it that is a
             * query per line.
             */
            $query = $query->with('flash_product.tax_rules');
            $query = $query->with('updated_inventory.inventory_attributes.attribute_value.attribute');
            $query = $query->with('shipping_place.shipping_rule');
            /*
             * original_price, under the name repriceForCustomer reads it by,
             * so it can tell whether the stored figures have drifted without
             * a query per line. The same select byUser uses.
             */
            $query = $query->select('id', 'product_id', 'user_id', 'inventory_id', 'quantity',
                'selected', 'shipping_place_id', 'shipping_type' , 'price as upsell_price',
                'original_price as stored_original_price', 'added_price', 'buy_now');


            if($this->user && !$request->user_token){

                $query = $query->where('admin_id', $this->user->id);

            } else if ($request->user('user')) {

                $query = $query->where('user_id', $request->user('user')->id);

            } else if($request->user_token){

                $query = $query->where('user_token', $request->user_token);

            } else {

                return response()->json(Validation::errorLang($lang));
            }



            $data = $query->get();

            /*
             * The same repricing byUser does, for the same reason - and this
             * is the call that made it necessary rather than merely tidy.
             *
             * The storefront REPLACES its basket with this response
             * (store/cart.js INSERT_CART_SHIPPING), and /shipping posts here
             * and then pushes straight to /checkout. Answering with bare rows
             * therefore did not just omit a few fields: it stripped the unit
             * price, the list price and the whole VAT split out of the basket
             * on the last hop before the summary that reads them, so /checkout
             * opened on a dash in every money row and "Updating prices..."
             * underneath - over a basket /cart had priced correctly a moment
             * earlier.
             *
             * Choosing a delivery method does not change what a product costs,
             * so this returns the figures byUser returns. That is the point:
             * the two must not disagree, or the summary moves between /cart
             * and /checkout without the customer doing anything.
             */
            $this->repriceForCustomer($request, $data);
            $this->priceShippingByWeight($data);
            $this->applyMemberShipping($request, $data);

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Hand a guest basket over to the signed-in account.
     *
     * Signing in through /user/signin merges the basket as part of creating the
     * session, but not every route into an account passes through there: a
     * social provider comes back on a redirect carrying a bearer token and
     * nothing else, so the storefront has to ask for the merge itself once it
     * knows who it is.
     *
     * Safe to call as often as the storefront likes. The merge consumes the
     * guest rows, so a second call moves nothing.
     */
    public function merge(Request $request)
    {
        try {
            $lang = $request->header('language');

            $customer = $request->user('user');

            if (!$customer) {
                return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
            }

            /*
             * ValidateGuestToken waves a request through once a customer is
             * signed in — it exists to stop forged guest identities, and a
             * bearer token is a better identity than any of them. This endpoint
             * is the one place that still cares, because the token names whose
             * basket to absorb, so the same "must be one we issued" rule is
             * applied here.
             */
            if (!$request->user_token
                || !GuestUser::where('user_token', $request->user_token)->exists()
            ) {
                return response()->json(Validation::error($request->token, __('lang.nothing_found', [], $lang)));
            }

            $summary = CartMerge::forUser($customer, $request->user_token);

            $summary['cart_count'] = (int)Cart::where('user_id', $customer->id)->inBasket()->sum('quantity');

            return response()->json(new Response($request->token, $summary));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function changeSelected(Request $request)
    {
        try {
            $lang = $request->header('language');

            /*
             * QA audit CART-03. Both updates took ids straight from the request
             * with no owner filter, so anybody could tick or untick anybody
             * else's basket by guessing sequential ids.
             */
            if (is_array($request->checked) && count($request->checked)) {
                $q = $this->ownerScope($request, Cart::query());

                if (is_null($q)) {
                    return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
                }

                $q->whereIn('id', $request->checked)
                    ->update(['selected' => Config::get('constants.status.PUBLIC')]);
            }

            if (is_array($request->unchecked) && count($request->unchecked)) {
                $q = $this->ownerScope($request, Cart::query());

                if (is_null($q)) {
                    return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
                }

                $q->whereIn('id', $request->unchecked)
                    ->update(['selected' => Config::get('constants.status.PRIVATE')]);
            }

            return response()->json(new Response('', true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            // QA audit CART-03: was Cart::find($id) with no owner check.
            $query = $this->ownerScope($request, Cart::query());

            if (is_null($query)) {
                return response()->json(Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang)));
            }

            $cart = $query->where('id', $id)->first();

            if (is_null($cart)){
                return response()->json(Validation::nothingFoundLang($lang));
            }


            if ($cart->delete()) {
                return response()->json(new Response($request->token, $cart));
            }

            return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Constrain a cart query to whoever is making the request.
     *
     * Returns null when the caller presented no identity at all, which the
     * callers turn into an unauthorised response. Every query that reads or
     * writes cart rows goes through here (QA audit CART-02, CART-03).
     */

    /* ------------------------------------------------------------------ *
     *  Cross-sell
     * ------------------------------------------------------------------ */

    /**
     * Add the recommendations the customer ticked.
     *
     * Lives here, beside add-to-basket, because a cross-sell product IS a
     * normal basket line the moment it is taken (section 18). It reuses the
     * same stock check, the same server-side pricing and the same owner
     * columns as `action()`; a second implementation would be a second answer
     * to "what does this line cost" and they would diverge.
     *
     * NOTHING the page sends about price, stock or eligibility is trusted
     * (sections 11 and 34). The page was rendered at some earlier moment. In
     * between, the product can be unpublished, the variant can sell out, and
     * the admin can switch the recommendation off -- so every line is proved
     * again here, against the catalogue, right now.
     *
     * Partial success is a real outcome and is reported as one. Four ticks
     * where the third has just sold out adds three and says which one did not
     * go and why. Refusing all four because of one would be worse for the
     * shopper and is not what they asked for.
     */
    public function addCrossSell(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($validate = Validation::crossSellAdd($request)) {
                return response()->json($validate);
            }

            /*
             * The basket has to belong to somebody before anything is written
             * to it -- the same three ways every other write here identifies a
             * caller, guests included (section 28).
             */
            if (is_null($this->ownerScope($request, Cart::query()))) {
                return response()->json(Validation::errorLang($lang));
            }

            $service = new CrossSellService();

            /*
             * What these recommendations have to be recommendations OF.
             *
             * The product the customer is looking at, plus everything already
             * in their basket. Without this the endpoint would add any product
             * id the page cared to send, which is an unrestricted write
             * wearing a cross-sell name.
             */
            $primaryIds = $this->crossSellPrimaries($request);

            if (empty($primaryIds)) {
                return response()->json(Validation::error(
                    $request->token, __('lang.cross_sell_gone', [], $lang)
                ));
            }

            $added = [];
            $skipped = [];

            foreach ($this->crossSellLines($request) as $line) {
                $refusal = $service->refusalFor(
                    $primaryIds, $line['product_id'], $line['inventory_id']
                );

                if ($refusal) {
                    $skipped[] = [
                        'product_id' => $line['product_id'],
                        'message' => __($refusal[0], $refusal[1], $lang),
                    ];

                    continue;
                }

                /*
                 * A product the customer was not asked to choose for has
                 * exactly one buyable option, which refusalFor has already
                 * established -- so this is not a choice being made on their
                 * behalf.
                 */
                $inventoryId = $line['inventory_id']
                    ?: $service->defaultInventoryId($line['product_id']);

                $result = $this->addCrossSellLine(
                    $request, $line['product_id'], $inventoryId, $line['quantity'], $lang
                );

                if (isset($result['error'])) {
                    $skipped[] = [
                        'product_id' => $line['product_id'],
                        'message' => $result['error'],
                    ];

                    continue;
                }

                $added[] = $result['cart'];
            }

            /*
             * Nothing added at all is a refusal, so the storefront shows the
             * reason rather than a success toast over an unchanged basket.
             * The per-product reasons ride along either way.
             */
            if (empty($added)) {
                $message = $skipped[0]['message'] ?? __('lang.cross_sell_not_added', [], $lang);

                return response()->json(new Response(
                    $request->token, ['added' => [], 'skipped' => $skipped], 201, $message
                ));
            }

            return response()->json(new Response($request->token, [
                'added' => $added,
                'skipped' => $skipped,
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Take an Essentials Bundle: every product in it, in one go.
     *
     * The bundle equivalent of addCrossSell, and different from it in exactly
     * one way -- it is ALL OR NOTHING. A recommendation block that adds three
     * of four ticks has done what the shopper asked; a bundle that adds four
     * of five items has charged them for something that is not the bundle they
     * were quoted. So every line is written inside one transaction and any
     * refusal rolls the whole thing back.
     *
     * Everything else is the same machinery as the ordinary add: the same
     * eligibility check, the same stock check, the same server-side pricing,
     * the same merge into an existing line. A bundle item becomes a normal
     * basket line the moment it is taken.
     */
    public function addBundle(Request $request)
    {
        try {
            $lang = $request->header('language');

            $primaryId = (int)$request->input('product_id');
            $bundleId = (int)$request->input('bundle_id');

            if ($primaryId < 1 || $bundleId < 1) {
                return response()->json(Validation::error(
                    $request->token, __('lang.bundle_gone', [], $lang)
                ));
            }

            // The basket has to belong to somebody before anything is written
            // to it -- guests included, the same way every other write here
            // identifies a caller.
            if (is_null($this->ownerScope($request, Cart::query()))) {
                return response()->json(Validation::errorLang($lang));
            }

            $service = new BundleService();

            if ($refusal = $service->refusalFor($primaryId, $bundleId)) {
                return response()->json(new Response(
                    $request->token, ['added' => []], 201,
                    __($refusal[0], $refusal[1], $lang)
                ));
            }

            $primary = Product::find($primaryId);

            $lines = $service->linesFor($primary, $bundleId);

            if (empty($lines)) {
                return response()->json(new Response(
                    $request->token, ['added' => []], 201,
                    __('lang.bundle_unavailable', [], $lang)
                ));
            }

            /*
             * One transaction around the whole bundle. addCrossSellLine opens
             * its own for a single line, which nests harmlessly -- the outer
             * one is what makes "all five or none" true, so a fifth item that
             * has just sold out cannot leave four in the basket at prices
             * quoted as part of a set.
             */
            $result = \DB::transaction(function () use ($request, $lines, $lang, $bundleId) {
                $added = [];

                foreach ($lines as $line) {
                    $written = $this->addCrossSellLine(
                        $request, $line['product_id'], $line['inventory_id'],
                        $line['quantity'], $lang, $bundleId
                    );

                    if (isset($written['error'])) {
                        return ['error' => $written['error']];
                    }

                    $added[] = $written['cart'];
                }

                return ['added' => $added];
            });

            if (isset($result['error'])) {
                return response()->json(new Response(
                    $request->token, ['added' => []], 201, $result['error']
                ));
            }

            return response()->json(new Response($request->token, [
                'bundle_id' => $bundleId,
                'added' => $result['added'],
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * What the Essentials Bundles in this basket are worth right now.
     *
     * Its own endpoint, like the loyalty discount, rather than a new field on
     * the basket read -- that response is a flat ARRAY of lines and every
     * screen in the storefront reads it as one, so growing an envelope around
     * it to carry a single number would be a shape change for the sake of a
     * total.
     *
     * The basket is read server-side from the caller's own rows. Nothing about
     * it is taken from the request, because a discount the page could describe
     * is a discount the page could invent.
     *
     * Only SELECTED lines count: a deselected line is not being bought, and a
     * bundle whose items are not all being bought is not being bought either.
     * That matches what checkout charges, which is the figure that matters.
     */
    /**
     * The shopper has started paying: keep the basket for CartHold's paying
     * grace, so it is not emptied while the card is being charged - and the
     * Buy Now line too, which is otherwise cleared after 30 idle minutes.
     */
    public function holdForPayment(Request $request)
    {
        $owner = CartHold::ownerFromRequest($request);

        if ($owner) {
            CartHold::markPaying($owner[0], $owner[1]);
            BasketUpkeep::touchBuyNow($owner[0], $owner[1]);
        }

        return response()->json(new Response($request->token, true));
    }


    public function bundleDiscount(Request $request)
    {
        try {
            $scope = $this->ownerScope($request, Cart::query());

            if (is_null($scope)) {
                return response()->json(new Response($request->token, [
                    'total' => 0, 'bundles' => [],
                ]));
            }

            $rows = $scope->forCheckout()->get();

            return response()->json(new Response(
                $request->token, BundleDiscount::forRows($rows)
            ));

        } catch (\Exception $ex) {
            /*
             * Answered as "no discount" rather than as an error. This is a
             * figure ON TOP of a basket that is already on screen and already
             * correct, and checkout applies the discount itself regardless of
             * what this says -- so a failure here costs a line in the summary,
             * never the order.
             */
            return response()->json(new Response($request->token, [
                'total' => 0, 'bundles' => [],
            ]));
        }
    }

    /**
     * The products these recommendations must belong to.
     *
     * The one on the page, plus whatever the basket already holds -- a shopper
     * on the basket page is being shown recommendations for all of it.
     */
    private function crossSellPrimaries(Request $request)
    {
        $ids = collect((array)$request->input('primary_product_ids', []))
            ->push($request->input('primary_product_id'))
            ->filter()
            ->map(function ($id) { return (int)$id; });

        $basket = $this->ownerScope($request, Cart::query());

        if (!is_null($basket)) {
            $ids = $ids->merge($basket->pluck('product_id'));
        }

        return $ids->filter()->map(function ($id) { return (int)$id; })->unique()->values()->all();
    }

    /**
     * The chosen lines, normalised.
     *
     * Deduplicated on the product, last one winning, so a page that submits
     * the same tick twice asks for one line rather than two adds of one --
     * which would read as a quantity of two the customer never chose.
     */
    private function crossSellLines(Request $request)
    {
        $lines = [];

        foreach ((array)$request->input('products', []) as $row) {
            $productId = (int)(is_array($row) ? ($row['product_id'] ?? 0) : $row);

            if ($productId < 1) {
                continue;
            }

            $inventoryId = is_array($row) && !empty($row['inventory_id'])
                ? (int)$row['inventory_id']
                : null;

            /*
             * Quantity is bounded here as well as by the line cap below,
             * because this one arrives straight from the page and a negative
             * would read as a REMOVAL once it reached the merge.
             */
            $quantity = is_array($row) ? (int)($row['quantity'] ?? 1) : 1;
            $quantity = max(1, min($quantity, Validation::MAX_LINE_QUANTITY));

            $lines[$productId] = [
                'product_id' => $productId,
                'inventory_id' => $inventoryId,
                'quantity' => $quantity,
            ];
        }

        return array_values($lines);
    }

    /**
     * Write one cross-sell line, merging into what the basket already has.
     *
     * Section 12. A product already in the basket gains quantity rather than a
     * second line -- the same behaviour, through the same lock, as `action()`.
     * The unique index over owner, product and variant makes two of these
     * racing each other wait rather than both insert, so a double-clicked
     * "Add Selected" adds one lot.
     *
     * Returns either ['cart' => row] or ['error' => message], so the caller
     * can report per product without this deciding how.
     */
    private function addCrossSellLine(Request $request, $productId, $inventoryId, $quantity, $lang, $bundleId = null)
    {
        return \DB::transaction(function () use ($request, $productId, $inventoryId, $quantity, $lang, $bundleId) {

            /*
             * The variant has to belong to the product, re-proved here rather
             * than taken from the page -- the same guard action() applies, for
             * the same reason: otherwise a line could pair any product with
             * any variant and be priced and stocked against the wrong one.
             */
            $inventory = UpdatedInventory::where('id', $inventoryId)
                ->where('product_id', $productId)
                ->first();

            if (is_null($inventory)) {
                return ['error' => __('lang.cross_sell_gone', [], $lang)];
            }

            $scope = $this->ownerScope($request, Cart::query());

            $existing = $scope
                ->inBasket()
                ->where('product_id', $productId)
                ->where('inventory_id', $inventory->id)
                ->lockForUpdate()
                ->first();

            $wanted = $existing ? (int)$existing->quantity + $quantity : $quantity;

            /*
             * Section 12 again: at the cap, the line STAYS at the cap and the
             * customer is told. Silently adding nothing reads as a bug, and
             * silently exceeding it is how a basket goes past what can be
             * shipped.
             */
            if ($wanted > Validation::MAX_LINE_QUANTITY) {
                return ['error' => __('lang.cart_quantity_maximum',
                    ['max' => Validation::MAX_LINE_QUANTITY], $lang)];
            }

            if ($stockError = $this->stockError($inventory, $wanted, $lang)) {
                return ['error' => $stockError];
            }

            if ($existing) {
                /*
                 * Re-priced on the NEW quantity. A business account crossing a
                 * band boundary would otherwise keep the rate the line was
                 * created at, so the basket showed one figure and checkout --
                 * which prices from the catalogue -- charged another.
                 */
                $pricing = $this->serverSidePricing(
                    $productId, $inventory, $wanted, $request->user('user')
                );

                $changes = [
                    'quantity' => $wanted,
                    'price' => $pricing['price'],
                    'original_price' => $pricing['original_price'],
                    // the price the shopper chose it at ("price changed" notice)
                    'added_price' => $pricing['price'],
                ];

                /*
                 * A line the shopper already had becomes part of the bundle
                 * when they take one containing it -- otherwise buying the
                 * case on its own first would quietly disqualify them from
                 * the bundle price for the rest of it. Only ever set, never
                 * cleared: an ordinary add must not strip the marker off a
                 * line the bundle is still paying for.
                 */
                if ($bundleId) {
                    $changes['bundle_deal_id'] = (int)$bundleId;
                }

                Cart::where('id', $existing->id)->update($changes);

                $existing->quantity = $wanted;
                $existing->price = $pricing['price'];
                $existing->original_price = $pricing['original_price'];

                if ($bundleId) {
                    $existing->bundle_deal_id = (int)$bundleId;
                }

                $cart = $existing;

            } else {
                /*
                 * createCartRow reads product_id, inventory_id and quantity
                 * off the request, so the chosen variant is merged in rather
                 * than the page's own fields being used. It prices the line
                 * itself and writes the owner columns.
                 */
                $cart = $this->createCartRow(
                    $this->crossSellLineRequest($request, $productId, $inventory->id, $quantity),
                    $inventory,
                    $bundleId ? ['bundle_deal_id' => (int)$bundleId] : []
                );
            }

            /* The same row shape, under the same names, as action(). */
            $cart->unit_price = Pricing::round((float)$cart->price);
            $cart->upsell_price = $cart->unit_price;
            $cart->original_price = Pricing::round((float)$cart->original_price);
            $cart->line_total = Pricing::round($cart->unit_price * (int)$cart->quantity);

            $this->attachVatSplit($cart, $productId);

            return ['cart' => $cart];
        });
    }

    /**
     * The request createCartRow expects, for one cross-sell line.
     *
     * A copy rather than a mutation of the real one: this loop writes several
     * lines from a single request, and rewriting the caller's product_id in
     * place would leave every line after the first reading the one before it.
     * The owner fields -- user_token and the authenticated user -- are carried
     * across untouched, because that is what decides whose basket this is.
     */
    private function crossSellLineRequest(Request $request, $productId, $inventoryId, $quantity)
    {
        $line = Request::create('/', 'POST', [
            'product_id' => $productId,
            'inventory_id' => $inventoryId,
            'quantity' => $quantity,
            'user_token' => $request->user_token,
        ]);

        $line->setUserResolver($request->getUserResolver());

        return $line;
    }

    private function ownerScope(Request $request, $query)
    {
        if ($this->user && !$request->user_token) {
            return $query->where('admin_id', $this->user->id);
        }

        if ($request->user('user')) {
            return $query->where('user_id', $request->user('user')->id);
        }

        if ($request->user_token) {
            return $query->where('user_token', $request->user_token);
        }

        return null;
    }


    /**
     * Stock check for a line, shared by add-to-cart, buy-now and checkout.
     *
     * QA audit STK-01. The old test was
     * "quantity < 1 && is_active !== 1", which passed any quantity whenever
     * stock was non-zero, and never looked at how many units were being bought.
     *
     * is_active on an inventory row means backorder is allowed for that
     * variant, so those lines are intentionally permitted to go negative.
     */
    private function stockError($inventory, $wantedQuantity, $lang)
    {
        if (is_null($inventory)) {
            return __('lang.nothing_found', [], $lang);
        }

        if ((int)$inventory->is_active === 1) {
            return null;
        }

        /*
         * Stock on the row is what nobody is holding. This basket's own
         * reserved units are available to it too (CartHold): without them a
         * shopper holding the last unit could not change that line at all.
         */
        $owner = CartHold::ownerFromRequest(request());
        $ownHeld = $owner ? CartHold::heldFor($owner[0], $owner[1], $inventory->id) : 0;

        if ((int)$wantedQuantity > (int)$inventory->quantity + $ownHeld) {
            return __('lang.quantity_exceeds', [], $lang);
        }

        return null;
    }


    /**
     * Price a line from the catalogue.
     *
     * QA audit CART-01: 'price', 'original_price' and 'voucher_discount' used
     * to be whatever the request said they were.
     *
     * A business or approved trade account is priced against its quantity
     * bands here as well as at checkout, so the cart shows the figure the
     * order will actually charge.
     */
    private function serverSidePricing($productId, $inventory, $quantity = 1, $customer = null)
    {
        $product = Product::pricingRecord($productId);

        $tiers = BusinessProductPricing::forCustomer($customer, $productId);

        $price = Pricing::unitPrice($product, $inventory, $quantity, $tiers);

        return [
            'price' => Pricing::round($price),
            'original_price' => Pricing::listPrice($product, $inventory, $price)
        ];
    }


    /**
     * Describe a written cart line's price for VAT, the way byUser does.
     *
     * Same helper, same rule, same field names as repriceForCustomer(): the
     * split is decided here because the storefront must never re-derive it
     * from a rate, and because which side of VAT a price sits on depends on
     * the product — both conventions exist in this catalogue.
     *
     * Quantity matters: a "buy X get Y free" bundle does not tax the free
     * units (QA audit ORD-03), so the line split follows the payable quantity
     * while the unit split describes one unit.
     */
    private function attachVatSplit($cart, $productId)
    {
        $product = Product::pricingRecord($productId);

        if (is_null($product)) {
            return;
        }

        $rate = Pricing::vatRate($product->tax_rules ?? null);
        $inclusive = Pricing::isVatInclusive($product);

        $unitPrice = (float)$cart->unit_price;
        $quantity = (int)$cart->quantity;

        $unitSplit = Pricing::vatBreakdown($unitPrice, $rate, $inclusive);

        $freeUnits = Pricing::bundleFreeQuantity($product, $quantity);
        $payableQty = max(0, $quantity - (int)$freeUnits);

        $lineSplit = Pricing::vatBreakdown(
            Pricing::round($unitPrice * $payableQty), $rate, $inclusive
        );

        $cart->vat_rate = $unitSplit['rate'];
        $cart->vat_inclusive = $unitSplit['inclusive'];
        $cart->unit_ex_vat = $unitSplit['ex'];
        $cart->unit_vat = $unitSplit['vat'];
        $cart->unit_inc_vat = $unitSplit['inc'];
        $cart->line_ex_vat = $lineSplit['ex'];
        $cart->line_vat = $lineSplit['vat'];
        $cart->line_inc_vat = $lineSplit['inc'];

        /* The list price of the line, all three ways — see repriceForCustomer. */
        $listSplit = Pricing::vatBreakdown(
            Pricing::round((float)$cart->original_price * $quantity), $rate, $inclusive
        );

        $cart->line_list_inc_vat = $listSplit['inc'];
        $cart->line_list = Pricing::round((float)$cart->original_price * $quantity);
        $cart->line_list_ex_vat = $listSplit['ex'];
    }


    /**
     * Create a cart row with server-decided pricing and owner columns.
     */
    private function createCartRow(Request $request, $inventory, array $extra = [])
    {
        $attributes = [
            'product_id' => $request->product_id,
            'inventory_id' => $request->inventory_id,
            'quantity' => (int)$request->quantity,
        ];

        /*
         * Optional columns are named only when the caller supplied them.
         *
         * carts.shipping_type is NOT NULL and carts.upgrades does not exist on
         * every install (its migration is one of the pending ones), so writing
         * them unconditionally fails the insert. The previous
         * Cart::create($request->all()) omitted absent keys for free; doing it
         * explicitly keeps that behaviour without taking the request's word for
         * the price columns.
         */
        foreach (['shipping_place_id', 'shipping_type', 'upgrades'] as $optional) {
            if ($request->has($optional) && !is_null($request->input($optional))) {
                $attributes[$optional] = $request->input($optional);
            }
        }

        if ($this->user && !$request->user_token) {
            $attributes['admin_id'] = $this->user->id;

        } else if ($request->user('user')) {
            $attributes['user_id'] = $request->user('user')->id;

        } else if ($request->user_token) {
            $attributes['user_token'] = $request->user_token;

            // QA audit ORD-02: assign the created record, do not drop it.
            $guestUser = GuestUser::where('user_token', $request->user_token)->first();

            if (!$guestUser) {
                GuestUser::create(['user_token' => $request->user_token]);
            }
        }

        $cart = Cart::create(array_merge($attributes, $extra));

        $pricing = $this->serverSidePricing(
            $request->product_id, $inventory,
            $request->quantity, $request->user('user')
        );

        // Guarded columns, written explicitly rather than mass-assigned.
        $cart->forceFill([
            'price' => $pricing['price'],
            'original_price' => $pricing['original_price'],
            // the price the shopper chose it at ("price changed" notice)
            'added_price' => $pricing['price'],
            'voucher_code' => null,
            'voucher_discount' => 0,
        ])->save();

        CartTrace::lineCreated($cart);

        return $cart;
    }

    /**
     * What delivery would cost, before anything is saved.
     *
     * The shipping and guest checkout pages ask this as soon as the shopper
     * picks delivery or collection and an address (or, for a guest still
     * typing one, a country and county), so the price and any "cannot be
     * shipped to" lines are shown before they press continue. It reads the
     * caller's own selected basket lines for the country restriction, and
     * decides the price with the same Helper\Shipping call the order uses.
     */
    public function shippingQuote(Request $request)
    {
        try {
            $lang = $request->header('language');

            $type = $request->input('shipping_type');
            $country = null;
            $state = null;

            if ((int)$type === Shipping::deliveryType()) {

                if ($request->selected_address) {
                    $address = $this->ownedAddress($request, $request->selected_address);

                    if (is_null($address)) {
                        return response()->json(Validation::nothingFoundLang($lang));
                    }

                    $country = $address->country;
                    $state = $address->state;

                } else if ($request->boolean('default_address') && ($address = $this->defaultAddress($request))) {
                    /*
                     * The product page asks with only the visitor's country,
                     * which prices the whole-country row (Ireland, €24) for a
                     * shopper whose saved address sits under a cheaper county
                     * row (Dublin, €15) - and checkout then charged the €15.
                     * Pricing their default address makes the two agree.
                     */
                    $country = $address->country;
                    $state = $address->state;

                } else {
                    $country = $request->input('country');
                    $state = $request->input('state');
                }
            }

            $carts = collect();
            $owned = $this->ownerScope($request, Cart::query());

            if ($owned) {
                $carts = $owned->forCheckout()
                    ->with('product')
                    ->get();
            }

            /*
             * What is being weighed: the lines being bought, or - from the
             * product page (product_id) - that one item at the quantity in
             * its box, which is what the page's "+ shipping" line is about.
             */
            $weight = $request->filled('product_id')
                ? Shipping::linesWeight([[
                    'product_id' => $request->input('product_id'),
                    'quantity' => max(1, (int)$request->input('quantity', 1)),
                ]])
                : Shipping::linesWeight($carts);

            $quote = Shipping::quote(
                $type, $country, $state,
                $request->input('pickup_place_id'),
                $carts->pluck('product'),
                $weight
            );

            /*
             * Free for a member, as the basket and the order have it. The
             * product page's single item is weighed alone, but a member's
             * delivery is free on the basket they will actually check out.
             */
            $memberFree = false;

            if ($quote['ok'] && (int)$type === Shipping::deliveryType() && $request->user('user')
                && Membership::freeShippingFor($request->user('user'), Membership::checkoutLines($request->user('user'))['subtotal'])) {
                $quote['price'] = 0.0;
                $memberFree = true;
            }

            $result = Shipping::publicQuote($quote);
            $result['member_free_shipping'] = $memberFree;
            $result['restricted_carts'] = (object)$this->restrictedByCart($quote, $carts);

            return response()->json(new Response($request->token, $result));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Delivery or collection, for the whole order. A request may say it once
     * (shipping_type) or on every line, as the pages always have; lines that
     * disagree give no answer, which the caller refuses.
     */
    private function requestedShippingType(Request $request, array $cartArrz)
    {
        if ($request->filled('shipping_type')) {
            return $request->input('shipping_type');
        }

        $types = array_unique(array_map(function ($v) {
            return (string)$v['shipping_type'];
        }, $cartArrz));

        return count($types) === 1 ? reset($types) : null;
    }

    /**
     * The collection point asked for: pickup_place_id, or - from pages that
     * still send a row per line - a line's row when that row is a collection
     * point. Anything else is ignored and Shipping picks the only one.
     */
    private function requestedPickupPoint(Request $request, array $cartArrz)
    {
        if ($request->filled('pickup_place_id')) {
            return (int)$request->input('pickup_place_id');
        }

        $points = Shipping::collectionPoints()->pluck('id')->all();

        foreach ($cartArrz as $v) {
            if (!empty($v['shipping_place_id']) && in_array((int)$v['shipping_place_id'], $points, true)) {
                return (int)$v['shipping_place_id'];
            }
        }

        return null;
    }

    /** The address a delivery goes to when the request did not name one. */
    private function savedDeliveryAddress(Request $request)
    {
        if ($this->user && !$request->user_token) {
            return null;
        }

        if ($user = $request->user('user')) {
            return $user->default_address
                ? UserAddress::where('id', $user->default_address)->where('user_id', $user->id)->first()
                : null;
        }

        if ($request->user_token) {
            $guest = GuestUser::where('user_token', $request->user_token)->first();

            return $guest && $guest->default_address
                ? UserAddress::where('id', $guest->default_address)->where('user_token', $request->user_token)->first()
                : null;
        }

        return null;
    }

    /** An address the caller may use: their own, or any customer's at the till. */
    private function ownedAddress(Request $request, $id)
    {
        if ($this->user && !$request->user_token) {
            return UserAddress::find($id);
        }

        if ($user = $request->user('user')) {
            return UserAddress::where('id', $id)->where('user_id', $user->id)->first();
        }

        if ($request->user_token) {
            return UserAddress::where('id', $id)->where('user_token', $request->user_token)->first();
        }

        return null;
    }

    /** The caller's default saved address, or null (guests included). */
    private function defaultAddress(Request $request)
    {
        if ($user = $request->user('user')) {
            $query = UserAddress::where('user_id', $user->id);
        } else if ($request->user_token) {
            $query = UserAddress::where('user_token', $request->user_token);
        } else {
            return null;
        }

        return $query->where('default', 1)->latest('id')->first();
    }

    /** [cart id => message] for the lines a quote refused. */
    private function restrictedByCart(array $quote, $carts)
    {
        $byCart = [];

        foreach ($carts as $cart) {
            $productId = optional($cart->product)->id;

            if ($productId && isset($quote['restricted'][$productId])) {
                $byCart[$cart->id] = $quote['restricted'][$productId];
            }
        }

        return $byCart;
    }

    /** A refused quote, in the shapes the checkout pages already show. */
    private function shippingRefusal(Request $request, array $quote, $carts)
    {
        $byCart = $this->restrictedByCart($quote, $carts);

        if ($byCart) {
            return Validation::error($request->token, array_map(function ($message) {
                return [$message];
            }, $byCart), 'product');
        }

        return Validation::error($request->token, $quote['message']);
    }


    /**
     * Capture the contact details for a collection order.
     *
     * A signed-in shopper already has a name, email and phone on their account,
     * so nothing is asked of them. A guest has to supply them here, because the
     * address form they are no longer being shown was the only other place they
     * would have been collected.
     */
    private function storeCollectionContact(Request $request, $lang)
    {
        if ($request->user('user')) {
            return null;
        }

        if (!$request->user_token) {
            return Validation::unauthorized(403, __('lang.cart_owner_required', [], $lang));
        }

        $validate = Validation::collectionContact($request);

        if ($validate) {
            return $validate;
        }

        $guestUser = GuestUser::where('user_token', $request->user_token)->first();

        if (!$guestUser) {
            $guestUser = GuestUser::create(['user_token' => $request->user_token]);
        }

        $guestUser->fill([
            'name' => $request->input('contact_name'),
            'email' => $request->input('contact_email'),
            'phone' => $request->input('contact_phone'),
        ])->save();

        return null;
    }

}
