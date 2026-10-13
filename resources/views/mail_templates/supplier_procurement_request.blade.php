@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, isset($lang) ? $lang : null);
    $lang = isset($lang) ? $lang : null;
@endphp

@section('title', 'New fulfilment request')

@section('preheader')
    Order {{ $data->order }} — {{ count($data->items) }} item(s) to price.
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-truck.png',
        'eyebrow'  => 'Procurement',
        'title'    => 'New fulfilment request',
        'subtitle' => 'Order ' . $data->order . ' — ' . count($data->items) . ' item(s) to price.',
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        Hello{{ $data->receiver ? ' ' . $data->receiver : '' }},
    </p>

    <p class="muted" style="margin:0 0 22px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        We have an order to source and would like your price on the items below.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="margin:0 0 22px 0; border-collapse:collapse;">
        <tr>
            <td style="padding:0 0 10px 0; font-size:12px; line-height:18px; letter-spacing:.4px;
                       text-transform:uppercase; color:{{ $brand->muted }};">
                Order {{ $data->order }}
            </td>
        </tr>
        @foreach ($data->items as $item)
            <tr>
                <td style="padding:10px 0; border-top:1px solid {{ $brand->line }};
                           font-size:15px; line-height:22px; color:{{ $brand->ink }};">
                    {{ $item }}
                </td>
            </tr>
        @endforeach
    </table>

    {{--
        The button partial sets align="left" on its table, which in email is a
        float -- anything after it wraps up alongside the button instead of
        sitting under it. Giving it a cell of its own contains that, the way
        the order templates do.
    --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="border-collapse:collapse;">
        <tr>
            <td>
                @include('mail_templates.partials.button', [
                    'brand' => $brand,
                    'url'   => $data->link,
                    'label' => 'Send us your price',
                ])
            </td>
        </tr>
    </table>

    <p class="muted" style="margin:22px 0 0 0; font-size:14px; line-height:22px; color:{{ $brand->muted }};">
        The link opens a page where you can enter a price and condition for each item.
        It stays open for {{ $data->expires_in_days }} days.
    </p>

    <p class="muted" style="margin:14px 0 0 0; font-size:13px; line-height:20px; word-break:break-all; color:{{ $brand->muted }};">
        If the button does not work, paste this into your browser:<br>
        {{ $data->link }}
    </p>
@endsection
