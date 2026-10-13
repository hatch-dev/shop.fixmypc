@php
    /**
     * A short reassurance or warning, set beside a round icon badge.
     *
     * Two table cells rather than an icon floated next to text: Word drops
     * float entirely and would stack the badge above the sentence.
     *
     * @var object $brand
     * @var string $text
     * @var string $tone   positive|warn|neutral
     * @var string|null $icon  basename in public/images/email
     * @var bool   $rule   draw a hairline above the note
     */
    use App\Models\Helper\MailBrand;

    $tone = isset($tone) ? $tone : 'positive';
    $rule = isset($rule) ? $rule : true;
    $icon = isset($icon) ? MailBrand::emailAsset($icon) : null;

    if ($tone === 'warn') {
        $badge = '#fff4e0';
        $textColor = '#8a5a12';
    } else if ($tone === 'neutral') {
        $badge = $brand->primary_soft;
        $textColor = $brand->muted;
    } else {
        $badge = '#dcf7e7';
        $textColor = $brand->muted;
    }
@endphp

@if ($rule)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td style="padding:24px 0 22px 0; font-size:0; line-height:0;">
                <div class="hairline" style="height:1px; line-height:1px; font-size:1px;
                     background-color:#e7eefa;">&nbsp;</div>
            </td>
        </tr>
    </table>
@endif

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        @if ($icon)
            <td valign="top" width="52" style="width:52px; padding-right:14px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="42"
                       bgcolor="{{ $badge }}"
                       style="width:42px; height:42px; border-radius:21px; background-color:{{ $badge }};">
                    <tr>
                        <td align="center" valign="middle" height="42" style="height:42px;">
                            <img src="{{ $icon }}" width="20" height="20" alt=""
                                 style="display:block; width:20px; height:20px;">
                        </td>
                    </tr>
                </table>
            </td>
        @endif
        <td valign="middle" style="font-family:Arial,Helvetica,sans-serif; font-size:14px;
            line-height:22px; color:{{ $textColor }};">
            {{ $text }}
        </td>
    </tr>
</table>
