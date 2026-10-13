@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_trade_approved_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_trade_approved_sub', [], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-verify.png',
        'eyebrow'  => __('lang.mail_trade_eyebrow', [], $lang),
        'title'    => __('lang.mail_trade_approved_title', [], $lang),
        'subtitle' => __('lang.mail_trade_approved_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_trade_approved_body', [], $lang) }}
    </p>

    {{-- The application as it stands on file: the company it was made for and
         the status it is now at. Nothing from the review itself beyond that. --}}
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
                    'value' => e(__('lang.mail_trade_status_approved', [], $lang)),
                    'strong' => true,
                ],
            ])),
        ])
    @endcomponent

    @include('mail_templates.partials.callout', [
        'brand' => $brand,
        'tone'  => 'positive',
        'icon'  => 'icon-shield.png',
        'text'  => __('lang.mail_trade_approved_pricing', [], $lang),
    ])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->shop_url,
        'label' => __('lang.mail_trade_approved_cta', [], $lang),
    ])

    {{-- A note from the reviewer, only when one was left. --}}
    @if($data->reason)
        @include('mail_templates.partials.note', [
            'brand' => $brand,
            'icon'  => 'icon-mail.png',
            'text'  => __('lang.mail_trade_note_prefix', [], $lang) . ' ' . $data->reason,
        ])
    @endif

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-headset.png',
        'text'  => __('lang.mail_trade_approved_account', ['url' => $data->account_url], $lang),
    ])
@endsection
