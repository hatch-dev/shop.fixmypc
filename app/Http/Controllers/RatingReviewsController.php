<?php

namespace App\Http\Controllers;

use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\OrderedProduct;
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
                    // rating and count are re-derived from the approved reviews left
                    $this->updateProductRating($ratingReview->product_id);
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

        // Only a product this delivered order contains can be reviewed through
        // it - that is what makes the review a verified purchase.
        if (!OrderedProduct::where('order_id', $order->id)->where('product_id', $product->id)->exists()) {
            return response()->json(Validation::error($request->token,
                __('lang.review_not_in_order', [], $lang)
            ));
        }

        if ($ratingReview) {

            // An edited review is new text: it goes back to awaiting approval.
            RatingReview::where('id', $ratingReview->id)
                ->update([
                    'rating' => $request->rating,
                    'review' => $request->review,
                    'is_verified' => 1,
                    'approved' => 0,
                ]);

            if ($request->deleted_images) {
                foreach (json_decode($request->deleted_images) as $i) {
                    ReviewImage::where(['id' => $i->id])->delete();
                    FileHelper::deleteFile($i->image);
                }
            }

            $ratingReviewId = $ratingReview->id;
            $ratingReview = RatingReview::find($ratingReviewId);

        } else {


            $ratingReviewArr = [

                'product_id' => $request->product_id,
                'rating' => $request->rating,
                'order_id' => $request->order_id,
                'review' => $request->review,
                // derived here from the delivered order checked above, never
                // taken from the request
                'is_verified' => 1,
                // shown on the product page only once an admin approves it
                'approved' => 0,
            ];

            if ($request->user('user')) {

                $ratingReviewArr['user_id'] = $request->user('user')->id;

            } else if ($request->user_token) {

                $ratingReviewArr['user_token'] = $request->user_token;

            } else {

                return response()->json(Validation::errorLang($lang));
            }

            $ratingReview = RatingReview::create($ratingReviewArr);

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

        // A pending review does not count yet; an edit may have just taken an
        // approved one out of the figures.
        $this->updateProductRating($product->id);

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
            __('lang.review_pending', [], $lang)
        ));
    } 
	
	
    /**
     * Admin: add a review by hand - one left on Google, Facebook or in the shop.
     *
     * This used to be a public v1 route: anyone could post an auto-approved
     * "verified" review under any name and source. It is now admin/rating-review/store,
     * behind the admin guard and the rating-review moderation permission
     * (rating_review.delete, the same one the list's Edit button uses). An
     * admin-entered review is approved at once and carries that admin's id;
     * is_verified is the admin's own statement, not a customer's.
     */
    public function store(Request $request)
    {
        $lang = $request->header('language');
        if ($can = Utils::userCan($this->user, 'rating_review.delete')) {
            return $can;
        }

        $validator = Validator::make($request->all(), $this->manualReviewRules());
        if ($validator->fails()) {
            return response()->json(Validation::error($request->token, $validator->errors()->first(), 'form', $lang));
        }

        if ($refused = $this->refuseForeignProduct($request->product_id)) {
            return $refused;
        }

        $review = RatingReview::create([
            'product_id' => $request->product_id,
            'order_id' => 0,
            'rating' => $request->rating,
            'review' => $request->review,
            'source' => $request->source,
            'name' => $request->name,
            'is_verified' => filter_var($request->is_verified, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'admin_id' => $this->user->id,
            'approved' => 1,
        ]);

        $this->storeManualReviewImages($request, $review->id);
        $this->updateProductRating($request->product_id);

        return response()->json(new Response($request->token, $review, 200, __('lang.review_saved', [], $lang)));
    }

    /**
     * Admin: approve or unapprove reviews. `id` may be a comma list;
     * `approved` is 1 (show on the product page) or 0 (hide it again).
     * The products' rating and review count are re-derived from what is
     * approved afterwards.
     */
    public function approve(Request $request)
    {
        try {
            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'rating_review.delete')) {
                return $can;
            }

            $approved = filter_var($request->approved, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $ids = array_filter(array_map('intval', explode(',', (string)$request->id)));
            if (!$ids) {
                return response()->json(Validation::noDataLang($lang));
            }

            $reviews = RatingReview::with('product_admin')->whereIn('id', $ids)->get();
            if ($reviews->isEmpty()) {
                return response()->json(Validation::noDataLang($lang));
            }

            foreach ($reviews as $review) {
                if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $review->product_admin)) {
                    return $isOwner;
                }
            }

            RatingReview::whereIn('id', $reviews->pluck('id'))->update(['approved' => $approved]);
            foreach ($reviews->pluck('product_id')->unique() as $productId) {
                $this->updateProductRating($productId);
            }

            return response()->json(new Response($request->token, ['ids' => $reviews->pluck('id'), 'approved' => $approved], 200,
                __($approved ? 'lang.review_approved' : 'lang.review_unapproved', [], $lang)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * A product's rating and review_count, from its approved reviews only:
     * a review awaiting approval is not on the product page, so it does not
     * move the stars either. No approved review left means 0 and 0.
     */
    protected function updateProductRating($productId)
    {
        $stats = RatingReview::where('product_id', $productId)
            ->where('approved', 1)
            ->selectRaw('COUNT(*) AS total, AVG(rating) AS average')
            ->first();

        Product::where('id', $productId)->update([
            'rating' => $stats && $stats->total ? round($stats->average, 2) : 0,
            'review_count' => $stats ? (int)$stats->total : 0,
        ]);
    }

    private function manualReviewRules()
    {
        return [
            'rating' => 'required|integer|min:1|max:5',
            'source' => 'required|in:google,facebook,shop',
            'name' => 'required|string|max:255',
            'product_id' => 'required|exists:products,id',
            'review' => 'required|string',
            'is_verified' => 'nullable|boolean',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'
        ];
    }

    /** A vendor may only put reviews on their own products. */
    private function refuseForeignProduct($productId)
    {
        if ($this->isVendor && (int)Product::where('id', $productId)->value('admin_id') !== (int)$this->user->id) {
            return response()->json(Validation::unauthorized());
        }

        return null;
    }

    private function storeManualReviewImages(Request $request, $reviewId)
    {
        if (!$request->hasFile('images')) {
            return;
        }
        foreach ($request->file('images') as $image) {
            $info = FileHelper::uploadImage($image, 'review');
            ReviewImage::create([
                'rating_review_id' => $reviewId,
                'image' => $info['name'],
            ]);
        }
    }

	 public function show($id)
    {
		
        try {
            // Find the review with relationships
            // only an approved review is public
            $review = RatingReview::where('approved', 1)->findOrFail($id);

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
	
    /**
     * Admin: edit a hand-entered or customer review (admin/rating-review/update,
     * same guard and permission as store). Was a public v1 route that let
     * anyone rewrite any review by id.
     */
    public function update(Request $request)
    {
        $lang = $request->header('language');
        if ($can = Utils::userCan($this->user, 'rating_review.delete')) {
            return $can;
        }

        $review = RatingReview::with('product_admin')->find($request->review_id);
        if (is_null($review)) {
            return response()->json(Validation::noDataLang($lang));
        }
        if ($this->isVendor && $isOwner = Utils::isDataOwner($this->user, $review->product_admin)) {
            return $isOwner;
        }

        $validator = Validator::make($request->all(), $this->manualReviewRules());
        if ($validator->fails()) {
            return response()->json(Validation::error($request->token, $validator->errors()->first(), 'form', $lang));
        }
        if ($refused = $this->refuseForeignProduct($request->product_id)) {
            return $refused;
        }

        $oldProductId = $review->product_id;
        $review->update([
            'product_id' => $request->product_id,
            'rating' => $request->rating,
            'review' => $request->review,
            'source' => $request->source,
            'name' => $request->name,
            'is_verified' => filter_var($request->is_verified, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        $this->updateProductRating($review->product_id);
        if ((int)$oldProductId !== (int)$review->product_id) {
            $this->updateProductRating($oldProductId);
        }

        return response()->json(new Response($request->token, $review->makeHidden('product_admin'), 200, __('lang.review_saved', [], $lang)));
    }

}
