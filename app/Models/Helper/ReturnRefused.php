<?php

namespace App\Models\Helper;

/**
 * A return move that cannot be made, with a message staff can act on.
 * Thrown by OrderReturns and shown as-is by the admin API.
 */
class ReturnRefused extends \RuntimeException
{
}
