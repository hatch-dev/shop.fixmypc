<?php

namespace App\Http\Controllers;

use App\Models\AccountUpgradeRequest;
use App\Models\Helper\AccountStatus;
use App\Models\Helper\AccountUpgrade;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Trade accounts sign up at retail pricing and wait for a person to look at
 * them. This is where staff do that.
 */
class TradeApplicationsController extends ControllerHelper
{
    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            $query = User::with('businessProfile')
                ->where('account_type', 'trade');

            // Default to the ones still waiting, since that is the job.
            $status = $request->input('status', User::TRADE_PENDING);

            if ($status && $status !== 'all') {

                if (!in_array($status, AccountStatus::tradeStatuses(), true)) {
                    return response()->json(Validation::error($request->token,
                        'Unknown trade status filter.'
                    ));
                }

                /*
                 * Applications predating the trade_status column carry NULL,
                 * and "pending" is what they are — without this they were
                 * invisible in every tab, which is the one state a review
                 * queue must never have.
                 */
                if ($status === User::TRADE_PENDING) {
                    $query->where(function ($q) {
                        $q->where('trade_status', User::TRADE_PENDING)
                            ->orWhereNull('trade_status');
                    });
                } else {
                    $query->where('trade_status', $status);
                }
            }

            if ($request->q) {
                $query->where(function ($q) use ($request) {
                    $q->where('users.email', 'LIKE', "%{$request->q}%")
                        ->orWhere('users.name', 'LIKE', "%{$request->q}%");
                });
            }

            $query->orderBy('users.created_at', 'DESC');

            $data = $query->paginate(
                $request->limit ?? Config::get('constants.api.PAGINATION')
            );

            foreach ($data as $item) {
                $item['created'] = Utils::formatDate($item->created_at);
                /*
                 * Account status and the company-details check are separate
                 * facts from the trade decision, and the queue now shows both:
                 * approving a suspended account grants pricing it cannot use.
                 * Without this the card read every account as "Active",
                 * because the row simply had no account state on it.
                 */
                $item['account'] = $item->accountSummary();
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

            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            $user = User::with('businessProfile')
                ->where('account_type', 'trade')
                ->find($id);

            if (is_null($user)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, $user));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Approve or reject. Approving is what switches trade pricing on.
     */
    public function action(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'user.edit')) {
                return $can;
            }

            $status = $request->input('trade_status');

            $user = User::where('account_type', 'trade')->find($id);

            if (is_null($user)) {
                return response()->json(Validation::noDataLang($lang));
            }

            /*
             * The move is checked against the ladder in AccountStatus rather
             * than against a flat list of names. A flat list accepts every
             * combination, including the ones that make no sense to answer for
             * — suspending an application nobody has approved yet, for one —
             * and leaves the reviewer no clue why the account is in the state
             * it is in.
             *
             * Approving is also what marks the company details verified, so the
             * two cannot be left to drift apart by hand.
             */
            $transitioned = false;

            $error = AccountStatus::applyTrade(
                $user, $status, $request->input('trade_review_note'), $transitioned
            );

            if ($error) {
                return response()->json(Validation::error($request->token, $error));
            }

            /*
             * The decision is committed by this point — applyTrade writes and
             * returns, and there is no transaction open around it. Only now is
             * the customer told, and only when this request is the one that
             * actually moved the application: re-submitting a decision the
             * account already holds edits the note without sending a second
             * email, and neither refreshing the queue nor reopening the
             * application writes anything at all.
             *
             * A genuine rejected -> pending -> approved run does transition on
             * that last step, so it does produce a fresh approval email. The
             * rule is "did the status change", not "have we ever written to
             * this customer".
             */
            // Read back once, with the company details the email names and the
            // response carries, rather than fetching the row twice.
            $fresh = User::with('businessProfile')->find($id);

            $notified = false;

            if ($transitioned) {
                $notified = MailHelper::tradeApplicationReviewed($fresh, $status, $lang);
            }

            $fresh['account'] = $fresh->accountSummary();

            /*
             * What the admin screen is allowed to claim.
             *
             * "Sent" and "queued" are different promises to the person
             * clicking the button, and this reports the one that is true:
             * MailHelper hands the message to the transport inside this
             * request, so by the time this responds it has been sent, not
             * parked somewhere to be sent later.
             *
             * Deliberately not derived from QUEUE_CONNECTION. That setting
             * describes the queue, not this send — Mail::send is synchronous
             * whatever the queue driver is — so reading it would let an
             * install that merely has a queue configured start telling admins
             * their emails were queued when they had already gone.
             *
             * Two separate facts, because they fail separately: whether this
             * request was the one that moved the application, and whether the
             * customer could actually be told about it.
             */
            $fresh['notification'] = [
                'triggered' => $transitioned,
                'delivered' => $notified,
                'queued' => false,
            ];

            return response()->json(new Response($request->token, $fresh));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    // ---- upgrade applications ---------------------------------------------
    //
    // A trade application from an account that already exists (personal or
    // business) is a row in account_upgrade_requests, not a user with
    // account_type = trade: the type only changes on approval, so a business
    // customer keeps business pricing while waiting. These three calls are
    // that queue. Rules: App\Models\Helper\AccountUpgrade.

    const UPGRADE_FIELD_LABELS = [
        'company_name' => 'Company name',
        'vat_number' => 'VAT number',
        'cro_number' => 'Company registration number',
        'billing_address' => 'Billing address',
        'sector' => 'Sector',
        'requires_po' => 'Purchase order required',
        'credit_application' => 'Applied for credit',
        'trade' => 'Trade',
        'expected_spend' => 'Expected monthly spend',
        'website' => 'Website',
        'resale_confirmed' => 'Buys for resale or business use',
        'pref_trade_list' => 'Trade mailing list',
    ];

    public function upgrades(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            $statuses = [
                AccountUpgradeRequest::STATUS_PENDING, AccountUpgradeRequest::STATUS_APPROVED,
                AccountUpgradeRequest::STATUS_REJECTED, AccountUpgradeRequest::STATUS_WITHDRAWN,
                AccountUpgradeRequest::STATUS_COMPLETED,
            ];

            // Default to the ones still waiting, as the sign-up queue does.
            $status = $request->input('status', AccountUpgradeRequest::STATUS_PENDING);

            if ($status !== 'all' && !in_array($status, $statuses, true)) {
                return response()->json(Validation::error($request->token, 'Unknown upgrade status filter.'));
            }

            $query = AccountUpgradeRequest::with(['user.businessProfile', 'reviewer:id,name']);

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            if ($request->q) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('email', 'LIKE', "%{$request->q}%")
                        ->orWhere('name', 'LIKE', "%{$request->q}%");
                });
            }

            // Oldest first while waiting (first come, first reviewed);
            // newest first for the history tabs.
            $status === AccountUpgradeRequest::STATUS_PENDING
                ? $query->orderBy('id')
                : $query->orderByDesc('id');

            $data = $query->paginate($request->limit ?? Config::get('constants.api.PAGINATION'));

            // With the side-by-side changes: the reviewer decides from the
            // card, and the profile is already eager-loaded, so it is free.
            $data->getCollection()->transform(function ($upgrade) {
                return self::presentUpgrade($upgrade, true);
            });

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function upgrade(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'user.view')) {
                return $can;
            }

            $upgrade = AccountUpgradeRequest::with(['user.businessProfile', 'reviewer:id,name'])->find($id);

            if (is_null($upgrade)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, self::presentUpgrade($upgrade, true)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Approve or reject an upgrade application.
     *
     * `notification.triggered` reports whether this request is the one that
     * decided it - the signal the customer email hangs off, exactly once.
     */
    public function upgradeAction(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'user.edit')) {
                return $can;
            }

            $upgrade = AccountUpgradeRequest::find($id);

            if (is_null($upgrade)) {
                return response()->json(Validation::noDataLang($lang));
            }

            $transitioned = false;

            $error = AccountUpgrade::decide(
                $upgrade,
                $request->input('decision'),
                $request->input('review_note'),
                $this->user->id,
                $transitioned
            );

            if ($error) {
                return response()->json(Validation::error($request->token, $error));
            }

            $upgrade->load(['user.businessProfile', 'reviewer:id,name']);

            /*
             * Emailed only by the request that decided it, like the sign-up
             * queue. The note comes from the application - a rejected upgrade
             * never writes trade_review_note - and a business customer who is
             * turned down is told their business pricing carries on, not that
             * they are back on retail.
             */
            $notified = false;
            $customer = $upgrade->user;

            if ($transitioned && $customer) {
                $notified = MailHelper::tradeApplicationReviewed($customer, $upgrade->status, $lang, [
                    'reason' => $upgrade->review_note,
                    'still_business' => $upgrade->status === AccountUpgradeRequest::STATUS_REJECTED
                        && $customer->account_type === 'business',
                ]);
            }

            $data = self::presentUpgrade($upgrade, true);
            $data['notification'] = [
                'triggered' => $transitioned,
                'delivered' => $notified,
                'queued' => false,
            ];

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * One application as the queue shows it. With $withChanges, each field
     * the customer submitted beside what the account holds now, so the
     * reviewer can see at a glance a business renaming itself or changing
     * VAT number - the one thing a trade approval must not wave through
     * unnoticed.
     *
     * "Current" is read live, so once an application is approved the two
     * columns match; the submitted column is the permanent record.
     */
    private static function presentUpgrade(AccountUpgradeRequest $upgrade, $withChanges = false)
    {
        $user = $upgrade->user;

        $out = [
            'id' => $upgrade->id,
            'from_type' => $upgrade->from_type,
            'to_type' => $upgrade->to_type,
            'status' => $upgrade->status,
            'details' => $upgrade->details,
            'review_note' => $upgrade->review_note,
            'reviewed_by' => $upgrade->reviewer ? ['id' => $upgrade->reviewer->id, 'name' => $upgrade->reviewer->name] : null,
            'reviewed_at' => optional($upgrade->reviewed_at)->toIso8601String(),
            'created_at' => optional($upgrade->created_at)->toIso8601String(),
            'created' => Utils::formatDate($upgrade->created_at),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'account' => $user->accountSummary(),
            ] : null,
        ];

        if ($withChanges) {
            $profile = $user ? $user->businessProfile : null;
            $details = (array)$upgrade->details;

            $out['changes'] = collect(AccountUpgrade::DETAIL_FIELDS[$upgrade->to_type] ?? [])
                ->map(function ($field) use ($details, $profile, $user) {

                    $current = $field === 'pref_trade_list'
                        ? ($user ? (bool)$user->pref_trade_list : null)
                        : optional($profile)->{$field};

                    $submitted = $details[$field] ?? null;

                    if (in_array($field, AccountUpgrade::BOOLEAN_FIELDS, true)) {
                        $current = is_null($current) ? null : (bool)$current;
                        $submitted = (bool)$submitted;
                    }

                    return [
                        'field' => $field,
                        'label' => self::UPGRADE_FIELD_LABELS[$field] ?? $field,
                        'current' => $current,
                        'submitted' => $submitted,
                        // A personal account has no company record: every
                        // field it filled in is new rather than changed.
                        'changed' => $profile
                            ? (string)$current !== (string)$submitted
                            : !is_null($submitted) && $submitted !== false,
                    ];
                })
                ->values()
                ->all();
        }

        return $out;
    }
}
