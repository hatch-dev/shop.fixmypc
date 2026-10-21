<?php

use App\Models\Helper\MailHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use App\Models\Order;
use App\Models\Plugin;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminsController;
use App\Http\Controllers\GiftVoucherController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\BrandsController;
use App\Http\Controllers\SubCategoriesController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\AttributesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PagesController;
use App\Http\Controllers\FooterLinksController;
use App\Http\Controllers\FooterImageLinksController;
use App\Http\Controllers\HomeSlidersController;
use App\Http\Controllers\FlashSalesController;
use App\Http\Controllers\UpsellController;
use App\Http\Controllers\UpsellCampaignsController;
use App\Http\Controllers\CrossSellsController;
use App\Http\Controllers\ProductUpgradesController;
use App\Http\Controllers\ProductUpgradeConfigController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TaxRulesController;
use App\Http\Controllers\ShippingRulesController;
use App\Http\Controllers\WysiwygImagesController;
use App\Http\Controllers\FrontendController;
use App\Http\Controllers\ProductCollectionsController;
use App\Http\Controllers\VouchersController;
use App\Http\Controllers\BundleDealsController;
use App\Http\Controllers\ClearancesController;
use App\Http\Controllers\CartsController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\RatingReviewsController;
use App\Http\Controllers\UserWishlistsController;
use App\Http\Controllers\PaymentsController;
use App\Http\Controllers\CancellationsController;
use App\Http\Controllers\OrderReturnsController;
use App\Http\Controllers\ContactUsController;
use App\Http\Controllers\PageWysiwygImagesController;
use App\Http\Controllers\UpdatedInventoriesController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\WithdrawalAccountsController;
use App\Http\Controllers\WithdrawalsController;
use App\Http\Controllers\AuthBannersController;
use App\Http\Controllers\TradeApplicationsController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\AccountUpgradesController;
use App\Http\Controllers\BannersController;
use App\Http\Controllers\SubscriptionEmailsController;
use App\Http\Controllers\SubscriptionEmailFormatsController;
use App\Http\Controllers\CompareListsController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\SiteSettingController;
use App\Http\Controllers\UserFollowStoreController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\IyzicoPaymentController;
use App\Http\Controllers\GuestUsersController;
use App\Http\Controllers\HeaderLinksController;
use App\Http\Controllers\BulkController;
use App\Http\Controllers\ImagesController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SiteFeaturesController;
use App\Http\Controllers\FeatureWysiwygImageController;
use App\Http\Controllers\CustomScriptsController;
use App\Http\Controllers\ProductImageAttributesController;
use App\Http\Controllers\PosSettingsController;
use App\Http\Controllers\PosOrdersController;
use App\Http\Controllers\PluginsController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\ProductTemplateCustomizationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SumupPaymentController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\LoyaltyGroupController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MembershipMemberController;
use App\Http\Controllers\MembershipPlanController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\BusinessProductsController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\RecentlyViewedController;
use App\Http\Controllers\FlashDiscountController;
use App\Http\Controllers\WalletOverviewController;
use App\Http\Controllers\SaveForLaterController;
use App\Http\Controllers\ProductSpecificationController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use App\Services\TwilioService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/*Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});*/

Route::post('/create-sumup-checkout', [SumupPaymentController::class, 'createCheckout']);

/*
 * QA audit SEC-03: /test-mail and /test-otp were removed. Both were reachable
 * without authentication; test-otp sent a Twilio SMS to any number supplied and
 * returned the generated OTP in the response body.
 */

Route::get('getStore', [StoreController::class, 'getStore']);

/*
 * The product picker on Rating & Reviews. It sat above the admin guard, so
 * the whole catalogue - unpublished products included - was readable by
 * anyone, and Product's three $appends made it 372 queries for 122 rows
 * (QA audit). Admin-only now, and gated on product.view like every other
 * catalogue read.
 */
Route::middleware(['auth:admin', 'scope:admin'])
    ->get('products/all-simple', [ProductsController::class, 'allSimple']);
Route::get('templates/all', [TemplateController::class, 'allSimple']);

// Read-only catalogue lookups stay public; everything that writes moves behind
// the admin guard below (QA audit SEC-03).
Route::get('templates', [TemplateController::class, 'index']);
Route::get('templates/{template}', [TemplateController::class, 'show']);

Route::get('products/{productId}/customizations', [ProductTemplateCustomizationController::class, 'index']);

Route::group([
    'middleware' => ['auth:admin', 'scope:admin']
], function () {
    Route::post('admin/products/{product}/duplicate', [ProductsController::class, 'duplicate']);

    Route::post('templates', [TemplateController::class, 'store']);
    Route::put('templates/{template}', [TemplateController::class, 'update']);
    Route::patch('templates/{template}', [TemplateController::class, 'update']);
    Route::delete('templates/{template}', [TemplateController::class, 'destroy']);

    Route::post('products/{productId}/customizations/bulk', [ProductTemplateCustomizationController::class, 'bulkStore']);
});

Route::get('/upsells/active', [UpsellController::class, 'getActiveUpsells']);
Route::get('/upsells/{upsell_id}/products', [UpsellController::class, 'findProducts']);

Route::get('inventory/find/{productId}', [UpdatedInventoriesController::class, 'byProduct']);
		
// Route::get('/clear-cache', function() {
//     Artisan::call('config:cache');
//     Artisan::call('config:clear');
//     Artisan::call('route:cache');
//     Artisan::call('route:clear');
//     Artisan::call('cache:clear');
//     return "Cache is Cleared";
// });

// QA audit SEC-03: cache clearing now requires an admin token. The authenticated
// equivalent already exists at POST /api/admin/clear-cache.
Route::middleware(['auth:admin', 'scope:admin'])->get('/clear-cache', function () {
    Artisan::call('optimize:clear');
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    return response()->json([
        'success' => true,
        'message' => 'Application cache cleared successfully.'
    ]);
});

// Route::post('/admin/clear-cache', function() {
   //  Artisan::call('config:cache');
   //  Artisan::call('config:clear');
   //  Artisan::call('route:cache');
   //  Artisan::call('route:clear');
   //  Artisan::call('cache:clear');
   //  return "Cache is cleared";
// });   

/*
 * QA audit SEC-03. These run migrations, seeders and .env rewrites against the
 * live database, so they are no longer reachable from the open internet.
 *
 * 'installer' (App\Http\Middleware\InstallerOnly) admits them only while the
 * app is genuinely uninstalled — SHARED_SERVER_CONFIGURED is false — and blocks
 * every one of them once setup has completed. Post-install maintenance is an
 * artisan job on the server, not an HTTP endpoint.
 */
Route::middleware('installer')->group(function () {
    Route::post('install', [InstallController::class, 'installPost']);
    Route::post('check-db', [InstallController::class, 'checkDb']);
    Route::get('fresh-migration', [InstallController::class, 'freshMigration']);
    Route::get('create-user', [InstallController::class, 'createUser']);
    Route::get('migration', [InstallController::class, 'migration']);
    Route::get('update-env', [InstallController::class, 'updateEnv']);
    Route::get('read-update-log', [InstallController::class, 'readUpdateLog']);
});

Route::middleware(['auth:admin', 'scope:admin'])
    ->get('deactivate', [AdminsController::class, "deactivate"]);
/*
 * QA audit SEC-03: the /nefedfsrgw debug route was removed. It rendered a
 * hard-coded order as HTML/PDF to any unauthenticated caller.
 */



Route::post('check-order', [OrdersController::class, 'checkOrder']);
		
Route::group([
    'prefix' => 'admin'
], function () {
    Route::post('login', [AdminsController::class, 'login']);
    Route::post('signup', [AdminsController::class, 'signup']);
    Route::post('forgot-password', [AdminsController::class, 'forgotPassword']);
    Route::post('verify-code', [AdminsController::class, 'verifyCode']);

    Route::get('languages', [LanguageController::class, "languages"]);
    Route::get('localization', [FrontendController::class, "localizationAdmin"]);
    Route::get('resource/{name}', [FrontendController::class, "resource"]);
    Route::get('countries-phones', [FrontendController::class, "countriesPhones"]);


    Route::group([
        'middleware' => ['auth:admin','scope:admin']
    ], function (){

        /*
         * QA audit SEC-03: onboarding, flash-discount and wallet-overview used
         * to sit outside this group. Creating and deleting store-wide discounts,
         * and reading wallet balances, needed no credentials at all.
         */
        Route::get('onboarding', [OnboardingController::class, 'index']);
        Route::get('onboarding/{id}', [OnboardingController::class, 'show']);
        Route::post('onboarding', [OnboardingController::class, 'store']);

        Route::prefix('flash-discount')->group(function () {
            Route::get('all', [FlashDiscountController::class, 'all']);
            Route::get('find/{id}', [FlashDiscountController::class, 'find']);
            Route::post('action/{id?}', [FlashDiscountController::class, 'action']);
            Route::delete('delete/{id}', [FlashDiscountController::class, 'delete']);
        });

        Route::prefix('wallet-overview')->group(function () {
            Route::get('all', [WalletOverviewController::class, 'all']);
        });

        Route::get('deactivate', [AdminsController::class, "deactivate"]);
        Route::post('activate', [AdminsController::class, "activate"]);
        Route::post('manual-activation', [AdminsController::class, "manualActivation"]);
        Route::get('logout', [AdminsController::class, 'logout']);
        Route::get('profile', [AdminsController::class, 'profile']);
        Route::get('dashboard', [AdminsController::class, 'dashboard']);
        Route::get('activity', [AdminsController::class, 'activity']);
        Route::get('order-statistic', [AdminsController::class, 'statistic']);
        Route::get('low-stock-products', [AdminsController::class, 'lowStockProducts']);
        Route::post('update', [AdminsController::class, 'update']);
        Route::post('update-profile-image', [AdminsController::class, 'updateProfileImage']);
        Route::post('update-password', [AdminsController::class, 'updatePassword']);

        Route::post('clear-cache', [AdminsController::class, 'clearCache']);

        Route::prefix('gift-voucher')->group(function () {
            Route::get('all', [GiftVoucherController::class, 'index']);
            Route::get('find/{id}', [GiftVoucherController::class, 'show']);
            Route::post('action/{id?}', [GiftVoucherController::class, 'store']);
            Route::delete('delete/{id}', [GiftVoucherController::class, 'delete']);
        });

        // Gift card sales: every card sold, resend its email, void it.
        Route::prefix('gift-card')->group(function () {
            Route::get('all', [\App\Http\Controllers\GiftCardSalesController::class, 'all']);
            Route::post('resend/{id?}', [\App\Http\Controllers\GiftCardSalesController::class, 'resend']);
            Route::post('void/{id?}', [\App\Http\Controllers\GiftCardSalesController::class, 'void']);
        });

        Route::group([
            'prefix' => 'admin-data'
        ], function (){
            Route::get('all', [AdminsController::class, 'all']);
            Route::get('find/{id}', [AdminsController::class, 'find']);
            Route::post('action/{admin?}', [AdminsController::class, 'action']);
            Route::delete('delete/{id}', [AdminsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'bulk'
        ], function (){
            Route::get('export', [BulkController::class, 'exportData']);
            Route::post('import', [BulkController::class, 'importData']);
        });

        Route::group([
            'prefix' => 'plugin'
        ], function (){
            Route::get('all', [PluginsController::class, 'all']);
            Route::post('activate', [PluginsController::class, 'activate']);
            Route::post('upload', [PluginsController::class, 'upload']);
            Route::delete('delete/{id}', [PluginsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'images'
        ], function (){
            Route::get('all', [ImagesController::class, 'all']);
            Route::post('upload', [ImagesController::class, 'upload']);
            Route::delete('delete/{image}', [ImagesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'role'
        ], function (){
            Route::get('all-permissions', [RolesController::class, 'allPermissions']);
            Route::get('all-roles', [RolesController::class, 'allRoles']);
            Route::get('all', [RolesController::class, 'all']);
            Route::get('find/{id}', [RolesController::class, 'find']);
            Route::post('action/{role?}', [RolesController::class, 'action']);
            Route::delete('delete/{id}', [RolesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'category'
        ], function (){
            Route::get('all', [CategoriesController::class, 'all']);
            Route::get('all-categories', [CategoriesController::class, 'allCategories']);
            Route::get('find/{id}', [CategoriesController::class, 'find']);
            Route::post('action/{id?}', [CategoriesController::class, 'action']);
            Route::delete('delete/{id}', [CategoriesController::class, 'delete']);
            Route::post('upload/{id?}', [CategoriesController::class, 'upload']);
            Route::post('update-parent', [CategoriesController::class, 'updateParent']);
            /*
             * Tells the form whether a slug is free before the admin fills in
             * the rest of it — the field used to render a hard-coded "Slug is
             * available" that checked nothing.
             */
            Route::get('slug-available', [CategoriesController::class, 'slugAvailable']);
            /*
             * What a delete would take with it, so the confirmation can say so.
             * Gated on category.delete, like the delete itself.
             */
            Route::get('delete-impact/{id}', [CategoriesController::class, 'deleteImpact']);
            // Publish/unpublish without saving the whole record. category.edit.
            Route::post('update-status', [CategoriesController::class, 'updateStatus']);
        });

        Route::group([
            'prefix' => 'subcategory'
        ], function (){
            Route::get('all', [SubCategoriesController::class, 'all']);
            Route::get('all-subcategories', [SubCategoriesController::class, 'allSubCategories']);
            Route::get('find/{id}', [SubCategoriesController::class, 'find']);
            Route::post('action/{id?}', [SubCategoriesController::class, 'action']);
            Route::delete('delete/{id}', [SubCategoriesController::class, 'delete']);
            Route::post('upload/{id?}', [SubCategoriesController::class, 'upload']);
        });

        Route::group([
            'prefix' => 'brand'
        ], function (){
            Route::get('all', [BrandsController::class, 'all']);
            Route::get('all-brands', [BrandsController::class, 'allBrands']);
            Route::get('find/{id}', [BrandsController::class, 'find']);
            Route::post('action/{id?}', [BrandsController::class, 'action']);
            Route::delete('delete/{id}', [BrandsController::class, 'delete']);
            Route::post('upload/{id?}', [BrandsController::class, 'upload']);
        });

        Route::group([
            'prefix' => 'product'
        ], function (){
            Route::get('all', [ProductsController::class, 'all']);
            Route::get('find/{id}', [ProductsController::class, 'find']);
            Route::get('dropdown-data', [ProductsController::class, 'dropDownData']);
            Route::post('action/{product?}', [ProductsController::class, 'action']);
            Route::delete('delete/{id}', [ProductsController::class, 'delete']);
            Route::post('upload/{id?}', [ProductsController::class, 'upload']);
            Route::post('upload-video/{id?}', [ProductsController::class, 'uploadVideo']);
            Route::get('all-images/{productId}', [ProductsController::class, 'allImages']);
            Route::post('upload-images/{productId}', [ProductsController::class, 'multipleImageUpload']);
            Route::delete('delete-image/{productImageId}', [ProductsController::class, 'deleteProductImage']);
            Route::get('with-categories', [ProductsController::class, 'allProductsWithCategory']);
            Route::post('bulk-update', [ProductsController::class, 'bulkUpdate']);
            Route::post('update-status', [ProductsController::class, 'updateStatus']);
            Route::post('set-main-image', [ProductsController::class, 'setMainImage']);
            Route::post('set-variant-images', [ProductsController::class, 'setVariantImages']);

            Route::post('/product-specifications', [ProductSpecificationController::class, 'store']);
            Route::get('/product-specifications/{inventoryId}', [ProductSpecificationController::class, 'getByInventory']);

            Route::post('feature-images/{id?}', [ProductsController::class, 'setProductFeatureImages']);

        });

        Route::group([
            'prefix' => 'product-image-attributes'
        ], function (){
            Route::post('action', [ProductImageAttributesController::class, 'action']);
        });

        Route::group([
            'prefix' => 'tag'
        ], function (){
            Route::get('all', [TagsController::class, 'all']);
            Route::post('action/{tag?}', [TagsController::class, 'action']);
        });

        Route::group([
            'prefix' => 'attribute'
        ], function (){
            Route::get('all', [AttributesController::class, 'all']);
            Route::get('all-attributes', [AttributesController::class, 'allAttributes']);
            Route::get('find/{id}', [AttributesController::class, 'find']);
            Route::post('action/{id?}', [AttributesController::class, 'action']);
            Route::delete('delete/{id}', [AttributesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'cart',
        ], function (){
            Route::get('by-user', [CartsController::class, "byUser"]);
            Route::post('action', [CartsController::class, "action"]);
            Route::delete('delete/{id}', [CartsController::class, 'delete']);
            Route::post('update-shipping', [CartsController::class, 'updateShipping']);
            Route::post('shipping-quote', [CartsController::class, 'shippingQuote']);
        });

        Route::group([
            'prefix' => 'updated-inventory'
        ], function (){
            Route::get('find/{productId}', [UpdatedInventoriesController::class, 'byProduct']);
            Route::post('action/{productId}', [UpdatedInventoriesController::class, 'action']);
        });

        Route::group([
            'prefix' => 'setting'
        ], function (){
            Route::get('find', [SettingsController::class, 'find']);
            Route::post('currency', [SettingsController::class, 'currency']);
            Route::post('address', [SettingsController::class, 'address']);
            Route::post('upload-logo', [SettingsController::class, 'uploadLogo']);
            Route::get('convert-image/{imageName}', [SettingsController::class, 'convert']);

            Route::get('social-login-find', [SettingsController::class, 'socialLoginFind']);
            Route::get('smtp-find', [SettingsController::class, 'smtpFind']);
            Route::get('media-storage-find', [SettingsController::class, 'mediaStorageFind']);

            Route::post('social-login-action', [SettingsController::class, 'socialLoginAction']);
            Route::post('smtp-action', [SettingsController::class, 'smtpAction']);
            Route::post('media-storage-action', [SettingsController::class, 'mediaStorageAction']);

            Route::post('miscellaneous', [SettingsController::class, 'miscellaneous']);
            Route::post('cart-reservation', [SettingsController::class, 'cartReservation']);
            Route::post('analytics', [SettingsController::class, 'analytics']);

            Route::get('sidebar-icons', [SettingsController::class, 'sidebarIconsFind']);
            Route::post('sidebar-icons', [SettingsController::class, 'sidebarIconsAction']);
            Route::post('sidebar-icons-delete', [SettingsController::class, 'sidebarIconsDelete']);

            Route::get('aftership', [SettingsController::class, 'aftershipFind']);
            Route::post('aftership', [SettingsController::class, 'aftershipAction']);
            Route::post('aftership-test', [SettingsController::class, 'aftershipTest']);

            Route::get('sumup', [SettingsController::class, 'getSumup']);
            Route::post('sumup', [SettingsController::class, 'updateSumup']);
        });

        Route::group([
            'prefix' => 'site-setting'
        ], function (){
            Route::get('find', [SiteSettingController::class, 'find']);
            Route::post('upload', [SiteSettingController::class, 'upload']);
            Route::post('action', [SiteSettingController::class, 'action']);
        });

        Route::group([
            'prefix' => 'store'
        ], function (){
            Route::get('find', [StoreController::class, 'find']);
            Route::post('action', [StoreController::class, 'action']);
            Route::post('upload-logo', [StoreController::class, 'uploadLogo']);
        });

        Route::group([
            'prefix' => 'pos-setting'
        ], function (){
            Route::get('find', [PosSettingsController::class, 'find']);
            Route::post('action', [PosSettingsController::class, 'action']);
            Route::post('upload', [PosSettingsController::class, 'upload']);
            Route::delete('delete', [PosSettingsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'pos-order'
        ], function (){
            Route::get('all', [PosOrdersController::class, 'all']);
            Route::post('action', [PosOrdersController::class, "action"]);
            Route::delete('delete/{id}', [PosOrdersController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'withdrawal-account'
        ], function (){
            Route::get('all', [WithdrawalAccountsController::class, 'all']);
            Route::get('find/{id}', [WithdrawalAccountsController::class, 'find']);
            Route::post('action/{withdrawalAccount?}', [WithdrawalAccountsController::class, 'action']);
            Route::delete('delete/{id}', [WithdrawalAccountsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'withdrawal-request'
        ], function (){
            Route::get('all', [WithdrawalsController::class, 'all']);
            Route::get('find', [WithdrawalsController::class, 'find']);
            Route::post('withdraw', [WithdrawalsController::class, 'withdrawMoney']);
            Route::post('cancel', [WithdrawalsController::class, 'withdrawCancel']);
            Route::post('approve', [WithdrawalsController::class, 'withdrawApprove']);
            Route::delete('delete/{id}', [WithdrawalsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'payment'
        ], function (){
            Route::get('find', [PaymentsController::class, 'find']);
            Route::post('save', [PaymentsController::class, 'save']);
        });

        Route::group([
            'prefix' => 'user'
        ], function (){
            Route::get('all', [UsersController::class, 'all']);
            // One customer with their business/trade details, for the screen
            // that has to decide whether to suspend or verify them.
            Route::get('find/{id}', [UsersController::class, 'find']);
            // Suspend / reactivate. Separate from the trade decision on purpose.
            Route::post('status/{id}', [UsersController::class, 'status']);
            // Mark a business account's company details checked.
            Route::post('verification/{id}', [UsersController::class, 'verification']);
            Route::delete('delete/{id}', [UsersController::class, 'delete']);


            Route::group([
                'prefix' => 'address',
            ], function (){
                Route::get('all', [UsersController::class, "addresses"]);
                Route::post('action', [UsersController::class, "addressAction"]);
                Route::delete('delete/{id}', [UsersController::class, "deleteAddress"]);
            });
        });


        Route::group([
            'prefix' => 'guest-user'
        ], function (){
            Route::get('all', [GuestUsersController::class, 'all']);
            Route::delete('delete/{id}', [GuestUsersController::class, 'delete']);
        });



        Route::group([
            'prefix' => 'subscriber'
        ], function (){
            Route::get('all', [SubscriptionEmailsController::class, 'all']);
            Route::delete('delete/{id}', [SubscriptionEmailsController::class, 'delete']);
            Route::get('all-subscribers', [SubscriptionEmailsController::class, 'allSubscribers']);
            Route::post('send-subscription-email', [SubscriptionEmailsController::class, 'sendSubscriptionEmail']);
        });

        Route::group([
            'prefix' => 'subscription-email-format'
        ], function (){
            Route::get('all-subscription-email-formats', [SubscriptionEmailFormatsController::class, 'allEmailFormats']);
            Route::get('all', [SubscriptionEmailFormatsController::class, 'all']);
            Route::get('find/{id}', [SubscriptionEmailFormatsController::class, 'find']);
            Route::post('action/{subscriptionEmailFormat?}', [SubscriptionEmailFormatsController::class, 'action']);
            Route::delete('delete/{id}', [SubscriptionEmailFormatsController::class, 'delete']);
        });


        Route::group([
            'prefix' => 'user-message'
        ], function (){
            Route::get('all', [ContactUsController::class, 'all']);
            Route::get('find/{id}', [ContactUsController::class, 'find']);
            Route::post('action/{contactUs?}', [ContactUsController::class, 'action']);
            Route::delete('delete/{id}', [ContactUsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'page'
        ], function (){
            Route::get('all', [PagesController::class, 'all']);
            Route::get('all-pages', [PagesController::class, 'allPages']);
            Route::post('action/{id?}', [PagesController::class, 'action']);
            Route::delete('delete/{id}', [PagesController::class, 'delete']);
            Route::get('find/{id}', [PagesController::class, 'find']);
        });

        Route::group([
            'prefix' => 'footer-link'
        ], function (){
            Route::get('all', [FooterLinksController::class, 'all']);
            Route::post('payment-social-action/{footerLink?}', [FooterLinksController::class, 'action']);
            Route::post('service-about-action', [FooterLinksController::class, 'serviceAndAboutAction']);
            Route::delete('delete/{id}', [FooterLinksController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'header-link'
        ], function (){
            Route::get('all', [HeaderLinksController::class, 'all']);
            Route::post('action', [HeaderLinksController::class, 'action']);
        });

        Route::group([
            'prefix' => 'footer-image-link'
        ], function (){
            Route::get('all', [FooterImageLinksController::class, 'all']);
            Route::get('find/{id}', [FooterImageLinksController::class, 'find']);
            Route::post('action/{footerImageLink?}', [FooterImageLinksController::class, 'action']);
            Route::delete('delete/{id}', [FooterImageLinksController::class, 'delete']);
            Route::post('image/{id?}', [FooterImageLinksController::class, 'upload']);
        });

        Route::group([
            'prefix' => 'home-slider-image'
        ], function (){
            Route::get('all', [HomeSlidersController::class, 'all']);
            Route::get('find/{id}', [HomeSlidersController::class, 'find']);
            Route::post('action/{id?}', [HomeSlidersController::class, 'action']);
            Route::delete('delete/{id}', [HomeSlidersController::class, 'delete']);
            Route::post('image/{id?}', [HomeSlidersController::class, 'upload']);
            Route::post('upload/{id?}', [HomeSlidersController::class, 'upload']);
        });

        Route::group([
            'prefix' => 'site-feature'
        ], function (){
            Route::get('all', [SiteFeaturesController::class, 'all']);
            Route::get('find/{id}', [SiteFeaturesController::class, 'find']);
            Route::post('action/{id?}', [SiteFeaturesController::class, 'action']);
            Route::post('upload/{id?}', [SiteFeaturesController::class, 'upload']);
            Route::delete('delete/{id}', [SiteFeaturesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'trade-application'
        ], function (){
            Route::get('all', [TradeApplicationsController::class, 'all']);
            Route::get('find/{id}', [TradeApplicationsController::class, 'find']);
            Route::post('action/{id}', [TradeApplicationsController::class, 'action']);

            // Trade applications from existing personal/business accounts.
            // The account type only changes when one of these is approved.
            Route::get('upgrades', [TradeApplicationsController::class, 'upgrades']);
            Route::get('upgrades/{id}', [TradeApplicationsController::class, 'upgrade']);
            // action/{id} rather than {id}/action: the admin store builds "<api>/<id>".
            Route::post('upgrades/action/{id}', [TradeApplicationsController::class, 'upgradeAction']);
        });

        Route::group([
            'prefix' => 'auth-banner'
        ], function (){
            Route::get('all', [AuthBannersController::class, 'all']);
            Route::get('links', [AuthBannersController::class, 'links']);
            Route::get('find/{id}', [AuthBannersController::class, 'find']);
            Route::post('action/{id?}', [AuthBannersController::class, 'action']);
            Route::post('upload/{id?}', [AuthBannersController::class, 'upload']);
            Route::post('reorder', [AuthBannersController::class, 'reorder']);
            Route::delete('delete/{id}', [AuthBannersController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'banner'
        ], function (){
            Route::get('all', [BannersController::class, 'all']);
            Route::get('find/{id}', [BannersController::class, 'find']);
            Route::post('action/{banner?}', [BannersController::class, 'action']);
            Route::post('image/{id?}', [BannersController::class, 'upload']);
            Route::post('upload/{id?}', [BannersController::class, 'upload']);
        });

        Route::group([
            'prefix' => 'flash-sale'
        ], function (){
            Route::get('all', [FlashSalesController::class, 'all']);
            Route::post('action/{id?}', [FlashSalesController::class, 'action']);
            Route::get('find/{id}', [FlashSalesController::class, 'find']);
            Route::get('product-tiers/{id}', [FlashSalesController::class, 'productTiers']);
            Route::get('product-meta', [FlashSalesController::class, 'productMeta']);
            Route::delete('delete/{id}', [FlashSalesController::class, 'delete']);
        });
		
        /*
         * The legacy upsell group CRUD was removed with the Upsell rebuild.
         * products.upsell_id survives because the storefront reads it as the
         * CROSS-SELL group: the product page's "goes well with" block and the
         * basket's add-these-too prompt both come from it. Those offer an
         * ADDITIONAL product rather than a replacement, so they are cross-sell
         * under the Upsell definition and retire with cross-sell, not here.
         */

        /*
         * Promotions -> Product Recommendations -> Upsell.
         *
         * Admin only, on purpose. The storefront gets its own endpoints once
         * this workflow is signed off; until then nothing here is reachable
         * without an admin session and an upsell.* permission, which every
         * action checks for itself.
         */
        Route::group([
            'prefix' => 'upsell'
        ], function (){
            Route::get('all', [UpsellCampaignsController::class, 'all']);
            // The catalogue, for the two product-picking steps.
            Route::get('products', [UpsellCampaignsController::class, 'searchProducts']);
            Route::get('find/{id}', [UpsellCampaignsController::class, 'find']);
            Route::post('action/{id?}', [UpsellCampaignsController::class, 'action']);
            Route::post('toggle', [UpsellCampaignsController::class, 'toggle']);
            Route::post('duplicate/{id}', [UpsellCampaignsController::class, 'duplicate']);
            // Takes a comma-joined list, so a bulk delete is one request.
            Route::delete('delete/{id}', [UpsellCampaignsController::class, 'delete']);
        });

        /*
         * Promotions -> Cross-sell: a named, reusable set of complementary
         * products, gated by crosssell.*. And the assignment of an EXISTING
         * one to a product from Products -> Product -> Cross-sell, gated by
         * product.edit because it is an edit of the product and nothing else.
         * The product page can assign; it cannot create.
         */
        Route::group([
            'prefix' => 'cross-sell'
        ], function (){
            Route::get('all', [CrossSellsController::class, 'all']);
            // The catalogue, for the promotion form's "Add Products" selector.
            Route::get('products', [CrossSellsController::class, 'searchProducts']);
            Route::get('find/{id}', [CrossSellsController::class, 'find']);
            Route::post('action/{id?}', [CrossSellsController::class, 'action']);
            Route::post('toggle', [CrossSellsController::class, 'toggle']);
            // Takes a comma-joined list, so a bulk delete is one request.
            Route::delete('delete/{id}', [CrossSellsController::class, 'delete']);

            // The promotions the "Assign Cross-sell" selector offers.
            Route::get('options', [CrossSellsController::class, 'options']);
            Route::get('assignments/{productId}', [CrossSellsController::class, 'assignments']);
            Route::post('assign/{productId}', [CrossSellsController::class, 'assign']);
            Route::post('assignments/{productId}', [CrossSellsController::class, 'updateAssignments']);
            Route::delete('unassign/{productId}/{id}', [CrossSellsController::class, 'unassign']);
        });

        /*
         * Per-product upgrade configuration. Gated on product.* rather than a
         * permission group of its own: this is part of setting a product up,
         * and anyone who may edit the product may configure its upgrades.
         */
        Route::group([
            'prefix' => 'product-upgrades'
        ], function (){
            Route::get('find/{id}', [ProductUpgradeConfigController::class, 'find']);
            Route::post('action/{id}', [ProductUpgradeConfigController::class, 'action']);
            Route::post('preview/{id}', [ProductUpgradeConfigController::class, 'preview']);
            Route::post('ranks', [ProductUpgradeConfigController::class, 'saveRanks']);
            Route::delete('delete/{id}', [ProductUpgradeConfigController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'loyalty-groups'
        ], function (){
            Route::get('all', [LoyaltyGroupController::class, 'all']);
            Route::post('action/{id?}', [LoyaltyGroupController::class, 'action']);
            Route::get('find/{id}', [LoyaltyGroupController::class, 'find']);
            // Who has spent this group's discount, paginated.
            Route::get('redemptions/{id}', [LoyaltyGroupController::class, 'redemptions']);
            Route::delete('delete/{id}', [LoyaltyGroupController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'membership-plans'
        ], function (){
            Route::get('all', [MembershipPlanController::class, 'all']);
            Route::post('action/{id?}', [MembershipPlanController::class, 'action']);
            Route::get('find/{id}', [MembershipPlanController::class, 'find']);
            Route::post('sync/{id}', [MembershipPlanController::class, 'sync']);
            Route::delete('delete/{id}', [MembershipPlanController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'memberships'
        ], function (){
            Route::get('all', [MembershipMemberController::class, 'all']);
            Route::get('stats', [MembershipMemberController::class, 'stats']);
            Route::get('customers', [MembershipMemberController::class, 'customers']);
            Route::get('find/{id}', [MembershipMemberController::class, 'find']);
            Route::post('action/{id}', [MembershipMemberController::class, 'action']);
            Route::post('grant', [MembershipMemberController::class, 'grant']);
        });

        Route::group([
            'prefix' => 'business-products'
        ], function (){
            Route::get('all', [BusinessProductsController::class, 'all']);
            Route::post('action/{id?}', [BusinessProductsController::class, 'action']);
            Route::get('find/{id}', [BusinessProductsController::class, 'find']);
            Route::delete('delete/{id}', [BusinessProductsController::class, 'delete']);
        });
		
		 Route::group([
            'prefix' => 'templates'
        ], function (){
            Route::get('all', [TemplateController::class, 'all']);
            Route::post('action/{id?}', [TemplateController::class, 'action']);
            Route::get('find/{id}', [TemplateController::class, 'find']);
            Route::delete('delete/{id}', [TemplateController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'tax-rule'
        ], function (){
            Route::get('all', [TaxRulesController::class, 'all']);
            Route::get('all-tax-rules', [TaxRulesController::class, 'allList']);
            Route::get('find/{id}', [TaxRulesController::class, 'find']);
            Route::post('action/{id?}', [TaxRulesController::class, 'action']);
            Route::delete('delete/{id}', [TaxRulesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'voucher'
        ], function (){
            Route::get('all', [VouchersController::class, 'all']);
            Route::get('find/{id}', [VouchersController::class, 'find']);
            Route::post('action/{id?}', [VouchersController::class, 'action']);
            Route::delete('delete/{id}', [VouchersController::class, 'delete']);
            Route::post('validity', [VouchersController::class, 'validity']);
        });

        Route::group([
            'prefix' => 'bundle-deal'
        ], function (){
            Route::get('all', [BundleDealsController::class, 'all']);
            Route::get('all-bundle-deals', [BundleDealsController::class, 'allList']);
            Route::get('find/{id}', [BundleDealsController::class, 'find']);
            Route::post('action/{id?}', [BundleDealsController::class, 'action']);
            Route::delete('delete/{id}', [BundleDealsController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'clearance'
        ], function (){
            Route::get('all', [ClearancesController::class, 'all']);
            Route::get('find/{id}', [ClearancesController::class, 'find']);
            Route::post('action/{id?}', [ClearancesController::class, 'action']);
            Route::delete('delete/{id}', [ClearancesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'shipping-rule'
        ], function (){
            Route::get('all', [ShippingRulesController::class, 'all']);
            Route::get('all-shipping-rules', [ShippingRulesController::class, 'allList']);
            Route::get('find/{id}', [ShippingRulesController::class, 'find']);
            Route::get('coverage', [ShippingRulesController::class, 'coverage']);
            Route::post('action/{id?}', [ShippingRulesController::class, 'action']);
            Route::delete('delete/{id}', [ShippingRulesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'wysiwyg-image'
        ], function (){
            Route::post('upload', [WysiwygImagesController::class, 'upload']);
            Route::delete('delete/{image_name}', [WysiwygImagesController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'page-wysiwyg-image'
        ], function (){
            Route::post('upload', [PageWysiwygImagesController::class, 'upload']);
            Route::delete('delete/{image_name}', [PageWysiwygImagesController::class, 'delete']);
        });


        Route::group([
            'prefix' => 'feature-wysiwyg-image'
        ], function (){
            Route::post('upload', [FeatureWysiwygImageController::class, 'upload']);
            Route::delete('delete/{image_name}', [FeatureWysiwygImageController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'product-collection'
        ], function (){
            Route::get('all', [ProductCollectionsController::class, 'all']);
            Route::get('all-product-collections', [ProductCollectionsController::class, 'allList']);
            Route::get('find/{id}', [ProductCollectionsController::class, 'find']);
            Route::post('action/{id?}', [ProductCollectionsController::class, 'action']);
            Route::delete('delete/{id}', [ProductCollectionsController::class, 'delete']);
            Route::post('upload-image/{id}', [ProductCollectionsController::class, 'image']);
            Route::post('update-sort-order', [ProductCollectionsController::class, 'updateSortOrder']);
        });


        Route::group([
            'prefix' => 'custom-script'
        ], function (){
            Route::get('all', [CustomScriptsController::class, 'all']);
            Route::get('find/{id}', [CustomScriptsController::class, 'find']);
            Route::post('action/{id?}', [CustomScriptsController::class, 'action']);
            Route::delete('delete/{id}', [CustomScriptsController::class, 'delete']);
        });


        Route::group([
            'prefix' => 'order'
        ], function (){
            Route::get('all', [OrdersController::class, 'all']);
            Route::get('vendor-all', [OrdersController::class, 'vendorAll']);
            Route::get('find/{id}', [OrdersController::class, 'find']);
            Route::post('update-status', [OrdersController::class, 'updateStatus']);
            Route::post('payment-status', [OrdersController::class, 'updatePaymentStatus']);
            Route::post('payment-method', [OrdersController::class, 'updatePaymentMethod']);
            Route::delete('delete/{id}', [OrdersController::class, 'delete']);
            Route::get('send-delivered-email/{id}', [OrdersController::class, 'sendDeliveredEmail']);
            // Courier + tracking number, registered with AfterShip.
            Route::post('shipment', [OrdersController::class, 'saveShipment']);
            Route::post('shipment-refresh/{id}', [OrdersController::class, 'refreshShipment']);
            // The couriers activated in the AfterShip account.
            Route::get('shipment-couriers', [OrdersController::class, 'shipmentCouriers']);

        });

        Route::group([
            'prefix' => 'rating-review'
        ], function (){
            Route::get('all', [RatingReviewsController::class, 'all']);
            Route::delete('delete/{id}', [RatingReviewsController::class, 'delete']);
        });
		
	
        Route::group([
            'prefix' => 'cancellation',
        ], function (){
            Route::get('find/{orderId}', [CancellationsController::class, 'find']);
            // QA audit RFN-01: refunding moves money, so it is a POST.
            Route::post('refund/{id}', [CancellationsController::class, 'refund']);
        });

        // Returns, opened by staff against a delivered order (OrderReturnsController).
        Route::group([
            'prefix' => 'order-return',
        ], function (){
            Route::get('all', [OrderReturnsController::class, 'all']);
            Route::get('stats', [OrderReturnsController::class, 'stats']);
            Route::get('find/{id}', [OrderReturnsController::class, 'find']);
            Route::get('order/{orderId}', [OrderReturnsController::class, 'forOrder']);
            // GET: a preview, and a POST through the admin store always toasts "Saved".
            Route::get('quote', [OrderReturnsController::class, 'quote']);
            Route::post('create', [OrderReturnsController::class, 'create']);
            // action/{id}: the admin store builds "<api>/<id>".
            Route::post('action/{id}', [OrderReturnsController::class, 'action']);
        });

        Route::group([
            'prefix' => 'language'
        ], function (){
            Route::get('all', [LanguageController::class, 'all']);
            Route::get('find/{id}', [LanguageController::class, 'find']);
            Route::post('action/{language?}', [LanguageController::class, 'action']);
            Route::delete('delete/{id}', [LanguageController::class, 'delete']);
        });

        Route::group([
            'prefix' => 'suppliers'
        ], function (){
            Route::get('/all', [SupplierController::class, 'all']);
            Route::get('find/{id}', [SupplierController::class, 'find']);

            /*
             * Procurement -> Sourcing: every order with lines still to be
             * sourced, and the quotes on each line. Grouped by order because
             * one basket can hold several procurement products.
             *
             * The two writes act on a single quote, and are gated separately
             * from reading and from each other: choosing a supplier and
             * signing off that they have been paid are different rights.
             */
            Route::get('sourcing', [SupplierController::class, 'sourcing']);
            Route::post('quotes/{id}/decide', [SupplierController::class, 'decideQuote']);
            Route::post('quotes/{id}/pay', [SupplierController::class, 'payQuote']);
            // Fills the categories picker on the supplier form.
            Route::get('categories', [SupplierController::class, 'categories']);
            Route::post('action/{id?}', [SupplierController::class, 'action']);
            Route::delete('delete/{id}', [SupplierController::class, 'delete']);
        });
    });
});


/*
 * Minting a guest token is the one guest route that cannot require a valid one
 * — a first-time visitor, or one holding a stale browser-generated token, has
 * to be able to get a real one (QA audit SEC-02).
 */
Route::post('v1/guest/token', [GuestUsersController::class, 'issueToken']);

/*
 * Stripe's webhook for membership subscriptions. No token of ours: the
 * Stripe-Signature header is checked against the signing secret instead.
 */
Route::post('v1/stripe/webhook', [StripeWebhookController::class, 'handle']);

/*
 * AfterShip's tracking webhook. No token of ours: the aftership-hmac-sha256
 * header is checked against AFTERSHIP_WEBHOOK_SECRET instead.
 */
Route::post('v1/aftership/webhook', [\App\Http\Controllers\AfterShipWebhookController::class, 'handle']);

Route::group([
    'prefix' => 'v1',
    // QA audit SEC-02: guest tokens must be ones the server issued.
    'middleware' => ['optionalAuth', 'session.activity', 'guest.token']
], function (){
    /*
     * Returns and exchanges, the customer's side (CustomerReturnsController).
     * Ownership as the order page: the signed-in owner or the guest token.
     */
    Route::group(['prefix' => 'order-return'], function () {
        Route::post('options', [\App\Http\Controllers\CustomerReturnsController::class, 'options']);
        Route::post('request', [\App\Http\Controllers\CustomerReturnsController::class, 'request'])->middleware('throttle:10,1');
        Route::post('cancel', [\App\Http\Controllers\CustomerReturnsController::class, 'cancel'])->middleware('throttle:10,1');
    });

    /*
     * An exchange difference paid by link. The 48-character link token is the
     * only credential; it travels as `link` (`token` is the API's own field).
     */
    Route::group(['prefix' => 'pay-link', 'middleware' => 'throttle:30,1'], function () {
        Route::get('view', [\App\Http\Controllers\CustomerReturnsController::class, 'payLink']);
        Route::post('start', [\App\Http\Controllers\CustomerReturnsController::class, 'payLinkStart']);
        Route::post('confirm', [\App\Http\Controllers\CustomerReturnsController::class, 'payLinkConfirm']);
    });

    /*
     * Guest checkout proves the email address before the order can be paid
     * for. Inside the guarded group: the token has to be one the server
     * issued, because the confirmation is recorded against it.
     */
    // Waiting for the code is checkout activity, not an idle basket.
    Route::post('guest/verify-email', [GuestUsersController::class, 'sendCheckoutCode'])->middleware('cart.hold:checkout');
    Route::post('guest/verify-email/check', [GuestUsersController::class, 'verifyCheckoutCode'])->middleware('cart.hold:checkout');

    Route::get('sessions', [FrontendController::class, 'sessions']);
    Route::post('logout-device', [FrontendController::class, 'logoutDevice']);
    Route::post('logout-other-devices', [FrontendController::class, 'logoutOtherDevices']);

    Route::get('onboarding', [FrontendController::class, 'onboarding']);
    Route::get('auth-banners', [FrontendController::class, 'authBanners']);
    Route::get('flash-discount', [FlashDiscountController::class, 'current']);
    Route::get('business-product/{id}', [FrontendController::class, 'businessProduct']);

    /*
     * Variant upgrades: the better versions of the product the customer
     * is already looking at. Outside the auth group like the product page
     * itself -- a guest is priced as a guest, an account against its own
     * bands, because the service is handed whoever is asking.
     */
    Route::get('product/{id}/upgrades', [ProductUpgradesController::class, 'forVariant']);
    Route::post('product/{id}/upgrades/validate', [ProductUpgradesController::class, 'validateUpgrade']);
    Route::get('common', [FrontendController::class, 'common']);
    Route::get('home', [FrontendController::class, 'home']);
    Route::get('products', [FrontendController::class, 'products']);
    Route::get('embed/category/{id}/products', [FrontendController::class, 'categoryProducts']);
    Route::get('categories', [FrontendController::class, 'categories']);
    Route::get('all', [FrontendController::class, 'all']);
    Route::get('brands', [FrontendController::class, 'brands']);
    Route::get('search', [FrontendController::class, 'search']);
    Route::get('product/{id}', [FrontendController::class, 'product']);
    // live stock of a product's variants, for the product page (CartHold moves it)
    Route::get('product/{id}/stock', [FrontendController::class, 'productStock']);
    Route::get('flash-sale/{id?}', [FrontendController::class, 'flashSale']);
    Route::get('reviews/{id}', [FrontendController::class, 'reviews']);
    Route::get('suggested-products/{id}', [FrontendController::class, 'productSuggestion']);
    Route::get('page/{slug}', [FrontendController::class, 'page']);
    Route::post('contact', [FrontendController::class, 'contactUs']);
    Route::post('email-subscription', [SubscriptionEmailsController::class, "emailSubscription"]);
    Route::get('track-order', [FrontendController::class, "trackOrder"]);

    // A wishlist someone shared: the link holder sees the products, priced for them.
    Route::get('wishlist/shared/{token}', [\App\Http\Controllers\WishlistShareController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::get('store', [FrontendController::class, "store"]);
    Route::get('payment-gateway', [FrontendController::class, "paymentGateway"]);
    Route::get('localization', [FrontendController::class, "localization"]);
    Route::get('countries-phones', [FrontendController::class, "countriesPhones"]);
    Route::get('towns', [FrontendController::class, "towns"]);
    Route::get('bundle-deals', [FrontendController::class, "bundleDeals"]);
    Route::get('clearance', [FrontendController::class, "clearance"]);
    Route::get('category-insights', [FrontendController::class, 'categoryInsights']);
    Route::get('price-ranges', [FrontendController::class, 'priceRanges']);


    Route::get('product/upsell-crosssell/{id}', [FrontendController::class, 'productCrossellUpsell']);

    /*
     * Outside the auth group, like the cart: a guest saves items for later
     * against its own token. The controller scopes every read and write to the
     * caller.
     */
    Route::group([
        'prefix' => 'user/save-for-later',
        'middleware' => 'cart.hold',
    ], function (){
        Route::get('all', [SaveForLaterController::class, 'all']);
        Route::post('action', [SaveForLaterController::class, 'action']);
        Route::delete('delete/{id}', [SaveForLaterController::class, 'delete']);
    });
	Route::get('upsell/find-products/', [UpsellController::class, 'findProducts']);
    /*
     * Cross-sell, for the storefront.
     *
     * Open to everybody, guests included. Eligibility, the display limit and
     * the price a particular customer pays are all decided server-side; the
     * page is told what to draw and is never trusted about any of them.
     */
    Route::get('product/{id}/cross-sells', [FrontendController::class, 'crossSellsForProduct']);
    Route::get('cart/cross-sells', [FrontendController::class, 'crossSellsForCart']);

   Route::get('/iyzico-redirect', [IyzicoPaymentController::class, 'redirect'])->name('iyzico.redirect');

    Route::post('/iyzico-callback', [IyzicoPaymentController::class, 'callback'])
        ->name('iyzico.callback');

    Route::group([
        'prefix' => 'cart',
        // basket reservations follow every change (App\Models\Helper\CartHold)
        'middleware' => 'cart.hold',
    ], function (){
        Route::get('by-user', [CartsController::class, "byUser"]);
        Route::post('action', [CartsController::class, "action"]);
        // the payment step has started: keep the basket while the card is charged
        Route::post('hold-for-payment', [CartsController::class, 'holdForPayment']);
        /*
         * Signing in through /user/signin merges the guest basket as part of
         * creating the session. This is for the ways into an account that do
         * not: a social provider redirect, or a storefront that wants to be
         * sure after a request it could not confirm. Idempotent.
         */
        Route::post('merge', [CartsController::class, "merge"]);
        Route::post('buy-now', [CartsController::class, "buyNow"]);
        Route::post('buy-now/cancel', [CartsController::class, 'cancelBuyNow']);
        // What changed the basket without the shopper (BasketNotice).
        Route::post('notices/dismiss', [CartsController::class, 'dismissNotices']);
        Route::post('notices/restore', [CartsController::class, 'restoreNotice']);
        // Before a pay-first gateway charges the card: can this checkout still be bought?
        Route::post('checkout-check', [CartsController::class, 'checkoutCheck']);
        /*
         * Move a line onto a better variant of the same product. Not an
         * add: the line changes, keeping its quantity, so the basket can
         * never hold the 128GB and the 256GB at once.
         */
        Route::post('switch-variant', [CartsController::class, 'switchVariant']);
        /*
         * Take one or more of the recommendations shown after an add.
         *
         * In the cart group and on CartsController because a cross-sell
         * product becomes a normal basket line the moment it is taken --
         * same stock check, same server-side pricing, same merge behaviour.
         * Outside the auth group, like the rest of the basket: a guest is
         * never made to sign in to be shown or to take an accessory.
         */
        Route::post('cross-sell', [CartsController::class, 'addCrossSell']);
        /*
         * Take an Essentials Bundle. Alongside cross-sell for the same
         * reason: its items become ordinary basket lines, priced and stocked
         * by the same code. All or nothing, which is the one thing that makes
         * it different -- see CartsController::addBundle.
         */
        Route::post('bundle', [CartsController::class, 'addBundle']);
        /*
         * What the bundles in this basket are worth. Re-derived from the
         * lines on every call and never stored -- see Helper\BundleDiscount.
         */
        Route::get('bundle-discount', [CartsController::class, 'bundleDiscount']);
        Route::delete('delete/{id}', [CartsController::class, 'delete']);
        Route::post('change', [CartsController::class, 'changeSelected']);
        Route::post('update-shipping', [CartsController::class, 'updateShipping']);
        // What delivery costs for an address, before anything is saved.
        Route::post('shipping-quote', [CartsController::class, 'shippingQuote']);
    });

    Route::group([
        'prefix' => 'payment'
    ], function (){
        /*
         * Public, so it serves the shopper's view of the settings. It used to
         * call the admin PaymentsController::find and hand every gateway
         * secret to anyone who asked.
         */
        Route::get('find', [FrontendController::class, 'paymentGateway']);
    });

    Route::group([
        'prefix' => 'cancellation',
    ], function (){
        Route::post('cancel-order', [CancellationsController::class, 'cancelOrder']);
        Route::get('find/{orderId}', [CancellationsController::class, 'findCancellation']);
    });

    Route::group([
        'prefix' => 'voucher',
    ], function (){
        Route::post('validity', [VouchersController::class, 'validity']);
    });

    Route::group([
        'prefix' => 'rating-review',
    ], function (){
        Route::post('action', [RatingReviewsController::class, "action"]);
		Route::post('store', [RatingReviewsController::class, 'store']);
		Route::get('show/{productId}', [RatingReviewsController::class, 'show']);
        Route::get('find/{productId}', [RatingReviewsController::class, "find"]);
		Route::post('update', [RatingReviewsController::class, 'update']);
    });

	

    Route::group([
        'prefix' => 'order',
    ], function (){
        Route::post('by-user', [OrdersController::class, "byUser"]);
        // cart.hold:checkout - a basket whose 20 minutes have lapsed is emptied
        // before it can be bought, and placing the order counts as activity.
        Route::post('action', [OrdersController::class, "action"])->middleware('cart.hold:checkout');
        Route::post('payment-done', [OrdersController::class, 'paymentDone']);
        // Stripe: the card is authorised before the order exists (StripeCheckout).
        Route::post('stripe-authorise', [\App\Http\Controllers\StripeCheckoutController::class, 'authorise'])
            ->middleware('cart.hold:checkout');
        Route::post('stripe-cancel', [\App\Http\Controllers\StripeCheckoutController::class, 'cancel']);
        Route::post('payfast-notify', [OrdersController::class, 'payFastNotify']);
        Route::post('transaction', [OrdersController::class, 'transaction']);
        Route::get('send-order-email/{id}', [OrdersController::class, 'sendOrderEmail']);

        /*
         * Procurement, supplier side. No account and no admin session: the
         * encrypted token in the WhatsApp link is the credential, and
         * BidController checks it on both routes.
         *
         * Throttled because these are the only routes here a link holder can
         * reach with no other gate, and the bid route writes. {token} stays
         * last in the group on purpose -- it is a catch-all and would
         * otherwise swallow any single-segment GET added above it.
         */
        Route::post('bid', [BidController::class, 'submitBid'])
            ->middleware('throttle:30,1');

        Route::get('{token}', [BidController::class, 'orderDetail'])
            ->middleware('throttle:60,1');
    });



    Route::group([
        'prefix' => 'seller',
    ], function (){
        Route::post('signup', [SellerController::class, 'signup']);
        Route::post('verify', [SellerController::class, 'verify']);
    });


    Route::group([
        'prefix' => 'user'
    ], function (){
        Route::group([
            'prefix' => 'address',
        ], function (){
            Route::get('all', [UsersController::class, "addresses"]);
            Route::post('action', [UsersController::class, "addressAction"]);
            Route::delete('delete/{id}', [UsersController::class, "deleteAddress"]);
        });

        Route::get('profile', [UsersController::class, "profile"]);

        /*
         * Outside the auth group on purpose: a guest keeps a wishlist against
         * its server-issued user_token, exactly the way it keeps a cart. The
         * controller scopes every read and write to the caller, so a guest only
         * ever sees its own rows.
         */
        Route::group([
            'prefix' => 'wishlist',
        ], function (){
            Route::get('all', [UserWishlistsController::class, "wishlists"]);
            Route::post('action', [UserWishlistsController::class, "wishlistAction"]);
        });

        Route::group([

            'prefix' => 'social-login',
            'middleware' => ['social', 'web']

        ], function () {
            Route::get('redirect/{service}',  [UsersController::class, 'redirectToProvider']);
            Route::get('callback/{service}',  [UsersController::class, 'handleProviderCallback']);
        });

        /*
         * Everything that takes a password, a code or an email address is
         * rate limited per IP. None of these were, so a password, a 6-digit
         * sign-in code and a 6-digit second factor could all be attacked as
         * fast as the server would answer — a six-digit code falls in under
         * an hour at a few hundred requests a second.
         *
         * The limits are generous enough that a person fumbling a code three
         * or four times never meets them.
         */
        Route::group(['middleware' => 'throttle:10,1'], function () {

            Route::post('signin', [UsersController::class, 'login']);
            Route::post('login-code', [UsersController::class, 'sendLoginCode']);
            Route::post('login-code/verify', [UsersController::class, 'verifyLoginCode']);
            // Reached with a challenge handle, not a session — the whole point is
            // that the user is not signed in yet.
            Route::post('two-factor/challenge', [UsersController::class, 'verifyTwoFactor']);
            Route::post('verify', [UsersController::class, 'verify']);
            Route::post('forgot-password', [UsersController::class, 'forgotPassword']);
            Route::post('reset-password', [UsersController::class, 'resetPassword']);
            Route::post('update-password', [UsersController::class, 'updatePassword']);
        });

        // Each one sends an email, so the cost of an unthrottled loop is
        // landing the sending domain on a blocklist.
        Route::group(['middleware' => 'throttle:5,1'], function () {
            Route::post('signup', [UsersController::class, 'signup']);
        });

        Route::post('reset-password/check', [UsersController::class, 'checkResetToken']);

        Route::get('user-vouchers', [UsersController::class, "vouchers"]);
        Route::get('gift-vouchers', [FrontendController::class, "getGiftVouchers"]);


        Route::group([
            'middleware' =>  ['auth:user', 'scope:user', 'account.active']
        ], function () {

            Route::prefix('two-factor')->group(function () {

                Route::get('status', [TwoFactorController::class, 'status']);

                /*
                 * Confirming a code and disabling 2FA are both guessing
                 * games — a 6-digit code and the account password — so they
                 * are capped even though the caller is already signed in.
                 */
                Route::group(['middleware' => 'throttle:10,1'], function () {
                    Route::post('setup', [TwoFactorController::class, 'setup']);
                    Route::post('confirm', [TwoFactorController::class, 'confirm']);
                    Route::post('sms/confirm', [TwoFactorController::class, 'confirmSms']);
                    Route::post('disable', [TwoFactorController::class, 'disable']);
                });

                // Tighter: every call spends money and rings someone's phone.
                Route::post('sms/send', [TwoFactorController::class, 'sendSms'])
                    ->middleware('throttle:4,10');
            });

            /*
             * Proving the mobile number on the account, without necessarily
             * turning on a second factor. Same limits as the 2FA pair: the
             * send costs money, the check is a guess at six digits.
             */
            Route::prefix('phone')->group(function () {

                Route::post('send-code', [TwoFactorController::class, 'sendPhoneCode'])
                    ->middleware('throttle:4,10');

                Route::post('verify', [TwoFactorController::class, 'verifyPhone'])
                    ->middleware('throttle:10,1');
            });

            // Share Wishlist: the owner's link, on / off.
            Route::get('wishlist/share', [\App\Http\Controllers\WishlistShareController::class, 'status']);
            Route::post('wishlist/share', [\App\Http\Controllers\WishlistShareController::class, 'enable']);
            Route::delete('wishlist/share', [\App\Http\Controllers\WishlistShareController::class, 'disable']);

            // The signed-in owner's invoice PDF (My Orders -> Invoice).
            Route::get('order/{id}/invoice', [\App\Http\Controllers\InvoiceController::class, 'show']);

            Route::get('points', [WalletController::class, 'getPoints']);
            Route::get('points/history', [WalletController::class, 'pointsHistory']);
            Route::get('wallet', [WalletController::class, "getWallet"]);
            Route::get('wallet/transactions', [WalletController::class, 'transactions']);
            Route::get('wallet/statement', [WalletController::class, 'statement']);
            // Total Saved on Vouchers and Savers Club: voucher discounts on standing orders
            Route::get('savings', [WalletController::class, 'savings']);
            Route::post('wallet/topup/confirm', [WalletController::class, "confirmTopup"]);
            Route::post('purchase-gift-voucher', [WalletController::class, "purchaseGiftVoucher"]);
            // Card purchases: recorded once SumUp confirms the checkout.
            // card purchase: fix the purchase server-side, then pay at SumUp
            Route::post('purchase-gift-voucher/start', [WalletController::class, "startGiftCardCheckout"]);
            Route::post('purchase-gift-voucher/confirm', [WalletController::class, "confirmGiftVoucher"]);
            // the codes bought, and adding a code (bought or given) to your vouchers
            Route::get('gift-voucher-codes', [WalletController::class, "giftCodes"]);
            Route::post('gift-voucher-codes/redeem', [WalletController::class, "redeemGiftCode"])->middleware('throttle:10,1');

            Route::get('product/qualifying', [FrontendController::class, "qualifyingProducts"]);

            /*
             * The loyalty group discount available to this customer. Signed-in
             * only and behind auth:user: the discount belongs to a person, and
             * an anonymous caller must not be able to probe who is in which
             * group by passing a user id.
             */
            Route::get('loyalty-discount', [LoyaltyController::class, 'current']);

            // Paid membership (Savers Club). The card is taken on the page by Stripe's card form.
            Route::get('membership', [MembershipController::class, 'show']);
            Route::post('membership/subscribe', [MembershipController::class, 'subscribe'])->middleware('throttle:10,1');
            Route::post('membership/subscribe/finish', [MembershipController::class, 'joinFinish'])->middleware('throttle:10,1');
            Route::get('membership/billing', [MembershipController::class, 'billing']);
            Route::post('membership/card', [MembershipController::class, 'card'])->middleware('throttle:10,1');
            Route::post('membership/card/finish', [MembershipController::class, 'cardFinish'])->middleware('throttle:10,1');
            Route::post('membership/cancel', [MembershipController::class, 'cancel']);
            Route::post('membership/resume', [MembershipController::class, 'resume']);
            Route::post('membership/change', [MembershipController::class, 'change'])->middleware('throttle:10,1');
            Route::post('membership/digest', [MembershipController::class, 'digest']);


            Route::get('logout', [UsersController::class, "logout"]);


            Route::post('update-profile', [UsersController::class, "updateProfile"]);
            Route::post('update-user-password', [UsersController::class, "updateUserPassword"]);

            Route::get('/recently-viewed', [RecentlyViewedController::class, 'index']);
            Route::delete('/recently-viewed/clear', [RecentlyViewedController::class, 'clear']);
            Route::delete('/recently-viewed/{product_id}', [RecentlyViewedController::class, 'deleteOne']);


            Route::group([
                'prefix' => 'compare-list',
            ], function (){
                Route::get('all', [CompareListsController::class, "all"]);
                Route::post('action', [CompareListsController::class, "action"]);
            });


            Route::group([
                'prefix' => 'store',
            ], function (){
                Route::post('follow', [UserFollowStoreController::class, 'action']);
                Route::get('following-list', [UserFollowStoreController::class, 'all']);
            });

            /*
             * The customer changing their own account type: personal to
             * business on the spot, or an application for trade. Submitting
             * is password-checked, so it is capped like the two-factor calls.
             */
            Route::prefix('account-upgrade')->group(function () {
                Route::get('/', [AccountUpgradesController::class, 'show']);
                Route::group(['middleware' => 'throttle:10,10'], function () {
                    Route::post('/', [AccountUpgradesController::class, 'store']);
                    Route::post('withdraw', [AccountUpgradesController::class, 'withdraw']);
                });
            });

        });

        Route::group([
            'middleware' =>  ['auth:user', 'scope:user', 'account.active']
        ], function () {
            Route::delete('delete', [UsersController::class, "deleteUser"]);
        });
    });
});
