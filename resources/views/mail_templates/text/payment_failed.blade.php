@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(isset($setting->receiver) ? $setting->receiver : $data->receiver, $lang);
    $retryUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/') . '/user/order/' . $data->order_id;
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_payment_failed_title', [], $lang) !!}

{!! __('lang.mail_payment_failed_body', [], $lang) !!}

{!! __('lang.mail_order_number', [], $lang) !!}: #{!! $data->order !!}
{!! __('lang.total', [], $lang) !!}: {!! $data->currency_icon !!}{!! number_format((float) $data->total_amount, 2) !!}

{!! __('lang.mail_payment_failed_cta', [], $lang) !!}: {!! $retryUrl !!}

{!! __('lang.mail_payment_failed_note', [], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
