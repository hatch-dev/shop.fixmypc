@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);
    $orderUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/') . '/user/order/' . $order->id;
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_delivered_title', [], $lang) !!}

{!! __('lang.mail_delivered_sub', ['order' => $order->order], $lang) !!}

{!! __('lang.mail_review_prompt', [], $lang) !!}
{!! __('lang.write_review', [], $lang) !!}: {!! $orderUrl !!}

{!! __('lang.shipped_to', [], $lang) !!}:
{!! $order->formatted_address !!}

{!! __('lang.mail_items_ordered', [], $lang) !!}:
@include('mail_templates.text._order_lines', ['order' => $order, 'brand' => $brand, 'lang' => $lang])
@include('mail_templates.text._order_totals', ['order' => $order, 'brand' => $brand, 'lang' => $lang])

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
