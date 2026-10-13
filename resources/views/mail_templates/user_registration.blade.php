@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_verify_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_verification_code', [], $lang) }}: {{ $data->code }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-verify.png',
        'eyebrow'  => __('lang.mail_welcome_eyebrow', [], $lang),
        'title'    => __('lang.mail_verify_title', [], $lang),
        'subtitle' => __('lang.mail_verify_sub', [], $lang),
    ])
@endsection

@section('content')
    <p style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};" class="ink">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_verify_body', [], $lang) }}
    </p>

    @include('mail_templates.partials.code', [
        'brand'   => $brand,
        'code'    => $data->code,
        'minutes' => isset($data->expires_in_minutes) ? $data->expires_in_minutes : null,
        'lang'    => $lang,
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-shield.png',
        'text'  => __('lang.mail_ignore_registration', [], $lang),
    ])
@endsection
