@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
Hello{{ $data->receiver ? ' ' . $data->receiver : '' }},

We have an order to source and would like your price on the items below.

Order {!! $data->order !!}
@foreach ($data->items as $item)
- {!! $item !!}
@endforeach

Send us your price here:
{!! $data->link !!}

The link opens a page where you can enter a price and condition for each item.
It stays open for {!! $data->expires_in_days !!} days.

{!! $brand->store_name !!}
