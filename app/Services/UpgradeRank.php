<?php

namespace App\Services;

/**
 * Which of two attribute values is the better one.
 *
 * The whole upgrade engine rests on this question, and the catalogue does not
 * answer it: attribute_values has a title and a colour, and id order is
 * insertion order, which is not the same thing.
 *
 * Three answers are tried, in this order:
 *
 *   1. attribute_values.rank, when an admin has set one. Always wins, because
 *      it is the only one that can express "Excellent beats Very Good".
 *   2. A quantity parsed out of the title -- "128GB", "1 TB", "16 GB RAM",
 *      "2 Years", "1.5m". This is what makes the engine work on a fresh
 *      install with no configuration at all, which covers storage, memory,
 *      capacity, length and warranty between them.
 *   3. Nothing. The value is unrankable and the caller leaves it out.
 *
 * Step 3 matters as much as the others. "Black" and "Blue" have no order, and
 * neither do "Excellent" and "Acceptable" until somebody says so -- so a
 * colour attribute is silently never treated as upgradeable, which is the
 * correct default rather than an omission.
 */
class UpgradeRank
{
    /**
     * Multipliers that put every size on one scale, so 1TB outranks 512GB.
     *
     * Keyed on the unit as it appears after the number. Longest keys are
     * matched first, so "mb" never matches inside "mbps".
     */
    private static $units = [
        'kb' => 1,
        'mb' => 1024,
        'gb' => 1048576,
        'tb' => 1073741824,
        'pb' => 1099511627776,

        'mm' => 1,
        'cm' => 10,
        'm' => 1000,
        'km' => 1000000,

        'mhz' => 1,
        'ghz' => 1000,

        'w' => 1,
        'kw' => 1000,

        'mah' => 1,
        'wh' => 1,

        'day' => 1,
        'days' => 1,
        'week' => 7,
        'weeks' => 7,
        'month' => 30,
        'months' => 30,
        'year' => 365,
        'years' => 365,
    ];

    /**
     * A comparable number for one value, or null when it has no order.
     *
     * $value is anything carrying `rank` and `title` -- an AttributeValue, or
     * the array the service has already flattened.
     */
    public static function of($value)
    {
        $explicit = self::explicitRank($value);

        if (!is_null($explicit)) {
            /*
             * Kept on its own scale, far below any parsed size, so an admin
             * ranking three values 1/2/3 is never accidentally compared
             * against a parsed "128GB". A group is ranked one way or the
             * other, never half of each.
             */
            return ['scale' => 'explicit', 'value' => (float)$explicit];
        }

        $parsed = self::parseTitle(self::titleOf($value));

        if (!is_null($parsed)) {
            return ['scale' => 'parsed', 'value' => $parsed];
        }

        return null;
    }

    /**
     * Compare two ranks. Returns <0, 0 or >0, or null when they cannot be
     * compared at all -- a parsed rank and an explicit one are not on the
     * same scale and must never be ordered against each other.
     */
    public static function compare($a, $b)
    {
        if (is_null($a) || is_null($b)) {
            return null;
        }

        if ($a['scale'] !== $b['scale']) {
            return null;
        }

        return $a['value'] <=> $b['value'];
    }

    /** Is $target a genuine step up from $current? */
    public static function isHigher($current, $target)
    {
        $comparison = self::compare($current, $target);

        return !is_null($comparison) && $comparison < 0;
    }


    /* ------------------------------------------------------------------ */

    private static function explicitRank($value)
    {
        $rank = is_array($value)
            ? ($value['rank'] ?? null)
            : ($value->rank ?? null);

        // 0 is a legitimate rank; only "not set" means fall through.
        return (is_null($rank) || $rank === '') ? null : (int)$rank;
    }

    private static function titleOf($value)
    {
        return (string)(is_array($value) ? ($value['title'] ?? '') : ($value->title ?? ''));
    }

    /**
     * The quantity a title describes, on a single comparable scale.
     *
     * Returns null rather than 0 for a title with no number in it, because a
     * value that cannot be ranked has to be distinguishable from one that
     * ranks lowest.
     */
    public static function parseTitle($title)
    {
        $normalised = strtolower(trim($title));

        if ($normalised === '') {
            return null;
        }

        // "128GB", "1 TB", "2 Years", "1.5m", "16GB RAM"
        if (!preg_match('/(\d+(?:[.,]\d+)?)\s*([a-z]+)?/', $normalised, $match)) {
            return null;
        }

        $number = (float)str_replace(',', '.', $match[1]);
        $unit = isset($match[2]) ? $match[2] : '';

        if ($unit === '') {
            return $number;
        }

        /*
         * Longest unit first, so "mb" cannot match the start of a longer word
         * and quietly rank a "2 Months" warranty as two megabytes.
         */
        $units = self::$units;
        uksort($units, function ($a, $b) { return strlen($b) - strlen($a); });

        foreach ($units as $suffix => $multiplier) {
            if ($unit === $suffix) {
                return $number * $multiplier;
            }
        }

        /*
         * A number with a unit nobody knows is still a number: "Tier 2" beats
         * "Tier 1". Only a title with no number at all is unrankable.
         */
        return $number;
    }
}
