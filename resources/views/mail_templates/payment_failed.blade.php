@extends('mail_templates.layout.base')

@php
    /**
     * "We could not take payment for your order."
     *
     * Its own template rather than a status email, because payment failure is
     * not one of the eight order statuses and rendering it as one would put a
     * wrong "Status:" line in front of the customer.
     *
     * Note what this does not contain: no decline code, no gateway error text,
     * no card detail, not even the last four digits. None of it is actionable
     * by the customer -- their bank will not explain a decline to them via a
     * code in an email -- and all of it is detail a receipt-scraping filter or
     * a forwarded inbox should not be carrying. The internal reason is in the
     * log, where support can find it.
     */
    use App\Models\Helper\MailBrand;

    $lang = isset($lang) ? $lang : null;

    $brand = isset($brand)
        ? $brand
        : MailBrand::make(isset($setting->receiver) ? $setting->receiver : $data->receiver, $lang);

    $retryUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/')
        . '/user/order/' . $data->order_id;
@endphp

@section('title', __('lang.mail_payment_failed_title', [], $lang))

@section('preheader')
    {{ __('lang.mail_payment_failed_sub', ['order' => $data->order], $lang) }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'tone'     => 'neutral',
        'art'      => 'hero-truck.png',
        'eyebrow'  => __('lang.mail_payment_failed_eyebrow', [], $lang),
        'title'    => __('lang.mail_payment_failed_title', [], $lang),
        'subtitle' => __('lang.mail_payment_failed_sub', ['order' => $data->order], $lang),
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 24px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    <p class="ink" style="margin:0 0 24px 0; font-size:15px; line-height:25px; color:{{ $brand->ink }};">
        {{ __('lang.mail_payment_failed_body', [], $lang) }}
    </p>

    @component('mail_templates.partials.panel', [
        'brand' => $brand,
        'title' => __('lang.order_summary', [], $lang),
    ])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => [
                [
                    'label'  => __('lang.mail_order_number', [], $lang),
                    'value'  => '#' . e($data->order),
                    'strong' => true,
                ],
                [
                    'label'  => __('lang.total', [], $lang),
                    'value'  => e($data->currency_icon . number_format((float) $data->total_amount, 2)),
                    'strong' => true,
                ],
            ],
        ])
    @endcomponent

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 26])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td align="center">
                @include('mail_templates.partials.button', [
                    'brand' => $brand,
                    'url'   => $retryUrl,
                    'label' => __('lang.mail_payment_failed_cta', [], $lang),
                    'align' => 'center',
                ])
            </td>
        </tr>
    </table>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22, 'rule' => true])

    <p class="faint" style="margin:0; font-size:13px; line-height:21px; color:{{ $brand->faint }};">
        {{ __('lang.mail_payment_failed_note', [], $lang) }}
    </p>
@endsection
