<?php

namespace App\Http\Controllers;

use App\Models\AccountUpgradeRequest;
use App\Models\Helper\AccountUpgrade;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The customer's side of changing their own account type.
 *
 * The rules live in App\Models\Helper\AccountUpgrade; this controller checks
 * the form, the password, and reports the account back in the same shape the
 * profile endpoint uses, so the page re-renders from the saved truth.
 *
 * Like the rest of this API, refusals are HTTP 201 with the reason in the body.
 */
class AccountUpgradesController extends Controller
{
    /**
     * What this account may upgrade to, and any application it has waiting.
     */
    public function show(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            return response()->json(new Response($request->token, self::state($user)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Upgrade to business, or apply for trade.
     *
     * Checked in the order that gives the customer the most useful answer:
     * whether the move is possible at all, then the form, then the password.
     * A customer who may not upgrade is not walked through fixing a form.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            $to = $request->input('to_type');

            if ($error = AccountUpgrade::eligibilityError($user, is_string($to) ? $to : '')) {
                return response()->json(Validation::error($request->token, $error));
            }

            $rules = array_merge(
                ['password' => 'required'],
                Validation::companyRules($request, $to, $user->id)
            );

            $validator = Validation::validationMessage($request, $rules, 'form', array_merge(
                ['password.required' => 'Enter your password to confirm this change.'],
                Validation::companyMessages()
            ));

            if ($validator) {
                return response()->json($validator);
            }

            /*
             * The type decides what the account is charged and how it is
             * invoiced, so the change is confirmed the way turning off two-
             * factor is. An account with no password cannot confirm it; there
             * is no social sign-in on the storefront, but the backend routes
             * for it still exist.
             */
            if (!$user->password) {
                return response()->json(Validation::error($request->token,
                    'Please set a password on your account before changing its type.'
                ));
            }

            if (!Hash::check((string)$request->input('password'), $user->password)) {
                // Against the field, like the form's own errors, so the page
                // can put it under the password box.
                $message = 'That password is not right.';

                return response()->json(new Response($request->token, [
                    'form' => [$message],
                    'fields' => ['password' => [$message]],
                ], 201, $message));
            }

            $details = $request->only(AccountUpgrade::DETAIL_FIELDS[$to]);

            $upgrade = null;

            $error = $to === 'business'
                ? AccountUpgrade::upgradeToBusiness($user, $details, $upgrade)
                : AccountUpgrade::applyForTrade($user, $details, $upgrade);

            if ($error) {
                return response()->json(Validation::error($request->token, $error));
            }

            /*
             * Told by email as well: a business upgrade is a change to what the
             * account is charged, so it doubles as a "was this you?"; a trade
             * application gets a receipt. Neither send can fail the request -
             * the change is already made.
             */
            $lang = $request->header('language');

            if ($to === 'business') {
                MailHelper::accountUpgradedToBusiness($user->fresh(), $lang);
            } else {
                MailHelper::tradeApplicationReceived($user, $upgrade, $lang);
            }

            $message = $to === 'business'
                ? 'Your account is now a business account. Business pricing is on.'
                : 'Thanks, your trade application is with us. We will email you once it has been reviewed.';

            return Validation::success($request, $message, self::state($user->fresh()));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Cancel a trade application nobody has decided yet.
     */
    public function withdraw(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            if ($error = AccountUpgrade::withdraw($user)) {
                return response()->json(Validation::error($request->token, $error));
            }

            return Validation::success($request, 'Your trade application has been withdrawn.', self::state($user->fresh()));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Everything the upgrade screen, the profile and the dashboard need.
     *
     * `account` and `business_profile` are the same objects the profile
     * endpoint returns, so a page can merge them straight in after a change.
     *
     * Kept out of User::accountSummary() on purpose: that is serialised for
     * every row of the admin customer and trade lists, and would cost a query
     * per row there for something only this screen shows.
     */
    public static function state(User $user)
    {
        $pending = AccountUpgrade::pendingFor($user);

        $lastDecision = AccountUpgradeRequest::where('user_id', $user->id)
            ->whereIn('status', [AccountUpgradeRequest::STATUS_APPROVED, AccountUpgradeRequest::STATUS_REJECTED])
            ->latest('id')
            ->first();

        return [
            'options' => AccountUpgrade::options($user),
            'pending' => $pending ? self::present($pending, true) : null,
            'last_decision' => $lastDecision ? self::present($lastDecision, false) : null,
            'account' => $user->accountSummary(),
            'business_profile' => $user->businessProfile,
        ];
    }

    /**
     * One request as the customer may see it. The reviewer's identity is
     * staff business; the note is written to the customer, as the trade
     * review note already is.
     */
    private static function present(AccountUpgradeRequest $upgrade, $withDetails)
    {
        return array_filter([
            'id' => $upgrade->id,
            'from_type' => $upgrade->from_type,
            'to_type' => $upgrade->to_type,
            'status' => $upgrade->status,
            'submitted_at' => optional($upgrade->created_at)->toIso8601String(),
            'reviewed_at' => optional($upgrade->reviewed_at)->toIso8601String(),
            'review_note' => $upgrade->review_note,
            'details' => $withDetails ? $upgrade->details : null,
        ], function ($value, $key) {
            return !is_null($value) || in_array($key, ['reviewed_at', 'review_note'], true);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
