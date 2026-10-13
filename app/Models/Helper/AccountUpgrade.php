<?php

namespace App\Models\Helper;

use App\Models\AccountUpgradeRequest;
use App\Models\User;
use App\Models\UserBusinessProfile;
use Illuminate\Support\Facades\DB;

/**
 * A customer moving their own account up a type.
 *
 *   personal -> business   immediate, no review (business pricing needs none
 *                          at signup either, so an upgrade asks for no more)
 *   personal -> trade      an application; nothing changes until approved
 *   business -> trade      an application; business pricing carries on
 *
 * Nothing else is self-serve. Downgrades, and trade -> business in particular,
 * go through support: business pricing needs no review, so a rejected or
 * suspended trade customer who could switch to business would simply buy the
 * decision back.
 *
 * Why trade does not flip account_type on submission: hasBusinessPricing()
 * reads account_type, and a business account that became trade/pending would
 * lose its bands for as long as the queue took — punished for applying. So
 * the application waits in account_upgrade_requests with the details the
 * customer typed, and only approval touches the account.
 *
 * Like AccountStatus, the methods return an error string phrased for the
 * customer or reviewer, or null when the move was made. The request, where
 * there is one, comes back through a by-reference argument.
 *
 * Callers are expected to have validated the details already
 * (Validation::companyRules); this class decides which of them belong to the
 * target type and stores only those.
 */
class AccountUpgrade
{
    const ALLOWED = [
        'personal' => ['business', 'trade'],
        'business' => ['trade'],
        'trade' => [],
    ];

    /** Company identity, asked of both business and trade. */
    const COMPANY_FIELDS = ['company_name', 'vat_number', 'cro_number', 'billing_address'];

    /** Per target type: everything the upgrade form may carry. */
    const DETAIL_FIELDS = [
        'business' => [
            'company_name', 'vat_number', 'cro_number', 'billing_address',
            'sector', 'requires_po', 'credit_application'
        ],
        'trade' => [
            'company_name', 'vat_number', 'cro_number', 'billing_address',
            'trade', 'expected_spend', 'website', 'resale_confirmed', 'pref_trade_list'
        ],
    ];

    const BOOLEAN_FIELDS = ['requires_po', 'credit_application', 'resale_confirmed', 'pref_trade_list'];

    const NOT_ALLOWED_MESSAGE = 'This account cannot be changed to that type online. Please contact support.';
    const PENDING_MESSAGE = 'You already have an application with us. Withdraw it first if you want to change it.';

    /**
     * The types this account may move to right now. Empty when it may not
     * upgrade at all, including while an application is waiting.
     */
    public static function options(User $user)
    {
        return collect(self::ALLOWED[self::typeOf($user)] ?? [])
            ->filter(function ($to) use ($user) {
                return is_null(self::eligibilityError($user, $to));
            })
            ->values()
            ->all();
    }

    public static function pendingFor(User $user)
    {
        return AccountUpgradeRequest::where('user_id', $user->id)
            ->pending()
            ->latest('id')
            ->first();
    }

    /**
     * Why this account may not move to $to now, or null when it may.
     */
    public static function eligibilityError(User $user, $to)
    {
        if (!$user->isActive()) {
            return AccountStatus::SUSPENDED_MESSAGE;
        }

        if (!(int)$user->verified) {
            return 'Please confirm your email address before changing your account type.';
        }

        if (!in_array($to, self::ALLOWED[self::typeOf($user)] ?? [], true)) {
            return self::NOT_ALLOWED_MESSAGE;
        }

        if (self::pendingFor($user)) {
            return self::PENDING_MESSAGE;
        }

        return null;
    }

    /**
     * Personal -> business, on the spot.
     */
    public static function upgradeToBusiness(User $user, array $details, &$upgrade = null)
    {
        $upgrade = null;

        return DB::transaction(function () use ($user, $details, &$upgrade) {

            // Re-read under lock: two tabs submitting at once both passed the
            // check on the model they were handed.
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            if ($error = self::eligibilityError($locked, 'business')) {
                return $error;
            }

            $details = self::only($details, 'business');

            // Trade answers left from an earlier attempt do not belong to a
            // business profile; the signup path drops them the same way.
            UserBusinessProfile::updateOrCreate(
                ['user_id' => $locked->id],
                $details + ['trade' => null, 'expected_spend' => null, 'website' => null, 'resale_confirmed' => false]
            );

            $locked->forceFill([
                'account_type' => 'business',
                'trade_status' => null,
                // Company details nobody has checked yet, as at signup.
                'verification_status' => User::VERIFICATION_PENDING,
            ])->save();

            $upgrade = AccountUpgradeRequest::create([
                'user_id' => $locked->id,
                'from_type' => 'personal',
                'to_type' => 'business',
                'status' => AccountUpgradeRequest::STATUS_COMPLETED,
                'details' => $details,
            ]);

            $user->setRawAttributes($locked->fresh()->getAttributes(), true);

            return null;
        });
    }

    /**
     * Personal or business -> trade: file the application, change nothing else.
     */
    public static function applyForTrade(User $user, array $details, &$upgrade = null)
    {
        $upgrade = null;

        return DB::transaction(function () use ($user, $details, &$upgrade) {

            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            if ($error = self::eligibilityError($locked, 'trade')) {
                return $error;
            }

            $details = self::only($details, 'trade');

            // The declaration staff approve on. Validation demands it too;
            // this is the last line if a caller forgot to validate.
            if (!$details['resale_confirmed']) {
                return 'Please confirm you are buying for resale or business use.';
            }

            $upgrade = AccountUpgradeRequest::create([
                'user_id' => $locked->id,
                'from_type' => self::typeOf($locked),
                'to_type' => 'trade',
                'status' => AccountUpgradeRequest::STATUS_PENDING,
                'details' => $details,
            ]);

            return null;
        });
    }

    /**
     * The customer cancels their waiting application.
     */
    public static function withdraw(User $user, &$upgrade = null)
    {
        $upgrade = self::pendingFor($user);

        if (!$upgrade) {
            return 'There is no application waiting to withdraw.';
        }

        $moved = AccountUpgradeRequest::where('id', $upgrade->id)
            ->pending()
            ->update(['status' => AccountUpgradeRequest::STATUS_WITHDRAWN]);

        $upgrade = $upgrade->fresh();

        // Decided by staff in the meantime: say so rather than claim a withdrawal.
        if ($moved !== 1) {
            return 'This application has already been ' . $upgrade->status . '.';
        }

        return null;
    }

    /**
     * Staff approve or reject a pending trade application.
     *
     * $transitioned is true only for the call that actually decided it, which
     * is what the customer email hangs off — the same exactly-once rule as
     * AccountStatus::applyTrade, for the same reason: two approve clicks both
     * read "pending", but only one conditional UPDATE matches.
     *
     * Approving writes the submitted details over the company profile (the
     * application is what staff just checked), makes the account trade and
     * approves it through AccountStatus::applyTrade, which also marks the
     * details verified. Rejecting touches the account not at all.
     *
     * Business-only profile fields (sector, requires_po, credit_application)
     * are left as they were on a business -> trade approval: requires_po is
     * still honoured at checkout for any non-personal account, and a
     * company's PO rule does not lapse because its price tier changed.
     */
    public static function decide(AccountUpgradeRequest $upgrade, $decision, $note = null, $adminId = null, &$transitioned = null)
    {
        $transitioned = false;

        $decisions = [AccountUpgradeRequest::STATUS_APPROVED, AccountUpgradeRequest::STATUS_REJECTED];

        if (!in_array($decision, $decisions, true)) {
            return 'Choose approved or rejected.';
        }

        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        try {
            $error = DB::transaction(function () use ($upgrade, $decision, $note, $adminId, &$transitioned) {

                // User first, request second: the same lock order as the
                // customer-side calls, so the two cannot deadlock.
                $user = User::where('id', $upgrade->user_id)->lockForUpdate()->first();

                if (!$user) {
                    return 'The customer account no longer exists.';
                }

                $claimed = AccountUpgradeRequest::where('id', $upgrade->id)
                    ->pending()
                    ->update([
                        'status' => $decision,
                        'review_note' => $note,
                        'reviewed_by' => $adminId,
                        'reviewed_at' => now(),
                    ]);

                if ($claimed !== 1) {
                    $fresh = AccountUpgradeRequest::find($upgrade->id);

                    // A double click landing second: same answer, nothing new.
                    if ($fresh && $fresh->status === $decision) {
                        return null;
                    }

                    return 'This application has already been ' . ($fresh ? $fresh->status : 'removed') . '.';
                }

                if ($decision === AccountUpgradeRequest::STATUS_APPROVED) {
                    self::applyTradeApproval($user, (array)$upgrade->details, $note);
                }

                $transitioned = true;

                return null;
            });
        } catch (\DomainException $refused) {
            $transitioned = false;
            $error = $refused->getMessage();
        }

        if ($fresh = AccountUpgradeRequest::find($upgrade->id)) {
            $upgrade->setRawAttributes($fresh->getAttributes(), true);
        }

        return $error;
    }

    /**
     * The account half of an approval. Throws DomainException to roll the
     * whole decision back if the trade ladder refuses the move.
     */
    private static function applyTradeApproval(User $user, array $details, $note)
    {
        $details = self::only($details, 'trade');

        UserBusinessProfile::updateOrCreate(
            ['user_id' => $user->id],
            collect($details)->except('pref_trade_list')->all()
        );

        $user->forceFill([
            'account_type' => 'trade',
            'pref_trade_list' => $details['pref_trade_list'],
        ])->save();

        $error = AccountStatus::applyTrade($user, User::TRADE_APPROVED, $note);

        if ($error) {
            throw new \DomainException($error);
        }
    }

    private static function typeOf(User $user)
    {
        return $user->account_type ?: 'personal';
    }

    /**
     * Keep the fields that belong to $type, normalised the way the profile
     * stores them: trimmed, blank as null, flags as real booleans. Fields
     * belonging to the other type are dropped rather than trusted.
     */
    private static function only(array $details, $type)
    {
        $out = [];

        foreach (self::DETAIL_FIELDS[$type] as $field) {

            $value = $details[$field] ?? null;

            if (in_array($field, self::BOOLEAN_FIELDS, true)) {
                $out[$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            if (!is_scalar($value)) {
                $value = null;
            }

            $value = is_string($value) ? trim($value) : $value;

            $out[$field] = ($value === '' || is_null($value)) ? null : (string)$value;
        }

        return $out;
    }
}
