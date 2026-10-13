@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_seller_title', [], $lang) !!}

{!! __('lang.ac_success', [], $lang) !!}

{!! __('lang.com', ['commission' => $data->commission], $lang) !!}

{!! __('lang.can_login', [], $lang) !!}
{!! $data->admin_url !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
