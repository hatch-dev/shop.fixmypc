<?php

namespace App\Http\Controllers;

use App\Models\Helper\MailBrand;
use App\Models\Helper\MailDeliverability;
use App\Models\Helper\MailHelper;
use App\Models\Helper\Utils;
use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Product;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Browser preview for the email templates.
 *
 * Email is the one part of the app you cannot iterate on by refreshing a
 * page, and the previous way to check a change was to place a real order.
 * These routes render each template with either a real order (pass ?order=ID)
 * or a representative sample, in any configured language (?lang=fr), and in
 * either the HTML or the plain-text part (?format=text).
 *
 * Registered only outside production — see routes/web.php.
 */
class MailPreviewController extends Controller
{
    /** Templates that can be previewed, and how their data is built. */
    const TEMPLATES = [
        'user_registration',
        'forgot_password',
        'login_code',
        'reset_password_link',
        'seller_activation',
        'guest_account_created',
        'order_placed',
        'order_placed_to_vendor',
        'package_delivered',
        'order_status_changed',
        'subscription',
    ];

    public function index(Request $request)
    {
        $lang = $request->query('lang');
        $brand = MailBrand::make('Yogesh Verma', $lang);

        $rows = '';

        foreach (self::TEMPLATES as $template) {
            $query = http_build_query(array_filter([
                'lang' => $lang,
                'order' => $request->query('order'),
            ]));

            $base = url('mail-preview/' . $template) . ($query ? '?' . $query . '&' : '?');

            $rows .= '<li><a href="' . e($base . 'format=html') . '">' . e($template) . '</a>'
                . ' <a class="txt" href="' . e($base . 'format=text') . '">text</a></li>';
        }

        return response('<!doctype html><meta charset="utf-8"><title>Email previews</title>'
            . '<style>body{font:15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;'
            . 'max-width:640px;margin:60px auto;padding:0 24px;color:#0f172a}'
            . 'h1{font-size:22px;margin:0 0 4px}p{color:#64748b;margin:0 0 24px}'
            . 'ul{list-style:none;padding:0}li{padding:10px 0;border-bottom:1px solid #e8ecf3}'
            . 'a{color:' . e($brand->primary) . ';text-decoration:none;font-weight:600}'
            . 'a.txt{float:right;font-weight:400;font-size:13px;color:#94a3b8}</style>'
            . '<h1>Email previews</h1>'
            . '<p>Add <code>?lang=fr</code> for a translated render, or <code>?order=123</code> '
            . 'to use a real order instead of the sample one.</p><ul>' . $rows . '</ul>');
    }

    public function show(Request $request, $template)
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            abort(404);
        }

        $lang = $request->query('lang');
        $text = $request->query('format') === 'text';

        $view = 'mail_templates.' . ($text ? 'text.' : '') . $template;

        if (!View::exists($view)) {
            abort(404, 'No ' . ($text ? 'text' : 'html') . ' part for ' . $template);
        }

        $body = View::make($view, $this->data($request, $template, $lang))->render();

        if ($text) {
            return response($body)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return response($body)->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function data(Request $request, $template, $lang)
    {
        $brand = MailBrand::make('Yogesh Verma', $lang);

        switch ($template) {
            case 'user_registration':
            case 'forgot_password':
            case 'login_code':
                return ['data' => $this->code(), 'brand' => $brand, 'lang' => $lang];

            case 'reset_password_link':
                return [
                    'data' => $this->code([
                        'link' => rtrim(config('env.url.CLIENT_BASE_URL'), '/')
                            . '/reset-password?token=' . str_repeat('a1b2', 8) . '&email=sample%40example.com',
                    ]),
                    'brand' => $brand,
                    'lang' => $lang,
                ];

            case 'seller_activation':
                return [
                    'data' => $this->code([
                        'commission' => '12%',
                        'admin_url' => rtrim(config('env.url.APP_URL'), '/') . '/admin',
                    ]),
                    'brand' => $brand,
                    'lang' => $lang,
                ];

            case 'guest_account_created':
                return [
                    'data' => $this->code([
                        'email' => 'sample@example.com',
                        // Shaped like a real generated password so the layout
                        // is tested against a realistic string length.
                        'password' => 'pZr55UiggXEQZn',
                        'sign_in_url' => rtrim($brand->client_url, '/') . '/login',
                    ]),
                    'brand' => $brand,
                    'lang' => $lang,
                ];

            case 'subscription':
                $recipient = 'sample@example.com';

                return [
                    'brand' => $brand,
                    'setting' => $brand,
                    'subject' => 'A sample campaign',
                    'body' => '<h2>A sample campaign</h2>'
                        . '<p>This is where the body written in the newsletter editor appears.</p>'
                        . '<ul><li>First point</li><li>Second point</li></ul>'
                        . '<p><a href="' . $brand->shop_url . '">Visit the store</a></p>',
                    'recipient' => $recipient,
                    'unsubscribeUrl' => MailDeliverability::unsubscribeUrl($recipient),
                    'lang' => $lang,
                ];

            case 'order_status_changed':
                $order = $this->order($request, $lang);

                $data = new \stdClass();
                $data->receiver = $brand->receiver;
                $data->order = $order->order;
                $data->order_id = $order->id;
                $data->status_label = $request->query('status', 'On the way');
                $data->total_amount = $order->calculated_price['total_price'];
                $data->currency_icon = $brand->currency_icon;
                $data->store_name = $brand->store_name;

                return ['data' => $data, 'setting' => $brand, 'brand' => $brand, 'lang' => $lang];

            default:
                return [
                    'setting' => $brand,
                    'brand' => $brand,
                    'order' => $this->order($request, $lang),
                    'lang' => $lang,
                ];
        }
    }

    protected function code($extra = [])
    {
        $o = new \stdClass();
        $o->code = '482913';
        $o->expires_in_minutes = 10;
        $o->receiver = 'Yogesh Verma';
        $o->store_name = MailBrand::make()->store_name;
        $o->address = MailBrand::make()->address;
        $o->phone = MailBrand::make()->phone;

        foreach ($extra as $key => $value) {
            $o->$key = $value;
        }

        return $o;
    }

    /**
     * A real order when one is asked for, otherwise a sample built in memory
     * so the order templates can be previewed on an empty database.
     */
    protected function order(Request $request, $lang)
    {
        if ($id = $request->query('order')) {
            $data = MailHelper::sendingOrderEmail($request, $id, $lang);

            if ($data) {
                return $data['order'];
            }
        }

        return $this->sampleOrder();
    }

    protected function sampleOrder()
    {
        $order = new Order();
        $order->id = 0;
        $order->order = 'SAMPLE-1042';
        $order->status = 4;
        $order->currency = config('env.url.APP_URL') ? null : null;
        $order->created_at = now()->subHours(3);

        $user = new User();
        $user->name = 'Yogesh Verma';
        $user->email = 'sample@example.com';
        $user->phone = '087 452 3344';

        $address = new UserAddress();
        $address->name = 'Yogesh Verma';
        $address->address_1 = '14 Meadow Court';
        $address->city = 'Maynooth';
        $address->state = 'Co. Kildare';
        $address->zip = 'W23 AB12';
        $address->country = 'Ireland';

        $products = Product::limit(3)->get();
        $prices = [899.00, 249.99, 45.99];
        $quantities = [1, 1, 2];

        $lines = new Collection();

        foreach ($products as $i => $product) {
            $op = new OrderedProduct();
            $op->id = 900 + $i;
            $op->quantity = isset($quantities[$i]) ? $quantities[$i] : 1;
            $op->selling = isset($prices[$i]) ? $prices[$i] : 99.00;
            $op->shipping_type = 1;

            $op->setRelation('product', $product);

            $shipping = new \stdClass();
            $shipping->price = $i === 0 ? 0 : 4.95;
            $shipping->pickup_price = 0;
            $op->setRelation('shipping_place', $shipping);

            $value = new \stdClass();
            $value->title = $i === 0 ? '16GB / 512GB' : 'Black';
            $value->attribute = new \stdClass();
            $value->attribute->title = $i === 0 ? 'Configuration' : 'Colour';

            $attribute = new \stdClass();
            $attribute->attribute_value = $value;

            $inventory = new \stdClass();
            $inventory->inventory_attributes = [$attribute];
            $op->setRelation('updated_inventory', $inventory);

            $lines->push($op);
        }

        $order->setRelation('user', $user);
        $order->setRelation('guest_user', null);
        $order->setRelation('address', $address);
        $order->setRelation('ordered_products', $lines);
        $order->setRelation('voucher', null);
        $order->setRelation('cancellation', null);

        $order->created = Utils::formatDate($order->created_at);
        $order->formatted_address = Utils::formatAddress($address);
        $order->order_method = 'Card payment';

        $subtotal = 0;
        $shippingTotal = 0;

        foreach ($lines as $op) {
            $subtotal += (float)$op->selling * (int)$op->quantity;
            $shippingTotal += (float)$op->shipping_place->price;
        }

        $order->total_amount = $subtotal + $shippingTotal - 50;
        $order->calculated_price = [
            'subtotal' => $subtotal,
            'shipping_price' => $shippingTotal,
            'bundle_offer' => 0,
            'voucher_price' => 50,
            'tax' => 0,
            'total_price' => $subtotal + $shippingTotal - 50,
        ];

        return $order;
    }
}
