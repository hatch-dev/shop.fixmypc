<?php

namespace App\Http\Controllers;

use App\Models\Cancellation;
use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\LoginCode;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\RatingReview;
use App\Models\ReviewImage;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

class GuestUsersController extends ControllerHelper
{
    /**
     * Mint a guest session token.
     *
     * QA audit SEC-02. The storefront used to generate this itself with
     * Math.random() — predictable, and accepted by the server without ever
     * having been issued. A guest token is a credential: it must come from
     * here, from a CSPRNG, and exist in guest_users before it is honoured.
     */
    public function issueToken(Request $request)
    {
        try {
            $existing = $request->input('user_token');

            // An already-valid token keeps working, so a returning guest does
            // not lose their basket.
            if ($existing && GuestUser::where('user_token', $existing)->exists()) {

                /*
                 * A visit is activity: a guest basket lives 90 days from the
                 * guest's last activity (BasketUpkeep), so a shopper who keeps
                 * coming back keeps their basket. Stamped at most once a day,
                 * because every page load asks for the token.
                 */
                GuestUser::where('user_token', $existing)
                    ->where(function ($query) {
                        $query->whereNull('updated_at')
                            ->orWhere('updated_at', '<', now()->subDay()->format('Y-m-d H:i:s'));
                    })
                    ->update(['updated_at' => now()->format('Y-m-d H:i:s')]);

                return response()->json(new Response($request->token, [
                    'user_token' => $existing
                ]));
            }

            do {
                $token = \Illuminate\Support\Str::random(48);
            } while (GuestUser::where('user_token', $token)->exists());

            GuestUser::create(['user_token' => $token]);

            return response()->json(new Response($request->token, [
                'user_token' => $token
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Step one of guest checkout's email confirmation: email a code.
     *
     * The address a guest types on the checkout form is where the receipt, the
     * tracking link and - once the order is placed - their new account's
     * credentials are sent, so it has to be proved before any of that happens.
     *
     * An address that already belongs to an account is turned away rather than
     * confirmed. The order would end up creating a second account on the same
     * address, which the users table forbids, and the person it belongs to can
     * simply sign in and check out with the history they already have.
     */
    public function sendCheckoutCode(Request $request)
    {
        try {
            $lang = $request->header('language');

            $setting = Setting::select('guest_checkout')->first();

            if (!$setting || !$setting->guest_checkout) {
                return response()->json(Validation::unauthorized());
            }

            $validator = Validation::guest_checkout_email($request);
            if ($validator) {
                return response()->json($validator);
            }

            $guest = GuestUser::where('user_token', $request->input('user_token'))->first();

            if (!$guest) {
                return response()->json(Validation::errorLang($lang));
            }

            $email = trim((string) $request->input('email'));

            $taken = User::where('email', $email)->where('verified', 1)->exists();

            if ($taken) {
                return response()->json(Validation::error(null,
                    'That email address already has an account. Sign in to check out.',
                    'form', $lang
                ));
            }

            if ($request->filled('phone')) {

                $phoneTaken = User::where('phone', $request->input('phone'))
                    ->where('verified', 1)
                    ->exists();

                if ($phoneTaken) {
                    return response()->json(Validation::error(null,
                        'That mobile number is already on another account. Sign in to check out.',
                        'form', $lang
                    ));
                }
            }

            $recent = LoginCode::where('email', $email)
                ->forPurpose(LoginCode::PURPOSE_GUEST_CHECKOUT)
                ->where('created_at', '>', now()->subMinutes(LoginCode::THROTTLE_MINUTES))
                ->count();

            if ($recent >= LoginCode::MAX_PER_WINDOW) {
                return response()->json(Validation::error(null,
                    'Too many codes requested for that address. Try again later.',
                    'form', $lang
                ));
            }

            // Any code already outstanding for this address stops working.
            LoginCode::where('email', $email)
                ->forPurpose(LoginCode::PURPOSE_GUEST_CHECKOUT)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            /*
             * The details are written back before the code goes out, so the
             * address the code was sent to is the one on the record when it
             * comes back to be checked.
             */
            $guest->update([
                'name' => $request->input('name') ?: $guest->name,
                'email' => $email,
                'phone' => $request->input('phone') ?: $guest->phone,
                'verified_email' => null,
                'email_verified_at' => null
            ]);

            $request->merge(['name' => $guest->name, 'email' => $email]);

            try {
                $code = MailHelper::codeSender($request, 'registration',
                    __('lang.mail_verify_title', [], $lang), $lang);

            } catch (\Exception $ex) {
                // Don't hand SMTP internals back to the browser.
                return response()->json(Validation::error(null,
                    'We could not send the code just now. Try again in a moment.',
                    'form', $lang
                ));
            }

            LoginCode::create([
                'email' => $email,
                'purpose' => LoginCode::PURPOSE_GUEST_CHECKOUT,
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(LoginCode::LIFETIME_MINUTES),
                'ip_address' => $request->ip()
            ]);

            return response()->json(new Response($request->token, [
                'email' => $email,
                'expires_in_minutes' => LoginCode::LIFETIME_MINUTES
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Step two: check the code and record which address was proved.
     */
    public function verifyCheckoutCode(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validator = Validation::guest_checkout_email($request);
            if ($validator) {
                return response()->json($validator);
            }

            $guest = GuestUser::where('user_token', $request->input('user_token'))->first();
            $email = trim((string) $request->input('email'));

            /*
             * One message however this fails, so the endpoint cannot be used to
             * work out which addresses have codes outstanding.
             */
            $genericFailure = response()->json(Validation::error(null,
                __('lang.code_invalid', [], $lang), 'form', $lang
            ));

            if (!$guest || !trim((string) $request->input('code'))) {
                return $genericFailure;
            }

            $loginCode = LoginCode::where('email', $email)
                ->forPurpose(LoginCode::PURPOSE_GUEST_CHECKOUT)
                ->usable()
                ->latest('id')
                ->first();

            if (!$loginCode) {
                return response()->json(Validation::error(null,
                    'That code has expired. Send a new one.', 'form', $lang
                ));
            }

            if (!Hash::check(trim($request->input('code')), $loginCode->code)) {

                $loginCode->increment('attempts');

                return $genericFailure;
            }

            $loginCode->update(['used_at' => now()]);

            $guest->update([
                'email' => $email,
                'verified_email' => $email,
                'email_verified_at' => now()
            ]);

            return response()->json(new Response($request->token, [
                'email' => $email,
                'verified' => true
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function all(Request $request)
    {
        try {

            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            if ($request->q) {
                $data = GuestUser::query()
                    ->orderBy($request->orderby, $request->type)
                    ->where('name', 'LIKE', "%{$request->q}%")
                    ->orWhere('email', 'LIKE', "%{$request->q}%")
                    ->paginate(Config::get('constants.api.PAGINATION'));
            } else {
                $data = GuestUser::orderBy($request->orderby, $request->type)
                    ->paginate(Config::get('constants.api.PAGINATION'));
            }

            $ids = [];
            if ($request->time_zone) {
                foreach ($data as $item) {
                    array_push($ids, $item->id);
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }
            } else {
                foreach ($data as $item) {
                    array_push($ids, $item->id);
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            GuestUser::whereIn('id', $ids)->where('viewed', false)->update([
                'viewed' => true
            ]);


            return response()->json(new Response($request->token, $data));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function delete(Request $request, $id)
    {
        try {

            $lang = $request->header('language');
            if ($can = Utils::userCan($this->user, 'user.delete')) {
                return $can;
            }


            $ids = explode(",", $id);

            foreach ($ids as $i) {


                $user = GuestUser::find($i);

                if (is_null($user)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                Cart::where('user_token', $user->user_token)
                    ->where('user_id', '!=', null)
                    ->update([
                        'user_token' => null
                    ]);


                Cart::where('user_token', $user->user_token)
                    ->where('user_id', null)
                    ->delete();


                // Ordered products delete
                $orderedProducts = OrderedProduct::leftJoin('orders', 'ordered_products.order_id', '=', 'orders.id')
                    ->where('orders.user_token', $user->user_token)
                    ->where('orders.user_id', null);

                $orderedProducts->delete();


                // Cancellation message  delete

                Cancellation::where('user_token', $user->user_token)
                    ->where('user_id', '!=', null)
                    ->update([
                        'user_token' => null
                    ]);


                $cancellation = Cancellation::leftJoin('orders', 'cancellations.order_id', '=', 'orders.id')
                    ->where('orders.user_token', $user->user_token)
                    ->where('orders.user_id', null);

                $cancellation->delete();


                Order::where('user_token', $user->user_token)
                    ->where('user_id', '!=', null)
                    ->update([
                        'user_token' => null
                    ]);

                Order::where('user_token', $user->user_token)
                    ->where('user_id', null)
                    ->delete();

                // Review delete
                $reviewImages = ReviewImage::leftJoin('rating_reviews', 'review_images.rating_review_id', '=', 'rating_reviews.id')
                    ->where('rating_reviews.user_token', $user->user_token)
                    ->where('rating_reviews.user_id', null);

                $rimages = $reviewImages->get();
                foreach ($rimages as $img) {
                    FileHelper::deleteFile($img->image);
                }

                $reviewImages->delete();


                RatingReview::where('user_token', $user->user_token)
                    ->where('user_id', '!=', null)
                    ->update([
                        'user_token' => null
                    ]);

                RatingReview::where('user_token', $user->user_token)
                    ->where('user_id', null)
                    ->delete();

                // Address delete

                UserAddress::where('user_token', $user->user_token)
                    ->where('user_id', '!=', null)
                    ->update([
                        'user_token' => null
                    ]);

                UserAddress::where('user_token', $user->user_token)
                    ->where('user_id', null)
                    ->delete();

                $user->delete();

            }


            return response()->json(new Response($request->token, true));

            //return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
