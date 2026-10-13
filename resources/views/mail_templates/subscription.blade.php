@extends('mail_templates.layout.base')

@php
    use App\Models\Helper\MailBrand;

    $lang = isset($lang) ? $lang : null;

    $brand = isset($brand)
        ? $brand
        : MailBrand::make(isset($setting->receiver) ? $setting->receiver : null, $lang);

    $recipient = isset($recipient) ? $recipient : null;
    $unsubscribeUrl = isset($unsubscribeUrl) ? $unsubscribeUrl : null;
    $preheader = isset($preheader) ? $preheader : null;
@endphp

@section('title', isset($subject) ? $subject : $brand->store_short)

@section('preheader')
    {{ $preheader ?: trim(mb_substr(strip_tags($body), 0, 120)) }}
@endsection

@section('content')
    {{-- Admin-authored body from the newsletter editor. Wrapped in a cell that
         sets the font, colour and line height, because a WYSIWYG paste rarely
         brings its own and unstyled Times New Roman is what makes a campaign
         look like spam. --}}
    <div style="font-family:Arial,Helvetica,sans-serif; font-size:16px; line-height:26px;
                color:{{ $brand->ink_soft }};" class="ink-soft">
        {!! $body !!}
    </div>
@endsection

@section('footer_reason')
    {{-- Bulk mail: a visible unsubscribe link is required alongside the
         List-Unsubscribe header, and its absence is one of the fastest ways
         to collect spam complaints. --}}
    <p class="faint" style="margin:8px 0 0 0; font-family:Arial,Helvetica,sans-serif;
       font-size:11px; line-height:18px; color:{{ $brand->faint }};">
        {{ __('lang.mail_newsletter_reason', ['store' => $brand->store_short], $lang) }}
        @if ($recipient)
            <br>{{ __('lang.mail_sent_to', ['email' => $recipient], $lang) }}
        @endif
    </p>

    @if ($unsubscribeUrl)
        <p style="margin:8px 0 0 0; font-family:Arial,Helvetica,sans-serif; font-size:11px; line-height:18px;">
            <a href="{{ $unsubscribeUrl }}" target="_blank"
               style="color:{{ $brand->muted }}; text-decoration:underline;">{{ __('lang.mail_unsubscribe', [], $lang) }}</a>
        </p>
    @endif
@endsection
