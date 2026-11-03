<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Something staff did to a gift card, or a step of its life that is not
 * already a row elsewhere: issued by staff, balance or expiry changed,
 * purchase refunded, voided, email resent. Who, when, how much and why.
 *
 * Spending is not copied here; it is read from ordered_voucher, where the
 * order that spent the card is.
 */
class GiftCardEvent extends Model
{
    const ISSUED = 'issued';
    const BALANCE = 'balance';
    const EXPIRY = 'expiry';
    const REFUND = 'refund';
    const VOID = 'void';
    const RESEND = 'resend';

    protected $fillable = [
        'gift_voucher_code_id', 'gift_voucher_order_id', 'voucher_id', 'type',
        'amount', 'old_value', 'new_value', 'reason', 'admin_id',
    ];

    /**
     * The table arrives with migration 2026_11_03_000100_gift_card_events.
     * Until it has been run, staff changes that must be recorded are refused
     * and the rest simply are not logged.
     */
    public static function available()
    {
        static $exists = null;
        if ($exists === null) {
            $exists = Schema::hasTable('gift_card_events');
        }
        return $exists;
    }

    public static function record(array $values)
    {
        return self::available() ? self::create($values) : null;
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
