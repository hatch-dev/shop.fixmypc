<?php

namespace App\Http\Controllers;

use App\Models\Attribute;
use App\Models\AttributeLang;
use App\Models\AttributeValue;
use App\Models\AttributeValueLang;
use App\Models\Helper\ControllerHelper;
use App\Models\Inventory;
use App\Models\InventoryAttribute;
use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class AttributesController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {


            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'attribute.view')) {
                return $can;
            }

            $query = Attribute::query();

            if ($lang) {
                $query = $query->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                    $join->on('al.attribute_id', '=', 'attributes.id');
                    $join->where('al.lang', $lang);
                });
                $query = $query->select('attributes.*', 'al.title');

                $query = $query->with(['values' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('attribute_values.*', 'avl.title');
                }]);


                if ($request->q) {
                    $query = $query->where('al.title', 'LIKE', "%{$request->q}%");
                }

            } else {
                $query = $query->with('values');


                if ($request->q) {
                    $query = $query->where('attributes.title', 'LIKE', "%{$request->q}%");
                }
            }

            $query = $query->orderBy('attributes.' . $request->orderby, $request->type);



            if ($this->isVendor) {
                $query = $query->where('attributes.admin_id', $this->user->id);
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


    public function allAttributes(Request $request)
    {
        try {
            $lang = $request->header('language');


            $query = Attribute::query();

            if ($lang) {
                $query = $query->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                    $join->on('al.attribute_id', '=', 'attributes.id');
                    $join->where('al.lang', $lang);
                });
                $query = $query->select('attributes.*', 'al.title');

                $query = $query->with(['values' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('attribute_values.*', 'avl.title');
                }]);

            } else {
                $query = $query->with('values');
            }

            $query = $query->orderBy('attributes.created_at');
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

            if ($can = Utils::userCan($this->user, 'attribute.view')) {
                return $can;
            }

            $query = Attribute::query();
            if ($lang) {
                $query = $query->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                    $join->on('al.attribute_id', '=', 'attributes.id');
                    $join->where('al.lang', $lang);
                });
                $query = $query->select('attributes.*', 'al.title');

                $query = $query->with(['values' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('attribute_values.*', 'avl.title');
                }]);

            } else {
                $query = $query->with('values');
            }

            $attribute = $query->find($id);

            if (is_null($attribute)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, $attribute));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function action(Request $request, $id = null)
    {
        try {

            $lang = $request->header('language');

            $validate = Validation::attribute($request);
            if ($validate) {
                return response()->json($validate);
            }

            if ($id) {
                if ($can = Utils::userCan($this->user, 'attribute.edit')) {
                    return $can;
                }

                $filtered = array_filter($request->all(), function ($element) {
                    return !is_array($element) && '' !== trim($element);
                });

                /*
                 * Work out what the request asks for before doing any of it.
                 *
                 * This loop used to write each title and colour as it reached
                 * them and then bail the moment it met a value that could not
                 * be deleted. A refused save had therefore already applied part
                 * of itself, while the form reported that it had failed — so
                 * what was actually stored was whatever the loop had got
                 * through, which is not visible from either end.
                 */
                $data = ['add' => [], 'update' => [], 'delete' => []];

                foreach ($request->values as $value) {

                    $title = trim($value['title'] ?? '');
                    $hasId = key_exists('id', $value);

                    if (!$hasId) {

                        // A new row left blank is the empty field at the bottom
                        // of the form, not a request for a nameless value.
                        if ($title !== '') {
                            $data['add'][] = [
                                'title' => $value['title'],
                                'color' => $value['color'] ?? null,
                                'attribute_id' => $id,
                                'admin_id' => $request->user()->id
                            ];
                        }

                    } else if ($title === '') {
                        // The form deletes a value by clearing its field.
                        $data['delete'][] = $value['id'];

                    } else {
                        $data['update'][] = [
                            'id' => $value['id'],
                            'title' => $value['title'],
                            'color' => $value['color'] ?? null,
                        ];
                    }
                }

                /*
                 * A value a product variant still uses cannot be removed. Every
                 * deletion is checked at once so the refusal can name all of
                 * them: the form hides the rows it has removed, so "one of
                 * these is in use" is not something the admin can act on.
                 */
                if ($data['delete']) {

                    $inUse = $this->attributeValuesInUse($data['delete']);

                    if ($inUse) {
                        return response()->json(Validation::error($request->token,
                            $this->attributeInUseMessage($inUse, $lang)));
                    }
                }

                if ($data['add'] && ($can = Utils::userCan($this->user, 'attribute.create'))) {
                    return $can;
                }

                if ($data['delete'] && ($can = Utils::userCan($this->user, 'attribute.delete'))) {
                    return $can;
                }

                // Everything has passed; apply the whole request or none of it.
                DB::transaction(function () use ($request, $data, $id, $lang, $filtered) {

                    foreach ($data['update'] as $value) {

                        // color lives on attribute_values (not language-specific)
                        AttributeValue::where('id', $value['id'])
                            ->update(['color' => $value['color']]);

                        if ($lang) {

                            $existingValue = AttributeValueLang::where('attribute_value_id', $value['id'])
                                ->where('lang', $lang)->first();

                            if ($existingValue) {
                                AttributeValueLang::where('id', $existingValue->id)
                                    ->update(['title' => $value['title']]);
                            } else {
                                AttributeValueLang::create([
                                    'attribute_value_id' => $value['id'],
                                    'lang' => $lang,
                                    'title' => $value['title'],
                                ]);
                            }

                        } else {
                            AttributeValue::where('id', $value['id'])
                                ->update(['title' => $value['title']]);
                        }
                    }

                    if ($data['add']) {

                        if ($lang) {
                            foreach ($data['add'] as $i) {
                                $avid = AttributeValue::create([
                                    'attribute_id' => $id,
                                    'color' => $i['color'] ?? null,
                                    'admin_id' => $request->user()->id
                                ]);

                                AttributeValueLang::create([
                                    'attribute_value_id' => $avid->id,
                                    'lang' => $lang,
                                    'title' => $i['title'],
                                ]);
                            }
                        } else {
                            AttributeValue::insert($data['add']);
                        }
                    }

                    if ($data['delete']) {
                        AttributeValueLang::whereIn('attribute_value_id', $data['delete'])->delete();
                        AttributeValue::whereIn('id', $data['delete'])->delete();
                    }

                    if ($lang) {

                        $existingAttrLang = AttributeLang::where('attribute_id', $id)
                            ->where('lang', $lang)->first();

                        if ($existingAttrLang) {
                            AttributeLang::where('id', $existingAttrLang->id)
                                ->update(['title' => $filtered['title']]);
                        } else {
                            AttributeLang::insert([
                                'lang' => $lang,
                                'attribute_id' => $id,
                                'title' => $request->title
                            ]);
                        }

                    } else {
                        Attribute::where('id', $id)->update(['title' => $filtered['title']]);
                    }
                });

            } else {
                if ($can = Utils::userCan($this->user, 'attribute.create')) {
                    return $can;
                }

                $request['admin_id'] = $request->user()->id;


                if ($lang) {

                    $attribute = Attribute::create(['admin_id' => $request->user()->id]);

                    AttributeLang::create([
                        'attribute_id' => $attribute->id,
                        'title' => $request['title'],
                        'lang' => $lang,
                    ]);


                } else {
                    $attribute = Attribute::create($request->all());
                }


                $id = $attribute->id;

                $data = ['add' => []];
                foreach ($request->values as $value) {
                    if (!key_exists('id', $value) && ('' != trim($value['title']))) {
                        array_push($data['add'],
                            [
                                'title' => $value['title'],
                                'color' => $value['color'] ?? null,
                                'attribute_id' => $attribute->id,
                                'admin_id' => $request->user()->id
                            ]
                        );
                    }
                }


                if (count($data['add']) > 0) {
                    if ($can = Utils::userCan($this->user, 'attribute.create')) {
                        return $can;
                    }

                    if ($lang) {
                        foreach ($data['add'] as $i) {
                            $avid = AttributeValue::create([
                                'attribute_id' => $attribute->id,
                                'color' => $i['color'] ?? null,
                                'admin_id' => $request->user()->id
                            ]);

                            AttributeValueLang::create([
                                'attribute_value_id' => $avid->id,
                                'lang' => $lang,
                                'title' => $i['title'],
                            ]);
                        }
                    } else {
                        AttributeValue::insert($data['add']);
                    }

                }
            }


            //Fetching attribute data
            $query = Attribute::query();
            if ($lang) {
                $query = $query->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                    $join->on('al.attribute_id', '=', 'attributes.id');
                    $join->where('al.lang', $lang);
                });
                $query = $query->select('attributes.*', 'al.title');

                $query = $query->with(['values' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('attribute_values.*', 'avl.title');
                }]);

            } else {
                $query = $query->with('values');
            }

            $attr = $query->find($id);


            return response()->json(new Response($request->token, $attr));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    /**
     * Which of these attribute values a product variant still holds, and what
     * is holding them.
     *
     * Keyed off inventory_attributes alone, exactly as the guard this replaces
     * was: a row there blocks the delete even if the variant it points at has
     * since gone. That keeps the set of values that can be removed identical —
     * what changes is only that the refusal can now say what is in the way.
     * The joins are there to name the products, hence left joins.
     */
    private function attributeValuesInUse(array $ids)
    {
        $rows = DB::table('inventory_attributes as ia')
            ->leftJoin('updated_inventories as ui', 'ui.id', '=', 'ia.inventory_id')
            ->leftJoin('products as p', 'p.id', '=', 'ui.product_id')
            ->whereIn('ia.attribute_value_id', $ids)
            ->select('ia.attribute_value_id as value_id', 'p.title as product')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $titles = AttributeValue::whereIn('id', $ids)->pluck('title', 'id');

        $inUse = [];

        foreach ($rows as $row) {

            $key = (int)$row->value_id;

            if (!isset($inUse[$key])) {
                $inUse[$key] = [
                    'title' => trim((string)($titles[$key] ?? '')),
                    'variants' => 0,
                    'products' => [],
                    'stale' => 0,
                ];
            }

            $inUse[$key]['variants']++;

            if ($row->product === null) {
                // The variant this row points at is gone. It still blocks the
                // delete, but there is no product to send the admin to.
                $inUse[$key]['stale']++;
            } else {
                $inUse[$key]['products'][$row->product] = true;
            }
        }

        return $inUse;
    }

    /**
     * The refusal itself, naming each value that blocked the save and where it
     * is used.
     *
     * What this replaces said "being used by Order. Delete the order first." No
     * order is involved — the check has always been against product variants —
     * so the one instruction it gave could not have worked.
     */
    private function attributeInUseMessage(array $inUse, $lang)
    {
        $parts = [];

        foreach ($inUse as $entry) {

            $name = $entry['title'] === ''
                ? __('lang.attribute_value_unnamed', [], $lang)
                : '"' . $entry['title'] . '"';

            $products = array_keys($entry['products']);

            /* Three names are enough to find the problem; a colour used across
             * twenty products would otherwise bury the rest of the message. */
            $where = count($products) > 3
                ? implode(', ', array_slice($products, 0, 3)) . ' +' . (count($products) - 3)
                : implode(', ', $products);

            if ($where === '') {
                $where = __('lang.attribute_value_stale',
                    ['count' => $entry['stale']], $lang);
            }

            $parts[] = __('lang.attribute_value_usage', [
                'name' => $name,
                'count' => $entry['variants'],
                'products' => $where,
            ], $lang);
        }

        return __('lang.attribute_value_in_use',
            ['values' => implode('; ', $parts)], $lang);
    }
    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'attribute.delete')) {
                return $can;
            }

            $ids =  explode(",", $id);

            foreach ($ids as $i){

                $attribute = Attribute::find($i);

                if (is_null($attribute)) {
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $attribute)) {
                    return $isOwner;
                }

                $attributeValues = AttributeValue::where('attribute_id', $i)->get();

                foreach ($attributeValues as $attributeValue) {

                    $inventoryAttribute = InventoryAttribute::where('attribute_value_id', $attributeValue->id)
                        ->first();

                    if (!is_null($inventoryAttribute)) {
                        return response()->json(Validation::error($request->token,
                            __('lang.delete_attribute', [], $lang)));
                    }
                }

                foreach ($attributeValues as $attributeValue) {
                    AttributeValueLang::where('attribute_value_id', $attributeValue->id)->delete();

                    AttributeValue::where('id', $attributeValue->id)->delete();
                }


                AttributeLang::where('attribute_id', $i)->delete();


                $attribute->delete();

            }
            return response()->json(new Response($request->token, true));


           // return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
