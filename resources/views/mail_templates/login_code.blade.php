@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_login_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_verification_code', [], $lang) }}: {{ $data->code }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-lock.png',
        'eyebrow'  => __('lang.mail_login_eyebrow', [], $lang),
        'title'    => __('lang.mail_login_title', [], $lang),
        'subtitle' => __('lang.mail_login_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_login_body', [], $lang) }}
    </p>

    @include('mail_templates.partials.code', [
        'brand'   => $brand,
        'code'    => $data->code,
        'minutes' => isset($data->expires_in_minutes) ? $data->expires_in_minutes : null,
        'lang'    => $lang,
    ])

    {{-- A sign-in code is the one thing worth warning about explicitly: code
         phishing works by getting the customer to read it out loud. --}}
    @include('mail_templates.partials.callout', [
        'brand' => $brand,
        'tone'  => 'warn',
        'icon'  => 'icon-warn.png',
        'text'  => __('lang.mail_login_warning', [], $lang),
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-shield.png',
        'text'  => __('lang.mail_ignore_login', [], $lang),
    ])
@endsection
