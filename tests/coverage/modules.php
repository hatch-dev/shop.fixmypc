<?php

/*
 * Which module every route and page belongs to, for `php artisan test:inventory`.
 *
 * Modules are business capabilities, not code layout: "Checkout & payments"
 * holds the storefront order endpoints and the SumUp controller alike. Who may
 * call a route (guest, customer, admin) is worked out from its middleware and
 * reported separately, so one module covers both its storefront and admin side.
 *
 * Tier is the testing priority:
 *   1  money or access: a bug charges the wrong amount, or lets someone in
 *   2  core operations: a bug breaks a daily workflow
 *   3  content and system: a bug is visible but contained
 *
 * A route is matched by "Controller@method" first, then by "Controller".
 * A new controller that is in neither list is reported as unassigned, so the
 * checklist cannot silently miss it: add it here and re-run.
 */
return [
    'modules' => [
        'accounts'    => ['Customer accounts & sign-in', 1],
        'staff'       => ['Admin sign-in, staff & roles', 1],
        'customers'   => ['Customer & trade account admin', 1],
        'pricing'     => ['Cart & pricing', 1],
        'checkout'    => ['Checkout & payments', 1],
        'orders'      => ['Orders, POS & after-sales', 1],
        'wallet'      => ['Wallet, gift cards & loyalty', 1],
        'promotions'  => ['Promotions', 1],
        'money'       => ['Tax, shipping & payment settings', 1],
        'catalogue'   => ['Catalogue & browsing', 2],
        'procurement' => ['Procurement & suppliers', 2],
        'dashboard'   => ['Dashboard & reports', 2],
        'settings'    => ['Store settings & system', 2],
        'content'     => ['Content & CMS', 3],
        'engagement'  => ['Reviews, messages & newsletter', 3],
        'sellers'     => ['Sellers, stores & withdrawals', 3],
        'platform'    => ['Installer, OAuth & framework', 3],
    ],

    /*
     * URL prefixes checked before the controller map, for endpoints that share
     * a storefront method but belong elsewhere: the admin cart is the POS basket.
     */
    'uris' => [
        'api/admin/pos-order/' => 'orders',
        'api/admin/cart/' => 'orders',
    ],

    /*
     * Routes that live under an admin or customer URL but must answer someone
     * who is not signed in yet: the sign-in, sign-up and recovery steps
     * themselves, and what the admin login page loads. Anything else under
     * api/admin without admin auth, or api/v1/user without customer auth, is
     * listed in the checklist's access review.
     *
     * Not listed on purpose: api/admin/signup. It is open, and it creates a
     * working admin account (see tests/FINDINGS.md).
     */
    'public_by_design' => [
        'api/admin/login',
        'api/admin/forgot-password',
        'api/admin/verify-code',
        'api/admin/languages',
        'api/admin/localization',
        'api/v1/user/signin',
        'api/v1/user/signup',
        'api/v1/user/verify',
        'api/v1/user/forgot-password',
        'api/v1/user/reset-password',
        'api/v1/user/reset-password/check',
        'api/v1/user/update-password',        // the reset step: requires the emailed code
        'api/v1/user/login-code',
        'api/v1/user/login-code/verify',
        'api/v1/user/two-factor/challenge',
        'api/v1/user/social-login/redirect/{service}',
        'api/v1/user/social-login/callback/{service}',
    ],

    'controllers' => [
        // --- methods of controllers that span several modules -------------
        'FrontendController@sessions' => 'accounts',
        'FrontendController@logoutDevice' => 'accounts',
        'FrontendController@logoutOtherDevices' => 'accounts',
        'FrontendController@businessProduct' => 'pricing',
        'FrontendController@qualifyingProducts' => 'pricing',
        'FrontendController@paymentGateway' => 'checkout',
        'FrontendController@trackOrder' => 'orders',
        'FrontendController@getGiftVouchers' => 'wallet',
        'FrontendController@clearance' => 'promotions',
        'FrontendController@flashSale' => 'promotions',
        'FrontendController@bundleDeals' => 'promotions',
        'FrontendController@crossSellsForCart' => 'promotions',
        'FrontendController@crossSellsForProduct' => 'promotions',
        'FrontendController@productCrossellUpsell' => 'promotions',
        'FrontendController@page' => 'content',
        'FrontendController@common' => 'content',
        'FrontendController@localization' => 'content',
        'FrontendController@onboarding' => 'content',
        'FrontendController@authBanners' => 'content',
        'FrontendController@contactUs' => 'engagement',
        'FrontendController@store' => 'sellers',
        'FrontendController@localizationAdmin' => 'platform',
        'FrontendController@resource' => 'platform',
        'FrontendController@countriesPhones' => 'platform',
        'FrontendController@towns' => 'platform',
        'FrontendController' => 'catalogue',

        'UsersController@all' => 'customers',
        'UsersController@find' => 'customers',
        'UsersController@delete' => 'customers',
        'UsersController@status' => 'customers',
        'UsersController@verification' => 'customers',
        'UsersController@vouchers' => 'wallet',
        'UsersController@sendCheckoutCode' => 'checkout',
        'UsersController@verifyCheckoutCode' => 'checkout',
        'UsersController' => 'accounts',

        'AdminsController@dashboard' => 'dashboard',
        'AdminsController@activity' => 'dashboard',
        'AdminsController@statistic' => 'dashboard',
        'AdminsController@lowStockProducts' => 'dashboard',
        'AdminsController@activate' => 'settings',
        'AdminsController@deactivate' => 'settings',
        'AdminsController@manualActivation' => 'settings',
        'AdminsController@clearCache' => 'settings',
        'AdminsController' => 'staff',

        'OrdersController@action' => 'checkout',          // storefront place-order; admin POS resolves below
        'OrdersController@transaction' => 'checkout',
        'OrdersController@paymentDone' => 'checkout',
        'OrdersController@payFastNotify' => 'checkout',
        'OrdersController' => 'orders',

        'SettingsController@getSumup' => 'money',
        'SettingsController@updateSumup' => 'money',
        'SettingsController@currency' => 'money',
        'SettingsController' => 'settings',

        'GuestUsersController@issueToken' => 'accounts',
        'GuestUsersController@sendCheckoutCode' => 'checkout',
        'GuestUsersController@verifyCheckoutCode' => 'checkout',
        'GuestUsersController' => 'customers',

        // --- whole controllers ---------------------------------------------
        'TwoFactorController' => 'accounts',
        'TradeApplicationsController' => 'customers',
        'RolesController' => 'staff',

        'CartsController' => 'pricing',
        'BusinessProductsController' => 'pricing',
        'ProductUpgradesController' => 'pricing',
        'ProductUpgradeConfigController' => 'pricing',

        'SumupPaymentController' => 'checkout',
        'IyzicoPaymentController' => 'checkout',

        'PosOrdersController' => 'orders',
        'CancellationsController' => 'orders',
        'InvoiceController' => 'orders',

        'WalletController' => 'wallet',
        'WalletOverviewController' => 'wallet',
        'GiftVoucherController' => 'wallet',
        'GiftCardSalesController' => 'wallet',
        'LoyaltyController' => 'wallet',
        'LoyaltyGroupController' => 'wallet',

        'FlashSalesController' => 'promotions',
        'FlashDiscountController' => 'promotions',
        'VouchersController' => 'promotions',
        'BundleDealsController' => 'promotions',
        'ClearancesController' => 'promotions',
        'CrossSellsController' => 'promotions',
        'UpsellCampaignsController' => 'promotions',
        'UpsellController' => 'promotions',

        'TaxRulesController' => 'money',
        'ShippingRulesController' => 'money',
        'PaymentsController' => 'money',

        'ProductsController' => 'catalogue',
        'UpdatedInventoriesController' => 'catalogue',
        'AttributesController' => 'catalogue',
        'ProductSpecificationController' => 'catalogue',
        'ProductImageAttributesController' => 'catalogue',
        'ProductTemplateCustomizationController' => 'catalogue',
        'ImagesController' => 'catalogue',
        'BulkController' => 'catalogue',
        'TagsController' => 'catalogue',
        'CategoriesController' => 'catalogue',
        'SubCategoriesController' => 'catalogue',
        'BrandsController' => 'catalogue',
        'ProductCollectionsController' => 'catalogue',
        'RecentlyViewedController' => 'catalogue',
        'CompareListsController' => 'catalogue',
        'UserWishlistsController' => 'catalogue',
        'WishlistShareController' => 'catalogue',
        'SaveForLaterController' => 'catalogue',
        'UserFollowStoreController' => 'catalogue',

        'SupplierController' => 'procurement',
        'BidController' => 'procurement',

        'SiteSettingController' => 'settings',
        'PosSettingsController' => 'settings',
        'LanguageController' => 'settings',
        'PluginsController' => 'settings',
        'CustomScriptsController' => 'settings',
        'OnboardingController' => 'settings',
        'SubscriptionEmailFormatsController' => 'settings',
        'MailPreviewController' => 'settings',

        'PagesController' => 'content',
        'PageWysiwygImagesController' => 'content',
        'WysiwygImagesController' => 'content',
        'FeatureWysiwygImageController' => 'content',
        'HomeSlidersController' => 'content',
        'BannersController' => 'content',
        'AuthBannersController' => 'content',
        'FooterLinksController' => 'content',
        'FooterImageLinksController' => 'content',
        'HeaderLinksController' => 'content',
        'SiteFeaturesController' => 'content',
        'TemplateController' => 'content',

        'RatingReviewsController' => 'engagement',
        'ContactUsController' => 'engagement',
        'SubscriptionEmailsController' => 'engagement',
        'NewsletterSubscriptionController' => 'engagement',

        'SellerController' => 'sellers',
        'StoreController' => 'sellers',
        'WithdrawalsController' => 'sellers',
        'WithdrawalAccountsController' => 'sellers',

        'InstallController' => 'platform',
        'Closure' => 'platform',
        'Laravel\Passport' => 'platform',
    ],

    /*
     * Pages, by path prefix inside each panel's pages/ folder. The longest
     * matching prefix wins; '' is the fallback.
     */
    'pages' => [
        'storefront' => [
            '' => 'catalogue',
            'cart' => 'pricing',
            'checkout' => 'checkout', 'payment' => 'checkout', 'guest-checkout' => 'checkout',
            'shipping' => 'checkout', 'payfast/' => 'checkout', 'razorpay-callback' => 'checkout',
            'track-order' => 'orders', 'user/order' => 'orders',
            'login' => 'accounts', 'register' => 'accounts', 'forgot-password' => 'accounts',
            'reset-password' => 'accounts', 'social-callback' => 'accounts',
            'user/profile' => 'accounts', 'user/addresses' => 'accounts', 'user/dashboard' => 'accounts',
            'user/vouchers' => 'wallet', 'user/topup-checkout' => 'wallet', 'user/savers-club' => 'wallet',
            'clearance' => 'promotions', 'flash-sale/' => 'promotions',
            'page/' => 'content',
            'seller/' => 'sellers', 'shop/' => 'sellers',
            'supplier/' => 'procurement',
        ],
        'admin' => [
            '' => 'settings',
            'index' => 'dashboard', 'inventory-alerts/' => 'dashboard', 'wallet-overview/' => 'wallet',
            'login' => 'staff', 'forgot-password' => 'staff', 'verify-code' => 'staff', 'profile' => 'staff',
            'admins-vendors/' => 'staff', 'roles-permissions/' => 'staff',
            'registered-users/' => 'customers', 'guest-users/' => 'customers', 'trade-applications/' => 'customers',
            'orders/' => 'orders', 'pos/' => 'orders',
            'business-products/' => 'pricing',
            'gift-cards/' => 'wallet', 'gift-vouchers/' => 'wallet', 'loyalty-groups/' => 'wallet',
            'flash-sales/' => 'promotions', 'flash-discount/' => 'promotions', 'vouchers/' => 'promotions',
            'bundle-deals/' => 'promotions', 'clearances/' => 'promotions', 'cross-sell/' => 'promotions',
            'upsell/' => 'promotions',
            'tax-rules/' => 'money', 'shipping-rules/' => 'money', 'setting/payment' => 'money',
            'setting/currency' => 'money',
            'products/' => 'catalogue', 'attributes/' => 'catalogue', 'categories/' => 'catalogue',
            'sub-categories/' => 'catalogue', 'brands/' => 'catalogue', 'product-collections/' => 'catalogue',
            'bulk-product-editor/' => 'catalogue', 'bulk-upload/' => 'catalogue', 'images/' => 'catalogue',
            'procurement/' => 'procurement',
            'pages/' => 'content', 'home-slider/' => 'content', 'banners/' => 'content',
            'auth-banners/' => 'content', 'footer-links/' => 'content', 'header-links/' => 'content',
            'site-features/' => 'content', 'templates/' => 'content',
            'rating-reviews/' => 'engagement', 'user-messages/' => 'engagement', 'subscribers/' => 'engagement',
            'store' => 'sellers', 'withdrawal-accounts/' => 'sellers', 'withdrawal-requests/' => 'sellers',
        ],
    ],
];
