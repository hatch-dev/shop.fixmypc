<?php

namespace Tests\Isolated;

use App\Models\Voucher;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\Config;
use Tests\IsolatedTestCase;

/**
 * My vouchers (GET api/v1/user/user-vouchers), 2026-11-20.
 *
 * Private vouchers were listed (the status filter was commented out) although
 * the checkout refuses them. Public ones, expired ones (the page files them
 * under Expired) and the customer's own gift cards must stay listed.
 */
class MyVouchersListTest extends IsolatedTestCase
{
    private function voucher(array $over)
    {
        return Voucher::forceCreate(array_merge([
            'title' => 'List test', 'type' => Config::get('constants.priceType.PERCENT'), 'price' => 10,
            'status' => Config::get('constants.status.PUBLIC'), 'usage_limit' => 100, 'limit_per_customer' => 5,
            'min_spend' => 0, 'apply_type' => 1, 'admin_id' => Fixture::ADMIN_SUPER_ID,
            'start_time' => now()->subDay(), 'end_time' => now()->addMonth(),
        ], $over));
    }

    private function listedCodes()
    {
        $reply = $this->asCustomer()->getJson('/api/v1/user/user-vouchers?page=1&order_by=id&type=desc');
        $this->assertApiOk($reply);

        return collect($reply->json('data.data'))->pluck('code')->all();
    }

    public function test_private_vouchers_are_not_listed_but_public_expired_and_own_gift_cards_are()
    {
        $public = $this->voucher(['code' => 'LIST-PUBLIC']);
        $private = $this->voucher(['code' => 'LIST-PRIVATE', 'status' => Config::get('constants.status.PRIVATE')]);
        $expired = $this->voucher(['code' => 'LIST-EXPIRED', 'start_time' => now()->subMonth(), 'end_time' => now()->subDay()]);
        $gift = $this->voucher(['code' => 'GIFT-LIST-TEST', 'type' => Config::get('constants.priceType.FLAT'),
            'price' => 25, 'gift_value' => 25, 'user_id' => Fixture::USER_PERSONAL_ID]);

        $codes = $this->listedCodes();

        $this->assertContains($public->code, $codes);
        $this->assertContains($expired->code, $codes, 'expired vouchers stay for the Expired section');
        $this->assertContains($gift->code, $codes, "the customer's own gift card stays listed");
        $this->assertNotContains($private->code, $codes, 'a private voucher is not listed');
    }

    public function test_another_customers_gift_card_is_still_not_listed()
    {
        $this->voucher(['code' => 'GIFT-SOMEONE-ELSE', 'type' => Config::get('constants.priceType.FLAT'),
            'price' => 25, 'gift_value' => 25, 'user_id' => Fixture::USER_BUSINESS_ID]);

        $this->assertNotContains('GIFT-SOMEONE-ELSE', $this->listedCodes());
    }
}
