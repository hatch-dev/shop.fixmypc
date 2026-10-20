@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;

    $lang = isset($lang) ? $lang : null;

    $brand = isset($brand)
        ? $brand
        : MailBrand::make(isset($setting->receiver) ? $setting->receiver : $data->receiver, $lang);

    $orderUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/')
        . '/user/order/' . $data->order_id;

    // Cancelled / returned / refunded should not arrive dressed up in the
    // same celebratory green as "on the way".
    $label = mb_strtolower((string) $data->status_label);
    $negative = false;
    foreach (['cancel', 'return', 'refund', 'fail'] as $word) {
        if (mb_strpos($label, $word) !== false) { $negative = true; }
    }
    $tone = $negative ? 'neutral' : 'accent';

    // "Tracking added" names its own heading; every other event reads the status.
    $title = !empty($data->title)
        ? $data->title
        : __('lang.mail_status_title', ['status' => $data->status_label], $lang);
    $subtitle = !empty($data->subtitle)
        ? $data->subtitle
        : __('lang.mail_status_sub', ['order' => $data->order], $lang);

    // Set on "on the way" / "tracking added" when the order has a shipment.
    $trackUrl = !empty($data->track_url) ? $data->track_url : null;

    /*
     * Rows the event adds to the panel -- the amount and reference on a
     * refund. Optional, because MailHelper::orderStatusChanged() still renders
     * this template and does not set them.
     *
     * Escaped here rather than at the call site: the kv partial prints values
     * raw so that callers can pass markup, and a refund reference comes from a
     * payment provider, which is not somewhere to take HTML from on trust.
     */
    $extraRows = [];

    foreach (isset($data->extra_rows) && is_array($data->extra_rows) ? $data->extra_rows : [] as $row) {
        $extraRows[] = [
            'label' => isset($row['label']) ? $row['label'] : '',
            'value' => e(isset($row['value']) ? $row['value'] : ''),
            'strong' => !empty($row['strong']),
        ];
    }
@endphp

@section('title', $title)

@section('preheader')
    {{ $subtitle }}
@endsection

@section('hero')
    @include('mail_templates.partials.hero', [
        'brand'    => $brand,
        'tone'     => $tone,
        'art'      => 'hero-truck.png',
        'eyebrow'  => __('lang.mail_status_eyebrow', [], $lang),
        'title'    => $title,
        'subtitle' => $subtitle,
    ])
@endsection

@section('content')
    <p class="ink" style="margin:0 0 24px 0; font-size:16px; line-height:26px; font-weight:bold; color:{{ $brand->ink }};">
        {{ __('lang.hello', [], $lang) }}@if($brand->receiver_first) {{ $brand->receiver_first }}@endif,
    </p>

    @component('mail_templates.partials.panel', [
        'brand' => $brand,
        'title' => __('lang.order_summary', [], $lang),
    ])
        @include('mail_templates.partials.kv', [
            'brand' => $brand,
            'rows'  => array_merge([
                [
                    'label'  => __('lang.mail_order_number', [], $lang),
                    'value'  => '#' . e($data->order),
                    'strong' => true,
                ],
                [
                    'label'  => __('lang.status', [], $lang),
                    'value'  => e($data->status_label),
                    'strong' => true,
                ],
                [
                    'label'  => __('lang.total', [], $lang),
                    'value'  => e($data->currency_icon . number_format((float) $data->total_amount, 2)),
                    'strong' => true,
                ],
            ], $extraRows),
        ])
    @endcomponent

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 26])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td align="center">
                @if(!empty($data->button_url))
                    {{-- An event with its own call to action: the exchange pay link. --}}
                    @include('mail_templates.partials.button', [
                        'brand' => $brand,
                        'url'   => $data->button_url,
                        'label' => $data->button_label,
                        'align' => 'center',
                    ])
                @elseif($trackUrl)
                    @include('mail_templates.partials.button', [
                        'brand' => $brand,
                        'url'   => $trackUrl,
                        'label' => __('lang.mail_track_parcel', [], $lang),
                        'align' => 'center',
                    ])
                @else
                    @include('mail_templates.partials.button', [
                        'brand' => $brand,
                        'url'   => $orderUrl,
                        'label' => __('lang.view_order', [], $lang),
                        'align' => 'center',
                    ])
                @endif
            </td>
        </tr>
    </table>

    @include('mail_templates.partials.spacer', ['brand' => $brand, 'h' => 22, 'rule' => true])

    <p class="faint" style="margin:0; font-size:13px; line-height:21px; color:{{ $brand->faint }};">
        {{ __('lang.mail_quote_order', [], $lang) }}
    </p>
@endsection
