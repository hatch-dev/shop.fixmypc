<?php

namespace App\Models\Helper;

use App\Models\GiftCardEvent;
use App\Models\GiftVoucherCode;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * One gift card's story, oldest first: bought (or issued by staff), emailed,
 * added to vouchers, spent on orders - including spending that was released
 * again by a cancellation, refund or return - and every change staff made.
 *
 * Built from the rows that already record each step (gift_voucher_orders,
 * gift_voucher_codes, ordered_voucher and orders, gift_card_events) rather
 * than a copy, so it cannot drift from the balance.
 *
 * $forStaff adds who did what and why; a customer sees what happened and how
 * much, not staff names or internal reasons.
 */
class GiftCardHistory
{
    public static function for(GiftVoucherCode $code, $forStaff = false)
    {
        $code->loadMissing(['order', 'redeemer:id,name,email']);
        $order = $code->order;
        $voucher = $code->voucher_id ? Voucher::find($code->voucher_id) : null;
        $events = [];
        $iso = function ($d) { return $d ? \Carbon\Carbon::parse($d)->toIso8601String() : null; };

        $staffEvents = GiftCardEvent::available()
            ? GiftCardEvent::with('admin:id,name')->where('gift_voucher_code_id', $code->id)->orderBy('id')->get()
            : collect();

        if ($order && $order->payment_method !== 'admin') {
            $events[] = [
                'type'   => 'purchased',
                'at'     => $iso($order->created_at),
                // what was paid for this card, whatever its value is now
                'amount' => self::money($order->amount),
                'method' => $order->payment_method,
                'gift'   => !empty($order->recipient_email),
            ];
        }

        foreach ($staffEvents as $e) {
            $row = [
                'type'      => $e->type,
                'at'        => $iso($e->created_at),
                'amount'    => is_null($e->amount) ? null : self::money($e->amount),
                'old_value' => $e->old_value,
                'new_value' => $e->type === GiftCardEvent::REFUND && !$forStaff ? null : $e->new_value,
            ];
            if ($forStaff) {
                $row['reason'] = $e->reason;
                $row['by'] = optional($e->admin)->name;
            }
            $events[] = $row;
        }

        if ($code->sent_at && !$staffEvents->contains('type', GiftCardEvent::RESEND)) {
            $events[] = ['type' => 'emailed', 'at' => $iso($code->sent_at), 'to' => $code->sent_to];
        }

        if ($code->redeemed_at) {
            $row = ['type' => 'redeemed', 'at' => $iso($code->redeemed_at)];
            if ($forStaff && $code->redeemer) {
                $row['by'] = $code->redeemer->name . ' <' . $code->redeemer->email . '>';
            }
            $events[] = $row;
        }

        if ($voucher) {
            $holding = Voucher::giftHoldingOrderSql('o');
            $uses = DB::select(
                "SELECT o.id, o.`order` AS number, o.created_at, ov.discount, COALESCE(ov.returned_amount, 0) AS returned,"
                . " CASE WHEN $holding THEN 1 ELSE 0 END AS holds"
                . " FROM ordered_voucher ov JOIN orders o ON o.id = ov.order_id AND o.voucher_id = ov.voucher_id"
                . " WHERE ov.voucher_id = ? ORDER BY o.created_at, o.id",
                [$voucher->id]
            );
            foreach ($uses as $u) {
                $events[] = [
                    'type'     => (int)$u->holds === 1 ? 'spent' : 'released',
                    'at'       => $iso($u->created_at),
                    'amount'   => self::money($u->discount),
                    'order'    => $u->number,
                    'order_id' => $forStaff ? (int)$u->id : null,
                ];
                if ((int)$u->holds === 1 && (float)$u->returned > 0) {
                    $events[] = [
                        'type'   => 'returned',
                        'at'     => $iso($u->created_at),
                        'amount' => self::money($u->returned),
                        'order'  => $u->number,
                    ];
                }
            }
        }

        usort($events, function ($a, $b) {
            return strcmp((string)$a['at'], (string)$b['at']);
        });

        [$value, $spent, $balance] = GiftCardAdmin::figures($code, $voucher);

        return [
            'code'    => $code->code,
            'state'   => $code->displayState(),
            'value'   => self::money($value),
            'spent'   => self::money($spent),
            'balance' => self::money($balance),
            // a calendar date: the card is good to the end of it
            'expires' => $code->expires_at ? $code->expires_at->format('Y-m-d') : null,
            'events'  => array_values($events),
        ];
    }

    private static function money($v)
    {
        return number_format((float)$v, 2, '.', '');
    }
}
