@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_guest_account_title', [], $lang) !!}

{!! __('lang.mail_guest_account_body', [], $lang) !!}

{!! __('lang.mail_guest_account_email', [], $lang) !!}: {!! $data->email !!}
{!! __('lang.mail_guest_account_password', [], $lang) !!}: {!! $data->password !!}

{!! __('lang.mail_guest_account_cta', [], $lang) !!}: {!! $data->sign_in_url !!}

{!! __('lang.mail_guest_account_warning', [], $lang) !!}

{!! __('lang.mail_guest_account_note', [], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
