@php
    /**
     * Bordered card used for order details, addresses and any grouped block.
     * Used as a component so the body can be arbitrary markup:
     *
     *   @component('mail_templates.partials.panel', ['brand' => $brand, 'title' => 'Order details'])
     *       ...
     *   @endcomponent
     *
     * @var object      $brand
     * @var string|null $title
     * @var string|null $tone   plain|soft
     */
    $title = isset($title) ? $title : null;
    $tone = isset($tone) ? $tone : 'plain';
    $bg = $tone === 'soft' ? $brand->canvas : $brand->surface;
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       class="{{ $tone === 'soft' ? 'bg-soft' : 'bg-card' }} hairline"
       style="background-color:{{ $bg }}; border:1px solid {{ $brand->line }}; border-radius:14px;">
    @if ($title)
        <tr>
            <td class="px-sm hairline" style="padding:14px 20px; border-bottom:1px solid {{ $brand->line }};">
                <p class="ink" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:12px;
                   line-height:16px; letter-spacing:1.2px; text-transform:uppercase; font-weight:bold;
                   color:{{ $brand->ink }};">{{ $title }}</p>
            </td>
        </tr>
    @endif
    <tr>
        <td class="px-sm" style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif;
            font-size:15px; line-height:24px; color:{{ $brand->ink_soft }};">
            {{ $slot }}
        </td>
    </tr>
</table>
