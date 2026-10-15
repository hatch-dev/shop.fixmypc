@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
{!! __('lang.hello', [], $lang) !!}@if($brand->receiver_first) {!! $brand->receiver_first !!}@endif,

Just arrived in the shop - as a {!! $data->plan !!} member you are hearing about it first.

@foreach ($data->products as $product)
- {!! $product->title !!}@if($product->price) - {!! $product->price !!}@endif

  {!! $product->url !!}
@endforeach
@if ($data->more > 0)
And {!! $data->more !!} more in the shop: {!! $data->shop_url !!}
@endif

You get this as a {!! $data->plan !!} member when new products arrive.
@if ($data->unsubscribe_url)
Stop new-product emails: {!! $data->unsubscribe_url !!}
@endif

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
