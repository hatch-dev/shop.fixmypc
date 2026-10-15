<?php

namespace App\Models\Helper;

use App\Models\Product;
use App\Models\ShippingPlace;
use Illuminate\Support\Facades\Config;

/**
 * The one place delivery is decided.
 *
 * Products and shipping rules are separate. A product is never assigned a
 * rule; it may only be limited to the countries it can be sold in
 * (products.sell_countries, NULL = anywhere). What an order costs to deliver
 * comes from the address alone:
 *
 *   Delivery    the shipping row that covers the address - an exact
 *               country + state row beats that country's "ALL" row, which
 *               beats an "ALL countries" row - charged once per order.
 *   Collection  a row with pickup switched on; its pickup price, once per
 *               order. The collection counts as a sale in that row's country.
 *
 * The browser only ever says delivery or collection, which address, and (for
 * collection, when there is more than one) which collection point. Price and
 * row are worked out here, by the cart, the order and the POS alike, so a
 * page cannot choose what it is charged.
 */
class Shipping
{
    public static function deliveryType()
    {
        return (int)Config::get('constants.shippingTypeIn.LOCATION');
    }

    public static function pickupType()
    {
        return (int)Config::get('constants.shippingTypeIn.PICKUP');
    }

    public static function isValidType($type)
    {
        return in_array((int)$type, [self::deliveryType(), self::pickupType()], true)
            && (string)(int)$type === trim((string)$type);
    }

    /** "IE, gb" / ['IE','gb'] -> ['GB', 'IE']; unknown codes are dropped. */
    public static function countryCodes($value)
    {
        if (is_null($value)) {
            return [];
        }

        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $known = self::countries();
        $codes = [];

        foreach ($parts as $part) {
            $code = strtoupper(trim((string)$part));

            if ($code !== '' && isset($known[$code])) {
                $codes[$code] = true;
            }
        }

        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }

    /** The stored form of a "sell only in" list: "GB,IE", or null for anywhere. */
    public static function normaliseCountries($value)
    {
        $codes = self::countryCodes($value);

        return $codes ? implode(',', $codes) : null;
    }

    public static function countryName($code)
    {
        $code = strtoupper(trim((string)$code));

        return self::countries()[$code]['name'] ?? $code;
    }

    /** A country's states / counties, code => name; empty when it has none. */
    public static function states($code)
    {
        $code = strtoupper(trim((string)$code));
        $names = [];

        foreach (self::countries()[$code]['states'] ?? [] as $key => $state) {
            $stateCode = strtoupper((string)($state['code'] ?? $key));

            if ($stateCode !== '' && $stateCode !== 'ALL' && !empty($state['name'])) {
                $names[$stateCode] = $state['name'];
            }
        }

        return $names;
    }

    /**
     * The county a collection point is in, as its name, or null when the
     * value is not one of the country's. A row for one county puts its store
     * in that county; a whole-country row takes the county given, by name or
     * code. A country with no counties keeps whatever was typed.
     */
    public static function pickupCounty($country, $rowState, $given)
    {
        $states = self::states($country);
        $rowState = strtoupper(trim((string)$rowState));

        if (!$states) {
            return trim((string)$given);
        }

        if ($rowState !== '' && $rowState !== 'ALL') {
            return $states[$rowState] ?? null;
        }

        $given = trim((string)$given);

        if ($given === '') {
            return null;
        }

        foreach ($states as $stateCode => $name) {
            if (strcasecmp($given, $name) === 0 || strcasecmp($given, $stateCode) === 0) {
                return $name;
            }
        }

        return null;
    }

    private static $countries = null;

    private static function countries()
    {
        if (is_null(self::$countries)) {
            $path = base_path() . '/storage/resources/countries.json';
            self::$countries = is_file($path)
                ? (json_decode(file_get_contents($path), true) ?: [])
                : [];
        }

        return self::$countries;
    }

    /** Whether a product may be sold to a country. */
    public static function canSellTo($product, $country)
    {
        $allowed = self::countryCodes(data_get($product, 'sell_countries'));

        return !$allowed || in_array(strtoupper(trim((string)$country)), $allowed, true);
    }

    /**
     * The shipping row that delivers to this address, or null when no rule
     * covers it. Ties at the same level go to the oldest row; the admin form
     * refuses the overlaps that would cause one.
     */
    public static function deliveryPlace($country, $state = null)
    {
        $country = strtoupper(trim((string)$country));
        $state = trim((string)$state);

        if ($country === '') {
            return null;
        }

        $best = null;
        $bestRank = 0;

        $places = ShippingPlace::with('shipping_rule')
            ->whereIn('country', [$country, 'ALL'])
            ->orderBy('id')
            ->get();

        foreach ($places as $place) {
            $rank = self::rank($place, $country, $state);

            if ($rank > $bestRank) {
                $best = $place;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    private static function rank($place, $country, $state)
    {
        $placeState = trim((string)$place->state);
        $anyState = $placeState === '' || strtoupper($placeState) === 'ALL';

        if ($place->country === 'ALL') {
            return 1;
        }

        if ($place->country !== $country) {
            return 0;
        }

        if ($anyState) {
            return 2;
        }

        return $state !== '' && $placeState === $state ? 3 : 0;
    }

    /** Rows with a collection point, oldest first. */
    public static function collectionPoints()
    {
        return ShippingPlace::with('shipping_rule')
            ->where('pickup_point', 1)
            ->where('country', '!=', 'ALL')
            ->orderBy('id')
            ->get();
    }

    /**
     * The collection point for an order: the one asked for, when it really is
     * one, or the only one there is when nothing was asked for.
     */
    public static function collectionPoint($id = null)
    {
        $points = self::collectionPoints();

        if ($id) {
            return $points->firstWhere('id', (int)$id);
        }

        return $points->count() === 1 ? $points->first() : null;
    }

    /**
     * Everything an order needs to know about its delivery.
     *
     * $products are the product records of the lines being bought (anything
     * with id, title and sell_countries). Returns:
     *
     *   ok          false when the order cannot go ahead as asked
     *   message     why, in the customer's words (the first reason)
     *   type        1 delivery / 2 collection
     *   place       the ShippingPlace charged, or null
     *   price       the delivery charge for the whole order
     *   country     the country the sale is made in
     *   restricted  [product_id => "Title cannot be shipped to Country."]
     *   weight      what the order weighs (kg), as given
     *
     * $weight is the order's weight in kg (linesWeight). It prices a
     * delivery row that has weight bands; collection is not weighed.
     */
    public static function quote($type, $country, $state, $pickupId, $products, $weight = 0.0)
    {
        $result = [
            'ok' => false,
            'message' => null,
            'type' => (int)$type,
            'place' => null,
            'price' => 0.0,
            'country' => null,
            'restricted' => [],
            'weight' => round(max(0.0, (float)$weight), 3),
            'too_heavy' => false,
        ];

        if (!self::isValidType($type)) {
            $result['message'] = 'Choose whether to have your order delivered or to collect it.';
            return $result;
        }

        $collection = (int)$type === self::pickupType();

        if ($collection) {
            $place = self::collectionPoint($pickupId);

            if (!$place) {
                $result['message'] = $pickupId
                    ? 'That collection point is not available. Please choose another.'
                    : (self::collectionPoints()->count() > 1
                        ? 'Choose where you would like to collect your order.'
                        : 'Collection is not available at the moment.');
                return $result;
            }

            $country = $place->country;
        } else {
            $country = strtoupper(trim((string)$country));

            if ($country === '') {
                $result['message'] = 'Add a delivery address to see the delivery cost.';
                return $result;
            }

            $place = self::deliveryPlace($country, $state);

            if (!$place) {
                $result['country'] = $country;
                $result['message'] = 'Sorry, we do not deliver to ' . self::countryName($country) . '.';
                return $result;
            }
        }

        $result['place'] = $place;
        $result['country'] = $country;

        $countryName = self::countryName($country);

        if ($collection) {
            $result['price'] = Pricing::round($place->pickup_price);
        } else {
            $price = self::deliveryPrice($place, $result['weight']);

            if (is_null($price)) {
                $result['too_heavy'] = true;
                $result['message'] = 'Sorry, at ' . self::formatWeight($result['weight'])
                    . ' this order is too heavy for delivery to ' . $countryName
                    . '. Please choose collection, or contact us to arrange delivery.';
                return $result;
            }

            $result['price'] = $price;
        }

        foreach ($products as $product) {
            if ($product && !self::canSellTo($product, $country)) {
                $result['restricted'][data_get($product, 'id')] = $collection
                    ? data_get($product, 'title') . ' is not sold in ' . $countryName . ' and cannot be collected there.'
                    : data_get($product, 'title') . ' cannot be shipped to ' . $countryName . '.';
            }
        }

        if ($result['restricted']) {
            $result['message'] = reset($result['restricted']);
            return $result;
        }

        $result['ok'] = true;

        return $result;
    }

    /*
     * Weight-based delivery, after WooCommerce's Table Rate Shipping. An
     * order weighs what its lines weigh - each product's weight times its
     * quantity, a product with no weight counting as 0 as WooCommerce counts
     * it - and a delivery row with weight bands charges the band that weight
     * falls in, once for the order. A row without bands keeps its flat price.
     *
     * Bands are flat prices ("up to 10 kg: €24"). One charge per extra kg on
     * the row (weight_extra_per_kg) prices an order heavier than the last
     * band: that band's cost plus the rate for every kg over its limit. With
     * no such charge, a heavier order is not delivered there.
     */

    /** The unit products.weight and the bands are kept in. */
    const WEIGHT_UNIT = 'kg';

    /**
     * The total weight of basket lines, in kg. $lines are anything with
     * product_id and quantity. Weights are read here in one query rather than
     * off each line's product relation, whose select need not carry them.
     */
    public static function linesWeight($lines)
    {
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int)data_get($line, 'product_id');

            if ($id) {
                $quantities[$id] = ($quantities[$id] ?? 0) + max(0, (int)data_get($line, 'quantity'));
            }
        }

        if (!$quantities) {
            return 0.0;
        }

        $weights = Product::whereIn('id', array_keys($quantities))->pluck('weight', 'id');
        $total = 0.0;

        foreach ($quantities as $id => $quantity) {
            $total += max(0.0, (float)($weights[$id] ?? 0)) * $quantity;
        }

        return round($total, 3);
    }

    /**
     * A row's bands as they are used: lightest first, numbers cleaned. A band
     * without a limit is dropped - heavier orders are what the row's charge
     * per extra kg is for. Anything that is not a list is no bands.
     */
    public static function weightRates($rates)
    {
        if (is_string($rates)) {
            $rates = json_decode($rates, true);
        }

        if (!is_array($rates)) {
            return [];
        }

        $clean = [];

        foreach ($rates as $rate) {
            $max = is_array($rate) ? ($rate['max'] ?? null) : null;

            if (is_null($max) || trim((string)$max) === '' || (float)$max <= 0) {
                continue;
            }

            $clean[] = [
                'max' => round((float)$max, 3),
                'cost' => Pricing::round((float)($rate['cost'] ?? 0)),
            ];
        }

        usort($clean, function ($a, $b) {
            return $a['max'] <=> $b['max'];
        });

        return $clean;
    }

    /** The row's charge per kg over its last band, or null when it has none. */
    public static function extraPerKg($value)
    {
        return is_null($value) || trim((string)$value) === '' ? null : Pricing::round((float)$value);
    }

    /**
     * The first problem with a row's bands or its charge per extra kg, in the
     * admin's words, or null. Checked on the raw input, before weightRates()
     * tidies it.
     */
    public static function weightRatesError($rates, $extraPerKg = null)
    {
        if (!is_null($extraPerKg) && trim((string)$extraPerKg) !== ''
            && (!is_numeric($extraPerKg) || (float)$extraPerKg < 0)) {
            return 'The cost per extra kg must be 0 or more, or empty.';
        }

        if (is_null($rates) || $rates === '' || $rates === []) {
            return null;
        }

        if (!is_array($rates)) {
            return 'The weight bands could not be read.';
        }

        $maxes = [];

        foreach (array_values($rates) as $index => $rate) {
            $band = 'Weight band ' . ($index + 1);

            if (!is_array($rate)) {
                return $band . ' could not be read.';
            }

            $max = $rate['max'] ?? null;

            if (is_null($max) || trim((string)$max) === '') {
                return $band . ': enter the weight it goes up to.';
            }

            if (!is_numeric($max) || (float)$max <= 0) {
                return $band . ': "up to" must be a weight above 0 kg.';
            }

            $maxes[] = round((float)$max, 3);

            $cost = $rate['cost'] ?? null;

            if (is_null($cost) || trim((string)$cost) === '') {
                return $band . ': enter a cost (0 for free).';
            }

            if (!is_numeric($cost) || (float)$cost < 0) {
                return $band . ': the cost must be 0 or more.';
            }
        }

        if (count($maxes) !== count(array_unique($maxes))) {
            return 'Two weight bands end at the same weight.';
        }

        return null;
    }

    /**
     * What delivery on this row costs for an order of $weight kg: the cost of
     * the band it falls in, or - heavier than the last band - that band's
     * cost plus the row's charge per extra kg over its limit. Null when the
     * order is heavier than the last band and the row has no such charge:
     * delivery is not offered then, as in WooCommerce. A row without bands
     * charges its flat price whatever the order weighs.
     */
    public static function deliveryPrice($place, $weight)
    {
        $rates = self::weightRates(data_get($place, 'weight_rates'));

        if (!$rates) {
            return Pricing::round((float)data_get($place, 'price'));
        }

        $weight = round(max(0.0, (float)$weight), 3);

        foreach ($rates as $rate) {
            if ($weight <= $rate['max']) {
                return $rate['cost'];
            }
        }

        $last = end($rates);
        $extra = self::extraPerKg(data_get($place, 'weight_extra_per_kg'));

        if (is_null($extra)) {
            return null;
        }

        return Pricing::round($last['cost'] + $extra * ($weight - $last['max']));
    }

    /** "1.75 kg", "12 kg" */
    public static function formatWeight($weight)
    {
        return rtrim(rtrim(number_format((float)$weight, 3, '.', ''), '0'), '.') . ' ' . self::WEIGHT_UNIT;
    }

    /** What the storefront is shown of a shipping row: no admin fields. */
    public static function publicPlace($place)
    {
        if (!$place) {
            return null;
        }

        return [
            'id' => $place->id,
            'country' => $place->country,
            'state' => $place->state,
            'price' => Pricing::round($place->price),
            'weight_rates' => self::weightRates($place->weight_rates),
            'weight_extra_per_kg' => self::extraPerKg($place->weight_extra_per_kg),
            'day_needed' => (int)$place->day_needed,
            'pickup_point' => (int)$place->pickup_point,
            'pickup_price' => Pricing::round($place->pickup_price),
            'pickup_phone' => $place->pickup_phone,
            'pickup_address_line_1' => $place->pickup_address_line_1,
            'pickup_address_line_2' => $place->pickup_address_line_2,
            'pickup_city' => $place->pickup_city,
            'pickup_state' => $place->pickup_state,
            'pickup_zip' => $place->pickup_zip,
            // the row's country, never the free text older rows were saved with
            'pickup_country' => $place->country && $place->country !== 'ALL'
                ? self::countryName($place->country)
                : $place->pickup_country,
        ];
    }

    /** The quote as the storefront receives it. */
    public static function publicQuote(array $quote)
    {
        return [
            'ok' => $quote['ok'],
            'message' => $quote['message'],
            'type' => $quote['type'],
            'price' => $quote['price'],
            'country' => $quote['country'],
            'weight' => $quote['weight'] ?? 0.0,
            'weight_unit' => self::WEIGHT_UNIT,
            'too_heavy' => $quote['too_heavy'] ?? false,
            'country_name' => $quote['country'] ? self::countryName($quote['country']) : null,
            'day_needed' => $quote['place'] ? (int)$quote['place']->day_needed : null,
            'place' => self::publicPlace($quote['place']),
            'restricted' => $quote['restricted'],
            'collection_points' => self::collectionPoints()->map(function ($p) {
                return self::publicPlace($p);
            })->values(),
        ];
    }
}
