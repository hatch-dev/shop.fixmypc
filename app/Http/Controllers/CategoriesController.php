<?php

namespace App\Http\Controllers;

use App\Models\BannerSourceCategory;
use App\Models\BannerSourceSubCategory;
use App\Models\Category;
use App\Models\CategoryLang;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\HomeSliderSourceCategory;
use App\Models\HomeSliderSourceSubCategory;
use App\Models\ProductCategory;
use App\Models\SubCategory;
use App\Models\SubCategoryLang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class CategoriesController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {

            $limit = $request->limit;
            $orderBy = $request->orderby ?? 'created_at';
            $type = $request->type ?? 'desc';

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.view')) {
                return $can;
            }

            $query = Category::query();

            $query->whereNull('parent');

            $query->withCount('products');

            $totalCategories = Category::whereNull('parent')->count();
            $totalSubcategories = Category::whereNotNull('parent')->count();
            $activeCategories = Category::where('status', 1)->count();
            $draftCategories = Category::where('status', 2)->count();

            if ($this->isVendor) {
                $query = $query->where('admin_id', $this->user->id);
                $totalCategories = Category::whereNull('parent')
                    ->where('admin_id', $this->user->id)
                    ->count();

                $totalSubcategories = Category::whereNotNull('parent')
                    ->where('admin_id', $this->user->id)
                    ->count();

                $activeCategories = Category::where('admin_id', $this->user->id)
                    ->where('status', 1)
                    ->count();

                $draftCategories = Category::where('admin_id', $this->user->id)
                    ->where('status', 2)
                    ->count();
            }


            if ($lang) {

                $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('categories.*', 'cl.title', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords');

                if ($request->q) {
                    $query = $query->where('cl.title', 'LIKE', "%{$request->q}%");
                }

                $query = $query->with(['child' => function ($query) use ($lang) {
                    $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                        $join->on('cl.category_id', '=', 'categories.id');
                        $join->where('cl.lang', $lang);
                    });
                    $query->select('categories.*', 'cl.title', 'cl.meta_title', 'cl.meta_description' , 'cl.meta_keywords');
                }]);

            } else {

                $query = $query->with(['child']);

                if ($request->q) {
                    $query = $query->where('categories.title', 'LIKE', "%{$request->q}%");
                }
            }

            //$query = $query->where('parent', 0);
            //$query = $query->orWhere('parent', null);

            $sortable = [
                'title' => 'categories.title',
                'slug' => 'categories.slug',
                'parent_id' => 'categories.parent',
                'products_count' => 'products_count',
                'status' => 'categories.status',
                'created_at' => 'categories.created_at'
            ];

            if (array_key_exists($orderBy, $sortable)) {
                $query->orderBy($sortable[$orderBy], $type);
            } else {
                $query->orderBy('categories.created_at', 'desc');
            }

            if ($limit == 'all') {
                $limit = $query->count();
            }

            $limit = $limit ?? Config::get('constants.api.PAGINATION');
            $data = $query->paginate($limit);

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
            }
            $dataArray = $data->toArray();
            $dataArray['total_categories'] = $totalCategories;
            $dataArray['total_subcategories'] = $totalSubcategories;
            $dataArray['active_categories'] = $activeCategories;
            $dataArray['draft_categories'] = $draftCategories;
            $data = (object) $dataArray;
            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function allCategories(Request $request)
    {
        try {
            $lang = $request->header('language');
            $query = Category::query();

            $query->whereNull('parent');


            if ($lang) {
                $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id');
                    $join->where('cl.lang', $lang);
                });

                if ($request->q) {
                    $query = $query->where('cl.title', 'LIKE', "%{$request->q}%");
                }
                $query = $query->select('categories.id', 'cl.title');

            } else {

                if ($request->q) {
                    $query = $query->where('categories.title', 'LIKE', "%{$request->q}%");
                }
                $query = $query->select('categories.id', 'categories.title');
            }

            $query = $query->orderBy('categories.created_at');

            if($request->per_page) {
                $data = $query->paginate($request->per_page);
            } else{
                $data = $query->get();
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function find(Request $request, $id)
    {
        try {

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.view')) {
                return $can;
            }

            $query = Category::query();
            if ($lang) {
                $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('categories.*', 'cl.title', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords');
            }

            $category = $query->find($id);

            if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $category)) {
                return $isOwner;
            }

            if (is_null($category)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, $category));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function action(Request $request, $id = null)
    {
        try {

            $lang = $request->header('language');

            $validate = Validation::category($request);
            if ($validate) {
                return response()->json($validate);
            }

            $bySlug = Category::where('slug', $request['slug'])->first();

            if ($id) {
                if ($can = Utils::userCan($this->user, 'category.edit')) {
                    return $can;
                }
                $existing = Category::find($id);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $existing)) {
                    return $isOwner;
                }

                if ($bySlug && $bySlug['id'] != $id) {
                    return response()->json(Validation::error($request->token,
                        __('lang.slug_exists', [], $lang)));
                }


                if((int)$request->parent == (int)$id){

                    return response()->json(Validation::error($request->token,
                        __('lang.cat_parent', [], $lang)));

                }

                $filtered = array_filter($request->all(), function ($element) {
                    return '' !== trim($element);
                });

                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($filtered, ['title', 'meta_title', 'meta_description', 'meta_keywords']);
                    Category::where('id', $id)->update($mainData);
                    $existingLang = CategoryLang::where('category_id', $id)
                        ->where('lang', $lang)
                        ->first();

                    if (!$existingLang) {
                        $langData['category_id'] = $id;
                        $langData['lang'] = $lang;
                        CategoryLang::create($langData);

                    } else {
                        CategoryLang::where('id', $existingLang->id)->update($langData);
                    }
                } else {
                    Category::where('id', $id)->update($filtered);
                }

            } else {
                if ($can = Utils::userCan($this->user, 'category.create')) {
                    return $can;
                }

                if ($bySlug) {
                    return response()->json(Validation::error($request->token,
                        __('lang.slug_exists', [], $lang)));
                }

                $request['image'] = Config::get('constants.media.DEFAULT_IMAGE');
                $request['admin_id'] = $request->user()->id;
                $request['id'] = Utils::idGenerator(new Category());

                if ($lang) {
                    [$langData, $mainData] = Utils::seperateLangData($request->all(), ['title', 'meta_title', 'meta_description', 'meta_keywords']);
                    $category = Category::create($mainData);

                    $langData['category_id'] = $category->id;
                    $langData['lang'] = $lang;
                    CategoryLang::create($langData);
                    $id = $category->id;

                } else {
                    $category = Category::create($request->all());
                    $id = $category->id;
                }
            }

            $query = Category::query();
            if ($lang) {
                $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('categories.*', 'cl.title', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords');
            }

            $category = $query->find($id);

            return response()->json(new Response($request->token, $category));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {


            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'category.delete')) {
                return $can;
            }


            $ids = explode(",", $id);

            foreach ($ids as $i) {
                $category = Category::find($i);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $category)) {
                    return $isOwner;
                }

                if (is_null($category)){
                    return response()->json(Validation::nothingFoundLang($lang));
                }

                HomeSliderSourceCategory::where('category_id', $i)->delete();

                BannerSourceCategory::where('category_id', $i)->delete();

                ProductCategory::where('category_id', $i)->delete();

                CategoryLang::where('category_id', $i)->delete();


                $subCats = SubCategory::where('category_id', $i)->get();
                foreach ($subCats as $sc) {
                    HomeSliderSourceSubCategory::where('sub_category_id', $sc->id)->delete();
                    BannerSourceSubCategory::where('sub_category_id', $sc->id)->delete();
                    SubCategoryLang::where('sub_category_id', $sc->id)->delete();

                    if ($sc->delete()) {
                        FileHelper::deleteFile($sc->image);
                    }
                }

                if ($category->delete()) {
                    Category::where('parent', $i)->update(['parent' => 0]);
                    FileHelper::deleteFile($category->image);
                }
            }

            return response()->json(new Response($request->token, true));

            //return response()->json(Validation::errorTokenLang($request->token, $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function upload(Request $request, $id = null)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::image($request);
            if ($validate) {
                return response()->json($validate);
            }

            $image_info = FileHelper::uploadImage($request['photo'], 'category');
            $request['image'] = $image_info['name'];

            $category = $id ? Category::find($id) : null;

            if (is_null($category)) {
                if ($can = Utils::userCan($this->user, 'category.create')) {
                    return $can;
                }

                $request['admin_id'] = $request->user()->id;
                $request['id'] = Utils::idGenerator(new Category());
                $category = Category::create($request->all());
                $id = $category->id;

            } else {
                if ($can = Utils::userCan($this->user, 'category.edit')) {
                    return $can;
                }
                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $category)) {
                    return $isOwner;
                }

                $category_image = $category->image;
                if ($category->update($request->all())) {
                    FileHelper::deleteFile($category_image);
                }
            }


            $query = Category::query();
            if ($lang) {
                $query = $query->leftJoin('category_langs as cl', function ($join) use ($lang) {
                    $join->on('cl.category_id', '=', 'categories.id');
                    $join->where('cl.lang', $lang);
                });
                $query = $query->select('categories.*', 'cl.title', 'cl.meta_title', 'cl.meta_description', 'cl.meta_keywords');
            }

            $category = $query->find($id);

            return response()->json(new Response($request->token, $category));


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }


    public function updateParent(Request $request)
    {
        try{

            $id = $request->id;
            $parent_id = $request->parent_id;
            $category = Category::find($id);

            if(!$parent_id){
                $category->parent = null;
                $category->save();
                return response()->json(new Response($request->token, true));
            }else{
                $category = Category::find($parent_id);
                $subcategory = Category::find($id);

                $exists = Category::where(['parent' => $category->id, 'id' => $subcategory->id])->exists();
                if($exists){
                    return response()->json(Validation::error($request->token, 'This category is already a child of the selected parent category'));
                }else{
                    $subcategory->parent = $category->id;
                    $subcategory->admin_id = $this->user->id;
                    $subcategory->save();
                    return response()->json(new Response($request->token, true));
                }
            }


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
