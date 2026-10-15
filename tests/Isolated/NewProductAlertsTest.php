<?php

namespace Tests\Isolated;

use App\Models\Cart;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use App\Models\NewProductAlert;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\Carbon;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\IsolatedTestCase;

/**
 * Members hear about new products first (Helper\NewProductAlerts, 2026-10-15).
 *
 * What these pin:
 *  - a product going on sale for the first time is emailed to members whose
 *    plan has early access, once; products published together share one
 *    email; products already on sale are never announced;
 *  - non-members and members who turned it off get nothing;
 *  - anyone can buy a new product: the email is the benefit, not a lock;
 *  - the email's one-click unsubscribe works and cannot be forged.
 */
class NewProductAlertsTest extends IsolatedTestCase
{
    private $member;
    private $nonMember;
    private $plan;

    protected function setUp(): void
    {
        parent::setUp();

        // everything in the fixture has been on sale, and announced, for ages
        DB::table('products')->update([
            'first_published_at' => Carbon::now()->subYear(),
            'members_notified_at' => Carbon::now()->subYear(),
        ]);

        $this->plan = MembershipPlan::create([
            'name' => 'Premium', 'slug' => 'premium-alerts', 'price' => 9.99, 'currency' => 'EUR', 'status' => 1,
        ]);
        MembershipPlanBenefit::create([
            'membership_plan_id' => $this->plan->id, 'type' => MembershipPlanBenefit::EARLY_ACCESS, 'title' => 'Early Access',
        ]);

        $this->member = User::findOrFail(Fixture::USER_PERSONAL_ID);
        $this->nonMember = User::findOrFail(Fixture::USER_BUSINESS_ID);
        $this->makeMember($this->member);
    }

    private function makeMember(User $user)
    {
        UserMembership::create([
            'user_id' => $user->id, 'membership_plan_id' => $this->plan->id, 'source' => 'admin',
            'status' => 'active', 'current_period_end' => Carbon::now()->addMonth(),
        ]);
    }

    /** As if it had just been created and put on sale. */
    private function arrive($productId, $status = 1)
    {
        DB::table('products')->where('id', $productId)
            ->update(['first_published_at' => null, 'members_notified_at' => null, 'status' => $status]);
    }

    private function run_alerts()
    {
        Artisan::call('membership:new-product-alerts');
    }

    private function sentMail()
    {
        return app('mailer')->getSwiftMailer()->getTransport()->messages();
    }

    public function test_a_new_product_is_emailed_to_members_once()
    {
        $this->arrive(Fixture::PRODUCT_PHONE_ID);

        $this->run_alerts();

        $sent = $this->sentMail();
        $this->assertCount(1, $sent);
        $this->assertArrayHasKey($this->member->email, $sent[0]->getTo());
        $this->assertSame('Just arrived: Fixturo Phone X', $sent[0]->getSubject());
        $this->assertStringContainsString('/product/' . Fixture::PRODUCT_PHONE_ID, $sent[0]->getBody());
        $this->assertStringContainsString('membership/digest/unsubscribe', (string)$sent[0]->getHeaders()->get('List-Unsubscribe'));
        $this->assertNotNull(DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->value('members_notified_at'));
        $this->assertSame(1, (int)NewProductAlert::firstOrFail()->recipients_count);

        $this->run_alerts();
        $this->assertCount(1, $this->sentMail(), 'announced once');
    }

    public function test_products_published_together_come_in_one_email()
    {
        $this->arrive(Fixture::PRODUCT_PHONE_ID);
        $this->arrive(Fixture::PRODUCT_LAPTOP_ID);

        $this->run_alerts();

        $sent = $this->sentMail();
        $this->assertCount(1, $sent);
        $this->assertSame('2 new products just arrived', $sent[0]->getSubject());
    }

    public function test_nothing_new_nothing_sent()
    {
        $this->run_alerts();

        $this->assertCount(0, $this->sentMail());
        $this->assertSame(0, NewProductAlert::count());
    }

    public function test_a_product_is_announced_when_it_goes_on_sale_not_before()
    {
        $this->arrive(Fixture::PRODUCT_PHONE_ID, 2);   // created, not published

        $this->run_alerts();
        $this->assertCount(0, $this->sentMail());

        DB::table('products')->where('id', Fixture::PRODUCT_PHONE_ID)->update(['status' => 1]);
        $this->run_alerts();
        $this->assertCount(1, $this->sentMail());
    }

    public function test_non_members_and_members_who_opted_out_get_nothing()
    {
        $optedOut = User::findOrFail(Fixture::USER_TRADE_APPROVED_ID);
        $optedOut->member_digest_opt_out = true;
        $optedOut->save();
        $this->makeMember($optedOut);

        $this->arrive(Fixture::PRODUCT_PHONE_ID);
        $this->run_alerts();

        $recipients = collect($this->sentMail())->flatMap(function ($m) {
            return array_keys($m->getTo());
        })->all();

        $this->assertSame([$this->member->email], $recipients);
    }

    public function test_anyone_can_buy_a_new_product()
    {
        $this->arrive(Fixture::PRODUCT_PHONE_ID);
        $this->run_alerts();

        Cart::where('user_id', $this->nonMember->id)->delete();
        $this->assertApiOk($this->asCustomer($this->nonMember->id)->postJson('/api/v1/cart/action', [
            'product_id' => Fixture::PRODUCT_PHONE_ID, 'inventory_id' => Fixture::PHONE_128_BLACK_ID, 'quantity' => 1,
        ]));
    }

    public function test_the_unsubscribe_link_turns_off_this_email_only_and_cannot_be_forged()
    {
        $url = URL::signedRoute('membership.digest.unsubscribe', ['user' => $this->member->id]);

        $this->post(str_replace(url('/'), '', $url))->assertStatus(200);
        $this->assertTrue((bool)$this->member->fresh()->member_digest_opt_out);
        $this->assertSame(1, UserMembership::where('user_id', $this->member->id)->entitled()->count(), 'membership unchanged');

        $this->post('/membership/digest/unsubscribe?user=' . $this->nonMember->id)->assertStatus(403);
        $this->assertFalse((bool)$this->nonMember->fresh()->member_digest_opt_out);
    }

    public function test_a_member_can_switch_the_email_back_on()
    {
        $this->member->member_digest_opt_out = true;
        $this->member->save();

        $response = $this->asCustomer($this->member->id)->postJson('/api/v1/user/membership/digest', ['enabled' => true]);

        $this->assertApiOk($response);
        $response->assertJsonPath('data.digest.enabled', true);
        $response->assertJsonPath('data.digest.available', true);
        $this->assertFalse((bool)$this->member->fresh()->member_digest_opt_out);
    }
}
