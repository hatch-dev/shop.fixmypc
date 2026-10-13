<?php

namespace Tests\Feature;

use App\Models\GiftVoucher;
use App\Models\GiftVoucherCode;
use App\Models\GiftVoucherOrder;
use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderedVoucher;
use App\Models\Voucher;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Buying a gift card, for yourself and as a gift, and redeeming it.
 *
 * Redeeming puts the card in the redeemer's voucher list (a voucher of their
 * own, code = the gift code) to apply at checkout. It is worth what is left
 * on it: orders that stand take their discount off, a cancelled one gives it
 * back, and what an order does not use stays for the next.
 *
 * Recipients are disposable yopmail.com addresses. phpunit.xml pins
 * MAIL_MAILER=array, so nothing leaves the machine: the email is read back
 * off the array transport, which holds the rendered message (MailFake would
 * drop these, since MailHelper sends with the raw Mail::send form).
 *
 * The API refuses with HTTP 422 and a `message`; field problems also come
 * back as `errors`, keyed by field, which the Vouchers page shows under each
 * input. Tests assert the message, not just the status: several rules can
 * refuse the same request.
 */
class GiftCardTest extends TestCase
{
    use DatabaseTransactions;

    const RECIPIENT = 'fixmypc-gift-test@yopmail.com';

    /** @var User */
    private $buyer;

    /** @var GiftVoucher */
    private $design;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox()->flush();

        $this->buyer = User::create([
            'name' => 'Gift Buyer',
            'email' => 'fixmypc-gift-buyer-' . uniqid() . '@yopmail.com',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();

        // Its own design, so an admin edit to the live ones cannot move the suite.
        $this->design = GiftVoucher::create([
            'title' => 'Test Birthday Card',
            'description' => 'Fixture',
            'amounts' => [10, 25, 50],
            'min_quantity' => 1,
            'max_quantity' => 4,
            'is_active' => true,
        ]);

        $this->fundWallet(100);
        Passport::actingAs($this->buyer, ['user'], 'user');
    }

    // ------------------------------------------------------------- fixtures

    private function mailbox()
    {
        return Mail::getSwiftMailer()->getTransport();
    }

    /** @return \Swift_Message[] */
    private function outbox()
    {
        $messages = $this->mailbox()->messages();

        return is_array($messages) ? $messages : $messages->all();
    }

    private function fundWallet($balance)
    {
        Wallet::updateOrCreate(['user_id' => $this->buyer->id], ['balance' => $balance, 'currency' => 'EUR']);
    }

    private function balance()
    {
        return round((float)Wallet::where('user_id', $this->buyer->id)->value('balance'), 2);
    }

    private function gift(array $overrides = [])
    {
        return array_merge([
            'voucher_id' => $this->design->id,
            'amount' => 25,
            'quantity' => 1,
            'payment_method' => 'wallet',
            'recipient_name' => 'Aoife Murphy',
            'recipient_email' => self::RECIPIENT,
            'sender_name' => 'Gift Buyer',
            'message' => 'Happy birthday! Treat yourself.',
        ], $overrides);
    }

    private function forMe(array $overrides = [])
    {
        return array_merge([
            'voucher_id' => $this->design->id,
            'amount' => 25,
            'quantity' => 1,
            'payment_method' => 'wallet',
        ], $overrides);
    }

    private function buy(array $payload)
    {
        return $this->postJson('/api/v1/user/purchase-gift-voucher', $payload);
    }

    private function codesBought()
    {
        return GiftVoucherCode::where('buyer_user_id', $this->buyer->id)->get();
    }

    private function assertRefusedOn($response, $field, $message)
    {
        $response->assertStatus(422);
        $this->assertSame($message, $response->json('errors.' . $field), 'field error for ' . $field);
        $this->assertSame($message, $response->json('message'));
        $this->assertCount(0, $this->codesBought(), 'a refused purchase issued codes');
        $this->assertSame(100.0, $this->balance(), 'a refused purchase moved the wallet');
        $this->assertCount(0, $this->outbox(), 'a refused purchase sent email');
    }

    // ----------------------------------------------------- buying for me

    public function test_buying_for_me_issues_a_code_debits_the_wallet_and_emails_me()
    {
        $res = $this->buy($this->forMe());

        $res->assertOk();
        $this->assertSame('Your gift card is ready: see My gift cards (and your inbox).', $res->json('message'));
        $this->assertEquals(75, $res->json('balance'));
        $this->assertSame(75.0, $this->balance());

        $codes = $this->codesBought();
        $this->assertCount(1, $codes);
        $code = $codes->first();
        $this->assertMatchesRegularExpression('/^GIFT-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code->code);
        $this->assertEquals(25, $code->amount);
        $this->assertSame(GiftVoucherCode::ACTIVE, $code->status);
        $this->assertTrue($code->expires_at->between(now()->addYears(5)->subDay(), now()->addYears(5)->addDay()));

        $order = GiftVoucherOrder::find($code->gift_voucher_order_id);
        $this->assertSame('success', $order->status);
        $this->assertNull($order->recipient_email);

        $this->assertTrue(WalletTransaction::where([
            'user_id' => $this->buyer->id, 'source' => 'gift_voucher', 'type' => 'debit', 'reference_id' => $order->id,
        ])->where('amount', 25)->exists());

        $mail = $this->outbox();
        $this->assertCount(1, $mail);
        $this->assertArrayHasKey($this->buyer->email, $mail[0]->getTo());
        $this->assertStringContainsString($code->code, $mail[0]->getBody());
        $this->assertSame($this->buyer->email, $code->fresh()->sent_to);
    }

    public function test_buying_several_for_me_issues_one_code_each()
    {
        $res = $this->buy($this->forMe(['amount' => 10, 'quantity' => 3]));

        $res->assertOk();
        $this->assertSame('3 gift cards are ready: see My gift cards (and your inbox).', $res->json('message'));
        $this->assertSame(70.0, $this->balance());

        $codes = $this->codesBought();
        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->pluck('code')->unique());

        $body = $this->outbox()[0]->getBody();
        foreach ($codes as $c) {
            $this->assertStringContainsString($c->code, $body);
        }
    }

    public function test_recipient_fields_are_ignored_without_a_recipient_email()
    {
        $res = $this->buy($this->forMe(['recipient_name' => 'Nobody', 'message' => 'Stray']));

        $res->assertOk();
        $order = GiftVoucherOrder::where('user_id', $this->buyer->id)->latest('id')->first();
        $this->assertNull($order->recipient_name);
        $this->assertNull($order->message);
        $this->assertArrayHasKey($this->buyer->email, $this->outbox()[0]->getTo());
    }

    // ----------------------------------------------------- buying as a gift

    public function test_buying_as_a_gift_emails_the_yopmail_recipient_with_name_and_message()
    {
        $res = $this->buy($this->gift());

        $res->assertOk();
        $this->assertSame('Your gift card went to ' . self::RECIPIENT . '.', $res->json('message'));
        $this->assertSame(75.0, $this->balance());

        $order = GiftVoucherOrder::where('user_id', $this->buyer->id)->latest('id')->first();
        $this->assertSame(self::RECIPIENT, $order->recipient_email);
        $this->assertSame('Aoife Murphy', $order->recipient_name);
        $this->assertSame('Happy birthday! Treat yourself.', $order->message);

        $mail = $this->outbox();
        $this->assertCount(1, $mail, 'the gift goes to the recipient only');
        $this->assertSame([self::RECIPIENT => 'Aoife Murphy'], $mail[0]->getTo());
        $this->assertStringContainsString('Gift Buyer sent you a', $mail[0]->getSubject());

        $code = $this->codesBought()->first();
        $body = $mail[0]->getBody();
        $this->assertStringContainsString($code->code, $body);
        $this->assertStringContainsString('Happy birthday! Treat yourself.', $body);
        $this->assertSame(self::RECIPIENT, $code->fresh()->sent_to);

        // The buyer still sees it, marked with who it went to.
        $list = $this->getJson('/api/v1/user/gift-voucher-codes');
        $this->assertSame($code->code, $list->json('data.0.code'));
        $this->assertSame('available', $list->json('data.0.state'));
        $this->assertSame('Aoife Murphy <' . self::RECIPIENT . '>', $list->json('data.0.recipient'));
    }

    public function test_several_gift_cards_go_to_the_recipient_in_one_email()
    {
        $res = $this->buy($this->gift(['amount' => 10, 'quantity' => 2]));

        $res->assertOk();
        $this->assertSame('2 gift cards went to ' . self::RECIPIENT . '.', $res->json('message'));
        $this->assertCount(1, $this->outbox());
        $this->assertCount(2, $this->codesBought());
    }

    public function test_a_gift_without_a_message_is_accepted()
    {
        $this->buy($this->gift(['message' => null]))->assertOk();
        $this->assertCount(1, $this->codesBought());
    }

    private function customer($tag)
    {
        return User::create([
            'name' => 'Aoife Murphy',
            'email' => "fixmypc-gift-$tag-" . uniqid() . '@yopmail.com',
            'phone' => '08' . random_int(10000000, 99999999),
            'password' => bcrypt('Abcdefghij1'),
            'account_type' => 'personal',
            'verified' => true,
            'status' => User::STATUS_ACTIVE,
        ])->fresh();
    }

    private function actAs(User $user)
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs($user, ['user'], 'user');
    }

    /** A €25 gift card bought as a gift and redeemed by a second customer. */
    private function redeemedCard()
    {
        $this->buy($this->gift())->assertOk();
        $code = $this->codesBought()->first();

        $recipient = $this->customer('recipient');
        $this->actAs($recipient);
        $this->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $code->code])->assertOk();

        return [$code->fresh(), $recipient, Voucher::find($code->fresh()->voucher_id)];
    }

    /** An order that used the voucher, standing (paid) unless said otherwise. */
    private function orderUsing(Voucher $voucher, User $user, $discount, array $overrides = [])
    {
        $order = Order::create(array_merge([
            'order' => 'TEST' . uniqid(),
            'user_id' => $user->id,
            'voucher_id' => $voucher->id,
            'status' => config('constants.orderStatus.PENDING'),
            'order_method' => config('constants.paymentMethod.WALLET'),
            'total_amount' => 10,
            'currency' => 'EUR',
            'payment_done' => 1,
            'contact_name' => 'Aoife Murphy',
            'contact_email' => $user->email,
            'contact_phone' => '+353871234567',
        ], $overrides));

        OrderedVoucher::create(['order_id' => $order->id, 'voucher_id' => $voucher->id, 'discount' => $discount]);

        return $order;
    }

    public function test_redeeming_adds_the_card_to_the_recipients_vouchers_not_their_wallet()
    {
        $this->buy($this->gift())->assertOk();
        $code = $this->codesBought()->first();
        $recipient = $this->customer('recipient');
        $this->actAs($recipient);

        // Typed loosely, as people do: lower case, spaces, no dashes.
        $loose = strtolower(str_replace('-', ' ', $code->code));
        $res = $this->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $loose]);

        $res->assertOk();
        $this->assertSame($code->code, $res->json('voucher_code'));
        $this->assertSame('Your €25.00 gift card is now in your vouchers. Apply it at checkout; anything left over stays for your next order.', $res->json('message'));

        $voucher = Voucher::where('code', $code->code)->first();
        $this->assertNotNull($voucher);
        $this->assertEquals($recipient->id, $voucher->user_id);
        $this->assertEquals(25, $voucher->gift_value);
        $this->assertEquals(0, $voucher->min_spend);
        $this->assertSame('Gift card – Test Birthday Card', $voucher->title);
        $this->assertSame($code->expires_at->toDateString(), \Carbon\Carbon::parse($voucher->end_time)->toDateString());

        $this->assertSame(GiftVoucherCode::REDEEMED, $code->fresh()->status);
        $this->assertEquals($voucher->id, $code->fresh()->voucher_id);
        $this->assertNull(Wallet::where('user_id', $recipient->id)->value('balance'), 'no wallet credit');

        // It is in their list, with its balance.
        $list = collect($this->getJson('/api/v1/user/user-vouchers?order_by=id&type=desc')->json('data.data'));
        $mine = $list->firstWhere('code', $code->code);
        $this->assertNotNull($mine, 'the card is in the voucher list');
        $this->assertTrue($mine['is_gift_card']);
        $this->assertSame('25.00', $mine['gift_balance']);

        // A second time is refused, for them and for anyone else.
        $again = $this->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $code->code]);
        $again->assertStatus(422);
        $this->assertSame('This gift card is already in your vouchers.', $again->json('message'));

        $this->actAs($this->buyer);
        $late = $this->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => $code->code]);
        $late->assertStatus(422);
        $this->assertSame('This gift card has already been redeemed.', $late->json('message'));
        $this->assertSame(1, Voucher::where('code', $code->code)->count());
    }

    public function test_a_redeemed_card_is_the_redeemers_alone()
    {
        [$code] = $this->redeemedCard();

        // The buyer knows the code, but it is not in their list.
        $this->actAs($this->buyer);
        $list = collect($this->getJson('/api/v1/user/user-vouchers?order_by=id&type=desc')->json('data.data'));
        $this->assertNull($list->firstWhere('code', $code->code));
    }

    public function test_a_card_takes_off_its_balance_up_to_the_value_of_the_goods()
    {
        [, , $voucher] = $this->redeemedCard();

        $this->assertEquals(10, Pricing::voucherDiscount($voucher, 10), 'a small basket: the goods, not the card');
        $this->assertEquals(25, Pricing::voucherDiscount($voucher, 80), 'a big basket: the whole card');
    }

    public function test_what_an_order_does_not_use_stays_on_the_card()
    {
        [, $recipient, $voucher] = $this->redeemedCard();

        $this->orderUsing($voucher, $recipient, 10);
        $this->assertSame(15.0, $voucher->giftBalance());
        $this->assertEquals(15, Pricing::voucherDiscount($voucher, 80));

        $list = collect($this->getJson('/api/v1/user/user-vouchers?order_by=id&type=desc')->json('data.data'));
        $this->assertSame('15.00', $list->firstWhere('id', $voucher->id)['gift_balance']);

        $this->orderUsing($voucher, $recipient, 15);
        $this->assertSame(0.0, $voucher->giftBalance());
        $this->assertEquals(0, Pricing::voucherDiscount($voucher, 80));
    }

    public function test_an_unpaid_cancelled_returned_or_refunded_order_does_not_spend_the_card()
    {
        [, $recipient, $voucher] = $this->redeemedCard();

        $this->orderUsing($voucher, $recipient, 25, ['payment_done' => 0]);
        $this->assertSame(25.0, $voucher->giftBalance(), 'an unpaid card order');

        $this->orderUsing($voucher, $recipient, 25)->forceFill(['cancelled' => 1])->save();
        $this->assertSame(25.0, $voucher->giftBalance(), 'a cancelled order');

        $this->orderUsing($voucher, $recipient, 25, ['status' => config('constants.orderStatus.REFUNDED')]);
        $this->assertSame(25.0, $voucher->giftBalance(), 'a refunded order');

        $this->orderUsing($voucher, $recipient, 25, ['status' => config('constants.orderStatus.RETURNED')]);
        $this->assertSame(25.0, $voucher->giftBalance(), 'a returned order');
    }

    public function test_a_used_up_card_is_refused_at_the_voucher_check()
    {
        [, $recipient, $voucher] = $this->redeemedCard();
        $this->orderUsing($voucher, $recipient, 25);

        $res = $this->postJson('/api/v1/voucher/validity', ['voucher' => $voucher->code, 'price' => 80]);

        $this->assertSame('This gift card has been used up.', $res->json('message'));
    }

    /** The admin sales report's row for one code. */
    private function adminRow($code)
    {
        $admin = \App\Models\Admin::whereHas('roles', function ($q) { $q->where('name', 'superadmin'); })->first();
        if (!$admin) {
            $this->markTestSkipped('No superadmin to drive the admin API with.');
        }
        $this->app['auth']->forgetGuards();
        Passport::actingAs($admin, ['admin'], 'admin');

        $res = $this->getJson('/api/admin/gift-card/all?q=' . urlencode($code));
        return [$res->json('data.data.0'), $res->json('data.summary')];
    }

    private function adminFilter($status, $code)
    {
        $res = $this->getJson('/api/admin/gift-card/all?q=' . urlencode($code) . '&status=' . $status);
        return collect($res->json('data.data'))->pluck('code')->all();
    }

    public function test_admin_sees_a_card_as_in_vouchers_until_an_order_spends_it()
    {
        [$code, $recipient, $voucher] = $this->redeemedCard();

        [$row] = $this->adminRow($code->code);
        $this->assertSame('in_vouchers', $row['state'], 'added, but nothing spent');
        $this->assertSame('0.00', $row['spent']);
        $this->assertSame('25.00', $row['left']);
        $this->assertSame([$code->code], $this->adminFilter('in_vouchers', $code->code));
        $this->assertSame([], $this->adminFilter('used_up', $code->code));

        $this->orderUsing($voucher, $recipient, 10);
        [$row] = $this->adminRow($code->code);
        $this->assertSame('partly_used', $row['state']);
        $this->assertSame('10.00', $row['spent']);
        $this->assertSame('15.00', $row['left']);
        $this->assertSame([$code->code], $this->adminFilter('partly_used', $code->code));

        $this->orderUsing($voucher, $recipient, 15);
        [$row] = $this->adminRow($code->code);
        $this->assertSame('used_up', $row['state']);
        $this->assertSame('0.00', $row['left']);
        $this->assertSame([$code->code], $this->adminFilter('used_up', $code->code));
    }

    public function test_admin_summary_counts_spending_not_redeeming()
    {
        $before = $this->adminRow('no-such-code')[1];
        $this->actAs($this->buyer);

        [$code, $recipient, $voucher] = $this->redeemedCard();
        $this->actAs($this->buyer);
        $this->buy($this->forMe(['amount' => 10]))->assertOk();   // a second card, never added

        $after = $this->adminRow($code->code)[1];
        $delta = function ($k) use ($before, &$after) { return round($after[$k] - $before[$k], 2); };

        $this->assertEquals(35, $delta('sold'));
        $this->assertEquals(0, $delta('spent'), 'adding a card to vouchers spends nothing');
        $this->assertEquals(35, $delta('outstanding'));
        $this->assertEquals(1, $after['added'] - $before['added']);

        $this->orderUsing($voucher, $recipient, 10);
        $after = $this->adminRow($code->code)[1];
        $this->assertEquals(10, $delta('spent'));
        $this->assertEquals(25, $delta('outstanding'));
    }
    public function test_gift_cards_stay_out_of_the_admin_voucher_list()
    {
        [, , $voucher] = $this->redeemedCard();

        $this->assertTrue(Voucher::whereNull('vouchers.gift_value')->where('id', $voucher->id)->doesntExist());
    }
    public function test_an_unknown_code_is_refused()
    {
        $res = $this->postJson('/api/v1/user/gift-voucher-codes/redeem', ['code' => 'GIFT-ZZZZ-ZZZZ']);

        $res->assertStatus(422);
        $this->assertSame('We could not find that gift card code. Check it and try again.', $res->json('message'));
        $this->assertSame(100.0, $this->balance());
    }

    // ----------------------------------------------- recipient validation

    public function test_a_gift_needs_the_recipients_name()
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_name' => ''])),
            'recipient_name', "Enter the recipient's name.");
    }

    public function test_a_one_letter_name_is_refused()
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_name' => 'A'])),
            'recipient_name', "The recipient's name looks too short.");
    }

    public function test_a_name_without_letters_is_refused()
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_name' => '12345'])),
            'recipient_name', "Enter the recipient's name in letters.");
    }

    public function test_a_name_over_120_characters_is_refused()
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_name' => str_repeat('a', 121)])),
            'recipient_name', "The recipient's name is too long (120 characters at most).");
    }

    public function test_an_accented_name_is_accepted()
    {
        $this->buy($this->gift(['recipient_name' => 'Siobhán Ó Briain']))->assertOk();
        $this->assertSame('Siobhán Ó Briain', GiftVoucherOrder::where('user_id', $this->buyer->id)->value('recipient_name'));
    }

    /**
     * @dataProvider badEmails
     */
    public function test_a_malformed_recipient_email_is_refused($email)
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_email' => $email])),
            'recipient_email', 'Enter a valid email address for the recipient.');
    }

    public function badEmails()
    {
        return [
            'no at sign' => ['fixmypc-gift-test.yopmail.com'],
            'no local part' => ['@yopmail.com'],
            'two at signs' => ['a@@yopmail.com'],
            'space inside' => ['fixmypc gift@yopmail.com'],
        ];
    }

    public function test_an_email_over_190_characters_is_refused()
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_email' => str_repeat('a', 180) . '@yopmail.com'])),
            'recipient_email', 'That email address is too long.');
    }

    public function test_the_buyers_own_email_is_refused_as_a_gift_recipient()
    {
        $this->assertRefusedOn($this->buy($this->gift(['recipient_email' => strtoupper($this->buyer->email)])),
            'recipient_email', 'That is your own email address. Choose "For me" instead.');
    }

    public function test_a_message_over_300_characters_is_refused()
    {
        $this->assertRefusedOn($this->buy($this->gift(['message' => str_repeat('x', 301)])),
            'message', 'Keep the message to 300 characters.');
    }

    public function test_a_message_of_exactly_300_characters_is_accepted()
    {
        $this->buy($this->gift(['message' => str_repeat('x', 300)]))->assertOk();
    }

    public function test_every_bad_field_is_reported_at_once()
    {
        $res = $this->buy($this->gift(['recipient_name' => '', 'recipient_email' => 'nope', 'message' => str_repeat('x', 301)]));

        $res->assertStatus(422);
        $this->assertEqualsCanonicalizing(['recipient_name', 'recipient_email', 'message'], array_keys($res->json('errors')));
    }

    // ------------------------------------------------ purchase validation

    public function test_an_amount_the_design_does_not_offer_is_refused()
    {
        $res = $this->buy($this->forMe(['amount' => 30]));

        $res->assertStatus(422);
        $this->assertSame('That amount is not available for this gift card.', $res->json('message'));
        $this->assertCount(0, $this->codesBought());
    }

    public function test_a_quantity_outside_the_designs_range_is_refused()
    {
        $res = $this->buy($this->forMe(['amount' => 10, 'quantity' => 5]));

        $res->assertStatus(422);
        $this->assertSame('Choose between 1 and 4.', $res->json('message'));
        $this->assertCount(0, $this->codesBought());
    }

    public function test_a_withdrawn_design_cannot_be_bought()
    {
        $this->design->update(['is_active' => false]);

        $res = $this->buy($this->gift());

        $res->assertStatus(422);
        $this->assertSame('This gift card is no longer available.', $res->json('message'));
    }

    public function test_a_short_wallet_is_refused_without_charging()
    {
        $this->fundWallet(20);

        $res = $this->buy($this->gift());

        $res->assertStatus(422);
        $this->assertSame('There is not enough in your wallet for this purchase.', $res->json('message'));
        $this->assertSame(20.0, $this->balance());
        $this->assertCount(0, $this->codesBought());
        $this->assertCount(0, $this->outbox());
    }

    public function test_a_guest_cannot_buy()
    {
        $this->app['auth']->forgetGuards();

        $res = $this->postJson('/api/v1/user/purchase-gift-voucher', $this->gift());
        // Refused in the body, not the HTTP code (see phpunit-api-testing notes).
        $this->assertSame(204, $res->json('status'));
        $this->assertSame('Unauthenticated.', $res->json('message'));
        $this->assertCount(0, GiftVoucherCode::where('buyer_user_id', $this->buyer->id)->get());
    }

    // ------------------------------------------------------- paying by card

    public function test_a_card_gift_is_held_pending_until_sumup_says_paid_then_emailed_once()
    {
        Http::fake([
            'api.sumup.com/v0.1/checkouts' => Http::response(['id' => 'chk_test_gift_1'], 201),
            'api.sumup.com/v0.1/checkouts/chk_test_gift_1' => Http::sequence()
                ->push(['id' => 'chk_test_gift_1', 'status' => 'FAILED', 'amount' => 50, 'currency' => 'EUR',
                    'description' => 'Gift Voucher Purchase #X'])
                ->push(['id' => 'chk_test_gift_1', 'status' => 'PAID', 'amount' => 50, 'currency' => 'EUR',
                    'description' => 'Gift Voucher Purchase #X', 'transaction_code' => 'TX1']),
        ]);

        $start = $this->postJson('/api/v1/user/purchase-gift-voucher/start', $this->gift(['amount' => 25, 'quantity' => 2]));
        $start->assertOk();
        $this->assertSame('chk_test_gift_1', $start->json('checkout_id'));

        $order = GiftVoucherOrder::where('reference', 'chk_test_gift_1')->first();
        $this->assertSame('pending', $order->status);
        $this->assertEquals(50, $order->total);
        $this->assertSame(self::RECIPIENT, $order->recipient_email);
        $this->assertCount(0, $this->codesBought(), 'no codes before payment');
        $this->assertCount(0, $this->outbox());

        // Declined card: nothing issued, and the customer is told nothing was charged.
        $declined = $this->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_test_gift_1']);
        $declined->assertStatus(422);
        $this->assertSame('The payment did not go through, so nothing was charged. Please try again.', $declined->json('message'));
        $this->assertCount(0, $this->codesBought());

        // Paid.
        $paid = $this->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_test_gift_1']);
        $paid->assertOk();
        $this->assertSame('2 gift cards went to ' . self::RECIPIENT . '.', $paid->json('message'));
        $this->assertSame('success', $order->fresh()->status);
        $this->assertCount(2, $this->codesBought());
        $this->assertCount(1, $this->outbox());
        $this->assertSame(100.0, $this->balance(), 'a card purchase does not touch the wallet');

        // Confirming again (a refresh) issues nothing more.
        $this->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_test_gift_1'])->assertOk();
        $this->assertCount(2, $this->codesBought());
        $this->assertCount(1, $this->outbox());
    }

    public function test_a_card_gift_is_validated_before_any_checkout_is_opened()
    {
        Http::fake();

        $res = $this->postJson('/api/v1/user/purchase-gift-voucher/start', $this->gift(['recipient_email' => 'nope']));

        $res->assertStatus(422);
        $this->assertSame('Enter a valid email address for the recipient.', $res->json('errors.recipient_email'));
        Http::assertNothingSent();
        $this->assertSame(0, GiftVoucherOrder::where('user_id', $this->buyer->id)->count());
    }

    public function test_someone_elses_checkout_cannot_be_confirmed()
    {
        $res = $this->postJson('/api/v1/user/purchase-gift-voucher/confirm', ['checkout_id' => 'chk_not_mine']);

        $res->assertStatus(422);
        $this->assertSame('We could not find this gift card purchase.', $res->json('message'));
    }
}
