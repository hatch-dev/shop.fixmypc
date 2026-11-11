<?php

namespace App\Models\Helper;

use App\Models\RatingReview;
use App\Models\ReviewImage;
use App\Models\User;
use App\Models\UserMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * What "Delete" on the admin customer list does.
 *
 * It used to hard-delete the customer's order lines and cancellations,
 * soft-delete their orders, and then fail on the address delete, because a
 * soft-deleted order still holds orders.user_address_id. With no transaction,
 * every failed attempt left the orders hidden and stripped of their lines.
 *
 * Orders, returns, wallet movements, gift-voucher purchases, vouchers and
 * memberships are the shop's records, kept for the accounts, so a customer
 * with any of them is ERASED: the users row stays (orders.user_id and the
 * order addresses point at it) with the person scrubbed out, sign-in closed,
 * and erased_at set so the customer list leaves it out. A customer with none
 * of them is DELETED outright. Either way it happens in one transaction.
 */
class AccountErasure
{
    const DELETED = 'deleted';
    const ERASED = 'erased';

    const ERASED_NAME = 'Deleted customer';

    /**
     * Why this customer cannot be removed yet, or null.
     *
     * A live Stripe subscription would go on charging a card for an account
     * nobody can sign into.
     */
    public static function blocker(User $user)
    {
        $liveSubscription = UserMembership::where('user_id', $user->id)
            ->whereNotNull('stripe_subscription_id')
            ->whereIn('status', UserMembership::OPEN)
            ->exists();

        if ($liveSubscription) {
            return 'This customer has a live paid membership. Cancel it under Membership first, then delete the customer.';
        }

        return null;
    }

    /**
     * Does the shop hold records for this customer that must outlive them.
     */
    public static function hasRecords(User $user)
    {
        $id = $user->id;

        return DB::table('orders')->where('user_id', $id)->exists()
            || DB::table('order_returns')->where('user_id', $id)->exists()
            || DB::table('wallet_transactions')->where('user_id', $id)->exists()
            || DB::table('gift_voucher_orders')->where('user_id', $id)->exists()
            || DB::table('vouchers')->where('user_id', $id)->exists()
            || DB::table('user_memberships')->where('user_id', $id)->exists();
    }

    /**
     * Remove the customer. Returns DELETED or ERASED; check blocker() first.
     */
    public static function remove(User $user)
    {
        $files = [];

        $outcome = DB::transaction(function () use ($user, &$files) {
            $keep = self::hasRecords($user);

            AccountStatus::revokeAllSessions($user);
            self::clearPersonalData($user, $files);

            if ($keep) {
                self::scrub($user);
                return self::ERASED;
            }

            self::deleteOutright($user);
            return self::DELETED;
        });

        // Only once the rows are gone for good: a rollback must not leave
        // reviews pointing at images that no longer exist.
        foreach ($files as $file) {
            FileHelper::deleteFile($file);
        }

        return $outcome;
    }

    /**
     * What goes in both cases: the basket and lists, reviews, sessions and
     * pending requests. None of it is a record of anything the shop did.
     */
    private static function clearPersonalData(User $user, array &$files)
    {
        $id = $user->id;

        // Basket lines hold real stock; hand it back before the lines go.
        CartHold::releaseAll($id, null);
        DB::table('carts')->where('user_id', $id)->delete();
        DB::table('cart_holds')->where('user_id', $id)->delete();
        DB::table('save_for_later')->where('user_id', $id)->delete();
        DB::table('user_wishlists')->where('user_id', $id)->delete();
        DB::table('compare_lists')->where('user_id', $id)->delete();
        DB::table('recently_viewed_products')->where('user_id', $id)->delete();
        DB::table('user_follow_stores')->where('user_id', $id)->delete();
        DB::table('user_sessions')->where('user_id', $id)->delete();
        DB::table('two_factor_challenges')->where('user_id', $id)->delete();
        DB::table('account_upgrade_requests')->where('user_id', $id)->delete();

        // Newsletter sign-ups are kept by address; while it is still the real one.
        DB::table('subscription_emails')->where('email', $user->email)->delete();

        $reviewIds = RatingReview::where('user_id', $id)->pluck('id');

        foreach (ReviewImage::whereIn('rating_review_id', $reviewIds)->pluck('image') as $image) {
            $files[] = $image;
        }

        ReviewImage::whereIn('rating_review_id', $reviewIds)->delete();
        RatingReview::whereIn('id', $reviewIds)->delete();

        if ($user->image) {
            $files[] = $user->image;
        }
    }

    /**
     * Keep the row for the orders that point at it; take the person out.
     */
    private static function scrub(User $user)
    {
        $id = $user->id;

        // Addresses an order was sent to belong to that order's record.
        $usedAddressIds = DB::table('orders')->where('user_id', $id)
            ->whereNotNull('user_address_id')->pluck('user_address_id');

        DB::table('user_addresses')->where('user_id', $id)
            ->whereNotIn('id', $usedAddressIds)->delete();

        $user->forceFill([
            'name' => self::ERASED_NAME,
            // Unique, and never deliverable (RFC 2606), so the address is free
            // for a new signup and no mail can reach the old one.
            'email' => 'deleted-' . $id . '@deleted.invalid',
            'phone' => null,
            'phone_verified_at' => null,
            'image' => null,
            'password' => Hash::make(Str::random(64)),
            'code' => null,
            'code_expires_at' => null,
            'google_id' => null,
            'facebook_id' => null,
            'default_address' => null,
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'wishlist_share_token' => null,
            'pref_email_offers' => 0,
            'pref_sms_offers' => 0,
            'pref_recommendations' => 0,
            'pref_trade_list' => 0,
            'member_digest_opt_out' => 1,
            // Suspended is what every sign-in path already refuses.
            'status' => User::STATUS_SUSPENDED,
            'status_note' => 'Deleted by staff; orders kept.',
            'status_changed_at' => now(),
            'erased_at' => now(),
        ])->save();
    }

    /**
     * No records to keep. The foreign keys that RESTRICT are cleared above or
     * here; the rest (wallet, points, memberships, sessions) CASCADE.
     */
    private static function deleteOutright(User $user)
    {
        $id = $user->id;

        DB::table('cancellations')->where('user_id', $id)->delete();
        DB::table('user_addresses')->where('user_id', $id)->delete();
        DB::table('user_business_profiles')->where('user_id', $id)->delete();
        DB::table('oauth_access_tokens')->where('user_id', $id)
            ->where('scopes', 'like', '%"user"%')->delete();

        $user->delete();
    }
}
