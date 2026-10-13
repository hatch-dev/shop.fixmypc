<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
	
	'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('BACKEND_SOCIAL_REDIRECT', \App\Models\Helper\Utils::backendSocialRedirect()) . '/facebook',
    ],
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('BACKEND_SOCIAL_REDIRECT', \App\Models\Helper\Utils::backendSocialRedirect()) . '/google',
    ],
    'sendinblue' => [        
            'v3'    => [
                'key'   => env('BREVO_API_KEY')                   
            ]
    ],
    /*
     * Procurement supplier links.
     *
     * The WhatsApp we send a supplier points at the STOREFRONT route
     * /supplier/bid/{token}, which is a Nuxt page, while app.url names the
     * API. They are one host on this install, so app.url is the default and
     * nothing has to be set -- but a split deploy would otherwise mail out
     * links into the API host, where that route does not exist.
     */
    'procurement' => [
        'link_base' => env('PROCUREMENT_LINK_BASE'),

        /*
         * How a supplier is asked to quote: email, whatsapp, or both.
         *
         * WhatsApp will not carry a business-initiated message without an
         * APPROVED Content Template, so this defaults to email -- which needs
         * no approval, no Twilio, and reaches an address the supplier record
         * already requires. Switch to whatsapp, or both, once the template in
         * TWILIO_WHATSAPP_CONTENT_SID has cleared Meta review.
         *
         * With both, either channel succeeding counts as the supplier having
         * been reached.
         */
        'channel' => env('PROCUREMENT_CHANNEL', 'email'),
    ],

    'twilio' => [
        'sid'   => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),

        /*
         * The approved WhatsApp Content Template to send business-initiated
         * messages with.
         *
         * WhatsApp does not allow a business to open a conversation with
         * free-form text: outside the 24-hour window that starts when the
         * recipient messages you, Twilio answers 21654 "ContentSid
         * Required". Unset, procurement still sends free-form, which is
         * correct inside a session window and in the sandbox.
         *
         * The template this names must declare four placeholders, in order:
         *   {{1}} order number   {{2}} item count
         *   {{3}} the items       {{4}} the bid link
         */
        'whatsapp_content_sid' => env('TWILIO_WHATSAPP_CONTENT_SID'),

        // WhatsApp sender.
        'from'  => env('TWILIO_PHONE'),

        // SMS sender. Must be an SMS-capable number bought on the Twilio
        // account — the shared WhatsApp sandbox number cannot send SMS.
        // Falls back to TWILIO_PHONE when unset.
        'sms_from' => env('TWILIO_SMS_FROM'),

        // Dialling code applied to numbers stored in local format, e.g.
        // "+353" turns 087 452 3344 into +353874523344. Without it a stored
        // local number cannot be sent to at all.
        'default_dial_code' => env('SMS_DEFAULT_DIAL_CODE'),

        /*
         * Send Twilio's canned "sms_2fa" template instead of our own text,
         * so SMS two-factor can be exercised on a free trial account, which
         * rejects custom bodies with error 572006.
         *
         * Twilio composes that message and puts its own placeholder code in
         * it — a fixed, publicly documented value — so it is a development
         * aid, not a security control. Ignored when APP_ENV=production.
         */
        'trial_template' => env('TWILIO_TRIAL_TEMPLATE', false),
    ],
];
