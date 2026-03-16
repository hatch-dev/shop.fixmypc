<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\Product;
use App\Models\RatingReview;
use App\Models\ReviewImage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

class RatingReviewsController extends ControllerHelper
{
    public function all(Request $request)
    {
        if ($can = Utils::userCan($this->user, 'rating_review.view')) {
            return $can;
        }

        if (!$this->isSuperAdmin) {
            $query = RatingReview::join('products as p', function ($join) {
                $join->on('p.id', '=', 'rating_reviews.product_id');
                $join->where('p.admin_id', $this->user->id);;
            });
            $query = $query->where('admin_id', $this->user->id);
        } else {
            $query = RatingReview::query();
        }

        $query = $query->with('product');
        $query = $query->with('guest_user');
        $query = $query->with('user');
        $query = $query->with('review_images');
        $query = $query->orderBy('rating_reviews.' . $request->orderby, $request->type);

        if ($request->q) {
            $query = $query->where('review', 'LIKE', "%{$request->q}%");
        }

        if ($request->product) {
            $query = $query->where('product_id', $request->product);
        }

        $data = $query->paginate(Config::get('constants.api.PAGINATION'));

        if ($request->time_zone) {
            foreach ($data as $item) {
                $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
            }
        } else {
            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
            }
        }
        return response()->json(new Response($request->token, $data));
    }


    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'rating_review.delete')) {
                return $can;
            }

            $ids = explode(",", $id);

            foreach ($ids as $rv) {

                $ratingReview = RatingReview::with('product_admin')->find($rv);

                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $ratingReview->product_admin)) {
                    return $isOwner;
                }

                if (is_null($ratingReview)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $reviewImages = ReviewImage::where('rating_review_id', $rv)->get();

                foreach ($reviewImages as $i) {
                    ReviewImage::where('id', $i->id)->delete();
                    FileHelper::deleteFile($i->image);
                }

                if ($ratingReview->where('id', $rv)->delete()) {

                    $product = Product::find($ratingReview->product_id);
                    $total = $product->rating * $product->review_count;


                    if($product->review_count > 1){
                        $avg = 0;
                    } else {
                        $avg = ($total - $ratingReview->rating) / $product->review_count - 1;
                    }


                    Product::where('id', $ratingReview->product_id)
                        ->update([
                            'rating' => $avg,
                            'review_count' => $product->review_count - 1
                        ]);
                }
            }


            return response()->json(new Response($request->token, true));
           // return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $productId)
    {

        $lang = $request->header('language');

        $query = RatingReview::with('review_images');

        if ($request->user('user')) {

            $query = $query->where('user_id', $request->user('user')->id);

        } else if ($request->user_token) {

            $query = $query->where('user_token', $request->user_token);

        } else {

            return response()->json(Validation::errorLang($lang));
        }

        $query = $query->where('product_id', $productId);
        $ratingReview = $query->first();

        if (is_null($ratingReview)) {
            return response()->json(Validation::noDataLang($lang));
        }

        return response()->json(new Response($request->token, $ratingReview));
    }


    public function action(Request $request)
    {
		
        $lang = $request->header('language');


        $validate = Validation::ratingReview($request, $lang);
        if ($validate) {
            return response()->json($validate);
        }

        $ratingReviewId = 0;


        $orderQuery = Order::where('id', $request->order_id);

        $ratingReviewQuery = RatingReview::where('order_id', $request->order_id);


        if ($request->user('user')) {

            $orderQuery = $orderQuery->where('user_id', $request->user('user')->id);
            $ratingReviewQuery = $ratingReviewQuery->where('user_id', $request->user('user')->id);

        } else if ($request->user_token) {

            $orderQuery = $orderQuery->where('user_token', $request->user_token);
            $ratingReviewQuery = $ratingReviewQuery->where('user_token', $request->user_token);

        } else {

            return response()->json(Validation::errorLang($lang));
        }

        $order = $orderQuery->first();

        $ratingReviewQuery = $ratingReviewQuery->where('product_id', $request->product_id);
        $ratingReview = $ratingReviewQuery->first();


        if (is_null($order)) {
            return response()->json(Validation::error($request->token,
                __('lang.no_order', [], $lang)
            ));
        }


        if ((int)$order->status != Config::get('constants.orderStatus.DELIVERED')) {
            return response()
                ->json(Validation::error($request->token,
                    __('lang.until_delivered', [], $lang)
                ));
        }

        if ((boolean)$order->cancelled) {
            return response()
                ->json(Validation::error($request->token,
                    __('lang.order_cancelled', [], $lang)
                ));
        }

        $product = Product::find($request->product_id);
        if (is_null($product)) {
            return response()->json(Validation::nothing_found(201, null, 'form', $lang));
        }

        if ($ratingReview) {

            RatingReview::where('id', $ratingReview->id)
                ->update([
                    'rating' => $request->rating,
                    'review' => $request->review
                ]);

            if ($request->deleted_images) {
                foreach (json_decode($request->deleted_images) as $i) {
                    ReviewImage::where(['id' => $i->id])->delete();
                    FileHelper::deleteFile($i->image);
                }
            }

            $reviewCount = $product->review_count;
            $avgRating = (($product->rating * $product->review_count)
                    + $request->rating - $ratingReview->rating) / $reviewCount;

            $ratingReviewId = $ratingReview->id;

        } else {


            $ratingReviewArr = [

                'product_id' => $request->product_id,
                'rating' => $request->rating,
                'order_id' => $request->order_id,
                'review' => $request->review,
            ];

            if ($request->user('user')) {

                $ratingReviewArr['user_id'] = $request->user('user')->id;

            } else if ($request->user_token) {

                $ratingReviewArr['user_token'] = $request->user_token;

            } else {

                return response()->json(Validation::errorLang($lang));
            }

            $ratingReview = RatingReview::create($ratingReviewArr);

            $reviewCount = $product->review_count + 1;
            $avgRating = (($product->rating * $product->review_count) + $request->rating) / $reviewCount;

            $ratingReviewId = $ratingReview->id;
        }

        $reviewImages = [];
        $hasError = [];

        if ($request->images) {
            foreach ($request->images as $img) {
                $imgValidate = Validator::make(['photo' => $img], Validation::imageRules());
                if ($imgValidate->fails()) {
                    array_push($hasError, $imgValidate->errors()->messages());
                } else {
                    $image_info = FileHelper::uploadImage($img, 'review');
                    $rImage['rating_review_id'] = $ratingReviewId;
                    $rImage['image'] = $image_info['name'];
                    $rImage['created_at'] = Carbon::now();
                    $rImage['updated_at'] = Carbon::now();
                    array_push($reviewImages, $rImage);
                }
            }
        }

        Product::where('id', $product->id)->update([
            'review_count' => $reviewCount,
            'rating' => round($avgRating, 2)
        ]);

        if (count($reviewImages) > 0) {
            ReviewImage::insert($reviewImages);
            $ratingReview['review_images'] = $reviewImages;
        }

        if (count($hasError) > 0) {
            return response()->json(new Response($request->token, $ratingReview, 200,
                __('lang.error_uploading', [], $lang)
            ));
        }

        return response()->json(new Response($request->token, $ratingReview, 200,
            __('lang.thank_feedback', [], $lang)
        ));
    } 
	
	
	public function store(Request $request)
    {
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'source' => 'required|in:google,facebook,shop',
            'name' => 'required|string|max:255',
            'product_id' => 'required|exists:products,id',
            'review' => 'required|string',
            'is_verified' => 'boolean',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
		
		if(isset($request->is_verified)){
			$is_verified=true;
		}else{
			$is_verified=false;
		}
		
		
        // Create the review
        $review = RatingReview::create([
            'product_id' => $request->product_id,
			'order_id'=>0,
            'rating' => $request->rating,
            'review' => $request->review,
            'source' => $request->source,
            'name' => $request->name,
            'is_verified' => $is_verified,
            'approved' => 1 // or 'approved' if you want auto-approve
        ]);

        // Handle image uploads
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('review_images', 'public');
                
                ReviewImage::create([
                    'rating_review_id' => $review->id,
                    'image_path' => $path
                ]);
            }
        }
		
		

        // Update product rating statistics
        $this->updateProductRating($request->product_id);

        return response()->json([
            'success' => true,
            'message' => 'Review submitted successfully!',
            'data' => $review
        ]);
    }
	
	 protected function updateProductRating($productId)
    {
        $product = \App\Models\Product::find($productId);
        
        $reviews = RatingReview::where('product_id', $productId)
            ->where('approved', '1')
            ->get();

        if ($reviews->count() > 0) {
            $product->rating = $reviews->avg('rating');
            $product->review_count = $reviews->count();
            $product->save();
        }
    }
	
	 public function show($id)
    {
		
        try {
            // Find the review with relationships
            $review = RatingReview::findOrFail($id);

            // Format the response
            $formattedReview = [
                'id' => $review->id,
                'rating' => $review->rating,
                'review' => $review->review,
                'source' => $review->source,
                'name' => $review->name,
                'is_verified' => (bool)$review->is_verified,
                'product_id' => $review->product_id,
                'product_title' => $review->product->title ?? null,
                'created_at' => $review->created_at->toDateTimeString(),
                'updated_at' => $review->updated_at->toDateTimeString()
            ];

            return response()->json([
                'success' => true,
                'data' => $formattedReview
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve review',
                'error' => $e->getMessage()
            ], 500);
        }
    }
	
	// Add this to your RatingReviewController
	public function update(Request $request)
	{
		
		$review = RatingReview::findOrFail($request->review_id);
		($request->is_verified=='on') ? $verified=1 : $verified=0;
		
		$review->update([
			'product_id' => $request->product_id,
			'rating' => $request->rating,
			'review' => $request->review,
			'source' => $request->source,
			'name' => $request->name,
			'is_verified' => $verified
		]);

		$this->updateProductRating($review->product_id);

		return response()->json([
			'success' => true,
			'message' => 'Review updated successfully!',
			'data' => $review
		]);
	}

}
