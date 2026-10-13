<?php

namespace App\Http\Controllers;

use App\Models\Helper\MailBrand;
use App\Models\SubscriptionEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Backs the List-Unsubscribe header on the newsletter.
 *
 * Gmail and Yahoo have required a working one-click unsubscribe from bulk
 * senders since February 2024; without one, campaign mail gets throttled or
 * junked no matter how clean the HTML is. Two entry points, same handler:
 *
 *   POST - sent machine-to-machine by the mailbox provider (RFC 8058). Must
 *          answer 200 quickly and must not require a session or a token.
 *   GET  - the visible "Unsubscribe" link a person clicks.
 *
 * Both are signed URLs, so the address cannot be swapped for somebody else's.
 */
class NewsletterSubscriptionController extends Controller
{
    public function unsubscribe(Request $request)
    {
        // Laravel's signed-URL check. An unsigned or tampered link is refused
        // rather than silently unsubscribing whoever the query string names.
        if (!$request->hasValidSignature()) {
            return $this->respond($request, false, 403);
        }

        $email = trim((string)$request->query('email'));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->respond($request, false, 422);
        }

        try {
            SubscriptionEmail::where('email', $email)->delete();

        } catch (\Throwable $e) {
            Log::error('Unsubscribe failed', ['email' => $email, 'message' => $e->getMessage()]);

            return $this->respond($request, false, 500);
        }

        return $this->respond($request, true, 200, $email);
    }

    /**
     * The provider's POST wants a bare 200 and reads nothing else. A person
     * clicking the link wants a page that confirms it worked.
     */
    protected function respond(Request $request, $ok, $status, $email = null)
    {
        if ($request->isMethod('post')) {
            return response($ok ? 'OK' : 'Failed', $status)
                ->header('Content-Type', 'text/plain');
        }

        return response()
            ->view('mail_templates.unsubscribed', [
                'ok' => $ok,
                'email' => $email,
                'brand' => MailBrand::make(),
            ], $status);
    }
}
