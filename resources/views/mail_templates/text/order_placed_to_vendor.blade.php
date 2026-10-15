@php
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\MailHelper;
    use App\Models\Helper\Utils;

    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);
    $placedOn = isset($order->created) && $order->created ? $order->created : Utils::formatDate($order->created_at);

    // worked out by OrderMailer::vendorCopies; null = the whole order is theirs
    $share = isset($order->vendor_totals) ? $order->vendor_totals : null;
    $money = function ($v) use ($brand) { return $brand->currency_icon . number_format((float) $v, 2); };
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_vendor_title', [], $lang) !!}

{!! __('lang.mail_vendor_sub', ['order' => $order->order], $lang) !!}

{!! __('lang.mail_order_number', [], $lang) !!}: #{!! $order->order !!}
{!! __('lang.mail_order_placed_on', [], $lang) !!}: {!! $placedOn !!}
@if ($order->order_method)
{!! __('lang.order_method', [], $lang) !!}: {!! $order->order_method !!}
@endif

{!! __('lang.ship_to', [], $lang) !!}:
{!! $order->formatted_address !!}

{!! __('lang.mail_items_ordered', [], $lang) !!}:
@include('mail_templates.text._order_lines', ['order' => $order, 'brand' => $brand, 'lang' => $lang])
@if (is_null($share))
@include('mail_templates.text._order_totals', ['order' => $order, 'brand' => $brand, 'lang' => $lang])
@else
{!! __('lang.subtotal', [], $lang) !!}: {!! $money($share['goods']) !!}
@if ($share['tax'] > 0)
{!! __('lang.vat', [], $lang) !!}: {!! $money($share['tax']) !!}
@endif
@if ($share['shipping'] > 0)
{!! __('lang.delivery_fee', [], $lang) !!}: {!! $money($share['shipping']) !!}
@endif
{!! __('lang.total', [], $lang) !!}: {!! $money($share['total']) !!}
(Your items on this order only. Discounts applied to the whole order are not included.)
@endif

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
