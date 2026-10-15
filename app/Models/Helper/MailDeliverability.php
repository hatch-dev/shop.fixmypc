<?php

namespace App\Models\Helper;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\URL;

/**
 * Headers and envelope details that decide whether a technically-correct email
 * reaches the inbox or the spam folder.
 *
 * The templates handle what the message looks like; this handles what the
 * receiving filter sees before it renders anything:
 *
 *  - a From name that is the store's name rather than an all-caps env value,
 *  - a Reply-To that a human actually reads,
 *  - Auto-Submitted / X-Auto-Response-Suppress so transactional mail does not
 *    trigger out-of-office loops (bounce loops wreck sender reputation),
 *  - a unique X-Entity-Ref-ID so Gmail stops collapsing consecutive receipts
 *    into one thread and clipping them,
 *  - List-Unsubscribe and one-click List-Unsubscribe-Post on bulk mail, which
 *    Gmail and Yahoo have required from bulk senders since Feb 2024.
 */
class MailDeliverability
{
    /**
     * Transactional: order receipts, codes, password resets, status updates.
     * These must never carry List-Unsubscribe — an unsubscribe link on a
     * receipt invites people to opt out of mail they cannot opt out of.
     */
    public static function transactional(Message $message, $brand = null, $category = null)
    {
        static::identity($message, $brand);
        static::reference($message, $category);

        $headers = $message->getSwiftMessage()->getHeaders();

        // Tells auto-responders this came from a machine, so holiday
        // auto-replies do not bounce back at the sending domain.
        static::set($headers, 'Auto-Submitted', 'auto-generated');
        static::set($headers, 'X-Auto-Response-Suppress', 'OOF, AutoReply');

        return $message;
    }

    /**
     * Bulk / marketing: the newsletter. Carries the unsubscribe affordances
     * the mailbox providers now require of anyone sending in volume.
     *
     * @param string $recipient The single address this copy is going to; the
     *                          unsubscribe link has to be per-person.
     */
    public static function bulk(Message $message, $brand, $recipient, $category = 'newsletter')
    {
        static::identity($message, $brand);
        static::reference($message, $category);

        $headers = $message->getSwiftMessage()->getHeaders();

        $unsubscribe = static::unsubscribeUrl($recipient);
        $mailto = static::unsubscribeMailto($brand, $recipient);

        $targets = [];

        if ($unsubscribe) {
            $targets[] = '<' . $unsubscribe . '>';
        }

        if ($mailto) {
            $targets[] = '<' . $mailto . '>';
        }

        if (count($targets)) {
            static::set($headers, 'List-Unsubscribe', join(', ', $targets));
        }

        if ($unsubscribe) {
            // RFC 8058 one-click. Only valid alongside an https List-Unsubscribe
            // target that accepts a POST, which the unsubscribe route does.
            static::set($headers, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        static::set($headers, 'Precedence', 'bulk');
        static::set($headers, 'X-Auto-Response-Suppress', 'OOF, AutoReply');

        if ($brand && $brand->store_short) {
            static::set($headers, 'List-ID',
                static::quoted($brand->store_short . ' newsletter') . ' <newsletter.' . static::domain($brand) . '>');
        }

        return $message;
    }

    /**
     * From name and Reply-To.
     *
     * MAIL_FROM_NAME is an env string that tends to end up shouting
     * ("FIXMYPC"). The store's own name is both nicer and dynamic, so it wins
     * whenever we have one.
     */
    protected static function identity(Message $message, $brand = null)
    {
        $from = config('mail.from.address');

        if ($from) {
            $name = $brand && $brand->store_short
                ? $brand->store_short
                : config('mail.from.name');

            $message->from($from, $name);
        }

        // Replies to a receipt should land with support, not in a no-reply
        // black hole. Filters read a working Reply-To as a positive signal.
        if ($brand && $brand->support_email && filter_var($brand->support_email, FILTER_VALIDATE_EMAIL)) {
            $message->replyTo($brand->support_email, $brand ? $brand->store_short : null);
        }

        return $message;
    }

    /**
     * A unique reference per message. Without it Gmail groups a customer's
     * receipts into one thread and clips everything past ~102KB, which is how
     * long order emails end up looking truncated.
     */
    protected static function reference(Message $message, $category = null)
    {
        $headers = $message->getSwiftMessage()->getHeaders();

        static::set($headers, 'X-Entity-Ref-ID', (string)\Illuminate\Support\Str::uuid());

        if ($category) {
            // Most ESPs (Brevo, SES, Postmark, Mailgun) read one of these for
            // per-stream reporting; sending both is harmless and means the
            // stats work whichever provider the store is pointed at.
            static::set($headers, 'X-Mailer-Category', $category);
            static::set($headers, 'X-Entity-Type', $category);
        }

        return $message;
    }

    /**
     * The members' new-product email. Bulk in shape, so it carries
     * List-Unsubscribe, but the link turns off this email only
     * (users.member_digest_opt_out) - not the newsletter, not receipts.
     */
    public static function memberDigest(Message $message, $brand, $unsubscribeUrl)
    {
        static::identity($message, $brand);
        static::reference($message, 'member-digest');

        if ($unsubscribeUrl) {
            $headers = $message->getSwiftMessage()->getHeaders();
            static::set($headers, 'List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            static::set($headers, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        return $message;
    }

    /**
     * Signed, per-address unsubscribe URL. Signed so the link cannot be used
     * to unsubscribe somebody else's address by editing the query string.
     */
    public static function unsubscribeUrl($recipient)
    {
        if (!$recipient) {
            return null;
        }

        try {
            return URL::signedRoute('newsletter.unsubscribe', ['email' => $recipient]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function unsubscribeMailto($brand, $recipient)
    {
        $address = $brand && $brand->support_email ? $brand->support_email : config('mail.from.address');

        if (!$address) {
            return null;
        }

        return 'mailto:' . $address . '?subject=' . rawurlencode('Unsubscribe ' . $recipient);
    }

    protected static function domain($brand)
    {
        $from = config('mail.from.address');

        if ($from && strpos($from, '@') !== false) {
            return substr(strrchr($from, '@'), 1);
        }

        $host = parse_url($brand->site_url, PHP_URL_HOST);

        return $host ?: 'localhost';
    }

    protected static function quoted($value)
    {
        return '"' . str_replace('"', '', $value) . '"';
    }

    /**
     * Swift throws if a header is added twice, and Laravel 8 has no
     * "set or replace" on the header set.
     */
    protected static function set($headers, $name, $value)
    {
        if ($headers->has($name)) {
            $headers->remove($name);
        }

        $headers->addTextHeader($name, $value);
    }

    /**
     * Strip a subject down to something a filter will not flag: no all-caps
     * shouting, no runs of exclamation marks, no stray whitespace.
     */
    public static function subject($subject)
    {
        $subject = trim(preg_replace('/\s+/u', ' ', (string)$subject));
        $subject = preg_replace('/!{2,}/', '!', $subject);

        // Leave acronyms alone, but de-shout anything long and fully upper.
        if (mb_strlen($subject) > 8 && mb_strtoupper($subject, 'UTF-8') === $subject) {
            $subject = mb_convert_case(mb_strtolower($subject, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return $subject;
    }
}
