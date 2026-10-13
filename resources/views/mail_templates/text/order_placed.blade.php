@php
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\Utils;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);
    $placedOn = isset($order->created) && $order->created ? $order->created : Utils::formatDate($order->created_at);
    $orderUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/') . '/user/order/' . $order->id;
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_order_title', [], $lang) !!}

{!! __('lang.mail_order_sub', [], $lang) !!}

{!! __('lang.mail_order_number', [], $lang) !!}: #{!! $order->order !!}
{!! __('lang.mail_order_placed_on', [], $lang) !!}: {!! $placedOn !!}
@if ($order->order_method)
{!! __('lang.mail_payment_method', [], $lang) !!}: {!! $order->order_method !!}
@endif

@if (!empty($bankTransfer))
PAY BY BANK TRANSFER
Amount to pay: {!! $brand->currency_icon . number_format((float) $order->calculated_price['total_price'], 2) !!}
Payment reference: {!! $order->order !!}
@foreach (['account_name' => 'Account name', 'iban' => 'IBAN', 'bic_swift' => 'BIC / SWIFT', 'account_number' => 'Account number', 'bank_name' => 'Bank'] as $key => $label)
@if (!empty($bankTransfer[$key]))
{!! $label !!}: {!! $bankTransfer[$key] !!}
@endif
@endforeach
Use {!! $order->order !!} as the payment reference so we can match your transfer to this order. We will email you once it arrives. If it has not reached us within {!! $bankTransfer['hold_hours'] ?? 24 !!} hours of your order, the order is cancelled automatically.

@endif
{!! __('lang.mail_delivery_address', [], $lang) !!}:
{!! $order->formatted_address !!}

{!! __('lang.mail_items_ordered', [], $lang) !!}:
@include('mail_templates.text._order_lines', ['order' => $order, 'brand' => $brand, 'lang' => $lang])
@include('mail_templates.text._order_totals', ['order' => $order, 'brand' => $brand, 'lang' => $lang])

{!! __('lang.mail_view_order', [], $lang) !!}: {!! $orderUrl !!}
{!! __('lang.mail_track_order', [], $lang) !!}: {!! $brand->track_url !!}

{!! __('lang.mail_next_update', [], $lang) !!}
{!! __('lang.mail_quote_order', [], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
