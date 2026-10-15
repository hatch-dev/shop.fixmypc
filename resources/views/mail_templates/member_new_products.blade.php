@extends('mail_templates.layout.base')

@php
    /**
     * Members' new-product email (MailHelper::newProductAlert).
     * One row per product: picture, name and price.
     */
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
    $count = count($data->products) + $data->more;
@endphp

@section('title', $count === 1 ? 'Just arrived' : $count . ' new products just arrived')

@section('preheader')
    New in the shop - as a {{ $data->plan }} member you hear about {{ $count === 1 ? 'it' : 'them' }} first.
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => 'New for members',
        'title'    => $count === 1 ? 'Just arrived' : 'Just arrived in the shop',
        'subtitle' => $count === 1
            ? 'A new product, and you are hearing about it first.'
            : $count . ' new products, and you are hearing about them first.',
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 18px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ $count === 1 ? 'This has' : 'These have' }} just gone on sale. As a <strong>{{ $data->plan }}</strong>
        member you get to know first, so you can get {{ $count === 1 ? 'it' : 'them' }} before {{ $count === 1 ? 'it sells' : 'they sell' }} out.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           class="bg-card hairline"
           style="background-color:{{ $brand->surface }}; border:1px solid {{ $brand->line }}; border-radius:14px; margin:0 0 22px 0;">
        @foreach ($data->products as $i => $product)
            <tr>
                <td class="cell-sm" style="padding:14px 18px; {{ $i > 0 ? 'border-top:1px solid ' . $brand->line . ';' : '' }}">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                            <td class="thumb" valign="top" width="64" style="width:64px; padding-right:14px;">
                                @if ($product->image)
                                    <a href="{{ $product->url }}" target="_blank">
                                        <img src="{{ $product->image }}" width="64" height="64" alt="{{ $product->title }}"
                                             style="display:block; width:64px; height:64px; border:1px solid {{ $brand->line }};
                                                    border-radius:10px; background-color:{{ $brand->canvas }};">
                                    </a>
                                @endif
                            </td>
                            <td valign="top" style="font-family:Arial,Helvetica,sans-serif;">
                                <a href="{{ $product->url }}" target="_blank" class="ink"
                                   style="font-size:15px; line-height:22px; font-weight:bold; color:{{ $brand->ink }}; text-decoration:none;">
                                    {{ $product->title }}
                                </a>
                            </td>
                            <td valign="top" align="right" style="font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:22px;
                                font-weight:bold; white-space:nowrap; color:{{ $brand->ink }}; padding-left:12px;">
                                {{ $product->price }}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endforeach
        @if ($data->more > 0)
            <tr>
                <td style="padding:12px 18px; border-top:1px solid {{ $brand->line }}; font-family:Arial,Helvetica,sans-serif;
                    font-size:14px; line-height:20px; color:{{ $brand->muted }};">
                    And {{ $data->more }} more in the shop.
                </td>
            </tr>
        @endif
    </table>

    @include('mail_templates.partials.button', [
        'brand' => $brand,
        'url'   => $data->products[0]->url ?? $data->shop_url,
        'label' => $count === 1 ? 'See it now' : 'Shop the new products',
    ])
@endsection

@section('footer_reason')
    <p class="faint" style="margin:10px 0 0 0; font-family:Arial,Helvetica,sans-serif;
       font-size:11.5px; line-height:18px; color:{{ $brand->faint }};">
        You get this as a {{ $data->plan }} member when new products arrive.
    </p>
    @if ($data->unsubscribe_url)
        <p style="margin:8px 0 0 0; font-family:Arial,Helvetica,sans-serif; font-size:11px; line-height:18px;">
            <a href="{{ $data->unsubscribe_url }}" target="_blank"
               style="color:{{ $brand->muted }}; text-decoration:underline;">Stop new-product emails</a>
        </p>
    @endif
@endsection
