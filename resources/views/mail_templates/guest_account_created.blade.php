@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_guest_account_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_guest_account_sub', [], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-lock.png',
        'eyebrow'  => __('lang.mail_guest_account_eyebrow', [], $lang),
        'title'    => __('lang.mail_guest_account_title', [], $lang),
        'subtitle' => __('lang.mail_guest_account_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_guest_account_body', [], $lang) }}
    </p>

    @component('mail_templates.partials.panel', ['brand' => $brand, 'title' => __('lang.mail_guest_account_details', [], $lang)])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => [
                ['label' => __('lang.mail_guest_account_email', [], $lang), 'value' => e($data->email)],
                ['label' => __('lang.mail_guest_account_password', [], $lang),
                 'value' => '<span style="font-family:Consolas,Menlo,monospace; font-size:16px; letter-spacing:1px;">' . e($data->password) . '</span>',
                 'strong' => true],
            ],
        ])
    @endcomponent

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->sign_in_url,
        'label' => __('lang.mail_guest_account_cta', [], $lang),
    ])

    {{-- This message is the only copy of the password: it is stored hashed the
         moment it is made, so nobody — including us — can read it back. --}}
    @include('mail_templates.partials.callout', [
        'brand' => $brand,
        'tone'  => 'warn',
        'icon'  => 'icon-warn.png',
        'text'  => __('lang.mail_guest_account_warning', [], $lang),
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-shield.png',
        'text'  => __('lang.mail_guest_account_note', [], $lang),
    ])
@endsection
