@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_reset_link_body', [], $lang) !!}

{!! __('lang.mail_set_new_password', [], $lang) !!}:
{!! $data->link !!}

{!! __('lang.mail_link_expiry', ['minutes' => $data->expires_in_minutes], $lang) !!}

{!! __('lang.mail_ignore_reset', [], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
