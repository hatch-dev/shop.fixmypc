<?php

namespace App\Models\Helper;

/**
 * Country codes from the project's own storage/resources/countries.json (the
 * list the checkout address form uses). AfterShip wants ISO alpha-3 ("IRL");
 * addresses store alpha-2 ("IE") and Settings stores a name ("Ireland").
 */
class CountryCodes
{
    private static $countries = null;

    private static function countries()
    {
        if (is_null(static::$countries)) {
            $path = storage_path('resources/countries.json');
            $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
            static::$countries = is_array($data) ? $data : [];
        }

        return static::$countries;
    }

    /** "IE", "IRL" or "Ireland" -> "IRL"; null when unknown. */
    public static function alpha3($value)
    {
        $v = trim((string)$value);

        if ($v === '') {
            return null;
        }

        $up = strtoupper($v);
        $name = static::plainName($v);

        foreach (static::countries() as $code2 => $c) {
            if ($up === strtoupper((string)$code2) || $up === strtoupper((string)($c['code3'] ?? ''))
                || $name === static::plainName($c['name'] ?? '')) {
                return isset($c['code3']) ? strtoupper($c['code3']) : null;
            }
        }

        return null;
    }

    /** "Republic of Ireland" and "Ireland" are the same country to a person typing Settings. */
    private static function plainName($name)
    {
        $n = mb_strtolower(trim((string)$name));
        $n = preg_replace('/^(the\s+)?(republic of|kingdom of|state of)\s+/u', '', $n);
        $n = preg_replace('/^the\s+/u', '', $n);

        return preg_replace('/[^a-z]/', '', $n);
    }

    /** The state/county name for a stored state code ("IE", "D" -> "Dublin"); the code itself when unknown. */
    public static function stateName($country, $state)
    {
        $state = trim((string)$state);

        if ($state === '') {
            return null;
        }

        $countries = static::countries();
        $c = $countries[strtoupper(trim((string)$country))] ?? null;

        return $c['states'][$state]['name'] ?? $state;
    }
}
