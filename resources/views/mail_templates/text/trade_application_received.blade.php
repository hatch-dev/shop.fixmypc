@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_trade_received_title', [], $lang) !!}

{!! __('lang.mail_trade_received_body', [], $lang) !!}

@if($data->company_name){!! __('lang.mail_trade_company', [], $lang) !!}: {!! $data->company_name !!}
@endif
@if($data->trade){!! __('lang.mail_trade_trade', [], $lang) !!}: {!! $data->trade !!}
@endif{!! __('lang.mail_trade_status', [], $lang) !!}: {!! __('lang.mail_trade_status_pending', [], $lang) !!}

{!! __($data->from_business ? 'lang.mail_trade_received_business' : 'lang.mail_trade_received_personal', [], $lang) !!}

{!! __('lang.mail_trade_received_cta', [], $lang) !!}: {!! $data->application_url !!}

{!! __('lang.mail_trade_received_not_you', ['email' => $data->support_email], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
