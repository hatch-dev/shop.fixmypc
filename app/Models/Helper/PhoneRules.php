<?php

namespace App\Models\Helper;

use App\Services\TwilioService;

/**
 * Is this a phone number that country could actually have?
 *
 * The supplier form used to accept any twenty characters. What matters is
 * narrower than "looks like digits": the number has to survive
 * TwilioService::toE164(), because that is what every outgoing WhatsApp is
 * built from, and a number that fails there is a supplier who will never be
 * contacted and no error anywhere to say why.
 *
 * Two checks, in order:
 *
 *  1. The national significant number is a length that country uses.
 *     storage/resources/phone-rules.json carries exact ranges for 75
 *     countries; anything else falls back to DEFAULT_RANGE, which is the
 *     generic ITU E.164 envelope rather than a guess at that country's plan.
 *
 *  2. toE164() can build a sendable number from the pair. This is the real
 *     constraint and it is the same code path the send uses.
 */
class PhoneRules
{
    /**
     * ITU-T E.164 puts 15 digits on a full international number, of which
     * the country code takes one to three. Four is the shortest national
     * number in use anywhere.
     */
    const DEFAULT_RANGE = [4, 14];

    /** E.164's hard ceiling, country code included. */
    const MAX_E164_DIGITS = 15;

    /**
     * Keys in storage/resources/phones.json that are not ISO 3166-1 alpha-2.
     *
     * That file calls the United Kingdom "UK"; countries.json calls it "GB",
     * which is the actual ISO code. Left alone the two never join, so the
     * picker showed a country with no name, stored a code no standard
     * recognises, and fell through to the generic length rule instead of the
     * UK's -- on the shop's nearest export market.
     *
     * Normalised in one place so the dialling code, the country name, the
     * flag and the length rule all key off the same thing.
     */
    const ISO_ALIASES = [
        'UK' => 'GB',
    ];

    /**
     * Countries in phones.json that countries.json has no name for.
     *
     * Without a name the picker prints the bare code -- "AC" on a row of its
     * own between Albania and Algeria, which reads as a glitch.
     */
    const EXTRA_NAMES = [
        'AC' => 'Ascension Island',
    ];

    /**
     * Codes that should not be offered at all.
     *
     * AN is the Netherlands Antilles, dissolved in 2010. Its entry (+5999) is
     * really Curaçao's national format, and Curaçao is already on the list as
     * CW (+599) -- so offering AN is offering the same country twice, once
     * under a country that no longer exists.
     */
    const RETIRED = ['AN'];

    /**
     * A dialling code as E.164 wants it.
     *
     * phones.json writes several as "country code + a bracketed hint at what
     * follows": "+1(268)" for Antigua, "+62(8)" for Indonesian mobiles,
     * "+7(7)" for Kazakhstan. Concatenated with a number they still dial
     * correctly, because toE164() strips the punctuation -- but they are
     * stored and shown to an admin exactly as written, and "+1(268)" in a
     * phone field looks like a mistake.
     */
    public static function normaliseDial($dial)
    {
        $dial = preg_replace('/[^0-9]/', '', (string)$dial);

        return $dial === '' ? '' : '+' . $dial;
    }

    /** The ISO 3166-1 alpha-2 code for a key that may be an alias. */
    public static function normaliseIso($iso)
    {
        $iso = strtoupper(trim((string)$iso));

        return isset(self::ISO_ALIASES[$iso]) ? self::ISO_ALIASES[$iso] : $iso;
    }

    private static $rules;

    /** @return array ISO 3166-1 alpha-2 => [min, max] */
    public static function all()
    {
        if (self::$rules === null) {
            $path = base_path('storage/resources/phone-rules.json');

            self::$rules = is_readable($path)
                ? (json_decode(file_get_contents($path), true) ?: [])
                : [];
        }

        return self::$rules;
    }

    /** The [min, max] national number length for a country. */
    public static function rangeFor($iso)
    {
        $rules = self::all();
        $iso = self::normaliseIso($iso);

        return isset($rules[$iso]) ? $rules[$iso] : self::DEFAULT_RANGE;
    }

    /**
     * The national significant number: digits only, with the trunk prefix
     * taken off.
     *
     * A supplier types their number the way they say it -- "087 452 3344" in
     * Ireland, "(020) 7946 0958" in the UK -- and that leading 0 is a trunk
     * prefix, not part of the number. toE164() already strips it; strip it
     * here too so the length check is measuring the same thing.
     */
    public static function nationalDigits($phone)
    {
        $digits = preg_replace('/[^0-9]/', '', (string)$phone);

        return ltrim($digits, '0');
    }

    /**
     * Why this number is not usable for that country, or null if it is.
     *
     * @return string|null a message fit to show on the form.
     */
    public static function problem($phone, $dialCode, $iso = null)
    {
        $national = self::nationalDigits($phone);

        if ($national === '') {
            return 'Enter a phone number.';
        }

        list($min, $max) = self::rangeFor($iso);

        $length = strlen($national);

        if ($length < $min || $length > $max) {
            $expected = $min === $max
                ? $min . ' digits'
                : $min . ' to ' . $max . ' digits';

            return 'That is ' . $length . ' digits. A number here is '
                . $expected . ', without the leading zero.';
        }

        $dialDigits = strlen(preg_replace('/[^0-9]/', '', (string)$dialCode));

        if ($dialDigits + $length > self::MAX_E164_DIGITS) {
            return 'That number is too long to dial internationally.';
        }

        /*
         * The check that actually decides whether a message can be sent.
         * Built the same way the send builds it: dialling code then number.
         */
        if (!TwilioService::toE164($dialCode . $national)) {
            return 'That is not a number we can send a message to.';
        }

        return null;
    }

    public static function valid($phone, $dialCode, $iso = null)
    {
        return self::problem($phone, $dialCode, $iso) === null;
    }
}
