@php
    /**
     * A filled, bordered box for something the reader must not miss — a
     * security warning, a caveat, a deadline.
     *
     * Its top gap is drawn as a spacer row rather than a margin on the
     * table. Word ignores margin on a table outright and several webmail
     * clients strip it while sanitising, which is what left the warning
     * sitting flush against the button above it.
     *
     * @var object      $brand
     * @var string      $text
     * @var string      $tone   warn|info|positive
     * @var string|null $icon   basename in public/images/email
     * @var int         $space  gap above, in px
     */
    use App\Models\Helper\MailBrand;

    $tone = isset($tone) ? $tone : 'warn';
    $space = isset($space) ? $space : 22;
    $icon = isset($icon) ? $icon : null;

    if ($tone === 'positive') {
        $bg = '#effaf4';
        $edge = '#c9ecda';
        $fg = '#166c48';
    } else if ($tone === 'info') {
        $bg = $brand->primary_soft;
        $edge = $brand->primary_edge;
        $fg = $brand->ink_soft;
    } else {
        $bg = '#fff8ec';
        $edge = '#f4e1bd';
        $fg = '#8a5a12';
    }

    $iconUrl = $icon ? MailBrand::emailAsset($icon) : null;
@endphp

@if ($space > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td style="font-size:0; line-height:0; height:{{ $space }}px;">
                <div style="height:{{ $space }}px; line-height:{{ $space }}px; font-size:1px;">&nbsp;</div>
            </td>
        </tr>
    </table>
@endif

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       bgcolor="{{ $bg }}"
       style="background-color:{{ $bg }}; border:1px solid {{ $edge }}; border-radius:12px;">
    <tr>
        @if ($iconUrl)
            <td valign="top" width="46" class="px-sm"
                style="width:46px; padding:15px 0 15px 16px;">
                <img src="{{ $iconUrl }}" width="20" height="20" alt=""
                     style="display:block; width:20px; height:20px;">
            </td>
        @endif
        <td valign="middle" class="px-sm"
            style="padding:15px 18px 15px {{ $iconUrl ? '10px' : '18px' }};
                   font-family:Arial,Helvetica,sans-serif; font-size:13.5px; line-height:21px;
                   color:{{ $fg }};">
            {{ $text }}
        </td>
    </tr>
</table>
