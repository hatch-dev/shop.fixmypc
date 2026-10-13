@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_trade_rejected_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_trade_rejected_sub', [], $lang) }}
@endsection

@section('hero')
    {{-- hero-store, not hero-alert: this is a decision about a trade account,
         not a security or delivery problem, and the alert art overstates it. --}}
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => __('lang.mail_trade_eyebrow', [], $lang),
        'title'    => __('lang.mail_trade_rejected_title', [], $lang),
        'subtitle' => __('lang.mail_trade_rejected_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_trade_rejected_body', [], $lang) }}
    </p>

    @component('mail_templates.partials.panel', ['brand' => $brand, 'title' => __('lang.mail_trade_panel', [], $lang)])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => array_values(array_filter([
                $data->company_name ? [
                    'label' => __('lang.mail_trade_company', [], $lang),
                    'value' => e($data->company_name),
                ] : null,
                [
                    'label' => __('lang.mail_trade_status', [], $lang),
                    'value' => e(__('lang.mail_trade_status_rejected', [], $lang)),
                    'strong' => true,
                ],
            ])),
        ])
    @endcomponent

    {{-- The reviewer's note, shown only when one was left. It is the same text
         the customer already sees on their dashboard; nothing internal to the
         review is included here. --}}
    @if($data->reason)
        @include('mail_templates.partials.callout', [
            'brand' => $brand,
            'tone'  => 'info',
            'icon'  => 'icon-mail.png',
            'text'  => __('lang.mail_trade_reason_prefix', [], $lang) . ' ' . $data->reason,
        ])
    @endif

    {{-- What the account still does, which is the most useful thing this
         email can say: retail prices, or - for a business customer whose
         trade upgrade was turned down - business pricing, unchanged. --}}
    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __(!empty($data->still_business) ? 'lang.mail_trade_rejected_business' : 'lang.mail_trade_rejected_retail', [], $lang) }}
    </p>

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->shop_url,
        'label' => __('lang.mail_trade_rejected_cta', [], $lang),
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-headset.png',
        'text'  => __('lang.mail_trade_rejected_support', ['email' => $data->support_email], $lang),
    ])
@endsection
