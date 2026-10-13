<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\GuestUser;
use App\Models\Helper\Validation;
use App\Models\Product;
use App\Models\UpdatedInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Guest basket -> sign in -> one basket.
 *
 * The hand-over is the single point where two baskets that were built
 * independently have to become one, and almost every way of getting it wrong
 * shows up as a number the customer can see: a quantity that doubled, a line
 * that appeared twice, a product that went missing. The cases below are the
 * manual QA script for that flow, written down.
 *
 * They run against the real database inside a transaction, because the
 * behaviour under test is largely about what the database is holding
 * afterwards -- which rows exist, who owns them, and whether the unique index
 * did its job.
 */
class GuestCartMergeTest extends TestCase
{
    use DatabaseTransactions;

    // ---- fixtures -----------------------------------------------------------

    /**
     * A guest token the server would have issued.
     *
     * Minted through the endpoint rather than inserted, so the row that
     * ValidateGuestToken looks for is the one the real flow creates.
     */
    private function guestToken()
    {
        $this->asGuest();

        $token = $this->postJson('/api/v1/guest/token')->json('data.user_token');

        $this->assertNotEmpty($token, 'The guest token endpoint issued nothing.');

        return $token;
    }

    private function asGuest()
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Become this customer and nobody else.
     *
     * forgetGuards() first: Passport::actingAs sets one guard and leaves the
     * others standing, and the cart controller scopes to admin_id whenever an
     * admin resolves -- which makes a correct basket read as an empty one.
     */
    private function asCustomer($user)
    {
        $this->app['auth']->forgetGuards();

        Passport::actingAs($user->fresh(), ['user'], 'user');
    }

    private function customer($attributes = [])
    {
        return User::create(array_merge([
            'name' => 'Merge Fixture',
            'email' => 'merge.' . uniqid() . '@example.test',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    /**
     * Distinct products that can actually be bought, as [product, inventory]
     * pairs -- the A, B and C of the QA script.
     */
    private function lines($count = 3)
    {
        $rows = DB::table('updated_inventories as ui')
            ->join('products as p', 'p.id', '=', 'ui.product_id')
            ->where('p.status', 1)
            ->whereNotNull('p.selling')->where('p.selling', '>', 0)
            ->where(function ($q) {
                $q->where('ui.quantity', '>', 20)->orWhere('ui.is_active', 1);
            })
            ->groupBy('p.id')
            ->select('p.id as product_id', DB::raw('MIN(ui.id) as inventory_id'))
            ->limit($count)
            ->get();

        if ($rows->count() < $count) {
            $this->markTestSkipped("Need $count purchasable products to run this.");
        }

        return $rows->map(function ($row) {
            return [
                'product_id' => (int)$row->product_id,
                'inventory_id' => (int)$row->inventory_id,
            ];
        })->values()->all();
    }

    /**
     * Two variants of one product, for the cases about a variant being its own
     * basket line.
     */
    private function variants()
    {
        $productId = DB::table('updated_inventories as ui')
            ->join('products as p', 'p.id', '=', 'ui.product_id')
            ->where('p.status', 1)
            ->where(function ($q) {
                $q->where('ui.quantity', '>', 20)->orWhere('ui.is_active', 1);
            })
            ->groupBy('p.id')
            ->havingRaw('COUNT(*) >= 2')
            ->value('p.id');

        if (!$productId) {
            $this->markTestSkipped('No product with two purchasable variants.');
        }

        $inventories = UpdatedInventory::where('product_id', $productId)
            ->where(function ($q) {
                $q->where('quantity', '>', 20)->orWhere('is_active', 1);
            })
            ->orderBy('id')->limit(2)->pluck('id')->all();

        return [
            'product_id' => (int)$productId,
            'first' => (int)$inventories[0],
            'second' => (int)$inventories[1],
        ];
    }

    // ---- actions the storefront takes ---------------------------------------

    private function addAsGuest($token, $line, $quantity)
    {
        $this->asGuest();

        return $this->postJson('/api/v1/cart/action', array_merge($line, [
            'quantity' => $quantity,
            'user_token' => $token,
        ]));
    }

    private function addAsCustomer($user, $line, $quantity)
    {
        $this->asCustomer($user);

        return $this->postJson('/api/v1/cart/action', array_merge($line, [
            'quantity' => $quantity,
        ]));
    }

    /**
     * Sign in the way the storefront does, carrying the guest token, and read
     * the basket back as the customer.
     */
    private function signIn($user, $token, $password = 'Abcdefghij1')
    {
        $this->asGuest();

        $response = $this->postJson('/api/v1/user/signin', [
            'email' => $user->email,
            'password' => $password,
            'user_token' => $token,
        ]);

        $this->asCustomer($user);

        return $response;
    }

    private function guestBasket($token)
    {
        $this->asGuest();

        return collect($this->getJson('/api/v1/cart/by-user?user_token=' . $token)->json('data'));
    }

    private function customerBasket($user)
    {
        $this->asCustomer($user);

        return collect($this->getJson('/api/v1/cart/by-user')->json('data'));
    }

    /** Quantity the database is holding for one line of one owner. */
    private function storedQuantity($ownerColumn, $owner, $line)
    {
        return (int)Cart::where($ownerColumn, $owner)
            ->where('product_id', $line['product_id'])
            ->where('inventory_id', $line['inventory_id'])
            ->sum('quantity');
    }

    private function rowCount($ownerColumn, $owner, $line)
    {
        return Cart::where($ownerColumn, $owner)
            ->where('product_id', $line['product_id'])
            ->where('inventory_id', $line['inventory_id'])
            ->count();
    }

    // ---- the guest basket, before anyone signs in ---------------------------

    public function test_a_guest_builds_a_basket_without_an_account()
    {
        [$a, $b] = $this->lines(2);
        $token = $this->guestToken();

        $this->addAsGuest($token, $a, 2)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();

        $basket = $this->guestBasket($token);

        $this->assertCount(2, $basket, 'Both products should be in the guest basket.');
        $this->assertSame(2, $this->storedQuantity('user_token', $token, $a));
        $this->assertSame(1, $this->storedQuantity('user_token', $token, $b));

        // The rows belong to the guest, and to nobody else.
        $this->assertSame(0, Cart::where('user_token', $token)->whereNotNull('user_id')->count());
    }

    public function test_adding_the_same_product_twice_raises_the_quantity_instead_of_adding_a_row()
    {
        [$a] = $this->lines(1);
        $token = $this->guestToken();

        $this->addAsGuest($token, $a, 2)->assertOk();
        $this->addAsGuest($token, $a, 3)->assertOk();

        $this->assertSame(1, $this->rowCount('user_token', $token, $a), 'A repeat add created a second row.');
        $this->assertSame(5, $this->storedQuantity('user_token', $token, $a));
    }

    public function test_two_variants_of_one_product_are_two_basket_lines()
    {
        $variants = $this->variants();
        $token = $this->guestToken();

        $first = ['product_id' => $variants['product_id'], 'inventory_id' => $variants['first']];
        $second = ['product_id' => $variants['product_id'], 'inventory_id' => $variants['second']];

        $this->addAsGuest($token, $first, 1)->assertOk();
        $this->addAsGuest($token, $second, 1)->assertOk();

        $this->assertSame(2, Cart::where('user_token', $token)
            ->where('product_id', $variants['product_id'])->count(),
            'The two variants collapsed into one line.');
    }

    public function test_the_guest_basket_prices_every_line_from_the_catalogue()
    {
        [$a] = $this->lines(1);
        $token = $this->guestToken();

        $this->addAsGuest($token, $a, 2)->assertOk();

        $row = Cart::where('user_token', $token)->first();
        $product = Product::find($a['product_id']);

        $this->assertGreaterThan(0, (float)$row->price, 'The line was stored with no price.');

        // Whatever the catalogue says, not whatever the request said.
        $this->assertLessThanOrEqual(
            (float)$product->selling + 0.005, (float)$row->price,
            'The stored unit price is above the list price.'
        );
    }

    // ---- the merge itself ---------------------------------------------------

    public function test_the_same_product_on_both_sides_becomes_one_line_with_the_quantities_added()
    {
        [$a] = $this->lines(1);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 1)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 2)->assertOk();

        $this->signIn($user, $token)->assertOk();

        $this->assertSame(3, $this->storedQuantity('user_id', $user->id, $a), 'Guest 2 + account 1 should be 3.');
        $this->assertSame(1, $this->rowCount('user_id', $user->id, $a), 'The merged product appears more than once.');
        $this->assertSame(0, Cart::where('user_token', $token)->count(), 'The guest rows were not consumed.');
    }

    public function test_products_only_one_side_holds_all_survive_the_merge()
    {
        [$a, $b, $c] = $this->lines(3);

        $user = $this->customer();
        $this->addAsCustomer($user, $c, 1)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 1)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();

        $this->signIn($user, $token)->assertOk();

        $basket = $this->customerBasket($user);

        $this->assertCount(3, $basket, 'All three products should be in the basket after signing in.');

        foreach ([$a, $b, $c] as $line) {
            $this->assertSame(1, $this->rowCount('user_id', $user->id, $line));
            $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $line));
        }
    }

    public function test_a_variant_the_account_does_not_hold_stays_its_own_line()
    {
        $variants = $this->variants();

        $first = ['product_id' => $variants['product_id'], 'inventory_id' => $variants['first']];
        $second = ['product_id' => $variants['product_id'], 'inventory_id' => $variants['second']];

        $user = $this->customer();
        $this->addAsCustomer($user, $first, 1)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $first, 2)->assertOk();
        $this->addAsGuest($token, $second, 1)->assertOk();

        $this->signIn($user, $token)->assertOk();

        // Same variant merged, different variant kept apart.
        $this->assertSame(3, $this->storedQuantity('user_id', $user->id, $first));
        $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $second));
        $this->assertSame(2, Cart::where('user_id', $user->id)
            ->where('product_id', $variants['product_id'])->count());
    }

    public function test_the_basket_the_server_returns_after_signing_in_is_the_merged_one()
    {
        [$a, $b] = $this->lines(2);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 2)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 5)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();

        $response = $this->signIn($user, $token);

        // The count the header renders comes back with the session.
        $this->assertSame(8, (int)$response->json('data.user.cart_count'));

        $basket = $this->customerBasket($user);

        $this->assertCount(2, $basket);
        $this->assertSame(8, (int)$basket->sum('quantity'));
    }

    // ---- doing it twice -----------------------------------------------------

    public function test_signing_in_a_second_time_does_not_merge_the_basket_again()
    {
        [$a] = $this->lines(1);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 2)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 3)->assertOk();

        $this->signIn($user, $token)->assertOk();
        $this->assertSame(5, $this->storedQuantity('user_id', $user->id, $a));

        // Signing out and back in still carries the same stored guest token.
        $this->signIn($user, $token)->assertOk();
        $this->signIn($user, $token)->assertOk();

        $this->assertSame(5, $this->storedQuantity('user_id', $user->id, $a), 'A repeat sign-in added the guest quantity again.');
        $this->assertSame(1, $this->rowCount('user_id', $user->id, $a));
    }

    public function test_the_merge_endpoint_is_idempotent()
    {
        [$a] = $this->lines(1);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 1)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 2)->assertOk();

        $this->asCustomer($user);

        $first = $this->postJson('/api/v1/cart/merge', ['user_token' => $token]);
        $first->assertOk();

        $this->assertSame(1, (int)$first->json('data.merged'));
        $this->assertSame(3, (int)$first->json('data.cart_count'));

        // Called again -- a retry, a double-clicked button, a second tab.
        $second = $this->postJson('/api/v1/cart/merge', ['user_token' => $token]);
        $second->assertOk();

        $this->assertSame(0, (int)$second->json('data.moved'), 'The second merge moved something.');
        $this->assertSame(3, (int)$second->json('data.cart_count'));
        $this->assertSame(3, $this->storedQuantity('user_id', $user->id, $a));
    }

    public function test_the_merge_endpoint_refuses_an_anonymous_caller()
    {
        $token = $this->guestToken();

        $this->asGuest();

        $response = $this->postJson('/api/v1/cart/merge', ['user_token' => $token]);

        $this->assertNotSame(200, (int)$response->json('status'), 'A guest was allowed to run a merge.');
    }

    public function test_the_merge_endpoint_refuses_a_token_the_server_never_issued()
    {
        $user = $this->customer();

        $this->asCustomer($user);

        $response = $this->postJson('/api/v1/cart/merge', [
            'user_token' => 'not-a-token-' . uniqid(),
        ]);

        $this->assertNotSame(200, (int)$response->json('status'));
    }

    // ---- limits -------------------------------------------------------------

    public function test_a_merge_that_would_pass_the_line_cap_is_capped_not_doubled()
    {
        [$a] = $this->lines(1);

        // Backorder on, so the cap under test is the line cap and not stock.
        UpdatedInventory::where('id', $a['inventory_id'])->update(['is_active' => 1]);

        $max = Validation::MAX_LINE_QUANTITY;

        $user = $this->customer();
        $this->addAsCustomer($user, $a, $max)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 10)->assertOk();

        $this->signIn($user, $token)->assertOk();

        $this->assertSame($max, $this->storedQuantity('user_id', $user->id, $a),
            'The merged line went past the maximum a line may hold.');
    }

    public function test_a_merge_respects_the_stock_a_variant_actually_has()
    {
        [$a] = $this->lines(1);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 2)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 3)->assertOk();

        // Both baskets were legal when they were built; between then and
        // signing in the variant sold down to four and backorder came off.
        // The stock column is what nobody holds (CartHold): four in all,
        // with the two baskets holding five between them, is 4 - 5 free.
        UpdatedInventory::where('id', $a['inventory_id'])
            ->update(['quantity' => 4 - 5, 'is_active' => 0]);

        $this->signIn($user, $token)->assertOk();

        $this->assertSame(4, $this->storedQuantity('user_id', $user->id, $a),
            'The merge sold five units of a variant with four in stock.');
    }

    public function test_an_over_subscribed_line_does_not_take_the_rest_of_the_basket_with_it()
    {
        [$a, $b] = $this->lines(2);

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 3)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();

        // One in all, the guest basket holding three of it (CartHold): 1 - 3 free.
        UpdatedInventory::where('id', $a['inventory_id'])
            ->update(['quantity' => 1 - 3, 'is_active' => 0]);

        $user = $this->customer();
        $this->signIn($user, $token)->assertOk();

        $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $a));
        $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $b), 'The second product was lost with the first.');
    }

    // ---- what the database is left holding ----------------------------------

    public function test_no_guest_rows_or_duplicates_are_left_behind()
    {
        [$a, $b, $c] = $this->lines(3);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 1)->assertOk();
        $this->addAsCustomer($user, $c, 2)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 1)->assertOk();
        $this->addAsGuest($token, $b, 4)->assertOk();

        $this->signIn($user, $token)->assertOk();

        $this->assertSame(0, Cart::where('user_token', $token)->count(), 'Guest rows survived the merge.');
        $this->assertSame(0, Cart::whereNull('user_id')->whereNull('user_token')->whereNull('admin_id')->count(),
            'The merge left a row with no owner at all.');

        $duplicates = DB::table('carts')
            ->select('product_id', 'inventory_id', DB::raw('COUNT(*) as total'))
            ->where('user_id', $user->id)
            ->groupBy('product_id', 'inventory_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicates, 'The account is holding a duplicate basket line.');
    }

    public function test_the_database_refuses_a_second_row_for_the_same_line()
    {
        [$a] = $this->lines(1);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 1)->assertOk();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Written straight past the controller, which is the only way to try.
        Cart::create([
            'product_id' => $a['product_id'],
            'inventory_id' => $a['inventory_id'],
            'user_id' => $user->id,
            'quantity' => 1,
        ]);
    }

    /**
     * An account that predates the unique index can still be carrying a
     * duplicate pair, and signing in is the moment it would otherwise be
     * doubled. There is no longer a way to produce one through the
     * application, so the index comes off for the length of this case.
     *
     * DDL commits the open transaction in MySQL, which is what
     * DatabaseTransactions rolls back at the end — so the index is dropped
     * before anything is written, and a fresh transaction is opened
     * immediately afterwards for the trait to unwind as usual.
     */
    public function test_a_duplicate_pair_already_on_the_account_is_folded_in_rather_than_doubled()
    {
        [$a] = $this->lines(1);

        DB::statement('ALTER TABLE carts DROP INDEX carts_user_line_unique');

        /*
         * disconnect() resets the connection's transaction counter, which the
         * implicit commit above left one level too high. Without it the nested
         * transaction inside the merge asks for a savepoint that is not there.
         */
        DB::disconnect();
        DB::beginTransaction();

        try {
            $user = $this->customer();
            $this->addAsCustomer($user, $a, 1)->assertOk();

            Cart::create([
                'product_id' => $a['product_id'],
                'inventory_id' => $a['inventory_id'],
                'user_id' => $user->id,
                'quantity' => 2,
            ]);

            $this->assertSame(2, $this->rowCount('user_id', $user->id, $a));

            $token = $this->guestToken();
            $this->addAsGuest($token, $a, 1)->assertOk();

            $this->signIn($user, $token)->assertOk();

            $this->assertSame(1, $this->rowCount('user_id', $user->id, $a), 'The duplicate pair was not collapsed.');
            $this->assertSame(4, $this->storedQuantity('user_id', $user->id, $a), '1 + 2 already held, plus 1 from the guest.');

        } finally {
            DB::rollBack();

            // Exactly as the migrations leave it - buy_now included since
            // 2026-10-09. Re-adding the older three-column key here quietly
            // undid that on whichever database this suite ran against.
            DB::statement('ALTER TABLE carts ADD UNIQUE carts_user_line_unique (user_id, product_id, inventory_id, buy_now)');

            // Hand the trait the transaction it expects to find.
            DB::disconnect();
            DB::beginTransaction();
        }
    }

    public function test_the_guest_record_is_claimed_by_the_account_that_absorbed_it()
    {
        [$a] = $this->lines(1);

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 1)->assertOk();

        $user = $this->customer();
        $this->signIn($user, $token)->assertOk();

        $guest = GuestUser::where('user_token', $token)->first();

        $this->assertNotNull($guest);
        $this->assertSame($user->email, $guest->email, 'The guest record was not claimed.');
    }

    // ---- registration -------------------------------------------------------

    public function test_a_basket_built_before_registering_follows_the_new_account()
    {
        [$a, $b] = $this->lines(2);

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 2)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();

        $email = 'newreg.' . uniqid() . '@example.test';

        $this->asGuest();

        $this->postJson('/api/v1/user/signup', [
            'name' => 'New Registrant',
            'email' => $email,
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => 'Abcdefghij1',
            'account_type' => 'personal',
            'terms' => 1,
            'user_token' => $token,
        ])->assertOk();

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user, 'Registration did not create the account.');

        // The account exists but has not signed in yet, so the basket is still
        // the guest's -- nothing has been lost, and nothing moved early.
        $this->assertSame(3, (int)Cart::where('user_token', $token)->sum('quantity'));

        // Verifying the address is what creates the session, and the basket
        // comes across with it.
        $user->update(['verified' => 1, 'status' => User::STATUS_ACTIVE]);

        $this->signIn($user->fresh(), $token)->assertOk();

        $this->assertSame(2, $this->storedQuantity('user_id', $user->id, $a));
        $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $b));
        $this->assertSame(0, Cart::where('user_token', $token)->count());
    }

    // ---- checkout, on the far side of the merge -----------------------------

    /**
     * Give every selected line a shipping place, which is what checkout
     * refuses an order without.
     */
    private function chooseShipping($user, $address)
    {
        $this->asCustomer($user);

        $lines = Cart::where('user_id', $user->id)->get();

        $payload = [];

        foreach ($lines as $line) {

            $place = DB::table('shipping_places as sp')
                ->join('products as p', 'p.shipping_rule_id', '=', 'sp.shipping_rule_id')
                ->where('p.id', $line->product_id)
                ->value('sp.id');

            if (!$place) {
                $this->markTestSkipped('No shipping place is configured for the test product.');
            }

            $payload[] = [
                'cart' => $line->id,
                // The endpoint reads the id out of a nested object, the way
                // the shipping page sends the place it was given.
                'shipping_place' => ['id' => $place],
                'shipping_type' => 1,
            ];
        }

        return $this->postJson('/api/v1/cart/update-shipping', [
            'cart' => $payload,
            'selected_address' => $address->id,
        ]);
    }

    private function addressFor($user)
    {
        $this->asCustomer($user);

        $response = $this->postJson('/api/v1/user/address/action', [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'address_1' => '1 Test Street',
            'city' => 'Dublin',
            // Region and postcode are checked against the country (AddressRules).
            'state' => 'D',
            'zip' => 'A65 F4E2',
            // Stored as an ISO code, not a name.
            'country' => 'IE',
            'type' => 1,
        ]);

        $id = $response->json('data.id');

        if (!$id) {
            $this->markTestSkipped('Could not create an address: ' . json_encode($response->json()));
        }

        return \App\Models\UserAddress::find($id);
    }

    /**
     * The payload the order endpoint expects: AES-encrypted, and padded to the
     * block size, because the controller decrypts with zero padding and a
     * short payload decodes to null — which reads as a refusal rather than a
     * malformed request.
     */
    private function orderPayload(array $params)
    {
        $json = json_encode($params);
        $padded = $json . str_repeat(' ', (16 - strlen($json) % 16) % 16);

        return openssl_encrypt(
            $padded,
            'AES-128-CBC',
            hex2bin('0123456470abcdef0123456789abcdef'),
            OPENSSL_ZERO_PADDING,
            hex2bin('abcdef1876343516abcdef9876543210')
        );
    }

    public function test_checkout_is_handed_the_merged_basket_and_clears_it_only_once_the_order_exists()
    {
        [$a, $b] = $this->lines(2);

        UpdatedInventory::whereIn('id', [$a['inventory_id'], $b['inventory_id']])
            ->update(['is_active' => 1]);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 2)->assertOk();

        $token = $this->guestToken();
        $this->addAsGuest($token, $a, 5)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();

        $this->signIn($user, $token)->assertOk();

        $address = $this->addressFor($user);
        $this->chooseShipping($user, $address)->assertOk();

        // What the basket is worth, from the rows checkout is about to read.
        $expected = Cart::where('user_id', $user->id)->get()
            ->reduce(function ($total, $line) {
                return $total + (float)$line->price * (int)$line->quantity;
            }, 0.0);

        $this->asCustomer($user);

        $response = $this->postJson('/api/v1/order/action', [
            'data' => $this->orderPayload([
                'user_token' => null,
                'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
                'voucher' => '',
                'time_zone' => 'Europe/Dublin',
            ]),
            'order_method' => (int)config('constants.paymentMethod.CASH_ON_DELIVERY'),
        ]);

        $this->assertSame(200, (int)$response->json('status'),
            'The order was refused: ' . json_encode($response->json()));

        $orderId = $response->json('data.id') ?? $response->json('data.order.id');

        $this->assertNotEmpty($orderId, 'The order endpoint returned no order.');

        $ordered = DB::table('ordered_products')->where('order_id', $orderId)->get();

        $this->assertCount(2, $ordered, 'The order does not hold both products.');

        $byProduct = $ordered->keyBy('product_id');

        $this->assertSame(7, (int)$byProduct[$a['product_id']]->quantity, 'Product A reached the order at the wrong quantity.');
        $this->assertSame(1, (int)$byProduct[$b['product_id']]->quantity);

        // The lines were priced from the catalogue, not from the guest basket.
        // `selling` is the unit price the order actually charges.
        $lineTotal = $ordered->reduce(function ($total, $line) {
            return $total + (float)$line->selling * (int)$line->quantity;
        }, 0.0);

        $this->assertEqualsWithDelta($expected, $lineTotal, 0.01,
            'The order total does not match the basket checkout was handed.');

        // And only now is the basket gone.
        $this->assertSame(0, Cart::where('user_id', $user->id)->count(),
            'The basket outlived the order it became.');
    }

    // ---- the end-to-end script ---------------------------------------------

    /**
     * The scenario the QA script ends on, start to finish.
     *
     * Guest adds A x2, B x1, then A x3 again; the account already holds A x2;
     * after signing in the basket is A x7 and B x1, on one row each, and stays
     * that way through a reload and a second sign-in.
     */
    public function test_the_full_guest_to_signed_in_journey()
    {
        [$a, $b] = $this->lines(2);

        // Both variants stay purchasable at these quantities.
        UpdatedInventory::whereIn('id', [$a['inventory_id'], $b['inventory_id']])
            ->update(['is_active' => 1]);

        $user = $this->customer();
        $this->addAsCustomer($user, $a, 2)->assertOk();

        // --- as a guest
        $token = $this->guestToken();

        $this->addAsGuest($token, $a, 2)->assertOk();
        $this->addAsGuest($token, $b, 1)->assertOk();
        $this->addAsGuest($token, $a, 3)->assertOk();

        $guestBasket = $this->guestBasket($token);

        $this->assertCount(2, $guestBasket, 'The guest basket should hold two products.');
        $this->assertSame(5, $this->storedQuantity('user_token', $token, $a), 'Guest A should be 2 + 3.');
        $this->assertSame(1, $this->storedQuantity('user_token', $token, $b));

        // --- sign in from the checkout
        $this->signIn($user, $token)->assertOk();

        $this->assertSame(7, $this->storedQuantity('user_id', $user->id, $a), 'Guest 5 + account 2 should be 7.');
        $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $b));
        $this->assertSame(1, $this->rowCount('user_id', $user->id, $a), 'A appears more than once.');

        // --- reload
        $afterReload = $this->customerBasket($user);

        $this->assertCount(2, $afterReload);
        $this->assertSame(8, (int)$afterReload->sum('quantity'));

        // --- sign out, sign in again
        $this->signIn($user, $token)->assertOk();

        $this->assertSame(7, $this->storedQuantity('user_id', $user->id, $a), 'The second sign-in merged the guest basket again.');
        $this->assertSame(1, $this->storedQuantity('user_id', $user->id, $b));

        // --- and the basket that checkout is handed is the same one
        $final = $this->customerBasket($user);

        $this->assertCount(2, $final);
        $this->assertSame(8, (int)$final->sum('quantity'));

        foreach ($final as $line) {
            $this->assertGreaterThan(0, (float)$line['upsell_price'], 'A line reached checkout with no unit price.');
        }
    }
}
