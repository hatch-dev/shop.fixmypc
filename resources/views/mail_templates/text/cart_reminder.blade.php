@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

We are holding your items for {!! $data->minutes_left !!} more minutes.

The items in your basket are set aside for you, so nobody else can buy them. Complete your purchase within the next {!! $data->minutes_left !!} minutes - after that they are released and your basket is emptied.

@foreach ($data->lines as $line)
- {!! $line->title !!} x {!! $line->quantity !!}
@endforeach

Complete my purchase: {!! $data->basket_url !!}

Changed your mind? Nothing to do - the items are released automatically.

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
