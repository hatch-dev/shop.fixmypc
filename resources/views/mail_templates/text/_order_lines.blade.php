@php
    /**
     * Line items for the plain-text part. Each line is assembled in PHP and
     * emitted whole: interleaving @if / @php with literal text leaves stray
     * spaces and broken line breaks in a text/plain body, where there is no
     * markup to absorb them.
     */
    use App\Models\Helper\MailHelper;

    $lang = isset($lang) ? $lang : null;
    $out = [];

    foreach ($order->ordered_products as $op) {
        $title = $op->product && $op->product->title
            ? $op->product->title
            : __('lang.mail_product', [], $lang);

        $variant = MailHelper::generatingAttribute($op);

        $line = '- ' . $title;

        if ($variant) {
            $line .= ' (' . $variant . ')';
        }

        $line .= "\n  " . __('lang.mail_qty', [], $lang) . ' ' . (int) $op->quantity
            . ' x ' . $brand->currency_icon . number_format((float) $op->selling, 2)
            . ' = ' . $brand->currency_icon
            . number_format((float) $op->selling * (int) $op->quantity, 2);

        $out[] = $line;
    }
@endphp
{!! join("\n", $out) !!}
