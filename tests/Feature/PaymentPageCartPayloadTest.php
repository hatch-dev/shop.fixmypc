<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * What /cart/by-user answers with for the account that saw "VAT 0.00".
 *
 * The payment page showed Products Total 1,598.00 beside a VAT of nothing and
 * original prices of 0.00 — the signature of rows that never received their
 * VAT split rather than rows that received a wrong one. This asks the real
 * endpoint, as the real account, and reports which fields are actually on the
 * wire.
 */
class PaymentPageCartPayloadTest extends TestCase
{
    use DatabaseTransactions;

    private function customer()
    {
        $user = User::where('email', 'business.test01@yopmail.com')->first();

        if (!$user) {
            $this->markTestSkipped('the reported account is not in this database');
        }

        Passport::actingAs($user, ['*'], 'user');

        return $user;
    }


    public function test_the_basket_endpoint_sends_every_field_the_summary_reads()
    {
        $this->customer();

        $rows = collect($this->getJson('/api/v1/cart/by-user')->json('data'));

        $this->assertGreaterThan(0, count($rows), 'the account has a basket');

        /*
         * Exactly the keys CheckoutRight and the Original Products list read.
         * A missing one does not throw anywhere — it renders as 0.00, which is
         * why this failed silently on the last screen before payment.
         */
        $required = [
            'unit_price', 'original_price', 'line_total',
            'vat_rate', 'vat_inclusive',
            'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
            'line_ex_vat', 'line_vat', 'line_inc_vat',
            'line_list', 'line_list_ex_vat', 'line_list_inc_vat',
        ];

        foreach ($rows as $row) {
            $title = $row['flash_product']['title'] ?? ('product ' . $row['product_id']);

            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $row,
                    "'$key' is missing from the payload for $title");
            }

            // And the split has to close, per line, on its own basis.
            $this->assertEqualsWithDelta(
                (float)$row['line_ex_vat'] + (float)$row['line_vat'],
                (float)$row['line_inc_vat'],
                0.02,
                "ex + VAT != inc for $title"
            );
        }
    }


    /**
     * The same basket, asked for with a language header.
     *
     * byUser builds a completely different query when the storefront sends
     * one, and the storefront always sends one. If the split survives without
     * the header but not with it, every screen in the flow is being served the
     * broken branch and the payload above proves nothing.
     */
    public function test_the_same_fields_survive_a_localised_request()
    {
        $this->customer();

        foreach (['en', 'fr'] as $lang) {
            $rows = collect(
                $this->getJson('/api/v1/cart/by-user', ['language' => $lang])->json('data')
            );

            $this->assertGreaterThan(0, count($rows), "[$lang] the account has a basket");

            foreach ($rows as $row) {
                foreach (['vat_rate', 'vat_inclusive', 'line_vat', 'line_list', 'original_price'] as $key) {
                    $this->assertArrayHasKey($key, $row,
                        "[$lang] '$key' is missing — this is the branch the storefront uses");
                }

                $this->assertEqualsWithDelta(
                    (float)$row['line_ex_vat'] + (float)$row['line_vat'],
                    (float)$row['line_inc_vat'],
                    0.02,
                    "[$lang] the split does not close"
                );
            }
        }
    }


    /**
     * The one the payment page actually depends on: an ex-VAT line has to
     * report VAT to add. Zero here is what put "VAT 0.00" on screen.
     */
    public function test_an_ex_vat_line_reports_vat_to_add()
    {
        $this->customer();

        $rows = collect($this->getJson('/api/v1/cart/by-user')->json('data'));

        $exVat = $rows->first(function ($row) {
            return isset($row['vat_inclusive'])
                && !$row['vat_inclusive']
                && (float)($row['vat_rate'] ?? 0) > 0;
        });

        if (!$exVat) {
            $this->markTestSkipped('this basket holds no ex-VAT line right now');
        }

        $this->assertGreaterThan(0, (float)$exVat['line_vat'],
            'an ex-VAT line must carry the VAT that gets added to it');
    }
}
