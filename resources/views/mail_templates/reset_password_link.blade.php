@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_reset_code_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_link_expiry', ['minutes' => $data->expires_in_minutes], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-lock.png',
        'eyebrow'  => __('lang.mail_reset_eyebrow', [], $lang),
        'title'    => __('lang.mail_reset_code_title', [], $lang),
        'subtitle' => __('lang.mail_reset_code_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 26px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_reset_link_body', [], $lang) }}
    </p>

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->link,
        'label' => __('lang.mail_set_new_password', [], $lang),
    ])

    <p class="muted" style="margin:22px 0 0 0; font-size:13px; line-height:21px; color:{{ $brand->muted }};">
        {{ __('lang.mail_link_expiry', ['minutes' => $data->expires_in_minutes], $lang) }}
    </p>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 20, 'rule' => true])

    {{-- A visible copy of the URL. Some corporate gateways rewrite or strip
         the anchor, and a reset email with no reachable link is a support
         ticket. --}}
    <p class="faint" style="margin:0 0 6px 0; font-size:12px; line-height:19px; color:{{ $brand->faint }};">
        {{ __('lang.mail_link_fallback', [], $lang) }}
    </p>
    <p style="margin:0; font-size:12px; line-height:19px; word-break:break-all;">
        <a href="{{ $data->link }}" style="color:{{ $brand->primary }}; text-decoration:underline;">{{ $data->link }}</a>
    </p>

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-shield.png',
        'text'  => __('lang.mail_ignore_reset', [], $lang),
    ])
@endsection
