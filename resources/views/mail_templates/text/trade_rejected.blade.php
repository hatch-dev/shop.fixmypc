@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_trade_rejected_title', [], $lang) !!}

{!! __('lang.mail_trade_rejected_body', [], $lang) !!}

@if($data->company_name){!! __('lang.mail_trade_company', [], $lang) !!}: {!! $data->company_name !!}
@endif{!! __('lang.mail_trade_status', [], $lang) !!}: {!! __('lang.mail_trade_status_rejected', [], $lang) !!}
@if($data->reason)

{!! __('lang.mail_trade_reason_prefix', [], $lang) !!} {!! $data->reason !!}
@endif

{!! __(!empty($data->still_business) ? 'lang.mail_trade_rejected_business' : 'lang.mail_trade_rejected_retail', [], $lang) !!}

{!! __('lang.mail_trade_rejected_cta', [], $lang) !!}: {!! $data->shop_url !!}

{!! __('lang.mail_trade_rejected_support', ['email' => $data->support_email], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
