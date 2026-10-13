<?php

namespace App\Models\Helper;

/**
 * The server half of the address hierarchy the delivery forms follow:
 *
 *   country -> region (county / state) -> town -> postcode
 *
 * The country decides which regions exist, whether a postcode is wanted at
 * all and what shape it takes. Keep this in step with
 * frontend-panel/mixin/addressRules.js - the browser checks the same rules so
 * a customer is told early, and these are the ones that actually hold, since
 * a form is only a suggestion to whatever posts to the endpoint.
 */
class AddressRules
{
    /**
     * Countries that run no postal code system. Refusing an address from one
     * of these for want of a postcode is the bug this list prevents.
     */
    const NO_POSTCODE = [
        'AE', 'AG', 'AO', 'AW', 'BF', 'BI', 'BJ', 'BS', 'BW', 'BZ', 'CD', 'CF',
        'CG', 'CI', 'CK', 'CM', 'CW', 'DJ', 'DM', 'ER', 'FJ', 'GD', 'GH', 'GM',
        'GQ', 'GY', 'HK', 'JM', 'KI', 'KM', 'KN', 'KP', 'LC', 'LY', 'ML', 'MO',
        'MR', 'MS', 'MU', 'MW', 'NR', 'NU', 'PA', 'QA', 'RW', 'SB', 'SC', 'SL',
        'SO', 'SR', 'ST', 'SY', 'TF', 'TL', 'TO', 'TT', 'TV', 'TZ', 'UG', 'VU',
        'YE', 'ZW',
    ];

    /**
     * Per country: what the field is called and the pattern the value has to
     * match once spaces and dashes are taken out.
     */
    const POSTCODE_RULES = [
        // routing key - one of the 15 letters An Post uses plus two digits,
        // D6W being the documented exception - then four characters from the
        // reduced alphabet that drops the letters confusable with digits
        'IE' => ['label' => 'Eircode', 'pattern' => '/^(?:[AC-FHKNPRTV-Y]\d{2}|D6W)[0-9AC-FHKNPRTV-Y]{4}$/', 'example' => 'A65 F4E2'],
        'GB' => ['label' => 'postcode', 'pattern' => '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', 'example' => 'SW1A 1AA'],
        'US' => ['label' => 'ZIP code', 'pattern' => '/^\d{5}(?:\d{4})?$/', 'example' => '10001'],
        'CA' => ['label' => 'postal code', 'pattern' => '/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z]\d[ABCEGHJ-NPRSTV-Z]\d$/', 'example' => 'K1A 0B1'],
        // a PIN code never starts with 0
        'IN' => ['label' => 'PIN code', 'pattern' => '/^[1-9]\d{5}$/', 'example' => '110001'],
        'AU' => ['label' => 'postcode', 'pattern' => '/^\d{4}$/', 'example' => '2000'],
        'NZ' => ['label' => 'postcode', 'pattern' => '/^\d{4}$/', 'example' => '6011'],
        'DE' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '10115'],
        'FR' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '75001'],
        'ES' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '28001'],
        'IT' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '00184'],
        'NL' => ['label' => 'postcode', 'pattern' => '/^\d{4}[A-Z]{2}$/', 'example' => '1012 AB'],
        'BE' => ['label' => 'postcode', 'pattern' => '/^\d{4}$/', 'example' => '1000'],
        'AT' => ['label' => 'postal code', 'pattern' => '/^\d{4}$/', 'example' => '1010'],
        'CH' => ['label' => 'postal code', 'pattern' => '/^\d{4}$/', 'example' => '8001'],
        'DK' => ['label' => 'postal code', 'pattern' => '/^\d{4}$/', 'example' => '1050'],
        'NO' => ['label' => 'postal code', 'pattern' => '/^\d{4}$/', 'example' => '0150'],
        'FI' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '00100'],
        'SE' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '111 21'],
        'PL' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '00-001'],
        'PT' => ['label' => 'postal code', 'pattern' => '/^\d{7}$/', 'example' => '1000-001'],
        'JP' => ['label' => 'postal code', 'pattern' => '/^\d{7}$/', 'example' => '100-0001'],
        'BR' => ['label' => 'CEP', 'pattern' => '/^\d{8}$/', 'example' => '01310-100'],
        'ZA' => ['label' => 'postal code', 'pattern' => '/^\d{4}$/', 'example' => '0001'],
        'SG' => ['label' => 'postal code', 'pattern' => '/^\d{6}$/', 'example' => '238823'],
        'MY' => ['label' => 'postcode', 'pattern' => '/^\d{5}$/', 'example' => '50450'],
        'CN' => ['label' => 'postal code', 'pattern' => '/^\d{6}$/', 'example' => '100000'],
        'MX' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '01000'],
        'RU' => ['label' => 'postal code', 'pattern' => '/^\d{6}$/', 'example' => '101000'],
        'PK' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '44000'],
        'BD' => ['label' => 'postal code', 'pattern' => '/^\d{4}$/', 'example' => '1000'],
        'LK' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '00100'],
        'NP' => ['label' => 'postal code', 'pattern' => '/^\d{5}$/', 'example' => '44600'],
    ];

    /** Anywhere else has a postal code whose shape we do not hold. */
    const GENERIC_POSTCODE = [
        'label' => 'postal code',
        'pattern' => '/^[A-Z0-9-]{3,10}$/',
        'example' => '',
    ];

    /** The rule for a country, or null where the country runs no postcodes. */
    public static function postcodeRule($country)
    {
        $code = strtoupper(trim((string) $country));

        if ($code === '' || in_array($code, self::NO_POSTCODE, true)) {
            return null;
        }

        return self::POSTCODE_RULES[$code] ?? self::GENERIC_POSTCODE;
    }

    /** Whether a country wants a postcode at all. */
    public static function needsPostcode($country)
    {
        return self::postcodeRule($country) !== null;
    }

    /** Strip a postcode back to the characters the pattern is written for. */
    public static function normalise($value)
    {
        return strtoupper(preg_replace('/[\s-]/', '', (string) $value));
    }

    /** Whether a postcode matches the shape its country uses. */
    public static function isValidPostcode($country, $value)
    {
        $rule = self::postcodeRule($country);

        if (!$rule) {
            return true;
        }

        return (bool) preg_match($rule['pattern'], self::normalise($value));
    }

    /** The regions a country has, keyed by code, straight from the resource. */
    public static function regions($country)
    {
        $code = strtoupper(trim((string) $country));

        if ($code === '') {
            return [];
        }

        $path = base_path() . '/storage/resources/countries.json';

        if (!is_file($path)) {
            return [];
        }

        $countries = json_decode(file_get_contents($path), true);

        return $countries[$code]['states'] ?? [];
    }

    /**
     * Whether a region code belongs to the country it was sent with. An empty
     * region is only acceptable where the country has none to choose from.
     */
    public static function isValidRegion($country, $state)
    {
        $regions = self::regions($country);

        if (empty($regions)) {
            return true;
        }

        return $state !== null && $state !== '' && isset($regions[$state]);
    }
}
