<?php

namespace App\Console\Commands;

use App\Http\Controllers\MailPreviewController;
use App\Models\Helper\MailBrand;
use App\Models\Helper\MailDeliverability;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

/**
 * Sends any email template to a real inbox.
 *
 * The browser previews at /mail-preview show the markup, but they cannot
 * show what actually matters about an email: how Gmail and Outlook rewrite
 * it, whether it lands in the inbox or spam, whether the plain-text part is
 * right, and whether images load from outside this machine. Those need a
 * message that has genuinely been through the mail transport.
 *
 *   php artisan mail:test order_placed you@example.com
 *   php artisan mail:test all you@example.com
 *   php artisan mail:test login_code you@example.com --lang=fr
 *   php artisan mail:test order_placed you@example.com --order=42
 */
class MailTest extends Command
{
    protected $signature = 'mail:test
                            {template : Template name, or "all", or "list" to show them}
                            {email? : Where to send it}
                            {--lang= : Language code, e.g. fr}
                            {--order= : Use a real order id instead of the sample}';

    protected $description = 'Send an email template to a real address for testing';

    /** Which templates carry a plain-text part and what each one is for. */
    const CATALOGUE = [
        'user_registration' => 'Sign-up verification code',
        'login_code' => 'Sign-in code (2FA by email)',
        'forgot_password' => 'Password reset code',
        'reset_password_link' => 'Password reset link',
        'seller_activation' => 'Vendor account approved',
        'guest_account_created' => 'Account opened at guest checkout (credentials)',
        'order_placed' => 'Order confirmation (customer)',
        'order_placed_to_vendor' => 'New order to fulfil (vendor)',
        'order_status_changed' => 'Order status update',
        'package_delivered' => 'Order delivered / review request',
        'subscription' => 'Newsletter (bulk)',
    ];

    public function handle()
    {
        $template = $this->argument('template');

        if ($template === 'list') {
            return $this->listTemplates();
        }

        $email = $this->argument('email');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give a valid email address: php artisan mail:test ' . $template . ' you@example.com');

            return 1;
        }

        $templates = $template === 'all'
            ? array_keys(self::CATALOGUE)
            : [$template];

        foreach ($templates as $name) {
            if (!isset(self::CATALOGUE[$name])) {
                $this->error('Unknown template "' . $name . '". Run: php artisan mail:test list');

                return 1;
            }
        }

        $lang = $this->option('lang') ?: null;
        $brand = MailBrand::make('Test Recipient', $lang);

        $sent = 0;

        foreach ($templates as $name) {
            try {
                $this->sendOne($name, $email, $lang, $brand);

                $this->line('  <info>sent</info>  ' . $name);
                $sent++;

            } catch (\Throwable $e) {
                $this->line('  <error>fail</error>  ' . $name . ' — ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info($sent . ' of ' . count($templates) . ' sent to ' . $email
            . ' via ' . config('mail.default') . '.');

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log" — nothing left the machine. Check storage/logs.');
        }

        return 0;
    }

    protected function sendOne($name, $email, $lang, $brand)
    {
        // Reuse the preview controller's fixtures so a test send and a
        // browser preview are always showing the same thing.
        $preview = app(MailPreviewController::class);

        $request = Request::create('/mail-test', 'GET', array_filter([
            'lang' => $lang,
            'order' => $this->option('order'),
        ]));

        $data = $this->invokeData($preview, $request, $name, $lang);

        $html = 'mail_templates.' . $name;
        $text = 'mail_templates.text.' . $name;

        $views = View::exists($text) ? ['html' => $html, 'text' => $text] : $html;

        $subject = MailDeliverability::subject('[TEST] ' . self::CATALOGUE[$name]);

        Mail::send($views, $data, function ($message) use ($email, $subject, $brand, $name) {
            $message->to($email)->subject($subject);

            if ($name === 'subscription') {
                MailDeliverability::bulk($message, $brand, $email);
            } else {
                MailDeliverability::transactional($message, $brand, 'test-' . $name);
            }
        });
    }

    /** The fixture builder on the preview controller is protected. */
    protected function invokeData($preview, $request, $name, $lang)
    {
        $method = new \ReflectionMethod($preview, 'data');
        $method->setAccessible(true);

        return $method->invoke($preview, $request, $name, $lang);
    }

    protected function listTemplates()
    {
        $rows = [];

        foreach (self::CATALOGUE as $name => $purpose) {
            $rows[] = [
                $name,
                $purpose,
                View::exists('mail_templates.text.' . $name) ? 'yes' : 'no',
            ];
        }

        $this->table(['Template', 'Sent when', 'Plain-text part'], $rows);

        $this->line('Preview in a browser: ' . rtrim(config('app.url'), '/') . '/mail-preview');
        $this->line('Send one for real:    php artisan mail:test <template> you@example.com');

        return 0;
    }
}
