<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'points',
        'value',
        'source',
        'reference_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCredit($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeDebit($query)
    {
        return $query->where('type', 'debit');
    }

    public function isCredit()
    {
        return $this->type === 'credit';
    }

    public function isDebit()
    {
        return $this->type === 'debit';
    }

    /**
     * The balance a customer is shown and can spend: credits less debits,
     * never below zero. It can net negative when points earned on one order
     * were spent on another and the first is then refunded, which takes the
     * earned points back; the customer sees 0, not a debt. The admin customer
     * list keeps the raw figure.
     */
    public static function balanceFor($userId)
    {
        $total = static::where('user_id', $userId)
            ->selectRaw("SUM(CASE WHEN type='credit' THEN points ELSE -points END) as total")
            ->value('total');

        return max(0, (int)$total);
    }

    /**
     * Points on the way: what the customer's orders will earn once delivered.
     * The same orders and the same arithmetic as awardLoyaltyPoints() - an
     * order that stands (paid, or cash on delivery; not cancelled, returned,
     * refunded or deleted), not delivered yet, and not already credited;
     * floor(total_amount) points each. Returns [points, orders].
     */
    public static function pendingFor($userId)
    {
        $delivered = (int)\Illuminate\Support\Facades\Config::get('constants.orderStatus.DELIVERED');

        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT COALESCE(SUM(FLOOR(GREATEST(o.total_amount, 0))), 0) AS points, COUNT(*) AS orders'
            . ' FROM orders o'
            . ' WHERE o.user_id = ? AND o.status != ' . $delivered
            . ' AND ' . Voucher::standingOrderSql('o')
            . " AND NOT EXISTS (SELECT 1 FROM point_transactions pt WHERE pt.reference_id = o.id"
            . " AND pt.source = 'order' AND pt.type = 'credit')",
            [(int)$userId]
        );

        return [(int)$row->points, (int)$row->orders];
    }

    /** What a customer reads for this row on Savers Club. */
    public static function describe($source, $type, $orderNumber = null)
    {
        $on = $orderNumber ? ' #' . $orderNumber : '';

        if ($source === 'order') {
            return $type === 'credit' ? 'Earned on order' . $on : 'Used on order' . $on;
        }

        if ($source === 'order_reversal') {
            // the opposite of what the order did: points used come back, points earned go
            return $type === 'credit'
                ? 'Returned: order' . $on . ' cancelled'
                : 'Removed: order' . $on . ' cancelled';
        }

        return ucfirst(str_replace('_', ' ', (string)$source));
    }

    /** Rows with a `description`, order numbers looked up in one query. */
    public static function withDescriptions($rows)
    {
        $orderIds = collect($rows)->pluck('reference_id')->filter()->unique()->values();

        $numbers = $orderIds->isEmpty() ? collect() : Order::withTrashed()
            ->whereIn('id', $orderIds->all())->pluck('order', 'id');

        foreach ($rows as $t) {
            $t->description = self::describe($t->source, $t->type, $numbers[$t->reference_id] ?? null);
        }

        return $rows;
    }
}
