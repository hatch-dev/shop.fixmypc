<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\ShippingPlace;
use App\Models\ShippingRule;
use App\Models\ShippingRuleLang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Models\Helper\Shipping;

class ShippingRulesController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'shipping_rule.view')) {
                return $can;
            }

            $query = ShippingRule::with('shipping_places');
            $query = $query->orderBy('shipping_rules.' . $request->orderby, $request->type);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }


            if ($lang) {
                $query = $query->leftJoin('shipping_rule_langs as srl', function ($join) use ($lang) {
                    $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                    $join->where('srl.lang', $lang);
                });
                $query = $query->select('shipping_rules.*', 'srl.title');


                if ($request->q) {
                    $query = $query->where('srl.title', 'LIKE', "%{$request->q}%");
                }
            } else {

                if ($request->q) {
                    $query = $query->where('shipping_rules.title', 'LIKE', "%{$request->q}%");
                }
            }


            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function allList(Request $request)
    {
        try {
            $lang = $request->header('language');


            $query = ShippingRule::query();

            if ($lang) {
                $query = $query->leftJoin('shipping_rule_langs as srl', function ($join) use ($lang) {
                    $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                    $join->where('srl.lang', $lang);
                });
                $query = $query->select('shipping_rules.id', 'srl.title');

            } else {

                $query = $query->select('shipping_rules.id', 'shipping_rules.title');
            }

            $query = $query->orderBy('shipping_rules.created_at');
            $data = $query->get();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {

        try {
            $lang = $request->header('language');


            if ($can = Utils::userCan($this->user, 'shipping_rule.view')) {
                return $can;
            }

            $query = ShippingRule::with('shipping_places');

            if ($lang) {
                $query = $query->leftJoin('shipping_rule_langs as srl', function ($join) use ($lang) {
                    $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                    $join->where('srl.lang', $lang);
                });
                $query = $query->select('shipping_rules.*', 'srl.title');
            }
            $data = $query->find($id);

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $data)) {
                return $isOwner;
            }

            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Create or update a shipping rule and its rows.
     *
     * Rules stand on their own: no product is assigned one. At checkout the
     * row that covers the delivery address is found across every rule
     * (Helper\Shipping), so the same country and state may only appear once
     * in the whole set, or the choice would be ambiguous. Everything is
     * checked before anything is written, and written in one transaction - a
     * refusal used to arrive after half the rows had already been saved.
     */
    public function action(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::shippingRule($request);
            if ($validate) {
                return response()->json($validate);
            }

            if ($id) {
                if ($can = Utils::userCan($this->user, 'shipping_rule.edit')) {
                    return $can;
                }

                $existing = ShippingRule::find($id);

                if (is_null($existing)) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }
            } else {
                if ($can = Utils::userCan($this->user, 'shipping_rule.create')) {
                    return $can;
                }
            }

            $places = array_values(array_filter((array)$request->shipping_places, 'is_array'));

            $active = array_values(array_filter($places, function ($place) {
                return empty($place['deleted']);
            }));

            if (count($active) < 1) {
                return response()->json(Validation::error($request->token,
                    __('lang.shipping_least', [], $lang)
                ));
            }

            /*
             * One destination per rule (2026-09-28): another country, or a
             * different price for one county, is another rule. A rule saved
             * earlier with several rows is saved once the extras are marked
             * deleted.
             */
            if (count($active) > 1) {
                return response()->json(Validation::error($request->token,
                    'A shipping rule has one destination. Remove the extra rows, and add another rule '
                    . 'for another country or county.'
                ));
            }

            if ($error = $this->placesError($active, $id)) {
                // With one row, "Row 1:" says nothing.
                $error = ucfirst(preg_replace('/^Row 1: /', '', $error));

                // under the field it is about, as well as in the list at the top
                return response()->json($this->placesErrorField
                    ? Validation::fieldError($request->token, $this->placesErrorField, $error, $request->header('language'))
                    : Validation::error($request->token, $error));
            }

            // weight bands, under their own field
            foreach ($active as $place) {
                if ($error = Shipping::weightRatesError($place['weight_rates'] ?? null, $place['weight_extra_per_kg'] ?? null)) {
                    return response()->json(Validation::fieldError($request->token, 'weight_rates', $error, $lang));
                }
            }

            $placeId = function ($place) {
                return isset($place['id']) && trim((string)$place['id']) !== '' ? (int)$place['id'] : null;
            };

            $named = array_values(array_filter(array_map($placeId, $places)));

            // A row is only ever edited or removed through its own rule.
            if ($named) {
                $own = $id
                    ? ShippingPlace::where('shipping_rule_id', $id)->whereIn('id', $named)->count()
                    : 0;

                if ($own !== count(array_unique($named))) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }
            }

            $deleteIds = [];

            foreach ($places as $place) {
                if (!empty($place['deleted']) && $placeId($place)) {
                    $deleteIds[] = $placeId($place);
                }
            }

            if ($deleteIds && OrderedProduct::whereIn('shipping_place_id', $deleteIds)->exists()) {
                return response()->json(Validation::error($request->token,
                    __('lang.place_delete', [], $lang)
                ));
            }

            $adminId = $request->user()->id;
            $title = trim((string)$request->title);

            $id = DB::transaction(function () use ($id, $lang, $title, $adminId, $places, $placeId, $deleteIds) {

                if ($id) {
                    if ($lang) {
                        ShippingRuleLang::updateOrCreate(
                            ['shipping_rule_id' => $id, 'lang' => $lang],
                            ['title' => $title]
                        );
                    } else {
                        ShippingRule::where('id', $id)->update(['title' => $title]);
                    }
                } else {
                    $rule = ShippingRule::create(['title' => $title, 'admin_id' => $adminId]);
                    $id = $rule->id;

                    if ($lang) {
                        ShippingRuleLang::create([
                            'shipping_rule_id' => $id, 'lang' => $lang, 'title' => $title
                        ]);
                    }
                }

                foreach ($places as $place) {
                    if (!empty($place['deleted'])) {
                        continue;
                    }

                    $columns = $this->placeColumns($place);

                    if ($placeId($place)) {
                        // a query-builder update skips the model's array cast
                        ShippingPlace::where('id', $placeId($place))
                            ->where('shipping_rule_id', $id)
                            ->update(array_merge($columns, [
                                'weight_rates' => is_null($columns['weight_rates'])
                                    ? null : json_encode($columns['weight_rates']),
                            ]));
                    } else {
                        ShippingPlace::create(array_merge($columns, [
                            'shipping_rule_id' => $id,
                            'admin_id' => $adminId,
                        ]));
                    }
                }

                if ($deleteIds) {
                    ShippingPlace::where('shipping_rule_id', $id)->whereIn('id', $deleteIds)->delete();
                }

                return $id;
            });

            $query = ShippingRule::with('shipping_places');

            if ($lang) {
                $query = $query->leftJoin('shipping_rule_langs as srl', function ($join) use ($lang) {
                    $join->on('srl.shipping_rule_id', '=', 'shipping_rules.id');
                    $join->where('srl.lang', $lang);
                });
                $query = $query->select('shipping_rules.*', 'srl.title');
            }

            return response()->json(new Response($request->token, $query->find($id)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** The form field the last placesError() message belongs to, or null. */
    private $placesErrorField = null;

    /**
     * The first problem with a rule's rows, in the admin's words, or null.
     * Sets placesErrorField, so the message can be shown under that field.
     * $ruleId is the rule being saved, so its own current rows do not count
     * as overlaps with themselves.
     */
    private function placesError(array $active, $ruleId)
    {
        $this->placesErrorField = null;

        $seen = [];

        foreach ($active as $index => $place) {
            $row = $index + 1;
            $country = strtoupper(trim((string)($place['country'] ?? '')));

            if ($country === '') {
                $this->placesErrorField = 'country';
                return "Row {$row}: choose a country.";
            }

            foreach (['price' => 'Price', 'pickup_price' => 'Pickup price', 'day_needed' => 'Days needed'] as $field => $label) {
                $value = $place[$field] ?? 0;
                $value = ($value === null || $value === '') ? 0 : $value;

                if (!is_numeric($value) || $value < 0) {
                    $this->placesErrorField = $field;
                    return "Row {$row}: {$label} must be a number, 0 or more.";
                }
            }

            if ((string)(int)($place['day_needed'] ?? 0) !== trim((string)($place['day_needed'] ?? 0)) &&
                !in_array($place['day_needed'] ?? null, [null, ''], true)) {
                $this->placesErrorField = 'day_needed';
                return "Row {$row}: Days needed must be a whole number.";
            }

            if ((int)($place['pickup_point'] ?? 0) === 1) {
                if ($country === 'ALL') {
                    $this->placesErrorField = 'country';
                    return "Row {$row}: a collection point needs a specific country, not All countries.";
                }

                /*
                 * Everything but the second address line is asked for: the
                 * customer is sent here with this address, and the store is
                 * in the row's country - the country the sale is made in and
                 * what a product's "sell only in" is checked against.
                 */
                $filled = function ($key) use ($place) {
                    return trim((string)($place[$key] ?? '')) !== '';
                };

                $required = [
                    'pickup_price' => 'the collection price (0 for free)',
                    'pickup_phone' => "the store's phone number",
                    'pickup_address_line_1' => "the collection point's street address",
                    'pickup_city' => "the collection point's town or city",
                ];

                foreach ($required as $key => $what) {
                    if (!$filled($key)) {
                        $this->placesErrorField = $key;
                        return "Row {$row}: enter {$what}.";
                    }
                }

                $county = Shipping::pickupCounty($country, $place['state'] ?? '', $place['pickup_state'] ?? '');

                if (is_null($county) || $county === '') {
                    $this->placesErrorField = 'pickup_state';
                    return Shipping::states($country)
                        ? "Row {$row}: the collection point must be in " . Shipping::countryName($country)
                            . '. Choose which county or state it is in.'
                        : "Row {$row}: enter the collection point's county or state.";
                }

                if (!$filled('pickup_zip')) {
                    $this->placesErrorField = 'pickup_zip';
                    return "Row {$row}: enter the collection point's Eircode / postcode.";
                }
            }

            $key = $this->coverageKey($country, $place['state'] ?? '');

            if (isset($seen[$key])) {
                $this->placesErrorField = 'country';
                return "Row {$row}: {$this->coverageName($key)} is already in row {$seen[$key]}.";
            }

            $seen[$key] = $row;
        }

        $others = ShippingPlace::with('shipping_rule')
            ->when($ruleId, function ($query) use ($ruleId) {
                $query->where('shipping_rule_id', '!=', $ruleId);
            })
            ->get();

        foreach ($others as $other) {
            $key = $this->coverageKey($other->country, $other->state);

            if (isset($seen[$key])) {
                $rule = optional($other->shipping_rule)->title ?: ('#' . $other->shipping_rule_id);

                $this->placesErrorField = 'country';
                return "Row {$seen[$key]}: {$this->coverageName($key)} is already covered by the shipping rule \"{$rule}\". "
                    . 'Each country and state can only be in one rule.';
            }
        }

        return null;
    }

    /**
     * What every other rule already covers, so the form only offers a
     * country or state that is still free. The save checks the same thing
     * (placesError); this just stops the admin being offered a dead end.
     */
    public function coverage(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'shipping_rule.view')) {
                return $can;
            }

            $except = (int)$request->input('except');

            $taken = ShippingPlace::with('shipping_rule')
                ->when($except, function ($query) use ($except) {
                    $query->where('shipping_rule_id', '!=', $except);
                })
                ->get()
                ->map(function ($place) {
                    return [
                        'key' => $this->coverageKey($place->country, $place->state),
                        'rule' => optional($place->shipping_rule)->title ?: ('#' . $place->shipping_rule_id),
                    ];
                })
                ->values();

            return response()->json(new Response($request->token, $taken));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** "IE|ALL", "IE|D", "ALL|ALL": what a row covers. */
    private function coverageKey($country, $state)
    {
        $country = strtoupper(trim((string)$country));
        $state = trim((string)$state);

        if ($country === 'ALL' || $state === '' || strtoupper($state) === 'ALL') {
            $state = 'ALL';
        }

        return $country . '|' . $state;
    }

    private function coverageName($key)
    {
        [$country, $state] = explode('|', $key);

        if ($country === 'ALL') {
            return 'All countries';
        }

        $name = Shipping::countryName($country);

        return $state === 'ALL' ? "{$name} (all states)" : "{$name} ({$state})";
    }

    /** The columns of one row, as saved. */
    private function placeColumns(array $place)
    {
        $text = function ($key) use ($place) {
            return key_exists($key, $place) && !is_null($place[$key]) ? (string)$place[$key] : '';
        };

        $country = strtoupper(trim((string)$place['country']));
        $pickup = (int)($place['pickup_point'] ?? 0) === 1 && $country !== 'ALL';

        return [
            'country' => $country,
            'state' => $country === 'ALL' ? '' : $text('state'),
            'price' => (float)($place['price'] ?? 0),
            // lightest first; none keeps the flat price above
            'weight_rates' => Shipping::weightRates($place['weight_rates'] ?? null) ?: null,
            // only means anything above a last band
            'weight_extra_per_kg' => Shipping::weightRates($place['weight_rates'] ?? null)
                ? Shipping::extraPerKg($place['weight_extra_per_kg'] ?? null)
                : null,
            'pickup_price' => (float)($place['pickup_price'] ?? 0),
            'day_needed' => (int)($place['day_needed'] ?? 0),
            'pickup_point' => (int)($place['pickup_point'] ?? 0) === 1 ? 1 : 0,
            'pickup_phone' => $text('pickup_phone'),
            'pickup_address_line_1' => $text('pickup_address_line_1'),
            'pickup_address_line_2' => $text('pickup_address_line_2'),
            'pickup_zip' => $text('pickup_zip'),
            'pickup_state' => $pickup ? (string)Shipping::pickupCounty($country, $place['state'] ?? '', $text('pickup_state')) : $text('pickup_state'),
            'pickup_city' => $text('pickup_city'),
            // always the row's country: the store is where the sale is made
            'pickup_country' => $pickup ? Shipping::countryName($country) : $text('pickup_country'),
        ];
    }

    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'shipping_rule.delete')) {
                return $can;
            }

            $ids = explode(",", $id);

            foreach ($ids as $i) {

                $shippingRules = ShippingRule::find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $shippingRules)) {
                    return $isOwner;
                }

                if (is_null($shippingRules)) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                /*
                 * Products are no longer assigned a rule, so they do not hold
                 * one in place. Orders do: each line points at the row it was
                 * delivered or collected by, and the tracking page reads the
                 * delivery estimate and collection address from it.
                 */
                $placeIds = ShippingPlace::where('shipping_rule_id', $i)->pluck('id');

                $orders = $placeIds->isNotEmpty()
                    ? OrderedProduct::whereIn('shipping_place_id', $placeIds)->distinct()->count('order_id')
                    : 0;

                if ($orders > 0) {
                    return response()->json(Validation::error($request->token,
                        "The \"{$shippingRules->title}\" rule cannot be deleted: {$orders} "
                        . ($orders === 1 ? 'order was' : 'orders were')
                        . ' delivered or collected with it. You can still edit its prices, countries and collection point.'
                    ));
                }

                DB::transaction(function () use ($i, $shippingRules) {
                    ShippingPlace::where('shipping_rule_id', $i)->delete();
                    ShippingRuleLang::where('shipping_rule_id', $i)->delete();
                    $shippingRules->delete();
                });
            }

            return response()->json(new Response($request->token, true));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
