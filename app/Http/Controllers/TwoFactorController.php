<?php

namespace App\Http\Controllers;

use App\Models\Helper\Response;
use App\Models\Helper\TotpHelper;
use App\Models\Helper\Validation;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Two-factor authentication: setting it up from the account, and the
 * challenge that stands between a correct password and a session.
 */
class TwoFactorController extends Controller
{
    /**
     * The provider failure behind the last unsuccessful SMS attempt, so the
     * response can name the real cause instead of only "we could not send".
     *
     * @var \Throwable|null
     */
    protected $lastSmsError = null;

    /**
     * Step one of setup: mint a secret and hand back the provisioning URI.
     * Nothing is switched on until confirm() sees a working code.
     */
    public function setup(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            $secret = TotpHelper::generateSecret();

            // Held encrypted and unconfirmed until a code proves it works.
            $user->update([
                'two_factor_secret' => Crypt::encryptString($secret),
                'two_factor_confirmed_at' => null,
                'two_factor_method' => null
            ]);

            $issuer = config('app.name') ?: 'FixMyPC Shop';

            return response()->json(new Response($request->token, [
                'secret' => $secret,
                'secret_display' => trim(chunk_split($secret, 4, ' ')),
                'uri' => TotpHelper::provisioningUri($secret, $user->email, $issuer)
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Step two: a correct code switches 2FA on and returns the recovery codes,
     * which are shown once and stored hashed.
     */
    public function confirm(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            if (!$user->two_factor_secret) {
                return response()->json(Validation::error($request->token,
                    'Start the setup again — no key is waiting to be confirmed.'
                ));
            }

            $secret = Crypt::decryptString($user->two_factor_secret);

            if (!TotpHelper::verify($secret, $request->input('code'))) {
                return response()->json(Validation::error($request->token,
                    "That code isn't right. Check your app and try again."
                ));
            }

            $codes = TotpHelper::generateRecoveryCodes();

            $user->update([
                'two_factor_method' => 'app',
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(
                    array_map(function ($code) {
                        return Hash::make($code);
                    }, $codes)
                ))
            ]);

            return response()->json(new Response($request->token, [
                'method' => 'app',
                'recovery_codes' => $codes
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * SMS as the second factor instead of an app. Reuses the login_codes table,
     * which already handles expiry, attempts and single use.
     */
    public function sendSms(Request $request, TwilioService $twilio)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            if (!$user->phone) {
                return response()->json(Validation::error($request->token,
                    'Add a mobile number to your account first.'
                ));
            }

            /*
             * Refuse before spending a message when the provider cannot carry
             * our own code — a trial account substitutes a canned body with a
             * fixed placeholder code, so the customer would get a text that
             * looks right and can never be accepted.
             */
            if ($reason = $twilio->smsUnavailableReason()) {
                \Illuminate\Support\Facades\Log::warning('2FA SMS unavailable', ['reason' => $reason]);

                return response()->json(Validation::error($request->token,
                    config('app.debug')
                        ? 'Text messages are not available right now. Use an authenticator app instead. [debug] ' . $reason
                        : 'Text messages are not available right now. Use an authenticator app instead.'
                ));
            }

            $code = $this->issueSmsCode($user, $request, $twilio);

            if ($code === false) {
                return response()->json(Validation::error($request->token,
                    $this->smsFailureMessage()
                ));
            }

            return response()->json(new Response($request->token, [
                'phone_hint' => $this->maskPhone($user->phone)
            ]));

        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::error('2FA SMS request failed', [
                'user_id' => Auth::id(),
                'reason' => $ex->getMessage(),
            ]);

            $this->lastSmsError = $ex;

            return response()->json(Validation::error($request->token,
                $this->smsFailureMessage()
            ));
        }
    }

    /**
     * What to put on screen when the text does not go out.
     *
     * A customer gets copy that tells them what to do instead. A developer
     * gets the provider's actual code and the setting that has to change,
     * because "We could not send that text" is unactionable when the cause
     * is a misconfigured sender number.
     *
     * Gated on APP_DEBUG: the provider's message quotes the Twilio account
     * SID and the sender number, neither of which belongs in a response to
     * the public site.
     */
    protected function smsFailureMessage()
    {
        $friendly = 'We could not send that text. Try an authenticator app instead.';

        if (!config('app.debug') || !$this->lastSmsError) {
            return $friendly;
        }

        $reason = $this->lastSmsError->getMessage();
        $fix = TwilioService::explain($this->lastSmsError->getCode());

        return $friendly . ' [debug] ' . $reason . ($fix ? ' → ' . $fix : '');
    }

    public function confirmSms(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            if (!$this->consumeSmsCode($user->email, $request->input('code'))) {
                return response()->json(Validation::error($request->token,
                    "That code isn't right. Try again or send a new one."
                ));
            }

            $codes = TotpHelper::generateRecoveryCodes();

            $user->update([
                'two_factor_method' => 'sms',
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => now(),
                /*
                 * Typing back a code that was texted to the number is exactly
                 * what proving the number means, so there is no reason to ask
                 * again separately.
                 */
                'phone_verified_at' => now(),
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(
                    array_map(function ($code) {
                        return Hash::make($code);
                    }, $codes)
                ))
            ]);

            return response()->json(new Response($request->token, [
                'method' => 'sms',
                'recovery_codes' => $codes
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Text a code to prove the mobile number on the account.
     *
     * Separate from the 2FA flow so a customer can confirm their number
     * without turning on a second factor — and so the number is already
     * proved if they turn one on later.
     */
    public function sendPhoneCode(Request $request, TwilioService $twilio)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            if (!$user->phone) {
                return response()->json(Validation::error($request->token,
                    'Add a mobile number to your account first.'
                ));
            }

            if ($user->phoneVerified()) {
                return response()->json(new Response($request->token, [
                    'already_verified' => true,
                    'phone_hint' => $this->maskPhone($user->phone)
                ]));
            }

            if ($reason = $twilio->smsUnavailableReason()) {
                \Illuminate\Support\Facades\Log::warning('Phone verification SMS unavailable', ['reason' => $reason]);

                return response()->json(Validation::error($request->token,
                    config('app.debug')
                        ? 'Text messages are not available right now. [debug] ' . $reason
                        : 'Text messages are not available right now. Try again later.'
                ));
            }

            $sent = $this->issueSmsCode($user, $request, $twilio, LoginCode::PURPOSE_PHONE_VERIFY);

            if ($sent === false) {
                return response()->json(Validation::error($request->token, $this->smsFailureMessage()));
            }

            return response()->json(new Response($request->token, [
                'phone_hint' => $this->maskPhone($user->phone)
            ]));

        } catch (\Throwable $ex) {
            $this->lastSmsError = $ex;

            return response()->json(Validation::error($request->token, $this->smsFailureMessage()));
        }
    }

    public function verifyPhone(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            if (!$this->consumeSmsCode($user->email, $request->input('code'), LoginCode::PURPOSE_PHONE_VERIFY)) {
                return response()->json(Validation::error($request->token,
                    "That code isn't right. Try again or send a new one."
                ));
            }

            $user->update(['phone_verified_at' => now()]);

            return response()->json(new Response($request->token, [
                'phone_verified' => true
            ]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }


    public function disable(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(Validation::unauthorized());
            }

            // Turning it off is a password-protected action.
            if (!Hash::check((string)$request->input('password'), $user->password)) {
                return response()->json(Validation::error($request->token,
                    'That password is not right.'
                ));
            }

            $user->update([
                'two_factor_method' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null
            ]);

            return response()->json(new Response($request->token, ['two_factor' => false]));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * The status the account screen shows.
     */
    public function status(Request $request, TwilioService $twilio)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(Validation::unauthorized());
        }

        /*
         * Reflects whether SMS is configured at all, not whether the provider
         * account is fully upgraded — a trial account still gets the option,
         * and a failed send reports the provider's reason.
         */
        $smsReason = $twilio->smsUnavailableReason();

        $payload = [
            'enabled' => $user->twoFactorEnabled(),
            'method' => $user->two_factor_method,
            'phone_hint' => $user->phone ? $this->maskPhone($user->phone) : null,
            'sms_available' => $smsReason === null,
            'has_phone' => !empty($user->phone),
            'phone_verified' => $user->phoneVerified(),
        ];

        if (config('app.debug')) {
            if ($smsReason) {
                $payload['sms_unavailable_reason'] = $smsReason;
            }

            // Informational only. A trial account cannot send custom text,
            // which is worth knowing when a send comes back as 572006.
            $payload['twilio_account_type'] = $twilio->accountType();
        }

        return response()->json(new Response($request->token, $payload));
    }

    /* ── helpers shared with the sign-in challenge ── */

    /**
     * Text a fresh code, invalidating any earlier one. Returns false if the
     * message could not be sent.
     */
    public function issueSmsCode(User $user, Request $request, TwilioService $twilio,
                                $purpose = LoginCode::PURPOSE_TWO_FACTOR)
    {
        $code = (string)random_int(100000, 999999);

        /*
         * Retire only this flow's earlier codes. Unscoped, this burned every
         * unused code on the address — a half-finished passwordless sign-in
         * or guest checkout was cancelled by someone asking for a 2FA text.
         */
        LoginCode::where('email', $user->email)
            ->forPurpose($purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        /*
         * Throwable, not Exception. sendSMS() did not exist on TwilioService,
         * and "Call to undefined method" is an Error — so it went straight
         * past this catch and out of the controller as a 500, which is why
         * the screen showed the browser's fallback copy rather than ours.
         * A messaging failure of any kind belongs in the log and returns
         * false so the caller can offer the authenticator app instead.
         */
        try {
            if ($twilio->trialTemplateMode()) {
                /*
                 * Trial accounts reject custom text (572006) and only accept
                 * the name of a canned Twilio template. Twilio then writes
                 * the body itself and puts its own code in it, so the code
                 * we generated above is never delivered. Read back what was
                 * actually sent and store that instead — otherwise the
                 * customer types a code this application has never seen and
                 * every attempt fails with nothing to explain why.
                 *
                 * That code is a fixed placeholder, so this is a development
                 * aid only; trialTemplateMode() refuses to engage in
                 * production.
                 */
                $result = $twilio->sendTemplateSms($user->phone, TwilioService::TRIAL_2FA_TEMPLATE);

                $delivered = TwilioService::extractCode(isset($result['body']) ? $result['body'] : '');

                if (!$delivered) {
                    throw new \RuntimeException(
                        'Twilio accepted the template but its message carried no readable code: '
                        . (isset($result['body']) ? $result['body'] : '(empty body)')
                    );
                }

                $code = $delivered;

                \Illuminate\Support\Facades\Log::warning(
                    '2FA SMS sent using a Twilio trial template. The code is a fixed placeholder '
                    . 'and provides no real security — for development only.',
                    ['user_id' => $user->id]
                );

            } else {
                $store = \App\Models\Helper\MailBrand::make()->store_short;

                $twilio->sendSMS(
                    $user->phone,
                    "Your $store verification code is $code. It expires in "
                    . LoginCode::LIFETIME_MINUTES . ' minutes. Do not share it.'
                );
            }

        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('2FA SMS send failed', [
                'user_id' => $user->id,
                'reason' => $ex->getMessage(),
            ]);

            // Held so sendSms() can report the real cause while debugging.
            $this->lastSmsError = $ex;

            return false;
        }

        LoginCode::create([
            'email' => $user->email,
            'purpose' => $purpose,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(LoginCode::LIFETIME_MINUTES),
            'ip_address' => $request->ip()
        ]);

        return true;
    }

    public function consumeSmsCode($email, $code, $purpose = LoginCode::PURPOSE_TWO_FACTOR)
    {
        $code = trim((string)$code);

        if ($code === '') {
            return false;
        }

        /*
         * Scoped to this purpose: without it the newest usable code of any
         * kind on the address was accepted, so a code emailed by the
         * passwordless sign-in could stand in for the texted second factor.
         */
        $loginCode = LoginCode::where('email', $email)
            ->forPurpose($purpose)
            ->usable()
            ->latest('id')
            ->first();

        if (!$loginCode) {
            return false;
        }

        if (!Hash::check($code, $loginCode->code)) {
            $loginCode->increment('attempts');
            return false;
        }

        $loginCode->update(['used_at' => now()]);

        return true;
    }

    /**
     * Check a code from any enabled method, or a recovery code. Recovery codes
     * are single use, so a match is burned before returning.
     */
    public static function passesChallenge(User $user, $code)
    {
        $code = trim((string)$code);

        if ($code === '') {
            return false;
        }

        if ($user->two_factor_method === 'app' && $user->two_factor_secret) {

            if (TotpHelper::verify(Crypt::decryptString($user->two_factor_secret), $code)) {
                return true;
            }
        }

        return self::consumeRecoveryCode($user, $code);
    }

    public static function consumeRecoveryCode(User $user, $code)
    {
        if (!$user->two_factor_recovery_codes) {
            return false;
        }

        $hashes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];

        foreach ($hashes as $index => $hash) {

            if (Hash::check(strtoupper($code), $hash) || Hash::check($code, $hash)) {

                unset($hashes[$index]);

                $user->update([
                    'two_factor_recovery_codes' => Crypt::encryptString(
                        json_encode(array_values($hashes))
                    )
                ]);

                return true;
            }
        }

        return false;
    }

    private function maskPhone($phone)
    {
        $digits = preg_replace('/\D/', '', (string)$phone);

        return strlen($digits) > 3
            ? str_repeat('•', max(strlen($digits) - 3, 0)) . substr($digits, -3)
            : $phone;
    }
}
