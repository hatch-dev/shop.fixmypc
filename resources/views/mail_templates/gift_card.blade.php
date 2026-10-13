@extends('mail_templates.layout.base')

{{--
    Gift card delivery: one or more codes, to the person they are for.

    $data: receiver, sender_name (null when buyers send to themselves),
    message, design, codes[] (code, amount), expires, redeem_url, is_gift.
--}}

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
    $count = count($data->codes);
    $title = $data->is_gift
        ? ($data->sender_name ? $data->sender_name . ' sent you a gift card' : 'You have received a gift card')
        : ($count > 1 ? 'Your gift cards are ready' : 'Your gift card is ready');
@endphp

@section('title', $title)

@section('preheader')
    {{ $count > 1 ? $count . ' gift card codes' : 'A gift card code' }} for {{ $brand->store_name }}. Add it to your vouchers and use it on any order.
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => 'Gift card',
        'title'    => $title,
        'subtitle' => $data->design,
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        Hello{{ $brand->receiver_first ? ' ' . $brand->receiver_first : '' }},
    </p>

    @if($data->is_gift && $data->message)
        @include('mail_templates.partials.note', [
            'brand' => $brand,
            'icon'  => 'icon-mail.png',
            'text'  => '“' . $data->message . '”' . ($data->sender_name ? ' — ' . $data->sender_name : ''),
        ])
        @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 14])
    @endif

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ $count > 1 ? 'Each code below is worth its value once.' : 'Your code is below.' }}
        Sign in, open Vouchers and enter the code: it is added to your vouchers, ready to apply
        at checkout on any order. Whatever an order does not use stays on it for next time.
    </p>

    @component('mail_templates.partials.panel', ['brand' => $brand, 'title' => $count > 1 ? 'Your codes' : 'Your code'])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => array_map(function ($c) {
                return ['label' => '€' . number_format((float)$c->amount, 2), 'value' => e($c->code), 'strong' => true];
            }, $data->codes),
        ])
    @endcomponent

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->redeem_url,
        'label' => 'Add to my vouchers',
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-clock.png',
        'text'  => 'Valid until ' . $data->expires . '. Keep this email: ' . ($count > 1 ? 'the codes are' : 'the code is') . ' all anyone needs to redeem ' . ($count > 1 ? 'them.' : 'it.'),
    ])
@endsection
