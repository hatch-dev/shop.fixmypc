<?php

namespace App\Models\Helper;

use App\Models\User;
use App\Models\UserSession;
use Laravel\Passport\Token;

/**
 * Which account-state moves are legal, in one place.
 *
 * Account status and trade status are separate ladders that happen to share a
 * vocabulary, and staff drive both from the admin screens. Without a single
 * table of legal moves each screen invented its own — the trade screen already
 * accepted "approved -> pending" while nothing else could express suspension
 * at all — so a customer could be walked into a combination no part of the
 * pricing code knows how to answer for.
 *
 * Both ladders are deliberately forgiving about staying put: re-sending the
 * status an account already has is a no-op, not an error, because the admin UI
 * can double-submit and that must not read as a failed request.
 */
class AccountStatus
{
    /**
     * Account status: may this account be used at all.
     *
     * rejected is terminal on purpose. An account turned away at signup is not
     * reinstated by flipping a column — the customer applies again, which is
     * what creates the record staff need of why it was allowed the second time.
     */
    const ACCOUNT_TRANSITIONS = [
        'pending'   => ['active', 'rejected', 'suspended'],
        'active'    => ['suspended'],
        'suspended' => ['active'],
        'rejected'  => [],
    ];

    /**
     * Trade status: is trade pricing granted.
     *
     * A rejected trade application can go back to pending, unlike a rejected
     * account — rejection here means "not on this evidence", and customers do
     * send better evidence.
     */
    const TRADE_TRANSITIONS = [
        'pending'   => ['approved', 'rejected'],
        'approved'  => ['suspended', 'rejected'],
        'suspended' => ['approved', 'rejected'],
        'rejected'  => ['pending', 'approved'],
    ];

    /**
     * What a blocked customer is told, whatever route they came in by.
     *
     * Deliberately says nothing beyond the fact and what to do about it: the
     * reason a account was suspended lives in `status_note`, which is a staff
     * remark and is not shown to the customer.
     */
    const SUSPENDED_MESSAGE = 'Your account has been suspended. Please contact support for assistance.';
    const REJECTED_MESSAGE = 'This account is not available. Please contact support for assistance.';

    /**
     * Why this account may not authenticate, or null when it may.
     *
     * The one place the question is asked. Sign-in, the two-factor step, the
     * emailed sign-in code, signup verification and every authenticated request
     * all route through here, so a new way in cannot quietly skip the check.
     *
     * Account status only. Trade status is a different ladder and deliberately
     * does NOT block sign-in: a trade customer whose pricing is suspended is
     * still a customer, and still buys at retail.
     */
    public static function authenticationBlock($user)
    {
        if (!$user) {
            return null;
        }

        if ($user->status === User::STATUS_SUSPENDED) {
            return self::SUSPENDED_MESSAGE;
        }

        if ($user->status === User::STATUS_REJECTED) {
            return self::REJECTED_MESSAGE;
        }

        // NULL status is an account that predates the column, and is active.
        return null;
    }

    /**
     * Take away every way this account can authenticate, everywhere.
     *
     * Suspension has to reach further than the column it writes. A customer
     * signed in on three devices holds three Passport tokens, and none of them
     * stops working because a column changed — so suspending an account left
     * every open session running until it expired, which for this application
     * is twelve months.
     *
     * Uses the same two writes the "log out this device" screen already does
     * (revoke the token, mark the session inactive) rather than a second
     * mechanism beside it, plus the remember-me token so a closed browser
     * cannot sign itself back in.
     *
     * Returns the number of tokens revoked.
     */
    public static function revokeAllSessions(User $user)
    {
        $tokenIds = UserSession::where('user_id', $user->id)
            ->pluck('token_id')
            ->filter()
            ->all();

        /*
         * Revoke by owner, not only by the ids user_sessions knows about: a
         * token minted before session tracking existed, or one whose session
         * row was pruned, is still a working key to the account.
         */
        $revoked = Token::where('user_id', $user->id)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($tokenIds) {
            Token::whereIn('id', $tokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);
        }

        UserSession::where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        /*
         * `remember_token` in this schema is a tinyint flag, not Laravel's
         * usual 60-character token string — persistent sign-in here is the
         * twelve-month Passport token revoked above, and this column only
         * records that the customer asked to be remembered. Clearing it keeps
         * the flag honest; the access it once implied has already gone.
         */
        $user->forceFill(['remember_token' => 0])->save();

        return $revoked;
    }

    public static function accountStatuses()
    {
        return array_keys(self::ACCOUNT_TRANSITIONS);
    }

    public static function tradeStatuses()
    {
        return array_keys(self::TRADE_TRANSITIONS);
    }

    /**
     * Null when the move is allowed, otherwise the reason it is not — phrased
     * for the person clicking the button, not for a log file.
     */
    public static function accountError($from, $to)
    {
        return self::check(self::ACCOUNT_TRANSITIONS, $from, $to, 'active', 'account status');
    }

    public static function tradeError($from, $to)
    {
        return self::check(self::TRADE_TRANSITIONS, $from, $to, User::TRADE_PENDING, 'trade status');
    }

    private static function check(array $table, $from, $to, $defaultFrom, $label)
    {
        $from = is_string($from) && $from !== '' ? $from : $defaultFrom;

        if (!is_string($to) || !array_key_exists($to, $table)) {
            return 'Choose one of: ' . implode(', ', array_keys($table)) . '.';
        }

        if (!array_key_exists($from, $table)) {
            /*
             * A row carrying a status this table does not list — a value left
             * by an older build. Let staff move it onto a known one rather
             * than leaving the account unmanageable.
             */
            return null;
        }

        if ($from === $to) {
            return null;
        }

        if (!in_array($to, $table[$from], true)) {
            return "This account is {$from}; it cannot be moved straight to {$to}.";
        }

        return null;
    }

    /**
     * Apply an account-status change, recording when and why.
     *
     * Returns the error string when the move is refused, null when it is made.
     * Suspending is the one move that has to reach further than the column:
     * business and trade pricing are entitlements of an active account, and
     * User::hasBusinessPricing() reads status, so nothing else needs unwinding.
     */
    public static function applyAccount(User $user, $to, $note = null)
    {
        $error = self::accountError($user->status, $to);

        if ($error) {
            return $error;
        }

        $user->forceFill([
            'status' => $to,
            'status_note' => $note,
            'status_changed_at' => now(),
        ])->save();

        /*
         * Losing access is part of being suspended, not a separate admin step.
         * Doing it here means every caller gets it — the admin screen, a
         * console command, a future bulk action — instead of each having to
         * remember. Reactivating deliberately does NOT restore the old tokens:
         * they stay revoked and the customer signs in again.
         */
        if (in_array($to, [User::STATUS_SUSPENDED, User::STATUS_REJECTED], true)) {
            self::revokeAllSessions($user);
        }

        return null;
    }

    /**
     * Apply a trade-status change.
     *
     * Verification status follows the decision rather than being set by hand:
     * approving a trade application *is* the act of verifying the details, and
     * leaving the two to be maintained separately is how they drift apart.
     */
    /**
     * Apply a trade-status change.
     *
     * $transitioned comes back true only when this call is the one that moved
     * the account onto a new status. That is the signal the customer
     * notification hangs off, and it is why the write below is a conditional
     * update rather than a save():
     *
     *   - Re-submitting the decision an account already holds edits the note
     *     and nothing else, so no second email goes out.
     *   - Two approve clicks racing each other both read "pending", but only
     *     one UPDATE matches `trade_status = pending`; the loser reports no
     *     transition and sends nothing.
     *
     * Without the WHERE, exactly-once would depend on the admin not
     * double-clicking, which is not a thing anybody can promise.
     */
    public static function applyTrade(User $user, $to, $note = null, &$transitioned = null)
    {
        $transitioned = false;

        $from = $user->trade_status;

        $error = self::tradeError($from, $to);

        if ($error) {
            return $error;
        }

        $verification = [
            User::TRADE_APPROVED  => User::VERIFICATION_VERIFIED,
            User::TRADE_REJECTED  => User::VERIFICATION_REJECTED,
            User::TRADE_PENDING   => User::VERIFICATION_PENDING,
            User::TRADE_SUSPENDED => User::VERIFICATION_VERIFIED,
        ];

        $payload = [
            'trade_status' => $to,
            'trade_reviewed_at' => now(),
            'trade_review_note' => $note,
            'verification_status' => $verification[$to],
        ];

        // Same status again: keep the note, report no transition.
        if ($from === $to) {
            $user->forceFill($payload)->save();

            return null;
        }

        $query = User::where('id', $user->id);

        /*
         * Applications predating the trade_status column carry NULL, and the
         * ladder reads NULL as pending. A `= 'pending'` comparison never
         * matches NULL in SQL, so those rows would be unapprovable.
         */
        if (is_null($from) || $from === '') {
            $query->where(function ($q) {
                $q->whereNull('trade_status')->orWhere('trade_status', '');
            });
        } else {
            $query->where('trade_status', $from);
        }

        $transitioned = $query->update($payload) === 1;

        if ($transitioned) {
            $user->forceFill($payload)->syncOriginal();

            return null;
        }

        /*
         * The row was not where we left it — another request got there first.
         * Re-read rather than filling the model with what we intended to
         * write: the caller reports this account's state back to the admin,
         * and showing them the write that did not happen is worse than showing
         * them the one that did.
         */
        $fresh = User::find($user->id);

        if ($fresh) {
            $user->forceFill($fresh->getAttributes())->syncOriginal();
        }

        return null;
    }
}
