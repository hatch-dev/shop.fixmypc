@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\MailHelper;
    use App\Models\Helper\Utils;

    $lang = isset($lang) ? $lang : null;

    $brand = isset($brand)
        ? $brand
        : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);

    $placedOn = isset($order->created) && $order->created
        ? $order->created
        : Utils::formatDate($order->created_at);

    /*
     * What the vendor's lines were charged, worked out by OrderMailer from
     * the order's own rows: null when every line is theirs (the totals are
     * then the customer's), else goods / tax / shipping / total for their
     * share. Never added up here - see OrderMailer::vendorCopies.
     */
    $share = isset($order->vendor_totals) ? $order->vendor_totals : null;

    $customerName = $order->address && $order->address->name
        ? $order->address->name
        : (isset($order->user->name) ? $order->user->name : null);

    $customerEmail = isset($order->user->email)
        ? $order->user->email
        : (isset($order->guest_user->email) ? $order->guest_user->email : null);

    $customerPhone = isset($order->user->phone) ? $order->user->phone : null;

    $adminUrl = rtrim(config('env.url.APP_URL') ?: $brand->site_url, '/');
    if (config('env.url.APP_URL') === config('env.url.CLIENT_BASE_URL')) {
        $adminUrl .= '/admin';
    }
    $adminUrl .= '/orders';

    $money = function ($v) use ($brand) {
        return $brand->currency_icon . number_format((float) $v, 2);
    };
@endphp

@section('title', __('lang.mail_subject_vendor_order', ['order' => $order->order], $lang))

@section('preheader')
    {{ __('lang.mail_vendor_sub', ['order' => $order->order], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'art'      => 'hero-store.png',
        'eyebrow'  => __('lang.mail_vendor_eyebrow', [], $lang),
        'title'    => __('lang.mail_vendor_title', [], $lang),
        'subtitle' => __('lang.mail_vendor_sub', ['order' => $order->order], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 24px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td class="stack" width="50%" valign="top" style="padding:0 7px 0 0;">
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
                            [
                                'label' => __('lang.mail_order_placed_on', [], $lang),
                                'value' => e($placedOn),
                            ],
                            $order->order_method ? [
                                'label' => __('lang.order_method', [], $lang),
                                'value' => e($order->order_method),
                            ] : null,
                        ])),
                    ])
                @endcomponent
            </td>

            <td class="stack-gap" style="font-size:0; line-height:0; height:0;">&nbsp;</td>

            <td class="stack" width="50%" valign="top" style="padding:0 0 0 7px;">
                @component('mail_templates.partials.panel', [
                    'brand' => $brand,
                    'title' => __('lang.ship_to', [], $lang),
                ])
                    @if ($customerName)
                        <p class="ink" style="margin:0 0 4px 0; font-size:15px; line-height:23px;
                           font-weight:bold; color:{{ $brand->ink }};">{{ $customerName }}</p>
                    @endif
                    <p class="muted unstyle-auto-detected-links" style="margin:0; font-size:14px;
                       line-height:22px; color:{{ $brand->muted }};">{{ $order->formatted_address }}</p>
                    @if ($customerEmail)
                        <p class="muted" style="margin:6px 0 0 0; font-size:13px; line-height:20px;
                           color:{{ $brand->muted }};">{{ $customerEmail }}</p>
                    @endif
                    @if ($customerPhone)
                        <p class="muted unstyle-auto-detected-links" style="margin:2px 0 0 0; font-size:13px;
                           line-height:20px; color:{{ $brand->muted }};">{{ $customerPhone }}</p>
                    @endif
                @endcomponent
            </td>
        </tr>
    </table>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.products', ['brand' => $brand, 'order' => $order, 'lang' => $lang])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 18])

    @if (is_null($share))
        @include('mail_templates.partials.totals', ['brand' => $brand, 'order' => $order, 'lang' => $lang])
    @else
        @component('mail_templates.partials.panel', ['brand' => $brand, 'tone' => 'soft'])
            @include('mail_templates.partials.kv', [
                'brand' => $brand,
                'rows'  => array_values(array_filter([
                    [
                        'label' => __('lang.subtotal', [], $lang),
                        'value' => e($money($share['goods'])),
                    ],
                    $share['tax'] > 0 ? [
                        'label' => __('lang.vat', [], $lang),
                        'value' => e($money($share['tax'])),
                    ] : null,
                    $share['shipping'] > 0 ? [
                        'label' => __('lang.delivery_fee', [], $lang),
                        'value' => e($money($share['shipping'])),
                    ] : null,
                    [
                        'label'  => __('lang.total', [], $lang),
                        'value'  => e($money($share['total'])),
                        'strong' => true,
                    ],
                ])),
            ])
            <p class="muted" style="margin:10px 0 0 0; font-size:12px; line-height:18px; color:{{ $brand->muted }};">
                Your items on this order only. Discounts applied to the whole order are not included.
            </p>
        @endcomponent
    @endif

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 26])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td align="center">
                @include('mail_templates.partials.button', [
                    'brand' => $brand,
                    'url'   => $adminUrl,
                    'label' => __('lang.mail_fulfil_order', [], $lang),
                    'align' => 'center',
                ])
            </td>
        </tr>
    </table>
@endsection

@section('footer_reason')
    <p class="faint" style="margin:8px 0 0 0; font-family:Arial,Helvetica,sans-serif;
       font-size:11px; line-height:18px; color:{{ $brand->faint }};">
        {{ __('lang.mail_transactional_note', [], $lang) }}
    </p>
@endsection
