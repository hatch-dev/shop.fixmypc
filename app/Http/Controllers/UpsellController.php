<?php

namespace App\Http\Controllers;

use App\Models\Upsell;
use App\Models\UpsellProduct;
use App\Models\Helper\ControllerHelper;
use App\Models\Product;
use App\Models\UpsellCategories;
use App\Models\UpsellSubCategories;
use Illuminate\Http\Request;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Support\Facades\Config;

class UpsellController extends ControllerHelper
{
    public function all(Request $request)
    {
		
        try {
            $lang = $request->header('language');

            /*if ($can = Utils::userCan($this->user, 'flash_sale.view')) {
                return $can;
            } */

            $query = Upsell::orderBy('upsells.' . $request->orderby, $request->type);

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
            }

           /* if ($lang) {
                $query = $query->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('flash_sales.*', 'cl.title');

                if ($request->q) {
                    $query = $query->where('cl.title', 'LIKE', "%{$request->q}%");
                }
            }else { */


                if ($request->q) {
                    $query = $query->where('upsells.title', 'LIKE', "%{$request->q}%");
                }
           // }

            $data = $query->paginate(Config::get('constants.api.PAGINATION'));

            /*foreach ($data as $item) {
                if ($request->time_zone) {
                    $item['created'] = Utils::convertTimeToUSERzone($item->created_at, $request->time_zone);
                    $item['start_time'] = Utils::convertTimeToUSERzone($item->start_time, $request->time_zone);
                    $item['end_time'] = Utils::convertTimeToUSERzone($item->end_time, $request->time_zone);
                } else {
                    $item['created'] = Utils::formatDate($item->created_at);
                    $item['start_time'] = Utils::formatDate($item->start_time);
                    $item['end_time'] = Utils::formatDate($item->end_time);
                }
            } */

            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }



    public function action(Request $request, $id = null)
    {
        try {

            $lang = $request->header('language');


            $validate = Validation::Upsell($request);
            if ($validate) {
                return response()->json($validate);
            }

            $selectedCategories = $request->selectedCategories;
            $selectedSubcategories = $request->selectedSubcategories;


           // $endTime = date('Y-m-d H:i:s', strtotime($request->end_time));
           // $startTime = date('Y-m-d H:i:s', strtotime($request->start_time));

           /* if ($endTime <= $startTime) {
                return response()->json(Validation::error(null,
                    __('lang.end_time_must', [], $lang)));
            }

            if ($request->time_zone) {
                $request['start_time'] = Utils::convertTimeToUTCzone($request->start_time, $request->time_zone);
                $request['end_time'] = Utils::convertTimeToUTCzone($request->end_time, $request->time_zone);
            } */


            $productIds = [];
            if ($request['products']) {
                foreach ($request->products as $value) {

                    if (!key_exists('deleted', $value) ||
                        (key_exists('deleted', $value) && !$value['deleted'])) {
                        array_push($productIds, $value['product']['id']);
                    }
                }
            }


            if ($id) {

                if ($can = Utils::userCan($this->user, 'flash_sale.edit')) {
                    return $can;
                }

                $existing = Upsell::find($id);
                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }


                $UpsellProduct = UpsellProduct::with(['product' => function ($query) use ($lang) {
                    $query->leftJoin('product_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('products.id', '=', 'avl.product_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'avl.title');
                }])
                    ->where('upsell_id', '!=', $id)
                    ->whereIn('product_id', $productIds)
                    ->first();


               /* if ($UpsellProduct) {
                    return response()->json(Validation::error($request->token,
                        __('lang.product_already', ['product' => $UpsellProduct->product->title], $lang)
                    ));
                } */


                $filtered = array_filter($request->all(), function ($element) {
                    return !is_array($element) && '' !== trim($element);
                });

                unset($filtered['time_zone']);

               /* if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, ['title']);
                    FlashSale::where('id', $id)->update($mainData);
                    $existingLang = FlashSaleLang::where('flash_sale_id', $id)->where('lang', $lang)->first();

                    if (!$existingLang) {
                        $langData['flash_sale_id'] = $id;
                        $langData['lang'] = $lang;
                        FlashSaleLang::create($langData);

                    } else {
                        FlashSaleLang::where('id', $existingLang->id)->update($langData);
                    }
                } else { */
                    Upsell::where('id', $id)->update($filtered);
               // }


            } else {
                /*if ($can = Utils::userCan($this->user, 'flash_sale.create')) {
                    return $can;
                } */

                $UpselProduct = UpsellProduct::with(['product' => function ($query) use ($lang) {
                    $query->leftJoin('product_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('products.id', '=', 'avl.product_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'avl.title');
                }])
                    ->whereIn('product_id', $productIds)
                    ->first();

               /* if ($UpselProduct) {
                    return response()->json(Validation::error($request->token,
                        __('lang.product_already', ['product' => $UpselProduct->product->title], $lang)
                    ));
                } */

                $request['admin_id'] = $request->user()->id;

                unset($request['time_zone']);

               /* if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($request->all(), ['title']);
                    $flashSale = FlashSale::create($mainData);

                    $langData['flash_sale_id'] = $flashSale->id;
                    $langData['lang'] = $lang;
                    FlashSaleLang::create($langData);
                    $id = $flashSale->id;

                } else { */
                    $Upsell = Upsell::create($request->all());
                    $id = $Upsell->id;
               // }

            }

            if ($request['products']) {
                $data = ['add' => [], 'delete' => []];
                foreach ($request->products as $value) {

                    if ((
                            !key_exists('id', $value) ||
                            (key_exists('id', $value) && '' === trim($value['id']))
                        ) && !(key_exists('deleted', $value) && $value['deleted'])
                    ) {
                        array_push($data['add'],
                            [
                                "product_id" => $value['product']['id'],
                                "price" => $value['price'],
                                "upsell_id" => $id,
                                'admin_id' => $request->user()->id
                            ]
                        );
                    } else if ((key_exists('id', $value) && '' == !trim($value['id'])) &&
                        key_exists('deleted', $value) && $value['deleted']) {
                        array_push($data['delete'], $value['id']);

                    } else if (key_exists('id', $value) && key_exists('updated', $value) && $value['updated']) {
                        UpsellProduct::where('id', $value['id'])
                            ->update([
                                "price" => $value['price']
                            ]);
                    }
                }


                UpsellProduct::insert($data['add']);
                UpsellProduct::whereIn('id', $data['delete'])->delete();
            }

            UpsellCategories::where('upsell_id', $id)->delete();

            foreach ($selectedCategories ?? [] as $category) {
                UpsellCategories::create([
                    'upsell_id' => $id,
                    'category_id' => $category['id']
                ]);
            }

            UpsellSubCategories::where('upsell_id', $id)->delete();
            foreach ($selectedSubcategories ?? [] as $subcategory) {
                UpsellSubCategories::create([
                    'upsell_id' => $id,
                    'subcategory_id' => $subcategory['id']
                ]);
            }


            $query = Upsell::query();
            $query = $query->with('products.product');

            /*if ($lang) {
                $query = $query->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('flash_sales.*', 'cl.title');
            } */

            $data = $query->find($id);


            return response()->json(new Response($request->token, $data));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function findProducts(Request $request,$id)
    {
        try {
			
            $UpsellProducts = UpsellProduct::where(['upsell_id' => $id])->get();

            foreach ($UpsellProducts as $item) {
                $product = Product::where(['id' => $item['product_id']])
                    ->get(['title', 'selling', 'offered','image'])
                    ->first();
                $item['title'] = $product['title'];
				$item['image']= $product['image'];
				
                $item['current_price'] = $product['offered'] ? $product['offered'] : $product['selling'];
				$item['selling_price'] = $product['selling'];
				$item['upsell_price'] = $item['price'];
            }

            return response()->json(new Response($request->token, $UpsellProducts));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function find(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            /*if ($can = Utils::userCan($this->user, 'flash_sale.view')) {
                return $can;
            } */

            $query = Upsell::query();



            if ($lang) {
                $query = $query->with(['products.product' => function ($query) use ($lang) {
                    $query->leftJoin('product_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('products.id', '=', 'avl.product_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->select('products.id', 'products.title', 'products.image', 'products.selling',
                            'products.offered', 'avl.title');
                }, 'categories', 'subcategories']);



                $query = $query->leftJoin('flash_sale_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.flash_sale_id', '=', 'flash_sales.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('flash_sales.*', 'cl.title');


            } else {
                $query = $query->with(['products.product', 'categories', 'subcategories']);
            }

            $data = $query->find($id);

            // Group subcategories by their parent category id
            $subcategoriesGrouped = $data->subcategories->groupBy('parent');

            // Attach only selected subcategories under their parent
            $data->categories->transform(function ($category) use ($subcategoriesGrouped) {

                $category->child = $subcategoriesGrouped->get($category->id, collect([]))
                    ->values();

                return $category;
            });


            if (is_null($data)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {

           /* $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'flash_sale.delete')) {
                return $can;
            } */

            $ids = explode(",", $id);

            foreach ($ids as $i) {
                $upsell = Upsell::with('products')->find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $upsell)) {
                    return $isOwner;
                }

                if (is_null($upsell)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                if (count($upsell['products']) > 0) {
                    UpsellProduct::where(['upsell_id' => $i])->delete();
                }

               // FlashSaleLang::where('_id', $i)->delete();
                $upsell->delete();

            }






            return response()->json(new Response($request->token, true));

            //return response()->json(Validation::error($request->token, $lang));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
	
	// In your UpsellController or API controller
	public function getActiveUpsells()
	{
		$upsells = Upsell::select('id', 'title')
			->where('status', 1) // Only active upsells
			->orderBy('title')
			->get()
			->keyBy('id')
			->map(function ($upsell) {
				return [
					'id' => $upsell->id,
					'title' => $upsell->title
				];
			})
			->toArray();

		return response()->json($upsells);
	}
}
