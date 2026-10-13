@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

{{-- Sent when an existing personal or business customer applies for trade
     from their account (App\Models\Helper\AccountUpgrade::applyForTrade).
     Nothing about the account changes until staff decide, and the email says
     what the customer can do in the meantime. --}}

@section('title', __('lang.mail_trade_received_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_trade_received_sub', [], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => __('lang.mail_trade_eyebrow', [], $lang),
        'title'    => __('lang.mail_trade_received_title', [], $lang),
        'subtitle' => __('lang.mail_trade_received_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_trade_received_body', [], $lang) }}
    </p>

    @component('mail_templates.partials.panel', ['brand' => $brand, 'title' => __('lang.mail_trade_panel', [], $lang)])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => array_values(array_filter([
                $data->company_name ? [
                    'label' => __('lang.mail_trade_company', [], $lang),
                    'value' => e($data->company_name),
                ] : null,
                $data->trade ? [
                    'label' => __('lang.mail_trade_trade', [], $lang),
                    'value' => e($data->trade),
                ] : null,
                [
                    'label' => __('lang.mail_trade_status', [], $lang),
                    'value' => e(__('lang.mail_trade_status_pending', [], $lang)),
                    'strong' => true,
                ],
            ])),
        ])
    @endcomponent

    @include('mail_templates.partials.callout', [
        'brand' => $brand,
        'tone'  => 'info',
        'icon'  => 'icon-shield.png',
        'text'  => __($data->from_business ? 'lang.mail_trade_received_business' : 'lang.mail_trade_received_personal', [], $lang),
    ])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->application_url,
        'label' => __('lang.mail_trade_received_cta', [], $lang),
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-headset.png',
        'text'  => __('lang.mail_trade_received_not_you', ['email' => $data->support_email], $lang),
    ])
@endsection
