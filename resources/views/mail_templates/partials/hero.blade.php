@php
    /**
     * Top of the card: illustration, eyebrow, headline and standfirst.
     *
     * The illustration is a PNG from public/images/email — Gmail strips
     * inline SVG and Word cannot draw it, and an emoji or an icon font is
     * not dependable across clients either. If the file is absent the block
     * simply starts at the eyebrow, so the email is never broken by a
     * missing asset.
     *
     * @var object      $brand
     * @var string      $title
     * @var string|null $eyebrow
     * @var string|null $subtitle
     * @var string|null $art       basename in public/images/email, e.g. 'hero-verify.png'
     */
    use App\Models\Helper\MailBrand;

    $eyebrow = isset($eyebrow) ? $eyebrow : null;
    $subtitle = isset($subtitle) ? $subtitle : null;
    $art = isset($art) ? MailBrand::emailAsset($art) : null;
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td class="px" align="center" style="padding:34px 34px 18px 34px;">

            @if ($art)
                <img src="{{ $art }}" class="hero-img" width="200" height="140"
                     alt="" role="presentation"
                     style="display:block; width:200px; height:140px; margin:0 auto 6px auto; border:0;">
            @endif

            @if ($eyebrow)
                <p style="margin:0 0 10px 0; font-family:Arial,Helvetica,sans-serif; font-size:12px;
                   line-height:16px; letter-spacing:2px; text-transform:uppercase; font-weight:bold;
                   color:{{ $brand->primary }};">
                    {{ $eyebrow }}
                </p>
            @endif

            <h1 class="h1 ink" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:31px;
                line-height:39px; font-weight:bold; letter-spacing:-0.5px; color:{{ $brand->ink }};
                mso-line-height-rule:exactly;">
                {{ $title }}
            </h1>

            @if ($subtitle)
                <p class="lead muted" style="margin:12px auto 0 auto; max-width:430px;
                   font-family:Arial,Helvetica,sans-serif; font-size:16px; line-height:26px;
                   color:{{ $brand->muted }};">
                    {{ $subtitle }}
                </p>
            @endif
        </td>
    </tr>
</table>
