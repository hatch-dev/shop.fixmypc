@php
    use App\Models\Helper\MailBrand;
    $lang = isset($lang) ? $lang : null;
    $brand = isset($brand) ? $brand : MailBrand::make($data->receiver, $lang);
@endphp
Hello{!! $brand->receiver_first ? ' ' . $brand->receiver_first : '' !!},

@if($data->is_gift)
{!! $data->sender_name ? $data->sender_name . ' sent you a gift card.' : 'You have received a gift card.' !!}
@if($data->message)

"{!! $data->message !!}"
@endif
@else
{!! count($data->codes) > 1 ? 'Your gift cards are ready.' : 'Your gift card is ready.' !!}
@endif

{!! $data->design !!}

@foreach($data->codes as $c)
{!! '€' . number_format((float)$c->amount, 2) !!}: {!! $c->code !!}
@endforeach

Sign in, open Vouchers and enter the code: it is added to your vouchers, ready to apply at checkout on any order. Whatever an order does not use stays on it for next time.

Add to my vouchers: {!! $data->redeem_url !!}

Valid until {!! $data->expires !!}. Keep this email: {!! count($data->codes) > 1 ? 'the codes are all anyone needs to redeem them.' : 'the code is all anyone needs to redeem it.' !!}

@include('mail_templates.text._footer', ['brand' => $brand, 'lang' => $lang])
