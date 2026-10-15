<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{


    protected $casts = [
        'paypal' => 'integer',
        'stripe' => 'integer',
        'flutterwave' => 'integer',
        'razorpay' => 'integer',
        'iyzico_payment' => 'integer',
        'cash_on_delivery' => 'integer',
        'bank' => 'integer',
        'default' => 'integer',
        'card_gateway' => 'integer',
    ];

    protected $fillable = [
        'cash_on_delivery', 'razorpay_key', 'razorpay_secret', 'stripe_key', 'stripe_secret',
        'paypal', 'paypal_key', 'paypal_secret','admin_id',
        'razorpay', 'stripe',
        'flutterwave', 'fw_environment','fw_public_key', 'fw_secret_key', 'fw_encryption_key',
        'iyzico_payment', 'ip_base_url','ip_api_key', 'ip_secret_key',
        'bank', 'bank_name', 'branch_name', 'account_name', 'account_number',
        'iban', 'bic_swift', 'default',
        'payfast_payment', 'payfast_base_url', 'payfast_merchant_id', 'payfast_merchant_key',
        'payfast_passphrase', 'sumup', 'sumup_api_key', 'sumup_merchant_email', 'sumup_merchant_code',
        'card_gateway', 'stripe_webhook_secret'

    ];

    protected $hidden = [
        'admin_id'
    ];

    /**
     * What a shopper's browser may see: which methods are on, the keys a
     * gateway's own client script needs, and where to send a bank transfer.
     *
     * A list of what is allowed out rather than $hidden listing what is not.
     * The row holds the secret key of every gateway this site has ever been
     * configured for, and the public endpoints used to return all of it --
     * live Stripe and SumUp secrets included. With a whitelist, a gateway
     * added later stays private until someone decides its field is public.
     */
    const PUBLIC_FIELDS = [
        'cash_on_delivery', 'paypal', 'stripe', 'razorpay', 'flutterwave',
        'iyzico_payment', 'payfast_payment', 'sumup', 'bank', 'default',
        'paypal_key', 'stripe_key', 'razorpay_key', 'fw_public_key',
        'bank_name', 'branch_name', 'account_name', 'account_number',
        'iban', 'bic_swift',
    ];

    public static function publicSettings()
    {
        $payment = self::first();

        if (!$payment) {
            return null;
        }

        return array_merge($payment->only(self::PUBLIC_FIELDS), [
            // Resolved, not the raw column: the one card option checkout shows.
            'card_gateway' => $payment->cardGateway(),
            'bank_transfer_hours' => \App\Console\Commands\CancelUnpaidBankTransfers::holdHours(),
        ]);
    }

    /**
     * Gateways this store does not use: no checkout row, greyed out in the
     * admin, and saved as off whatever a request says.
     */
    const RETIRED_GATEWAYS = ['paypal', 'razorpay', 'flutterwave', 'iyzico_payment', 'payfast_payment'];

    /**
     * The gateways that can sit behind checkout's one card option. Only one
     * takes cards at a time; the other is refused even when its own box is
     * ticked, so a stale tab or a hand-made request cannot pick it.
     */
    public static function cardGateways()
    {
        return [
            (int)config('constants.paymentMethod.SUMUP'),
            (int)config('constants.paymentMethod.STRIPE'),
        ];
    }

    /**
     * The gateway taking cards: the one chosen in settings if it is switched
     * on, otherwise SumUp, then Stripe, whichever is on. Null when neither.
     */
    public function cardGateway()
    {
        $sumup = (int)config('constants.paymentMethod.SUMUP');
        $stripe = (int)config('constants.paymentMethod.STRIPE');
        $on = [$sumup => (int)$this->sumup === 1, $stripe => (int)$this->stripe === 1];

        $chosen = (int)$this->card_gateway;
        if (!empty($on[$chosen])) {
            return $chosen;
        }

        foreach ([$sumup, $stripe] as $method) {
            if ($on[$method]) {
                return $method;
            }
        }

        return null;
    }

    /** Whether a card payment by this method is accepted now. */
    public function acceptsCardMethod($method)
    {
        return (int)$method === $this->cardGateway();
    }

    use HasFactory;
}
