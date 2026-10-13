<?php

namespace App\Models\Helper;

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
     */
    public static function quote($type, $country, $state, $pickupId, $products)
    {
        $result = [
            'ok' => false,
            'message' => null,
            'type' => (int)$type,
            'place' => null,
            'price' => 0.0,
            'country' => null,
            'restricted' => [],
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
        $result['price'] = Pricing::round($collection ? $place->pickup_price : $place->price);

        $countryName = self::countryName($country);

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
