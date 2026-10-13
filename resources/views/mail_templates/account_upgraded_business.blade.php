@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

{{-- Sent when a customer upgrades their own account from personal to business
     (App\Models\Helper\AccountUpgrade::upgradeToBusiness). It takes effect on
     the spot, so this is a confirmation - and, because the account type
     decides what the customer is charged, a "was this you?" as well. --}}

@section('title', __('lang.mail_upgrade_business_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_upgrade_business_sub', [], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => __('lang.mail_upgrade_business_eyebrow', [], $lang),
        'title'    => __('lang.mail_upgrade_business_title', [], $lang),
        'subtitle' => __('lang.mail_upgrade_business_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_upgrade_business_body', [], $lang) }}
    </p>

    @component('mail_templates.partials.panel', ['brand' => $brand, 'title' => __('lang.mail_upgrade_panel', [], $lang)])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => array_values(array_filter([
                $data->company_name ? [
                    'label' => __('lang.mail_trade_company', [], $lang),
                    'value' => e($data->company_name),
                ] : null,
                [
                    'label' => __('lang.mail_upgrade_type', [], $lang),
                    'value' => e(__('lang.mail_upgrade_type_business', [], $lang)),
                    'strong' => true,
                ],
            ])),
        ])
    @endcomponent

    @include('mail_templates.partials.callout', [
        'brand' => $brand,
        'tone'  => 'positive',
        'icon'  => 'icon-shield.png',
        'text'  => __('lang.mail_upgrade_business_pricing', [], $lang),
    ])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_upgrade_business_check', [], $lang) }}
    </p>

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->shop_url,
        'label' => __('lang.mail_upgrade_business_cta', [], $lang),
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-headset.png',
        'text'  => __('lang.mail_upgrade_not_you', ['email' => $data->support_email], $lang),
    ])
@endsection
