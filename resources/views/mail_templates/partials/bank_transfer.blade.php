@php
    /**
     * Where to send a bank transfer, on the receipt of an order still waiting
     * on one. The amount and reference come from the order, so they are the
     * same figures the bank details page shows.
     *
     * @var object $brand
     * @var object $order
     * @var array  $bank   OrderMailer::bankTransferDetails()
     */
    $p = $order->calculated_price;
    $amount = $brand->currency_icon . number_format((float) $p['total_price'], 2);

    $rows = [
        ['label' => 'Amount to pay', 'value' => e($amount), 'strong' => true],
        ['label' => 'Payment reference', 'value' => e($order->order), 'strong' => true],
    ];

    foreach ([
        'account_name' => 'Account name',
        'iban' => 'IBAN',
        'bic_swift' => 'BIC / SWIFT',
        'account_number' => 'Account number',
        'bank_name' => 'Bank',
    ] as $key => $label) {
        if (!empty($bank[$key])) {
            $rows[] = ['label' => $label, 'value' => e($bank[$key])];
        }
    }
@endphp

@component('mail_templates.partials.panel', [
    'brand' => $brand,
    'title' => 'Pay by bank transfer',
])
    @include('mail_templates.partials.kv', ['brand' => $brand, 'rows' => $rows])
@endcomponent

@include('mail_templates.partials.callout', [
    'brand' => $brand,
    'tone' => 'warn',
    'space' => 12,
    'text' => 'Use ' . $order->order . ' as the payment reference so we can match your transfer to this order. We will email you once it arrives. If it has not reached us within ' . ($bank['hold_hours'] ?? 24) . ' hours of your order, the order is cancelled automatically.',
])
