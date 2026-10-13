<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InstallController;
use \Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Response;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/.well-known/apple-developer-merchantid-domain-association', function () {
    $path = public_path('.well-known/apple-developer-merchantid-domain-association');

    return Response::file($path, [
        'Content-Type' => 'text/plain',
    ]);
});

/*
 * Newsletter unsubscribe — the target of the List-Unsubscribe header.
 *
 * Registered ahead of the {any} catch-all below, and outside the session
 * middleware, because the POST arrives machine-to-machine from Gmail or
 * Yahoo with no cookies. The signed URL is what authenticates it.
 */
Route::match(['get', 'post'], 'newsletter/unsubscribe',
    [\App\Http\Controllers\NewsletterSubscriptionController::class, 'unsubscribe'])
    ->name('newsletter.unsubscribe');

/*
 * Browser previews for the email templates. Registered outside production
 * only: the pages render sample customer data and need no authentication.
 */
if (!app()->environment('production')) {
    Route::get('mail-preview',
        [\App\Http\Controllers\MailPreviewController::class, 'index']);

    Route::get('mail-preview/{template}',
        [\App\Http\Controllers\MailPreviewController::class, 'show']);
}

/*
 * Serve the project-root uploads/ directory.
 *
 * The app is laid out for shared hosting, where the document root IS the
 * project root, so Apache serves /uploads/... straight off disk and never
 * reaches Laravel. Under `php artisan serve` (and any docroot=public/ setup)
 * that directory is invisible: every image URL fell through to the {any}
 * catch-all below and came back as a 500 HTML page, which is why product,
 * category and brand thumbnails were blank across the admin panel.
 *
 * Registered ahead of the catch-all. Where a real web server already serves
 * the file this route is simply never reached.
 */
Route::get('uploads/{path}', function ($path) {
    foreach ([base_path('uploads'), public_path('uploads')] as $dir) {
        $full = realpath($dir . DIRECTORY_SEPARATOR . $path);

        // realpath + prefix check keeps ../ out of the served tree
        if ($full === false || strpos($full, realpath($dir)) !== 0 || !is_file($full)) {
            continue;
        }

        return response()->file($full, ['Cache-Control' => 'public, max-age=604800']);
    }

    abort(404);
})->where('path', '.*');

Route::group(['middleware' => 'web'], function () {
    Route::get('install', [InstallController::class, 'install']);
    Route::get('update', [InstallController::class, 'update']);



    Route::get('{any}', function () {

        // Only for Laravel frontend. Should be removed in docker
        if(str_contains(\Illuminate\Support\Facades\URL::current(), 'admin')){
            return view('admin');
        }

        return view('welcome');
    })->where('any', '.*');

});


/*
Route::get('/user-registration', function (\Illuminate\Http\Request $request) {
    $objDemo = new \stdClass();
    $objDemo->receiver = "John";
    $objDemo->code = "123";
    $objDemo->store_name = "Address";
    $objDemo->address = "Address";
    $objDemo->phone = "ww";

    return view('mail_templates.user_registration', ['data' => $objDemo]);
});
Route::get('/subscription', function (\Illuminate\Http\Request $request) {
    $body = "<h1>Test</h1>";
    $setting = \App\Models\Setting::get()->first();

    return view('mail_templates.subscription', [
        "body" => $body,
        "setting" => $setting
    ]);
});
Route::get('/package-delivered', function (\Illuminate\Http\Request $request) {
    $mailData = \App\Models\Helper\MailHelper::sendingOrderEmail($request, 1);
    if(is_null($mailData)){
        response()->json(\App\Models\Helper\Validation::error($request->token, 'Invalid.'));
    }
    return view('mail_templates.package_delivered', $mailData);
});
Route::get('/order-placed', function (\Illuminate\Http\Request $request) {
    $mailData = \App\Models\Helper\MailHelper::sendingOrderEmail($request, 1);
    if(is_null($mailData)){
        response()->json(\App\Models\Helper\Validation::error($request->token, 'Invalid.'));
    }
    return view('mail_templates.order_placed', $mailData);
});

Route::get('/order', function (\Illuminate\Http\Request $request) {
    $mailData = \App\Models\Helper\MailHelper::sendingOrderEmail($request, 1);
    if(is_null($mailData)){
        response()->json(\App\Models\Helper\Validation::error($request->token, 'Invalid.'));
    }
    return view('mail_templates.order_pdf', $mailData);
});
Route::get('forgot', function (){
    $objDemo = new \stdClass();
    $objDemo->receiver = "John";
    $objDemo->code = "123";
    $objDemo->store_name = "Address";
    $objDemo->address = "Address";
    $objDemo->phone = "ww";

    return view('mail_templates.forgot_password', ['data' => $objDemo]
    );
});

Route::get('{any}', function () {

   if(!env('SHARED_SERVER_CONFIGURED', false)){
        return redirect("/install");
    }

    if(str_contains(\Illuminate\Support\Facades\URL::current(), 'admin')){
        return view('admin');
    }

    return view('welcome');
})->where('any', '.*');

*/


