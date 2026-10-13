@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_trade_approved_title', [], $lang) !!}

{!! __('lang.mail_trade_approved_body', [], $lang) !!}

@if($data->company_name){!! __('lang.mail_trade_company', [], $lang) !!}: {!! $data->company_name !!}
@endif{!! __('lang.mail_trade_status', [], $lang) !!}: {!! __('lang.mail_trade_status_approved', [], $lang) !!}

{!! __('lang.mail_trade_approved_pricing', [], $lang) !!}

{!! __('lang.mail_trade_approved_cta', [], $lang) !!}: {!! $data->shop_url !!}
@if($data->reason)

{!! __('lang.mail_trade_note_prefix', [], $lang) !!} {!! $data->reason !!}
@endif

{!! __('lang.mail_trade_approved_account', ['url' => $data->account_url], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
