@php
    /**
     * Line items.
     *
     * A three-cell row per product rather than a five-column grid: on a
     * 320px screen a five-column price table is unreadable, and the columns
     * a customer actually checks are "what did I buy" and "what did it cost".
     * Quantity rides on the product image as a badge-style caption and unit
     * price sits under the title, so nothing has to be dropped on mobile.
     *
     * @var object $order
     * @var object $brand
     */
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\MailHelper;

    $lang = isset($lang) ? $lang : null;
    $items = $order->ordered_products;
    $count = 0;
    foreach ($items as $i) { $count += (int) $i->quantity; }
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       class="bg-card hairline"
       style="background-color:{{ $brand->surface }}; border:1px solid {{ $brand->line }}; border-radius:14px;">

    <tr>
        <td class="px-sm hairline" style="padding:14px 20px; border-bottom:1px solid {{ $brand->line }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td align="left" style="font-family:Arial,Helvetica,sans-serif; font-size:12px;
                        line-height:16px; letter-spacing:1.2px; text-transform:uppercase; font-weight:bold;
                        color:{{ $brand->ink }};" class="ink">
                        {{ __('lang.mail_items_ordered', [], $lang) }}
                    </td>
                    <td align="right" style="font-family:Arial,Helvetica,sans-serif; font-size:12px;
                        line-height:16px; font-weight:bold; color:{{ $brand->primary }};">
                        {{ trans_choice('lang.mail_item_count', $count, ['count' => $count], $lang) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    @foreach ($items as $index => $op)
        @php
            $title = $op->product && $op->product->title ? $op->product->title : __('lang.mail_product', [], $lang);
            $variant = MailHelper::generatingAttribute($op);
            $image = $op->product && $op->product->image ? MailBrand::asset($op->product->image) : null;
            $lineTotal = (float) $op->selling * (int) $op->quantity;
        @endphp
        <tr>
            <td class="cell-sm" style="padding:16px 20px; {{ $index + 1 < count($items) ? 'border-bottom:1px solid ' . $brand->line . ';' : '' }}">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td class="thumb" valign="top" width="64" style="width:64px; padding-right:14px;">
                            @if ($image)
                                <img src="{{ $image }}" width="64" height="64" alt="{{ $title }}"
                                     style="display:block; width:64px; height:64px; border:1px solid {{ $brand->line }};
                                            border-radius:10px; background-color:{{ $brand->canvas }};">
                            @else
                                {{-- No product image on file. A tinted tile with the
                                     initial keeps the row aligned instead of leaving a
                                     ragged gap where the thumbnail should be. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                                       width="64" style="width:64px; height:64px;
                                       border:1px solid {{ $brand->line }}; border-radius:10px;
                                       background-color:{{ $brand->canvas }};">
                                    <tr>
                                        <td align="center" valign="middle" height="62"
                                            style="height:62px; font-family:Arial,Helvetica,sans-serif;
                                                   font-size:20px; font-weight:bold; color:{{ $brand->faint }};">
                                            {{ mb_strtoupper(mb_substr($title, 0, 1)) }}
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>

                        <td valign="top" style="font-family:Arial,Helvetica,sans-serif;">
                            <p class="ink" style="margin:0 0 3px 0; font-size:15px; line-height:22px;
                               font-weight:bold; color:{{ $brand->ink }};">{{ $title }}</p>

                            @if ($variant)
                                <p class="muted" style="margin:0 0 3px 0; font-size:13px; line-height:20px;
                                   color:{{ $brand->muted }};">{{ $variant }}</p>
                            @endif

                            <p class="muted" style="margin:0; font-size:13px; line-height:20px;
                               color:{{ $brand->muted }};">
                                {{ __('lang.mail_qty', [], $lang) }} {{ $op->quantity }}
                                &nbsp;·&nbsp;
                                {{ $brand->currency_icon }}{{ number_format((float) $op->selling, 2) }}
                                {{ __('lang.mail_each', [], $lang) }}
                            </p>
                        </td>

                        <td valign="top" align="right" style="padding-left:10px; white-space:nowrap;
                            font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:22px;
                            font-weight:bold; color:{{ $brand->ink }};" class="ink">
                            {{ $brand->currency_icon }}{{ number_format($lineTotal, 2) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    @endforeach
</table>
