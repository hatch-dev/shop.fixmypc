<?php

namespace App\Services;

/**
 * An AfterShip call that did not succeed, already sorted into what the admin
 * can do about it. The raw response body is kept for the log, never shown.
 */
class AfterShipException extends \RuntimeException
{
    /** HTTP status, or 0 when AfterShip was never reached. */
    public $httpStatus;

    /** AfterShip's meta.code: 4005, 4010, 4012 ... or the HTTP status. */
    public $metaCode;

    public $type;

    /** AfterShip's data block on a refusal; a 4003 carries the existing id. */
    public $data = [];

    /**
     * When a rate limit lifts (unix time), from AfterShip's X-RateLimit-Reset
     * header or its message. Set on a 429 only.
     */
    public $resetAt = null;

    /**
     * A 429 that is the account's daily allowance running out (the free plan
     * allows 100 calls a day), not a busy second. Retrying cannot help until
     * the reset; the message must say so instead of "try again in a few seconds".
     */
    public function isQuotaExhausted()
    {
        return $this->httpStatus === 429
            && (stripos($this->getMessage(), 'per day') !== false
                || ($this->resetAt && $this->resetAt - time() > 60));
    }

    public function __construct($message, $httpStatus = 0, $metaCode = 0, $type = null, \Throwable $previous = null)
    {
        parent::__construct($message, (int)$metaCode, $previous);

        $this->httpStatus = (int)$httpStatus;
        $this->metaCode = (int)$metaCode;
        $this->type = $type;
    }

    /** Worth trying again later: rate limit, AfterShip's own fault, no connection. */
    public function isRetryable()
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }

    /** The courier or tracking number was refused: the admin must correct the input. */
    public function isInputError()
    {
        return in_array($this->metaCode, [4005, 4008, 4010, 4011, 4012, 4017], true);
    }

    /**
     * One sentence an admin can act on. AfterShip's own wording is kept for
     * 4011/4012, whose message names the missing field or the reason the
     * number was not recognised.
     */
    public function adminMessage()
    {
        switch (true) {
            case $this->httpStatus === 401:
                return 'AfterShip rejected the API key. Check the API key in Settings > AfterShip.';
            case $this->httpStatus === 403:
                return 'AfterShip refused this request for the account. Check the plan and API key permissions.';
            case $this->isQuotaExhausted():
                return 'AfterShip daily API limit reached for this account (the free plan allows 100 calls a day). '
                    . 'Nothing was saved. It resets '
                    . ($this->resetAt ? 'at ' . \Carbon\Carbon::createFromTimestamp($this->resetAt)->setTimezone('Europe/Dublin')->format('H:i \o\n D j M') . ' (Irish time)' : 'tomorrow')
                    . '. Upgrade the AfterShip plan for more.';
            case $this->httpStatus === 429:
                return 'AfterShip is busy (rate limit). Nothing was saved; try again in a few seconds.';
            case $this->httpStatus === 0:
                return 'AfterShip could not be reached. Nothing was saved; try again shortly.';
            case $this->httpStatus >= 500:
                return 'AfterShip had a problem on its side. Nothing was saved; try again shortly.';
            case $this->metaCode === 4010:
                return 'AfterShip does not recognise that courier.';
            case $this->metaCode === 4005 || $this->metaCode === 4017:
                return 'That tracking number is not valid for the selected courier. Check it against the label.';
            case $this->metaCode === 4011 || $this->metaCode === 4012:
                return 'AfterShip could not accept this shipment: ' . $this->getMessage();
            case $this->metaCode === 4004:
                return 'AfterShip has no record of this tracking.';
            default:
                return 'AfterShip refused the shipment: ' . $this->getMessage();
        }
    }
}
