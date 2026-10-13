@php
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\MailHelper;
    use App\Models\Helper\Utils;

    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);
    $placedOn = isset($order->created) && $order->created ? $order->created : Utils::formatDate($order->created_at);

    $vendorGoods = 0; $vendorShipping = 0;
    foreach ($order->ordered_products as $op) {
        $vendorGoods += (float) $op->selling * (int) $op->quantity;
        if ($op->shipping_place) {
            $vendorShipping += (float) MailHelper::shippingPrice($op->shipping_place, $op->shipping_type);
        }
    }
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
{!! __('lang.subtotal', [], $lang) !!}: {!! $brand->currency_icon !!}{!! number_format($vendorGoods, 2) !!}
@if ($vendorShipping > 0)
{!! __('lang.delivery_fee', [], $lang) !!}: {!! $brand->currency_icon !!}{!! number_format($vendorShipping, 2) !!}
@endif
{!! __('lang.total', [], $lang) !!}: {!! $brand->currency_icon !!}{!! number_format($vendorGoods + $vendorShipping, 2) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
