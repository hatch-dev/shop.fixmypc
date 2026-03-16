<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Store;
use App\Models\StoreLang;
use Illuminate\Http\Request;

class StoreController extends ControllerHelper
{
    public function find(Request $request)
    {
        try {

            $lang = $request->header('language');

            $query = Store::query();
            if ($lang) {
                $query = $query->leftJoin('store_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.store_id', '=', 'stores.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('stores.*', 'cl.name', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords');
            }
            //$query = $query->where('stores.admin_id', $this->user->id);
            $query = $query->where('stores.admin_id', 1);
            $data = $query->first();


            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request)
    {
        try {

            $lang = $request->header('language');

            $validate = Validation::store($request);
            if ($validate) {
                return response()->json($validate);
            }

            //$data = Store::where('admin_id', $this->user->id)->first();
            /// change for getting same store data for all stores///
           $data =  Store::where('admin_id',1)->first();

            $bySlug = Store::where('slug', $request['slug'])->first();


            $request['created_at'] = $request['updated_at'] = '';

            $filtered = array_filter($request->all(), function ($element) {
                return '' !== trim($element);
            });
            $filtered['whatsapp_btn'] = $request->whatsapp_btn;
            $filtered['whatsapp_number'] = $request->whatsapp_number;
            $filtered['whatsapp_default_msg'] = $request->whatsapp_default_msg;

            $filtered['pickup_address'] = $request->pickup_address;

            if ($data) {

                if ($bySlug && $bySlug->id != $data->id) {
                    return response()->json(Validation::error($request->token,
                        __('lang.slug_exists', [], $lang)));
                }



                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, [
                        'name', 'meta_title', 'meta_description', 'meta_keywords'
                    ]);
                    //Store::where('admin_id', $this->user->id)->update($mainData);
                    Store::where('admin_id', 1)->update($mainData);

                    $existingLang = StoreLang::where('store_id', $data->id)
                        ->where('lang', $lang)->first();

                    if (!$existingLang) {
                        $langData['store_id'] = $request->id;
                        $langData['lang'] = $lang;
                        StoreLang::create($langData);

                    } else {
                        StoreLang::where('id', $existingLang->id)->update($langData);
                    }
                } else {
                     Store::where('admin_id', 1)->update($filtered);
                   // Store::where('admin_id', $this->user->id)->update($filtered);
                }




            } else {

                if ($bySlug) {
                    return response()->json(Validation::error($request->token,
                        __('lang.slug_exists', [], $lang)));
                }

                $filtered['admin_id'] = 1; //$this->user->id;


                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, [
                        'name', 'meta_title', 'meta_description', 'meta_keywords'
                    ]);
                    $siteSetting = Store::create($mainData);

                    $langData['store_id'] = $siteSetting->id;
                    $langData['lang'] = $lang;
                    StoreLang::create($langData);

                } else {
                    Store::create($filtered);

                }
            }

            $query = Store::query();
            if ($lang) {
                $query = $query->leftJoin('store_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.store_id', '=', 'stores.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('stores.*', 'cl.name', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords'
                );
            }
            $query = $query->where('admin_id', 1);  //$query->where('admin_id', $this->user->id);
            $data = $query->first();

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function uploadLogo(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::image($request);
            if ($validate) {
                return response()->json($validate);
            }

            $image_info = FileHelper::uploadImage($request['photo'], 'image');

            $existingStore = Store::where('admin_id', $this->user->id)->first();

            if (is_null($existingStore)) {
                Store::create(['image' => $image_info['name'], 'admin_id' => $this->user->id]);

            } else {
                if ($existingStore->image) {
                    FileHelper::deleteFile($existingStore->image);
                }
                $existingStore->image = $image_info['name'];
                Store::where('admin_id', $this->user->id)->update(['image' => $image_info['name']]);
            }


            $query = Store::query();
            if ($lang) {
                $query = $query->leftJoin('store_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.store_id', '=', 'stores.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('stores.*', 'cl.name', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords'
                );
            }
            $query = $query->where('admin_id', $this->user->id);
            $data = $query->first();

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }


    }

    public function getStore(Request $request)
    {
        try {
            // Get the first store (or modify based on your needs)
            $store_id=$request->storeId;
            $store = Store::find($store_id);
            
            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'pickup_address' => $store->pickup_address,
                    // Include other fields you need
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store data'
            ], 500);
        }
    }
}
