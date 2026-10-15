<?php

namespace App\Models\Helper;

use App\Models\MembershipPlanBenefit;
use App\Models\NewProductAlert;
use App\Models\Product;
use App\Models\Setting;
use App\Models\UpdatedInventory;
use App\Models\UserMembership;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Members hear about new products first (2026-10-15).
 *
 * Every minute (Console\Commands\SendNewProductAlerts) products that are on
 * sale and were never announced are emailed to the members whose plan has the
 * early_access benefit, in one email per run - a bulk upload of thirty
 * products is one email, not thirty. Anyone can buy the products as normal;
 * the email is the benefit.
 *
 * Products are put on sale by several query-builder updates (the edit form,
 * bulk status, bulk upload) that fire no model events, which is why this
 * looks at the products table rather than listening for a save.
 */
class NewProductAlerts
{
    /** Products listed in one email; the rest are "and N more". */
    const SHOWN = 12;

    /**
     * Announce what is new. Returns ['products' => n, 'recipients' => n].
     * A product is announced once: it is marked before the emails go, so a
     * failure part-way never sends it twice.
     */
    public static function send($now = null)
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $public = (int)config('constants.status.PUBLIC');

        // first time on sale
        DB::table('products')->where('status', $public)->whereNull('first_published_at')
            ->update(['first_published_at' => $now]);

        $products = Product::where('status', $public)
            ->whereNotNull('first_published_at')
            ->whereNull('members_notified_at')
            ->orderByDesc('first_published_at')
            ->get(['id', 'title', 'slug', 'image', 'selling', 'offered']);

        if ($products->isEmpty()) {
            return ['products' => 0, 'recipients' => 0];
        }

        Product::whereIn('id', $products->pluck('id'))->update(['members_notified_at' => $now]);

        $rows = self::rows($products->take(self::SHOWN));
        $more = max(0, $products->count() - self::SHOWN);
        $sent = 0;

        foreach (self::recipients() as [$user, $plan]) {
            if (MailHelper::newProductAlert($user, $rows, $plan, $more)) {
                $sent++;
            }
        }

        NewProductAlert::create([
            'products_count' => $products->count(),
            'recipients_count' => $sent,
            'product_ids' => mb_substr($products->pluck('id')->implode(','), 0, 1000),
        ]);

        return ['products' => $products->count(), 'recipients' => $sent];
    }

    /**
     * Members to email: benefits now, a plan with early_access, not suspended,
     * not opted out, with an address. [[User, plan name], ...], one each.
     */
    public static function recipients()
    {
        $out = [];

        $memberships = UserMembership::entitled()->with(['plan.benefits', 'user'])->orderByDesc('id')->get();

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (!$user || isset($out[$user->id]) || !optional($membership->plan)->benefit(MembershipPlanBenefit::EARLY_ACCESS)
                || $user->isSuspended() || $user->member_digest_opt_out || !$user->email) {
                continue;
            }

            $out[$user->id] = [$user, $membership->plan->name];
        }

        return array_values($out);
    }

    /** Products as the email shows them. */
    public static function rows($products)
    {
        $site = rtrim(MailBrand::make()->site_url, '/');
        $currency = optional(Setting::first())->currency_icon ?: '€';

        return $products->map(function ($product) use ($site, $currency) {
            return (object)[
                'title' => $product->title,
                'image' => $product->image ? MailBrand::asset($product->image) : null,
                'url' => $site . '/' . ($product->slug ?: 'item') . '/product/' . $product->id,
                'price' => self::priceLabel($product, $currency),
            ];
        })->values()->all();
    }

    /**
     * "€49.00", or "from €29.00" when the variants are priced differently. A
     * product priced only on its variants has no price of its own (0).
     */
    private static function priceLabel($product, $currency)
    {
        $variants = UpdatedInventory::where('product_id', $product->id)->where('price', '>', 0)->pluck('price')
            ->map(function ($p) {
                return round((float)$p, 2);
            })->unique();

        $shelf = Pricing::shelfPrice($product);
        $prices = $variants->count() > 1 || $shelf <= 0 ? $variants : collect([$shelf]);

        if ($prices->isEmpty()) {
            return '';
        }

        return ($prices->count() > 1 ? 'from ' : '') . $currency . number_format($prices->min(), 2);
    }
}
