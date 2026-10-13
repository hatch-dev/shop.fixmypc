<?php

namespace App\Models\Helper;

/**
 * Is this mail failure worth trying again?
 *
 * The distinction the whole retry strategy rests on. Retrying a permanent
 * failure is not merely wasted work: repeatedly presenting a dead address to a
 * provider is exactly the behaviour that gets a sending domain rate-limited and
 * then blocked, so "retry everything" costs the deliverability of every other
 * email the store sends. Giving up on a temporary one silently loses a receipt
 * a customer is waiting for.
 *
 * Classification is by SMTP reply code where there is one, because that is the
 * part of the message the RFCs actually define. Message-text matching is the
 * fallback for the transport-level failures that never reach a reply code at
 * all -- a refused TCP connection has no 4xx to read.
 */
class MailFailure
{
    const TRANSIENT = 'transient';
    const PERMANENT = 'permanent';

    /**
     * Transport and provider failures that are about the connection, the load
     * or the moment -- never about the recipient. All safely retryable.
     */
    private static $transientPatterns = [
        'connection could not be established',
        'connection refused',
        'connection timed out',
        'connection reset',
        'could not connect',
        'failed to connect',
        'timed out',
        'timeout',
        'temporarily unavailable',
        'try again later',
        'too many',
        'rate limit',
        'throttl',
        'greylist',
        'service not available',
        'service unavailable',
        'internal server error',
        'bad gateway',
        'gateway timeout',
        'server busy',
        'resources temporarily',
        'stream_socket_enable_crypto',
        'ssl operation failed',
        'broken pipe',
        'name or service not known',
        'temporary failure in name resolution',
    ];

    /**
     * Failures that say something about the address or the message itself.
     * Sending the identical message again produces the identical refusal.
     */
    private static $permanentPatterns = [
        'does not comply',
        'address in mailbox given',
        'invalid address',
        'invalid recipient',
        'no such user',
        'user unknown',
        'unknown user',
        'mailbox unavailable',
        'mailbox not found',
        'recipient address rejected',
        'recipient rejected',
        'does not exist',
        'user not found',
        'relay access denied',
        'message rejected',
        'blacklist',
        'authentication failed',
        'authentication required',
        'must issue a starttls',
        'sender address rejected',
        'domain not found',
        'unrouteable address',
    ];

    /**
     * @param  \Throwable $e
     * @return string     self::TRANSIENT or self::PERMANENT
     */
    public static function classify(\Throwable $e)
    {
        /*
         * A malformed address never becomes well-formed. Swift throws this
         * before it opens a connection, so there is no reply code to read.
         */
        if ($e instanceof \Swift_RfcComplianceException) {
            return self::PERMANENT;
        }

        /*
         * A template that will not render, a missing variable, a bad argument:
         * these are bugs in this codebase. They fail identically every time,
         * and hiding them behind twelve retries only delays the log line that
         * says so.
         */
        if ($e instanceof \Illuminate\View\ViewException ||
            $e instanceof \InvalidArgumentException ||
            $e instanceof \TypeError ||
            $e instanceof \Error) {
            return self::PERMANENT;
        }

        $message = mb_strtolower($e->getMessage());

        /*
         * What the message SAYS is read before the number it quotes, because
         * the same three digits mean opposite things depending on who sent
         * them. "HTTP 503 Service Unavailable" from an API transport is a
         * provider having a bad minute; SMTP 503 is a permanent protocol
         * error. The words disambiguate; the digits do not.
         *
         * Permanent first, so that a refusal which happens to contain a
         * hopeful phrase ("rejected as spam, try again later") is read as the
         * refusal it is.
         */
        foreach (self::$permanentPatterns as $pattern) {
            if (mb_strpos($message, $pattern) !== false) {
                return self::PERMANENT;
            }
        }

        foreach (self::$transientPatterns as $pattern) {
            if (mb_strpos($message, $pattern) !== false) {
                return self::TRANSIENT;
            }
        }

        /*
         * Then the reply code, for the refusals that carry no recognisable
         * wording. 4xx is "not now", 5xx is "not ever" -- that is what the two
         * classes mean in RFC 5321.
         *
         * Matched on a word boundary so a message quoting an order total or a
         * byte count does not read as a reply code.
         */
        if (preg_match('/\b([45])\d{2}\b/', $message, $m)) {
            return $m[1] === '4' ? self::TRANSIENT : self::PERMANENT;
        }

        // HTTP-transport providers report a status instead of a reply code.
        if (preg_match('/\b429\b/', $message)) {
            return self::TRANSIENT;
        }

        /*
         * Unrecognised failures are treated as temporary.
         *
         * The retry ceiling makes this the safe default rather than an open
         * loop: an unknown failure that is really permanent costs a handful of
         * attempts and then lands in failed_jobs, whereas defaulting the other
         * way would discard a receipt on the first unfamiliar network error.
         */
        return self::TRANSIENT;
    }

    public static function isTransient(\Throwable $e)
    {
        return self::classify($e) === self::TRANSIENT;
    }

    /**
     * A message fit to store and log: one line, length-capped, with anything
     * that looks like an email address masked.
     *
     * Provider errors quote the recipient back at you ("550 5.1.1
     * <a@b.com>: Recipient address rejected"), which is how a customer's
     * address ends up in a log file that is not held to the same standard as
     * the database.
     */
    public static function summarise(\Throwable $e, $limit = 300)
    {
        $message = trim(preg_replace('/\s+/u', ' ', $e->getMessage()));

        $message = preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            function ($m) {
                return MailFailure::maskAddress($m[0]);
            },
            $message
        );

        if (mb_strlen($message) > $limit) {
            $message = rtrim(mb_substr($message, 0, $limit - 1)) . '...';
        }

        return $message;
    }

    /**
     * j***@example.com -- enough to recognise an address you already know,
     * not enough to be a copy of the customer list.
     */
    public static function maskAddress($email)
    {
        if (!is_string($email) || mb_strpos($email, '@') === false) {
            return '[redacted]';
        }

        list($local, $domain) = explode('@', $email, 2);

        $visible = mb_substr($local, 0, 1);

        return $visible
            . str_repeat('*', max(1, min(3, mb_strlen($local) - 1)))
            . '@' . $domain;
    }
}
