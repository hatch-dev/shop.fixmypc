@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make(isset($setting->receiver) ? $setting->receiver : $data->receiver, $lang);
    $orderUrl = rtrim(config('env.url.CLIENT_BASE_URL') ?: $brand->site_url, '/') . '/user/order/' . $data->order_id;
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

{!! !empty($data->title) ? $data->title : __('lang.mail_status_title', ['status' => $data->status_label], $lang) !!}

{!! !empty($data->subtitle) ? $data->subtitle : __('lang.mail_status_sub', ['order' => $data->order], $lang) !!}

{!! __('lang.mail_order_number', [], $lang) !!}: #{!! $data->order !!}
{!! __('lang.status', [], $lang) !!}: {!! $data->status_label !!}
{!! __('lang.total', [], $lang) !!}: {!! $data->currency_icon !!}{!! number_format((float) $data->total_amount, 2) !!}
@foreach (isset($data->extra_rows) && is_array($data->extra_rows) ? $data->extra_rows : [] as $row)
{!! $row['label'] !!}: {!! $row['value'] !!}
@endforeach

@if(!empty($data->button_url))
{!! $data->button_label !!}: {!! $data->button_url !!}
@endif
@if(!empty($data->track_url))
{!! __('lang.mail_track_parcel', [], $lang) !!}: {!! $data->track_url !!}
@endif
{!! __('lang.view_order', [], $lang) !!}: {!! $orderUrl !!}

{!! __('lang.mail_quote_order', [], $lang) !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
