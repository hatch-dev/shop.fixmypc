@php
    /**
     * Master email shell.
     *
     * Everything here is table-based on purpose. Outlook 2013-2021 renders
     * through Word, which ignores flexbox, grid, float, box-shadow,
     * border-radius and background-image — a div-and-flex layout collapses
     * into a single unstyled column there, and Outlook is still a large
     * share of B2B mail. The design degrades to square corners and no
     * shadow in Word, which is the intended fallback.
     *
     * Rules this file keeps to, all of them deliverability-relevant:
     *   - no <link> to a CDN or a webfont: remote CSS is stripped by Gmail
     *     and a request to a third-party host on open is a spam signal
     *   - every style that matters is inline; the <style> block only carries
     *     the responsive and dark-mode rules that inline styles cannot express
     *   - all images absolute PNGs, with alt text, width and height, so a
     *     blocked image still leaves a readable email (no inline SVG: Gmail
     *     strips it and Word cannot draw it)
     *   - a hidden preheader, so the inbox preview is a sentence we chose
     *   - valid doctype, charset and lang; malformed markup scores against you
     *
     * Sections a template can fill:
     *   preheader   - inbox preview line (plain text)
     *   hero        - illustration, eyebrow, headline and standfirst
     *   content     - the body, inside the white card
     *   after_card  - anything below the card and above the support strip
     */

    use App\Models\Helper\MailBrand;

    // Older call sites pass $setting; newer ones pass $brand. Accept either so
    // no template breaks mid-migration.
    if (!isset($brand)) {
        $brand = (isset($setting) && isset($setting->store_short))
            ? $setting
            : MailBrand::make(
                isset($setting->receiver) ? $setting->receiver : null,
                isset($lang) ? $lang : null
            );
    }

    $lang = isset($lang) ? $lang : null;

    $dir = in_array($lang, ['ar', 'he', 'fa', 'ur']) ? 'rtl' : 'ltr';
    $align = $dir === 'rtl' ? 'right' : 'left';
    $start = $dir === 'rtl' ? 'right' : 'left';
    $end = $dir === 'rtl' ? 'left' : 'right';

    // Soft blue page behind the card, rather than a flat grey.
    $canvas = '#eef3fb';

    $iconMail = MailBrand::emailAsset('icon-mail.png');
    $iconPhone = MailBrand::emailAsset('icon-phone.png');
    $iconHeadset = MailBrand::emailAsset('icon-headset.png');
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"
      xmlns:v="urn:schemas-microsoft-com:vml"
      xmlns:o="urn:schemas-microsoft-com:office:office"
      lang="{{ $lang ?: 'en' }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <title>@yield('title', $brand->store_short)</title>

    <!--[if mso]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <style type="text/css">
        table, td, div, p, a, h1, h2, h3 { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->

    {{--
        The stylesheet below is wrapped so Blade hands it through untouched.
        It is pure CSS with no template syntax in it, and Blade otherwise
        reads the CSS at-rules as unknown directives and rewrites their
        whitespace.

        (Careful: naming the wrapping directive inside this comment makes
        Blade start the raw block here and eat the rest of the head.)
    --}}
    @verbatim
    <style type="text/css">
        /* Client resets ------------------------------------------------ */
        html, body { margin: 0 !important; padding: 0 !important; height: 100% !important; width: 100% !important; }
        body { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        p { margin: 0; }

        /* Stop Apple Mail and Outlook.com auto-linking plain numbers and
           addresses into blue underlined junk. */
        a[x-apple-data-detectors],
        .unstyle-auto-detected-links a,
        .aBn { border-bottom: 0 !important; cursor: default !important; color: inherit !important;
               text-decoration: none !important; font-size: inherit !important; font-family: inherit !important;
               font-weight: inherit !important; line-height: inherit !important; }

        .im { color: inherit !important; }
        u + #body a { color: inherit; text-decoration: none; }
        #MessageViewBody a { color: inherit; text-decoration: none; }

        /* Mobile -------------------------------------------------------- */
        @media only screen and (max-width: 620px) {
            .wrap        { width: 100% !important; max-width: 100% !important; }
            .px          { padding-left: 20px !important; padding-right: 20px !important; }
            .px-sm       { padding-left: 16px !important; padding-right: 16px !important; }
            .py          { padding-top: 24px !important; padding-bottom: 24px !important; }

            /* Any cell marked .stack becomes a full-width row on phones. */
            .stack       { display: block !important; width: 100% !important; max-width: 100% !important;
                           padding-left: 0 !important; padding-right: 0 !important; }
            .stack-gap   { display: block !important; width: 100% !important;
                           height: 14px !important; line-height: 14px !important; font-size: 14px !important; }
            /* A nested table carries align="right", which survives a
               text-align change, so it has to be un-floated explicitly. */
            .stack-center       { text-align: center !important; padding-top: 14px !important; }
            .stack-center table { float: none !important; margin: 0 auto !important; }

            /* The vertical rule inside the support card becomes a horizontal
               one once its two halves are stacked. */
            .rule-v      { display: block !important; width: 100% !important; height: 1px !important;
                           border-left: 0 !important; border-top: 1px solid #e3ebf6 !important;
                           margin: 4px 0 !important; font-size: 0 !important; line-height: 0 !important; }

            .h1          { font-size: 25px !important; line-height: 32px !important; }
            .h2          { font-size: 19px !important; line-height: 26px !important; }
            .lead        { font-size: 15px !important; line-height: 24px !important; }
            .total       { font-size: 26px !important; }
            .hero-img    { width: 150px !important; height: 105px !important; }

            /* Code tiles shrink so six of them still fit a 320px screen. */
            .digit       { width: 40px !important; height: 50px !important;
                           font-size: 22px !important; line-height: 50px !important; }
            .digit-gap   { width: 6px !important; }

            .btn         { width: 100% !important; }
            .btn a       { display: block !important; width: auto !important; text-align: center !important; }
            .hide-sm     { display: none !important; max-height: 0 !important; overflow: hidden !important; }

            .cell-sm     { padding-left: 14px !important; padding-right: 14px !important; }
            .thumb       { width: 56px !important; }
            .thumb img   { width: 56px !important; height: 56px !important; }
        }

        /* Dark mode. Conservative on purpose: clients that force dark will
           invert what they like, so the job here is only to make sure
           nothing ends up dark-on-dark or light-on-light. */
        @media (prefers-color-scheme: dark) {
            .bg-canvas   { background-color: #0b1220 !important; }
            .bg-card     { background-color: #111a2b !important; }
            .bg-soft     { background-color: #16213a !important; }
            .ink         { color: #f1f5f9 !important; }
            .ink-soft    { color: #e2e8f0 !important; }
            .muted       { color: #94a3b8 !important; }
            .faint       { color: #7c8aa5 !important; }
            .hairline    { border-color: #24314c !important; }
            .card-border { border-color: #24314c !important; }
            .digit       { background-color: #1b2740 !important; border-color: #2b3a58 !important; color: #f1f5f9 !important; }
        }
        [data-ogsc] .bg-canvas   { background-color: #0b1220 !important; }
        [data-ogsc] .bg-card     { background-color: #111a2b !important; }
        [data-ogsc] .bg-soft     { background-color: #16213a !important; }
        [data-ogsc] .ink         { color: #f1f5f9 !important; }
        [data-ogsc] .ink-soft    { color: #e2e8f0 !important; }
        [data-ogsc] .muted       { color: #94a3b8 !important; }
        [data-ogsc] .faint       { color: #7c8aa5 !important; }
        [data-ogsc] .hairline    { border-color: #24314c !important; }
    </style>
    @endverbatim
</head>

<body id="body" class="bg-canvas"
      style="margin:0; padding:0; width:100%; background-color:{{ $canvas }};
             -webkit-font-smoothing:antialiased; word-break:break-word;">

{{-- Inbox preview line. Hidden in the body, read by the inbox list. --}}
<div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;
            mso-hide:all; font-family:Arial,sans-serif; color:transparent;">
    @yield('preheader', $brand->store_short)
    {{-- Padding characters stop Gmail from pulling body copy into the preview. --}}
    &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847;
    &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847;
    &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847; &#8199;&#65279;&#847;
</div>

<div role="article" aria-roledescription="email" aria-label="@yield('title', $brand->store_short)"
     lang="{{ $lang ?: 'en' }}">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="bg-canvas"
       bgcolor="{{ $canvas }}" style="background-color:{{ $canvas }};">
    <tr>
        <td align="center" style="padding:0;">

            <!--[if mso]>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" align="center"><tr><td>
            <![endif]-->

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="wrap"
                   style="width:600px; max-width:600px; margin:0 auto;">

                {{-- ============================ HEADER ============================ --}}
                <tr>
                    <td class="px" style="padding:26px 24px 18px 24px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                            <tr>
                                <td align="{{ $start }}" valign="middle">
                                    <a href="{{ $brand->site_url }}" target="_blank"
                                       style="text-decoration:none; color:{{ $brand->ink }};">
                                        @if ($brand->logo)
                                            <img src="{{ $brand->logo }}"
                                                 width="150" height="40"
                                                 alt="{{ $brand->store_short }}"
                                                 style="display:block; width:150px; max-width:150px; height:auto;
                                                        border:0; outline:none; text-decoration:none;">
                                        @else
                                            {{-- No logo on file: a two-line wordmark, which is also what a
                                                 recipient with images turned off effectively sees. --}}
                                            <span class="ink" style="display:inline-block;
                                                  font-family:Arial,Helvetica,sans-serif; font-size:23px;
                                                  line-height:26px; font-weight:bold; letter-spacing:-0.5px;
                                                  color:{{ $brand->ink }};">{{ $brand->mark_word }}</span>
                                            @if ($brand->mark_tail)
                                                <br>
                                                <span class="muted" style="display:inline-block;
                                                      font-family:Arial,Helvetica,sans-serif; font-size:15px;
                                                      line-height:19px; color:{{ $brand->muted }};">{{ $brand->mark_tail }}</span>
                                            @endif
                                        @endif
                                    </a>
                                </td>
                                <td align="{{ $end }}" valign="middle" class="hide-sm">
                                    <a href="{{ $brand->shop_url }}" target="_blank"
                                       style="font-family:Arial,Helvetica,sans-serif; font-size:14px;
                                              font-weight:bold; color:{{ $brand->primary }}; text-decoration:none;">
                                        {{ __('lang.mail_visit_store', [], $lang) }} &rarr;
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ============================= CARD ============================= --}}
                <tr>
                    <td style="padding:0 12px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                               class="bg-card card-border" bgcolor="{{ $brand->surface }}"
                               style="background-color:{{ $brand->surface }}; border-radius:22px;
                                      border:1px solid #e7eefa;">

                            @hasSection('hero')
                                <tr>
                                    <td>@yield('hero')</td>
                                </tr>
                            @endif

                            <tr>
                                <td class="px py" align="{{ $align }}"
                                    style="padding:6px 34px 34px 34px; font-family:Arial,Helvetica,sans-serif;
                                           font-size:16px; line-height:26px; color:{{ $brand->ink_soft }};">
                                    @yield('content')
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @hasSection('after_card')
                    <tr>
                        <td style="padding:14px 12px 0 12px;">@yield('after_card')</td>
                    </tr>
                @endif

                {{-- ========================= SUPPORT CARD ========================= --}}
                @if ($brand->support_email || $brand->phone || $brand->help_url)
                    <tr>
                        <td style="padding:14px 12px 0 12px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   class="bg-card card-border" bgcolor="{{ $brand->surface }}"
                                   style="background-color:{{ $brand->surface }}; border-radius:18px;
                                          border:1px solid #e7eefa;">
                                <tr>
                                    <td class="px-sm" style="padding:20px 22px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                {{-- headset + heading. No width on this cell: the contact
                                                     column and the button are both nowrap, so letting the
                                                     heading take whatever is left is what stops
                                                     "support@fixmypc.ie" breaking across two lines. --}}
                                                <td class="stack" valign="middle">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            @if ($iconHeadset)
                                                                <td valign="middle" width="46" style="width:46px; padding-{{ $end }}:12px;">
                                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                                                                           width="44" bgcolor="{{ $brand->primary_soft }}"
                                                                           style="width:44px; height:44px; border-radius:22px;
                                                                                  background-color:{{ $brand->primary_soft }};">
                                                                        <tr>
                                                                            <td align="center" valign="middle" height="44" style="height:44px;">
                                                                                <img src="{{ $iconHeadset }}" width="22" height="22"
                                                                                     alt="" style="display:block; width:22px; height:22px;">
                                                                            </td>
                                                                        </tr>
                                                                    </table>
                                                                </td>
                                                            @endif
                                                            <td valign="middle" style="font-family:Arial,Helvetica,sans-serif;">
                                                                <p class="ink" style="margin:0; font-size:15px; line-height:21px;
                                                                   font-weight:bold; color:{{ $brand->ink }};">
                                                                    {{ __('lang.mail_need_help', [], $lang) }}
                                                                </p>
                                                                <p class="muted" style="margin:2px 0 0 0; font-size:13px;
                                                                   line-height:19px; color:{{ $brand->muted }};">
                                                                    {{ __('lang.mail_support_line', [], $lang) }}
                                                                </p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>

                                                <td class="rule-v" width="1"
                                                    style="width:1px; border-{{ $start }}:1px solid #e3ebf6; font-size:0; line-height:0;">&nbsp;</td>

                                                {{-- contact details --}}
                                                <td class="stack" valign="middle" style="padding-{{ $start }}:18px; white-space:nowrap;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                        @if ($brand->support_email)
                                                            <tr>
                                                                @if ($iconMail)
                                                                    <td valign="middle" width="24" style="width:24px; padding-{{ $end }}:8px;">
                                                                        <img src="{{ $iconMail }}" width="16" height="16" alt=""
                                                                             style="display:block; width:16px; height:16px;">
                                                                    </td>
                                                                @endif
                                                                <td valign="middle" style="font-family:Arial,Helvetica,sans-serif;
                                                                    font-size:13.5px; line-height:24px; padding-bottom:2px; white-space:nowrap;">
                                                                    <a href="mailto:{{ $brand->support_email }}"
                                                                       style="color:{{ $brand->primary }}; text-decoration:none;
                                                                              font-weight:bold;">{{ $brand->support_email }}</a>
                                                                </td>
                                                            </tr>
                                                        @endif
                                                        @if ($brand->phone)
                                                            <tr>
                                                                @if ($iconPhone)
                                                                    <td valign="middle" width="24" style="width:24px; padding-{{ $end }}:8px;">
                                                                        <img src="{{ $iconPhone }}" width="16" height="16" alt=""
                                                                             style="display:block; width:16px; height:16px;">
                                                                    </td>
                                                                @endif
                                                                <td valign="middle" class="unstyle-auto-detected-links"
                                                                    style="font-family:Arial,Helvetica,sans-serif;
                                                                    font-size:13.5px; line-height:24px; white-space:nowrap;">
                                                                    <a href="{{ $brand->phone_href }}"
                                                                       style="color:{{ $brand->primary }}; text-decoration:none;
                                                                              font-weight:bold;">{{ $brand->phone }}</a>
                                                                </td>
                                                            </tr>
                                                        @endif
                                                    </table>
                                                </td>

                                                @if ($brand->help_url)
                                                    <td class="stack stack-center" valign="middle" align="{{ $end }}"
                                                        style="padding-{{ $start }}:14px;">
                                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                                                               align="{{ $end }}">
                                                            <tr>
                                                                <td bgcolor="{{ $brand->primary_soft }}" align="center"
                                                                    style="background-color:{{ $brand->primary_soft }};
                                                                           border-radius:10px; padding:11px 16px;">
                                                                    <a href="{{ $brand->help_url }}" target="_blank"
                                                                       style="font-family:Arial,Helvetica,sans-serif; font-size:13.5px;
                                                                              font-weight:bold; color:{{ $brand->primary }};
                                                                              text-decoration:none; white-space:nowrap;">{{ __('lang.mail_help_centre', [], $lang) }} &rarr;</a>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                @endif
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- ============================ FOOTER ============================ --}}
                <tr>
                    <td class="px" align="center" style="padding:26px 24px 40px 24px;">

                        @if (count($brand->socials))
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"
                                   style="margin:0 auto 22px auto;">
                                <tr>
                                    @foreach ($brand->socials as $social)
                                        <td align="center" style="padding:0 5px;">
                                            <a href="{{ $social->link }}" target="_blank" rel="noopener"
                                               style="text-decoration:none;">
                                                @if ($social->image)
                                                    <img src="{{ $social->image }}" width="32" height="32"
                                                         alt="{{ $social->title }}" title="{{ $social->title }}"
                                                         style="display:block; width:32px; height:32px; border:0;
                                                                border-radius:16px;">
                                                @else
                                                    {{-- Icon file missing: a named link still works, and still
                                                         reads as a footer rather than as damage. --}}
                                                    <span class="muted" style="display:inline-block;
                                                          font-family:Arial,Helvetica,sans-serif; font-size:12px;
                                                          line-height:18px; padding:5px 12px; border-radius:999px;
                                                          background-color:#ffffff; border:1px solid #e3ebf6;
                                                          color:{{ $brand->muted }};">{{ $social->title }}</span>
                                                @endif
                                            </a>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        @endif

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                            <tr>
                                <td style="padding:0 0 18px 0; font-size:0; line-height:0;">
                                    <div style="height:1px; line-height:1px; font-size:1px;
                                                background-color:#dfe8f5;">&nbsp;</div>
                                </td>
                            </tr>
                        </table>

                        {{-- Sender identity and a real postal address. Required by
                             CAN-SPAM, expected by GDPR, and read by filters as a
                             sign that a real business is behind the message. --}}
                        <p class="ink" style="margin:0 0 7px 0; font-family:Arial,Helvetica,sans-serif;
                           font-size:14px; line-height:21px; font-weight:bold; color:{{ $brand->ink }};">
                            {{ $brand->store_short }}
                        </p>

                        @if (count($brand->address_lines))
                            <p class="muted unstyle-auto-detected-links" style="margin:0 0 12px 0;
                               font-family:Arial,Helvetica,sans-serif; font-size:12.5px; line-height:20px;
                               color:{{ $brand->muted }};">
                                {{ join(' · ', $brand->address_lines) }}
                            </p>
                        @endif

                        @php
                            $legal = [];
                            if ($brand->privacy_url) { $legal[] = ['url' => $brand->privacy_url, 'label' => __('lang.mail_privacy', [], $lang)]; }
                            if ($brand->refund_url)  { $legal[] = ['url' => $brand->refund_url,  'label' => __('lang.mail_refunds', [], $lang)]; }
                            if ($brand->contact_url) { $legal[] = ['url' => $brand->contact_url, 'label' => __('lang.mail_contact', [], $lang)]; }
                        @endphp

                        @if (count($legal))
                            <p style="margin:0 0 12px 0; font-family:Arial,Helvetica,sans-serif;
                               font-size:12.5px; line-height:20px;">
                                @foreach ($legal as $i => $item)
                                    @if ($i > 0)
                                        <span class="faint" style="color:#c3cede;">&nbsp;&nbsp;|&nbsp;&nbsp;</span>
                                    @endif
                                    <a href="{{ $item['url'] }}" target="_blank"
                                       style="color:{{ $brand->primary }}; text-decoration:underline;">{{ $item['label'] }}</a>
                                @endforeach
                            </p>
                        @endif

                        @yield('footer_extra')

                        <p class="muted" style="margin:0; font-family:Arial,Helvetica,sans-serif;
                           font-size:12px; line-height:19px; color:{{ $brand->muted }};">
                            {{ $brand->copyright }}
                        </p>

                        @section('footer_reason')
                            <p class="faint" style="margin:10px 0 0 0; font-family:Arial,Helvetica,sans-serif;
                               font-size:11.5px; line-height:18px; color:{{ $brand->faint }};">
                                {{ __('lang.mail_transactional_note', [], $lang) }}
                            </p>
                        @show
                    </td>
                </tr>
            </table>

            <!--[if mso]>
            </td></tr></table>
            <![endif]-->

        </td>
    </tr>
</table>
</div>
</body>
</html>
