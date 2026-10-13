<?php

namespace App\Models\Helper;

use App\Models\BusinessProductPricing;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Product;
use App\Models\UpdatedInventory;
use Illuminate\Support\Facades\DB;

/**
 * Hand a guest basket over to the account that just signed in.
 *
 * Everything here exists because the merge used to be a loop in
 * UsersController::issueUserSession that read the guest rows, added their
 * quantities to the matching account rows and deleted them — correct for one
 * caller at a time, and wrong in every other respect:
 *
 *  - it was not in a transaction and took no locks, so two sign-ins racing
 *    each other (a double-clicked button, a retried request) both read the
 *    same guest rows and both added them, doubling the quantity;
 *  - it never looked at the line cap or at stock, so a guest with 900 units
 *    and an account with 900 units merged to 1800 and only failed later, at
 *    checkout;
 *  - it kept the guest's price, which is retail, so a business account that
 *    signed in mid-basket carried retail figures into the order;
 *  - it matched the account row with first(), so a pre-existing duplicate pair
 *    stayed a duplicate pair and the merged quantity landed on whichever of
 *    them the database happened to return.
 *
 * The merge is idempotent by construction: it consumes the guest rows inside
 * the same transaction that writes the account rows, so a second call — a
 * refresh, a retry, signing out and back in — finds nothing left to move.
 */
class CartMerge
{
    /**
     * Move every guest cart line held under $userToken onto $user.
     *
     * The return value says what happened, for the API response and for tests.
     *
     * @return array{merged:int, adopted:int, capped:array, moved:int}
     */
    public static function forUser($user, $userToken)
    {
        $summary = ['merged' => 0, 'adopted' => 0, 'capped' => [], 'moved' => 0];

        if (!$user || !$userToken) {
            return $summary;
        }

        /*
         * Both baskets' reserved units go back first: the merge caps lines at
         * free stock, and a basket's own reserved units must count as free for
         * it. Held again for the merged basket below.
         *
         * How old the guest basket is goes to CartTrace, read before anything
         * moves.
         */
        $guestBasket = CartTrace::basketState($userToken);

        CartHold::releaseAll($user->id, null);
        CartHold::releaseAll(null, $userToken);

        // What was moved, for CartTrace; filled inside the transaction.
        $traced = [];

        $merged = DB::transaction(function () use ($user, $userToken, $summary, &$traced) {

            /*
             * A Buy Now in progress is a checkout of one item, not basket
             * contents. It carries over as the account's
             * Buy Now, replacing any older one, so a guest who signs in half
             * way through buying one item is still buying that one item. It is
             * never folded into the basket below.
             */
            $guestBuyNow = Cart::whereNull('user_id')->where('user_token', $userToken)
                ->where('buy_now', 1)->lockForUpdate()->pluck('id');

            if ($guestBuyNow->isNotEmpty()) {
                Cart::where('user_id', $user->id)->where('buy_now', 1)->delete();
                Cart::whereIn('id', $guestBuyNow->all())->update([
                    'user_id' => $user->id, 'user_token' => null,
                ]);
            }

            /*
             * One locking read over both sides of the merge.
             *
             * Taking the two sets in separate statements is what lets a
             * concurrent sign-in interleave between them; a single predicate
             * means every racing request queues on the same row set in the
             * same order, so the second one starts only once the first has
             * committed — and by then there are no guest rows left to move.
             */
            $rows = Cart::where(function ($query) use ($user, $userToken) {
                $query->where('user_id', $user->id)
                    ->orWhere(function ($query) use ($userToken) {
                        $query->whereNull('user_id')
                            ->where('user_token', $userToken);
                    });
            })->where('buy_now', 0)->orderBy('id')->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return $summary;
            }

            $guestRows = $rows->filter(function ($row) use ($userToken) {
                return is_null($row->user_id) && $row->user_token === $userToken;
            })->values();

            $userRows = $rows->filter(function ($row) use ($user) {
                return (int)$row->user_id === (int)$user->id;
            })->values();

            // The account side, one row per product+variant, duplicates folded in.
            $target = self::collapse($userRows);

            if ($guestRows->isEmpty()) {
                return $summary;
            }

            // Before the loop below folds, adopts or deletes any of them.
            $traced = CartTrace::snapshot($guestRows);

            $inventories = UpdatedInventory::whereIn(
                'id', $rows->pluck('inventory_id')->unique()->filter()->all()
            )->get()->keyBy('id');

            $tiers = BusinessProductPricing::tierMap(
                $user, $rows->pluck('product_id')->unique()
            );

            foreach ($guestRows as $guestRow) {

                $key = self::lineKey($guestRow);
                $inventory = $inventories->get($guestRow->inventory_id);

                if (isset($target[$key])) {

                    $keep = $target[$key];

                    $wanted = (int)$keep->quantity + (int)$guestRow->quantity;
                    $allowed = self::cap($wanted, $inventory);

                    if ($allowed < $wanted) {
                        $summary['capped'][] = self::capNote($guestRow, $wanted, $allowed);
                    }

                    $keep->quantity = $allowed;

                    /*
                     * The bundle marker comes across with the quantity.
                     *
                     * `carts.bundle_deal_id` is what tells Helper\BundleDiscount
                     * a set is in play, and the discount is only granted while
                     * every one of the bundle's products is held. Folding the
                     * guest's marked line into an unmarked one the account
                     * already had dropped the marker with the row, so a shopper
                     * who happened to own one of the bundle's products already
                     * lost part or all of the saving by signing in -- silently,
                     * and in the shop's favour.
                     *
                     * Only ever set, never cleared, for the same reason
                     * addCrossSellLine only ever sets it: an ordinary line
                     * absorbing a bundle line has become part of the bundle,
                     * but a bundle line absorbing an ordinary one has not
                     * stopped being one.
                     */
                    if (empty($keep->bundle_deal_id) && !empty($guestRow->bundle_deal_id)) {
                        $keep->bundle_deal_id = (int)$guestRow->bundle_deal_id;
                    }

                    // Folded in; the guest row itself is spent.
                    $guestRow->delete();

                    $summary['merged']++;

                } else {

                    /*
                     * Adopted rather than re-created: keeping the row id means
                     * anything already pointing at this line — a shipping
                     * choice made during guest checkout, an open basket in
                     * another tab — still resolves.
                     */
                    $wanted = (int)$guestRow->quantity;
                    $allowed = self::cap($wanted, $inventory);

                    if ($allowed < $wanted) {
                        $summary['capped'][] = self::capNote($guestRow, $wanted, $allowed);
                    }

                    $guestRow->user_id = $user->id;
                    $guestRow->user_token = null;
                    $guestRow->quantity = $allowed;

                    $target[$key] = $guestRow;

                    $summary['adopted']++;
                }

                $summary['moved']++;
            }

            /*
             * Re-price every surviving line against the catalogue and against
             * whatever bands this account is entitled to. The guest paid
             * retail; a business or approved trade account signing in must not
             * carry the retail figure into checkout, and a line whose quantity
             * has just grown may have crossed a band boundary.
             */
            $products = [];

            foreach ($target as $row) {

                $productId = (int)$row->product_id;

                if (!array_key_exists($productId, $products)) {
                    $products[$productId] = Product::pricingRecord($productId);
                }

                $pricing = self::price(
                    $products[$productId],
                    $inventories->get($row->inventory_id),
                    (int)$row->quantity,
                    $tiers->get($productId)
                );

                // price/original_price are guarded on the model (CART-01), so
                // they are written here rather than mass-assigned.
                $row->forceFill([
                    'user_id' => $user->id,
                    'user_token' => null,
                    'quantity' => (int)$row->quantity,
                    'price' => $pricing['price'],
                    'original_price' => $pricing['original_price'],
                ])->save();
            }

            /*
             * Whatever the guest still holds under this token is spent. Rows
             * folded into an account line above were deleted as they were
             * folded; this catches anything the loop did not reach, so a
             * second merge with the same token has nothing to find.
             */
            Cart::whereNull('user_id')->where('user_token', $userToken)->delete();

            GuestUser::where('user_token', $userToken)->update([
                'name' => $user->name,
                'email' => $user->email,
            ]);

            return $summary;
        });

        CartHold::reconcile(null, $userToken);
        CartHold::reconcile($user->id, null);

        /*
         * Say so. Guest items joining the account used to happen silently,
         * which is how a new account could open with products the shopper did
         * not remember adding. Anything the guest basket was still waiting to
         * be told (an expiry, a trimmed line) comes across with it.
         */
        BasketNotice::moveToUser($userToken, $user->id);

        if (!empty($traced)) {
            BasketNotice::add($user->id, null, BasketNotice::MERGED, [
                'lines' => BasketNotice::describe(array_map(function ($line) {
                    return (object)$line;
                }, $traced)),
            ]);
        }

        CartTrace::merged($user, $userToken, $traced, $merged, $guestBasket);

        return $merged;
    }

    /**
     * One row per product+variant, with any duplicate pair already on the
     * account folded into the oldest of them.
     *
     * A duplicate should not exist — every write path keys on the same triple,
     * and there is a unique index behind them now — but an account that
     * predates the index can still be carrying one, and the merge is the
     * moment it would otherwise be doubled again.
     *
     * @return array<string, Cart> keyed by product_id:inventory_id
     */
    private static function collapse($rows)
    {
        $kept = [];

        foreach ($rows as $row) {

            $key = self::lineKey($row);

            if (!isset($kept[$key])) {
                $kept[$key] = $row;
                continue;
            }

            $kept[$key]->quantity = (int)$kept[$key]->quantity + (int)$row->quantity;
            $kept[$key]->save();

            $row->delete();
        }

        return $kept;
    }

    private static function lineKey($row)
    {
        return (int)$row->product_id . ':' . (int)$row->inventory_id;
    }

    private static function capNote($row, $wanted, $allowed)
    {
        return [
            'product_id' => (int)$row->product_id,
            'inventory_id' => (int)$row->inventory_id,
            'requested' => (int)$wanted,
            'allowed' => (int)$allowed,
        ];
    }

    /**
     * The largest quantity this line may actually hold.
     *
     * Deliberately caps instead of refusing: the customer is signing in, not
     * adding to their basket, and rejecting the whole merge over one
     * over-subscribed variant would lose the rest of the basket with it.
     */
    private static function cap($wanted, $inventory)
    {
        $allowed = min((int)$wanted, Validation::MAX_LINE_QUANTITY);

        if (is_null($inventory)) {
            return max(1, $allowed);
        }

        // is_active on an inventory row means backorder is allowed for that
        // variant, so those lines are intentionally free to exceed stock.
        if ((int)$inventory->is_active === 1) {
            return max(1, $allowed);
        }

        return max(1, min($allowed, (int)$inventory->quantity));
    }

    private static function price($product, $inventory, $quantity, $tiers)
    {
        $price = Pricing::unitPrice($product, $inventory, $quantity, $tiers);

        $listPrice = $inventory && Pricing::inventoryPrice($inventory) > 0
            ? Pricing::inventoryPrice($inventory)
            : (float)($product->selling ?? $price);

        return [
            'price' => Pricing::round($price),
            'original_price' => Pricing::round(max($listPrice, $price)),
        ];
    }
}
