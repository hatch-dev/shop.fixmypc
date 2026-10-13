@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\Utils;

    $lang = isset($lang) ? $lang : null;

    $brand = isset($brand)
        ? $brand
        : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);

    $orderUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/')
        . '/user/order/' . $order->id;

    $shipName = $order->address && $order->address->name
        ? $order->address->name
        : (isset($order->user->name) ? $order->user->name : $brand->receiver);

    $customerEmail = isset($order->user->email)
        ? $order->user->email
        : (isset($order->guest_user->email) ? $order->guest_user->email : null);
@endphp

@section('title', __('lang.mail_delivered_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_delivered_sub', ['order' => $order->order], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'tone'     => 'positive',
        'art'      => 'hero-truck.png',
        'eyebrow'  => __('lang.mail_delivered_eyebrow', [], $lang),
        'title'    => __('lang.mail_delivered_title', [], $lang),
        'subtitle' => __('lang.mail_delivered_sub', ['order' => $order->order], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 8px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="muted" style="margin:0 0 24px 0; font-size:16px; line-height:26px; color:{{ $brand->muted }};">
        {{ __('lang.mail_review_prompt', [], $lang) }}
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td align="center">
                @include('mail_templates.partials.button', [
                    'brand' => $brand,
                    'url'   => $orderUrl,
                    'label' => __('lang.write_review', [], $lang),
                    'align' => 'center',
                ])
            </td>
        </tr>
    </table>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 26])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td class="stack" width="50%" valign="top" style="padding:0 7px 0 0;">
                @component('mail_templates.partials.panel', [
                    'brand' => $brand,
                    'title' => __('lang.shipped_to', [], $lang),
                ])
                    <p class="ink" style="margin:0 0 4px 0; font-size:15px; line-height:23px;
                       font-weight:bold; color:{{ $brand->ink }};">{{ $shipName }}</p>
                    <p class="muted unstyle-auto-detected-links" style="margin:0; font-size:14px;
                       line-height:22px; color:{{ $brand->muted }};">{{ $order->formatted_address }}</p>
                    @if ($customerEmail)
                        <p class="muted" style="margin:6px 0 0 0; font-size:13px; line-height:20px;
                           color:{{ $brand->muted }};">{{ $customerEmail }}</p>
                    @endif
                @endcomponent
            </td>

            <td class="stack-gap" style="font-size:0; line-height:0; height:0;">&nbsp;</td>

            <td class="stack" width="50%" valign="top" style="padding:0 0 0 7px;">
                @component('mail_templates.partials.panel', [
                    'brand' => $brand,
                    'title' => __('lang.mail_order_details', [], $lang),
                ])
                    @include('mail_templates.partials.kv', [
                        'brand' => $brand,
                        'rows'  => array_values(array_filter([
                            [
                                'label'  => __('lang.mail_order_number', [], $lang),
                                'value'  => '#' . e($order->order),
                                'strong' => true,
                            ],
                            $order->order_method ? [
                                'label' => __('lang.paid_via', [], $lang),
                                'value' => e($order->order_method),
                            ] : null,
                        ])),
                    ])
                @endcomponent
            </td>
        </tr>
    </table>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.products', ['brand' => $brand, 'order' => $order, 'lang' => $lang])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 18])

    @include('mail_templates.partials.totals', ['brand' => $brand, 'order' => $order, 'lang' => $lang])
@endsection
