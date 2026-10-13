@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;
    use App\Models\Helper\Utils;

    $lang = isset($lang) ? $lang : null;

    // $setting is what the existing controllers pass; it already carries the
    // recipient name, so reuse it rather than re-querying for a name.
    $brand = isset($brand)
        ? $brand
        : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);

    $orderUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/')
        . '/user/order/' . $order->id;

    $placedOn = isset($order->created) && $order->created
        ? $order->created
        : Utils::formatDate($order->created_at);

    $shipName = $order->address && $order->address->name
        ? $order->address->name
        : (isset($order->user->name) ? $order->user->name : $brand->receiver);

    $customerEmail = isset($order->user->email) ? $order->user->email : null;
@endphp

@section('title', __('lang.mail_subject_order', ['order' => $order->order], $lang))

@section('preheader')
    {{ __('lang.mail_order_sub', [], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'tone'     => 'positive',
        'art'      => 'hero-order.png',
        'eyebrow'  => __('lang.mail_order_eyebrow', [], $lang),
        'title'    => __('lang.mail_order_title', [], $lang),
        'subtitle' => __('lang.mail_order_sub', [], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 24px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    {{-- Two panels side by side on desktop, stacked on phones. Plain table
         cells with a .stack class, because a flex row here is exactly what
         breaks the current template in Outlook. --}}
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
                                'label' => __('lang.mail_payment_method', [], $lang),
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
                    'title' => __('lang.mail_delivery_address', [], $lang),
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
        </tr>
    </table>

    @if (!empty($bankTransfer))
        @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

        @include('mail_templates.partials.bank_transfer', ['brand' => $brand, 'order' => $order, 'bank' => $bankTransfer])
    @endif

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22])

    @include('mail_templates.partials.products', ['brand' => $brand, 'order' => $order, 'lang' => $lang])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 18])

    @include('mail_templates.partials.totals', ['brand' => $brand, 'order' => $order, 'lang' => $lang])

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 28])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td align="center">
                @include('mail_templates.partials.button', [
                    'brand' => $brand,
                    'url'   => $orderUrl,
                    'label' => __('lang.mail_view_order', [], $lang),
                    'align' => 'center',
                ])
            </td>
        </tr>
        <tr>
            <td align="center" style="padding-top:14px;">
                <a href="{{ $brand->track_url }}" target="_blank"
                   style="font-family:Arial,Helvetica,sans-serif; font-size:14px; font-weight:bold;
                          color:{{ $brand->primary }}; text-decoration:none;">
                    {{ __('lang.mail_track_order', [], $lang) }} &rarr;
                </a>
            </td>
        </tr>
        <tr>
            <td align="center" style="padding-top:14px;">
                <p class="muted" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:14px;
                   line-height:22px; color:{{ $brand->muted }};">
                    {{ __('lang.mail_next_update', [], $lang) }}
                </p>
            </td>
        </tr>
    </table>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22, 'rule' => true])

    <p class="faint" style="margin:0; font-size:13px; line-height:21px; color:{{ $brand->faint }};">
        {{ __('lang.mail_quote_order', [], $lang) }}
    </p>
@endsection
