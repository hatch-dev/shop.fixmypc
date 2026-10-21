<?php

namespace App\Database;

use Illuminate\Database\MySqlConnection;

/**
 * The MySQL connection, with translated columns falling back to the original.
 *
 * @see TranslationFallbackGrammar
 */
class TranslationFallbackMySqlConnection extends MySqlConnection
{
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new TranslationFallbackGrammar);
    }
}
