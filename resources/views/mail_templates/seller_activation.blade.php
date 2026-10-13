@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', __('lang.mail_seller_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_seller_sub', [], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'tone'     => 'positive',
        'art'      => 'hero-store.png',
        'eyebrow'  => __('lang.mail_seller_eyebrow', [], $lang),
        'title'    => __('lang.mail_seller_title', [], $lang),
        'subtitle' => __('lang.mail_seller_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.ac_success', [], $lang) }}
    </p>

    @component('mail_templates.partials.panel', ['brand' => $brand, 'tone' => 'soft'])
        <p style="margin:0; font-size:15px; line-height:24px; color:{{ $brand->ink_soft }};" class="ink-soft">
            {{ __('lang.com', ['commission' => $data->commission], $lang) }}
        </p>
    @endcomponent

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 24])

    <p class="muted" style="margin:0 0 20px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.can_login', [], $lang) }}
    </p>

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->admin_url,
        'label' => __('lang.mail_open_dashboard', [], $lang),
    ])
@endsection
