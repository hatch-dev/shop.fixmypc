<?php

namespace Tests\Isolated;

use App\Models\Product;
use App\Models\UpdatedInventory;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedTestCase;

/**
 * Admin: the bulk product editor's save rules, barcode handling, and the
 * wallet overview list (2026-11-03).
 *
 * Bulk save: each rule guards the field it is about, and only when the save
 * writes that field. A product carrying an old gap elsewhere (a stock row
 * imported without a SKU, a slug never set, a back-ordered variant below
 * zero) can still have its price edited; what IS written still answers to
 * every rule; rows the save does not change are not rewritten.
 */
class AdminBulkWalletBarcodeTest extends IsolatedTestCase
{
    private function stockRow($id = Fixture::LAPTOP_INVENTORY_ID)
    {
        return UpdatedInventory::findOrFail($id);
    }

    /** The row the bulk editor submits for the laptop, as it stands. */
    private function laptopRow(array $productOverrides = [], array $stockOverrides = [], $mode = null)
    {
        $product = Product::findOrFail(Fixture::PRODUCT_LAPTOP_ID);
        $stock = $this->stockRow();

        $row = array_merge([
            'id' => $product->id,
            'title' => $product->title,
            'slug' => $product->slug,
            'selling' => (string)$product->selling,
            'discount_type' => $product->discount_type,
            'discount_value' => $product->discount_value,
            'offered' => (string)$product->offered,
            'status' => $product->status,
            'upsell_id' => $product->upsell_id,
            'bundle_deal_id' => $product->bundle_deal_id,
            'product_inventories' => [array_merge([
                'id' => $stock->id,
                'price' => (string)$stock->price,
                'quantity' => (string)$stock->quantity,
                'sku' => $stock->sku,
                'imei' => $stock->imei,
                'barcode' => $stock->barcode,
                'is_active' => (int)$stock->is_active,
            ], $stockOverrides)],
        ], $productOverrides);

        if ($mode) {
            $row['mode'] = $mode;
        }

        return $row;
    }

    private function bulkSave(array $rows)
    {
        return $this->asAdmin()->post('/api/admin/product/bulk-update', [
            'products' => json_encode($rows),
        ]);
    }

    // ------------------------------------------------------------ bulk save

    public function test_a_price_edit_saves_although_an_untouched_stock_row_has_no_sku()
    {
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)->update(['sku' => '']);

        $response = $this->bulkSave([$this->laptopRow(['selling' => '1200.00', 'offered' => '1200.00'])]);

        $this->assertApiOk($response);
        $this->assertEquals(1200, (float)Product::find(Fixture::PRODUCT_LAPTOP_ID)->selling, 'the price edit saved');
        $this->assertSame('', (string)$this->stockRow()->sku, 'the untouched row was not rewritten');
        $this->assertStringContainsString('no SKU yet',
            implode(' ', $response->json('data.warnings.' . Fixture::PRODUCT_LAPTOP_ID)), 'and the gap is reported');
    }

    public function test_editing_a_stock_row_with_no_sku_is_refused_on_its_sku_field_only()
    {
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)->update(['sku' => '']);
        $before = (float)$this->stockRow()->price;

        $response = $this->bulkSave([$this->laptopRow([], ['price' => '999.00'])]);

        $this->assertSame(201, $response->json('status'), 'refused');
        $fields = $response->json('data.fields.' . Fixture::PRODUCT_LAPTOP_ID);
        $this->assertSame(['inventory.' . Fixture::LAPTOP_INVENTORY_ID . '.sku'], array_keys($fields),
            'one message, on the SKU of that stock row');
        $this->assertCount(1, $response->json('data.form'), 'stated once');
        $this->assertEquals($before, (float)$this->stockRow()->price, 'nothing was written');

        // Given its SKU, the same edit saves.
        $this->assertApiOk($this->bulkSave([$this->laptopRow([], ['price' => '999.00', 'sku' => 'LAP-1'])]));
        $this->assertEquals(999, (float)$this->stockRow()->price);
        $this->assertSame('LAP-1', $this->stockRow()->sku);
    }

    public function test_blanking_an_existing_sku_is_refused()
    {
        $sku = $this->stockRow()->sku;
        $this->assertNotSame('', (string)$sku);

        $response = $this->bulkSave([$this->laptopRow([], ['sku' => '   '])]);

        $this->assertSame(201, $response->json('status'));
        $this->assertSame($sku, $this->stockRow()->sku, 'the SKU is untouched');
    }

    public function test_a_slug_never_set_does_not_block_a_price_edit_but_clearing_one_is_refused()
    {
        DB::table('products')->where('id', Fixture::PRODUCT_LAPTOP_ID)->update(['slug' => '']);

        $response = $this->bulkSave([$this->laptopRow(['selling' => '1100.00', 'offered' => '1100.00'])]);

        $this->assertApiOk($response);
        $this->assertEquals(1100, (float)Product::find(Fixture::PRODUCT_LAPTOP_ID)->selling);
        $this->assertStringContainsString('no slug yet',
            implode(' ', $response->json('data.warnings.' . Fixture::PRODUCT_LAPTOP_ID)));

        // A product that has a slug cannot have it cleared, or take another's.
        $phone = Product::findOrFail(Fixture::PRODUCT_PHONE_ID);
        DB::table('products')->where('id', Fixture::PRODUCT_LAPTOP_ID)->update(['slug' => 'fixturo-laptop-pro']);

        $cleared = $this->bulkSave([$this->laptopRow(['slug' => ''])]);
        $this->assertSame(201, $cleared->json('status'));
        $this->assertArrayHasKey('slug', $cleared->json('data.fields.' . Fixture::PRODUCT_LAPTOP_ID));

        $taken = $this->bulkSave([$this->laptopRow(['slug' => $phone->slug])]);
        $this->assertSame(201, $taken->json('status'));
        $this->assertSame('fixturo-laptop-pro', Product::find(Fixture::PRODUCT_LAPTOP_ID)->slug);
    }

    public function test_the_bulk_edit_modal_does_not_check_stock_rows_it_never_writes()
    {
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)->update(['sku' => '']);
        $before = (float)$this->stockRow()->price;

        $response = $this->bulkSave([$this->laptopRow(['status' => 2], ['price' => '1.00'], 'merge')]);

        $this->assertApiOk($response);
        $this->assertSame(2, (int)Product::find(Fixture::PRODUCT_LAPTOP_ID)->status, 'the status change saved');
        $this->assertEquals($before, (float)$this->stockRow()->price, 'a merge writes no stock row');
    }

    public function test_back_ordered_stock_below_zero_does_not_block_a_price_edit()
    {
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)
            ->update(['quantity' => -2, 'is_active' => 1]);

        $this->assertApiOk($this->bulkSave([$this->laptopRow([], ['price' => '950.00'])]));
        $this->assertEquals(950, (float)$this->stockRow()->price);
        $this->assertSame(-2, (int)$this->stockRow()->quantity, 'the back-ordered quantity is left as it is');

        // Setting a negative quantity is still refused.
        $response = $this->bulkSave([$this->laptopRow([], ['quantity' => '-5'])]);
        $this->assertSame(201, $response->json('status'));
        $this->assertSame(-2, (int)$this->stockRow()->quantity);
    }

    // ------------------------------------------------------------- barcode

    public function test_a_barcode_is_free_text_on_both_save_paths_and_stored_trimmed()
    {
        // The bulk editor: letters, digits and symbols, trimmed; blank is null.
        $this->assertApiOk($this->bulkSave([$this->laptopRow([], ['barcode' => '  ABC-123/x.9  ', 'imei' => ' 356938035643809 '])]));
        $this->assertSame('ABC-123/x.9', $this->stockRow()->barcode);
        $this->assertSame('356938035643809', $this->stockRow()->imei);

        $this->assertApiOk($this->bulkSave([$this->laptopRow([], ['barcode' => '   '])]));
        $this->assertNull($this->stockRow()->barcode, 'a blank barcode is stored as none, as the product form does');

        // A leading zero survives: the value is text, not a number.
        $this->assertApiOk($this->bulkSave([$this->laptopRow([], ['barcode' => '0012345678905'])]));
        $this->assertSame('0012345678905', $this->stockRow()->barcode);

        // The product form's stock endpoint takes the same text.
        $stock = $this->stockRow();
        $response = $this->asAdmin()->postJson('/api/admin/updated-inventory/action/' . Fixture::PRODUCT_LAPTOP_ID, [
            'inventories' => [[
                'id' => $stock->id, 'sku' => $stock->sku, 'price' => $stock->price,
                'quantity' => $stock->quantity, 'is_active' => 0,
                'imei' => '', 'barcode' => 'EAN13-5012345678900', 'values' => [],
            ]],
        ]);
        $this->assertApiOk($response);
        $this->assertSame('EAN13-5012345678900', $this->stockRow()->barcode);
    }

    public function test_duplicating_a_product_does_not_copy_its_barcode_or_imei()
    {
        DB::table('updated_inventories')->where('id', Fixture::LAPTOP_INVENTORY_ID)
            ->update(['barcode' => '5012345678900', 'imei' => '356938035643809']);

        // This endpoint answers with the new product itself, not the usual wrapper.
        $response = $this->asAdmin()->postJson('/api/admin/products/' . Fixture::PRODUCT_LAPTOP_ID . '/duplicate');
        $response->assertSuccessful();

        $copyId = $response->json('id');
        $this->assertNotEquals(Fixture::PRODUCT_LAPTOP_ID, $copyId);
        $copy = UpdatedInventory::where('product_id', $copyId)->firstOrFail();

        $this->assertNull($copy->barcode, 'the copy gets no barcode until one is entered');
        $this->assertNull($copy->imei, 'nor an IMEI, which belongs to one device');
        $this->assertStringContainsString('-COPY-', $copy->sku);
        $this->assertSame('5012345678900', $this->stockRow()->barcode, 'the original keeps its barcode');
    }

    // ------------------------------------------------------- wallet overview

    private function walletActivity()
    {
        DB::table('wallet_transactions')->insert([
            ['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'credit', 'amount' => 50, 'balance_after' => 50,
                'source' => 'topup', 'status' => 'success', 'created_at' => '2026-02-21 10:00:00', 'updated_at' => '2026-02-21 10:00:00'],
            ['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'debit', 'amount' => 20, 'balance_after' => 30,
                'source' => 'order', 'status' => 'success', 'created_at' => '2026-03-03 09:00:00', 'updated_at' => '2026-03-03 09:00:00'],
            ['user_id' => Fixture::USER_BUSINESS_ID, 'type' => 'credit', 'amount' => 10, 'balance_after' => 10,
                'source' => 'topup', 'status' => 'success', 'created_at' => '2026-01-05 08:00:00', 'updated_at' => '2026-01-05 08:00:00'],
        ]);
        DB::table('point_transactions')->insert([
            ['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'credit', 'points' => 300, 'value' => 3,
                'source' => 'order', 'created_at' => '2026-02-25 12:00:00', 'updated_at' => '2026-02-25 12:00:00'],
            ['user_id' => Fixture::USER_PERSONAL_ID, 'type' => 'debit', 'points' => 100, 'value' => 1,
                'source' => 'order', 'created_at' => '2026-02-26 12:00:00', 'updated_at' => '2026-02-26 12:00:00'],
        ]);
    }

    public function test_the_wallet_overview_is_a_paginated_list_of_customers_with_their_balances()
    {
        $this->walletActivity();

        $response = $this->asAdmin()->getJson('/api/admin/wallet-overview/all');
        $this->assertApiOk($response);

        $this->assertSame(2, $response->json('data.total'), 'one entry per customer, with a total');
        $this->assertSame(1, $response->json('data.last_page'));

        $first = $response->json('data.data.0');
        $this->assertSame(Fixture::USER_PERSONAL_ID, $first['user_id'], 'newest activity first');
        $this->assertEquals(30, $first['wallet_balance'], 'the latest balance_after, as before');
        $this->assertSame(200, $first['cherry_points'], 'credits less debits, as before');
        $this->assertSame('03 Mar 2026', $first['last_activity']);

        // By real time, not by the date string ("21 Feb" would sort after "03 Mar").
        $this->assertSame(['03 Mar 2026', '26 Feb 2026', '25 Feb 2026', '21 Feb 2026'],
            array_column($first['transactions'], 'created'));
    }

    public function test_the_wallet_overview_search_narrows_by_name_or_email()
    {
        $this->walletActivity();

        $email = DB::table('users')->where('id', Fixture::USER_BUSINESS_ID)->value('email');
        $response = $this->asAdmin()->getJson('/api/admin/wallet-overview/all?q=' . urlencode($email));

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(Fixture::USER_BUSINESS_ID, $response->json('data.data.0.user_id'));
    }
}
