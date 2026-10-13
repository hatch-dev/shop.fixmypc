@php
    /**
     * Bulletproof call-to-action button.
     *
     * Outlook draws through Word, which throws away padding on an anchor and
     * every border-radius. The VML rounded rectangle in the conditional
     * comment is what stops the button collapsing into a bare blue link
     * there; every other client sees the anchor below it.
     *
     * @var string $url
     * @var string $label
     * @var object $brand
     * @var string $variant  solid|outline
     * @var string $align
     */
    $variant = isset($variant) ? $variant : 'solid';
    $align = isset($align) ? $align : 'left';

    $bg = $variant === 'solid' ? $brand->primary : $brand->surface;
    $fg = $variant === 'solid' ? $brand->on_primary : $brand->primary;
    $border = $variant === 'solid' ? $brand->primary : $brand->primary_edge;

    // Word needs an explicit pixel width; approximate it from the label so a
    // short button does not stretch and a long one does not clip.
    $msoWidth = max(190, min(360, (int) (mb_strlen($label) * 9.5) + 68));
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0"
       class="btn" style="margin:0;" align="{{ $align }}">
    <tr>
        <td align="{{ $align }}">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
                         xmlns:w="urn:schemas-microsoft-com:office:word"
                         href="{{ $url }}"
                         style="height:50px; v-text-anchor:middle; width:{{ $msoWidth }}px;"
                         arcsize="50%" strokecolor="{{ $border }}" fillcolor="{{ $bg }}">
                <w:anchorlock/>
                <center style="color:{{ $fg }}; font-family:Arial,Helvetica,sans-serif; font-size:16px; font-weight:bold;">
                    {{ $label }}
                </center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="{{ $url }}" target="_blank" rel="noopener"
               style="display:inline-block; background-color:{{ $bg }}; color:{{ $fg }};
                      font-family:Arial,Helvetica,sans-serif; font-size:16px; font-weight:bold;
                      line-height:20px; text-decoration:none; text-align:center;
                      padding:15px 34px; border:1px solid {{ $border }}; border-radius:999px;
                      mso-hide:all;">{{ $label }}</a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
