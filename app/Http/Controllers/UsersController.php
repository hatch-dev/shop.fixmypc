<?php

namespace App\Http\Controllers;

use Jenssegers\Agent\Agent;
use App\Models\Cancellation;
use App\Models\UserSession;
use App\Models\Cart;
use App\Models\CompareList;
use App\Models\GuestUser;
use App\Models\Helper\AccountStatus;
use App\Models\Helper\CartMerge;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\LoginCode;
use App\Models\Order;
use App\Models\PasswordResetToken;
use App\Models\TwoFactorChallenge;
use App\Models\UserBusinessProfile;
use App\Services\TwilioService;
use App\Models\OrderedProduct;
use App\Models\RatingReview;
use App\Models\SaveForLater;
use App\Models\ReviewImage;
use App\Models\Setting;
use App\Models\User;
use App\Models\Helper\Validation;
use App\Models\UserAddress;
use App\Models\UserWishlist;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsersController extends ControllerHelper
{
    public function redirectToProvider($service)
    {
        try {

            return Socialite::driver($service)->stateless()->redirect();

        } catch (\Exception $ex) {
            return response()->json(Validation::error(null, $ex->getMessage()));
        }
    }

    public function handleProviderCallback(Request $request, $service)
    {
        try {

            $user = Socialite::driver($service)->stateless()->user();
        
            $socialVariable = $service . '_id';

            $existingUser = User::where($socialVariable, $user->id)
                ->orWhere('email', $user->email)
                ->first();

            if ($existingUser) {

                Auth::guard('web')->login($existingUser);

            } else {

                $newUser = [
                    'name' => $user->name,
                    'email' => $user->email,
                    $socialVariable => $user->id,
                    'password' => encrypt(rand(1000, 9999)),
                    'verified' => 1
                ];
                $newUser = User::create($newUser);
                Auth::guard('web')->login($newUser);
            }

            $authUser = Auth::user();
            $token = $authUser->createToken('user', ['user'])->accessToken;
            $id = $authUser->id;
            $name = $authUser->name;
            $email = $authUser->email;

            return redirect(env('FRONTEND_SOCIAL_REDIRECT', Utils::frontendSocialRedirect()) . '?token=' . $token . '&&user=' . $id . '&&email=' . $email . '&&name=' . $name);
        } catch (\Exception $ex) {
            // return response()->json($ex->getMessage());

            return redirect(env('FRONTEND_SOCIAL_REDIRECT', Utils::frontendSocialRedirect()) . '?error=' . explode('response', $ex->getMessage())[0]);
        }
    }


    public function all(Request $request)
    {
        try {

            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            $limit = $request->limit;

            /*
             * total_spend and total_points are correlated subqueries, not two
             * joins and a GROUP BY.
             *
             * Joining orders AND point_transactions to the same row produced a
             * cartesian product: a customer with 3 orders and 4 point movements
             * got 12 rows, so their spend was reported at four times its real
             * value and their points at three times. Those two figures are what
             * an admin picks loyalty group members by, and they were wrong for
             * every customer who had both an order and a point movement.
             *
             * Cancelled, returned and refunded orders are also no longer
             * counted as spend -- money that was given back is not turnover.
             */
            $spendExcluded = [
                Config::get('constants.orderStatus.CANCELLED'),
                Config::get('constants.orderStatus.RETURNED'),
                Config::get('constants.orderStatus.REFUNDED')
            ];

            $totalSpend = DB::table('orders')
                ->selectRaw('COALESCE(SUM(total_amount),0)')
                ->whereColumn('orders.user_id', 'users.id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', $spendExcluded);

            /*
             * Points are a running balance: credits minus debits. SUM(points)
             * added the debits on, so spending points made a customer look
             * richer in points, not poorer.
             */
            $totalPoints = DB::table('point_transactions')
                ->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN points ELSE -points END),0)")
                ->whereColumn('point_transactions.user_id', 'users.id');

            $recentOrders = DB::table('orders')
                ->selectRaw('COUNT(*)')
                ->whereColumn('orders.user_id', 'users.id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', $spendExcluded)
                ->where('orders.created_at', '>=', Carbon::now()->subDays(30));

            $query = User::query()
                ->select('users.*')
                ->selectSub($totalSpend, 'total_spend')
                ->selectSub($totalPoints, 'total_points')
                ->selectSub($recentOrders, 'orders_last_30d');

            if ($request->q) {
                $query->where(function ($q) use ($request) {
                    $q->where('users.email', 'LIKE', "%{$request->q}%")
                    ->orWhere('users.name', 'LIKE', "%{$request->q}%");
                });
            }

            /*
             * Account type, account status and verification status are three
             * separate questions, so they are three separate filters. Rolling
             * them into one "customer type" dropdown is what makes "suspended
             * business accounts" unanswerable.
             */
            if (in_array($request->account_type, User::ACCOUNT_TYPES, true)) {
                $query->where('users.account_type', $request->account_type);
            }

            if (in_array($request->status, AccountStatus::accountStatuses(), true)) {

                // Rows written before the status column existed are active.
                if ($request->status === User::STATUS_ACTIVE) {
                    $query->where(function ($q) {
                        $q->where('users.status', User::STATUS_ACTIVE)
                            ->orWhereNull('users.status');
                    });
                } else {
                    $query->where('users.status', $request->status);
                }
            }

            $verifications = [
                User::VERIFICATION_NOT_REQUIRED, User::VERIFICATION_PENDING,
                User::VERIFICATION_VERIFIED, User::VERIFICATION_REJECTED
            ];

            if (in_array($request->verification_status, $verifications, true)) {
                $query->where('users.verification_status', $request->verification_status);
            }

            /*
             * is_numeric rather than a truthiness test: "0" is a legitimate
             * floor and the old check threw it away, so "customers who have
             * spent nothing" was not expressible.
             */
            if (is_numeric($request->spend_min)) {
                $query->having('total_spend', '>=', (float)$request->spend_min);
            }

            if (is_numeric($request->spend_max)) {
                $query->having('total_spend', '<=', (float)$request->spend_max);
            }

            if ($request->registration) {
                $query->whereDate('users.created_at', '>=', $request->registration);
            }

            if (is_numeric($request->points)) {
                $query->having('total_points', '>=', (float)$request->points);
            }

            /*
             * "Orders Last 30d" was a field on the loyalty group filter panel
             * that the API had no handler for, so the browser sent it and the
             * backend silently ignored it: the admin filtered, the list did not
             * change, and there was nothing to say why.
             */
            if (is_numeric($request->orders)) {
                $query->having('orders_last_30d', '>=', (int)$request->orders);
            }

            $query->orderBy($request->orderby ?? 'users.created_at', $request->type ?? 'desc');

            /*
             * limit=all is what the loyalty group member picker asks for.
             *
             * It used to run the whole query with get()->count() and then run
             * it a second time through paginate(), and when the filters matched
             * nobody it handed paginate() a page size of 0 -- a division by
             * zero inside the paginator. Counting without materialising the
             * rows, and never going below 1, fixes both.
             */
            if ($limit === 'all') {
                $limit = max(1, $query->toBase()->getCountForPagination());
            }

            $limit = $limit ?? Config::get('constants.api.PAGINATION');

            $data = $query->paginate($limit);

            $ids = [];

            foreach ($data as $item) {

                $ids[] = $item->id;

                /*
                 * The same object the storefront reads, so a customer and the
                 * admin looking at them are never told different things about
                 * the state of the account.
                 */
                $item['account'] = $item->accountSummary();

                if ($request->time_zone) {
                    $item['created'] = Utils::formatDate(
                        Utils::convertTimeToUSERzone($item->created_at, $request->time_zone)
                    );
                } else {
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }


            User::whereIn('id', $ids)->where('viewed', false)->update([
                'viewed' => true
            ]);

            return response()->json(new Response($request->token, $data));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }




    /**
     * One customer in full, including the business/trade details.
     *
     * The admin could see that an account said "business" and nothing else —
     * not the company name it registered, not its VAT number, not whether
     * anyone had checked them. Approving a trade application without being
     * able to read the application is not a workflow.
     */
    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            $user = User::with('businessProfile')->find($id);

            if (is_null($user)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $user['account'] = $user->accountSummary();
            $user['created'] = Utils::formatDate($user->created_at);

            /*
             * Everything the customer submitted, grouped by the account type
             * they chose. The screen that decides whether to approve a trade
             * application had nothing to review: it could see the company name
             * and nothing else the applicant filled in.
             */
            $user['registration'] = $user->registrationProfile();

            /*
             * Delivery addresses are not part of registration — the customer
             * adds them later — but a reviewer looking at "where is this
             * company" should not have to open another screen to find out.
             */
            $user['delivery_addresses'] = UserAddress::where('user_id', $user->id)
                ->orderByDesc('default')
                ->get();

            /*
             * Every self-serve change of account type, newest first: the
             * record of how a personal sign-up came to be a trade account.
             */
            $user['upgrade_requests'] = $user->upgradeRequests()
                ->with('reviewer:id,name')
                ->orderByDesc('id')
                ->get(['id', 'user_id', 'from_type', 'to_type', 'status', 'review_note', 'reviewed_by', 'reviewed_at', 'created_at']);

            return response()->json(new Response($request->token, $user));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Suspend or reactivate a customer account.
     *
     * Behind user.edit, the permission that already governs changing a
     * customer — suspension stops someone spending money, so it is not a
     * lesser act than editing their name.
     *
     * Note what this does not do: it never touches account_type or
     * trade_status. Suspension is a separate axis, and an account that comes
     * back from suspension is the same kind of customer it was before, with
     * the same trade decision still recorded against it. Pricing follows
     * automatically because User::hasBusinessPricing() reads the status.
     */
    public function status(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'user.edit')) {
                return $can;
            }

            $user = User::find($id);

            if (is_null($user)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $note = $request->input('status_note');

            if ($error = AccountStatus::applyAccount($user, $request->input('status'), $note)) {
                return response()->json(Validation::error($request->token, $error));
            }

            $user = User::with('businessProfile')->find($id);
            $user['account'] = $user->accountSummary();

            return response()->json(new Response($request->token, $user));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Record whether a business account's company details have been checked.
     *
     * Trade accounts get this for free as part of approving the application
     * (AccountStatus::applyTrade). Business accounts have no application to
     * approve — they buy at business rates from day one — so this is the only
     * place their VAT and company numbers can be marked as looked at.
     */
    public function verification(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'user.edit')) {
                return $can;
            }

            $allowed = [
                User::VERIFICATION_PENDING,
                User::VERIFICATION_VERIFIED,
                User::VERIFICATION_REJECTED
            ];

            $status = $request->input('verification_status');

            if (!in_array($status, $allowed, true)) {
                return response()->json(Validation::error($request->token,
                    'Choose verified, rejected or pending.'
                ));
            }

            $user = User::find($id);

            if (is_null($user)) {
                return response()->json(Validation::noDataLang($lang));
            }

            if ($user->account_type === 'personal') {
                return response()->json(Validation::error($request->token,
                    'Personal accounts have no company details to verify.'
                ));
            }

            $user->forceFill(['verification_status' => $status])->save();

            $user = User::with('businessProfile')->find($id);
            $user['account'] = $user->accountSummary();

            return response()->json(new Response($request->token, $user));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function deleteUser(Request $request)

    {
        try {

            $id = Auth::user()->id;

            $lang = $request->header('language');

            $user = User::find($id);

            if (is_null($user)) {
                return response()->json(Validation::noDataLang($lang));
            }

            Cart::where('user_id', $id)->delete();

            UserWishlist::where('user_id', $id)->delete();

            CompareList::where('user_id', $id)->delete();

            // Ordered products delete
            $orderedProducts = OrderedProduct::leftJoin('orders', 'ordered_products.order_id', '=', 'orders.id')
                ->where('orders.user_id', $id);

            $orderedProducts->delete();

            // Cancellation message  delete
            $cancellation = Cancellation::leftJoin('orders', 'cancellations.order_id', '=', 'orders.id')
                ->where('orders.user_id', $id);

            $cancellation->delete();

            Order::where('user_id', $id)->delete();

            // Review delete
            $reviewImages = ReviewImage::leftJoin('rating_reviews', 'review_images.rating_review_id', '=', 'rating_reviews.id')
                ->where('rating_reviews.user_id', $id);

            $rimages = $reviewImages->get();
            foreach ($rimages as $img) {
                FileHelper::deleteFile($img->image);
            }

            $reviewImages->delete();

            RatingReview::where('user_id', $id)->delete();


            // Address delete
            UserAddress::where('user_id', $id)->delete();

            if ($user->delete()) {
                return response()->json(new Response(
                    $request->token, $user, 200, __('lang.acc_deleted')
                ));
            }

            return response()->json(Validation::error($request->token, null, 'form', $lang));

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
                $user = User::find($i);

                if (is_null($user)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                Cart::where('user_id', $i)->delete();

                UserWishlist::where('user_id', $i)->delete();

                CompareList::where('user_id', $i)->delete();

                // Ordered products delete
                $orderedProducts = OrderedProduct::leftJoin('orders', 'ordered_products.order_id', '=', 'orders.id')
                    ->where('orders.user_id', $i);

                $orderedProducts->delete();

                // Cancellation message  delete
                $cancellation = Cancellation::leftJoin('orders', 'cancellations.order_id', '=', 'orders.id')
                    ->where('orders.user_id', $i);

                $cancellation->delete();

                Order::where('user_id', $i)->delete();

                // Review delete
                $reviewImages = ReviewImage::leftJoin('rating_reviews', 'review_images.rating_review_id', '=', 'rating_reviews.id')
                    ->where('rating_reviews.user_id', $i);

                $rimages = $reviewImages->get();
                foreach ($rimages as $img) {
                    FileHelper::deleteFile($img->image);
                }

                $reviewImages->delete();

                RatingReview::where('user_id', $i)->delete();


                // Address delete
                UserAddress::where('user_id', $i)->delete();

                $user->delete();

            }


            return response()->json(new Response($request->token, true));

           // return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function login(Request $request)
    {
        try {
            $lang = $request->header('language');


            $validator = Validation::admin_login($request);
            if ($validator)
                return response()->json($validator);

            if ($request->input('token')) {
                $this->auth->setToken($request->input('token'));

                $user = $this->auth->authenticate();
                if ($user) {

                    // Signing in by presenting an existing token is still
                    // signing in, so it answers to the same gate.
                    if ($refusal = $this->authenticationRefusal($user)) {
                        return $refusal;
                    }

                    return response()->json(new Response($request->input('token'), $request->user()));
                }
            }

            $user = User::where('email', request('email'))->first();

            /*
             * An account that never confirmed its email is treated as if it
             * were not there at all — same message as a wrong password or an
             * unknown address, so the response can't be used to discover which
             * addresses are registered but unverified.
             */
            if ($user && !$user->verified) {
                $user = null;
            }

            $password_check = Validation::password_check($user, request('password'));
            if ($password_check) {
                return response()->json($password_check);
            }

            /*
             * Right password, but the account is not allowed to be used. This
             * sits after the password check on purpose: answering "suspended"
             * to a wrong password would tell anyone who guessed an address
             * which accounts exist and what state they are in.
             */
            if ($refusal = $this->authenticationRefusal($user)) {
                return $refusal;
            }

            /*
             * Password was right, but that is only the first factor. Hand back
             * a challenge instead of a session and make them prove the second.
             *
             * Nothing is signed in before this returns. The web guard used to
             * be logged in above this check, which left a password alone
             * enough to open a session guard — the API authorises on the
             * passport guard, so it was not reachable, but it is the kind of
             * gap that stops being theoretical the first time a web-guard
             * route is added.
             */
            if ($user->twoFactorEnabled()) {
                return response()->json(
                    new Response($request->token, $this->startTwoFactorChallenge($request, $user))
                );
            }

            Auth::guard('web')->login($user);

            return response()->json(
                new Response($request->token, $this->issueUserSession($request, $user))
            );


        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }

    }

    /**
     * Open a 2FA challenge, texting a code first if that is their method.
     */
    private function startTwoFactorChallenge(Request $request, $user)
    {
        $challenge = TwoFactorChallenge::issueFor($user, $request->ip());

        if ($user->two_factor_method === 'sms') {
            app(TwoFactorController::class)->issueSmsCode($user, $request, app(TwilioService::class));
        }

        return [
            'two_factor_required' => true,
            'challenge' => $challenge,
            'method' => $user->two_factor_method,
            'expires_in_minutes' => TwoFactorChallenge::LIFETIME_MINUTES
        ];
    }

    /**
     * Second factor accepted -> issue the session. Separate endpoint so the
     * client can log in through it exactly as it would with a password.
     */
    public function verifyTwoFactor(Request $request)
    {
        try {
            $challenge = TwoFactorChallenge::findUsable($request->input('challenge'));

            if (!$challenge) {
                return response()->json(Validation::error(null,
                    'That sign-in attempt has expired. Enter your password again.'
                ));
            }

            $user = $challenge->user;

            if (!$user || !$user->twoFactorEnabled()) {
                return response()->json(Validation::error(null,
                    'That sign-in attempt has expired. Enter your password again.'
                ));
            }

            $code = trim((string)$request->input('code'));

            $passed = $user->two_factor_method === 'sms'
                ? (app(TwoFactorController::class)->consumeSmsCode($user->email, $code)
                    || TwoFactorController::consumeRecoveryCode($user, $code))
                : TwoFactorController::passesChallenge($user, $code);

            if (!$passed) {

                $challenge->increment('attempts');

                return response()->json(Validation::error(null,
                    "That code isn't right. Try again or use a recovery code."
                ));
            }

            // Suspension can land between the password and the second factor.
            if ($refusal = $this->authenticationRefusal($user)) {
                return $refusal;
            }

            $challenge->update(['used_at' => now()]);

            Auth::guard('web')->login($user);

            return response()->json(
                new Response($request->token, $this->issueUserSession($request, $user))
            );

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Mint the access token, record the device session and pull any guest
     * cart / orders / addresses onto the account. Shared by password sign-in
     * and by the emailed sign-in code, so both hand back the same payload.
     */
    /**
     * Refuse to sign in a blocked account, in the form the callers return.
     *
     * Every way into a session calls this before minting anything. The check
     * itself lives in AccountStatus so sign-in, the second factor, the emailed
     * code and signup verification cannot drift apart on what "suspended"
     * means.
     */
    private function authenticationRefusal($user)
    {
        $blocked = AccountStatus::authenticationBlock($user);

        return $blocked
            ? response()->json(Validation::error(null, $blocked))
            : null;
    }

    private function issueUserSession(Request $request, $user)
    {
        /*
         * A last gate rather than the only one: the callers refuse first and
         * report properly. This is here so a fifth way into a session cannot
         * be added without the check, and it throws the customer-facing
         * sentence so even the generic handler says something sensible.
         */
        if ($blocked = AccountStatus::authenticationBlock($user)) {
            // Nothing has been minted at this point, so there is nothing to undo.
            throw new \RuntimeException($blocked);
        }

        /*
         * How long this sign-in lasts: 30 days when the customer ticked "Keep
         * me signed in", otherwise a day.
         *
         * Both branches used to say twelve months, and the flag was read as
         * remember_token while the login page sends remember_me - so every
         * sign-in, ticked or not, stayed alive for a year. A browser signed in
         * weeks earlier went on showing that account's basket to someone who
         * believed they were browsing as a guest. remember_token is still
         * accepted for any older client that sends it.
         */
        $remember = $request->boolean('remember_me') || $request->boolean('remember_token');

        $data['expires_in'] = $remember
            ? Carbon::now()->addDays(Config::get('constants.auth.EXPIRATION_IN_DAYS'))
            : Carbon::now()->addHours(Config::get('constants.auth.SESSION_HOURS'));

        $data['remembered'] = $remember;

        Passport::personalAccessTokensExpireIn($data['expires_in']);

        // Passport reads that lifetime once, when it first builds its token
        // server, and keeps the server for the rest of the request. Dropping
        // it makes the lifetime just set the one this token gets, even if
        // something earlier in the request had already built the server.
        app()->forgetInstance(\League\OAuth2\Server\AuthorizationServer::class);

        // Token minted from the user we were handed, not from the active guard,
        // so this works the same whichever guard the request came in on.
        $tokenResult = $user->createToken('user', ['user']);

        $data['token'] = $tokenResult->accessToken;

        $agent = new Agent();

        $sessionId = Str::uuid();

        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'token_id' => $tokenResult->token->id,
            'device_name' => $agent->device(),
            'device_type' => $agent->isMobile()
                ? 'Mobile'
                : 'Desktop',
            'browser' => $agent->browser(),
            'platform' => $agent->platform(),
            'ip_address' => $request->ip(),
            'last_activity' => now(),
        ]);

        $data['session_id'] = $sessionId;

        $userArr['id'] = $user->id;
        $userArr['name'] = $user->name;
        $userArr['email'] = $user->email;


        if ($request->user_token) {

            /*
             * The basket hand-over is its own thing now — see CartMerge. It has
             * to lock, cap and re-price, none of which a loop in the middle of
             * session creation was doing, and the storefront needs to be able
             * to ask for it again on its own (social sign-in comes back on a
             * redirect, with no chance to send a token here).
             */
            CartMerge::forUser($user, $request->user_token);

            Order::whereNull('user_id')
                ->where('user_token', $request->user_token)
                ->update([
                    'user_id' => $user->id,
                    'user_token' => null
                ]);

            UserAddress::whereNull('user_id')
                ->where('user_token', $request->user_token)
                ->update([
                    'user_id' => $user->id,
                    'user_token' => null
                ]);

            Cancellation::whereNull('user_id')
                ->where('user_token', $request->user_token)
                ->update([
                    'user_id' => $user->id,
                    'user_token' => null
                ]);

            /*
             * The guest wishlist follows the shopper into their account, the
             * same way the basket does. Rows for a product they had already
             * wishlisted while signed in are dropped rather than adopted:
             * user_wishlists holds one row per owner and product.
             */
            $guestWishlists = UserWishlist::whereNull('user_id')
                ->where('user_token', $request->user_token)
                ->get();

            foreach ($guestWishlists as $guestWishlist) {

                $alreadyWishlisted = UserWishlist::where('user_id', $user->id)
                    ->where('product_id', $guestWishlist->product_id)
                    ->exists();

                if ($alreadyWishlisted) {

                    $guestWishlist->delete();

                } else {

                    $guestWishlist->update([
                        'user_id' => $user->id,
                        'user_token' => null
                    ]);
                }
            }

            // Anything the guest put aside for later comes with them too.
            $guestSaved = SaveForLater::whereNull('user_id')
                ->where('user_token', $request->user_token)
                ->get();

            foreach ($guestSaved as $saved) {

                $alreadySaved = SaveForLater::where('user_id', $user->id)
                    ->where('product_id', $saved->product_id)
                    ->where('inventory_id', $saved->inventory_id)
                    ->exists();

                if ($alreadySaved) {

                    $saved->delete();

                } else {

                    $saved->update([
                        'user_id' => $user->id,
                        'user_token' => null
                    ]);
                }
            }
        }


        $userArr['cart_count'] = Cart::where('user_id', $user->id)->inBasket()
            ->sum('quantity');

        $data['user'] = $userArr;

        return $data;
    }

    /**
     * Step one of passwordless sign-in: email the customer a 6-digit code.
     */
    public function sendLoginCode(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validator = Validation::email_verification($request);
            if ($validator) {
                return response()->json($validator);
            }

            // Unverified accounts answer the same way as unknown ones.
            $user = User::where('email', request('email'))->verified()->first();

            if (!$user) {
                return response()->json(Validation::error(null,
                    __('lang.not_exists', [], $lang)
                ));
            }

            $recent = LoginCode::where('email', $user->email)
                ->forPurpose(LoginCode::PURPOSE_LOGIN)
                ->where('created_at', '>', now()->subMinutes(LoginCode::THROTTLE_MINUTES))
                ->count();

            if ($recent >= LoginCode::MAX_PER_WINDOW) {
                return response()->json(Validation::error(null,
                    'Too many sign-in codes requested. Try again later or sign in with your password.'
                ));
            }

            // Any code already outstanding for this address stops working.
            LoginCode::where('email', $user->email)
                ->forPurpose(LoginCode::PURPOSE_LOGIN)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $request->merge(['name' => $user->name]);

            try {
                // Subject left to codeSender, which now gives each flow its own
                // line; registration, reset and sign-in all used to share
                // "You are almost there."
                $code = MailHelper::codeSender($request, 'login_code', null, $lang);

            } catch (\Exception $ex) {
                // Don't hand SMTP internals back to the browser.
                return response()->json(Validation::error(null,
                    'We could not send the code just now. Try again, or sign in with your password.'
                ));
            }

            LoginCode::create([
                'email' => $user->email,
                'purpose' => LoginCode::PURPOSE_LOGIN,
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(LoginCode::LIFETIME_MINUTES),
                'ip_address' => $request->ip()
            ]);

            return response()->json(new Response(null, [
                'email' => $user->email,
                'expires_in_minutes' => LoginCode::LIFETIME_MINUTES
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Step two: swap a valid code for a signed-in session.
     */
    public function verifyLoginCode(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validator = Validation::email_verification($request);
            if ($validator) {
                return response()->json($validator);
            }

            if (!trim((string)$request->input('code'))) {
                return response()->json(Validation::error(null,
                    __('lang.code_invalid', [], $lang)
                ));
            }

            $user = User::where('email', request('email'))->first();

            if (!$user || !$user->verified) {
                return response()->json(Validation::error(null,
                    __('lang.code_invalid', [], $lang)
                ));
            }

            $loginCode = LoginCode::where('email', $user->email)
                ->forPurpose(LoginCode::PURPOSE_LOGIN)
                ->usable()
                ->latest('id')
                ->first();

            if (!$loginCode) {
                return response()->json(Validation::error(null,
                    'That code has expired. Send a new one.'
                ));
            }

            if (!Hash::check(trim($request->input('code')), $loginCode->code)) {

                $loginCode->increment('attempts');

                return response()->json(Validation::error(null,
                    __('lang.code_invalid', [], $lang)
                ));
            }

            // The emailed sign-in code is a way in like any other.
            if ($refusal = $this->authenticationRefusal($user)) {
                return $refusal;
            }

            $loginCode->update(['used_at' => now()]);

            Auth::guard('web')->login($user);

            return response()->json(
                new Response($request->token, $this->issueUserSession($request, $user))
            );

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            $lang = $request->header('language');


            $validator = Validation::update_password($request);
            if ($validator) {
                return response()->json($validator);
            }

            $existingUser = User::where('email', $request->email)
                ->first();

            if (!$existingUser) {
                return response()->json(Validation::error(null,
                    __('lang.no_email', [], $lang)
                ));
            }


            if ($existingUser->code != $request->code) {
                return response()->json(Validation::error(null,
                    __('lang.code_invalid', [], $lang)
                ));
            }

            User::where('email', $request->email)->update([
                'password' => Hash::make($request->password)
            ]);

            return response()->json(new Response($request->token, $request->email));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $lang = $request->header('language');


            $validator = Validation::email_verification($request);
            if ($validator) {
                return response()->json($validator);
            }

            $existingUser = User::where('email', request('email'))
                ->verified()
                ->first();

            if ($existingUser) {

                $recent = PasswordResetToken::where('email', $existingUser->email)
                    ->where('created_at', '>', now()->subMinutes(PasswordResetToken::THROTTLE_MINUTES))
                    ->count();

                if ($recent >= PasswordResetToken::MAX_PER_WINDOW) {
                    return response()->json(Validation::error(null,
                        'Too many reset links requested. Try again later.'
                    ));
                }

                try {
                    $token = PasswordResetToken::issueFor($existingUser->email, $request->ip());

                    $request->merge(['name' => $existingUser->name]);

                    MailHelper::resetLinkSender(
                        $request,
                        Utils::passwordResetLink($token, $existingUser->email),
                        PasswordResetToken::LIFETIME_MINUTES,
                        null,
                        $lang
                    );

                    return response()->json(new Response(null, [
                        'email' => $existingUser->email,
                        'expires_in_minutes' => PasswordResetToken::LIFETIME_MINUTES
                    ]));

                } catch (\Exception $ex) {
                    return response()->json(Validation::error(null,
                        'We could not send the reset link just now. Try again shortly.'
                    ));
                }


            } else {
                /*
                 * QA audit ACC-01. This used to answer "no such account" for an
                 * unknown address, which told an attacker exactly which emails
                 * are registered. The response is now identical either way; a
                 * link is only actually sent when the account exists.
                 */
                return response()->json(new Response(null, [
                    'email' => $request->email,
                    'expires_in_minutes' => PasswordResetToken::LIFETIME_MINUTES
                ]));
            }
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Called when the reset page opens, so an expired or spent link says so
     * before the customer types a new password.
     */
    public function checkResetToken(Request $request)
    {
        try {
            $reset = PasswordResetToken::findUsable(
                $request->input('token'),
                $request->input('email')
            );

            if (!$reset) {
                return response()->json(Validation::error(null,
                    'This reset link has expired or has already been used.'
                ));
            }

            return response()->json(new Response(null, [
                'email' => $reset->email,
                'valid' => true
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Set the new password against a reset link token.
     */
    public function resetPassword(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validator = Validation::reset_password($request);
            if ($validator) {
                return response()->json($validator);
            }

            $reset = PasswordResetToken::findUsable(
                $request->input('token'),
                $request->input('email')
            );

            if (!$reset) {
                return response()->json(Validation::error(null,
                    'This reset link has expired or has already been used.'
                ));
            }

            $user = User::where('email', $reset->email)->verified()->first();

            if (!$user) {
                return response()->json(Validation::error(null,
                    __('lang.not_exists', [], $lang)
                ));
            }

            $user->update(['password' => Hash::make($request->input('password'))]);

            $reset->update(['used_at' => now()]);

            // Any other outstanding link for this address is now moot.
            PasswordResetToken::where('email', $reset->email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            /*
             * Changing the password signs every existing session out — the
             * point of a reset is usually to lock somebody else out.
             */
            $user->tokens->each(function ($token) {
                if ($token->name === 'user') $token->delete();
            });

            UserSession::where('user_id', $user->id)->update(['is_active' => false]);

            return response()->json(new Response(null, ['email' => $user->email]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function verify(Request $request)
    {
        try {
            $lang = $request->header('language');


            $validator = Validation::user_verification($request);
            if ($validator) {
                return response()->json($validator);
            }

            $existingUser = User::where('email', request('email'))
                ->first();

            /*
             * QA audit ACC-01. Same message whichever way this fails, so the
             * endpoint cannot be used to find out which addresses have accounts.
             */
            $genericFailure = response()->json(Validation::error(null,
                __('lang.code_invalid', [], $lang)
            ));

            if (!$existingUser) {
                return $genericFailure;
            }

            /*
             * The comparison was a loose ==, and there was no attempt limit
             * beyond the global 60/min throttle — a 4-digit code fell in
             * minutes. Strict comparison, five tries, then the code is burned.
             */
            if ((int)$existingUser->code_attempts >= User::MAX_CODE_ATTEMPTS) {
                User::where('id', $existingUser->id)->update([
                    'code' => null,
                    'code_expires_at' => null,
                    'code_attempts' => 0
                ]);

                return $genericFailure;
            }

            if (User::codeMatches($existingUser, request('code'))) {

                    /*
                     * Signup rejects a number that already belongs to a
                     * verified account, but two people can hold the same number
                     * as pending signups at once - both were unverified when
                     * each was checked. This is the moment one of them becomes
                     * an account, so it is the last point the pair can still be
                     * kept apart.
                     */
                    if ($existingUser->phone) {

                        $phoneTaken = User::where('phone', $existingUser->phone)
                            ->where('verified', 1)
                            ->where('id', '!=', $existingUser->id)
                            ->exists();

                        if ($phoneTaken) {
                            return response()->json(Validation::error(null,
                                'That mobile number is already on another account. Use a different number.'
                            ));
                        }
                    }

                    User::where('email', $existingUser->email)->update([
                        'verified' => true,
                        'code' => null,
                        'code_expires_at' => null,
                        'code_attempts' => 0
                    ]);

                    $existingUser = $existingUser->fresh();

                    /*
                     * Confirming an email does not override a block. Signup
                     * already refuses a suspended address, so reaching this
                     * means the account was suspended between the two steps.
                     */
                    if ($refusal = $this->authenticationRefusal($existingUser)) {
                        return $refusal;
                    }

                    /*
                     * Sign them in on the spot. The signup flow goes straight
                     * from here into optional 2FA setup, which needs a token,
                     * and it saves a fresh sign-in for everyone else.
                     */
                    Auth::guard('web')->login($existingUser);

                    $session = $this->issueUserSession($request, $existingUser);

                    return response()->json(new Response($request->token, array_merge(
                        $session,
                        ['account' => $existingUser]
                    )));
            }

            User::where('id', $existingUser->id)->increment('code_attempts');

            return $genericFailure;

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function signup(Request $request)
    {
        try {
            $lang = $request->header('language');


            // The multi-step form sends an account_type; anything without one
            // is a legacy caller and keeps the original, looser rules.
            $validator = $request->filled('account_type')
                ? Validation::user_registration($request)
                : Validation::user_signup($request);

            if ($validator) {
                return response()->json($validator);
            }

            $existingUser = User::where('email', request('email'))
                ->first();

            if ($existingUser && $existingUser->verified) {
                return response()->json(Validation::error(null,
                    __('lang.email_verified', [], $lang)
                ));
            }

            /*
             * Signing up again over an unverified row is how somebody who lost
             * their code starts over, and the payload below overwrites that row
             * wholesale. A suspended or rejected account must not be launderable
             * that way: without this, the cheapest route back to an active
             * account is to re-register the same address.
             */
            if ($existingUser && !$existingUser->isActive()) {
                return response()->json(Validation::error(null,
                    'We cannot open an account for this email address. Please contact us.'
                ));
            }

            $request['password'] = Hash::make(request('password'));


            $request['code'] = MailHelper::codeSender($request, null,
                __('lang.account_registration', [], $lang),
                $lang
            );

            $accountType = in_array($request->input('account_type'), User::ACCOUNT_TYPES, true)
                ? $request->input('account_type')
                : 'personal';

            /*
             * Built field by field rather than from $request->all(): `verified`
             * and `trade_status` are fillable, so passing the raw request would
             * let a signup mark itself verified or trade-approved.
             */
            $payload = [
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'password' => $request['password'],
                'code' => $request['code'],
                // QA audit ACC-01: verification codes now expire.
                'code_expires_at' => now()->addMinutes(User::CODE_LIFETIME_MINUTES),
                'code_attempts' => 0,
                'account_type' => $accountType,
                'verified' => false,
                'trade_status' => $accountType === 'trade' ? User::TRADE_PENDING : null,
                /*
                 * Every account opens active. Business and trade customers can
                 * buy from the moment their email is confirmed — a trade account
                 * simply buys at retail until its application is approved, which
                 * is what trade_status tracks. Opening them suspended instead
                 * would mean nobody could order over a weekend.
                 */
                'status' => User::STATUS_ACTIVE,
                'status_note' => null,
                'status_changed_at' => now(),
                /*
                 * A personal account has no company details, so there is nothing
                 * to verify — a different state from "not checked yet", and the
                 * admin queue is filtered on the difference.
                 */
                'verification_status' => $accountType === 'personal'
                    ? User::VERIFICATION_NOT_REQUIRED
                    : User::VERIFICATION_PENDING,
                'pref_email_offers' => $request->boolean('pref_email_offers'),
                'pref_sms_offers' => $request->boolean('pref_sms_offers'),
                'pref_recommendations' => $request->boolean('pref_recommendations'),
                'pref_trade_list' => $accountType === 'trade' && $request->boolean('pref_trade_list')
            ];

            if (!$existingUser) {
                $user = User::create($payload);

            } else {
                // Re-registering an address that never got verified.
                $existingUser->update($payload);
                $user = $existingUser->fresh();
            }

            if (in_array($accountType, ['business', 'trade'], true)) {

                UserBusinessProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => $request->input('company_name'),
                        'vat_number' => $request->input('vat_number'),
                        'cro_number' => $request->input('cro_number'),
                        'billing_address' => $request->input('billing_address'),
                        'sector' => $accountType === 'business' ? $request->input('sector') : null,
                        'requires_po' => $accountType === 'business' && $request->boolean('requires_po'),
                        'credit_application' => $accountType === 'business' && $request->boolean('credit_application'),
                        'trade' => $accountType === 'trade' ? $request->input('trade') : null,
                        'expected_spend' => $accountType === 'trade' ? $request->input('expected_spend') : null,
                        'website' => $accountType === 'trade' ? $request->input('website') : null,
                        'resale_confirmed' => $accountType === 'trade' && $request->boolean('resale_confirmed')
                    ]
                );

            } else {
                // Switched back to personal: drop any profile from a previous try.
                UserBusinessProfile::where('user_id', $user->id)->delete();
            }

            return response()->json(new Response(null, $request->email));

        } catch (\Exception $ex) {
            return response()->json(Validation::error(null, explode('.', $ex->getMessage())[0]));
        }
    }


    public function logout(Request $request)
    {
        try {
            $lang = $request->header('language');


            Auth::user()->tokens->each(function ($token, $key) {
                if ($token->name === "user") $token->delete();
            });
            return Validation::success($request,
                __('lang.logged_out', [], $lang)
            );
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function updateProfile(Request $request)
    {
        try {
            $lang = $request->header('language');


            $user = Auth::user();

            $validator = Validation::userProfile($request, $user);
            if ($validator) {
                return response()->json($validator);
            }

            if ($request->hasFile('image')) {

                $file = $request->file('image');
                $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                $file->move(base_path('uploads'), $filename);

                $user->image = $filename;
            }

            $user->name = $request->name;

            if ($request->has('phone')) {

                /*
                 * A new number has not been proved, whatever the old one had
                 * been. Without this, verifying one number and then editing
                 * in another leaves the account claiming a number it has
                 * never sent anything to — and if SMS is the second factor,
                 * pointing that factor at an unproved phone.
                 */
                if ((string)$user->phone !== (string)$request->phone) {
                    $user->phone_verified_at = null;

                    /*
                     * SMS 2FA cannot stand on a number nobody has proved, so
                     * it goes back to unconfigured rather than silently
                     * texting codes somewhere new. An authenticator app is
                     * unaffected — it has nothing to do with the number.
                     */
                    if ($user->two_factor_method === 'sms') {
                        $user->two_factor_method = null;
                        $user->two_factor_confirmed_at = null;
                        $user->two_factor_recovery_codes = null;
                    }
                }

                $user->phone = $request->phone;
            }

            /*
             * Marketing preferences.
             *
             * Only keys actually present are touched. An unchecked box posts
             * nothing at all, so reading them with boolean() unconditionally
             * would silently switch every preference off whenever a form that
             * does not carry them — the name-and-phone panel — is saved.
             *
             * pref_trade_list is deliberately absent from this list: it is
             * offered to trade accounts only, and is handled below.
             */
            foreach (['pref_email_offers', 'pref_sms_offers', 'pref_recommendations'] as $preference) {

                if ($request->has($preference)) {
                    $user->{$preference} = $request->boolean($preference);
                }
            }

            if ($user->account_type === 'trade' && $request->has('pref_trade_list')) {
                $user->pref_trade_list = $request->boolean('pref_trade_list');
            }

            /*
             * Company details, for the account types that have them.
             *
             * account_type, trade_status, status, verification_status and
             * resale_confirmed are all absent by design. They are staff
             * decisions or one-time declarations, and accepting any of them
             * here would let a customer approve their own trade account.
             */
            if (in_array($user->account_type, ['business', 'trade'], true)) {
                $this->saveBusinessProfile($request, $user);
            }

            $user->save();

            return Validation::success($request, __('lang.profile_updated', [], $lang), [
                'name' => $user->name,
                'phone' => $user->phone,
                'image' => $user->image,
                // Echoed back so the page can re-render from the saved truth
                // rather than from what it hoped it had sent.
                'account' => $user->accountSummary(),
                'business_profile' => $user->fresh()->businessProfile,
                'preferences' => [
                    'pref_email_offers' => (bool)$user->pref_email_offers,
                    'pref_sms_offers' => (bool)$user->pref_sms_offers,
                    'pref_recommendations' => (bool)$user->pref_recommendations,
                    'pref_trade_list' => (bool)$user->pref_trade_list,
                ]
            ]);

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    /**
     * Write the editable half of a business/trade profile.
     *
     * Only fields present in the request are touched, so the marketing panel
     * saving on its own cannot blank a company's VAT number.
     *
     * `trade` and `expected_spend` describe the application rather than the
     * company, and `resale_confirmed` is the declaration the applicant made
     * at the time — none of the three is editable afterwards, because they
     * are what staff reviewed when they approved the account.
     */
    private function saveBusinessProfile(Request $request, $user)
    {
        $editable = ['company_name', 'vat_number', 'cro_number', 'billing_address'];

        // Sector is a business-account field; a trade account describes
        // itself through `trade`, which is fixed at application time.
        if ($user->account_type === 'business') {
            $editable[] = 'sector';
        }

        if ($user->account_type === 'trade') {
            $editable[] = 'website';
        }

        $profile = UserBusinessProfile::firstOrNew(['user_id' => $user->id]);

        /*
         * Changing who the company *is* invalidates whatever staff checked.
         * Noted before the write, while the stored values are still the old
         * ones.
         */
        $identityChanged = false;

        foreach ($editable as $field) {

            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);
            $value = is_string($value) ? trim($value) : $value;
            $value = ($value === '') ? null : $value;

            if (in_array($field, ['company_name', 'vat_number', 'cro_number'], true)
                && $profile->{$field} !== $value) {

                $identityChanged = true;
            }

            $profile->{$field} = $value;
        }

        /*
         * PO numbers and credit are a business-account arrangement.
         *
         * requires_po is the customer's own preference and is theirs to set.
         * credit_application is only ever raised here — it records that they
         * asked for a 30-day account, never that it was granted. Approval is
         * a staff decision and lives outside this request entirely, so an
         * application already decided is not reopened by a profile save.
         */
        /*
         * The PO rule is honoured at checkout for any non-personal account,
         * and a business approved for trade keeps it - so a trade account has
         * to be able to switch it off again.
         */
        if ($request->has('requires_po')) {
            $profile->requires_po = $request->boolean('requires_po');
        }

        if ($user->account_type === 'business') {

            if ($request->has('credit_application') && !$profile->credit_application) {
                $profile->credit_application = $request->boolean('credit_application');
            }
        }

        $profile->user_id = $user->id;
        $profile->save();

        /*
         * Send the account back to the review queue when its identifying
         * details move. Left alone if it was never verified in the first
         * place, so this cannot promote a rejected account to pending.
         */
        if ($identityChanged && $user->verification_status === User::VERIFICATION_VERIFIED) {
            $user->verification_status = User::VERIFICATION_PENDING;
        }
    }


    public function updateUserPassword(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validator = Validation::updateUserPassword($request);
            if ($validator) {
                return response()->json($validator);
            }

            $user = User::where('id', Auth::user()->id)->first();

            $password_check = Validation::password_check($user, $request->current_password,
                __('lang.Password_matched', [], $lang)
            );
            if ($password_check) {
                return response()->json($password_check);
            }

            User::where('id', Auth::user()->id)->update([
                'password' => Hash::make($request->new_password)
            ]);

            return Validation::success($request,
                __('lang.password_updated', [], $lang)
            );
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function profile(Request $request)
    {
        try {
            $lang = $request->header('language');

            if ($request->user('user')) {
                $user = $request->user('user');
                $user['cart_count'] = Cart::where('user_id', $user->id)->inBasket()->sum('quantity');
                $user['is_logged_in'] = true;
                unset($user['code']);

                /*
                 * Account type, status, verification and trade standing, decided
                 * server-side. The storefront reads this one object rather than
                 * inferring entitlement from account_type — it was doing the
                 * latter nowhere at all, which is why a pending trade customer
                 * saw no sign their application existed.
                 */
                $user['account'] = $user->accountSummary();
                $user['business_profile'] = $user->businessProfile;

            } else if ($request->user_token) {

                $setting = Setting::select('guest_checkout')->first();
                if(!$setting->guest_checkout){
                    return response()->json(Validation::unauthorized());
                }

                $user = GuestUser::where('user_token', $request->user_token)->first();
                $user['cart_count'] = Cart::where('user_token', $request->user_token)->inBasket()->sum('quantity');
                $user['is_logged_in'] = false;

                /*
                 * A guest is a personal shopper with no account behind it.
                 * Saying so explicitly stops the storefront having to treat a
                 * missing key as "not entitled" and getting it wrong.
                 */
                $user['account'] = [
                    'account_type' => 'personal',
                    'status' => 'active',
                    'status_note' => null,
                    'verification_status' => 'not_required',
                    'trade_status' => null,
                    'trade_review_note' => null,
                    'has_business_pricing' => false,
                    'can_checkout' => true,
                    'headline' => null,
                ];

            } else {
                return response()->json(Validation::nothingFoundLang($lang, 201));
            }

            return response()->json(new Response($request->token, $user));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function addressAction(Request $request)
    {
        try {
            $lang = $request->header('language');

            $validate = Validation::user_address($request);
            if ($validate) {
                return response()->json($validate);
            }

            if (!$request->name) {
                $request['name'] = trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? ''));
            }

            if ($request->default == 1) {
                if ($request->user('user')) {
                    UserAddress::where('user_id', $request->user('user')->id)
                        ->update(['default' => 0]);
                } else if ($request->user_token) {
                    UserAddress::where('user_token', $request->user_token)
                        ->update(['default' => 0]);
                }
            }

            $userAddress = null;


            if ($request->user_token && !$request->user('user')) {

                $guestUser = GuestUser::where('user_token', $request->user_token)
                    ->first();

                if (!$guestUser) {

                    GuestUser::create([
                        'user_token' => $request->user_token,
                        'name' => $request->name,
                        'email' => $request->email
                    ]);

                } else {

                    GuestUser::where('id', $guestUser->id)
                        ->update([
                            'name' => $request->name,
                            'email' => $request->email
                        ]);
                }
            }

            if($request->user('user')){
                $request['user_token'] = null;
            }

            if ($request->id) {

                $query = UserAddress::query();

                if ($request->user('user')) {




                    if($request->user_id && $request->user_id != -1){
                        $query = $query->where('user_id', $request->user_id);
                    } else {

                        if(Auth::user() && !empty(Auth::user()->roles) && count(Auth::user()->roles) > 0 && Auth::user()->roles[0]->guard_name == 'admin'){

                        } else {
                            $query = $query->where('user_id', $request->user('user')->id);
                        }

                    }

                } else if ($request->user_token) {

                    $query = $query->where('user_token', $request->user_token);

                } else {
                    return response()->json(Validation::errorLang($lang));
                }

                $userAddress = $query->first();
            }

            $message = '';

            if (is_null($userAddress)) {

                if ($request->user('user')) {

                    if(!$request->user_id || $request->user_id == -1){




                        if(Auth::user() && !empty(Auth::user()->roles) && count(Auth::user()->roles) > 0 && Auth::user()->roles[0]->guard_name == 'admin'){


                            $userByEmail = User::where('email', $request->email)->first();
                            if($userByEmail) {
                                $request['user_id'] = $userByEmail->id;
                            } else {

                                $user = User::create([
                                    'email' => $request->email,
                                    'password' => '',
                                    'name' => $request->name
                                ]);

                                $request['user_id'] = $user->id;

                            }



                        } else {
                            $request['user_id'] = $request->user('user')->id;
                        }

                    }





                } else if ($request->user_token) {

                    $request['user_token'] = $request->user_token;

                } else {
                    return response()->json(Validation::errorLang($lang));
                }

                $data = $request->all();
                unset($data['first_name'], $data['last_name'], $data['user']);
                $userAddress = UserAddress::create($request->all());

                $message = __('lang.created', [], $lang);

            } else {

                $data = $request->all();
                unset($data['first_name'], $data['last_name'], $data['created'], $data['id'], $data['user']);

                $filtered = array_filter($data, function ($value) {
                    return $value !== null && $value !== '';
                });

                UserAddress::where('id', $request->id)->update($filtered);

                $userAddress = UserAddress::find($request->id);

                $message = __('lang.updated', [], $lang);
            }

            $ua = UserAddress::with('user')->where('id', $userAddress->id)->first();
            $ua->first_name = explode(' ', $ua->name)[0] ?? '';
            $ua->last_name = trim(str_replace($ua->first_name, '', $ua->name));

            return Validation::success($request,
                __('lang.address_message', ['message' => $message], $lang), $ua);

        } catch (\Exception $ex) {

            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function deleteAddress(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            $query = UserAddress::where('id', $id);

            if ($request->user('user')) {

                $query = $query->where('user_id', $request->user('user')->id);

            } else if ($request->user_token) {

                $query = $query->where('user_token', $request->user_token);

            } else {
                return response()->json(Validation::errorLang($lang));
            }

            $userAddress = $query->first();


            if (is_null($userAddress)) {
                return response()->json(Validation::nothingFoundLang($lang));
            }


            $order = Order::where('user_address_id', $id)->first();

            if ($order) {
                return response()->json(Validation::error($request->token,
                    __('lang.address_used', [], $lang)
                ));
            }


            if (UserAddress::where('id', $id)->delete()) {
                return Validation::success($request,
                    __('lang.address_message', ['message' => __('lang.deleted', [], $lang)], $lang),
                    $userAddress);
            }

            return response()->json(Validation::error($request->token, null, 'form', $lang));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function addresses(Request $request)
    {
        try {

            $lang = $request->header('language');

            $query = UserAddress::query();

            if ($request->q) {
                $query = $query->where('address_1', 'LIKE', "%{$request->q}%");
            }

            if ($request->user_id && $this->user) {

                $query = $query->where('user_id', $request->user_id);
                $query = $query->with('user');

            } else if ($request->user('user')) {

                $query = $query->where('user_id', $request->user('user')->id);
                $query = $query->with('user');

            } else if ($request->user_token) {

                $guestUser = GuestUser::where('user_token', $request->user_token)
                    ->first();

                if (!$guestUser) {
                    GuestUser::create([
                        'user_token' => $request->user_token,
                    ]);
                }


                $query = $query->where('user_token', $request->user_token);
                $query = $query->with('guest_user');

            } else {
                return response()->json(Validation::errorLang($lang));
            }


            if ($request->orderby && $request->type) {
                $query = $query->orderBy($request->orderby, $request->type);
            }

            $data = $query->paginate(100);


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

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function vouchers(Request $request)
    {
        try {

            $lang = $request->header('language');

            $currentTime = Carbon::now()->format('Y-m-d H:i:s');


            $query = Voucher::query();
            // $query = $query->where('end_time', '>=', $currentTime);
            // $query = $query->where('start_time', '<=', $currentTime);
            $query = $query->where('apply_type', 1)
                // store-wide vouchers plus this customer's own gift vouchers
                ->usableBy(optional($request->user('user'))->id)
                ->orderBy('vouchers.' . ($request->order_by ?: 'id'), $request->type ?: 'desc');
            // $query = $query->where('status', Config::get('constants.status.PUBLIC'));


            if ($lang) {
                $query = $query->leftJoin('voucher_langs as pcl', function ($join) use ($lang) {
                    $join->on('pcl.voucher_id', '=', 'vouchers.id');
                    $join->where('pcl.lang', $lang);
                });
                $query = $query->select('vouchers.*', \DB::raw('COALESCE(pcl.title, vouchers.title) as title'));


                if ($request->q) {
                    $query = $query->where('pcl.title', 'LIKE', "%{$request->q}%");
                }

            } else {

                if ($request->q) {
                    $query = $query->where('title', 'LIKE', "%{$request->q}%");
                }

            }


            $data = $query->paginate(Config::get('constants.frontend.PAGINATION'));

            foreach ($data as $item) {
                $item['is_gift_card'] = $item->isGiftCard();
                $item['gift_balance'] = $item->isGiftCard()
                    ? number_format($item->giftBalance(), 2, '.', '') : null;
            }

            if ($request->time_zone) {
                foreach ($data as $item) {
                    $item['start_time'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->start_time, $request->time_zone),
                        Config::get('constants.dateTime.ONLY_DATE'));
                    $item['end_time'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->end_time, $request->time_zone),
                        Config::get('constants.dateTime.ONLY_DATE'));
                    $item['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($item->created_at, $request->time_zone));
                }

            } else {
                foreach ($data as $item) {
                    $item['start_time'] = Utils::formatDate($item->start_time, Config::get('constants.dateTime.ONLY_DATE'));
                    $item['end_time'] = Utils::formatDate($item->end_time, Config::get('constants.dateTime.ONLY_DATE'));
                    $item['created'] = Utils::formatDate($item->created_at);
                }
            }

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
