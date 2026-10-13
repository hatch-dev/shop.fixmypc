@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! __('lang.mail_upgrade_business_title', [], $lang) !!}

{!! __('lang.mail_upgrade_business_body', [], $lang) !!}

@if($data->company_name){!! __('lang.mail_trade_company', [], $lang) !!}: {!! $data->company_name !!}
@endif{!! __('lang.mail_upgrade_type', [], $lang) !!}: {!! __('lang.mail_upgrade_type_business', [], $lang) !!}

{!! __('lang.mail_upgrade_business_pricing', [], $lang) !!}

{!! __('lang.mail_upgrade_business_check', [], $lang) !!}

{!! __('lang.mail_upgrade_business_cta', [], $lang) !!}: {!! $data->shop_url !!}

{!! __('lang.mail_upgrade_not_you', ['email' => $data->support_email], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
