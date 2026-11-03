<?php

namespace App\Http\Controllers;

use App\Models\GiftCardEvent;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherCode;
use App\Models\Helper\GiftCardAdmin;
use App\Models\Helper\GiftCardHistory;
use App\Models\Voucher;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gift card sales (admin): every card sold, who bought it, who it was for,
 * and where it stands. Staff can resend a card's email and void a card
 * that has not been redeemed (e.g. a disputed payment).
 *
 * Redeeming only puts a card in a customer's voucher list; it is spent by
 * orders. So a redeemed card reads "In vouchers" until an order uses it,
 * then "Partly used" / "Used up", with what is spent and what is left.
 * Cards redeemed before 2026-09-24 went into a wallet instead (no voucher
 * row): those read "Moved to wallet" and count as spent.
 */
class GiftCardSalesController extends ControllerHelper
{
    /**
     * SQL: what a card is worth now - its voucher's gift_value once redeemed
     * (staff may have changed it, GiftCardAdmin::adjustBalance), else the
     * code's amount.
     */
    private static function valueSql()
    {
        return 'COALESCE((SELECT gv.gift_value FROM vouchers gv WHERE gv.id = gift_voucher_codes.voucher_id), gift_voucher_codes.amount)';
    }

    /** SQL: what orders have taken off this code's voucher (0 if none). */
    private static function usedSql()
    {
        return 'CASE WHEN gift_voucher_codes.voucher_id IS NULL THEN 0 ELSE '
            . Voucher::giftUsedSql('gift_voucher_codes.voucher_id') . ' END';
    }

    /** [state, spent, left] for one code, with used_amount selected. */
    private static function usage($c)
    {
        $amount = round((float)($c->value_now ?? $c->amount), 2);

        if ($c->status !== GiftVoucherCode::REDEEMED) {
            return [$c->displayState(), 0.0, $c->displayState() === 'available' ? $amount : 0.0];
        }

        if (is_null($c->voucher_id)) {
            return ['wallet', $amount, 0.0];
        }

        $spent = round(min($amount, (float)$c->used_amount), 2);
        $left = round($amount - $spent, 2);

        if ($spent <= 0) {
            $state = $c->isExpired() ? 'expired' : 'in_vouchers';
        } else {
            $state = $left <= 0 ? 'used_up' : ($c->isExpired() ? 'expired' : 'partly_used');
        }

        return [$state, $spent, $left];
    }

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'gift_card.view')) {
                return $can;
            }

            $used = self::usedSql();
            $value = self::valueSql();
            $query = GiftVoucherCode::query()
                ->select('gift_voucher_codes.*')
                ->selectRaw("($used) AS used_amount")
                ->selectRaw("($value) AS value_now")
                ->with(['order.design:id,title', 'buyer:id,name,email', 'redeemer:id,name,email']);

            if ($request->q) {
                $q = trim($request->q);
                $query->where(function ($w) use ($q) {
                    $w->where('code', 'LIKE', "%{$q}%")
                        ->orWhere('sent_to', 'LIKE', "%{$q}%")
                        ->orWhereHas('buyer', function ($b) use ($q) {
                            $b->where('name', 'LIKE', "%{$q}%")->orWhere('email', 'LIKE', "%{$q}%");
                        })
                        ->orWhereHas('order', function ($o) use ($q) {
                            $o->where('recipient_email', 'LIKE', "%{$q}%")->orWhere('recipient_name', 'LIKE', "%{$q}%");
                        });
                });
            }

            switch ($request->status) {
                case 'available':
                    $query->where('status', GiftVoucherCode::ACTIVE)
                        ->where(function ($w) { $w->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
                    break;
                case 'redeemed':
                    $query->where('status', GiftVoucherCode::REDEEMED);
                    break;
                case 'in_vouchers':
                    $query->where('status', GiftVoucherCode::REDEEMED)
                        ->whereNotNull('voucher_id')
                        ->whereRaw("($used) <= 0");
                    break;
                case 'partly_used':
                    $query->where('status', GiftVoucherCode::REDEEMED)
                        ->whereNotNull('voucher_id')
                        ->whereRaw("($used) > 0")
                        ->whereRaw("($used) < ($value)");
                    break;
                case 'used_up':
                    $query->where('status', GiftVoucherCode::REDEEMED)
                        ->where(function ($w) use ($used, $value) {
                            $w->whereNull('voucher_id')->orWhereRaw("($used) >= ($value)");
                        });
                    break;
                case 'void':
                    $query->where('status', GiftVoucherCode::VOID);
                    break;
                case 'expired':
                    $query->where('status', GiftVoucherCode::ACTIVE)->where('expires_at', '<=', now());
                    break;
            }

            $allowed = ['id', 'amount', 'created_at', 'redeemed_at'];
            $orderBy = in_array($request->orderby, $allowed) ? $request->orderby : 'id';
            $type = strtolower($request->type) === 'asc' ? 'asc' : 'desc';

            $data = $query->orderBy($orderBy, $type)->paginate(Config::get('constants.api.PAGINATION'));

            $data->getCollection()->transform(function ($c) {
                $order = $c->order;
                [$state, $spent, $left] = self::usage($c);

                return [
                    'id'             => $c->id,
                    'code'           => $c->code,
                    'amount'         => number_format((float)$c->value_now, 2, '.', ''),
                    'order_id'       => $c->gift_voucher_order_id,
                    'order_status'   => optional($order)->status,
                    'refundable'     => $order && in_array($order->payment_method, ['wallet', 'sumup'], true)
                        && in_array($order->status, ['success', 'partially_refunded'], true)
                        && $c->status === GiftVoucherCode::ACTIVE,
                    'design'         => optional(optional($order)->design)->title ?: '—',
                    'state'          => $state,
                    'spent'          => number_format($spent, 2, '.', ''),
                    'left'           => number_format($left, 2, '.', ''),
                    'buyer'          => $c->buyer ? ['id' => $c->buyer->id, 'name' => $c->buyer->name, 'email' => $c->buyer->email] : null,
                    'recipient'      => $order && $order->recipient_email
                        ? ['name' => $order->recipient_name, 'email' => $order->recipient_email]
                        : null,
                    'message'        => optional($order)->message,
                    'payment_method' => optional($order)->payment_method,
                    'sent_to'        => $c->sent_to,
                    'sent_at'        => $c->sent_at ? Utils::formatDate($c->sent_at) : null,
                    'redeemed_by'    => $c->redeemer ? ['name' => $c->redeemer->name, 'email' => $c->redeemer->email] : null,
                    'redeemed_at'    => $c->redeemed_at ? Utils::formatDate($c->redeemed_at) : null,
                    'expires'        => $c->expires_at ? $c->expires_at->format('d M Y') : null,
                    'void_reason'    => $c->void_reason,
                    'created'        => Utils::formatDate($c->created_at),
                ];
            });

            /*
             * Totals across everything sold, for the header. "Spent" is what
             * orders have actually taken off cards (all of a card moved to a
             * wallet under the old rule); "outstanding" is value customers
             * can still use: cards not yet added, plus what is left on added
             * ones, while unexpired.
             */
            $live = '(gift_voucher_codes.expires_at IS NULL OR gift_voucher_codes.expires_at > NOW())';
            $summary = GiftVoucherCode::selectRaw("
                    COUNT(*) as cards,
                    COALESCE(SUM(amount), 0) as sold,
                    COALESCE(SUM(CASE WHEN status = 'redeemed' THEN 1 ELSE 0 END), 0) as added,
                    COALESCE(SUM(CASE
                        WHEN status = 'redeemed' AND voucher_id IS NULL THEN amount
                        WHEN status = 'redeemed' THEN LEAST($value, $used)
                        ELSE 0 END), 0) as spent,
                    COALESCE(SUM(CASE
                        WHEN status = 'active' AND $live THEN amount
                        WHEN status = 'redeemed' AND voucher_id IS NOT NULL AND $live THEN GREATEST(0, ($value) - ($used))
                        ELSE 0 END), 0) as outstanding
                ")->first();

            $payload = $data->toArray();
            $payload['summary'] = [
                'cards'       => (int)$summary->cards,
                'sold'        => number_format((float)$summary->sold, 2, '.', ''),
                'added'       => (int)$summary->added,
                'spent'       => number_format((float)$summary->spent, 2, '.', ''),
                'outstanding' => number_format((float)$summary->outstanding, 2, '.', ''),
            ];

            return response()->json(new Response($request->token, $payload));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Email this card again, to where it was sent (or a corrected address). */
    public function resend(Request $request, $id = null)
    {
        $id = $id ?: $request->id;

        try {
            if ($can = Utils::userCan($this->user, 'gift_card.edit')) {
                return $can;
            }

            $code = GiftVoucherCode::with('order')->find($id);

            if (!$code || !$code->order) {
                return response()->json(Validation::noDataLang(null));
            }

            if ($code->status !== GiftVoucherCode::ACTIVE || $code->isExpired()) {
                return response()->json(Validation::error($request->token, 'Only an unredeemed, unexpired card can be resent.'));
            }

            $order = $code->order;
            /*
             * Staff may correct a mistyped address before resending. It goes
             * on this card only (sent_to), never on the purchase: writing it
             * to recipient_email turned a "for me" purchase into a gift, and
             * redirected every other card in the purchase with it.
             */
            $to = null;
            if ($request->email) {
                $to = trim((string)$request->email);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(Validation::error($request->token, 'That email address is not valid.'));
                }
            }
            $before = $code->sent_to;
            $sent = MailHelper::giftCardDelivery($order, [$code], null, $to);
            if ($sent) {
                GiftCardEvent::record([
                    'gift_voucher_code_id'  => $code->id,
                    'gift_voucher_order_id' => $order->id,
                    'type'                  => GiftCardEvent::RESEND,
                    'old_value'             => $before ? mb_substr($before, 0, 64) : null,
                    'new_value'             => mb_substr((string)$code->fresh()->sent_to, 0, 64),
                    'admin_id'              => optional($this->user)->id,
                ]);
            }

            return $sent
                ? response()->json(new Response($request->token, true, 200, 'Gift card emailed again.'))
                : response()->json(Validation::error($request->token, 'The email could not be sent. Check the address and mail settings.'));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Withdraw a card that has not been redeemed. It can no longer be used. */
    public function void(Request $request, $id = null)
    {
        $id = $id ?: $request->id;

        try {
            if ($can = Utils::userCan($this->user, 'gift_card.edit')) {
                return $can;
            }

            $reason = trim((string)$request->reason);

            return DB::transaction(function () use ($request, $id, $reason) {
                $code = GiftVoucherCode::where('id', $id)->lockForUpdate()->first();

                if (!$code) {
                    return response()->json(Validation::noDataLang(null));
                }

                if ($code->status === GiftVoucherCode::REDEEMED) {
                    return response()->json(Validation::error($request->token,
                        'This card has already been redeemed and cannot be voided.'));
                }

                if ($code->status === GiftVoucherCode::VOID) {
                    return response()->json(Validation::error($request->token, 'This card is already void.'));
                }
                $code->update(['status' => GiftVoucherCode::VOID, 'void_reason' => $reason ?: 'Voided by staff']);
                Log::info('Gift card voided', ['code_id' => $code->id, 'admin_id' => optional($this->user)->id, 'reason' => $reason]);
                GiftCardEvent::record([
                    'gift_voucher_code_id'  => $code->id,
                    'gift_voucher_order_id' => $code->gift_voucher_order_id,
                    'type'                  => GiftCardEvent::VOID,
                    'amount'                => $code->amount,
                    'reason'                => $reason ?: 'Voided by staff',
                    'admin_id'              => optional($this->user)->id,
                ]);

                return response()->json(new Response($request->token, true, 200, 'Gift card voided.'));
            });

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
    /** One card's history, with who did what and why. */
    public function history(Request $request, $id = null)
    {
        if ($can = Utils::userCan($this->user, 'gift_card.view')) {
            return $can;
        }
        $code = GiftVoucherCode::find($id ?: $request->id);
        if (!$code) {
            return response()->json(Validation::noDataLang(null));
        }
        $data = GiftCardHistory::for($code, true);
        $order = $code->order;
        $data['purchase'] = $order ? [
            'id'             => $order->id,
            'status'         => $order->status,
            'payment_method' => $order->payment_method,
            'total'          => number_format((float)$order->total, 2, '.', ''),
            'quantity'       => (int)$order->quantity,
        ] : null;
        $data['log_ready'] = GiftCardEvent::available();
        return response()->json(new Response($request->token, $data));
    }

    /** Designs staff may issue a card in, for the issue form. */
    public function designs(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'gift_card.view')) {
            return $can;
        }
        $designs = GiftVoucher::orderBy('title')->get(['id', 'title', 'amounts', 'is_active']);
        return response()->json(new Response($request->token, $designs));
    }

    /** Issue cards without a purchase. */
    public function issue(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'gift_card.edit')) {
            return $can;
        }
        return $this->reply($request, GiftCardAdmin::issue($request->only([
            'design_id', 'amount', 'quantity', 'expires_at', 'customer_email',
            'recipient_name', 'recipient_email', 'sender_name', 'message', 'reason', 'send_email',
        ]), optional($this->user)->id));
    }

    /** Add to or take from a card's balance. */
    public function adjust(Request $request, $id = null)
    {
        if ($can = Utils::userCan($this->user, 'gift_card.edit')) {
            return $can;
        }
        return $this->reply($request, GiftCardAdmin::adjustBalance($id ?: $request->id, $request->amount, $request->reason, optional($this->user)->id));
    }

    /** Move a card's expiry date. */
    public function expiry(Request $request, $id = null)
    {
        if ($can = Utils::userCan($this->user, 'gift_card.edit')) {
            return $can;
        }
        return $this->reply($request, GiftCardAdmin::changeExpiry($id ?: $request->id, $request->expires_at, $request->reason, optional($this->user)->id));
    }

    /** Refund the unredeemed cards of a purchase, the way it was paid. */
    public function refund(Request $request, $orderId = null)
    {
        if ($can = Utils::userCan($this->user, 'gift_card.edit')) {
            return $can;
        }
        return $this->reply($request, GiftCardAdmin::refundPurchase($orderId ?: $request->order_id, $request->reason, optional($this->user)->id));
    }

    private function reply(Request $request, array $result)
    {
        [$ok, $message, $data] = $result;
        return $ok
            ? response()->json(new Response($request->token, $data ?: true, 200, $message))
            : response()->json(Validation::error($request->token, $message));
    }
}
