@php
    /**
     * Money summary for the plain-text part, assembled in PHP for the same
     * reason as the line items: control over exactly where the newlines fall.
     */
    $lang = isset($lang) ? $lang : null;
    $p = $order->calculated_price;

    $money = function ($v) use ($brand) {
        return $brand->currency_icon . number_format((float) $v, 2);
    };

    $rows = [];

    // Subtotal at list price then the product discount, matching the HTML part.
    $discount = (float) ($p['discount'] ?? 0);

    $rows[] = __('lang.subtotal', [], $lang) . ': '
        . $money($discount > 0 ? $p['original_subtotal'] : $p['subtotal']);

    if ($discount > 0) {
        $rows[] = __('lang.product_discount', [], $lang) . ': -' . $money($discount);
    }

    $rows[] = __('lang.shipping_cost', [], $lang) . ': '
        . ((float) $p['shipping_price'] > 0 ? $money($p['shipping_price']) : __('lang.mail_free', [], $lang));

    if ((float) $p['bundle_offer'] > 0) {
        $rows[] = __('lang.bundle_offer', [], $lang) . ': -' . $money($p['bundle_offer']);
    }

    if (isset($p['voucher_price']) && (float) $p['voucher_price'] > 0) {
        $rows[] = __('lang.voucher', [], $lang) . ': -' . $money($p['voucher_price']);
    }

    // Kept in step with the HTML part; see partials/totals.blade.php.
    if (isset($p['flash_discount']) && (float) $p['flash_discount'] > 0) {
        $rows[] = __('lang.flash_discount', [], $lang) . ': -' . $money($p['flash_discount']);
    }

    if (isset($p['bundle_discount']) && (float) $p['bundle_discount'] > 0) {
        $rows[] = __('lang.bundle_discount', [], $lang) . ': -' . $money($p['bundle_discount']);
    }

    if (isset($p['loyalty_discount']) && (float) $p['loyalty_discount'] > 0) {
        $rows[] = ($p['loyalty_label'] ?: __('lang.loyalty_discount', [], $lang))
            . ': -' . $money($p['loyalty_discount']);
    }

    if (isset($p['points_discount']) && (float) $p['points_discount'] > 0) {
        $rows[] = __('lang.points_discount', [], $lang) . ': -' . $money($p['points_discount']);
    }

    // Net then VAT, matching the HTML part.
    if (isset($p['ex_vat'])) {
        $rows[] = __('lang.net_ex_vat', [], $lang) . ': ' . $money($p['ex_vat']);
        $rows[] = __('lang.vat', [], $lang) . ': ' . $money($p['vat']);

    } else if ((float) $p['tax'] > 0) {
        $rows[] = __('lang.tax', [], $lang) . ': ' . $money($p['tax']);
    }

    $rows[] = __('lang.total_inc_vat', [], $lang) . ': ' . $money($p['total_price']);

    // A split payment: how the total was paid.
    if (isset($p['wallet_paid']) && (float) $p['wallet_paid'] > 0) {
        $rows[] = 'Paid from wallet: -' . $money($p['wallet_paid']);
        $rows[] = 'Paid by your chosen method: ' . $money($p['amount_due']);
    }
@endphp
{!! join("\n", $rows) !!}
