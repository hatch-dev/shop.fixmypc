<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio messaging.
 *
 * Two things this class has to get right that the previous version did not:
 *
 *  1. sendSMS() has to exist. TwoFactorController calls it on every SMS
 *     second-factor setup, and it was never written — so the call raised
 *     "Call to undefined method", which is an Error rather than an Exception
 *     and sailed straight through the controller's catch into a 500.
 *
 *  2. A non-2xx from Twilio has to be treated as a failure. Http::post() does
 *     not throw on a 4xx, so returning $response->json() unconditionally, as
 *     sendWhatsApp() did, reports Twilio's error body to the caller as though
 *     it were a success.
 */
class TwilioService
{
    /**
     * How long a placeholder value has to be before finding it in the
     * delivered text proves the template was ours. Short values -- an item
     * count above all -- appear by coincidence in any wording.
     */
    const MIN_TEMPLATE_EVIDENCE = 6;

    /** Twilio's REST API rejects a body longer than this. */
    const MAX_BODY = 1600;

    /**
     * Twilio's canned two-factor template, the only 2FA body a trial account
     * will accept. Twilio writes the message itself and puts its own
     * placeholder code in it, so whatever this sends has to be read back out
     * of the response — see sendTemplateSms().
     */
    const TRIAL_2FA_TEMPLATE = 'sms_2fa';

    protected $sid;
    protected $token;
    protected $from;
    protected $smsFrom;

    public function __construct()
    {
        $this->sid = config('services.twilio.sid');
        $this->token = config('services.twilio.token');
        $this->from = config('services.twilio.from');

        /*
         * A WhatsApp sender and an SMS sender are usually different numbers —
         * in particular Twilio's shared WhatsApp sandbox number cannot send
         * SMS at all. Keep them separate, falling back to the one number when
         * only one is configured.
         */
        $this->smsFrom = config('services.twilio.sms_from') ?: $this->from;
    }

    /**
     * Whether SMS can be attempted at all. Checked before sending so an
     * unconfigured install fails with something a developer can act on
     * instead of a 401 from Twilio.
     */
    public function canSendSms()
    {
        return $this->sid && $this->token && $this->smsFrom;
    }

    public function canSendWhatsApp()
    {
        return $this->sid && $this->token && $this->from;
    }

    /**
     * Why SMS cannot be used right now, or null if it can.
     *
     * Meant for the UI, so a second factor that cannot possibly work is not
     * offered to a customer in the first place. Costs one cached Twilio API
     * call, so it belongs on a status endpoint rather than the send path.
     */
    public function smsUnavailableReason()
    {
        if (!$this->canSendSms()) {
            return 'Twilio is not configured for SMS. Set TWILIO_SID, TWILIO_AUTH_TOKEN '
                . 'and an SMS-capable TWILIO_SMS_FROM (or TWILIO_PHONE).';
        }

        /*
         * Deliberately not gated on the account tier. A Twilio trial rejects
         * custom message text with error 572006, so the option is offered and
         * the send reports that error rather than the card being hidden — the
         * product decision is to keep the choice visible.
         *
         * accountType() stays available and is reported alongside the status
         * for diagnosis. What must NOT happen is "fixing" 572006 by sending
         * the name of a canned Twilio template such as "sms_2fa": that
         * template's body is a fixed demo string carrying the placeholder
         * code 482913 on every send, so the customer would receive a code
         * that is not the one hashed into login_codes and every sign-in would
         * fail with nothing to explain why. Failing the send is correct;
         * delivering the wrong code is not.
         */

        return null;
    }

    public function smsAvailable()
    {
        return $this->smsUnavailableReason() === null;
    }

    /**
     * "Trial" or "Full", cached briefly so a status call does not hit Twilio
     * on every page load. Short TTL so an upgrade is picked up without a
     * deploy or a manual cache clear.
     *
     * @return string|null null when the account cannot be read.
     */
    public function accountType()
    {
        if (!$this->sid || !$this->token) {
            return null;
        }

        return \Illuminate\Support\Facades\Cache::remember(
            'twilio.account_type.' . substr($this->sid, -6),
            now()->addMinutes(10),
            function () {
                try {
                    $response = Http::withBasicAuth($this->sid, $this->token)
                        ->timeout(10)
                        ->get('https://api.twilio.com/2010-04-01/Accounts/' . $this->sid . '.json');

                    if (!$response->successful()) {
                        return null;
                    }

                    $type = $response->json('type');

                    return $type ?: null;

                } catch (\Throwable $e) {
                    Log::warning('Could not read Twilio account type', ['error' => $e->getMessage()]);

                    return null;
                }
            }
        );
    }

    /**
     * Send an SMS.
     *
     * @throws \RuntimeException when the message is not accepted by Twilio.
     * @return array Twilio's message resource.
     */
    public function sendSMS($to, $message)
    {
        if (!$this->canSendSms()) {
            throw new \RuntimeException(
                'Twilio is not configured for SMS. Set TWILIO_SID, TWILIO_AUTH_TOKEN '
                . 'and an SMS-capable TWILIO_SMS_FROM (or TWILIO_PHONE).'
            );
        }

        $to = static::toE164($to);

        if (!$to) {
            throw new \RuntimeException('No usable mobile number to send to.');
        }

        return $this->post([
            'From' => $this->smsFrom,
            'To' => $to,
            'Body' => static::trimBody($message),
        ], 'sms');
    }

    /**
     * Whether to send the name of a canned Twilio template instead of our own
     * message text.
     *
     * A trial account rejects custom text with error 572006 and accepts only
     * a template name. Twilio then writes the body itself, including its own
     * placeholder verification code — so the caller MUST read the delivered
     * code back out of the response (extractCode) and store that, or the
     * customer gets a code the application has never heard of.
     *
     * Never on in production: the code Twilio puts in that template is a
     * fixed, publicly documented placeholder, so a real second factor built
     * on it would be no factor at all.
     */
    public function trialTemplateMode()
    {
        if (!config('services.twilio.trial_template')) {
            return false;
        }

        if (app()->environment('production')) {
            Log::error('TWILIO_TRIAL_TEMPLATE is enabled in production and has been ignored. '
                . "Twilio's trial 2FA template sends a fixed placeholder code, which is not a "
                . 'usable second factor. Upgrade the Twilio account instead.');

            return false;
        }

        return true;
    }

    /**
     * Send one of Twilio's predefined templates by name.
     *
     * The returned resource carries the body Twilio actually composed, which
     * is the only way to know what the recipient will read.
     *
     * @throws \RuntimeException when the message is not accepted by Twilio.
     * @return array Twilio's message resource.
     */
    public function sendTemplateSms($to, $template)
    {
        if (!$this->canSendSms()) {
            throw new \RuntimeException(
                'Twilio is not configured for SMS. Set TWILIO_SID, TWILIO_AUTH_TOKEN '
                . 'and an SMS-capable TWILIO_SMS_FROM (or TWILIO_PHONE).'
            );
        }

        $to = static::toE164($to);

        if (!$to) {
            throw new \RuntimeException('No usable mobile number to send to.');
        }

        return $this->post([
            'From' => $this->smsFrom,
            'To' => $to,
            'Body' => $template,
        ], 'sms-template');
    }

    /**
     * Pull the verification code out of a message body Twilio composed.
     *
     * Deliberately reads it from the response rather than assuming the
     * placeholder stays what it is today — if Twilio changes the template,
     * this keeps working instead of silently sending codes that never match.
     *
     * @return string|null
     */
    public static function extractCode($body)
    {
        if (!preg_match('/\b(\d{4,8})\b/', (string)$body, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * Send a WhatsApp message.
     *
     * WhatsApp will not let a business start a conversation with free-form
     * text. Outside the 24-hour window that opens when the recipient messages
     * you, Twilio answers 21654 "ContentSid Required": the message has to be
     * an approved Content Template, named by its SID, with its placeholders
     * supplied separately.
     *
     * So: when TWILIO_WHATSAPP_CONTENT_SID is set and the caller supplies
     * variables, this sends the template. Otherwise it falls back to the
     * free-form body, which is still correct inside a session window and is
     * what the Twilio sandbox accepts once a recipient has joined.
     *
     * @param  string $to
     * @param  string $message   free-form text, used when no template applies
     * @param  array  $variables template placeholders, in order from 1
     *
     * @throws \RuntimeException when the message is not accepted by Twilio.
     * @return array Twilio's message resource.
     */
    public function sendWhatsApp($to, $message, array $variables = [])
    {
        if (!$this->canSendWhatsApp()) {
            throw new \RuntimeException(
                'Twilio is not configured. Set TWILIO_SID, TWILIO_AUTH_TOKEN and TWILIO_PHONE.'
            );
        }

        $to = static::toE164($to);

        if (!$to) {
            throw new \RuntimeException('No usable mobile number to send to.');
        }

        $payload = [
            'From' => 'whatsapp:' . $this->from,
            'To' => 'whatsapp:' . $to,
        ];

        $contentSid = config('services.twilio.whatsapp_content_sid');

        if ($contentSid && $variables) {
            $payload['ContentSid'] = $contentSid;
            $payload['ContentVariables'] = static::contentVariables($variables);

            $response = $this->post($payload, 'whatsapp-template');

            $this->assertOurTemplate($response, $variables, $contentSid);

            return $response;
        }

        $payload['Body'] = static::trimBody($message);

        return $this->post($payload, 'whatsapp');
    }


    /**
     * Check that the template Twilio just sent was OUR template.
     *
     * A ContentSid that names one of Twilio's demo templates -- every account
     * ships with the appointment-reminder sample -- is accepted, composed
     * from ITS OWN variables, delivered, and reported as a success. Ours are
     * ignored. The supplier then receives "Reminder: Appt Tue Oct 29, 3:00
     * PM" with no order and no link, while the 2xx writes a notification row
     * and the dedupe marks them as told, so they are never told again.
     *
     * This is the WhatsApp version of the trap sendTemplateSms() documents
     * for the 2FA template, and the same rule settles it: failing the send is
     * correct, delivering the wrong message is not.
     *
     * Twilio echoes the composed body, so the check is simply whether any of
     * our values reached it. One is enough -- a template may carry the link
     * in a URL button rather than in the text -- and a template carrying none
     * of them is not describing this order to anybody.
     *
     * @throws \RuntimeException when the delivered text is not ours.
     */
    protected function assertOurTemplate(array $response, array $variables, $contentSid)
    {
        $body = isset($response['body']) ? (string)$response['body'] : '';

        foreach ($variables as $value) {
            $value = static::templateValue($value);

            /*
             * Only values distinctive enough to mean something. The item
             * count is a digit or two, and a digit turns up in almost any
             * text -- the "2" in an item count matched the "2" of "Oct 29"
             * in Twilio's sample reminder and let it through. An order
             * number, an item list and a link are all far longer than this.
             */
            if (mb_strlen($value) < self::MIN_TEMPLATE_EVIDENCE) {
                continue;
            }

            if (mb_strpos($body, $value) !== false) {
                return;
            }
        }

        Log::error('WhatsApp template ignored the values it was given', [
            'content_sid' => $contentSid,
            'delivered' => mb_substr($body, 0, 200),
            'sid' => isset($response['sid']) ? $response['sid'] : null,
        ]);

        throw new \RuntimeException(
            'TWILIO_WHATSAPP_CONTENT_SID (' . $contentSid . ') is not the procurement template: '
            . 'Twilio delivered "' . mb_substr($body, 0, 80) . '..." instead of this order. '
            . 'It is most likely one of Twilio\'s sample templates. Build a template whose body '
            . 'uses {{1}} order number, {{2}} item count, {{3}} items, {{4}} bid link, and use its SID.',
            21656
        );
    }

    /**
     * Placeholder values, encoded the way the Content API expects: a JSON
     * object keyed "1", "2", "3"... in the order the template declares them.
     *
     * WhatsApp rejects a placeholder that is empty, or that contains a
     * newline, a tab, or a run of more than four spaces -- so a value is
     * flattened to a single line here rather than at each call site. A
     * message built for free-form text is full of newlines, and passing one
     * through unflattened fails with an error about the parameter format
     * rather than about the newline.
     */
    public static function contentVariables(array $variables)
    {
        $encoded = [];
        $position = 1;

        foreach ($variables as $value) {
            $encoded[(string)$position] = static::templateValue($value);
            $position++;
        }

        return json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** One placeholder value, flattened to something WhatsApp will accept. */
    public static function templateValue($value)
    {
        $value = preg_replace('/\s+/u', ' ', (string)$value);
        $value = trim($value);

        // An empty placeholder is refused outright, so give it something.
        if ($value === '') {
            return '-';
        }

        return mb_strlen($value) > 1024 ? mb_substr($value, 0, 1021) . '...' : $value;
    }


    protected function post(array $payload, $channel)
    {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $this->sid . '/Messages.json';

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->sid, $this->token)
                ->timeout(15)
                ->post($url, $payload);

        } catch (\Throwable $e) {
            // DNS failure, TLS problem, timeout — never reached Twilio.
            Log::error('Twilio request failed', [
                'channel' => $channel,
                'to' => static::maskNumber($payload['To']),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Could not reach the SMS provider.', 0, $e);
        }

        $body = $response->json();

        if (!$response->successful()) {
            /*
             * Twilio returns its own numeric code and a docs link, and those
             * are the only things that make a delivery failure diagnosable
             * (21606 = the From number cannot send SMS, 21211 = malformed To,
             * 21608 = unverified number on a trial account, 20003 = auth).
             * Log them; they used to be swallowed entirely.
             */
            $code = isset($body['code']) ? $body['code'] : $response->status();
            $detail = isset($body['message']) ? $body['message'] : 'Unknown error';

            Log::error('Twilio rejected the message', [
                'channel' => $channel,
                'to' => static::maskNumber($payload['To']),
                'from' => $payload['From'],
                'status' => $response->status(),
                'twilio_code' => $code,
                'twilio_message' => $detail,
                'more_info' => isset($body['more_info']) ? $body['more_info'] : null,
            ]);

            /*
             * The numeric code is carried on the exception so a caller can
             * turn it into an instruction rather than re-parsing the string.
             */
            throw new \RuntimeException(
                'Twilio ' . $code . ': ' . $detail,
                is_numeric($code) ? (int)$code : 0
            );
        }

        Log::info('Twilio message queued', [
            'channel' => $channel,
            'to' => static::maskNumber($payload['To']),
            'sid' => isset($body['sid']) ? $body['sid'] : null,
            'status' => isset($body['status']) ? $body['status'] : null,
        ]);

        return $body;
    }

    /**
     * Normalise a stored number to the E.164 form Twilio requires.
     *
     * Numbers get typed into the account screen however the customer writes
     * them — "087 452 3344", "(087) 452-3344", "0035387...". Twilio rejects
     * anything that is not +<country><subscriber>, so a stored local number
     * silently fails to send.
     *
     * A leading 0 is a national trunk prefix and is replaced by the default
     * dialling code, which SMS_DEFAULT_DIAL_CODE sets per install.
     */
    public static function toE164($number)
    {
        $number = trim((string)$number);

        if ($number === '') {
            return null;
        }

        // Strip everything a human might type around the digits.
        $digits = preg_replace('/[^0-9+]/', '', $number);

        // "00" is the international access prefix in most of the world.
        if (strpos($digits, '00') === 0) {
            $digits = '+' . substr($digits, 2);
        }

        if (strpos($digits, '+') === 0) {
            $digits = '+' . preg_replace('/[^0-9]/', '', substr($digits, 1));

            return strlen($digits) >= 8 ? $digits : null;
        }

        $default = trim((string)config('services.twilio.default_dial_code'));
        $default = $default === ''
            ? null
            : '+' . ltrim(preg_replace('/[^0-9]/', '', $default), '0');

        if (strpos($digits, '0') === 0) {
            // A leading zero is the national trunk prefix. Without a default
            // dialling code there is no way to know which country it is.
            if (!$default) {
                return null;
            }

            $digits = ltrim($digits, '0');

            return $digits === '' ? null : static::atLeast8($default . $digits);
        }

        /*
         * Bare digits with no prefix are ambiguous. Country codes are one to
         * three digits and national numbers run to about ten, so anything
         * from eleven digits up already carries its country code — prepending
         * the default to those is how "+91" + "9876274711" would turn into
         * +91919876274711. Shorter than that, treat it as national.
         */
        if (strlen($digits) >= 11 || !$default) {
            return static::atLeast8('+' . $digits);
        }

        return static::atLeast8($default . $digits);
    }

    /**
     * Turn a Twilio error code into the thing that has to change.
     *
     * Twilio's own wording describes the symptom ("Mismatch between the
     * 'From' number and the account"), not the action, and the code is the
     * only reliable part to switch on. Everything here maps to a concrete
     * setting or account step so a failure is diagnosable without opening
     * Twilio's docs.
     *
     * @param  int|string $code
     * @return string|null
     */
    public static function explain($code)
    {
        $fixes = [
            20003 => 'Twilio rejected the request as unauthorised. Either the credentials are wrong '
                . '(check TWILIO_SID and TWILIO_AUTH_TOKEN), or the account is on the free trial and '
                . 'the feature is not included in it -- the Content Template API is one of those.',
            20404 => 'The Twilio account in TWILIO_SID does not exist, or the credentials belong to a different account.',
            21211 => "The recipient's number is not valid E.164. Check the number on the account, and set SMS_DEFAULT_DIAL_CODE if it is stored in local format.",
            21212 => 'The sender number is not valid. TWILIO_SMS_FROM must be in +<country><number> form.',
            21408 => 'This Twilio account is not permitted to send to that country. Enable the destination under Messaging → Geo permissions.',
            21606 => 'TWILIO_SMS_FROM is not an SMS-capable Twilio number. Buy an SMS-capable number on this account and use that.',
            21608 => 'Trial accounts can only text numbers verified in the Twilio console. Verify the recipient, or upgrade the account.',
            21610 => 'That recipient replied STOP and is unsubscribed. They have to text START before you can message them again.',
            21614 => 'That number cannot receive SMS — it is a landline or otherwise not mobile.',
            21655 => 'TWILIO_WHATSAPP_CONTENT_SID does not name a template Twilio can use. Check it is '
                . 'copied from Content Template Builder on THIS account (' . config('services.twilio.sid') . '), '
                . 'and that its WhatsApp approval has come through -- a template that is still pending is '
                . 'not yet usable for a business-initiated message.',
            21659 => 'TWILIO_SMS_FROM is not a valid sender on this account. Use an SMS-capable number owned by the account.',
            21654 => 'WhatsApp will not let a business start a conversation with free-form text. '
                . 'Create an approved Content Template in the Twilio console and put its SID in '
                . 'TWILIO_WHATSAPP_CONTENT_SID. The template needs four placeholders: order number, '
                . 'item count, items, bid link.',
            63007 => 'Twilio has no WhatsApp channel for TWILIO_PHONE. Register the number as a WhatsApp sender, '
                . 'or use the sandbox number and have the recipient join the sandbox first.',
            63016 => 'Free-form WhatsApp is only allowed for 24 hours after the recipient messages you. '
                . 'Set TWILIO_WHATSAPP_CONTENT_SID to send an approved template instead.',
            21660 => 'TWILIO_SMS_FROM is not owned by this Twilio account. The WhatsApp sandbox number (+14155238886) cannot send SMS — buy an SMS-capable number and set TWILIO_SMS_FROM to it.',
            21661 => 'TWILIO_SMS_FROM is not usable for messaging on this account.',
            572003 => 'This Twilio account is on the free trial, which can only message numbers verified '
                . 'on it. Add the recipient under Phone Numbers -> Verified Caller IDs (you need the '
                . 'handset to enter the code), and for WhatsApp have that number text the join code to '
                . 'the sandbox sender as well. Upgrading the account lifts both restrictions.',
            30007 => 'The carrier filtered the message as spam. Register the sender for A2P messaging.',
            63038 => 'The daily message limit for this Twilio account has been reached.',
            572006 => 'This Twilio account is still on the free trial, which cannot send custom message text — '
                . 'only Twilio\'s canned demo templates, whose verification code is a fixed placeholder. '
                . 'Upgrade the Twilio account to send real codes.',
        ];

        $code = (int)$code;

        return isset($fixes[$code]) ? $fixes[$code] : null;
    }

    /** Shorter than this is a typo, not a phone number. */
    protected static function atLeast8($e164)
    {
        return strlen($e164) >= 8 ? $e164 : null;
    }

    protected static function trimBody($message)
    {
        $message = trim((string)$message);

        return mb_strlen($message) > self::MAX_BODY
            ? mb_substr($message, 0, self::MAX_BODY)
            : $message;
    }

    /** Never write a full customer number into the log. */
    protected static function maskNumber($number)
    {
        $number = (string)$number;

        return mb_strlen($number) > 4
            ? str_repeat('•', mb_strlen($number) - 4) . mb_substr($number, -4)
            : $number;
    }
}
