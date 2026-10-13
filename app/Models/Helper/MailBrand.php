<?php

namespace App\Models\Helper;

use App\Models\FooterImageLink;
use App\Models\FooterLink;
use App\Models\Setting;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Config;

/**
 * Single source of truth for everything an email template needs to know about
 * the store: identity, contact details, colours, social profiles and the
 * storefront links.
 *
 * Every value comes out of the database (settings / site_settings / pages /
 * footer_image_links), so a template never hard-codes a store name, an address
 * or a brand colour. Nothing here is FixMyPC-specific — point the install at a
 * different database and the emails re-brand themselves.
 *
 * Built once per request and memoised, because a single order email otherwise
 * re-queries the same six tables for its header, its body and its footer.
 */
class MailBrand
{
    /** @var object|null */
    protected static $cache = null;

    /**
     * Neutral palette shared by every template. Only the accent is dynamic —
     * these greys are chosen to stay legible against both the light and the
     * dark rendering that mail clients apply on their own.
     */
    const INK = '#0f172a';
    const INK_SOFT = '#1e293b';
    const MUTED = '#64748b';
    const FAINT = '#94a3b8';
    const LINE = '#e8ecf3';
    const CANVAS = '#f1f5f9';
    const SURFACE = '#ffffff';
    const POSITIVE = '#0f9d76';

    /**
     * @param string|null $receiver Name of the person being written to.
     * @param string|null $lang     Language code for the translated site name.
     */
    public static function make($receiver = null, $lang = null)
    {
        $brand = static::resolve($lang);

        // Clone so two emails in one request (customer + vendor) can carry
        // different recipients off the same cached store data.
        $copy = clone $brand;
        $copy->receiver = $receiver;
        $copy->receiver_first = static::firstName($receiver);

        return $copy;
    }

    /**
     * Flush the memo. Only needed by tests and by the admin settings screen,
     * which can change the logo or the address inside a single request.
     */
    public static function forget()
    {
        static::$cache = null;
    }

    protected static function resolve($lang = null)
    {
        if (!is_null(static::$cache)) {
            return static::$cache;
        }

        $setting = Setting::first();
        $siteSetting = static::siteSetting($lang);

        $b = new \stdClass();

        /* ---------------------------------------------------------------
         | Identity
         * -------------------------------------------------------------- */
        $b->store_name = $siteSetting && $siteSetting->site_name
            ? $siteSetting->site_name
            : config('app.name');

        // Site names are often a full marketing strapline ("Acme Store —
        // Reliable Electronics, Unbeatable Value"). That reads badly as an
        // email header or a From name, so keep the part before the first
        // separator for display and the full string for the legal footer.
        $b->store_short = static::shortName($b->store_name);
        $b->tagline = static::tagline($b->store_name);

        /*
         * The header wordmark sets the first word large and whatever follows
         * underneath it, so "FixMyPC Store" reads as a lockup rather than one
         * long line. Used only when no logo image is on file.
         */
        $markParts = preg_split('/\s+/', trim($b->store_short), 2);
        $b->mark_word = $markParts[0];
        $b->mark_tail = isset($markParts[1]) ? $markParts[1] : null;

        $b->site_url = static::storefrontUrl($siteSetting);

        /* ---------------------------------------------------------------
         | Logo — absolute URLs only. A relative src silently breaks the
         | moment the mail leaves the app, and a broken logo is both an
         | engagement and a reputation problem.
         * -------------------------------------------------------------- */
        $b->image = $siteSetting ? $siteSetting->email_logo : null;
        $b->logo = $b->image ? static::asset($b->image) : null;
        $b->logo_base64 = null; // filled lazily by logoBase64(), PDFs only

        /* ---------------------------------------------------------------
         | Contact
         * -------------------------------------------------------------- */
        $b->support_email = $setting && $setting->email ? $setting->email : config('mail.from.address');
        $b->phone = $setting && $setting->phone ? $setting->phone : null;
        $b->phone_href = $b->phone ? static::telHref($b->phone) : null;
        $b->whatsapp = $setting && isset($setting->whatsapp) && $setting->whatsapp ? $setting->whatsapp : null;
        $b->whatsapp_url = $b->whatsapp
            ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $b->whatsapp)
            : null;

        $b->address = $setting ? Utils::formatAddress($setting) : 'N/A';
        $b->address_lines = static::addressLines($setting);

        /* ---------------------------------------------------------------
         | Money
         * -------------------------------------------------------------- */
        $b->currency_icon = $setting ? $setting->currency_icon : '';
        $b->currency = $setting ? $setting->currency : null;

        /* ---------------------------------------------------------------
         | Colour — driven by the storefront's own primary colour so the
         | emails and the site never drift apart.
         * -------------------------------------------------------------- */
        $primary = $siteSetting && $siteSetting->primary_color
            ? trim($siteSetting->primary_color)
            : '#2563eb';

        $b->primary = static::normalizeHex($primary, '#2563eb');
        $b->primary_dark = static::shade($b->primary, -0.22);
        $b->primary_soft = static::mixWithWhite($b->primary, 0.92);
        $b->primary_edge = static::mixWithWhite($b->primary, 0.78);
        $b->on_primary = static::readableOn($b->primary);

        // Neutrals, exposed to the templates so a partial never invents a
        // colour of its own.
        $b->ink = self::INK;
        $b->ink_soft = self::INK_SOFT;
        $b->muted = self::MUTED;
        $b->faint = self::FAINT;
        $b->line = self::LINE;
        $b->canvas = self::CANVAS;
        $b->surface = self::SURFACE;
        $b->positive = self::POSITIVE;

        /* ---------------------------------------------------------------
         | Links into the storefront
         * -------------------------------------------------------------- */
        $client = rtrim(config('env.url.CLIENT_BASE_URL') ?: $b->site_url, '/');

        $b->client_url = $client;
        $b->orders_url = $client . '/user/orders';
        $b->account_url = $client . '/user/dashboard';
        $b->track_url = $client . '/track-order';
        $b->shop_url = $client;

        $b->pages = static::pages($client);
        $b->privacy_url = isset($b->pages['privacy-policy']) ? $b->pages['privacy-policy']['url'] : null;
        $b->help_url = isset($b->pages['help']) ? $b->pages['help']['url'] : null;
        $b->faq_url = isset($b->pages['faq']) ? $b->pages['faq']['url'] : null;
        $b->contact_url = isset($b->pages['contact']) ? $b->pages['contact']['url'] : null;
        $b->refund_url = isset($b->pages['refund-policy']) ? $b->pages['refund-policy']['url'] : null;

        /* ---------------------------------------------------------------
         | Social profiles, from the same rows that drive the site footer
         * -------------------------------------------------------------- */
        $b->socials = static::socials();

        /* ---------------------------------------------------------------
         | Legal footer. A postal address and a clear sender identity are
         | required by CAN-SPAM and expected by every major filter.
         * -------------------------------------------------------------- */
        $b->year = date('Y');
        $b->copyright = $siteSetting && $siteSetting->copyright_text
            ? $siteSetting->copyright_text
            : '© ' . $b->year . ' ' . $b->store_short . '. All rights reserved.';

        $b->receiver = null;
        $b->receiver_first = null;

        static::$cache = $b;

        return $b;
    }

    /**
     * Absolute URL for an image referenced from an email.
     *
     * FileHelper builds these from APP_URL, which on a lot of installs is a
     * local address — and an <img src="http://127.0.0.1:8000/..."> reaches
     * nobody. MAIL_ASSET_URL lets the public host be set independently, which
     * matters beyond broken pictures: images that fail to load hurt
     * engagement, and a link to a private address is something filters score
     * against. Falls back to the normal behaviour when unset.
     */
    public static function asset($image)
    {
        /*
         * A missing file has to come back as null, not as a URL that 404s.
         * Templates fall back to text when there is no image, and a text
         * wordmark reads far better than a broken-image box — which is both
         * an obvious trust problem in a customer's inbox and something
         * filters notice. This is not hypothetical: a logo replaced in admin
         * and not synced to the file store leaves every email broken, which
         * is exactly the state this install is in locally.
         */
        if (!static::exists($image)) {
            return null;
        }

        $url = FileHelper::imageFullUrl($image);

        $override = rtrim((string)env('MAIL_ASSET_URL', ''), '/');

        if ($url && $override) {
            $parts = parse_url($url);

            if (isset($parts['path'])) {
                return $override . $parts['path'];
            }
        }

        return $url;
    }

    /**
     * URL for one of the template's own images — the icons and illustrations
     * under public/images/email, as opposed to a customer or catalogue
     * upload.
     *
     * Same host rules as asset(): MAIL_ASSET_URL wins so images do not end up
     * pointing at a local APP_URL, and a missing file returns null so the
     * template can leave the <img> out rather than render a broken one.
     */
    public static function emailAsset($file)
    {
        $relative = 'public/images/email/' . ltrim($file, '/');

        if (!file_exists(base_path($relative))) {
            return null;
        }

        $base = rtrim((string)env('MAIL_ASSET_URL', ''), '/');

        if ($base === '') {
            $base = rtrim((string)(config('env.url.APP_URL') ?: config('app.url')), '/');
        }

        return $base . '/' . $relative;
    }

    /**
     * Whether an upload is actually present.
     *
     * Only answerable for local storage; a CDN or remote-URL store is taken
     * on trust rather than paid for with a network round trip per image.
     */
    public static function exists($image)
    {
        if (!$image || $image === 'null') {
            return false;
        }

        if (config('env.media.STORAGE') !== config('env.media.LOCAL')) {
            return true;
        }

        try {
            return \Illuminate\Support\Facades\Storage::disk('public')->exists($image);

        } catch (\Throwable $e) {
            // Misconfigured disk: assume present rather than blanking every
            // image in every email.
            return true;
        }
    }

    /**
     * Base64 of the email logo, for the PDF invoice where remote images are
     * not fetched. Resolved on demand — it is dead weight in an HTML email.
     */
    public static function logoBase64($brand)
    {
        if (is_null($brand->logo_base64) && $brand->image) {
            $brand->logo_base64 = FileHelper::imageToBase64($brand->image);
        }

        return $brand->logo_base64;
    }

    protected static function siteSetting($lang = null)
    {
        $query = SiteSetting::query();

        if ($lang) {
            $query = $query->leftJoin('site_setting_langs as cl', function ($join) use ($lang) {
                $join->on('cl.site_setting_id', '=', 'site_settings.id');
                $join->where('cl.lang', $lang);
            })->select('site_settings.*', 'cl.site_name', 'cl.copyright_text',
                'cl.meta_title', 'cl.meta_description');
        }

        return $query->first();
    }

    /**
     * Published pages that the site footer links to, keyed by slug so a
     * template can ask for "privacy-policy" without caring how many pages
     * the store has configured.
     */
    protected static function pages($client)
    {
        $pages = [];

        try {
            $links = FooterLink::with('page')->get();

            foreach ($links as $link) {
                if (!$link->page || !$link->page->slug) {
                    continue;
                }

                $pages[$link->page->slug] = [
                    'title' => $link->page->title,
                    'slug' => $link->page->slug,
                    'url' => $client . '/page/' . $link->page->slug,
                ];
            }
        } catch (\Throwable $e) {
            // A store with no footer links configured still gets its emails.
        }

        return $pages;
    }

    protected static function socials()
    {
        $out = [];

        try {
            $rows = FooterImageLink::where('type', Config::get('constants.footerImageLinkType.SOCIAL'))
                ->where('status', Config::get('constants.status.PUBLIC'))
                ->get();

            foreach ($rows as $row) {
                if (!$row->link) {
                    continue;
                }

                $out[] = (object)[
                    'title' => $row->title,
                    'link' => $row->link,
                    'image' => $row->image ? static::asset($row->image) : null,
                ];
            }
        } catch (\Throwable $e) {
            // Social row missing or table absent: the footer just omits them.
        }

        return $out;
    }

    protected static function storefrontUrl($siteSetting)
    {
        $url = $siteSetting && $siteSetting->site_url ? $siteSetting->site_url : null;

        if (!$url) {
            $url = config('env.url.CLIENT_BASE_URL') ?: config('app.url');
        }

        return rtrim($url, '/');
    }

    /**
     * "Acme Store – Reliable Electronics" becomes "Acme Store".
     */
    public static function shortName($name)
    {
        if (!$name) {
            return config('app.name');
        }

        $parts = preg_split('/\s+[–—|:-]\s+/u', $name, 2);
        $short = trim($parts[0]);

        // A name with no separator but a lot of words still needs trimming,
        // or the From name gets cut off mid-word in the inbox list.
        if ($short === '' ) {
            $short = trim($name);
        }

        return $short;
    }

    protected static function tagline($name)
    {
        $parts = preg_split('/\s+[–—|:-]\s+/u', $name, 2);

        return isset($parts[1]) ? trim($parts[1]) : null;
    }

    protected static function firstName($name)
    {
        if (!$name) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name));

        return $parts[0];
    }

    protected static function addressLines($setting)
    {
        if (!$setting) {
            return [];
        }

        $lines = [];

        foreach ([$setting->address_1, $setting->address_2] as $line) {
            if ($line && trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        $locality = array_filter([
            $setting->city,
            $setting->state,
            $setting->zip,
        ], function ($v) {
            return $v && trim($v) !== '';
        });

        if (count($locality)) {
            $lines[] = join(', ', $locality);
        }

        if ($setting->country && trim($setting->country) !== '') {
            $lines[] = trim($setting->country);
        }

        return $lines;
    }

    protected static function telHref($phone)
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone);

        return 'tel:' . $clean;
    }

    /* -------------------------------------------------------------------
     | Colour maths. Inline styles cannot use CSS colour functions, so the
     | shades a template needs have to be computed here.
     * ------------------------------------------------------------------ */

    public static function normalizeHex($hex, $fallback = '#2563eb')
    {
        $hex = ltrim(trim((string)$hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $fallback;
        }

        return '#' . strtolower($hex);
    }

    protected static function rgb($hex)
    {
        $hex = ltrim(static::normalizeHex($hex), '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    protected static function toHex($r, $g, $b)
    {
        return sprintf('#%02x%02x%02x',
            max(0, min(255, (int)round($r))),
            max(0, min(255, (int)round($g))),
            max(0, min(255, (int)round($b)))
        );
    }

    /** Negative amount darkens, positive lightens. */
    public static function shade($hex, $amount)
    {
        list($r, $g, $b) = static::rgb($hex);

        if ($amount < 0) {
            $f = 1 + $amount;
            return static::toHex($r * $f, $g * $f, $b * $f);
        }

        return static::toHex(
            $r + (255 - $r) * $amount,
            $g + (255 - $g) * $amount,
            $b + (255 - $b) * $amount
        );
    }

    /** $ratio of white, 0..1 — used for tinted panel backgrounds. */
    public static function mixWithWhite($hex, $ratio)
    {
        return static::shade($hex, $ratio);
    }

    /**
     * Black or white text, whichever stays readable on the given background.
     * A pale brand colour with white text on it is a real accessibility bug.
     */
    public static function readableOn($hex)
    {
        list($r, $g, $b) = static::rgb($hex);

        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.62 ? self::INK : '#ffffff';
    }
}
