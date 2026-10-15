<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */


    /** A verification code is good for this long, then it is worthless. */
    const CODE_LIFETIME_MINUTES = 30;

    /** Wrong guesses tolerated before the code is discarded entirely. */
    const MAX_CODE_ATTEMPTS = 5;

    /**
     * Strict comparison of a submitted verification code (QA audit ACC-01).
     *
     * The check used to be a loose ==, with no expiry and no attempt limit.
     */
    public static function codeMatches($user, $submitted)
    {
        if (is_null($user) || is_null($user->code) || $user->code === '') {
            return false;
        }

        if (is_null($submitted) || is_array($submitted) || $submitted === '') {
            return false;
        }

        if ($user->code_expires_at && $user->code_expires_at->isPast()) {
            return false;
        }

        if ((int)$user->code_attempts >= self::MAX_CODE_ATTEMPTS) {
            return false;
        }

        return hash_equals((string)$user->code, (string)$submitted);
    }

    protected $casts = [
        'viewed' => 'integer',
        'verified' => 'integer',
        'remember_token' => 'integer',
        'code_expires_at' => 'datetime',
        'code_attempts' => 'integer',
        'pref_email_offers' => 'boolean',
        'pref_sms_offers' => 'boolean',
        'pref_recommendations' => 'boolean',
        'pref_trade_list' => 'boolean',
        'two_factor_confirmed_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'trade_reviewed_at' => 'datetime',
        'status_changed_at' => 'datetime'
    ];


    protected $fillable = ['name', 'email', 'account_type', 'password', 'code',
        'code_expires_at', 'code_attempts', 'default_address', 'phone',
        'phone_verified_at',
        'verified', 'remember_token', 'facebook_id', 'google_id', 'viewed',
        'trade_status', 'trade_reviewed_at', 'trade_review_note',
        'status', 'status_note', 'status_changed_at', 'verification_status',
        'pref_email_offers', 'pref_sms_offers', 'pref_recommendations', 'pref_trade_list',
        'two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    /*
     * `code` is a live authentication secret — it is the one-time code that
     * signs somebody in or confirms their email, and it was being serialised
     * straight out of the admin customer endpoint, so anyone who could read a
     * customer record could also take over that account. One caller already
     * unset it by hand (UsersController::updateProfile); hiding it here covers
     * every other caller instead of waiting for each one to remember.
     *
     * Hiding affects array/JSON output only, so the flows that compare
     * $user->code during verification are unchanged.
     */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'code', 'code_expires_at', 'code_attempts',
        // The secret in a shared-wishlist link; only WishlistShareController reads it.
        'wishlist_share_token',
        // billing reference; only Helper\StripeBilling reads it
        'stripe_customer_id'];

    public const ACCOUNT_TYPES = ['personal', 'business', 'trade'];

    public const TRADE_PENDING = 'pending';
    public const TRADE_APPROVED = 'approved';
    public const TRADE_REJECTED = 'rejected';
    /** Approved, then withdrawn. Distinct from rejected: the application stood. */
    public const TRADE_SUSPENDED = 'suspended';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REJECTED = 'rejected';

    public const VERIFICATION_NOT_REQUIRED = 'not_required';
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REJECTED = 'rejected';

    /**
     * Is the account usable at all?
     *
     * Read as "active unless told otherwise": rows written before the status
     * column existed carry no value, and treating those as unusable would lock
     * out every customer already registered.
     */
    public function isActive()
    {
        $status = $this->status;

        return is_null($status) || $status === '' || $status === self::STATUS_ACTIVE;
    }

    public function isSuspended()
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * May this account place an order?
     *
     * Suspension has to bite somewhere that costs the customer something, and
     * refusing checkout is that place. Signing in stays allowed on purpose — a
     * suspended customer needs to be able to read why, and locking them out of
     * their own order history mostly generates support mail.
     */
    public function canCheckout()
    {
        return $this->isActive();
    }

    /**
     * Trade pricing only applies once a person has reviewed the application —
     * and only while the account is still in good standing.
     */
    public function hasTradePricing()
    {
        return $this->account_type === 'trade'
            && $this->trade_status === self::TRADE_APPROVED
            && $this->isActive();
    }

    /**
     * Is this account entitled to the business_product_pricing quantity bands?
     *
     * The one place the question is answered. A business account qualifies the
     * moment it signs up; a trade account only once staff have approved it,
     * which is the whole point of the application queue. Everything that prices
     * a line — cart, checkout, vouchers, and the product page — asks this, so
     * an account cannot be shown a band price it will not be charged.
     */
    public function hasBusinessPricing()
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->account_type === 'business' || $this->hasTradePricing();
    }

    /**
     * Everything the storefront needs to render an account correctly, decided
     * here rather than by the browser reading three columns and guessing.
     *
     * The dashboard showed nothing at all about the account type, so a trade
     * customer waiting on approval had no way to tell whether anything was
     * happening. `headline` is the one sentence that answers that.
     */
    public function accountSummary()
    {
        return [
            'account_type' => $this->account_type ?: 'personal',
            'status' => $this->isActive() ? self::STATUS_ACTIVE : $this->status,
            'status_note' => $this->status_note,
            'verification_status' => $this->verification_status ?: self::VERIFICATION_NOT_REQUIRED,
            'trade_status' => $this->trade_status,
            'trade_review_note' => $this->trade_review_note,
            'has_business_pricing' => $this->hasBusinessPricing(),
            'can_checkout' => $this->canCheckout(),
            'headline' => $this->accountHeadline(),
        ];
    }

    /**
     * One sentence describing where this account stands.
     */
    public function accountHeadline()
    {
        if ($this->isSuspended()) {
            /*
             * This used to promise that a suspended customer could still sign
             * in and read their order history. They cannot: suspension now
             * revokes every session and refuses sign-in, so the old wording
             * described behaviour the application no longer has.
             */
            return 'This account is suspended and cannot be used to sign in. '
                . 'Please contact support for assistance.';
        }

        if ($this->status === self::STATUS_REJECTED) {
            return 'This account application was not accepted.';
        }

        if ($this->account_type === 'trade') {

            switch ($this->trade_status) {

                case self::TRADE_APPROVED:
                    return 'Your trade account is approved. Trade pricing is applied automatically.';

                case self::TRADE_REJECTED:
                    return 'Your trade application was not approved, so you are buying at retail prices. '
                        . 'Contact us if your circumstances have changed.';

                case self::TRADE_SUSPENDED:
                    return 'Your trade pricing is suspended, so you are buying at retail prices. '
                        . 'Contact us to have it reinstated.';

                default:
                    return 'Your trade application is with us. You can order at retail prices now, '
                        . 'and trade pricing switches on once it is approved.';
            }
        }

        if ($this->account_type === 'business') {
            return 'Business pricing is applied automatically at the quantity breaks shown on each product.';
        }

        return null;
    }

    /**
     * Everything this customer actually submitted when they registered,
     * grouped and labelled, decided by the account type they chose.
     *
     * Built here rather than in the admin screen for the reason accountSummary
     * exists: the registration form, the customer's own profile and the admin
     * preview were each deciding separately which fields belonged to which
     * account type, and the admin one simply left most of them out — a
     * business account's sector, PO requirement and credit request were
     * collected at signup and then visible nowhere.
     *
     * Two rules the shape enforces:
     *
     *  - A section is only returned when it applies to this account type, so
     *    a business account cannot be rendered with empty trade fields that
     *    look like the customer failed to answer them.
     *  - A field the customer left blank comes back with value === null and
     *    `provided` false, so the screen can say "Not provided" rather than
     *    printing an empty row that reads like missing data.
     */
    public function registrationProfile()
    {
        $type = $this->account_type ?: 'personal';
        $profile = $this->businessProfile;

        $text = function ($value) {
            $value = is_string($value) ? trim($value) : $value;
            return ($value === null || $value === '') ? null : $value;
        };

        $field = function ($label, $value, $kind = 'text', $note = null) {
            return [
                'label' => $label,
                'value' => $value,
                'kind' => $kind,
                'note' => $note,
                // A boolean answer of "No" is still an answer, so only text
                // fields can be "not provided".
                'provided' => $kind === 'bool' ? true : !is_null($value),
            ];
        };

        $sections = [];

        // ---- personal / contact, for every account type ---------------------
        $sections[] = [
            'key' => 'personal',
            'title' => 'Personal information',
            'fields' => [
                $field('Full name', $text($this->name), 'text',
                    'Registration collects one name field, not separate first and last names.'),
                $field('Email address', $text($this->email)),
                $field('Phone number', $text($this->phone)),
            ],
        ];

        // ---- company details, for business and trade ------------------------
        if (in_array($type, ['business', 'trade'], true)) {

            $companyFields = [
                $field('Company name', $text(optional($profile)->company_name)),
                $field('Company registration number', $text(optional($profile)->cro_number), 'text',
                    'Optional at registration. Digits only.'),
                $field('VAT number', $text(optional($profile)->vat_number), 'text',
                    $type === 'trade'
                        ? 'Required for trade registration.'
                        : 'Optional for business registration.'),
            ];

            // Sector is a business-only question. Showing it on a trade
            // account would invite a reviewer to read a blank as an omission.
            if ($type === 'business') {
                $companyFields[] = $field('Sector', $text(optional($profile)->sector));
            }

            $sections[] = [
                'key' => 'business',
                'title' => 'Business information',
                'fields' => $companyFields,
            ];

            $sections[] = [
                'key' => 'address',
                'title' => 'Registered address',
                'fields' => [
                    $field('Billing address', $text(optional($profile)->billing_address), 'text',
                        'Registration collects the billing address as one free-text field. '
                        . 'There are no separate city, county, country or Eircode inputs.'),
                ],
            ];
        }

        // ---- the trade application itself -----------------------------------
        if ($type === 'trade') {
            $sections[] = [
                'key' => 'trade',
                'title' => 'Trade application',
                'fields' => [
                    $field('Trade type', $text(optional($profile)->trade)),
                    $field('Expected monthly spend', $text(optional($profile)->expected_spend)),
                    $field('Website', $text(optional($profile)->website), 'text', 'Optional.'),
                    $field('Resale confirmed', (bool)optional($profile)->resale_confirmed, 'bool',
                        'Required confirmation for a trade account.'),
                    $field('Applied on', $this->registrationTimestamp(optional($profile)->created_at), 'date'),
                    $field('Decision recorded', $this->registrationTimestamp($this->trade_reviewed_at), 'date'),
                    $field('Note sent to the customer', $text($this->trade_review_note), 'text',
                        'This note is shown to the customer and included in the decision email. '
                        . 'It is not an internal remark.'),
                ],
            ];
        }

        // ---- preferences the customer set ------------------------------------
        $prefs = [];

        if (in_array($type, ['business', 'trade'], true)) {
            $prefs[] = $field('Purchase order required at checkout', (bool)optional($profile)->requires_po, 'bool',
                $type === 'trade'
                    ? 'Not asked during trade registration; the customer can set it from their profile.'
                    : null);
            $prefs[] = $field('Credit account requested', (bool)optional($profile)->credit_application, 'bool',
                $type === 'trade'
                    ? 'Not asked during trade registration.'
                    : 'Records interest only — it does not grant a credit account.');
        }

        $prefs[] = $field('Email offers', (bool)$this->pref_email_offers, 'bool');
        $prefs[] = $field('SMS offers', (bool)$this->pref_sms_offers, 'bool');
        $prefs[] = $field('Product recommendations', (bool)$this->pref_recommendations, 'bool');

        if ($type === 'trade') {
            $prefs[] = $field('Trade mailing list', (bool)$this->pref_trade_list, 'bool');
        }

        $sections[] = [
            'key' => 'preferences',
            'title' => 'Account preferences',
            'fields' => $prefs,
        ];

        // ---- account and verification state ----------------------------------
        $verification = [
            $field('Email confirmed', (bool)$this->verified, 'bool'),
            $field('Phone confirmed', !is_null($this->phone_verified_at), 'bool'),
            $field('Two-factor sign-in', $this->twoFactorEnabled()
                ? ('On (' . $this->two_factor_method . ')')
                : 'Off', 'text'),
            $field('Registered on', $this->registrationTimestamp($this->created_at), 'date'),
            $field('Last updated', $this->registrationTimestamp($this->updated_at), 'date'),
            $field('Status last changed', $this->registrationTimestamp($this->status_changed_at), 'date'),
        ];

        if ($this->google_id || $this->facebook_id) {
            $verification[] = $field('Signed up through',
                $this->google_id ? 'Google' : 'Facebook', 'text');
        }

        $sections[] = [
            'key' => 'verification',
            'title' => 'Account and verification',
            'fields' => $verification,
        ];

        return [
            'account_type' => $type,
            'sections' => $sections,
            /*
             * Named so the screen can say plainly that these were never asked
             * for, instead of leaving a reviewer to wonder whether the customer
             * skipped them or the page is broken.
             */
            'not_collected' => $this->fieldsNotCollected($type),
        ];
    }

    /**
     * Fields a reviewer may expect to find, that registration does not ask for.
     */
    private function fieldsNotCollected($type)
    {
        $common = [
            'Separate first and last name — one combined name field is collected.',
            'Structured address — city, county, country and Eircode are not separate inputs.',
            'Delivery address — not collected at registration; any shown were added later by the customer.',
        ];

        if ($type === 'trade') {
            return array_merge($common, [
                'Sector — asked of business accounts only.',
                'Years in business — not collected.',
                'Reviewed by — the application records when a decision was made, but not which admin made it.',
            ]);
        }

        if ($type === 'business') {
            return array_merge($common, [
                'Trade type, expected spend, website and resale confirmation — asked of trade accounts only.',
            ]);
        }

        return array_merge($common, [
            'Company, VAT and trade details — not asked of personal accounts.',
        ]);
    }

    private function registrationTimestamp($value)
    {
        if (!$value) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('d M Y, H:i')
            : (string)$value;
    }

    /*
     * An account that never confirmed its email is treated as non-existent by
     * sign-in, forgot-password and the emailed sign-in code — so those replies
     * can't be used to discover which addresses are registered.
     */
    public function scopeVerified($query)
    {
        return $query->where('verified', true);
    }

    public function twoFactorEnabled()
    {
        return !is_null($this->two_factor_confirmed_at)
            && !is_null($this->two_factor_method);
    }

    /**
     * Has the customer proved they can receive a message at this number?
     *
     * Nothing is gated on it — an unproved number is a contact detail, and
     * plenty of long-standing accounts have never been asked. It matters
     * where the number is used as a second factor, and it is shown in the
     * account so the customer can see which of the two their number is.
     */
    public function phoneVerified()
    {
        return !is_null($this->phone_verified_at) && !empty($this->phone);
    }

    /** Customer-initiated type changes, newest last. See Helper\AccountUpgrade. */
    public function upgradeRequests()
    {
        return $this->hasMany(AccountUpgradeRequest::class);
    }

    public function businessProfile()
    {
        return $this->hasOne(UserBusinessProfile::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function pointTransactions()
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function savedItems()
    {
        return $this->hasMany(SaveForLater::class);
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }
}
