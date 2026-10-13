<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'type', 'status', 'usage_limit', 'limit_per_customer', 'price', 'capped_price',
        'min_spend', 'code', 'start_time', 'end_time', 'admin_id', 'apply_type', 'user_id', 'gift_value'
    ];

    /** A redeemed gift card: usable until its value is spent. */
    public function isGiftCard()
    {
        return !is_null($this->gift_value);
    }

    /**
     * What is left on a gift card voucher: its value less what orders that
     * stand (paid, or cash on delivery and not cancelled) took off. Derived
     * rather than stored, so a cancelled order puts the money back on the
     * card with nothing to undo. Null for an ordinary voucher.
     */
    public function giftBalance()
    {
        if (!$this->isGiftCard()) {
            return null;
        }

        $used = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT ' . static::giftUsedSql((int)$this->id) . ' AS used'
        )->used;

        return round(max(0, (float)$this->gift_value - (float)$used), 2);
    }

    /**
     * SQL for what orders have taken off a gift card voucher: the orders
     * redemptionQuery() counts (paid, or cash on delivery; not cancelled;
     * not deleted), less any that were returned or refunded -- a status
     * change does not set the cancelled flag, and the money goes back on
     * the card either way. A SQL expression so the admin report can filter
     * and total on it; $voucherId is an int or a column name.
     */
    public static function giftUsedSql($voucherId)
    {
        return "(SELECT COALESCE(SUM(ov.discount), 0) FROM ordered_voucher ov"
            . " JOIN orders o ON o.id = ov.order_id AND o.voucher_id = ov.voucher_id"
            . " WHERE ov.voucher_id = $voucherId"
            . " AND " . static::standingOrderSql('o') . ")";
    }

    /**
     * An order whose voucher discount stands: not deleted, not cancelled,
     * paid or cash on delivery, and not returned or refunded. Shared by the
     * gift card balance and a customer's lifetime savings, so a cancelled
     * order puts money back on the card and takes it off "Total Saved" at
     * the same moment.
     */
    public static function standingOrderSql($alias)
    {
        $status = function ($k) {
            return (int)\Illuminate\Support\Facades\Config::get("constants.orderStatus.$k");
        };
        $released = implode(',', [$status('CANCELLED'), $status('RETURNED'), $status('REFUNDED')]);
        $cod = (int)\Illuminate\Support\Facades\Config::get('constants.paymentMethod.CASH_ON_DELIVERY');

        return "$alias.deleted_at IS NULL"
            . " AND ($alias.cancelled != 1 OR $alias.cancelled IS NULL)"
            . " AND ($alias.payment_done = 1 OR $alias.order_method = $cod)"
            . " AND $alias.status NOT IN ($released)";
    }

    /**
     * What vouchers have actually taken off this customer's orders: the
     * discount recorded on each order that stands, not the face value of the
     * vouchers they hold. Gift cards are left out -- spending one is paying
     * with money already paid in, not a discount.
     */
    public static function savedBy($userId)
    {
        $row = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(SUM(ov.discount), 0) AS saved FROM ordered_voucher ov"
            . " JOIN orders o ON o.id = ov.order_id AND o.voucher_id = ov.voucher_id"
            . " JOIN vouchers v ON v.id = ov.voucher_id AND v.gift_value IS NULL"
            . " WHERE o.user_id = ? AND " . static::standingOrderSql('o'),
            [(int)$userId]
        );

        return round((float)$row->saved, 2);
    }

    protected $hidden = [
        'admin_id'
    ];

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'voucher_products'
        );
    }


    /**
     * Orders that actually consume a redemption of this voucher.
     *
     * QA audit CPN-05. Usage limits were counted with a bare
     * Order::where('voucher_id', ...)->count(), which included orders that were
     * never paid for and orders the customer cancelled, so a failed card
     * payment permanently used up a single-use code.
     *
     * An order counts once it is either paid or a cash-on-delivery order that
     * has not been cancelled — the same test the admin order list treats as a
     * live order.
     */
    /**
     * Vouchers a caller may use: every store-wide voucher (user_id null),
     * plus the ones that belong to this customer (redeemed gift codes).
     * A guest only ever sees store-wide ones.
     */
    public function scopeUsableBy($query, $userId = null)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('vouchers.user_id');
            if ($userId) {
                $q->orWhere('vouchers.user_id', $userId);
            }
        });
    }

    public static function redemptionQuery($voucherId)
    {
        return Order::where('voucher_id', $voucherId)
            ->where(function ($q) {
                $q->where('cancelled', '!=', 1)->orWhereNull('cancelled');
            })
            ->where(function ($q) {
                $q->where('payment_done', 1)
                    ->orWhere('order_method', \Illuminate\Support\Facades\Config::get('constants.paymentMethod.CASH_ON_DELIVERY'));
            });
    }
}
