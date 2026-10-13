@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp

@section('title', 'Your basket is reserved for ' . $data->minutes_left . ' more minutes')

@section('preheader')
    Complete your purchase within {{ $data->minutes_left }} minutes, or the items go back on sale.
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => 'Still thinking it over?',
        'title'    => 'We are holding your items',
        'subtitle' => 'Reserved for you for ' . $data->minutes_left . ' more minutes.',
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 18px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        The items in your basket are set aside for you, so nobody else can buy them.
        Complete your purchase within the next <strong>{{ $data->minutes_left }} minutes</strong>
        &mdash; after that they are released and your basket is emptied.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           class="bg-card hairline"
           style="background-color:{{ $brand->surface }}; border:1px solid {{ $brand->line }}; border-radius:14px; margin:0 0 22px 0;">
        @foreach ($data->lines as $i => $line)
            <tr>
                <td style="padding:14px 18px; {{ $i > 0 ? 'border-top:1px solid ' . $brand->line . ';' : '' }}
                    font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:22px; color:{{ $brand->ink }};">
                    {{ $line->title }}
                </td>
                <td align="right" style="padding:14px 18px; {{ $i > 0 ? 'border-top:1px solid ' . $brand->line . ';' : '' }}
                    font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:22px; white-space:nowrap; color:{{ $brand->muted }};">
                    &times; {{ $line->quantity }}
                </td>
            </tr>
        @endforeach
    </table>

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->basket_url,
        'label' => 'Complete my purchase',
    ])

    @include('mail_templates.partials.note', [
        'brand' => $brand,
        'icon'  => 'icon-shield.png',
        'text'  => 'Changed your mind? Nothing to do - the items are released automatically.',
    ])
@endsection
