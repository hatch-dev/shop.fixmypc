<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One gift card: a code, its value, and where it stands.
 *
 * Redeeming a code credits its full value to the redeemer's Savers Club
 * wallet (WalletController::redeemGiftCode), where it pays for orders at
 * checkout and any balance carries over. status is the single source of
 * truth: active -> redeemed, or active -> void (an admin withdrew it).
 * Expiry is read from expires_at, not stored as a status.
 */
class GiftVoucherCode extends Model
{
    const ACTIVE = 'active';
    const REDEEMED = 'redeemed';
    const VOID = 'void';

    /**
     * Irish law (Consumer Protection (Gift Vouchers) Act 2019) requires a
     * gift voucher to stay valid for at least five years from sale.
     */
    const VALID_YEARS = 5;

    protected $fillable = [
        'gift_voucher_order_id', 'buyer_user_id', 'code', 'amount', 'expires_at',
        'redeemed_by', 'redeemed_at', 'voucher_id', 'status', 'sent_to', 'sent_at', 'void_reason',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'redeemed_at' => 'datetime',
        'sent_at'     => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(GiftVoucherOrder::class, 'gift_voucher_order_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function redeemer()
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * What a person should be told: available, redeemed, void or expired.
     */
    public function displayState()
    {
        if ($this->status === self::REDEEMED) return 'redeemed';
        if ($this->status === self::VOID) return 'void';
        if ($this->isExpired()) return 'expired';
        return 'available';
    }

    /** One code per card bought. Called inside the purchase transaction. */
    public static function issueFor(GiftVoucherOrder $order)
    {
        $codes = [];

        for ($i = 0; $i < max(1, (int)$order->quantity); $i++) {
            $codes[] = static::create([
                'gift_voucher_order_id' => $order->id,
                'buyer_user_id'         => $order->user_id,
                'code'                  => static::newCode(),
                'amount'                => $order->amount,
                'expires_at'            => Carbon::parse($order->created_at ?: now())->addYears(self::VALID_YEARS),
                'status'                => self::ACTIVE,
            ]);
        }

        return $codes;
    }

    /** GIFT-XXXX-XXXX, no 0/O/1/I so it can be read out or typed without doubt. */
    public static function newCode()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $part = function () use ($alphabet) {
                $s = '';
                for ($i = 0; $i < 4; $i++) {
                    $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                return $s;
            };
            $code = 'GIFT-' . $part() . '-' . $part();
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Accepts "gift abcd 2345", "giftabcd2345", "GIFT-ABCD-2345". */
    public static function normalise($input)
    {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$input));

        if (strlen($raw) === 12 && strpos($raw, 'GIFT') === 0) {
            return 'GIFT-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        }

        return strtoupper(trim((string)$input));
    }

    /**
     * Credit this code's value to $user's wallet. Returns [ok, message, balance].
     * Locks the code and the wallet so the same code cannot be credited twice.
     */
    /**
     * The customer's own voucher for a gift card: its code, a flat amount
     * equal to the card's value, no minimum spend, valid until the card
     * expires, and usable by nobody else (vouchers.user_id). Usage limits
     * are left wide open: the balance is what runs out, not a count.
     */
    public static function toVoucher(GiftVoucherCode $gift, User $user)
    {
        $design = optional(optional($gift->order)->design)->title;

        return Voucher::create([
            'title'              => 'Gift card' . ($design ? ' – ' . $design : ''),
            'type'               => \Illuminate\Support\Facades\Config::get('constants.priceType.FLAT'),
            'status'             => \Illuminate\Support\Facades\Config::get('constants.status.PUBLIC'),
            'usage_limit'        => 1000000,
            'limit_per_customer' => 1000000,
            'price'              => $gift->amount,
            'gift_value'         => $gift->amount,
            'capped_price'       => null,
            'min_spend'          => 0,
            'code'               => $gift->code,
            'start_time'         => now()->subMinute(),
            'end_time'           => $gift->expires_at,
            // vouchers.admin_id must name an admin: the store's first one
            'admin_id'           => (int)Admin::orderBy('id')->value('id'),
            'apply_type'         => 1,
            'user_id'            => $user->id,
        ]);
    }

    public static function redeem($input, User $user)
    {
        $code = static::normalise($input);

        return DB::transaction(function () use ($code, $user) {

            $gift = static::where('code', $code)->lockForUpdate()->first();

            if (!$gift) {
                return [false, 'We could not find that gift card code. Check it and try again.', null];
            }
            if ($gift->status === self::REDEEMED) {
                return [false, (int)$gift->redeemed_by === (int)$user->id
                    ? 'This gift card is already in your vouchers.'
                    : 'This gift card has already been redeemed.', null];
            }
            if ($gift->status === self::VOID) {
                return [false, 'This gift card is no longer valid. Please contact us.', null];
            }
            if ($gift->isExpired()) {
                return [false, 'This gift card has expired.', null];
            }

            $voucher = static::toVoucher($gift, $user);

            $gift->update([
                'status'      => self::REDEEMED,
                'redeemed_by' => $user->id,
                'redeemed_at' => now(),
                'voucher_id'  => $voucher->id,
            ]);

            return [true, 'Your €' . number_format((float)$gift->amount, 2)
                . ' gift card is now in your vouchers. Apply it at checkout; anything left over stays for your next order.',
                $voucher];
        });
    }
}
