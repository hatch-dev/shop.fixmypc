<?php

namespace App\Models\Helper;

use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\UpdatedInventory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Basket stock reservations.
 *
 * Adding to the basket takes the units off updated_inventories straight away,
 * so another shopper cannot buy what is sitting in someone's basket. The
 * basket then has:
 *   - REMIND_AFTER minutes before a reminder email (registered customers,
 *     and guests who have given an email) saying it is held for the rest;
 *   - EXPIRE_AFTER minutes before it is emptied and the units go back.
 * One timer per basket, restarted whenever something is added to it.
 * Placing an order uses the held units instead of taking stock a second time.
 *
 * How it stays right: cart lines are changed and deleted from many places
 * (bulk deletes among them), so nothing here tracks individual edits.
 * reconcile() compares what a basket holds (cart_reservations) with what is
 * in it (carts) and takes or gives back the difference. It runs after every
 * basket request (middleware cart.hold), after a sign-in merges a guest
 * basket, and in the sweep, so a line deleted by anything is released within
 * a minute at most.
 *
 * Admin (POS) baskets are not reserved: carts with an admin_id are ignored.
 * Times are PHP times, never SQL NOW() - the two clocks differ here.
 */
class CartHold
{
    const REMIND_AFTER_MINUTES = 5;
    const EXPIRE_AFTER_MINUTES = 20;

    /** How long a basket that has started paying is kept whatever its age. */
    const PAYING_GRACE_MINUTES = 15;


    public static function ownerKey($userId, $userToken)
    {
        if ($userId) {
            return 'u:' . (int)$userId;
        }

        return $userToken ? 'g:' . $userToken : null;
    }


    /** [user_id, user_token] for an owner key, or null. */
    public static function parseKey($key)
    {
        if (strpos((string)$key, 'u:') === 0) {
            return [(int)substr($key, 2), null];
        }

        if (strpos((string)$key, 'g:') === 0) {
            return [null, substr($key, 2)];
        }

        return null;
    }


    /**
     * The basket a request acts on: the signed-in customer's, else the guest
     * token's. Null for an admin/POS request, which is not reserved.
     */
    public static function ownerFromRequest($request)
    {
        $user = $request->user('user');

        if ($user) {
            return [$user->id, null];
        }

        $token = $request->input('user_token') ?: $request->query('user_token');

        if ($token) {
            return [null, $token];
        }

        return null;
    }


    private static function basketQuery($userId, $userToken)
    {
        $query = Cart::whereNull('admin_id');

        return $userId
            ? $query->where('user_id', $userId)
            : $query->whereNull('user_id')->where('user_token', $userToken);
    }


    /** Units of an inventory row this basket is holding. */
    public static function heldFor($userId, $userToken, $inventoryId)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key || !$inventoryId) {
            return 0;
        }

        return (int)DB::table('cart_reservations')
            ->where('owner_key', $key)->where('inventory_id', $inventoryId)
            ->value('quantity');
    }


    /** [inventory_id => units held] for a basket. */
    public static function heldMap($userId, $userToken)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key) {
            return [];
        }

        return DB::table('cart_reservations')->where('owner_key', $key)
            ->pluck('quantity', 'inventory_id')->map(function ($q) { return (int)$q; })->all();
    }


    /**
     * Make what the basket holds match what is in it.
     *
     * More in the basket than held: take the difference off the stock (as
     * far as it goes - a line that cannot be covered is trimmed, which only
     * happens when two shoppers race for the last unit; the add-to-basket
     * check refuses it first in every normal case). Less: give it back.
     * Backorder inventories (is_active 1) may go below zero, as orders on
     * them always could.
     */
    public static function reconcile($userId, $userToken)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key) {
            return;
        }

        try {
            DB::transaction(function () use ($key, $userId, $userToken) {

                $wanted = self::basketQuery($userId, $userToken)
                    ->selectRaw('inventory_id, SUM(quantity) as quantity')
                    ->groupBy('inventory_id')
                    ->pluck('quantity', 'inventory_id')
                    ->map(function ($q) { return (int)$q; })
                    ->all();

                $held = DB::table('cart_reservations')->where('owner_key', $key)
                    ->lockForUpdate()->get()->keyBy('inventory_id');

                $inventoryIds = array_unique(array_merge(array_keys($wanted), $held->keys()->all()));
                $added = false;
                $holding = false;

                foreach ($inventoryIds as $inventoryId) {
                    $want = max(0, (int)($wanted[$inventoryId] ?? 0));
                    $have = (int)optional($held->get($inventoryId))->quantity;

                    if ($want > $have) {
                        $inventory = UpdatedInventory::where('id', $inventoryId)->lockForUpdate()->first();

                        if ($inventory) {
                            $short = $want - $have;
                            $take = (int)$inventory->is_active === 1
                                ? $short
                                : min($short, max(0, (int)$inventory->quantity));

                            if ($take > 0) {
                                UpdatedInventory::where('id', $inventoryId)->decrement('quantity', $take);
                                $have += $take;
                                $added = true;
                            }
                        }

                        if ($have < $want) {
                            self::trimBasket($userId, $userToken, $inventoryId, $have);
                        }

                    } else if ($want < $have) {
                        UpdatedInventory::where('id', $inventoryId)->increment('quantity', $have - $want);
                        $have = $want;
                    }

                    if ($have > 0) {
                        $holding = true;
                        DB::table('cart_reservations')->updateOrInsert(
                            ['owner_key' => $key, 'inventory_id' => $inventoryId],
                            ['quantity' => $have, 'updated_at' => self::now(), 'created_at' => self::now()]
                        );
                    } else {
                        DB::table('cart_reservations')
                            ->where('owner_key', $key)->where('inventory_id', $inventoryId)->delete();
                    }
                }

                if (!$holding) {
                    DB::table('cart_holds')->where('owner_key', $key)->delete();
                    return;
                }

                $timer = DB::table('cart_holds')->where('owner_key', $key)->first();

                if (!$timer) {
                    DB::table('cart_holds')->insert([
                        'owner_key' => $key,
                        'user_id' => $userId,
                        'user_token' => $userToken,
                        'last_activity_at' => self::now(),
                        'created_at' => self::now(),
                        'updated_at' => self::now(),
                    ]);
                } else if ($added) {
                    // Something was added: the 5 + 15 minutes start again.
                    DB::table('cart_holds')->where('owner_key', $key)->update([
                        'last_activity_at' => self::now(),
                        'reminded_at' => null,
                        'updated_at' => self::now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Cart hold reconcile failed', ['owner' => $key, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Give back everything a basket holds and forget its timer, leaving its
     * lines alone - for the sign-in merge, which re-sizes lines against free
     * stock and must see this basket's own units as free. reconcile()
     * takes them again once the merge is done.
     */
    public static function releaseAll($userId, $userToken)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key) {
            return;
        }

        DB::transaction(function () use ($key) {
            $held = DB::table('cart_reservations')->where('owner_key', $key)->lockForUpdate()->get();

            foreach ($held as $row) {
                if ((int)$row->quantity > 0) {
                    UpdatedInventory::where('id', $row->inventory_id)->increment('quantity', (int)$row->quantity);
                }
            }

            DB::table('cart_reservations')->where('owner_key', $key)->delete();
            DB::table('cart_holds')->where('owner_key', $key)->delete();
        });
    }


    /**
     * Cut this basket's lines of one inventory row down to $keep units, and
     * say so (BasketNotice): a line that shrank or vanished with no word was
     * one of the ways a basket changed under the shopper unexplained.
     */
    private static function trimBasket($userId, $userToken, $inventoryId, $keep)
    {
        $lines = self::basketQuery($userId, $userToken)
            ->where('inventory_id', $inventoryId)->orderBy('id')->get();

        foreach ($lines as $line) {
            $quantity = min((int)$line->quantity, $keep);
            $keep -= $quantity;

            if ($quantity === (int)$line->quantity) {
                continue;
            }

            $described = BasketNotice::describe([$line])[0];

            BasketNotice::add($userId, $userToken, BasketNotice::TRIMMED, [
                'title' => $described['title'],
                'from' => (int)$line->quantity,
                'to' => max(0, $quantity),
            ]);

            if ($quantity <= 0) {
                Cart::where('id', $line->id)->delete();
            } else {
                Cart::where('id', $line->id)->update(['quantity' => $quantity]);
            }
        }
    }


    /**
     * The shopper did something with their basket or checkout: the 20 idle
     * minutes start again.
     *
     * Only adding to the basket used to restart them, so a shopper filling in
     * an address, waiting for the guest email code or choosing delivery was
     * "idle" - reminded at 5 minutes and emptied at 20 while still checking
     * out. The reminder is not re-armed: one email per basket is plenty.
     */
    public static function touch($userId, $userToken)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key) {
            return;
        }

        DB::table('cart_holds')->where('owner_key', $key)->update([
            'last_activity_at' => self::now(),
            'updated_at' => self::now(),
        ]);
    }


    /**
     * Empty this basket now if its 20 minutes have lapsed. Returns whether it did.
     *
     * The sweep does this every minute - but only when the scheduler or some
     * other request runs it, and only after that request has been answered.
     * Asked first by every request that touches the basket or checkout
     * (middleware cart.hold), so a lapsed basket can never be priced, shipped
     * or bought, whether or not the sweep has got to it yet. A basket that has
     * started paying keeps its grace, as in the sweep.
     */
    public static function expireIfLapsed($userId, $userToken)
    {
        $key = self::ownerKey($userId, $userToken);
        $hold = $key ? DB::table('cart_holds')->where('owner_key', $key)->first() : null;

        if (!$hold) {
            return false;
        }

        $now = Carbon::parse(self::now());

        if ($hold->paying_until && Carbon::parse($hold->paying_until)->gt($now)) {
            return false;
        }

        if (Carbon::parse($hold->last_activity_at)->diffInSeconds($now, false) < self::EXPIRE_AFTER_MINUTES * 60) {
            return false;
        }

        self::expire($hold);

        return true;
    }


    /**
     * An order is taking $quantity units: use what the basket holds first.
     * Returns how many were held; the caller takes only the rest off stock.
     */
    public static function consume($userId, $userToken, $inventoryId, $quantity)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key || $quantity <= 0) {
            return 0;
        }

        $held = (int)DB::table('cart_reservations')
            ->where('owner_key', $key)->where('inventory_id', $inventoryId)
            ->lockForUpdate()->value('quantity');

        $used = min($held, (int)$quantity);

        if ($used <= 0) {
            return 0;
        }

        if ($held - $used > 0) {
            DB::table('cart_reservations')
                ->where('owner_key', $key)->where('inventory_id', $inventoryId)
                ->update(['quantity' => $held - $used, 'updated_at' => self::now()]);
        } else {
            DB::table('cart_reservations')
                ->where('owner_key', $key)->where('inventory_id', $inventoryId)->delete();
        }

        return $used;
    }


    /**
     * The shopper has started paying: keep the basket for PAYING_GRACE
     * minutes whatever its age, so it cannot be emptied while a card is being
     * charged for it.
     */
    public static function markPaying($userId, $userToken)
    {
        $key = self::ownerKey($userId, $userToken);

        if (!$key) {
            return;
        }

        DB::table('cart_holds')->where('owner_key', $key)->update([
            'paying_until' => Carbon::parse(self::now())->addMinutes(self::PAYING_GRACE_MINUTES)->format('Y-m-d H:i:s'),
            'updated_at' => self::now(),
        ]);
    }


    /**
     * Every minute: remind at 5 minutes, empty at 20, and release whatever a
     * deleted line left held. Returns what it did, for the command's output.
     */
    public static function sweep()
    {
        $done = ['reminded' => 0, 'expired' => 0, 'reconciled' => 0];
        $now = Carbon::parse(self::now());

        foreach (DB::table('cart_holds')->get() as $hold) {
            if ($hold->paying_until && Carbon::parse($hold->paying_until)->gt($now)) {
                continue;
            }

            $idle = Carbon::parse($hold->last_activity_at)->diffInSeconds($now, false);

            if ($idle >= self::EXPIRE_AFTER_MINUTES * 60) {
                self::expire($hold);
                $done['expired']++;
            } else if ($idle >= self::REMIND_AFTER_MINUTES * 60 && !$hold->reminded_at) {
                self::remind($hold);
                $done['reminded']++;
            }
        }

        // Reservations whose lines were deleted elsewhere (admin, import...).
        foreach (DB::table('cart_reservations')->distinct()->pluck('owner_key') as $key) {
            $owner = self::parseKey($key);

            if ($owner) {
                self::reconcile($owner[0], $owner[1]);
                $done['reconciled']++;
            }
        }

        return $done;
    }


    /**
     * Empty the basket and give its stock back - and keep what was in it
     * (BasketNotice), so the shopper is told and can add it back.
     */
    private static function expire($hold)
    {
        $lines = self::basketQuery($hold->user_id, $hold->user_token)->where('buy_now', 0)->get();

        if ($lines->isNotEmpty()) {
            BasketNotice::add($hold->user_id, $hold->user_token, BasketNotice::EXPIRED, [
                'minutes' => self::EXPIRE_AFTER_MINUTES,
                'lines' => BasketNotice::describe($lines),
            ]);
        }

        self::basketQuery($hold->user_id, $hold->user_token)->delete();
        self::reconcile($hold->user_id, $hold->user_token);
        DB::table('cart_holds')->where('owner_key', $hold->owner_key)->delete();

        Log::info('Basket reservation expired', ['owner' => $hold->owner_key]);
    }


    /** Send the reminder, once, if there is anyone to send it to. */
    private static function remind($hold)
    {
        // Stamped first: a failed send must not retry every minute.
        DB::table('cart_holds')->where('id', $hold->id)->update([
            'reminded_at' => self::now(), 'updated_at' => self::now(),
        ]);

        $recipient = $hold->user_id
            ? User::find($hold->user_id)
            : GuestUser::where('user_token', $hold->user_token)->first();

        if (!$recipient || !$recipient->email) {
            return;
        }

        // The basket's own lines: an in-progress Buy Now is not basket contents.
        $lines = self::basketQuery($hold->user_id, $hold->user_token)
            ->where('buy_now', 0)
            ->with('product')->get()
            ->map(function ($line) {
                return (object)[
                    'title' => optional($line->product)->title ?: 'Item',
                    'quantity' => (int)$line->quantity,
                ];
            })->all();

        if (!count($lines)) {
            return;
        }

        $expiresAt = Carbon::parse($hold->last_activity_at)->addMinutes(self::EXPIRE_AFTER_MINUTES);
        $minutesLeft = max(1, (int)ceil(Carbon::parse(self::now())->diffInSeconds($expiresAt, false) / 60));

        MailHelper::cartHoldReminder($recipient, $lines, $minutesLeft);
    }


    private static function now()
    {
        return date('Y-m-d H:i:s');
    }
}
