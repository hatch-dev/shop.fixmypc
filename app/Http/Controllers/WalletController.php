<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\PointTransaction;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherOrder;
use App\Models\GiftVoucherCode;
use App\Models\Helper\GiftCardHistory;
use App\Models\Helper\GiftCardPurchases;
use App\Models\Helper\MailHelper;
use App\Models\Voucher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;
use App\Models\Helper\GatewayVerifier;

class WalletController extends Controller
{
    public function getWallet(Request $request)
    {
        $user = $request->user();

        $wallet = Wallet::where('user_id', $user->id)->first();

        $pending = WalletTransaction::where('user_id', $user->id)
        ->where('status', 'pending')
        ->sum('amount');

        return response()->json([
            'balance' => $wallet?->balance ?? 0.00,
            'pending' => $pending ?? 0.00,
            'currency' => $wallet?->currency ?? 'EUR'
        ]);
    }

    /**
     * Credits the wallet for a SumUp top-up, once, and only after SumUp itself
     * says the checkout was paid.
     *
     * This used to add whatever `amount` the browser posted, without asking
     * SumUp anything and without checking the reference had not been used
     * before, so any signed-in customer could credit their own wallet at will
     * and spend it on gift vouchers. Now the checkout is read back from SumUp:
     * it must be PAID, for this amount, created as a top-up rather than an
     * order payment, and not already credited. The amount credited is the one
     * SumUp charged, not the one the browser sent.
     */
    public function confirmTopup(Request $request)
    {
        $request->validate([
            'checkout_id' => 'required|string|max:100',
            'amount'      => 'required|numeric|min:0.01',
        ]);

        $user = $request->user();

        $verified = GatewayVerifier::verifySumup(
            Payment::first(), $request->checkout_id, $request->amount, 'EUR'
        );

        $checkout = $verified['checkout'] ?? [];

        /*
         * The description is written by createCheckout on the server, so it is
         * the one thing that tells a top-up checkout from an order payment.
         * Without this, paying for an order would also credit the wallet.
         */
        $isTopup = strpos((string)($checkout['description'] ?? ''), 'Top-up Payment #') === 0;

        if (!$verified['ok'] || !$isTopup) {
            Log::warning('Wallet top-up refused', [
                'user_id'     => $user->id,
                'checkout_id' => $request->checkout_id,
                'reason'      => $verified['reason'] ?? 'checkout is not a top-up',
            ]);

            return response()->json([
                'message' => empty($verified['charged'])
                    ? 'The payment did not go through, so nothing was charged. Please try again.'
                    : 'We could not confirm this payment. Your card may have been charged; please contact us.'
            ], 422);
        }

        $reference = $checkout['checkout_reference'] ?? $request->checkout_id;
        $amount = round((float)$checkout['amount'], 2);

        return DB::transaction(function () use ($user, $reference, $amount, $verified) {

            Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'currency' => 'EUR']
            );

            // Serialises two confirms of the same checkout (a double click, a retry).
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            $already = WalletTransaction::where('source', 'topup')
                ->where('reference_id', $reference)
                ->first();

            if ($already) {
                if ((int)$already->user_id !== (int)$user->id) {
                    return response()->json(['message' => 'This payment has already been used.'], 422);
                }

                return response()->json([
                    'message' => 'Wallet already credited for this payment',
                    'balance' => $wallet->balance
                ]);
            }

            $newBalance = round($wallet->balance + $amount, 2);

            WalletTransaction::create([
                'user_id'        => $user->id,
                'type'           => 'credit',
                'amount'         => $amount,
                'balance_after'  => $newBalance,
                'source'         => 'topup',
                'reference_id'   => $reference,
                'transaction_id' => $verified['reference'],
                'status'         => 'success',
            ]);

            $wallet->update(['balance' => $newBalance]);

            return response()->json([
                'message' => 'Wallet credited successfully',
                'balance' => $newBalance
            ]);
        });
    }

    public function transactions(Request $request)
    {
        $user = $request->user();

        // Savers Club's sort and filter controls: ?order=asc|desc, ?type=credit|debit
        $transactions = WalletTransaction::where('user_id', $user->id)
            ->when(in_array($request->type, ['credit', 'debit'], true), function ($q) use ($request) {
                $q->where('type', $request->type);
            })
            ->orderBy('id', $request->order === 'asc' ? 'asc' : 'desc')
            ->paginate(10)
            ->appends($request->only('order', 'type'));

        WalletTransaction::withDescriptions($transactions->getCollection());

        return response()->json($transactions);
    }

    /**
     * The customer's whole wallet history as a CSV, for the Statement button
     * on Savers Club (which used to only scroll to the table).
     */
    public function statement(Request $request)
    {
        $user = $request->user();

        $rows = WalletTransaction::withDescriptions(
            WalletTransaction::where('user_id', $user->id)->orderBy('id', 'desc')->get()
        );

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Description', 'Type', 'Amount (EUR)', 'Balance (EUR)', 'Status']);

        foreach ($rows as $t) {
            fputcsv($out, [
                optional($t->created_at)->format('Y-m-d H:i'),
                $t->description,
                ucfirst($t->type),
                ($t->type === 'debit' ? '-' : '') . number_format((float)$t->amount, 2, '.', ''),
                number_format((float)$t->balance_after, 2, '.', ''),
                ucfirst((string)$t->status),
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="wallet-statement-' . date('Y-m-d') . '.csv"',
        ]);
    }

    public function getPoints(Request $request)
    {
        $user = $request->user();

        $points = PointTransaction::balanceFor($user->id);

        $value = $points * 0.01;

        [$pendingPoints, $pendingOrders] = PointTransaction::pendingFor($user->id);

        return response()->json([
            'points' => (int)$points,
            'value' => number_format($value, 2, '.', ''),
            // earned on delivery; shown on Savers Club as "on the way"
            'pending_points' => $pendingPoints,
            'pending_orders' => $pendingOrders,
        ]);
    }

    /**
     * Lifetime savings, as the Vouchers and Savers Club pages show it: what
     * vouchers really took off this customer's orders. Both pages read this
     * one figure so they cannot disagree.
     */
    public function savings(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'total_saved' => number_format(Voucher::savedBy($user->id), 2, '.', ''),
        ]);
    }

    /** Cherry Points earned and used, newest first, for Savers Club. */
    public function pointsHistory(Request $request)
    {
        $user = $request->user();

        // ?order=asc|desc, ?type=credit (earned or returned) | debit (used or removed)
        $rows = PointTransaction::where('user_id', $user->id)
            ->when(in_array($request->type, ['credit', 'debit'], true), function ($q) use ($request) {
                $q->where('type', $request->type);
            })
            ->orderBy('id', $request->order === 'asc' ? 'asc' : 'desc')
            ->paginate(10, ['id', 'type', 'points', 'value', 'source', 'reference_id', 'created_at'])
            ->appends($request->only('order', 'type'));

        PointTransaction::withDescriptions($rows->getCollection());

        return response()->json($rows);
    }

    /* ══════════ Gift cards ══════════
     *
     * A design (gift_voucher) is bought in a value it offers, 1..n at a time,
     * for yourself or for someone else. Each card becomes a code
     * (GiftVoucherCode); redeeming a code adds its value to the redeemer's
     * wallet, which pays for orders at checkout.
     */

    /**
     * Validates a purchase against the design and returns
     * [[design, unit, quantity, total, recipient], null] or [null, refusal].
     * `amount` is ONE card's face value and must be one the design offers.
     */
    private function giftVoucherLine(Request $request)
    {
        $lang = $request->header('language');
        /*
         * Field by field, in words a customer can act on, and returned as
         * `errors` so the form can put each one under its own field. The
         * framework default ("The given data was invalid.") told nobody
         * which field was wrong.
         */
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'voucher_id'      => 'required|exists:gift_voucher,id',
            'amount'          => 'required|numeric|min:1',
            'quantity'        => 'required|integer|min:1',
            'recipient_email' => 'nullable|email:rfc|max:190',
            'recipient_name'  => ['nullable', 'required_with:recipient_email', 'string', 'min:2', 'max:120', 'regex:/\pL/u'],
            'sender_name'     => 'nullable|string|max:120',
            'message'         => 'nullable|string|max:300',
        ], [
            'voucher_id.*'                 => __('lang.gc_design_required', [], $lang),
            'amount.*'                     => __('lang.gc_amount_required', [], $lang),
            'quantity.*'                   => __('lang.gc_quantity_required', [], $lang),
            'recipient_email.email'        => __('lang.gc_recipient_email_invalid', [], $lang),
            'recipient_email.max'          => __('lang.gc_recipient_email_long', [], $lang),
            'recipient_name.required_with' => __('lang.gc_recipient_name_required', [], $lang),
            'recipient_name.min'           => __('lang.gc_recipient_name_short', [], $lang),
            'recipient_name.max'           => __('lang.gc_recipient_name_long', [], $lang),
            'recipient_name.regex'         => __('lang.gc_recipient_name_letters', [], $lang),
            'message.max'                  => __('lang.gc_message_long', [], $lang),
        ]);

        // A gift to your own address is a purchase for yourself.
        $validator->after(function ($v) use ($request, $lang) {
            $buyer = Auth::user();
            if ($buyer && $request->recipient_email
                && strcasecmp(trim($request->recipient_email), trim((string)$buyer->email)) === 0) {
                $v->errors()->add('recipient_email', __('lang.gc_own_email', [], $lang));
            }
        });

        if ($validator->fails()) {
            return [null, response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => collect($validator->errors()->toArray())->map(function ($m) { return $m[0]; }),
            ], 422)];
        }

        $voucher = GiftVoucher::findOrFail($request->voucher_id);

        if (isset($voucher->is_active) && !$voucher->is_active) {
            return [null, response()->json(['message' => __('lang.gc_unavailable', [], $lang)], 422)];
        }

        $offered = collect(is_array($voucher->amounts) ? $voucher->amounts : (json_decode($voucher->amounts, true) ?: []))
            ->map(function ($a) { return round((float)$a, 2); });

        $unit = round((float)$request->amount, 2);
        $quantity = (int)$request->quantity;

        if (!$offered->contains($unit)) {
            return [null, response()->json(['message' => __('lang.gc_amount_unavailable', [], $lang)], 422)];
        }

        if ($quantity < (int)$voucher->min_quantity || $quantity > (int)$voucher->max_quantity) {
            return [null, response()->json(['message' => __('lang.gc_quantity_range', ['min' => $voucher->min_quantity, 'max' => $voucher->max_quantity], $lang)], 422)];
        }

        $recipient = [
            'recipient_email' => $request->recipient_email ?: null,
            'recipient_name'  => $request->recipient_email ? ($request->recipient_name ?: null) : null,
            'sender_name'     => $request->recipient_email ? ($request->sender_name ?: null) : null,
            'message'         => $request->recipient_email ? ($request->message ?: null) : null,
        ];

        return [[$voucher, $unit, $quantity, round($unit * $quantity, 2), $recipient], null];
    }

    /**
     * Buy gift cards from the wallet.
     *
     * Balance checked under a lock; the codes are issued in the same
     * transaction as the debit, and emailed once it has committed.
     */
    public function purchaseGiftVoucher(Request $request)
    {
        if ($request->payment_method !== 'wallet') {
            return response()->json(['message' => __('lang.gc_card_at_checkout', [], $request->header('language'))], 422);
        }

        [$line, $refusal] = $this->giftVoucherLine($request);
        if ($refusal) {
            return $refusal;
        }
        [$voucher, $unit, $quantity, $total, $recipient] = $line;

        $user = Auth::user();

        $result = DB::transaction(function () use ($user, $voucher, $unit, $quantity, $total, $recipient) {

            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (!$wallet || round((float)$wallet->balance, 2) < $total) {
                return null;
            }

            $order = GiftVoucherOrder::create(array_merge([
                'user_id'        => $user->id,
                'voucher_id'     => $voucher->id,
                'amount'         => $unit,
                'quantity'       => $quantity,
                'total'          => $total,
                'payment_method' => 'wallet',
                'status'         => 'success',
            ], $recipient));

            $codes = GiftVoucherCode::issueFor($order);

            $newBalance = round($wallet->balance - $total, 2);
            $wallet->update(['balance' => $newBalance]);

            WalletTransaction::create([
                'user_id'       => $user->id,
                'type'          => 'debit',
                'amount'        => $total,
                'balance_after' => $newBalance,
                'source'        => 'gift_voucher',
                'reference_id'  => $order->id,
                'status'        => 'success',
            ]);

            return [$order, $codes, $newBalance];
        });

        if (!$result) {
            return response()->json(['message' => __('lang.gc_wallet_short', [], $request->header('language'))], 422);
        }

        [$order, $codes, $newBalance] = $result;
        $lang = $request->header('language');
        $sent = MailHelper::giftCardDelivery($order, $codes, $lang);
        return response()->json([
            'message' => $this->purchasedMessage($order, count($codes), $sent, $lang),
            'balance' => $newBalance,
        ]);
    }

    /**
     * Card purchase, step 1: record the purchase as pending and open a SumUp
     * checkout for its total. The design, value, quantity and recipient are
     * fixed here, on the server, before any payment; confirmation only
     * needs the checkout id.
     */
    public function startGiftCardCheckout(Request $request)
    {
        [$line, $refusal] = $this->giftVoucherLine($request);
        if ($refusal) {
            return $refusal;
        }
        [$voucher, $unit, $quantity, $total, $recipient] = $line;

        $checkoutRequest = new Request([
            'amount'          => $total,
            'currency'        => 'EUR',
            'is_gift_voucher' => true,
        ]);
        // createCheckout only starts a gift card checkout for a signed-in customer
        $checkoutRequest->setUserResolver(function () use ($request) {
            return $request->user('user') ?: $request->user();
        });

        $checkout = app(SumupPaymentController::class)->createCheckout($checkoutRequest);

        $checkout = is_array($checkout) ? $checkout : (method_exists($checkout, 'getData') ? $checkout->getData(true) : []);

        if (empty($checkout['id'])) {
            Log::warning('Gift card checkout could not be created', ['response' => $checkout]);
            return response()->json(['message' => __('lang.gc_start_failed', [], $request->header('language'))], 422);
        }

        GiftVoucherOrder::create(array_merge([
            'user_id'        => Auth::id(),
            'voucher_id'     => $voucher->id,
            'amount'         => $unit,
            'quantity'       => $quantity,
            'total'          => $total,
            'payment_method' => 'sumup',
            'reference'      => $checkout['id'],
            'status'         => 'pending',
        ], $recipient));

        return response()->json(['checkout_id' => $checkout['id']]);
    }

    /**
     * Card purchase, step 2: the customer's browser is back from SumUp. Ask
     * SumUp where the checkout stands and, if it was paid for this
     * purchase's total, issue the codes (GiftCardPurchases::settle). The
     * same settling runs when SumUp notifies us (sumupGiftCardNotify) and on
     * a schedule (gift-cards:reconcile-payments), so the codes no longer
     * depend on the customer coming back. Safe to call any number of times.
     */
    public function confirmGiftVoucher(Request $request)
    {
        $request->validate(['checkout_id' => 'required|string|max:100']);
        $lang = $request->header('language');
        $user = Auth::user();
        $order = GiftVoucherOrder::where('reference', $request->checkout_id)
            ->where('user_id', $user->id)
            ->first();
        if (!$order) {
            return response()->json(['message' => __('lang.gc_purchase_missing', [], $lang)], 422);
        }
        $result = GiftCardPurchases::settleAndDeliver($order, $lang);
        switch ($result['state']) {
            case 'paid':
                $fresh = $order->fresh();
                return response()->json(['message' => $this->purchasedMessage($fresh, $fresh->quantity, $result['sent'], $lang)]);
            case 'pending':
                return response()->json(['message' => __('lang.gc_pending', [], $lang), 'pending' => true], 202);
            case 'unconfirmed':
                Log::warning('Gift card card payment refused', ['gift_order_id' => $order->id, 'reason' => 'charged but does not match']);
                return response()->json(['message' => __('lang.gc_unconfirmed', [], $lang)], 422);
            default:
                // failed or cancelled: SumUp never took the money
                return response()->json(['message' => __('lang.gc_not_charged', [], $lang)], 422);
        }
    }

    /**
     * SumUp's notification that a gift card checkout changed (the checkout's
     * return_url). It carries the checkout id and nothing we would trust: the
     * purchase is settled by asking SumUp directly, exactly as the browser's
     * confirm does, so a forged or repeated call can at most make us look
     * again. Always answers 200 with nothing about the purchase in it.
     */
    public function sumupGiftCardNotify(Request $request)
    {
        $checkoutId = (string)($request->input('id') ?: $request->input('checkout_id'));
        if ($checkoutId !== '' && strlen($checkoutId) <= 100) {
            $order = GiftVoucherOrder::where('reference', $checkoutId)
                ->where('payment_method', 'sumup')
                ->first();
            if ($order) {
                try {
                    GiftCardPurchases::settleAndDeliver($order);
                } catch (\Throwable $e) {
                    Log::error('Gift card SumUp notification could not be settled', [
                        'gift_order_id' => $order->id, 'message' => $e->getMessage(),
                    ]);
                }
            }
        }
        return response()->json(['ok' => true]);
    }

    private function purchasedMessage($order, $count, $sent, $lang = null)
    {
        if ($order->recipient_email) {
            if (!$sent) {
                return __('lang.gc_email_failed', ['email' => $order->recipient_email], $lang);
            }
            return $count > 1
                ? __('lang.gc_sent_many', ['count' => $count, 'email' => $order->recipient_email], $lang)
                : __('lang.gc_sent_one', ['email' => $order->recipient_email], $lang);
        }
        if ($count > 1) {
            return __($sent ? 'lang.gc_ready_many_inbox' : 'lang.gc_ready_many', ['count' => $count], $lang);
        }
        return __($sent ? 'lang.gc_ready_one_inbox' : 'lang.gc_ready_one', [], $lang);
    }

    /**
     * One of the customer's gift cards, start to finish: bought, emailed,
     * added to vouchers, spent, given back, changed by staff. Theirs if they
     * bought it or redeemed it. By code id (My gift cards) or by the voucher
     * it became (the voucher list).
     */
    public function giftCardHistory(Request $request)
    {
        $user = Auth::user();
        $query = GiftVoucherCode::query();
        if ($request->code_id) {
            $query->where('id', (int)$request->code_id);
        } elseif ($request->voucher_id) {
            $query->where('voucher_id', (int)$request->voucher_id);
        } else {
            return response()->json(['message' => __('lang.gc_not_found', [], $request->header('language'))], 422);
        }
        $code = $query->where(function ($w) use ($user) {
            $w->where('buyer_user_id', $user->id)->orWhere('redeemed_by', $user->id);
        })->first();
        if (!$code) {
            return response()->json(['message' => __('lang.gc_not_found', [], $request->header('language'))], 404);
        }
        return response()->json(['status' => 200, 'data' => GiftCardHistory::for($code, false)]);
    }

    /** The gift cards this customer has bought, one row per code. */
    public function giftCodes(Request $request)
    {
        $user = Auth::user();

        $codes = GiftVoucherCode::with(['order.design', 'redeemer:id,name'])
            ->where('buyer_user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $data = $codes->map(function ($c) use ($user) {
            $state = $c->displayState();
            $order = $c->order;

            return [
                'id'             => $c->id,
                'code'           => $c->code,
                'amount'         => number_format((float)$c->amount, 2, '.', ''),
                'title'          => optional(optional($order)->design)->title ?: 'Gift card',
                'purchased_at'   => optional($c->created_at)->toIso8601String(),
                'expires_at'     => optional($c->expires_at)->toIso8601String(),
                'state'          => $state,
                'redeemed_by_me' => $state === 'redeemed' && (int)$c->redeemed_by === (int)$user->id,
                'redeemed_at'    => optional($c->redeemed_at)->toIso8601String(),
                'recipient'      => optional($order)->recipient_email
                    ? trim((optional($order)->recipient_name ?: '') . ' <' . $order->recipient_email . '>')
                    : null,
                'sent_at'        => optional($c->sent_at)->toIso8601String(),
            ];
        })->values();

        return response()->json(['status' => 200, 'data' => $data]);
    }

    /** Add a gift card's value to the signed-in customer's wallet. */
    public function redeemGiftCode(Request $request)
    {
        $request->validate(['code' => 'required|string|max:40']);

        [$ok, $message, $voucher] = GiftVoucherCode::redeem($request->code, Auth::user(), $request->header('language'));

        return $ok
            ? response()->json(['status' => 200, 'message' => $message, 'voucher_code' => $voucher->code])
            : response()->json(['message' => $message], 422);
    }
}
