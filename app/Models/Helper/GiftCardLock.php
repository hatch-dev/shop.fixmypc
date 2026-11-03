<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\DB;

/**
 * One gift card, one checkout (or staff change) at a time.
 *
 * A card's balance is derived from the orders that spent it, and an order
 * only counts once it is paid. Two checkouts with the same card used to read
 * the same balance, each take all of it, and both be paid: the card was spent
 * twice. Holding this lock from the balance check until the order is either
 * paid or rolled back makes the second checkout wait and then see what the
 * first one took.
 *
 * A MySQL named lock rather than a row lock: placing an order is many
 * statements and a gateway call, not one transaction, and a named lock is
 * held by the connection for exactly as long as it is needed. It is released
 * when the connection closes, so a crashed request cannot leave a card stuck.
 */
class GiftCardLock
{
    const WAIT_SECONDS = 20;

    /** How long a checkout waits for another one on the same card (tests shorten it). */
    public static $waitSeconds = self::WAIT_SECONDS;

    private static $held = [];

    private static function name($voucherId)
    {
        return 'fixmypc_gift_card_' . (int)$voucherId;
    }

    /** True once this connection holds the card; false if it timed out. */
    public static function acquire($voucherId, $wait = null)
    {
        $wait = $wait === null ? self::$waitSeconds : $wait;
        $voucherId = (int)$voucherId;
        if (isset(self::$held[$voucherId])) {
            self::$held[$voucherId]++;
            return true;
        }

        $row = DB::selectOne('SELECT GET_LOCK(?, ?) AS got', [self::name($voucherId), (int)$wait]);
        if ((int)($row->got ?? 0) !== 1) {
            return false;
        }

        self::$held[$voucherId] = 1;
        return true;
    }

    public static function release($voucherId)
    {
        $voucherId = (int)$voucherId;
        if (!isset(self::$held[$voucherId])) {
            return;
        }
        if (--self::$held[$voucherId] > 0) {
            return;
        }
        unset(self::$held[$voucherId]);
        DB::select('SELECT RELEASE_LOCK(?)', [self::name($voucherId)]);
    }

    /** Run $work holding the card's lock; null when the lock was not had. */
    public static function with($voucherId, callable $work, $wait = null)
    {
        if (!self::acquire($voucherId, $wait)) {
            return null;
        }
        try {
            return $work();
        } finally {
            self::release($voucherId);
        }
    }
}
