<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The two rows under a product page: GET /api/v1/suggested-products/{id}.
 *
 *   suggestion_2  "People who viewed this item also viewed"
 *   suggestion_1  "Recommended for you"
 *
 * They used to be "the other sub-categories" and "the same sub-category",
 * unsorted, so an iPhone page recommended four out-of-stock Samsungs while
 * the two buyable ones sat on later pages, and "also viewed" used no viewing
 * data at all. Runs against the dev catalogue, so it asserts rules rather
 * than particular products.
 */
class ProductSuggestionTest extends TestCase
{
    use DatabaseTransactions;

    const PRODUCT = 99492258; // Apple iPhone 15 Pro: Mobile > Apple

    private function customer($tag)
    {
        return User::create([
            'name' => 'Suggest ' . $tag,
            'email' => "fixmypc-suggest-$tag-" . uniqid() . '@yopmail.com',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function viewed(User $user, array $productIds)
    {
        foreach ($productIds as $i => $pid) {
            DB::table('recently_viewed_products')->insert([
                'user_id' => $user->id, 'product_id' => $pid,
                'created_at' => now()->subMinutes(count($productIds) - $i),
                'updated_at' => now()->subMinutes(count($productIds) - $i),
            ]);
        }
    }

    private function rows($page = 1, $productId = self::PRODUCT)
    {
        $res = $this->getJson("/api/v1/suggested-products/$productId?page=$page");
        $res->assertOk();
        $this->assertSame(200, $res->json('status'), (string)$res->json('message'));
        return [$res->json('data.suggestion_1'), $res->json('data.suggestion_2')];
    }

    private function ids($row)
    {
        return array_map('intval', array_column($row['data'], 'id'));
    }

    private function buyable(array $ids)
    {
        $stock = DB::table('updated_inventories')->whereIn('product_id', $ids)
            ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) q')->pluck('q', 'product_id');
        $backorder = DB::table('products')->whereIn('id', $ids)->where('back_order', 1)->pluck('id')->all();

        return array_map(function ($id) use ($stock, $backorder) {
            return ($stock[$id] ?? 0) > 0 || in_array($id, $backorder);
        }, $ids);
    }

    private function inCategory($categoryId)
    {
        return DB::table('product_categories')->where('category_id', $categoryId)
            ->pluck('product_id')->map(function ($id) { return (int)$id; })->all();
    }

    private function everyPage($which)
    {
        [$r1, $r2] = $this->rows(1);
        $first = $which === 1 ? $r1 : $r2;
        $all = $this->ids($first);
        for ($p = 2; $p <= $first['last_page']; $p++) {
            [$a, $b] = $this->rows($p);
            $all = array_merge($all, $this->ids($which === 1 ? $a : $b));
        }
        return [$first, $all];
    }

    public function test_the_pages_hold_what_the_count_says_and_never_repeat()
    {
        foreach ([1, 2] as $which) {
            [$first, $all] = $this->everyPage($which);
            $this->assertSame($first['total'], count($all), "row $which: total vs pages");
            $this->assertSame($all, array_values(array_unique($all)), "row $which repeats a product");
            $this->assertNotContains(self::PRODUCT, $all, "row $which shows the product itself");

            $bad = DB::table('products')->whereIn('id', $all)
                ->where(function ($q) { $q->where('status', '!=', 1)->orWhereNull('slug'); })->count();
            $this->assertSame(0, $bad, "row $which shows an unpublished or slug-less product");
        }
    }

    public function test_the_two_rows_never_show_the_same_product()
    {
        [, $recommended] = $this->everyPage(1);
        [, $alsoViewed] = $this->everyPage(2);

        $this->assertSame([], array_values(array_intersect($recommended, $alsoViewed)));
    }

    public function test_recommended_puts_buyable_products_first()
    {
        [, $recommended] = $this->everyPage(1);
        $flags = $this->buyable($recommended);

        // once an unbuyable one appears, no buyable one may follow
        $seenUnbuyable = false;
        foreach ($flags as $i => $ok) {
            if (!$ok) { $seenUnbuyable = true; }
            $this->assertFalse($seenUnbuyable && $ok, 'a buyable product sits behind an out-of-stock one at #' . $i);
        }
    }

    public function test_also_viewed_leads_with_what_viewers_of_this_product_viewed()
    {
        // two customers viewed the iPhone and this cable; one also viewed the flash drive
        $cable = 61142212;
        $drive = 92493161;
        $a = $this->customer('a');
        $b = $this->customer('b');
        $this->viewed($a, [self::PRODUCT, $cable, $drive]);
        $this->viewed($b, [self::PRODUCT, $cable]);

        [, $alsoViewed] = $this->rows(1);
        $ids = $this->ids($alsoViewed);

        $this->assertSame($cable, $ids[0], 'the most shared view comes first');
        $this->assertContains($drive, array_slice($ids, 0, 5));
        $this->assertLessThan(array_search($drive, $ids), array_search($cable, $ids));
    }

    public function test_recommended_follows_what_the_signed_in_customer_browses()
    {
        // someone who has only been looking at accessories
        $accessories = DB::table('categories')->where('title', 'Accessories')->value('id');
        $this->assertNotNull($accessories, 'fixture category missing');
        $browsed = array_slice($this->inCategory($accessories), 0, 3);
        $this->assertNotEmpty($browsed);

        $me = $this->customer('browser');
        $this->viewed($me, $browsed);

        // as a guest the row is this product's own category (Mobile)
        [$guest] = $this->rows(1);
        $this->assertNotContains($this->ids($guest)[0], $this->inCategory($accessories));

        Passport::actingAs($me, ['user'], 'user');
        [$mine] = $this->rows(1);

        $this->assertContains($this->ids($mine)[0], $this->inCategory($accessories),
            'a customer browsing accessories is recommended accessories first');
    }

    public function test_an_unknown_product_gets_empty_rows_not_an_error()
    {
        $res = $this->getJson('/api/v1/suggested-products/1?page=1')->assertOk();

        $this->assertSame([], $res->json('data.suggestion_1'));
        $this->assertSame([], $res->json('data.suggestion_2'));
    }
}
