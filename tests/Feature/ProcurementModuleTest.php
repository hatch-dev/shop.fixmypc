<?php

namespace Tests\Feature;

use App\Http\Controllers\BidController;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Helper\PhoneRules;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Supplier;
use App\Models\SupplierBid;
use App\Models\SupplierNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Procurement, end to end.
 *
 * A product flagged `procurement` is one we sell and then source. Placing an
 * order containing one sends a WhatsApp to every supplier covering that
 * product's categories, carrying an encrypted link to a page where they give
 * us a price. That price comes back into supplier_bids and shows up under
 * Procurement -> Requests.
 *
 * Every test here pins something that was broken, and the docblock says what.
 * The headline one: the bid page collected a price and a grade and then threw
 * them away -- submitBid() logged to the browser console, and there was no
 * endpoint, no table and no model behind it.
 */
class ProcurementModuleTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        $admin = Admin::whereHas('roles', function ($query) {
            $query->where('name', 'superadmin');
        })->first();

        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }

        $this->app['auth']->forgetGuards();
        Passport::actingAs($admin, ['admin'], 'admin');

        return $admin;
    }

    /** Run $callback with $permission taken away from the superadmin role. */
    private function without($permission, callable $callback)
    {
        $role = Role::where('name', 'superadmin')->where('guard_name', 'admin')->first();
        $perm = Permission::where('name', $permission)->where('guard_name', 'admin')->first();

        if (!$role || !$perm) {
            $this->markTestSkipped($permission . ' is not a registered permission.');
        }

        $role->revokePermissionTo($perm);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        try {
            $this->admin();
            return $callback();
        } finally {
            $role->givePermissionTo($perm);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    private function supplier(array $over = [])
    {
        return Supplier::create(array_merge([
            'name'         => 'QA Supplier ' . uniqid(),
            'email'        => 'qa-' . uniqid() . '@example.invalid',
            'country_code' => '+353',
            'phone'        => '870000000',
            'address'      => 'Somewhere',
        ], $over));
    }

    /** A supplier who has been asked to quote on one line of one order. */
    private function request($supplier = null, $productId = 987654321, $inventoryId = 123456789)
    {
        $supplier = $supplier ?: $this->supplier();
        $order = Order::orderByDesc('id')->firstOrFail();

        $notification = SupplierNotification::create([
            'supplier_id'  => $supplier->id,
            'product_id'   => $productId,
            'inventory_id' => $inventoryId,
            'order_id'     => $order->id,
        ]);

        return [$supplier, $order, $notification];
    }

    private function token($orderId, $supplierId)
    {
        return Crypt::encryptString(json_encode([
            'order_id'    => $orderId,
            'supplier_id' => $supplierId,
        ]));
    }

    /* ------------------------------------------------------------------ *
     *  The supplier's side: reading the request, sending a price
     * ------------------------------------------------------------------ */

    /**
     * The whole feature's point. submitBid() in the bid page used to be a
     * console.log: there was no endpoint, no supplier_bids table and no
     * model, so a supplier who typed a price and pressed Submit had it
     * discarded silently.
     */
    public function test_a_supplier_can_send_us_a_price()
    {
        list($supplier, $order, $notification) = $this->request();

        $response = $this->postJson('/api/v1/order/bid', [
            'token'        => $this->token($order->id, $supplier->id),
            'product_id'   => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'price'        => 249.5,
            'grade'        => 'Grade A',
        ]);

        $response->assertStatus(200);

        $bid = SupplierBid::where('supplier_notification_id', $notification->id)->first();

        $this->assertNotNull($bid, 'The bid was not recorded.');
        $this->assertEquals('249.50', $bid->price);
        $this->assertEquals('Grade A', $bid->grade);
        $this->assertEquals($order->id, $bid->order_id);
    }

    /** Revising a price replaces it; it does not stack a second bid. */
    public function test_resubmitting_updates_the_same_bid()
    {
        list($supplier, $order, $notification) = $this->request();

        $send = function ($price) use ($supplier, $order, $notification) {
            return $this->postJson('/api/v1/order/bid', [
                'token'        => $this->token($order->id, $supplier->id),
                'product_id'   => $notification->product_id,
                'inventory_id' => $notification->inventory_id,
                'price'        => $price,
                'grade'        => 'Grade B',
            ]);
        };

        $send(100)->assertStatus(200);
        $send(90)->assertStatus(200);

        $bids = SupplierBid::where('supplier_notification_id', $notification->id)->get();

        $this->assertCount(1, $bids);
        $this->assertEquals('90.00', $bids->first()->price);
    }

    /**
     * The link names an order and a supplier, nothing else. Without this a
     * supplier holding one valid link could quote on any product id they
     * cared to name, on that order or otherwise.
     */
    public function test_a_supplier_cannot_bid_on_a_line_it_was_not_asked_about()
    {
        list($supplier, $order, $notification) = $this->request();

        $this->postJson('/api/v1/order/bid', [
            'token'        => $this->token($order->id, $supplier->id),
            'product_id'   => $notification->product_id + 1,
            'inventory_id' => $notification->inventory_id,
            'price'        => 10,
            'grade'        => 'Grade A',
        ])->assertStatus(404);

        $this->assertSame(0, SupplierBid::where('order_id', $order->id)->count());
    }

    /** The grade list lives on the model, not only in the page's dropdown. */
    public function test_a_grade_outside_the_list_is_refused()
    {
        list($supplier, $order, $notification) = $this->request();

        $this->postJson('/api/v1/order/bid', [
            'token'        => $this->token($order->id, $supplier->id),
            'product_id'   => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'price'        => 10,
            'grade'        => 'Grade Z',
        ])->assertStatus(422);
    }

    public function test_a_price_of_zero_or_less_is_refused()
    {
        list($supplier, $order, $notification) = $this->request();

        foreach ([0, -5] as $price) {
            $this->postJson('/api/v1/order/bid', [
                'token'        => $this->token($order->id, $supplier->id),
                'product_id'   => $notification->product_id,
                'inventory_id' => $notification->inventory_id,
                'price'        => $price,
                'grade'        => 'Grade A',
            ])->assertStatus(422);
        }
    }

    /**
     * A bad token used to come back as an HTTP 500 carrying the decryption
     * exception's message verbatim.
     */
    public function test_a_bad_link_is_a_plain_404_not_a_500()
    {
        $response = $this->getJson('/api/v1/order/not-a-real-token');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('payload', strtolower($response->getContent()));
        $this->assertStringNotContainsString('mac', strtolower($response->getContent()));
    }

    /**
     * A token can only be minted by us, but it named a pairing rather than
     * proving one. A supplier who was never asked about an order must not be
     * able to read it.
     */
    public function test_a_token_for_a_pairing_that_was_never_asked_is_refused()
    {
        $order = Order::orderByDesc('id')->firstOrFail();
        $supplier = $this->supplier();

        $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(404);
    }

    /**
     * The link is a bearer credential sitting in a WhatsApp thread forever,
     * and what it opens is a customer's order. It had no expiry at all.
     */
    public function test_an_old_link_stops_working()
    {
        list($supplier, $order, $notification) = $this->request();

        $notification->created_at = now()->subDays(BidController::LINK_TTL_DAYS + 1);
        $notification->save();

        $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(410);
    }

    /** Asking the supplier again about the same order re-opens their link. */
    public function test_asking_again_reopens_an_expired_link()
    {
        list($supplier, $order, $notification) = $this->request();

        $notification->created_at = now()->subDays(BidController::LINK_TTL_DAYS + 1);
        $notification->save();

        SupplierNotification::create([
            'supplier_id'  => $supplier->id,
            'product_id'   => $notification->product_id + 1,
            'inventory_id' => $notification->inventory_id,
            'order_id'     => $order->id,
        ]);

        $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(200);
    }

    /**
     * The page reads supplier_id off the response. It used to hold one in
     * `data` and never assign it, so the bid it posted named the empty
     * string -- which is why the bid endpoint takes the supplier from the
     * token and ignores the body.
     */
    public function test_the_request_page_is_told_which_supplier_it_is()
    {
        list($supplier, $order, $notification) = $this->request();

        $body = $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(200)
            ->json();

        $this->assertEquals($supplier->id, $body['supplier_id']);
        $this->assertEquals($order->order, $body['order_number']);
        $this->assertNotEmpty($body['grades']);
    }

    /** A bid already given comes back with the line so the form can show it. */
    public function test_an_existing_bid_is_returned_with_the_line()
    {
        list($supplier, $order, $notification) = $this->request();

        SupplierBid::create([
            'supplier_notification_id' => $notification->id,
            'supplier_id'  => $supplier->id,
            'order_id'     => $order->id,
            'product_id'   => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'price'        => 12.34,
            'grade'        => 'Like New',
        ]);

        $body = $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->json();

        $this->assertEquals('12.34', $body['data'][0]['bid']['price']);
        $this->assertEquals('Like New', $body['data'][0]['bid']['grade']);
    }

    /* ------------------------------------------------------------------ *
     *  The admin side: suppliers, and reading the answers
     * ------------------------------------------------------------------ */

    /**
     * SupplierController gated on nothing at all. The routes sit behind
     * auth:admin and this application has no permission middleware, so any
     * admin token -- a vendor's included -- could read, edit and delete every
     * supplier in the shop.
     */
    public function test_every_supplier_endpoint_needs_its_permission()
    {
        $this->admin();
        $supplier = $this->supplier();

        $cases = [
            'supplier.view'   => ['get', '/api/admin/suppliers/all'],
            'supplier.create' => ['post', '/api/admin/suppliers/action'],
            'supplier.edit'   => ['post', '/api/admin/suppliers/action/' . $supplier->id],
            'supplier.delete' => ['delete', '/api/admin/suppliers/delete/' . $supplier->id],
        ];

        foreach ($cases as $permission => $call) {
            $body = $this->without($permission, function () use ($call) {
                return $this->{$call[0] . 'Json'}($call[1], [])->json();
            });

            // A refusal is reported in the body, not the HTTP code, the way
            // every admin endpoint here reports one.
            $this->assertEquals(403, $body['status'], $permission . ' was not enforced');
        }

        $this->assertNotNull($supplier->fresh(), 'The supplier was deleted by an unpermitted call.');
    }

    /**
     * action() used updateOrCreate(['id' => $id]), so posting an id that
     * names nothing made a brand new supplier under a fresh id and reported
     * "Supplier updated successfully".
     */
    public function test_editing_a_supplier_that_does_not_exist_is_a_404_not_a_create()
    {
        $this->admin();

        $before = Supplier::count();

        $body = $this->postJson('/api/admin/suppliers/action/999999999', [
            'name'         => 'Ghost',
            'email'        => 'ghost-' . uniqid() . '@example.invalid',
            'country_code' => '+353',
            'phone'        => '870000000',
            'address'      => 'Nowhere',
            'categories'   => [Category::value('id')],
        ])->json();

        $this->assertEquals(404, $body['status']);
        $this->assertSame($before, Supplier::count());
    }

    /**
     * supplier_categories cascades on delete; supplier_notifications has no
     * foreign key and had no cleanup, so a deleted supplier left its
     * procurement requests behind -- and those rows are exactly what a bid
     * link is checked against, so the link kept working.
     */
    public function test_deleting_a_supplier_takes_its_requests_and_bids_with_it()
    {
        $this->admin();

        list($supplier, $order, $notification) = $this->request();

        SupplierBid::create([
            'supplier_notification_id' => $notification->id,
            'supplier_id'  => $supplier->id,
            'order_id'     => $order->id,
            'product_id'   => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'price'        => 50,
            'grade'        => 'Grade C',
        ]);

        $this->deleteJson('/api/admin/suppliers/delete/' . $supplier->id)
            ->assertStatus(200);

        $this->assertNull(Supplier::find($supplier->id));
        $this->assertSame(0, SupplierNotification::where('supplier_id', $supplier->id)->count());
        $this->assertSame(0, SupplierBid::where('supplier_id', $supplier->id)->count());

        // And the link that pointed at them stops resolving.
        $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(404);
    }

    /**
     * The list search joined categories on a `name` column. categories has
     * `title` and no `name`, so any search at all threw an unhandled SQL
     * error and the endpoint 500'd.
     */
    public function test_searching_the_supplier_list_does_not_error()
    {
        $this->admin();
        $supplier = $this->supplier(['name' => 'Findable Supplier']);

        $body = $this->getJson('/api/admin/suppliers/all?q=Findable')->json();

        $this->assertEquals(200, $body['status']);
        // suppliers/all is a plain paginator; only the sourcing board nests one.
        $this->assertContains($supplier->id, array_column($body['data']['data'], 'id'));
    }

    /**
     * Bids were storable but invisible: nothing in the admin read
     * supplier_bids, so a price a supplier sent was seen by no one.
     */
    public function test_the_admin_can_see_what_a_supplier_quoted()
    {
        $this->admin();

        list($supplier, $order, $notification) = $this->request();

        SupplierBid::create([
            'supplier_notification_id' => $notification->id,
            'supplier_id'  => $supplier->id,
            'order_id'     => $order->id,
            'product_id'   => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'price'        => 77.5,
            'grade'        => 'Grade A',
        ]);

        $body = $this->getJson('/api/admin/suppliers/sourcing?order_id=' . $order->id)->json();

        $this->assertEquals(200, $body['status']);

        $row = $body['data']['orders']['data'][0];

        $this->assertEquals($order->id, $row['order_id']);
        $this->assertEquals(1, $row['line_count']);
        $this->assertEquals(1, $row['quoted_count']);

        $quote = $row['lines'][0]['quotes'][0];

        $this->assertEquals($supplier->id, $quote['supplier']['id']);
        $this->assertEquals(77.50, $quote['price']);
        $this->assertEquals(SupplierBid::PENDING, $quote['status']);
    }

    /**
     * One card per order, however many procurement products the basket
     * held -- the board groups lines under the order they belong to.
     */
    public function test_lines_are_grouped_under_their_order()
    {
        $this->admin();

        list($supplier, $order, $notification) = $this->request();

        SupplierNotification::create([
            'supplier_id'  => $supplier->id,
            'product_id'   => $notification->product_id + 1,
            'inventory_id' => $notification->inventory_id,
            'order_id'     => $order->id,
        ]);

        $body = $this->getJson('/api/admin/suppliers/sourcing?order_id=' . $order->id)->json();

        // One card, two lines inside it -- not two cards.
        $this->assertCount(1, $body['data']['orders']['data'], 'The order was split into two cards.');

        $row = $body['data']['orders']['data'][0];

        $this->assertEquals(2, $row['line_count']);
        $this->assertCount(2, $row['lines']);
        $this->assertEquals(0, $row['quoted_count']);
        $this->assertEquals(0, $row['awarded_count']);

        // Nothing awarded yet, so the order owes nothing.
        $this->assertEquals(0, $row['total_payable']);
    }

    public function test_the_sourcing_board_needs_supplier_view()
    {
        $this->admin();

        $body = $this->without('supplier.view', function () {
            return $this->getJson('/api/admin/suppliers/sourcing')->json();
        });

        $this->assertEquals(403, $body['status']);
    }

    /**
     * The supplier form filled its category dropdown from
     * product/with-categories -- which serialises every product in the shop,
     * with two appended attributes that each cost a query per row -- and
     * gated on category.view, a permission procurement has no reason to need.
     */
    public function test_the_category_picker_has_its_own_light_endpoint()
    {
        $this->admin();

        $body = $this->getJson('/api/admin/suppliers/categories')->json();

        $this->assertEquals(200, $body['status']);
        $this->assertNotEmpty($body['data']['categories']);

        // Only what the picker uses, so the payload cannot drift into
        // carrying every category's meta description again.
        $this->assertEquals(['id', 'title'], array_keys($body['data']['categories'][0]));
    }

    /**
     * supplier_notifications had no key of any kind. The duplicate guard was
     * a read-then-write, and a product in two of one supplier's categories
     * recorded its line once per category.
     */
    public function test_the_same_line_cannot_be_recorded_twice()
    {
        list($supplier, $order, $notification) = $this->request();

        $this->expectException(\Illuminate\Database\QueryException::class);

        SupplierNotification::create([
            'supplier_id'  => $supplier->id,
            'product_id'   => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'order_id'     => $order->id,
        ]);
    }
    /* ------------------------------------------------------------------ *
     *  The supplier form: every field, and the phone against its country
     * ------------------------------------------------------------------ */

    private function formPayload(array $over = [])
    {
        return array_merge([
            'name'       => 'QA Form Supplier',
            'email'      => 'form-' . uniqid() . '@example.com',
            'country'    => 'IE',
            'phone'      => '0874523344',
            'address'    => '12 Main Street, Dublin',
            'categories' => [Category::whereNull('parent')->value('id')],
        ], $over);
    }

    /** @return array the `fields` map from a refused save */
    private function refusal(array $over)
    {
        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload($over))->json();

        $this->assertEquals(201, $body['status'], 'The save was not refused.');

        return $body['data']['fields'];
    }

    /**
     * The form checked "is it non-empty?" in the browser and the server took
     * whatever arrived -- a one-character name, a phone with no digits in it,
     * two kilobytes of address.
     */
    public function test_every_field_is_checked_on_the_server()
    {
        $this->admin();

        $cases = [
            'name'       => [['name' => ''], ['name' => 'A'], ['name' => '   ']],
            'email'      => [['email' => ''], ['email' => 'not-an-email']],
            'country'    => [['country' => ''], ['country' => 'ZZ']],
            'phone'      => [['phone' => ''], ['phone' => 'abcdef']],
            'address'    => [['address' => ''], ['address' => 'abc']],
            'categories' => [['categories' => []], ['categories' => [999999999]]],
        ];

        foreach ($cases as $field => $payloads) {
            foreach ($payloads as $over) {
                $fields = $this->refusal($over);

                $this->assertArrayHasKey(
                    $field,
                    $fields,
                    $field . ' accepted ' . json_encode($over)
                );
            }
        }
    }

    /**
     * Refusals have to arrive as 201 with a `fields` map. DataPage reads that
     * to put each message against the control that caused it;
     * $request->validate() throws a 422 the admin panel cannot unpack, which
     * is why this form never showed a server-side error.
     */
    public function test_a_refusal_names_the_field_it_is_about()
    {
        $this->admin();

        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload(['email' => 'nope']))
            ->json();

        $this->assertEquals(201, $body['status']);
        $this->assertArrayHasKey('fields', $body['data']);
        $this->assertArrayHasKey('form', $body['data']);
        $this->assertArrayHasKey('email', $body['data']['fields']);
    }

    /**
     * The validator names the element it refused (`categories.0`), but the
     * form has one control for the set and anchors it `data-field=
     * "categories"`. An indexed key has nothing to attach to.
     */
    public function test_a_category_refusal_lands_on_the_categories_control()
    {
        $this->admin();

        $fields = $this->refusal(['categories' => [999999999]]);

        $this->assertArrayHasKey('categories', $fields);
        $this->assertArrayNotHasKey('categories.0', $fields);
    }

    /**
     * The headline of this pass: a number is checked against the country the
     * picker is showing a flag for. The form used to accept any twenty
     * characters, so a supplier could be saved with a number Twilio would
     * never deliver to and nothing anywhere would say so.
     */
    public function test_a_phone_is_checked_against_the_chosen_country()
    {
        $this->admin();

        // Ten digits: right for India, too long for Ireland.
        $this->assertArrayHasKey(
            'phone',
            $this->refusal(['country' => 'IE', 'phone' => '9876274711']),
            'An Indian-length number was accepted as Irish.'
        );

        $this->postJson('/api/admin/suppliers/action', $this->formPayload([
            'country' => 'IN',
            'phone'   => '9876274711',
        ]))->assertStatus(200)->assertJsonPath('status', 200);
    }

    /** A trunk prefix is how people write the number; it is not part of it. */
    public function test_a_leading_zero_is_accepted_and_stored_stripped()
    {
        $this->admin();

        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload([
            'country' => 'IE',
            'phone'   => '087 452 3344',
        ]))->json();

        $this->assertEquals(200, $body['status']);
        $this->assertEquals('874523344', $body['data']['phone']);
        $this->assertEquals('+353', $body['data']['country_code']);
        $this->assertEquals('IE', $body['data']['country']);
    }

    /**
     * phones.json calls the United Kingdom "UK"; countries.json, the flag
     * codepoints and the length rules all use "GB". Unreconciled, a UK
     * supplier got the generic 4-14 rule instead of the UK's 9-10.
     */
    public function test_the_uk_is_reconciled_to_its_iso_code()
    {
        $this->admin();

        $this->assertEquals([9, 10], PhoneRules::rangeFor('GB'));
        $this->assertEquals([9, 10], PhoneRules::rangeFor('UK'));

        $this->assertArrayHasKey(
            'phone',
            $this->refusal(['country' => 'GB', 'phone' => '1234567']),
            'A 7-digit number was accepted as a UK number.'
        );

        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload([
            'country' => 'GB',
            'phone'   => '(020) 7946 0958',
        ]))->json();

        $this->assertEquals(200, $body['status']);
        $this->assertEquals('+44', $body['data']['country_code']);
    }

    /** The dialling code is derived from the country, never taken on trust. */
    public function test_the_browser_cannot_contradict_the_country_with_a_dial_code()
    {
        $this->admin();

        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload([
            'country'      => 'IE',
            'country_code' => '+91',
        ]))->json();

        $this->assertEquals(200, $body['status']);
        $this->assertEquals('+353', $body['data']['country_code']);
    }

    /**
     * A supplier is matched to an order line through the top-level
     * categories a product records, so a subcategory mapping would look
     * narrower than it is and never match anything.
     */
    public function test_the_picker_offers_top_level_categories_only()
    {
        $this->admin();

        $offered = collect($this->getJson('/api/admin/suppliers/categories')->json('data.categories'))
            ->pluck('id');

        $this->assertTrue($offered->isNotEmpty());
        $this->assertSame(
            0,
            Category::whereIn('id', $offered)->whereNotNull('parent')->count(),
            'The picker offered a subcategory.'
        );

        $child = Category::whereNotNull('parent')->value('id');

        if ($child) {
            $this->assertArrayHasKey('categories', $this->refusal(['categories' => [$child]]));
        }
    }

    /** The picker carries the rules so the form checks what the server will. */
    public function test_the_picker_ships_the_phone_rules()
    {
        $this->admin();

        $data = $this->getJson('/api/admin/suppliers/categories')->json('data');

        $this->assertEquals([9, 10], $data['phone_rules']['GB']);
        $this->assertEquals(PhoneRules::DEFAULT_RANGE, $data['phone_default']);
        $this->assertEquals('GB', $data['iso_aliases']['UK']);
    }
    /**
     * phones.json writes several dialling codes with a bracketed hint at what
     * follows -- "+1(268)" for Antigua, "+62(8)" for Indonesian mobiles,
     * "+7(7)" for Kazakhstan. They still dial correctly once toE164() strips
     * the punctuation, but they were stored and shown to an admin verbatim,
     * and "+1(268)" in a phone field reads as a mistake.
     */
    public function test_dialling_codes_are_stored_without_bracketed_hints()
    {
        $this->admin();

        $this->assertEquals('+1268', PhoneRules::normaliseDial('+1(268)'));
        $this->assertEquals('+628', PhoneRules::normaliseDial('+62(8)'));

        $codes = $this->getJson('/api/admin/suppliers/categories')->json('data.dial_codes');

        foreach ($codes as $iso => $dial) {
            $this->assertMatchesRegularExpression(
                '/^\+[0-9]+$/',
                $dial,
                $iso . ' has a dialling code that is not plain E.164: ' . $dial
            );
        }

        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload([
            'country' => 'AG',
            'phone'   => '4641234',
        ]))->json();

        $this->assertEquals(200, $body['status']);
        $this->assertEquals('+1268', $body['data']['country_code']);
    }

    /**
     * AN is the Netherlands Antilles, dissolved in 2010. Its entry (+5999) is
     * really Curacao's national format, and Curacao is on the list already as
     * CW (+599) -- so offering AN offered the same country twice, the second
     * time under a country that no longer exists and has no flag.
     */
    public function test_a_dissolved_country_is_not_offered()
    {
        $this->admin();

        $data = $this->getJson('/api/admin/suppliers/categories')->json('data');

        $this->assertArrayNotHasKey('AN', $data['dial_codes']);
        $this->assertArrayHasKey('CW', $data['dial_codes']);
        $this->assertContains('AN', $data['retired']);

        // And it cannot be saved by hand either.
        $body = $this->postJson('/api/admin/suppliers/action', $this->formPayload([
            'country' => 'AN',
        ]))->json();

        $this->assertEquals(201, $body['status']);
        $this->assertArrayHasKey('country', $body['data']['fields']);
    }

    /**
     * countries.json has no entry for Ascension Island, so the picker printed
     * the bare code "AC" on a row between Albania and Algeria.
     */
    public function test_a_country_with_no_name_gets_one()
    {
        $this->admin();

        $names = $this->getJson('/api/admin/suppliers/categories')->json('data.extra_names');

        $this->assertEquals('Ascension Island', $names['AC']);
    }

    /**
     * The picker shows a real flag image now, not an emoji: Windows has no
     * colour flag glyphs and rendered a regional indicator pair as the two
     * letters, which is most of this panel's audience.
     */
    public function test_every_offered_country_has_a_flag_file()
    {
        $this->admin();

        $dir = base_path('admin-panel/static/flags');

        if (!is_dir($dir)) {
            $this->markTestSkipped('The flag assets are not installed.');
        }

        $codes = array_keys($this->getJson('/api/admin/suppliers/categories')->json('data.dial_codes'));

        $missing = array_values(array_filter($codes, function ($iso) use ($dir) {
            return !is_readable($dir . '/' . strtolower($iso) . '.svg');
        }));

        $this->assertSame([], $missing, 'No flag file for: ' . implode(', ', $missing));
    }
    /* ------------------------------------------------------------------ *
     *  Getting the request to the supplier
     * ------------------------------------------------------------------ */

    /** Send one procurement notification and return what went to Twilio. */
    private function sentToTwilio($contentSid = null)
    {
        config([
            'services.twilio.whatsapp_content_sid' => $contentSid,
            // These tests are about the WhatsApp path; the default is email.
            'services.procurement.channel' => 'whatsapp',
        ]);

        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMfake', 'status' => 'queued'], 201)]);

        list($supplier, $order, $notification) = $this->request();

        $supplier->categories()->sync([Category::whereNull('parent')->value('id')]);

        $product = Product::where('procurement', 1)->first();

        if (!$product) {
            $this->markTestSkipped('No product is flagged for procurement.');
        }

        // Cover whatever categories that product actually sits in.
        $supplier->categories()->sync(
            ProductCategory::where('product_id', $product->id)->pluck('category_id')->all()
        );

        SupplierNotification::where('order_id', $order->id)->delete();

        $controller = new \App\Http\Controllers\OrdersController();
        $method = new \ReflectionMethod(\App\Http\Controllers\OrdersController::class, 'notifySuppliers');
        $method->setAccessible(true);
        $method->invoke(
            $controller,
            (object)['id' => $order->id, 'order' => $order->order],
            [['product_id' => $product->id, 'inventory_id' => null, 'quantity' => 2]],
            app(TwilioService::class)
        );

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) { $sent = $request->data(); return true; });

        return [$sent, $order, $supplier];
    }

    /**
     * WhatsApp will not let a business open a conversation with free-form
     * text. Outside the 24-hour window that starts when the recipient
     * messages you, Twilio answers 21654 "ContentSid Required" -- which is
     * exactly what production hit, so every supplier notification failed and
     * nobody was told.
     */
    public function test_a_content_template_is_sent_when_one_is_configured()
    {
        list($sent, $order) = $this->sentToTwilio('HX0123456789abcdef0123456789abcdef');

        $this->assertEquals('HX0123456789abcdef0123456789abcdef', $sent['ContentSid']);
        $this->assertArrayNotHasKey('Body', $sent, 'A template send must not also carry free-form text.');

        $vars = json_decode($sent['ContentVariables'], true);

        // The order IS the template's {{1}}..{{4}}.
        $this->assertEquals($order->order, $vars['1']);
        $this->assertEquals('1', $vars['2']);
        $this->assertStringContainsString('x2', $vars['3']);
        $this->assertStringContainsString('/supplier/bid/', $vars['4']);
    }

    /** Unset, it still sends free-form -- correct inside a session window. */
    public function test_free_form_is_still_sent_when_no_template_is_configured()
    {
        list($sent) = $this->sentToTwilio(null);

        $this->assertArrayHasKey('Body', $sent);
        $this->assertArrayNotHasKey('ContentSid', $sent);
        $this->assertStringContainsString('/supplier/bid/', $sent['Body']);
    }

    /**
     * A placeholder cannot contain a newline, a tab, or a run of more than
     * four spaces, and cannot be empty. The free-form message is full of
     * newlines, so passing its parts through unflattened fails with an error
     * about the parameter format rather than about the newline.
     */
    public function test_template_values_are_flattened_to_one_line()
    {
        $encoded = json_decode(TwilioService::contentVariables([
            "order\nnumber", 3, "A x1\n• B x2\t• C x3", '',
        ]), true);

        foreach ($encoded as $position => $value) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\t]/', $value, "{{$position}} has a line break");
            $this->assertNotSame('', $value, "{{$position}} is empty");
        }

        $this->assertEquals('order number', $encoded['1']);
        $this->assertEquals('-', $encoded['4']);
    }

    /**
     * A failed send must leave no notification row: the dedupe check treats a
     * row as proof the supplier was told, so recording one for a message that
     * never arrived means they are never told.
     */
    public function test_a_refused_send_records_nothing_and_says_what_to_fix()
    {
        config([
            'services.twilio.whatsapp_content_sid' => null,
            'services.procurement.channel' => 'whatsapp',
        ]);

        Http::fake(['api.twilio.com/*' => Http::response(
            ['code' => 21654, 'message' => 'ContentSid Required'], 400
        )]);

        list($supplier, $order) = $this->request();

        $product = Product::where('procurement', 1)->first();

        if (!$product) {
            $this->markTestSkipped('No product is flagged for procurement.');
        }

        $supplier->categories()->sync(
            ProductCategory::where('product_id', $product->id)->pluck('category_id')->all()
        );

        SupplierNotification::where('order_id', $order->id)->delete();

        $controller = new \App\Http\Controllers\OrdersController();
        $method = new \ReflectionMethod(\App\Http\Controllers\OrdersController::class, 'notifySuppliers');
        $method->setAccessible(true);

        // The failure is caught and logged, never thrown at the checkout.
        $method->invoke(
            $controller,
            (object)['id' => $order->id, 'order' => $order->order],
            [['product_id' => $product->id, 'inventory_id' => null, 'quantity' => 1]],
            app(TwilioService::class)
        );

        $this->assertSame(
            0,
            SupplierNotification::where('order_id', $order->id)->where('supplier_id', $supplier->id)->count(),
            'A supplier who was not reached must not be recorded as notified.'
        );

        // And the log carries the action, not just Twilio's wording.
        $this->assertStringContainsString(
            'TWILIO_WHATSAPP_CONTENT_SID',
            TwilioService::explain(21654)
        );
    }
    /**
     * A ContentSid naming one of Twilio's demo templates -- every account
     * ships the appointment-reminder sample -- is accepted, composed from ITS
     * variables, delivered, and reported as a success. This actually happened:
     * the supplier received "Reminder: Appt Tue Oct 29, 3:00 PM" with no order
     * and no link, while the 2xx wrote a notification row and the dedupe
     * marked them as told, so they would never be told again.
     *
     * Same trap as the 2FA SMS template, same rule: failing the send is
     * correct, delivering the wrong message is not.
     */
    public function test_a_template_that_ignores_our_values_is_a_failure()
    {
        config(['services.twilio.whatsapp_content_sid' => 'HX0123456789abcdef0123456789abcdef']);

        $variables = ['ORDER-123', 2, 'A Product x2', 'https://shop.test/supplier/bid/TOKEN'];

        $bodies = [
            'sample template'   => 'Reminder: Appt Tue Oct 29, 3:00 PM. Reply C to confirm.',
            'ours, full body'   => 'New fulfilment request. Order ORDER-123 - 2 item(s): A Product x2. https://shop.test/supplier/bid/TOKEN',
            'ours, url button'  => 'New fulfilment request for order ORDER-123. Tap below to send your price.',
            'nothing echoed'    => '',
        ];

        /*
         * Http::fake() merges stubs rather than replacing them, so every call
         * would otherwise be answered by the first one.
         */
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push(['sid' => 'MMfake', 'status' => 'queued', 'body' => $body], 201);
        }

        Http::fake(['api.twilio.com/*' => $sequence]);

        $refused = [];

        foreach ($bodies as $label => $body) {
            try {
                app(TwilioService::class)->sendWhatsApp('+353874523344', 'free-form', $variables);
            } catch (\Throwable $e) {
                $refused[$label] = $e->getMessage();
            }
        }

        $this->assertArrayHasKey('sample template', $refused, "Twilio's sample template was accepted.");
        $this->assertArrayHasKey('nothing echoed', $refused, 'An unverifiable send was accepted.');

        $this->assertArrayNotHasKey('ours, full body', $refused);
        // A template may carry the link in a button rather than the text.
        $this->assertArrayNotHasKey('ours, url button', $refused);

        $this->assertStringContainsString(
            'not the procurement template',
            $refused['sample template']
        );
    }
    /* ------------------------------------------------------------------ *
     *  Email, while the WhatsApp template waits on approval
     * ------------------------------------------------------------------ */

    /** Run one notification on a given channel; returns rows written. */
    private function notifyOn($channel)
    {
        config([
            'services.procurement.channel' => $channel,
            'services.procurement.link_base' => 'https://shop.test',
        ]);

        list($supplier, $order) = $this->request();

        $product = Product::where('procurement', 1)->first();

        if (!$product) {
            $this->markTestSkipped('No product is flagged for procurement.');
        }

        $supplier->categories()->sync(
            ProductCategory::where('product_id', $product->id)->pluck('category_id')->all()
        );

        SupplierNotification::where('order_id', $order->id)->delete();

        $controller = new \App\Http\Controllers\OrdersController();
        $method = new \ReflectionMethod(\App\Http\Controllers\OrdersController::class, 'notifySuppliers');
        $method->setAccessible(true);
        $method->invoke(
            $controller,
            $order,
            [['product_id' => $product->id, 'inventory_id' => null, 'quantity' => 2]],
            app(TwilioService::class),
            null
        );

        return [
            SupplierNotification::where('order_id', $order->id)
                ->where('supplier_id', $supplier->id)->count(),
            $supplier,
            $order,
        ];
    }

    /**
     * WhatsApp cannot carry a business-initiated message without an approved
     * Content Template, and approval is Meta's queue, not ours. Email needs
     * none of that and the supplier record already requires an address.
     */
    public function test_the_request_goes_out_by_email()
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21654], 400)]);

        list($rows) = $this->notifyOn('email');

        $this->assertSame(1, $rows, 'Nothing was recorded, so the email did not go.');

        // Twilio must not be touched at all on the email channel.
        Http::assertNothingSent();
    }

    /**
     * On 'both', either channel reaching the supplier is enough -- a Twilio
     * refusal must not throw away a delivered email, because no row means
     * the supplier is asked again on the next order and never told about
     * this one.
     */
    public function test_email_alone_is_enough_when_whatsapp_refuses()
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(
            ['code' => 21654, 'message' => 'ContentSid Required'], 400
        )]);

        list($rows) = $this->notifyOn('both');

        $this->assertSame(1, $rows, 'A delivered email was discarded because WhatsApp failed.');
    }

    /** And with neither channel reaching them, nothing is recorded. */
    public function test_nothing_is_recorded_when_no_channel_reaches_the_supplier()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21654], 400)]);

        list($rows, $supplier) = $this->notifyOn('whatsapp');

        $this->assertSame(0, $rows);
    }

    /** The email has to carry the one thing it exists to deliver: the link. */
    public function test_the_email_carries_the_bid_link_and_the_items()
    {
        $brand = \App\Models\Helper\MailBrand::make('QA Supplier');

        $data = (object)[
            'receiver' => 'QA Supplier',
            'order' => 'ORDER-123',
            'items' => ['Apple iPhone 15 Pro x1', 'Crucial 16GB DDR4 x2'],
            'link' => 'https://shop.test/supplier/bid/TOKEN123',
            'expires_in_days' => \App\Http\Controllers\BidController::LINK_TTL_DAYS,
        ];

        foreach (['mail_templates.supplier_procurement_request',
                  'mail_templates.text.supplier_procurement_request'] as $view) {

            $rendered = view($view, ['data' => $data, 'brand' => $brand, 'lang' => null])->render();

            $this->assertStringContainsString('ORDER-123', $rendered, $view);
            $this->assertStringContainsString('Apple iPhone 15 Pro x1', $rendered, $view);
            $this->assertStringContainsString('Crucial 16GB DDR4 x2', $rendered, $view);
            $this->assertStringContainsString('https://shop.test/supplier/bid/TOKEN123', $rendered, $view);
        }
    }

    /** A supplier with no usable address is a failure, not a silent success. */
    public function test_an_unusable_email_address_records_nothing()
    {
        Mail::fake();

        $supplier = $this->supplier(['email' => 'not-an-address']);
        $order = Order::orderByDesc('id')->firstOrFail();

        $sent = \App\Models\Helper\MailHelper::supplierProcurementRequest(
            $supplier, $order, ['A Product x1'], 'https://shop.test/supplier/bid/T', 30
        );

        $this->assertFalse($sent);
    }
    /**
     * The price box opens on what the line was charged at, so the supplier
     * adjusts a figure rather than starting from an empty field.
     *
     * ordered_products.selling is the charged unit price -- the column
     * OrderSummary treats as the source of truth for what the customer paid
     * -- and it is sent as recorded, with the line's own VAT treatment
     * beside it. This catalogue runs both conventions and the line says which
     * one applied, so nothing here divides by a rate to convert.
     */
    public function test_the_page_carries_the_price_the_line_was_ordered_at()
    {
        $inventory = \App\Models\UpdatedInventory::firstOrFail();
        $product = Product::findOrFail($inventory->product_id);

        list($supplier, $order, $notification) = $this->request(null, $product->id, $inventory->id);

        $line = OrderedProduct::create([
            'order_id' => $order->id,
            'product_id' => $notification->product_id,
            'inventory_id' => $notification->inventory_id,
            'quantity' => 2,
            'selling' => 599.00,
            'purchased' => 549.00,
            'tax_price' => 137.77,
            'tax_rate' => 23.00,
            'tax_inclusive' => 0,
            // Columns without a default on this table.
            'shipping_place_id' => 1,
            'shipping_type' => 2,
        ]);

        $body = $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(200)
            ->json();

        $item = $body['data'][0];

        $this->assertEquals(599.00, $item['ordered_price']);
        $this->assertFalse($item['ordered_price_includes_vat']);
        $this->assertEquals(2, $item['quantity']);

        /*
         * And it is the CHARGED price, not the catalogue price -- a line sold
         * on a flash discount must not quote the supplier the list price.
         */
        $this->assertNotEquals(
            (float)$product->selling,
            (float)$item['ordered_price'],
            'The catalogue price was sent instead of what the line was charged at.'
        );

        $line->delete();
    }

    /** A line with nothing ordered against it simply has no starting price. */
    public function test_a_line_with_no_order_row_has_no_starting_price()
    {
        list($supplier, $order) = $this->request();

        $item = $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->json('data.0');

        $this->assertNull($item['ordered_price']);
        $this->assertNull($item['ordered_price_includes_vat']);
    }

    /**
     * The supplier is not shown what we sell it for. The original page sent
     * `selling` and `offered` straight off the product, which anchors a quote
     * to our margin rather than their cost.
     */
    public function test_the_catalogue_price_is_never_sent_to_the_supplier()
    {
        list($supplier, $order) = $this->request(null, Product::value('id'));

        $item = $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->json('data.0');

        $this->assertArrayNotHasKey('selling', $item['product']);
        $this->assertArrayNotHasKey('offered', $item['product']);
    }

    /**
     * A supplier opens this from a message, with no account and no
     * branding around the form, so the page has to say who is asking --
     * "Price sent" on its own does not say sent to whom.
     */
    public function test_the_page_names_who_the_price_goes_to()
    {
        list($supplier, $order) = $this->request();

        $body = $this->getJson('/api/v1/order/' . rawurlencode($this->token($order->id, $supplier->id)))
            ->assertStatus(200)
            ->json();

        $this->assertNotEmpty($body['store']);
        $this->assertEquals(\App\Models\Helper\MailBrand::make()->store_short, $body['store']);
    }
    /**
     * MAX(created_at) comes back from the group-by as a bare string with no
     * zone. A browser reads that as LOCAL time, so the same instant rendered
     * hours away from the bid timestamps beside it and a request appeared to
     * have been answered before it was sent.
     */
    public function test_the_asked_time_is_sent_as_an_unambiguous_instant()
    {
        $this->admin();

        list($supplier, $order) = $this->request();

        $row = $this->getJson('/api/admin/suppliers/sourcing?order_id=' . $order->id)
            ->json('data.orders.data.0');

        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.*Z$/',
            $row['asked_at'],
            'asked_at carries no timezone, so the browser will read it as local time.'
        );
    }

    /**
     * A quote on its own is not a decision. Each line carries what it sold
     * for and what it cost, so the price can be read against something.
     */
    public function test_each_line_carries_what_it_sold_and_cost()
    {
        $this->admin();

        $inventory = \App\Models\UpdatedInventory::firstOrFail();
        $product = Product::findOrFail($inventory->product_id);

        list($supplier, $order, $notification) = $this->request(null, $product->id, $inventory->id);

        $line = OrderedProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 2,
            'selling' => 599.00,
            'purchased' => 549.00,
            'tax_price' => 137.77,
            'tax_rate' => 23.00,
            'tax_inclusive' => 0,
            'shipping_place_id' => 1,
            'shipping_type' => 2,
        ]);

        SupplierBid::create([
            'supplier_notification_id' => $notification->id,
            'supplier_id' => $supplier->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'price' => 649.00,
            'grade' => 'Grade A',
        ]);

        $row = $this->getJson('/api/admin/suppliers/sourcing?order_id=' . $order->id)
            ->json('data.orders.data.0.lines.0');

        $this->assertEquals(599.00, $row['sold_price']);
        $this->assertEquals(549.00, $row['cost_price']);
        $this->assertEquals(2, $row['quantity']);

        // Sold 599, quoted 649 -> fulfilling this line loses 50.
        $this->assertTrue($row['margin_comparable']);
        $this->assertEquals(-50.00, $row['quotes'][0]['margin']);

        $line->delete();
    }

    /**
     * A supplier quotes ex-VAT. A line sold VAT-inclusive carries the VAT
     * inside `selling`, so the two do not subtract -- and no margin is
     * invented for it rather than dividing by a rate to force one.
     */
    public function test_no_margin_is_invented_for_a_vat_inclusive_line()
    {
        $this->admin();

        $inventory = \App\Models\UpdatedInventory::firstOrFail();
        $product = Product::findOrFail($inventory->product_id);

        list($supplier, $order, $notification) = $this->request(null, $product->id, $inventory->id);

        $line = OrderedProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 1,
            'selling' => 736.77,
            'purchased' => 549.00,
            'tax_price' => 137.77,
            'tax_rate' => 23.00,
            'tax_inclusive' => 1,
            'shipping_place_id' => 1,
            'shipping_type' => 2,
        ]);

        SupplierBid::create([
            'supplier_notification_id' => $notification->id,
            'supplier_id' => $supplier->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'price' => 600.00,
            'grade' => 'Grade A',
        ]);

        $row = $this->getJson('/api/admin/suppliers/sourcing?order_id=' . $order->id)
            ->json('data.orders.data.0.lines.0');

        $this->assertFalse($row['margin_comparable']);
        $this->assertNull($row['quotes'][0]['margin']);

        $line->delete();
    }
    /* ------------------------------------------------------------------ *
     *  Awarding a line, and settling it
     * ------------------------------------------------------------------ */

    /** A line with two suppliers quoting on it. @return [line, cheap, dear] */
    private function contestedLine($cheapPrice = 400.00, $dearPrice = 500.00)
    {
        $inventory = \App\Models\UpdatedInventory::firstOrFail();
        $product = Product::findOrFail($inventory->product_id);
        $order = Order::orderByDesc('id')->firstOrFail();

        $line = OrderedProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'inventory_id' => $inventory->id,
            'quantity' => 2,
            'selling' => 599.00,
            'purchased' => 549.00,
            'tax_price' => 137.77,
            'tax_rate' => 23.00,
            'tax_inclusive' => 0,
            'shipping_place_id' => 1,
            'shipping_type' => 2,
        ]);

        $bids = [];

        foreach ([$cheapPrice, $dearPrice] as $price) {
            $supplier = $this->supplier();

            $notification = SupplierNotification::create([
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'inventory_id' => $inventory->id,
                'order_id' => $order->id,
            ]);

            $bids[] = SupplierBid::create([
                'supplier_notification_id' => $notification->id,
                'supplier_id' => $supplier->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'inventory_id' => $inventory->id,
                'price' => $price,
                'grade' => 'Grade A',
            ]);
        }

        return [$line, $bids[0], $bids[1]];
    }

    /**
     * One supplier sources a line. Leaving the rivals pending invites a
     * second acceptance and a second invoice for the same item.
     */
    public function test_awarding_a_line_rejects_the_competing_quotes()
    {
        $this->admin();

        list($line, $cheap, $dear) = $this->contestedLine();

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted'])
            ->assertJsonPath('status', 200);

        $this->assertEquals(SupplierBid::ACCEPTED, $cheap->fresh()->status);
        $this->assertEquals(SupplierBid::REJECTED, $dear->fresh()->status);
        $this->assertEquals('Another quote was accepted.', $dear->fresh()->decline_reason);

        $line->delete();
    }

    /**
     * The agreed price becomes the line's real cost, so profit reporting
     * stops using the estimate booked at sale time -- and the old figure is
     * kept so the decision can be undone.
     */
    public function test_awarding_writes_the_agreed_price_onto_the_order_line()
    {
        $this->admin();

        list($line, $cheap) = $this->contestedLine(400.00);

        $this->assertEquals(549.00, (float)$line->purchased);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted']);

        $this->assertEquals(400.00, (float)$line->fresh()->purchased);
        $this->assertEquals(549.00, (float)$cheap->fresh()->cost_before);

        // Undoing the award puts the original cost back.
        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'rejected']);

        $this->assertEquals(549.00, (float)$line->fresh()->purchased);

        $line->delete();
    }

    /** Switching supplier must not leave the first one's price behind. */
    public function test_switching_the_award_moves_the_cost_with_it()
    {
        $this->admin();

        list($line, $cheap, $dear) = $this->contestedLine(400.00, 500.00);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted']);
        $this->assertEquals(400.00, (float)$line->fresh()->purchased);

        $this->postJson('/api/admin/suppliers/quotes/' . $dear->id . '/decide', ['decision' => 'accepted']);

        $this->assertEquals(500.00, (float)$line->fresh()->purchased);
        $this->assertEquals(SupplierBid::REJECTED, $cheap->fresh()->status);

        // And undoing the second award restores the ORIGINAL cost, not the first bid's.
        $this->postJson('/api/admin/suppliers/quotes/' . $dear->id . '/decide', ['decision' => 'rejected']);
        $this->assertEquals(549.00, (float)$line->fresh()->purchased);

        $line->delete();
    }

    /** Quotes for a line sit together, cheapest first, so they compare. */
    public function test_quotes_for_a_line_are_listed_cheapest_first()
    {
        $this->admin();

        list($line, $cheap, $dear) = $this->contestedLine(400.00, 500.00);

        $row = collect($this->getJson('/api/admin/suppliers/sourcing?order_id=' . $line->order_id)
            ->json('data.orders.data.0.lines'))
            ->firstWhere('product_id', $line->product_id);

        $prices = array_column($row['quotes'], 'price');

        $this->assertEquals([400.00, 500.00], array_slice($prices, 0, 2));

        // price is per unit; payable is the line, and the quantity is 2.
        $this->assertEquals(800.00, $row['quotes'][0]['payable']);
        $this->assertEquals(2, $row['quantity']);

        $line->delete();
    }

    /**
     * Paying a supplier whose quote was never taken is either a mistake or a
     * decision nobody recorded. Both want fixing before money is booked.
     */
    public function test_a_payment_needs_an_accepted_quote()
    {
        $this->admin();

        list($line, $cheap) = $this->contestedLine();

        $body = $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', [
            'amount' => 800.00, 'method' => 'Bank transfer',
        ])->json();

        $this->assertEquals(201, $body['status']);
        $this->assertNull($cheap->fresh()->paid_at);

        $line->delete();
    }

    /** What left the bank is recorded, and cannot be recorded twice. */
    public function test_a_payment_is_recorded_once()
    {
        $this->admin();

        list($line, $cheap) = $this->contestedLine(400.00);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted']);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', [
            'amount' => 795.50, 'method' => 'Bank transfer', 'reference' => 'SEPA-1',
        ])->assertJsonPath('status', 200);

        $paid = $cheap->fresh();

        // The amount is what actually left, not the quote -- an invoice can differ.
        $this->assertEquals(795.50, (float)$paid->paid_amount);
        $this->assertEquals('Bank transfer', $paid->payment_method);
        $this->assertEquals('SEPA-1', $paid->payment_reference);
        $this->assertNotNull($paid->paid_at);

        $again = $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', [
            'amount' => 795.50, 'method' => 'Cash',
        ])->json();

        $this->assertEquals(201, $again['status']);

        $line->delete();
    }

    /**
     * A paid quote is settled. Changing the decision under it would leave a
     * payment recorded against a supplier we did not use.
     */
    public function test_a_paid_quote_cannot_be_un_awarded_until_the_payment_is_reversed()
    {
        $this->admin();

        list($line, $cheap) = $this->contestedLine(400.00);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted']);
        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', [
            'amount' => 800.00, 'method' => 'Bank transfer',
        ]);

        $blocked = $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'rejected'])->json();

        $this->assertEquals(201, $blocked['status']);
        $this->assertEquals(SupplierBid::ACCEPTED, $cheap->fresh()->status);

        // Reverse it, and the decision opens up again.
        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', ['reverse' => 1])
            ->assertJsonPath('status', 200);

        $this->assertNull($cheap->fresh()->paid_at);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'rejected'])
            ->assertJsonPath('status', 200);

        $line->delete();
    }

    /** Both writes are gated, and separately: one commits, one settles. */
    public function test_deciding_and_paying_need_their_own_permissions()
    {
        $this->admin();

        list($line, $cheap) = $this->contestedLine(400.00);

        $refused = $this->without('procurement.decide', function () use ($cheap) {
            return $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted'])->json();
        });

        $this->assertEquals(403, $refused['status']);
        $this->assertEquals(SupplierBid::PENDING, $cheap->fresh()->status);

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted']);

        $refusedPay = $this->without('procurement.pay', function () use ($cheap) {
            return $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', [
                'amount' => 800.00, 'method' => 'Bank transfer',
            ])->json();
        });

        $this->assertEquals(403, $refusedPay['status']);
        $this->assertNull($cheap->fresh()->paid_at);

        $line->delete();
    }
    /* ------------------------------------------------------------------ *
     *  Filtering the board
     * ------------------------------------------------------------------ */

    /**
     * The state filters describe what is still true of an order rather than
     * reading a stored column -- state is derived from the quotes, so a
     * stored copy would drift the moment one changed.
     */
    public function test_the_board_filters_by_where_the_order_stands()
    {
        $this->admin();

        list($line, $cheap, $dear) = $this->contestedLine(400.00, 500.00);
        $orderId = $line->order_id;

        $only = function ($state) use ($orderId) {
            $ids = collect($this->getJson('/api/admin/suppliers/sourcing?state=' . $state)
                ->json('data.orders.data'))->pluck('order_id');

            return $ids->contains($orderId);
        };

        // Quoted but nobody chosen yet.
        $this->assertTrue($only('needs_award'), 'An unawarded order is not "ready to award".');
        $this->assertFalse($only('unpaid'));
        $this->assertFalse($only('settled'));

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/decide', ['decision' => 'accepted']);

        // Awarded, so it is owed rather than waiting on a decision.
        $this->assertFalse($only('needs_award'));
        $this->assertTrue($only('unpaid'), 'An awarded, unpaid order is not owing.');
        $this->assertFalse($only('settled'));

        $this->postJson('/api/admin/suppliers/quotes/' . $cheap->id . '/pay', [
            'amount' => 800.00, 'method' => 'Bank transfer',
        ]);

        $this->assertFalse($only('unpaid'));
        $this->assertTrue($only('settled'), 'A fully awarded and paid order is not settled.');

        $line->delete();
    }

    /** A line nobody has answered on is waiting on quotes, not on a decision. */
    public function test_a_line_with_no_quotes_is_waiting_on_quotes()
    {
        $this->admin();

        list($supplier, $order) = $this->request();

        $ids = collect($this->getJson('/api/admin/suppliers/sourcing?state=needs_quote')
            ->json('data.orders.data'))->pluck('order_id');

        $this->assertTrue($ids->contains($order->id));
    }

    /** By order number, and by a supplier who was asked. */
    public function test_the_board_filters_by_order_number_and_supplier()
    {
        $this->admin();

        list($supplier, $order) = $this->request();

        $found = collect($this->getJson('/api/admin/suppliers/sourcing?q=' . $order->order)
            ->json('data.orders.data'))->pluck('order_id');

        $this->assertTrue($found->contains($order->id));

        $missing = $this->getJson('/api/admin/suppliers/sourcing?q=definitely-not-an-order')
            ->json('data.orders.data');

        $this->assertSame([], $missing);

        $bySupplier = collect($this->getJson('/api/admin/suppliers/sourcing?supplier_id=' . $supplier->id)
            ->json('data.orders.data'))->pluck('order_id');

        $this->assertTrue($bySupplier->contains($order->id));

        // A supplier who was never asked about it matches nothing.
        $other = $this->supplier();

        $this->assertSame([], $this->getJson('/api/admin/suppliers/sourcing?supplier_id=' . $other->id)
            ->json('data.orders.data'));
    }

    /**
     * The filter only offers suppliers who have been asked about something.
     * Names that can never match make a filter look broken.
     */
    public function test_the_filter_offers_only_suppliers_that_were_asked()
    {
        $this->admin();

        list($supplier, $order) = $this->request();

        $neverAsked = $this->supplier();

        $offered = collect($this->getJson('/api/admin/suppliers/sourcing')->json('data.suppliers'))
            ->pluck('id');

        $this->assertTrue($offered->contains($supplier->id));
        $this->assertFalse($offered->contains($neverAsked->id));
    }
}
