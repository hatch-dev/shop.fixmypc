@php
    /**
     * Order money summary.
     *
     * Dark panel painted with bgcolor as well as background-color: Word only
     * honours the attribute, and a summary that loses its background would
     * put white text on white.
     *
     * @var object $order
     * @var object $brand
     */
    $lang = isset($lang) ? $lang : null;
    $p = $order->calculated_price;

    $money = function ($value) use ($brand) {
        return $brand->currency_icon . number_format((float) $value, 2);
    };

    $rows = [];

    /*
     * Subtotal at list price, then the discount that got the goods down to what
     * was charged - the same pair, in the same order, the customer approved at
     * checkout and reads on the order confirmation.
     *
     * This printed a single "Subtotal" of the goods at the charged price, so a
     * business customer's receipt showed the discounted figure with nothing
     * naming the discount: the one number a trade account wants to see on a
     * receipt was the one number missing from it. Falls back to the old shape
     * when there is no discount, and when the summary was built by hand
     * (MailPreviewController) and has neither key.
     */
    $discount = (float) ($p['discount'] ?? 0);

    $rows[] = [
        'label' => __('lang.subtotal', [], $lang),
        'value' => $money($discount > 0 ? $p['original_subtotal'] : $p['subtotal']),
    ];

    if ($discount > 0) {
        $rows[] = [
            'label' => __('lang.product_discount', [], $lang),
            'value' => '− ' . $money($discount),
            'tone' => 'positive',
        ];
    }

    $rows[] = [
        'label' => __('lang.shipping_cost', [], $lang),
        'value' => (float) $p['shipping_price'] > 0 ? $money($p['shipping_price']) : __('lang.mail_free', [], $lang),
        'tone'  => (float) $p['shipping_price'] > 0 ? null : 'positive',
    ];

    if ((float) $p['bundle_offer'] > 0) {
        $rows[] = ['label' => __('lang.bundle_offer', [], $lang), 'value' => '− ' . $money($p['bundle_offer']), 'tone' => 'positive'];
    }

    if (isset($p['voucher_price']) && (float) $p['voucher_price'] > 0) {
        $rows[] = ['label' => __('lang.voucher', [], $lang), 'value' => '− ' . $money($p['voucher_price']), 'tone' => 'positive'];
    }

    /*
     * The discounts beside the order rather than on its lines. Without these
     * the email listed a total the customer was never charged, with no line
     * accounting for the difference.
     *
     * Each is isset()-guarded because MailPreviewController builds its own
     * calculated_price by hand for previews.
     */
    if (isset($p['flash_discount']) && (float) $p['flash_discount'] > 0) {
        $rows[] = ['label' => __('lang.flash_discount', [], $lang), 'value' => '− ' . $money($p['flash_discount']), 'tone' => 'positive'];
    }

    if (isset($p['bundle_discount']) && (float) $p['bundle_discount'] > 0) {
        $rows[] = ['label' => __('lang.bundle_discount', [], $lang), 'value' => '− ' . $money($p['bundle_discount']), 'tone' => 'positive'];
    }

    if (isset($p['loyalty_discount']) && (float) $p['loyalty_discount'] > 0) {
        // The wording frozen on the order, so a later rename cannot rewrite it.
        $rows[] = [
            'label' => $p['loyalty_label'] ?: __('lang.loyalty_discount', [], $lang),
            'value' => '− ' . $money($p['loyalty_discount']),
            'tone'  => 'positive',
        ];
    }

    if (isset($p['points_discount']) && (float) $p['points_discount'] > 0) {
        $rows[] = ['label' => __('lang.points_discount', [], $lang), 'value' => '− ' . $money($p['points_discount']), 'tone' => 'positive'];
    }

    /*
     * The VAT statement, in the order that reconciles: net, then the tax
     * on it, then the payable total below. `tax` was the VAT *added*, which
     * is zero on the products whose prices already contain it - so an email
     * for one of those showed no VAT at all on a price that carries 23%.
     *
     * isset-guarded because MailPreviewController builds its own
     * calculated_price by hand for previews.
     */
    if (isset($p['ex_vat'])) {
        $rows[] = ['label' => __('lang.net_ex_vat', [], $lang), 'value' => $money($p['ex_vat'])];
        $rows[] = ['label' => __('lang.vat', [], $lang), 'value' => $money($p['vat'])];

    } else if ((float) $p['tax'] > 0) {
        $rows[] = ['label' => __('lang.tax', [], $lang), 'value' => $money($p['tax'])];
    }
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       bgcolor="{{ $brand->ink }}"
       style="background-color:{{ $brand->ink }}; border-radius:14px;">
    <tr>
        <td class="px-sm" style="padding:22px 22px 20px 22px;">

            @foreach ($rows as $i => $row)
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td align="left" style="padding:{{ $i === 0 ? '0' : '10px' }} 10px 0 0;
                            font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px;
                            color:#b6c2d6;">{{ $row['label'] }}</td>
                        <td align="right" style="padding:{{ $i === 0 ? '0' : '10px' }} 0 0 0;
                            font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px;
                            font-weight:bold; white-space:nowrap;
                            color:{{ isset($row['tone']) && $row['tone'] === 'positive' ? '#5fe3b0' : '#ffffff' }};">{{ $row['value'] }}</td>
                    </tr>
                </table>
            @endforeach

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td style="padding:18px 0 0 0; font-size:0; line-height:0;">
                        <div style="height:1px; line-height:1px; font-size:1px;
                                    background-color:#2b3a52;">&nbsp;</div>
                    </td>
                </tr>
            </table>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td align="left" valign="middle" style="padding:16px 10px 0 0;
                        font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px;
                        color:#b6c2d6;">{{ __('lang.total_inc_vat', [], $lang) }}</td>
                    <td align="right" valign="middle" class="total" style="padding:16px 0 0 0;
                        font-family:Arial,Helvetica,sans-serif; font-size:30px; line-height:36px;
                        font-weight:bold; white-space:nowrap; color:#ffffff;
                        mso-line-height-rule:exactly;">{{ $money($p['total_price']) }}</td>
                </tr>
            </table>

            {{-- A split payment: how the total was paid. A payment, not a discount. --}}
            @if (isset($p['wallet_paid']) && (float) $p['wallet_paid'] > 0)
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td align="left" style="padding:12px 10px 0 0;
                            font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:20px;
                            color:#b6c2d6;">Paid from wallet</td>
                        <td align="right" style="padding:12px 0 0 0;
                            font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:20px;
                            font-weight:bold; white-space:nowrap; color:#5fe3b0;">{{ '− ' . $money($p['wallet_paid']) }}</td>
                    </tr>
                    <tr>
                        <td align="left" style="padding:4px 10px 0 0;
                            font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:20px;
                            color:#b6c2d6;">Paid by your chosen method</td>
                        <td align="right" style="padding:4px 0 0 0;
                            font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:20px;
                            font-weight:bold; white-space:nowrap; color:#ffffff;">{{ $money($p['amount_due']) }}</td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
</table>
