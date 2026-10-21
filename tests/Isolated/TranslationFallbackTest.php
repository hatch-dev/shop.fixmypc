<?php

namespace Tests\Isolated;

use App\Database\TranslationFallbackGrammar;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Choosing a language blanked everything nobody had translated (2026-10-21).
 *
 * Queries for another language left-join `*_langs` and select `cl.title` over
 * the original, so an untranslated row came back with a NULL title and a filter
 * on it matched nothing: blank menu items and product names, an Arabic product
 * page that crashed on the null h1, and a Turkish search that found nothing.
 * TranslationFallbackGrammar makes `cl.title` read as COALESCE(cl.title, base.title).
 */
class TranslationFallbackTest extends IsolatedTestCase
{
    private function categoriesIn($lang)
    {
        return Category::leftJoin('category_langs as cl', function ($join) use ($lang) {
            $join->on('cl.category_id', '=', 'categories.id');
            $join->where('cl.lang', $lang);
        });
    }

    public function test_the_connection_uses_the_fallback_grammar()
    {
        $this->assertInstanceOf(TranslationFallbackGrammar::class, DB::connection()->getQueryGrammar());
    }

    public function test_selects_filters_and_sorts_fall_back_to_the_original()
    {
        $sql = $this->categoriesIn('tr')
            ->select('categories.*', 'cl.title', 'cl.meta_title as seo')
            ->where('cl.title', 'like', '%a%')
            ->orderBy('cl.title')
            ->toSql();

        $fallback = 'coalesce(`cl`.`title`, `categories`.`title`)';
        $this->assertStringContainsString("$fallback as `title`", $sql);
        $this->assertStringContainsString('coalesce(`cl`.`meta_title`, `categories`.`meta_title`) as `seo`', $sql);
        $this->assertStringContainsString("where $fallback like ?", $sql);
        $this->assertStringContainsString("order by $fallback asc", $sql);
        // the join keys are not translated columns
        $this->assertStringContainsString('`cl`.`category_id` = `categories`.`id` and `cl`.`lang` = ?', $sql);
    }

    public function test_an_aliased_base_table_is_the_fallback()
    {
        $sql = DB::table('flash_sale_products')
            ->join('products as p', 'p.id', '=', 'flash_sale_products.product_id')
            ->leftJoin('product_langs as avl', function ($join) {
                $join->on('p.id', '=', 'avl.product_id');
                $join->where('avl.lang', 'ar');
            })
            ->select('flash_sale_products.*', 'avl.title', 'avl.badge')
            ->toSql();

        $this->assertStringContainsString('coalesce(`avl`.`title`, `p`.`title`) as `title`', $sql);
        $this->assertStringContainsString('coalesce(`avl`.`badge`, `p`.`badge`) as `badge`', $sql);
    }

    public function test_writes_and_queries_without_a_lang_join_are_untouched()
    {
        $this->assertSame(
            'select `categories`.`title` from `categories` where `title` = ?',
            Category::select('categories.title')->where('title', 'x')->toSql()
        );
    }

    public function test_a_translation_wins_and_a_missing_one_falls_back()
    {
        DB::table('category_langs')->insert([
            'category_id' => Fixture::CATEGORY_PHONES_ID, 'lang' => 'tr', 'title' => 'Telefonlar',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $titles = $this->categoriesIn('tr')
            ->whereIn('categories.id', [Fixture::CATEGORY_PHONES_ID, Fixture::CATEGORY_COMPUTERS_ID])
            ->select('categories.id', 'cl.title')
            ->pluck('title', 'id');

        $this->assertSame('Telefonlar', $titles[Fixture::CATEGORY_PHONES_ID]);
        $this->assertSame('Computers', $titles[Fixture::CATEGORY_COMPUTERS_ID]);
    }

    public function test_search_in_another_language_finds_an_untranslated_product()
    {
        $this->assertSame(0, DB::table('product_langs')->where('product_id', Fixture::PRODUCT_PHONE_ID)->count());

        $response = $this->withHeaders(['Language' => 'tr'])->getJson('/api/v1/search?q=Fixturo%20Phone');

        $products = collect($response->json('data.product'));
        $phone = $products->firstWhere('id', Fixture::PRODUCT_PHONE_ID);
        $this->assertNotNull($phone, 'the untranslated product is found');
        $this->assertSame(Product::find(Fixture::PRODUCT_PHONE_ID)->title, $phone['title']);
    }
}
