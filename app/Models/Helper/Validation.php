<?php

namespace App\Models\Helper;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Validation
{
    /** Upper bound on a single cart/order line, so a typo cannot drain stock. */
    const MAX_LINE_QUANTITY = 999;

    /**
     * Saving a product's stock rows.
     *
     * 'inventories' => 'required' was the whole of it, and the controller adds
     * no checks of its own, so whatever the client sent was written: a variant
     * with quantity -99 and price -50.00 saved without complaint, and so did a
     * row with no SKU. Negative stock is not merely untidy - the storefront
     * reads quantity < 1 as out of stock, so the row vanishes from sale while
     * still counting against the product, and a negative price flows straight
     * into the cart.
     *
     * These are the rules the admin form already enforces before it submits;
     * the server simply did not repeat them.
     */
    public static function updatedInventory($request, $errorType="form"){
        $rules = [
            /*
             * `min:1` meant the last variant of a product could never be
             * removed: the form posts the whole list on every save, so
             * deleting the only remaining row submitted an empty array and was
             * refused -- inside the body, with HTTP 200, so the screen showed a
             * generic stock error and the row stayed put. An empty list is a
             * legitimate thing to say: this product has no variants.
             */
            'inventories' => 'present|array',
            'inventories.*.sku' => 'required|string|max:255|distinct',
            'inventories.*.quantity' => 'required|integer|min:0',
            'inventories.*.price' => 'required|numeric|min:0',
            'inventories.*.is_active' => 'nullable|boolean',
            'inventories.*.imei' => 'nullable|string|max:255',
            'inventories.*.barcode' => 'nullable|string|max:255',
        ];



        return self::validationMessage($request, $rules, $errorType);
    }



    public static function imageAttribute($request, $errorType="images_attribute"){
        $rules = [
            'product_image_id' => 'required'
        ];

        return self::validationMessage($request, $rules, $errorType);
    }

    public static function cancelled($request){
        $rules = [
            'order_id' => 'required',
            'title' => 'required',
            'message' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }

    public static function user_wishlist($request){
        $rules = [
            'product_id' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }

    /**
     * Editing an account from Account -> Profile.
     *
     * Only the fields the customer is allowed to change are described here.
     * Account type, trade status, credit approval and verification state are
     * decided by staff and are not accepted from this request at all — see
     * UsersController::updateProfile, which builds its update from a
     * whitelist rather than from the payload.
     *
     * Every rule past 'name' is conditional on the field being present. The
     * profile form posts a partial payload (the marketing panel sends only
     * the preferences, for instance), and requiring a field that was never
     * on screen would fail a save the customer had no way to satisfy.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\User $user The account being edited, for the
     *        uniqueness checks and to know which blocks apply.
     */
    public static function userProfile($request, $user = null)
    {
        $rules = [
            'name' => 'required',
        ];

        /*
         * One account per number, matching the rule registration applies.
         * Scoped to verified rows and excluding this account, so re-saving
         * your own profile does not report your own number as taken.
         */
        if ($request->has('phone')) {
            $rules['phone'] = [
                'required',
                Rule::unique('users', 'phone')
                    ->ignore($user ? $user->id : null)
                    ->where(function ($query) {
                        $query->where('verified', 1);
                    })
            ];
        }

        foreach (['pref_email_offers', 'pref_sms_offers',
                  'pref_recommendations', 'pref_trade_list'] as $preference) {

            if ($request->has($preference)) {
                $rules[$preference] = 'boolean';
            }
        }

        $type = $user ? $user->account_type : null;

        // Company details only exist on a business or trade account.
        if (in_array($type, ['business', 'trade'], true)) {

            if ($request->has('company_name')) {
                $rules['company_name'] = 'required';
            }

            if ($request->has('billing_address')) {
                $rules['billing_address'] = 'required';
            }

            // Same shape checks registration applies, so the two entry
            // points cannot disagree about what a VAT number looks like.
            if (self::filled($request, 'vat_number')) {
                $rules['vat_number'] = [function ($attribute, $value, $fail) {
                    if (!self::looksLikeVatNumber($value)) {
                        $fail('That does not look like a VAT number. '
                            . 'Enter it as it appears on your VAT certificate.');
                    }
                }];
            }

            if (self::filled($request, 'cro_number')) {
                $rules['cro_number'] = [function ($attribute, $value, $fail) {
                    if (!self::looksLikeCompanyNumber($value)) {
                        $fail('A company registration number is digits only.');
                    }
                }];
            }

            if (self::filled($request, 'website')) {
                $rules['website'] = ['regex:/^(https?:\/\/)?[^\s.]+\.[^\s]{2,}$/i'];
            }

            /*
             * One VAT number, one account — the same rule registration
             * enforces, or a company's number could be taken over by editing
             * it in afterwards rather than registering with it.
             *
             * Scoped to verified accounts and excluding this one, exactly as
             * the registration rule is: an abandoned unverified signup is not
             * an account yet and must not block a real company from using its
             * own number.
             */
            if (self::filled($request, 'vat_number')) {
                $rules['vat_number'][] = Rule::unique('user_business_profiles', 'vat_number')
                    ->where(function ($query) use ($user) {
                        $query->whereIn('user_id', function ($sub) use ($user) {
                            $sub->select('id')->from('users')
                                ->where('verified', 1)
                                ->where('id', '!=', $user ? $user->id : 0);
                        });
                    });
            }
        }

        return self::validationMessage($request, $rules);
    }

    public static function transId($request){
        $rules = [
            'id' => 'required',
            'trans_id' => 'required|string|max:100'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function orderStatus($request){
        $rules = [
            'id' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    /**
     * QA audit ORD-03. updateStatus() only ever required 'id', so 'status'
     * could be any integer at all — including values with no meaning in
     * constants.orderStatus.
     */
    public static function orderStatusChange($request){
        $rules = [
            'id' => 'required|integer|exists:orders,id',
            'status' => 'required|integer|in:' .
                implode(',', array_values(Config::get('constants.orderStatus')))
        ];

        return self::validationMessage($request, $rules);
    }


    public static function orderPaymentStatus($request){
        $rules = [
            'id' => 'required|integer|exists:orders,id',
            'payment_done' => 'required|boolean'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function orderPaymentMethod($request){
        $rules = [
            'id' => 'required|integer|exists:orders,id',
            'order_method' => 'required|integer|in:' .
                implode(',', array_values(Config::get('constants.paymentMethod')))
        ];

        return self::validationMessage($request, $rules);
    }


    public static function ratingReview($request, $lang = null){
        $rules = [
            'rating' => 'required|numeric|min:1|max:5',
            'product_id' => 'required',
            'order_id' => 'required'
        ];

        $messages = [
            'required' =>  __('lang.ra_required', [], $lang),
            'max' => __('lang.ra_max', [], $lang),
            'numeric' =>  __('lang.ra_numeric', [], $lang),
            'min' => __('lang.ra_min', [], $lang)
        ];

        return self::validationMessage($request, $rules, 'form', $messages);
    }


    public static function voucherValidity($request){
        $rules = [
            'voucher' => 'required',
            'price' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function admin_login($request, $lang = null)
    {
        $rules = [
            'email' => 'required|email',
            'password' => 'required|min:6'
        ];


        $messages = [
            'required' =>  __('lang.email_required', [], $lang),
            'email' => __('lang.valid_email', [], $lang),
            'min' => __('lang.pass_min', [], $lang)
        ];


        return self::validationMessage($request, $rules, 'form', $messages);
    }


    public static function page_wysiwyg_image($request)
    {
        $rules = [];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }

    public static function wysiwyg_image($request)
    {
        $rules = [
            'type' => 'required'
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }

    public static function email_verification($request)
    {
        $rules = [
            'email' => 'required|email',
        ];

        return self::validationMessage($request, $rules);
    }

    /**
     * Guest checkout's email confirmation, both sending and checking a code.
     *
     * The token is required because the confirmation is recorded against the
     * guest it belongs to; without it there is nothing to record against.
     */
    public static function guest_checkout_email($request)
    {
        $rules = [
            'user_token' => 'required',
            'email' => 'required|email'
        ];

        $messages = [
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.'
        ];

        return self::validationMessage($request, $rules, 'form', $messages);
    }

    public static function update_password($request)
    {
        $rules = [
            'code' => 'required|min:4',
            'email' => 'required|email',
            'password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }

    /**
     * Setting a new password from a reset link. Same strength rule as signup.
     */
    public static function reset_password($request)
    {
        $rules = [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'min:10', 'regex:/[A-Z]/', 'regex:/\d/']
        ];

        $messages = [
            'password.min' => 'Use at least 10 characters, with a capital letter and a number.',
            'password.regex' => 'Use at least 10 characters, with a capital letter and a number.',
            'token.required' => 'This reset link is not valid.'
        ];

        return self::validationMessage($request, $rules, 'form', $messages);
    }

    public static function updateUserPassword($request)
    {
        $rules = [
            'current_password' => 'required|min:6',
            'new_password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }

    /*
     * The address hierarchy, checked the way the delivery forms present it:
     * country -> region -> town -> postcode. Everything below the country is
     * judged by what the country says, so a county has to belong to the
     * country it came with, and a postcode is only demanded - and only held
     * to a shape - where the country runs one.
     */
    public static function user_address($request)
    {
        $country = strtoupper(trim((string) $request->country));

        $rules = [
            'country' => 'required|min:2|max:2',
            'city' => 'required',
            'address_1' => 'required',
            'email' => 'required',
            'name' => 'required',
            'phone' => 'required'
        ];

        if (AddressRules::needsPostcode($country)) {
            $rules['zip'] = 'required';
        }

        $validate = self::validationMessage($request, $rules);

        if ($validate) {
            return $validate;
        }

        /*
         * Past the shape checks, the parts have to agree with each other.
         * These are reported against their own field so a form can put the
         * message on the control that caused it.
         */
        $fields = [];

        if (!AddressRules::isValidRegion($country, $request->state)) {
            $fields['state'] = [
                'Choose a county or region that belongs to the country selected.'
            ];
        }

        if (AddressRules::needsPostcode($country) && !AddressRules::isValidPostcode($country, $request->zip)) {
            $rule = AddressRules::postcodeRule($country);

            $fields['zip'] = [
                $rule['example']
                    ? "Enter a valid {$rule['label']}, for example {$rule['example']}."
                    : "Enter a valid {$rule['label']}."
            ];
        }

        if ($fields) {
            return new Response($request->token, [
                'form' => Utils::formatErrors($fields),
                'fields' => $fields
            ], 201);
        }

        return false;
    }


    public static function adminVerification($request)
    {
        $rules = [
            'code' => 'required|min:4',
            'email' => 'required|email',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function user_verification($request)
    {
        $rules = [
            'code' => 'required|min:4',
            'email' => 'required|email',
        ];

        return self::validationMessage($request, $rules);
    }



    public static function sellerSignup($request)
    {
        $rules = [
            'name' => 'required',
            'email' => 'required|email',
            'store_name' => 'required',
            'password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function user_signup($request)
    {
        $rules = [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }

    /**
     * Registration through the multi-step signup: the account type decides
     * which extra blocks are required.
     */
    public static function user_registration($request)
    {
        $type = $request->input('account_type');

        $rules = [
            'name' => 'required',
            /*
             * One account per address and per number.
             *
             * Both are scoped to verified rows on purpose: an address that was
             * signed up for but never confirmed is not an account yet, and
             * signup deliberately overwrites that pending row so someone who
             * lost the code can start again. The phone rule skips the row being
             * re-registered, so re-sending your own signup does not report your
             * own number as taken.
             *
             * The same pair is re-checked at verification (UsersController::
             * verification), which is where two pending signups sharing a
             * number would otherwise both become real accounts.
             */
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->where(function ($query) {
                    $query->where('verified', 1);
                })
            ],
            'phone' => [
                'required',
                Rule::unique('users', 'phone')->where(function ($query) use ($request) {
                    $query->where('verified', 1)
                        ->where('email', '!=', (string) $request->input('email'));
                })
            ],
            'account_type' => 'required|in:personal,business,trade',
            // Matches the strength rule shown on the form.
            'password' => ['required', 'min:10', 'regex:/[A-Z]/', 'regex:/\d/'],
            'terms' => 'accepted'
        ];

        $rules = array_merge($rules, self::companyRules($request, $type));

        $messages = array_merge([
            'password.min' => 'Use at least 10 characters, with a capital letter and a number.',
            'password.regex' => 'Use at least 10 characters, with a capital letter and a number.',
            'terms.accepted' => 'Tick the box to continue.',
            'phone.required' => 'Enter a mobile number we can text.',
            'email.unique' => 'That email address already has an account. Sign in instead, or reset your password.',
            'phone.unique' => 'That mobile number is already on another account. Use a different number.'
        ], self::companyMessages());

        return self::validationMessage($request, $rules, 'form', $messages);
    }

    /**
     * The company fields a business or trade account is asked for: which are
     * required for each type, and the shape checks on the identifiers.
     *
     * Shared by registration and the self-serve account upgrade
     * (AccountUpgradesController), so the two forms cannot drift apart.
     *
     * $ownerId is the account the details will belong to, when it already
     * exists: its own VAT number is then not "already registered". At signup
     * there is no id yet, and the row being re-registered is recognised by
     * its email instead.
     */
    public static function companyRules($request, $type, $ownerId = null)
    {
        $rules = [];

        if ($type === 'business') {
            $rules['company_name'] = 'required';
            $rules['sector'] = 'required';
            $rules['billing_address'] = 'required';
        }

        if ($type === 'trade') {
            $rules['company_name'] = 'required';
            $rules['trade'] = 'required';
            $rules['vat_number'] = 'required';
            $rules['billing_address'] = 'required';
            $rules['expected_spend'] = 'required';
            $rules['resale_confirmed'] = 'accepted';
        }

        /*
         * Company identifiers are checked for shape whenever they are supplied,
         * for either account type. They were stored exactly as typed, so "n/a",
         * "will send later" and a phone number all became a VAT number — and
         * the person approving a trade application had no way to tell those
         * from a real one without looking each up by hand.
         *
         * The patterns are deliberately loose. A VAT number can be issued by
         * any member state and a strict per-country table would reject valid
         * numbers the moment a new customer registers abroad; what is worth
         * catching is free text, which these do.
         */
        if (in_array($type, ['business', 'trade'], true)) {

            if (self::filled($request, 'vat_number')) {
                $rules['vat_number'] = array_merge(
                    (array)($rules['vat_number'] ?? []),
                    [function ($attribute, $value, $fail) {
                        if (!self::looksLikeVatNumber($value)) {
                            $fail('That does not look like a VAT number. '
                                . 'Enter it as it appears on your VAT certificate.');
                        }
                    }]
                );
            }

            if (self::filled($request, 'cro_number')) {
                $rules['cro_number'] = [function ($attribute, $value, $fail) {
                    if (!self::looksLikeCompanyNumber($value)) {
                        $fail('A company registration number is digits only.');
                    }
                }];
            }

            if (self::filled($request, 'website')) {
                $rules['website'] = ['regex:/^(https?:\/\/)?[^\s.]+\.[^\s]{2,}$/i'];
            }

            /*
             * One VAT number, one account.
             *
             * Scoped to verified accounts for the same reason the email and
             * phone rules are: an address that was signed up for but never
             * confirmed is not an account yet, and signup deliberately
             * overwrites that row so somebody who lost their code can start
             * again. Without the scope, an abandoned first attempt would lock
             * a company out of registering at all.
             */
            if (self::filled($request, 'vat_number')) {
                $rules['vat_number'][] = Rule::unique('user_business_profiles', 'vat_number')
                    ->where(function ($query) use ($request, $ownerId) {
                        $query->whereIn('user_id', function ($sub) use ($request, $ownerId) {
                            $sub->select('id')->from('users')->where('verified', 1);

                            if ($ownerId) {
                                $sub->where('id', '!=', $ownerId);
                            } else {
                                $sub->where('email', '!=', (string)$request->input('email'));
                            }
                        });
                    });
            }
        }

        return $rules;
    }

    public static function companyMessages()
    {
        return [
            'resale_confirmed.accepted' => 'Trade accounts need this confirmation.',
            'company_name.required' => 'Enter the company name.',
            'billing_address.required' => 'We need a billing address for VAT invoices.',
            'vat_number.required' => 'Enter your VAT number.',
            'vat_number.regex' => 'That does not look like a VAT number. Enter it as it appears on your VAT certificate.',
            'vat_number.unique' => 'That VAT number is already registered to another account. Sign in instead, or contact us.',
            'cro_number.regex' => 'A company registration number is digits only.',
            'website.regex' => 'Enter a valid website address, for example yourcompany.ie.',
            'trade.required' => 'Choose the trade that best describes your work.',
            'sector.required' => 'Choose what the business does.',
            'expected_spend.required' => 'Choose your expected monthly spend.'
        ];
    }

    public static function order($request)
    {
        $rules = [
            'order_method' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }

    public static function admin_signup($request)
    {
        $rules = [
            'username' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function admin_password($request)
    {
        $rules = [
            'password' => 'required|min:6',
            'new_password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function forgotPassword($request)
    {
        $rules = [
            'email' => 'required|email',
        ];

        return self::validationMessage($request, $rules);
    }

    public static function verifyCode($request)
    {
        $rules = [
            'email' => 'required|email',
            'code' => 'required',
            'password' => 'required|min:6'
        ];

        return self::validationMessage($request, $rules);
    }



    public static function payment($request)
    {
        $rules = [
            'cash_on_delivery' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function withdrawal($request)
    {
        $rules = [
            'amount' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function withdrawalApprove($request)
    {
        $rules = [
            'id' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function withdrawalCancel($request)
    {
        $rules = [
            'id' => 'required',
            'message' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function withdrawalAccount($request)
    {
        $rules = [
            'account_number' => 'required',
            'account_name' => 'required',
            'bank_name' => 'required',
            'branch_name' => 'required',
            'title' => 'required',
            'default' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function siteSetting($request)
    {
        $rules = [
            'site_name' => 'required',
            'meta_title' => 'required',
            'meta_description' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }

    public static function store($request)
    {
        $rules = [
            'name' => 'required',
            'slug' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function analytics($request)
    {
        $rules = [
            'enable_ga' => 'required',
            'enable_pixel' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function miscellaneous($request)
    {
        $rules = [
            'attach_pdf' => 'required',
            'send_seller_email' => 'required',
            'cookie_banner' => 'required',
            'vendor_registration' => 'required',
            'guest_checkout' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    /**
     * Basket reservation timings. Both count from the last add, so the
     * reminder has to come before the basket is emptied.
     */
    public static function cartReservation($request)
    {
        $rules = [
            'cart_remind_minutes' => 'required|integer|min:1|lt:cart_expire_minutes',
            'cart_expire_minutes' => 'required|integer|min:2|max:' . CartHold::MAX_EXPIRE_MINUTES
        ];

        $messages = [
            'cart_remind_minutes.required' => 'Enter when the reminder email should be sent.',
            'cart_remind_minutes.integer' => 'The reminder time must be a whole number of minutes.',
            'cart_remind_minutes.min' => 'The reminder must be sent at least 1 minute after the item is added.',
            'cart_remind_minutes.lt' => 'The reminder must be sent before the basket is emptied.',
            'cart_expire_minutes.required' => 'Enter when the basket should be emptied.',
            'cart_expire_minutes.integer' => 'The removal time must be a whole number of minutes.',
            'cart_expire_minutes.min' => 'The basket must be kept for at least 2 minutes.',
            'cart_expire_minutes.max' => 'A basket can be reserved for at most ' . CartHold::MAX_EXPIRE_MINUTES . ' minutes (one day).'
        ];

        return self::validationMessage($request, $rules, 'form', $messages);
    }

    public static function aftership($request)
    {
        $min = AfterShipSettings::MIN_SYNC_MINUTES;
        $max = AfterShipSettings::MAX_SYNC_MINUTES;

        $rules = [
            'api_key' => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'auto_deliver' => 'required|boolean',
            'sync_minutes' => "nullable|integer|min:$min|max:$max",
        ];

        $messages = [
            'api_key.max' => 'That API key is too long. Copy it again from AfterShip > Settings > API keys.',
            'webhook_secret.max' => 'That webhook secret is too long. Copy it again from AfterShip > Settings > Webhooks.',
            'auto_deliver.required' => 'Choose whether a delivered scan marks the order Delivered.',
            'sync_minutes.integer' => 'The check interval must be a whole number of minutes.',
            'sync_minutes.min' => "Check at most every $min minutes: each check uses one AfterShip API call.",
            'sync_minutes.max' => "Check at least once a day ($max minutes).",
        ];

        return self::validationMessage($request, $rules, 'form', $messages);
    }

    public static function address($request)
    {
        $rules = [
            'address_1' => 'required',
            'city' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'country' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function currency($request)
    {
        $rules = [
            'currency' => 'required',
            'currency_icon' => 'required',
            'currency_position' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function page($request)
    {
        $rules = [
            'title' => 'required',
            'slug' => 'required',
            'page_from_component' => 'required',
            'meta_title' => 'required',
            'meta_description' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    /**
     * A flash sale, in shape only.
     *
     * status was 'numeric|min:0|not_in:0', which accepted 7 as readily as 1 --
     * flash_sales only has PUBLIC (1) and PRIVATE (2), and anything else is a
     * sale that no query will ever match and no screen can explain.
     *
     * The products are checked here for shape and in
     * FlashSalesController::reviewProducts for everything that needs the
     * database -- the product exists, it is published, it is not already
     * promoted, and the price is a reduction. Each of those has to name the
     * product it is refusing, which a rule string cannot do.
     */
    public static function flashSale($request)
    {
        $rules = [
            'title' => 'required',
            'start_time' => 'required',
            'end_time' => 'required',
            'status' => 'required|numeric|in:1,2',

            'products' => 'nullable|array',
            'products.*.price' => 'nullable|numeric',
            'products.*.product.id' => 'nullable|numeric|min:1',
        ];

        return self::validationMessage($request, $rules);
    }
	
    /**
     * An upsell campaign.
     *
     * Shape only. Everything that has to look at the database -- the products
     * exist and are published, a source is not also an upgrade option, the
     * campaign has at least one of each -- lives in
     * UpsellCampaignsController::action, because each of those has to name the
     * field it refused and say what to do about it.
     */
    public static function upsellCampaign($request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'status' => 'required|numeric|in:1,2',
            'customer_type' => 'required|in:all,personal,business,trade',

            'source_products' => 'required|array|min:1',
            'source_products.*.product_id' => 'required|numeric|min:1',
            'source_products.*.inventory_id' => 'nullable|numeric|min:1',

            'upgrade_options' => 'required|array|min:1',
            'upgrade_options.*.product_id' => 'required|numeric|min:1',
            'upgrade_options.*.inventory_id' => 'nullable|numeric|min:1',
            'upgrade_options.*.priority' => 'nullable|integer|min:1|max:999',

            'minimum_cart_value' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',

            // Bounded so a typo cannot bury a campaign below everything else.
            'priority' => 'nullable|integer|min:1|max:999',

            'show_on_product_page' => 'nullable|boolean',
            'show_on_cart_page' => 'nullable|boolean',
            'show_on_checkout_page' => 'nullable|boolean',
        ];

        return self::validationMessage($request, $rules);
    }


    /**
     * A cross-sell promotion.
     *
     * Shape only. Everything that has to look at the catalogue -- the products
     * exist and are published, an active promotion actually has something to
     * show -- lives in CrossSellsController::action, because each of those has
     * to name the products it refused and say what to do about them.
     *
     * `products` is `present` rather than `required`: an empty list is a
     * meaningful save. It is a draft an admin has named and will fill in
     * later, and the controller refuses to switch such a promotion on.
     *
     * The list is bounded here so a crafted payload cannot ask the controller
     * to check ten thousand ids before refusing them.
     */
    public static function crossSell($request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'status' => 'required|integer|in:1,2,3',
            'max_products' => 'nullable|integer|min:1|max:12',
            'products' => 'present|array|max:50',
            'products.*' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }


    /**
     * A shopper taking one or more cross-sell recommendations.
     *
     * Shape only, and deliberately loose about which products they are: what
     * makes a product addable here is that it is a LIVE recommendation of
     * something the shopper is looking at or already has, that it is published
     * and that the chosen variant is buyable -- all of which move between the
     * block rendering and the click, so all of them are proved against the
     * catalogue in CrossSellService::refusalFor rather than asserted here.
     *
     * 'exists' is not used on the ids for the same reason: a product that has
     * been deleted has to come back as "that product is no longer available"
     * next to the tick the shopper made, not as a validation error about a
     * field they never saw.
     */
    public static function crossSellAdd($request)
    {
        $rules = [
            'products' => 'required|array|min:1|max:' . self::MAX_LINE_QUANTITY,
            'products.*.product_id' => 'required|integer|min:1',
            'products.*.inventory_id' => 'nullable|integer|min:1',
            'products.*.quantity' => 'nullable|integer|min:1|max:' . self::MAX_LINE_QUANTITY,

            'primary_product_id' => 'nullable|integer|min:1',
            'primary_product_ids' => 'nullable|array',
            'primary_product_ids.*' => 'integer|min:1',
        ];

        return self::validationMessage($request, $rules);
    }


    /**
     * A product's upgrade configuration.
     *
     * Shape only. Everything that has to look at the catalogue -- the
     * attribute is one the product's variants actually use, a manual group has
     * values that belong to its attribute, no attribute is configured twice --
     * lives in ProductUpgradeConfigController, where each refusal can name the
     * group it is about.
     *
     * `groups` is deliberately `present` rather than `required`: an empty list
     * is a meaningful save. It turns the automatic defaults off for this
     * product, and it is the only way an admin can say "no upgrades here".
     */
    public static function productUpgradeGroups($request)
    {
        $rules = [
            'groups' => 'present|array',
            'groups.*.name' => 'required|string|max:255',
            'groups.*.attribute_id' => 'required|numeric|min:1',
            'groups.*.mode' => 'required|in:automatic,manual',
            'groups.*.status' => 'required|numeric|in:1,2',
            'groups.*.display_order' => 'nullable|integer|min:1|max:999',
            'groups.*.out_of_stock' => 'required|in:hide,disable',
            'groups.*.customer_type' => 'required|in:all,personal,business,trade',
            'groups.*.max_options' => 'nullable|integer|min:1|max:50',
            'groups.*.option_value_ids' => 'nullable|array',
            'groups.*.option_value_ids.*' => 'numeric|min:1',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function serviceAndAbout($request)
    {
        $rules = [
            'service_links' => 'required',
            'about_links' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function footerLink($request)
    {
        $rules = [
            'page_id' => 'required',
            'type' => 'required|numeric|min:0|not_in:0'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function footerImageLink($request)
    {
        $rules = [
            'title' => 'required',
            'link' => 'required',
            'type' => 'required|numeric|min:0|not_in:0'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function footerImage($request)
    {
        $rules = [
            'type' => 'required|numeric|min:0|not_in:0'
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }


    public static function deActivate($request)
    {
        $rules = [
            'public_key' => 'required',
            'secret_key' => 'required',
            'encrypt_key' => 'required',
            'encrypt_iv' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function banner($request)
    {
        $rules = [
            'source_type' => 'required|numeric|min:0|not_in:0',
            'type' => 'required|numeric|min:0|not_in:0',
            'closable' => 'required|numeric|min:0|not_in:0',
            'slug' => 'required',
            'title' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function homeSlider($request)
    {
        $rules = [
            'source_type' => 'required|numeric|min:0|not_in:0',
            'type' => 'required|numeric|min:0|not_in:0',
            'slug' => 'required',
            'title' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function bannerImage($request)
    {
        $rules = [
            'type' => 'required|numeric|min:0|not_in:0'
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }


    public static function homeSliderImage($request)
    {
        $rules = [
            'type' => 'required|numeric|min:0|not_in:0'
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }

    public static function password_check($admin, $password, $message = null, $error_type = 'form', $lang = null)
    {
        if(!$message){
            $message = __('lang.wrong_email', [], $lang);
        }

        if (is_null($admin) || !Hash::check($password, $admin->password)){
            return new Response(null, [$error_type => [$message]], 201, $message);
        }

        return false;
    }

    public static function frontendError($message = null, $lang = null)
    {
        if(!$message){
            $message = __('lang.couldnt_found', [], $lang);
        }
        return Validation::message(null, $message);
    }


    public static function errorTokenLang($token = null, $lang = null)
    {
        return self::error($token, null, 'form', $lang);
    }


    public static function errorLang($lang = null)
    {
        return self::error(null, null, 'form', $lang);
    }


    public static function error($token = null, $message = null, $error_type = 'form', $lang = null, $status = 201)
    {
        if(!$message){
            $message = __('lang.went_wrong', [], $lang);
        }

        $message = self::scrubInternalDetail($message, $lang);

        return new Response($token, [$error_type => [$message]], $status, $message);
    }

    /**
     * A refusal that belongs to one named field.
     *
     * Validation::error() puts the message under the error type it is given,
     * so a check scoped to a field - a slug already in use, a parent that
     * would make a loop - answered with {"slug": ["..."]}, which no form
     * renders: the pages read `form` and `image`. The message reached the
     * browser and was never shown, so the save looked like it had simply done
     * nothing. This sends both: the flat list every existing form already
     * displays, and `fields`, which lets a form put it against the control
     * that caused it (the same shape validationResponse() sends).
     */
    public static function fieldError($token = null, $field = null, $message = null, $lang = null, $status = 201)
    {
        if (!$message) {
            $message = __('lang.went_wrong', [], $lang);
        }

        $message = self::scrubInternalDetail($message, $lang);

        return new Response($token, [
            'form' => [$message],
            'fields' => [$field => [$message]]
        ], $status, $message);
    }


    /**
     * Keep internal failure detail out of API responses.
     *
     * Controllers across this codebase answer their catch blocks with
     * Validation::error($request->token, $ex->getMessage()), which handed the
     * caller raw SQL — table names, column names and the full INSERT with its
     * bound values — plus PHP fatal-error text, regardless of APP_DEBUG. Two
     * were reproduced during QA: a truncation error on products.warranty that
     * returned the entire insert statement, and a foreign-key violation that
     * named the database.
     *
     * Anything that looks like engine or interpreter output is logged and
     * replaced with the generic message; genuine, translated messages meant for
     * the customer pass through untouched.
     */
    public static function scrubInternalDetail($message, $lang = null)
    {
        if (!is_string($message) || $message === '') {
            return $message;
        }

        $signatures = [
            'SQLSTATE',
            'SQL: ',
            'Attempt to read property',
            'Attempt to assign property',
            'Trying to access array offset',
            'Call to a member function',
            'Call to undefined',
            'Undefined array key',
            'Undefined variable',
            'Undefined property',
            'Too few arguments',
            'Argument #',
            'must be of type',
            'Class "',
            'Target class',
            'Integrity constraint violation',
            'syntax error',
            'Maximum execution time',
            'Allowed memory size',
            'vendor\\laravel',
            'vendor/laravel',
            '.php:',
            'Stack trace',
        ];

        foreach ($signatures as $needle) {
            if (stripos($message, $needle) !== false) {

                return self::suppress($message, $lang);
            }
        }

        // A filesystem path is never something a customer should be shown.
        if (preg_match('/(^|\s)([A-Za-z]:\\\\|\/(var|home|usr|srv)\/)/', $message)) {

            return self::suppress($message, $lang);
        }

        return $message;
    }


    /**
     * The generic message in place of internal detail, with a reference.
     *
     * The detail is logged under a short code that the customer sees too,
     * "Something went wrong (ref 7F3A9C21)", so a screenshot of the failure is
     * enough to find it: grep the code in storage/logs. The detail itself stays
     * out of the response - it is SQL with customers' session tokens in it.
     *
     * On a developer's own machine (APP_ENV=local with APP_DEBUG on) the real
     * message is returned instead. Never on live, never under the tests.
     */
    private static function suppress($message, $lang = null)
    {
        $ref = strtoupper(bin2hex(random_bytes(4)));

        \Illuminate\Support\Facades\Log::error('Suppressed internal error detail', [
            'ref' => $ref,
            'message' => $message,
        ]);

        if (app()->environment('local') && config('app.debug')) {
            return $message . ' (ref ' . $ref . ')';
        }

        /*
         * API_ERROR_DETAIL, for finding a failure on live: the error itself,
         * without the "(SQL: ...)" that follows it - the statement and its
         * bound values, session tokens among them.
         */
        if (config('app.error_detail')) {
            $detail = trim(preg_replace('/\s*\(SQL: .*$/s', '', $message));

            return mb_strimwidth($detail, 0, 300, '...') . ' (ref ' . $ref . ')';
        }

        return __('lang.went_wrong', [], $lang) . ' (ref ' . $ref . ')';
    }

    public static function nothingFoundLang($lang = null, $status = 200)
    {
        return self::nothing_found($status, null, 'form', $lang);
    }


    public static function nothing_found($status = 200, $message = null, $error_type = 'form', $lang = null)
    {
        if(!$message){
            $message = __('lang.couldnt_found', [], $lang);
        }

        return new Response(null, [$error_type => [$message]], $status, $message);
    }

    public static function unauthorized($status = 403, $message = null, $error_type = 'form', $lang = null)
    {
        if(!$message){
            $message = __('lang.no_access', [], $lang);
        }

        return new Response(null, [$error_type => [$message]], $status, $message);
    }


    public static function noDataLang($lang = null)
    {
        return self::noData(201, null, 'form', $lang);
    }


    public static function noData($status = 201, $message = null, $error_type = 'form', $lang = null)
    {
        if(!$message){
            $message = __('lang.couldnt_found', [], $lang);
        }
        return new Response(null, [$error_type => [$message]], $status, $message);
    }

    public static function invalid_parameter($token, $message = null, $error_type = 'form', $lang = null)
    {
        if(!$message){
            $message = __('lang.invalid_parameter', [], $lang);
        }

        return new Response($token, [$error_type => [$message]], 201, $message);
        //return new Response($token, [$message], 201, $message);
    }

    public static function message($token, $message)
    {
        return new Response($token, [$message], 201, $message);
    }


    public static function productDescription($request){
        $rules = [
            'description' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function changeCart($request){
        $rules = [
            'checked' => 'required',
            'unchecked' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function productMain($request){
        $rules = [
            'title' => 'required',
            /*
             * The slug was never required, but ProductsController::all() opens
             * with whereNotNull('slug'), so any client that omitted it created
             * a product that was live, orderable and completely absent from the
             * admin Products list. Nine rows in this database got in that way,
             * seven of them published.
             */
            'slug' => 'required',
            'unit' => 'required',
            'meta_title' => 'required',
            'meta_description' => 'required',
            'description' => 'required',
            'overview' => 'required',
            'selling' => 'required|numeric|min:0|not_in:0',
            'purchased' => 'required|numeric|min:0|not_in:0',
            // QA audit PRC-02: 'offered' was unvalidated, so an "offer" above
            // the selling price was accepted and then charged.
            'offered' => 'nullable|numeric|min:0|lte:selling',
            'tax_rule_id' => 'required|numeric|min:0|not_in:0'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function updateCart($request){
        $rules = [
            'id' => 'required',
            'quantity' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }

    public static function shippingCart($request){
        $rules = [
            'cart' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function sendSubscriptionEmail($request){
        $rules = [
            'id' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function emailSubscription($request){
        $rules = [
            'email' => 'required|email',
        ];

        return self::validationMessage($request, $rules);
    }


    /**
     * QA audit STK-02. 'quantity' used to be 'required' and nothing else, so a
     * negative value reached UpdatedInventory::decrement() and *increased*
     * stock, while also subtracting from the order total.
     */
    public static function cart($request){
        $rules = [
            'product_id' => 'required|integer|exists:products,id',
            'inventory_id' => 'required|integer|exists:updated_inventories,id',
            'quantity' => 'required|integer|min:1|max:' . self::MAX_LINE_QUANTITY,
        ];

        return self::validationMessage($request, $rules);
    }

    /*
     * The cart 'action' endpoint takes a *delta*, not an absolute quantity,
     * so the basket's "-" button legitimately sends -1. Reusing cart() here
     * made every decrement fail with "The quantity must be at least 1"; the
     * line total itself is bounded in the controller once the delta is applied.
     */
    public static function cartDelta($request){
        $rules = [
            'product_id' => 'required|integer|exists:products,id',
            'inventory_id' => 'required|integer|exists:updated_inventories,id',
            'quantity' => 'required|integer|not_in:0|between:-' . self::MAX_LINE_QUANTITY . ',' . self::MAX_LINE_QUANTITY,
        ];

        return self::validationMessage($request, $rules);
    }

    /*
     * A collection order has no delivery address, so these are the only
     * contact details the store gets for a guest picking an order up.
     */
    public static function collectionContact($request){
        $rules = [
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'required|string|max:50',
        ];

        return self::validationMessage($request, $rules);
    }

    public static function admin($request){
        $rules = [
            'username' => 'required',
            'roles' => 'required',
            'email' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function role($request){
        $rules = [
            'name' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function subCategory($request){
        $rules = [
            'title' => 'required',
            'category_id' => 'required|numeric|min:0|not_in:0',
            'slug' => 'required',
            'meta_title' => 'required',
            'meta_description' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function attributeValue($request){
        $rules = [
            'title' => 'required',
            'attribute_id' => 'required|numeric|min:0|not_in:0'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function contactUs($request)
    {
        $rules = [
            'id' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function shippingRule($request)
    {
        $rules = [
            'title' => 'required',
            'shipping_places' => 'required|array',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function attribute($request)
    {
        $rules = [
            'title' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function tag($request)
    {
        $rules = [
            'title' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }



    public static function collection($request)
    {
        $rules = [
            'title' => 'required',
            'slug' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }


    public static function customScript($request)
    {
        $rules = [
            'route_pattern' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }


    public static function siteFeature($request)
    {
        $rules = [
            'detail' => 'required',
        ];

        return self::validationMessage($request, $rules);
    }



    public static function brand($request)
    {
        $rules = [
            'title' => 'required',
            'slug' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    /**
     * A category, as the admin form submits it.
     *
     * This was four bare `required`s. Everything the form actually collects —
     * the slug's shape, the numeric fields, the image, the SEO lengths — was
     * unchecked, so an oversized meta description was silently truncated by
     * the column, a slug of "My Category!" went in verbatim and then 404'd on
     * the storefront, and products_per_page accepted "abc".
     *
     * Lengths match the columns: title/slug/meta_title/canonical_url are
     * varchar(255), short_description/description/meta_description/
     * meta_keywords are TEXT (capped here at a sane editorial length rather
     * than the column's 64KB).
     *
     * Parent is only checked for shape here; whether it is a legal parent for
     * *this* category is a hierarchy question, answered by
     * CategoryTree::parentProblem() where the category's own id is known.
     */
    public static function category($request)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'meta_title' => 'required|string|max:255',
            'meta_description' => 'required|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:5000',
            'canonical_url' => 'nullable|string|max:255|url',
            'parent' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:1,2',
            'display_order' => 'nullable|integer|min:0|max:99999',
            'products_per_page' => 'nullable|integer|min:1|max:200',
            'in_footer' => 'nullable|integer|in:1,2',
            'in_frontend' => 'nullable|integer|in:1,2',
            'featured' => 'nullable|integer|in:1,2',
            'searchable' => 'nullable|boolean',
            'show_in_nav' => 'nullable|boolean',
            'show_homepage' => 'nullable|boolean',
            'enable_filters' => 'nullable|boolean',
            'allow_promotions' => 'nullable|boolean',
            'publish_at' => 'nullable|date',
        ];

        $messages = [
            'slug.regex' => __('lang.category_slug_format', [], $request->header('language')),
        ];

        return self::validationMessage($request, $rules, 'form', $messages);
    }

    /**
     * A base64 data URL for a category image or banner.
     *
     * The upload path took whatever the browser sent, matched
     * `data:image/(\w+);base64,` and wrote the decoded bytes to the uploads
     * folder under that extension — so `data:image/php;base64,...` produced an
     * executable file in a web-served directory. Type and size are decided
     * here, from the payload, before anything is written.
     */
    const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];

    const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /**
     * @return string|null the problem, or null when the payload is acceptable
     */
    public static function base64ImageProblem($base64, $lang = null, $maxBytes = null)
    {
        $maxBytes = $maxBytes ?? self::MAX_IMAGE_BYTES;

        if (!is_string($base64) || !preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', $base64, $type)) {
            return __('lang.invalid_image', [], $lang);
        }

        $extension = strtolower($type[1]);

        // svg+xml arrives with the suffix attached.
        $extension = explode('+', $extension)[0];

        if (!in_array($extension, self::IMAGE_TYPES, true)) {
            return __('lang.invalid_image', [], $lang);
        }

        $payload = substr($base64, strlen($type[0]));

        // Four base64 characters carry three bytes; close enough to reject an
        // oversized upload without decoding the whole thing first.
        $bytes = (int)(strlen($payload) * 3 / 4);

        if ($bytes > $maxBytes) {
            return __('lang.image_too_large', ['max' => round($maxBytes / 1024 / 1024, 1)], $lang);
        }

        return null;
    }


    public static function subscriptionEmail($request)
    {
        $rules = [
            'title' => 'required',
            'subject' => 'required',
            'body' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function voucherRules($request)
    {
        $rules = [
            'title' => 'required',
            'code' => 'required',
            'type' => 'required|numeric|min:0|not_in:0',
            'price' => 'required|numeric|min:0|not_in:0'
        ];
        return self::validationMessage($request, $rules);
    }


    public static function language($request)
    {
        $rules = [
            'name' => 'required',
            'code' => 'required'
        ];
        return self::validationMessage($request, $rules);
    }


    /**
     * A Bundle Deal is a PRICING rule, so the numbers on it are checked.
     *
     * This asked for a title and a description and nothing else, which let
     * two useless rules be saved:
     *
     *   - A bundle with no products. BundleService drops an empty bundle
     *     rather than render an empty card, so it was saved, listed and
     *     assignable while being unable ever to appear.
     *   - A percentage of 500. Pricing::bundleDiscount caps the payout at the
     *     value of the goods so nothing is over-refunded, but the bundle
     *     silently becomes free, which nobody typing 500 into a box intended.
     */
    public static function bundleDeals($request)
    {
        $rules = [
            'title' => 'required',
            'description' => 'required',

            // The set it prices. A bundle without one cannot be offered.
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|numeric',

            'discount_type' => 'required|in:fixed,percentage',
            'discount_value' => 'required|numeric|min:0',

            // "Buy X get Y free" lives on the same table; it is optional, but
            // it cannot be negative.
            'buy' => 'nullable|numeric|min:0',
            'free' => 'nullable|numeric|min:0',
        ];

        // A percentage has a ceiling; a fixed amount does not.
        if ($request->input('discount_type') === 'percentage') {
            $rules['discount_value'] = 'required|numeric|min:0|max:100';
        }

        return self::validationMessage($request, $rules);
    }

    /**
     * A clearance deal.
     *
     * The discount was unchecked: only `product` and `status` were required,
     * so the screen saved a 150% deal -- which prices the product at zero,
     * because Pricing clamps the result rather than rejecting it -- and a
     * negative one, which is a price RISE wearing a deal badge. The form's
     * min="0" was the only guard and it is client-side.
     *
     * A percentage has a ceiling and a flat amount does not, exactly as in
     * bundleDeals() above: a flat amount is capped against each line's own
     * base at the moment it is charged.
     */
    public static function clearance($request)
    {
        $rules = [
            'product' => 'required',
            'status'  => 'required|numeric|min:0|not_in:0',

            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
        ];

        if ($request->input('discount_type') === 'percentage') {
            $rules['discount_value'] = 'nullable|numeric|min:0|max:100';
        }

        return self::validationMessage($request, $rules);
    }

    public static function userFollowStore($request)
    {
        $rules = [
            'store_id' => 'required'
        ];

        return self::validationMessage($request, $rules);
    }

    public static function taxRules($request)
    {
        $rules = [
            'title' => 'required',
            'type' => 'required|numeric|min:0|not_in:0',
            'price' => 'required|numeric|min:0'
        ];
        return self::validationMessage($request, $rules);
    }


    public static function tagRules($request)
    {
        $rules = [
            'title' => 'required',
            'type' => 'required|numeric|min:0|not_in:0'
        ];


        return self::validationMessage($request, $rules);
    }

    public static function quantityValidation($request, $token)
    {
        $rules = [
            'attributes' => 'required',
            'quantity' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
        ];

        $validator = Validator::make($request, $rules);
        return self::validationResponse($validator, $token, 'inventory');
    }

    public static function inventoryQuantity($request)
    {
        $rules = [
            'quantity' => 'required|numeric|min:0',
            'product_id' => 'required|numeric'
        ];

        return self::validationMessage($request, $rules, 'inventory');
    }

    public static function inventoryValue($request)
    {
        $rules = [
            'attributes' => 'required',
            'product_id' => 'required|numeric'
        ];

        return self::validationMessage($request, $rules, 'inventory');
    }


    public static function install($request){
        $rules = [
            'appName' => 'required',
            'dbName' => 'required',
            'dbUser' => 'required'
        ];
        return self::validationMessage($request, $rules);
    }

    public static function subCategoryImage($request){
        $rules = [
            'category_id' => 'required|numeric|min:0|not_in:0'
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }

    public static function productPreviewImage($request)
    {
        $rules = [
            'category_id' => 'required|numeric|min:0|not_in:0',
            'tax_rule_id' => 'required|numeric|min:0|not_in:0'
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules, 'image');
    }

    public static function activatePlugin($request)
    {
        $rules = [
            'code' => 'required',
            'name' => 'required',
        ];

        array_push($rules, self::imageRules());

        return self::validationMessage($request, $rules);
    }


    /*
     * QA audit SEC-06. The remote-storage branches of these three used to fall
     * back to a bare 'required' — no type and no size limit — so the storage
     * backend decided what got uploaded. The full rules now apply either way.
     */

    public static function zip($request, $errorType = 'zip')
    {
        return self::validationMessage($request, self::zipRules(), $errorType);
    }

    public static function image($request, $errorType = 'image')
    {
        return self::validationMessage($request, self::imageRules(), $errorType);
    }

    public static function video($request, $errorType = 'video')
    {
        return self::validationMessage($request, self::videoRules(), $errorType);
    }


    public static function multipleImages($request, $token){
        $validator = Validator::make($request, self::imageRules());

        return self::validationResponse($validator, $token, 'multiple_image');
    }

    public static function success($request, $message = "Success", $data = null, $status = 200){
        return response()->json(new Response($request->token, $data, $status, @$message));
    }


    public static function zipRules(){
        return ['file' => 'required|file|mimes:zip|max:'.Config::get('constants.media.MAX_FILE_SIZE')];
    }

    /**
     * QA audit SEC-06. 'svg' was dropped from the accepted product-image types:
     * an SVG is a script container, and these are served inline from the
     * storefront origin, which made it a stored-XSS vector against any admin
     * opening the product. The dedicated sidebarIcon() rule below still takes
     * SVG, because those are uploaded by admins and rendered as icons.
     */
    public static function imageRules(){
        return ['photo' => 'required|file|image|mimes:jpeg,jpg,png,gif,webp|max:'.Config::get('constants.media.MAX_IMAGE_SIZE')];
    }

    public static function sidebarIcon($request){
        $rules = [
            'menu_key' => 'required',
            'photo' => 'required|file|mimes:svg|max:'.Config::get('constants.media.MAX_IMAGE_SIZE'),
        ];

        return self::validationMessage($request, $rules);
    }

    public static function videoRules(){
        return ['video_file'  => 'mimes:mp4,mov,ogg,qt|max:'.Config::get('constants.media.MAX_VIDEO_SIZE')];
    }

    /**
     * Does this look like a VAT number rather than a sentence?
     *
     * Separators are stripped first, because customers copy them straight off
     * a certificate ("IE 1234567 FA") and rejecting that is rejecting a
     * correct answer. What is left has to be alphanumeric, the right sort of
     * length, and carry at least two digits — which is what tells a VAT number
     * apart from the free text the column was collecting: "n/a", "none yet",
     * "will send later", a contact name.
     *
     * Deliberately not a per-country table. VAT numbers are issued by every
     * member state in a different shape, and a strict table rejects a valid
     * number the first time a customer registers from a country nobody thought
     * of. Staff verify the number itself; this only keeps prose out.
     */
    public static function looksLikeVatNumber($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $compact = preg_replace('/[\s.\-\/]/', '', $value);

        if (!preg_match('/^[A-Za-z0-9]{6,18}$/', (string)$compact)) {
            return false;
        }

        return preg_match_all('/[0-9]/', $compact) >= 2;
    }


    /**
     * Company registration numbers are digits, with separators tolerated.
     */
    public static function looksLikeCompanyNumber($value)
    {
        if (!is_string($value)) {
            return false;
        }

        $compact = preg_replace('/[\s.\-\/]/', '', $value);

        return (bool)preg_match('/^[0-9]{4,12}$/', (string)$compact);
    }


    /**
     * Did the caller actually supply this field?
     *
     * Request::filled() treats "0" as filled and whitespace as filled; neither
     * is a company number. Optional identifiers are only validated when the
     * customer typed something, so that an empty box stays optional.
     */
    private static function filled($request, $field)
    {
        $value = $request->input($field);

        return is_string($value) && trim($value) !== '';
    }


    public static function validationMessage($request, $rules, $error_type = 'form', $message = null){

        if($message) {
            $validator = Validator::make($request->all(), $rules, $message);

        } else{
            $validator = Validator::make($request->all(), $rules);
        }

        return self::validationResponse($validator, $request->token, $error_type);
    }

    public static function validationResponse($validator, $token, $error_type = 'form'){
        if ($validator->fails()){

            $messages = $validator->errors()->messages();

            /*
             * `fields` is additive: every existing caller reads the flat list
             * under $error_type and is untouched. Forms that want to put a
             * message against the control that caused it - registration's
             * email and phone, say - need to know which field each one came
             * from, and the flat list throws that away.
             */
            return new Response($token, [
                $error_type => Utils::formatErrors($messages),
                'fields' => $messages
            ], 201);
        }

        return false;
    }

    /**
     * Loyalty group rules.
     *
     * The admin form asked for a title, a discount, a validity mode and a set
     * of users, and the controller accepted whatever arrived: a blank title, a
     * negative discount, a 5000% percentage, a "30 day" period with no days, a
     * date range ending before it starts. All of it reached the database.
     */
    public static function loyaltyGroup($request)
    {
        $rules = [
            'title' => 'required|string|max:191',
            'customer_label' => 'nullable|string|max:100',
            'discount_type' => 'required|in:fixed,percentage',
            'discount_value' => 'required|numeric|min:0.01',
            'validity' => 'required|in:one_time,period,date_range',
            'min_cart_value' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'users' => 'nullable|array',
            'users.*' => 'integer|exists:users,id',
            'period_days' => 'required_if:validity,period|nullable|integer|min:1|max:3650',
            'start_date' => 'required_if:validity,date_range|nullable|date',
            'end_date' => 'required_if:validity,date_range|nullable|date|after_or_equal:start_date'
        ];

        $messages = [
            'period_days.required_if' => 'Number of days is required for a set period',
            'start_date.required_if' => 'Start date is required for a date range',
            'end_date.required_if' => 'End date is required for a date range',
            'end_date.after_or_equal' => 'End date cannot be before the start date',
            'users.*.exists' => 'One of the selected customers no longer exists'
        ];

        if ($fail = self::validationMessage($request, $rules, 'form', $messages)) {
            return $fail;
        }

        /*
         * A percentage over 100 is not a discount, it is a payout, and a fixed
         * discount capped below its own value is a contradiction. Neither is
         * expressible as a stock validation rule.
         */
        if ($request->discount_type === 'percentage' && (float)$request->discount_value > 100) {
            return self::error($request->token, 'A percentage discount cannot exceed 100%');
        }

        if ($request->discount_type === 'fixed'
            && !is_null($request->max_discount)
            && $request->max_discount !== ''
            && (float)$request->max_discount > 0
            && (float)$request->max_discount < (float)$request->discount_value) {

            return self::error($request->token,
                'The maximum discount cannot be lower than the fixed discount value');
        }

        return null;
    }

    /**
     * Saving a store-wide flash discount.
     *
     * What used to be here was four truthiness checks, under a comment in the
     * controller that read "create this validation". Everything else went
     * through: a percentage of 150, a negative value, and a window whose end
     * came before its start. The type was policed by nothing but MySQL strict
     * mode, so a bad one surfaced as the generic "something went wrong".
     *
     * $id is the row being edited, excluded from the overlap check so saving a
     * discount does not collide with itself.
     */
    public static function flashDiscount($request, $id = null)
    {
        $type = $request->type;

        if (!in_array($type, ['percentage', 'fixed'], true)) {
            return self::error($request->token, 'Type must be either percentage or fixed');
        }

        /*
         * `!$request->value` rejected 0 as "Value is required", which is the
         * wrong sentence for the wrong reason. Missing and zero are separate
         * answers and each gets its own.
         */
        if (is_null($request->value) || $request->value === '') {
            return self::error($request->token, 'Value is required');
        }

        if (!is_numeric($request->value)) {
            return self::error($request->token, 'Value must be a number');
        }

        $value = (float)$request->value;

        if ($value <= 0) {
            return self::error($request->token, 'Value must be greater than zero');
        }

        if ($type === 'percentage' && $value > 100) {
            return self::error($request->token, 'A percentage discount cannot be greater than 100');
        }

        foreach (['min_cart_value' => 'Minimum cart value', 'max_discount' => 'Maximum discount'] as $field => $label) {

            $given = $request->$field;

            if (is_null($given) || $given === '') {
                continue;
            }

            if (!is_numeric($given)) {
                return self::error($request->token, $label . ' must be a number');
            }

            if ((float)$given < 0) {
                return self::error($request->token, $label . ' cannot be negative');
            }
        }

        /*
         * A fixed discount capped below its own value pays out the cap, which
         * is never what was meant. Same rule the loyalty group already has.
         */
        if ($type === 'fixed'
            && is_numeric($request->max_discount)
            && (float)$request->max_discount > 0
            && (float)$request->max_discount < $value) {

            return self::error($request->token,
                'The maximum discount cannot be lower than the fixed discount value');
        }

        if (!$request->start_time || !$request->end_time) {
            return self::error($request->token, 'Start & End time required');
        }

        try {
            $start = \Carbon\Carbon::parse($request->start_time);
            $end = \Carbon\Carbon::parse($request->end_time);
        } catch (\Exception $ex) {
            return self::error($request->token, 'Start & End time must be valid dates');
        }

        if ($end->lessThanOrEqualTo($start)) {
            return self::error($request->token, 'The end time must come after the start time');
        }

        /*
         * Only one store-wide discount can be live at a time. Nothing enforced
         * that, and FlashDiscount::active()->first() then returned whichever
         * row the database happened to hand back -- so which promotion a
         * customer got was row order. Overlapping windows are refused here
         * instead, which is the only place that can explain why.
         *
         * Touching ends are fine: a window that starts exactly as another ends
         * does not overlap it.
         */
        $isActive = is_null($request->is_active) ? true : (bool)$request->is_active;

        if ($isActive) {

            $clash = \App\Models\FlashDiscount::where('is_active', 1)
                ->where('start_time', '<', $end)
                ->where('end_time', '>', $start)
                ->when($id, function ($query) use ($id) {
                    return $query->where('id', '!=', $id);
                })
                ->first();

            if ($clash) {
                return self::error($request->token,
                    'This window overlaps the live discount running from '
                    . $clash->start_time->format('d M Y H:i') . ' to '
                    . $clash->end_time->format('d M Y H:i')
                    . '. Switch that one off or change the dates.');
            }
        }

        return null;
    }
}
