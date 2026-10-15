<?php

namespace App\Models\Helper;

/**
 * A membership action that could not be done. getMessage() is safe to show
 * the customer; $reason is what actually happened, for the log and tests.
 */
class MembershipError extends \RuntimeException
{
    public $reason;

    public function __construct($message, $reason = null)
    {
        parent::__construct($message);

        $this->reason = $reason ?? $message;
    }
}
