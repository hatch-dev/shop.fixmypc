@php
    /**
     * Vertical space. Margins collapse unpredictably across clients and Word
     * drops them on block elements, so gaps are drawn as an empty row.
     *
     * @var int  $h
     * @var bool $rule  draw a hairline instead of empty space
     */
    $h = isset($h) ? $h : 20;
    $rule = isset($rule) ? $rule : false;
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td style="font-size:0; line-height:0; height:{{ $h }}px;">
            @if ($rule)
                <div style="height:{{ $h }}px; line-height:{{ $h }}px; font-size:1px;">&nbsp;</div>
                <div class="hairline" style="height:1px; line-height:1px; font-size:1px;
                     background-color:{{ $brand->line }};">&nbsp;</div>
                <div style="height:{{ $h }}px; line-height:{{ $h }}px; font-size:1px;">&nbsp;</div>
            @else
                <div style="height:{{ $h }}px; line-height:{{ $h }}px; font-size:1px;">&nbsp;</div>
            @endif
        </td>
    </tr>
</table>
