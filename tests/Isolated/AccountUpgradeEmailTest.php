<?php

namespace Tests\Isolated;

use App\Models\AccountUpgradeRequest;
use App\Models\Helper\AccountUpgrade;
use App\Models\User;
use Database\Seeders\Testing\Fixture;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatedTestCase;

/**
 * The emails an account upgrade sends, read off the array transport as the
 * customer would receive them: who gets one, when, and - the point of step 7 -
 * that a business customer turned down for trade is told business pricing
 * carries on, not that they are back on retail.
 *
 * The array transport rather than Mail::fake(): the app sends with raw
 * Mail::send($views, ...), which the fake does not capture.
 */
class AccountUpgradeEmailTest extends IsolatedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox()->flush();
    }

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

    private function onlyMessage()
    {
        $messages = $this->outbox();

        $this->assertCount(1, $messages, 'expected exactly one email');

        return reset($messages);
    }

    /** HTML and plain text together, tags stripped, whitespace collapsed. */
    private function textOf($message)
    {
        $body = $message->getBody();

        foreach ($message->getChildren() as $child) {
            $body .= "\n" . $child->getBody();
        }

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body)));
    }

    private function upgrade($userId, array $body)
    {
        return $this->asCustomer($userId)->postJson('/api/v1/user/account-upgrade', array_merge([
            'password' => Fixture::PASSWORD,
        ], $body));
    }

    private function tradeBody($overrides = [])
    {
        return array_merge([
            'to_type' => 'trade',
            'company_name' => 'Mail Trade Ltd',
            'vat_number' => 'IE5555555X',
            'billing_address' => '5 Mail Lane, Sligo',
            'trade' => 'Phone and tablet repair',
            'expected_spend' => 'Under €500',
            'resale_confirmed' => true,
        ], $overrides);
    }

    private function applyDirectly($userId)
    {
        AccountUpgrade::applyForTrade(User::findOrFail($userId), [
            'company_name' => 'Direct Trade Ltd',
            'vat_number' => 'IE' . $userId . '0000A',
            'billing_address' => '1 Fixture Street, Dublin',
            'trade' => 'Phone and tablet repair',
            'expected_spend' => 'Under €500',
            'resale_confirmed' => true,
        ], $upgrade);

        $this->mailbox()->flush();

        return $upgrade;
    }

    private function decide($upgrade, $decision, $note = null)
    {
        return $this->asAdmin()->postJson('/api/admin/trade-application/upgrades/action/' . $upgrade->id, [
            'decision' => $decision,
            'review_note' => $note,
        ]);
    }

    // ---- customer side --------------------------------------------------

    public function test_a_business_upgrade_confirms_by_email_and_asks_if_it_was_them()
    {
        $this->assertApiOk($this->upgrade(Fixture::USER_PERSONAL_ID, [
            'to_type' => 'business',
            'company_name' => 'Mail Business Ltd',
            'billing_address' => '4 Mail Street, Cork',
            'sector' => 'Other',
        ]));

        $message = $this->onlyMessage();
        $text = $this->textOf($message);

        $this->assertArrayHasKey(Fixture::email(Fixture::USER_PERSONAL_ID), $message->getTo());
        $this->assertStringContainsString('Your Business Account Is Ready', $message->getSubject());
        $this->assertStringContainsString('Mail Business Ltd', $text);
        $this->assertStringContainsString('Business pricing now applies automatically', $text);
        $this->assertStringContainsString('If you did not make this change', $text);
    }

    public function test_a_trade_application_from_a_business_says_business_pricing_carries_on()
    {
        $this->assertApiOk($this->upgrade(Fixture::USER_BUSINESS_ID, $this->tradeBody()));

        $message = $this->onlyMessage();
        $text = $this->textOf($message);

        $this->assertStringContainsString('We Have Received Your Trade Application', $message->getSubject());
        $this->assertStringContainsString('Mail Trade Ltd', $text, 'the company as applied for, not as on file');
        $this->assertStringContainsString('Phone and tablet repair', $text);
        $this->assertStringContainsString('business account and its pricing carry on', $text);
        $this->assertStringNotContainsString('standard prices', $text);
        $this->assertStringContainsString('/user/profile#upgrade', $message->getBody());
    }

    public function test_a_trade_application_from_a_personal_account_says_standard_prices_meanwhile()
    {
        $this->assertApiOk($this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody()));

        $text = $this->textOf($this->onlyMessage());

        $this->assertStringContainsString('keep ordering at our standard prices', $text);
        $this->assertStringNotContainsString('business account and its pricing', $text);
    }

    public function test_a_refused_upgrade_sends_nothing()
    {
        $this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody(['password' => 'Wrong-Password-1']));
        $this->upgrade(Fixture::USER_TRADE_APPROVED_ID, $this->tradeBody());

        $this->assertCount(0, $this->outbox());
    }

    public function test_the_upgrade_stands_when_the_mailer_fails()
    {
        Mail::shouldReceive('send')->once()->andThrow(new \Swift_TransportException('Connection refused'));

        $response = $this->upgrade(Fixture::USER_PERSONAL_ID, $this->tradeBody());

        $this->assertApiOk($response);
        $this->assertSame('pending', $response->json('data.pending.status'));
    }

    // ---- staff decision -------------------------------------------------

    public function test_approval_emails_once_with_the_reviewer_note()
    {
        $upgrade = $this->applyDirectly(Fixture::USER_PERSONAL_ID);

        $first = $this->decide($upgrade, 'approved', 'Welcome to trade.');
        $this->decide($upgrade, 'approved');

        $this->assertTrue($first->json('data.notification.delivered'));

        $message = $this->onlyMessage();
        $text = $this->textOf($message);

        $this->assertStringContainsString('Your Trade Account Has Been Approved', $message->getSubject());
        $this->assertStringContainsString('Welcome to trade.', $text);
        $this->assertStringContainsString('Direct Trade Ltd', $text, 'the company now on the approved profile');
    }

    public function test_a_rejected_business_is_told_business_pricing_still_applies()
    {
        $upgrade = $this->applyDirectly(Fixture::USER_BUSINESS_ID);

        $response = $this->decide($upgrade, 'rejected', 'We could not find that VAT number.');

        $this->assertTrue($response->json('data.notification.delivered'));

        $text = $this->textOf($this->onlyMessage());

        $this->assertStringContainsString('business pricing still applies', $text);
        $this->assertStringNotContainsString('order at our standard prices', $text);
        $this->assertStringContainsString('We could not find that VAT number.', $text, 'the note on the application');
    }

    public function test_a_rejected_personal_applicant_is_told_standard_prices()
    {
        $upgrade = $this->applyDirectly(Fixture::USER_PERSONAL_ID);

        $this->decide($upgrade, 'rejected');

        $text = $this->textOf($this->onlyMessage());

        $this->assertStringContainsString('order at our standard prices', $text);
        $this->assertStringNotContainsString('business pricing still applies', $text);
    }

    public function test_a_rejection_does_not_repeat_an_old_trade_note()
    {
        User::where('id', Fixture::USER_BUSINESS_ID)->update(['trade_review_note' => 'An old note from years ago']);
        $upgrade = $this->applyDirectly(Fixture::USER_BUSINESS_ID);

        $this->decide($upgrade, 'rejected');

        $this->assertStringNotContainsString('An old note from years ago', $this->textOf($this->onlyMessage()));
    }

    public function test_the_decision_stands_when_the_mailer_fails()
    {
        $upgrade = $this->applyDirectly(Fixture::USER_PERSONAL_ID);

        Mail::shouldReceive('send')->once()->andThrow(new \Swift_TransportException('Connection refused'));

        $response = $this->decide($upgrade, 'approved');

        $this->assertApiOk($response);
        $this->assertTrue($response->json('data.notification.triggered'));
        $this->assertFalse($response->json('data.notification.delivered'));
        $this->assertSame('trade', User::find(Fixture::USER_PERSONAL_ID)->account_type);
        $this->assertSame(AccountUpgradeRequest::STATUS_APPROVED, $upgrade->fresh()->status);
    }
}
