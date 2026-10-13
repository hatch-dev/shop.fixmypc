@php
    /**
     * Shared tail for every plain-text part: store identity, postal address
     * and contact details, matching what the HTML footer shows.
     */
    $lang = isset($lang) ? $lang : null;
@endphp
--
{!! $brand->store_short !!}
@foreach ($brand->address_lines as $line)
{!! $line !!}
@endforeach
@if ($brand->phone)
{!! __('lang.phone', [], $lang) !!}: {!! $brand->phone !!}
@endif
@if ($brand->support_email)
{!! __('lang.email', [], $lang) !!}: {!! $brand->support_email !!}
@endif
{!! $brand->site_url !!}

{!! $brand->copyright !!}
