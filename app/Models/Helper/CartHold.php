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
 *   - remindAfterMinutes() before a reminder email (registered customers,
 *     and guests who have given an email) saying it is held for the rest;
 *   - expireAfterMinutes() before it is emptied and the units go back.
 * Both count from the same moment and are set by the admin under
 * Settings > Basket reservation (settings.cart_remind_minutes and
 * cart_expire_minutes). One timer per basket, restarted whenever something
 * is added to it. A changed setting applies to baskets already held.
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
    /** Used when the settings row has no usable timings. */
    const DEFAULT_REMIND_MINUTES = 5;
    const DEFAULT_EXPIRE_MINUTES = 20;

    /** The longest an admin may keep a basket reserved: one day. */
    const MAX_EXPIRE_MINUTES = 1440;

    /** How long a basket that has started paying is kept whatever its age. */
    const PAYING_GRACE_MINUTES = 15;

    private static $timings = null;
    private static $timingsReadAt = 0;


    /** Minutes after the last add before the reminder email goes. */
    public static function remindAfterMinutes()
    {
        return self::timings()['remind'];
    }


    /** Minutes after the last add before the basket is emptied and restocked. */
    public static function expireAfterMinutes()
    {
        return self::timings()['expire'];
    }


    /**
     * The admin's timings, read at most once a minute per process - every
     * basket request and the sweep ask. A missing row or column falls back to
     * the defaults, and a pair the admin form would refuse is clamped so the
     * reminder always comes before the basket is emptied.
     */
    public static function timings()
    {
        if (self::$timings && self::$timingsReadAt > time() - 60) {
            return self::$timings;
        }

        $remind = self::DEFAULT_REMIND_MINUTES;
        $expire = self::DEFAULT_EXPIRE_MINUTES;

        try {
            $row = DB::table('settings')->first();

            if ($row && isset($row->cart_expire_minutes) && (int)$row->cart_expire_minutes > 0) {
                $expire = (int)$row->cart_expire_minutes;
            }

            if ($row && isset($row->cart_remind_minutes) && (int)$row->cart_remind_minutes > 0) {
                $remind = (int)$row->cart_remind_minutes;
            }
        } catch (\Exception $ex) {
            Log::warning('Basket reservation timings unreadable, using defaults', ['message' => $ex->getMessage()]);
        }

        $expire = min(max($expire, 2), self::MAX_EXPIRE_MINUTES);
        $remind = min(max($remind, 1), $expire - 1);

        self::$timings = ['remind' => $remind, 'expire' => $expire];
        self::$timingsReadAt = time();

        return self::$timings;
    }


    /** Read the settings again on the next call (after a save, and in tests). */
    public static function forgetTimings()
    {
        self::$timings = null;
    }


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
                    // Something was added: the reminder and expiry timers start again.
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
     * Empty this basket now if its time has lapsed. Returns whether it did.
     *
     * The sweep does this every minute - but only when the scheduler or some
     * other request runs it, and only after that request has been answered.
     * Asked first by every request on the basket or checkout
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

        if (Carbon::parse($hold->last_activity_at)->diffInSeconds($now, false) < self::expireAfterMinutes() * 60) {
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
     * Every minute: remind and empty on the admin's timings, and release whatever a
     * deleted line left held. Returns what it did, for the command's output.
     */
    public static function sweep()
    {
        $done = ['reminded' => 0, 'expired' => 0, 'reconciled' => 0];
        $now = Carbon::parse(self::now());
        $remindAfter = self::remindAfterMinutes() * 60;
        $expireAfter = self::expireAfterMinutes() * 60;

        foreach (DB::table('cart_holds')->get() as $hold) {
            if ($hold->paying_until && Carbon::parse($hold->paying_until)->gt($now)) {
                continue;
            }

            $idle = Carbon::parse($hold->last_activity_at)->diffInSeconds($now, false);

            if ($idle >= $expireAfter) {
                self::expire($hold);
                $done['expired']++;
            } else if ($idle >= $remindAfter && !$hold->reminded_at) {
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
                'minutes' => self::expireAfterMinutes(),
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
            Log::info('Basket reminder skipped: no email address', ['owner' => $hold->owner_key]);
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

        $expiresAt = Carbon::parse($hold->last_activity_at)->addMinutes(self::expireAfterMinutes());
        $minutesLeft = max(1, (int)ceil(Carbon::parse(self::now())->diffInSeconds($expiresAt, false) / 60));

        // A failed send is logged by MailHelper as "Basket reminder email failed".
        if (MailHelper::cartHoldReminder($recipient, $lines, $minutesLeft)) {
            Log::info('Basket reminder sent', ['owner' => $hold->owner_key, 'email' => $recipient->email, 'minutes_left' => $minutesLeft]);
        }
    }


    private static function now()
    {
        return date('Y-m-d H:i:s');
    }
}
