@php
    /**
     * One-time code, set as individual digit tiles.
     *
     * Each digit is its own table cell rather than one styled string, which
     * is the only way to get evenly spaced tiles in Outlook — letter-spacing
     * on a single run is applied inconsistently there and leaves a trailing
     * gap. The digits are still real text, so the code survives image
     * blocking and can be read out by a screen reader.
     *
     * The mock-up has a copy button beside the tiles; it is left out because
     * an email cannot run script, and a button that looks tappable and does
     * nothing is worse than no button.
     *
     * @var string      $code
     * @var object      $brand
     * @var int|null    $minutes
     */
    use App\Models\Helper\MailBrand;

    $lang = isset($lang) ? $lang : null;
    $minutes = isset($minutes) ? $minutes : null;
    $digits = preg_split('//u', trim((string)$code), -1, PREG_SPLIT_NO_EMPTY);
    $clock = MailBrand::emailAsset('icon-clock.png');
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       class="bg-soft" bgcolor="{{ $brand->primary_soft }}"
       style="background-color:{{ $brand->primary_soft }}; border-radius:16px;">
    <tr>
        <td align="center" class="px-sm" style="padding:24px 16px 22px 16px;">

            <p class="muted" style="margin:0 0 16px 0; font-family:Arial,Helvetica,sans-serif;
               font-size:12px; line-height:16px; letter-spacing:2px; text-transform:uppercase;
               font-weight:bold; color:{{ $brand->muted }};">
                {{ __('lang.mail_verification_code', [], $lang) }}
            </p>

            {{-- aria-label keeps the code readable as one number for assistive
                 tech, which would otherwise announce six separate cells. --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"
                   aria-label="{{ $code }}" style="margin:0 auto;">
                <tr>
                    @foreach ($digits as $i => $digit)
                        @if ($i > 0)
                            <td class="digit-gap" width="8" style="width:8px; font-size:0; line-height:0;">&nbsp;</td>
                        @endif
                        <td class="digit bg-card" align="center" valign="middle" width="52" height="62"
                            bgcolor="{{ $brand->surface }}"
                            style="width:52px; height:62px; background-color:{{ $brand->surface }};
                                   border:1px solid #dde7f6; border-radius:12px;
                                   font-family:Arial,Helvetica,sans-serif; font-size:28px; line-height:62px;
                                   font-weight:bold; color:{{ $brand->ink }};
                                   mso-line-height-rule:exactly;">{{ $digit }}</td>
                    @endforeach
                </tr>
            </table>

            @if ($minutes)
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"
                       style="margin:16px auto 0 auto;">
                    <tr>
                        @if ($clock)
                            <td valign="middle" width="22" style="width:22px; padding-right:7px;">
                                <img src="{{ $clock }}" width="14" height="14" alt=""
                                     style="display:block; width:14px; height:14px;">
                            </td>
                        @endif
                        <td valign="middle" class="muted" style="font-family:Arial,Helvetica,sans-serif;
                            font-size:13px; line-height:20px; color:{{ $brand->muted }};">
                            {{ __('lang.mail_code_expiry', ['minutes' => $minutes], $lang) }}
                        </td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
</table>
