@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_verify_title', [], $lang) !!}

{!! __('lang.mail_verify_body', [], $lang) !!}

{!! __('lang.mail_verification_code', [], $lang) !!}: {!! $data->code !!}
@if (isset($data->expires_in_minutes) && $data->expires_in_minutes)

{!! __('lang.mail_code_expiry', ['minutes' => $data->expires_in_minutes], $lang) !!}
@endif

{!! __('lang.mail_ignore_registration', [], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
