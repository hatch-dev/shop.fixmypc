<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'source',
        'reference_id',
        'transaction_id',
        'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What a customer reads for this row. `source` is an internal key
     * (gift_voucher, admin, ...) and was printed as it stands on Savers Club.
     * $orderNumber is the order's public number for order and refund rows.
     */
    public static function describe($source, $orderNumber = null)
    {
        $on = $orderNumber ? ' #' . $orderNumber : '';

        switch ($source) {
            case 'topup':
                return 'Wallet top-up';
            case 'order':
                return 'Payment for order' . $on;
            case 'refund':
                return 'Refund for order' . $on;
            case 'order_release':
                // the wallet part of a split payment, back from an order that was never paid
                return 'Returned from cancelled order' . $on;
            case 'gift_voucher':
                return 'Gift card purchase';
            case 'gift_card':
                return 'Gift card redeemed';
            case 'admin':
                return 'Adjustment by FixMyPC';
            default:
                return ucfirst(str_replace('_', ' ', (string)$source));
        }
    }

    /**
     * Rows with a `description` added, looking the order numbers up in one
     * query rather than one per row.
     */
    public static function withDescriptions($rows)
    {
        $orderIds = collect($rows)
            ->filter(function ($t) { return in_array($t->source, ['order', 'refund', 'order_release'], true); })
            ->pluck('reference_id')->filter()->unique()->values();

        $orders = $orderIds->isEmpty() ? collect() : Order::withTrashed()
            ->whereIn('id', $orderIds->all())->get(['id', 'order', 'deleted_at'])->keyBy('id');

        foreach ($rows as $t) {
            $order = in_array($t->source, ['order', 'refund', 'order_release'], true) ? ($orders[$t->reference_id] ?? null) : null;

            $t->description = self::describe($t->source, $order ? $order->order : null);
            // lets Savers Club link the row to the order; not for a deleted one, whose page is gone
            $t->order_id = $order && !$order->deleted_at ? (int)$order->id : null;
        }

        return $rows;
    }
}
