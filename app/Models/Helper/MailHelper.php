<?php

namespace App\Models\Helper;

use App\Models\AttributeValue;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\SubscriptionEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use PDF;
use Mail;
use App\Mail\EmailSender;
use App\Models\Setting;

class MailHelper
{

    /**
     * Newsletter send.
     *
     * One message per subscriber, deliberately. The previous version put
     * every subscriber into a single To: header, which
     *   - exposed the whole mailing list to every recipient,
     *   - made a per-person unsubscribe link impossible, and
     *   - is one of the clearest bulk-spam patterns a filter can see.
     *
     * @return array{sent:int, failed:int}
     */
    public static function sendingSubscriptionEmail($subject, $body, $subscribers, $lang = null)
    {
        $brand = MailBrand::make(null, $lang);
        $subject = MailDeliverability::subject($subject);

        $sent = 0;
        $failed = 0;

        foreach ($subscribers as $subscriber) {
            if (is_null($subscriber->email) || !filter_var($subscriber->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $recipient = $subscriber->email;
            $unsubscribeUrl = MailDeliverability::unsubscribeUrl($recipient);

            $data = [
                'brand' => $brand,
                'setting' => $brand,
                'body' => $body,
                'subject' => $subject,
                'recipient' => $recipient,
                'unsubscribeUrl' => $unsubscribeUrl,
                'lang' => $lang,
            ];

            try {
                Mail::send(
                    ['html' => 'mail_templates.subscription', 'text' => 'mail_templates.text.subscription'],
                    $data,
                    function ($message) use ($brand, $subject, $recipient) {
                        $message->to($recipient)->subject($subject);

                        MailDeliverability::bulk($message, $brand, $recipient);
                    }
                );

                $sent++;

            } catch (\Exception $ex) {
                // One bad address must not abandon the rest of the campaign.
                $failed++;

                \Illuminate\Support\Facades\Log::warning('Newsletter send failed', [
                    'email' => $recipient,
                    'message' => $ex->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function sendingOrderEmail($request, $id, $lang = null)
    {
        $query = Order::query();

        $query = $query->with('cancellation');
        $query = $query->with('user');
        $query = $query->with('guest_user');

        $query = $query->with('address');
        $query = $query->with('ordered_products.shipping_place');


        if ($lang) {

            $query = $query->with(['ordered_products.product' => function ($query) use ($lang) {
                $query->leftJoin('product_langs as pl',
                    function ($join) use ($lang) {
                        $join->on('products.id', '=', 'pl.product_id');
                        $join->where('pl.lang', $lang);
                    })
                    ->select('products.id', 'products.image', 'products.selling',
                        'products.offered', 'products.shipping_rule_id',
                        'products.bundle_deal_id', 'products.unit', 'pl.title');
            }]);

            $query = $query->with(['voucher' => function ($query) use ($lang) {
                $query->leftJoin('voucher_langs as vl',
                    function ($join) use ($lang) {
                        $join->on('vouchers.id', '=', 'vl.voucher_id');
                        $join->where('vl.lang', $lang);
                    })
                    ->select('vouchers.*', 'vl.title');
            }]);

            $query = $query->with(['ordered_products.updated_inventory.inventory_attributes.attribute_value' => function ($query) use ($lang) {
                    $query->leftJoin('attribute_value_langs as avl',
                        function ($join) use ($lang) {
                            $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                            $join->where('avl.lang', $lang);
                        })
                        ->with(['attribute' => function ($query) use ($lang) {

                            $query->leftJoin('attribute_langs as al',
                                function ($join) use ($lang) {
                                    $join->on('attributes.id', '=', 'al.attribute_id');
                                    $join->where('al.lang', $lang);
                                })
                                ->select('attributes.id', 'attributes.title', 'al.title');
                        }])
                        ->select('attribute_values.*', 'avl.title');
                }]);

        } else {

            $query = $query->with('ordered_products.product');
            $query = $query->with('ordered_products.updated_inventory.inventory_attributes.attribute_value.attribute');
            $query = $query->with('voucher');
        }
        $order = $query->find($id);

        if (is_null($order)) {
            return null;
        }

        if ($request->time_zone) {
            $order['created'] = Utils::formatDate(Utils::convertTimeToUSERzone($order->created_at, $request->time_zone));
        } else {
            $order['created'] = Utils::formatDate($order->created_at);
        }


        $om = __('lang.' . Config::get('constants.paymentMethodKLangIn')[$order['order_method']], [], $lang);

        $order['formatted_address'] = Utils::formatAddress($order->address);
        $order['order_method'] = $om;
        $order['calculated_price'] = Utils::calcPrice($order);


        /*
            $objDemo = MailHelper::emailData("sf");
            $objDemo->logo_base64 = FileHelper::imageToBase64($objDemo->image);
            return ['setting' => $objDemo, 'order' => $order, 'lang' => $lang];
         */



        if ($order->user) {
            $objDemo = MailHelper::emailData($order->user->name, $lang);

        } else if ($order->guest_user) {

            $order->user = $order->guest_user;

            $objDemo = MailHelper::emailData($order->guest_user->name, $lang);

        } else {

            /*
             * Neither an account nor a guest row -- but the order may still
             * carry the contact details captured at the shipping step, which
             * is how a collection order placed without signing in is held.
             *
             * This used to `return null`, and returning null here means no
             * receipt: the caller reads it as "this order is not mailable" and
             * gives up. Order::contactDetails() has treated those columns as a
             * valid recipient since guest checkout landed; this is the one
             * place that did not agree with it.
             *
             * Shaped as a plain object carrying the properties the templates
             * touch, exactly as the guest_user substitution above does.
             * businessProfile is set explicitly because order_pdf reads it and
             * an undefined property on stdClass is a warning inside a PDF
             * render, which is not somewhere a warning is easy to see.
             */
            $contact = $order->contactDetails();

            if (!$contact['email']) {
                return null;
            }

            $stand_in = new \stdClass();
            $stand_in->name = $contact['name'];
            $stand_in->email = $contact['email'];
            $stand_in->phone = $contact['phone'];
            $stand_in->businessProfile = null;

            $order->user = $stand_in;

            $objDemo = MailHelper::emailData($contact['name'], $lang);
        }

        // Needed only by the PDF invoice, which cannot fetch a remote logo.
        $objDemo->logo_base64 = FileHelper::imageToBase64($objDemo->image);

        return [
            'setting' => $objDemo,
            'brand' => $objDemo,
            'order' => $order,
            'lang' => $lang,
        ];
    }

    public static function shippingPrice($shipping, $type)
    {
        if ((int)$type === Config::get('constants.shippingTypeIn.LOCATION')) {
            return (float)$shipping->price;
        } else if ((int)$type === Config::get('constants.shippingTypeIn.PICKUP')) {
            return (float)$shipping->pickup_price;
        }
    }

    public static function generatingAttribute($order)
    {
        $attributes = $order->updated_inventory->inventory_attributes;
        $attrStr = [];
        foreach ($attributes as $i) {
            array_push($attrStr, $i->attribute_value->attribute->title . ': ' . $i->attribute_value->title);
        }
        return join(', ', $attrStr);
    }


    public static function order($id)
    {
        $order = Order::with('ordered_products.product')
            ->with('cancellation')
            ->with('ordered_products.updated_inventory.inventory_attributes.attribute_value.attribute')
            ->with('voucher')
            ->with('user')
            ->with('guest_user')
            ->with('address')
            ->with('ordered_products.shipping_place')
            ->find($id);

        $order['created'] = Utils::formatDate($order->created_at);
        $order['formatted_address'] = Utils::formatAddress($order->address);
        $order['order_method'] = Config::get('constants.paymentMethodIn')[$order['order_method']];
        $order['calculated_price'] = Utils::calcPrice($order);

        return $order;
    }

    /**
     * The store context handed to a template as $setting.
     *
     * Now backed by MailBrand, so templates get the colours, social profiles,
     * legal links and storefront URLs on top of the handful of fields this
     * used to build by hand. Every property the old version returned is still
     * present, so nothing downstream had to change.
     */
    public static function emailData($receiver, $lang = null)
    {
        return MailBrand::make($receiver, $lang);
    }

    /**
     * Kept for the callers that already hold a Setting / SiteSetting pair.
     * The arguments are ignored — MailBrand reads both itself and memoises
     * them — but the signature stays put so existing call sites still work.
     */
    public static function prepareEmailData($setting = null, $siteSetting = null, $lang = null)
    {
        return MailBrand::make(null, $lang);
    }


    /**
     * Superseded. Kept as a redirect, not as a sender.
     *
     * This used to render and send the receipt itself, which meant it was a
     * way to put an order email in front of a customer without claiming the
     * event first -- no idempotency, no retry, no record. Nothing calls it any
     * more, but a live method that quietly bypasses all three is how duplicate
     * receipts come back, so it now goes where everything else goes.
     *
     * $user is ignored deliberately: the recipient is resolved from the order,
     * which is the only source that is right for a guest, for a collection
     * order, and for an account whose address has since changed.
     */
    public static function orderPlaced($user, $orderId)
    {
        return OrderEmailDispatcher::dispatch($orderId, OrderEmailEvent::ORDER_PLACED);
    }


    /**
     * Emails a password reset link rather than a code. Same store details and
     * template plumbing as codeSender, but the payload is a one-use URL.
     */
    public static function resetLinkSender($request, $link, $minutes, $subject = null, $lang = null)
    {
        $brand = MailBrand::make($request->name, $lang);

        if (!$subject) {
            $subject = __('lang.mail_subject_forgot', [], $lang);
        }

        $subject = MailDeliverability::subject($subject);

        $data = new \stdClass();
        $data->link = $link;
        $data->expires_in_minutes = $minutes;
        $data->receiver = $request->name;
        $data->address = $brand->address;
        $data->phone = $brand->phone;
        $data->store_name = $brand->store_name;

        Mail::send(
            ['html' => 'mail_templates.reset_password_link', 'text' => 'mail_templates.text.reset_password_link'],
            ['data' => $data, 'brand' => $brand, 'lang' => $lang],
            function ($message) use ($request, $subject, $brand) {
                $message->to($request->email, $request->name)->subject($subject);

                MailDeliverability::transactional($message, $brand, 'password-reset');
            });

        return true;
    }

    public static function codeSender($request, $type = 'registration', $subject = null, $lang = null)
    {
        if (is_null($type)) {
            $type = 'registration';
        }

        $bladeTemplate = 'mail_templates.user_registration';
        $textTemplate = 'mail_templates.text.user_registration';
        $digits = 4;

        /*
         * Registration, password reset and sign-in used to leave here with the
         * same "You are almost there." subject. Three different emails sharing
         * one subject line is bad for the reader and bad for the sender: Gmail
         * collapses identical subjects from one sender into a single thread and
         * clips them. Each flow now has its own default.
         */
        $defaultSubject = __('lang.mail_subject_registration', [], $lang);
        $category = 'account-verification';

        switch ($type) {
            case 'registration':
                // Six digits to match the code entry on the signup screen, and
                // because four is only 10k combinations against a check that
                // has no attempt limit.
                $digits = 6;
                break;
            case 'forgot_password':
                $bladeTemplate = 'mail_templates.forgot_password';
                $textTemplate = 'mail_templates.text.forgot_password';
                $defaultSubject = __('lang.mail_subject_forgot', [], $lang);
                $category = 'password-reset';
                break;
            case 'login_code':
                $bladeTemplate = 'mail_templates.login_code';
                $textTemplate = 'mail_templates.text.login_code';
                $defaultSubject = __('lang.mail_subject_login', [], $lang);
                $category = 'login-code';
                $digits = 6;
                break;
        }

        $subject = MailDeliverability::subject($subject ?: $defaultSubject);

        $brand = MailBrand::make($request->name, $lang);

        $objDemo = new \stdClass();
        // Kept as a plain 4-digit int for the existing flows, whose stored codes
        // are compared loosely; only the sign-in code widens to 6 digits.
        $objDemo->code = $digits === 6
            ? (string)random_int(100000, 999999)
            : rand(1000, 9999);
        $objDemo->expires_in_minutes = \App\Models\LoginCode::LIFETIME_MINUTES;
        $objDemo->receiver = $request->name;

        $objDemo->address = $brand->address;
        $objDemo->phone = $brand->phone;
        $objDemo->store_name = $brand->store_name;

        try {

            Mail::send(
                ['html' => $bladeTemplate, 'text' => $textTemplate],
                ['data' => $objDemo, 'brand' => $brand, 'lang' => $lang],
                function ($message) use ($request, $subject, $brand, $category) {
                    $message->to($request->email, $request->name)
                        ->subject($subject);

                    MailDeliverability::transactional($message, $brand, $category);
                });


        } catch (\Exception $ex) {
            throw new \Exception($ex);
        }
        return $objDemo->code;
    }


    /**
     * Hand a guest the account their order just opened for them.
     *
     * The password is only ever in this message - it is hashed the moment it
     * is generated and never stored in the clear - so the email doubles as the
     * only copy, and says so.
     */
    public static function guestAccountCreated($user, $password, $lang = null)
    {
        if (!$user || !$user->email) {
            return false;
        }

        $brand = MailBrand::make($user->name, $lang);

        $data = new \stdClass();
        $data->receiver = $user->name;
        $data->email = $user->email;
        $data->password = $password;
        $data->sign_in_url = rtrim($brand->site_url, '/') . '/login';

        $data->address = $brand->address;
        $data->phone = $brand->phone;
        $data->store_name = $brand->store_name;

        $subject = MailDeliverability::subject(
            __('lang.mail_guest_account_title', [], $lang)
        );

        try {

            Mail::send(
                [
                    'html' => 'mail_templates.guest_account_created',
                    'text' => 'mail_templates.text.guest_account_created'
                ],
                ['data' => $data, 'brand' => $brand, 'lang' => $lang],
                function ($message) use ($user, $subject, $brand) {
                    $message->to($user->email, $user->name)
                        ->subject($subject);

                    MailDeliverability::transactional($message, $brand, 'account-created');
                });

        } catch (\Exception $ex) {

            /*
             * The order is already placed and its own confirmation is a
             * separate message. A failure here must not surface as an order
             * failure, so it is logged and left.
             */
            \Illuminate\Support\Facades\Log::error('Guest account credentials email failed', [
                'user_id' => $user->id,
                'message' => $ex->getMessage()
            ]);

            return false;
        }

        return true;
    }


    /**
     * Superseded. Kept as a redirect, not as a sender.
     *
     * Same reasoning as orderPlaced() above: this rendered and sent a status
     * email directly, so calling it put a message in front of a customer
     * without claiming the event -- which is how an order that is set to
     * "delivered" twice sends two emails. The rendering it used to do lives in
     * OrderMailer::statusChanged(), where the queue can retry it.
     */
    public static function orderStatusChanged($order, $newStatus, $lang = null)
    {
        if (is_null($order)) {
            return false;
        }

        $event = OrderEmailEvent::forStatus($newStatus);

        if (is_null($event)) {
            return false;
        }

        return OrderEmailDispatcher::dispatch($order, $event, $lang, [
            'status' => (int)$newStatus,
        ]);
    }

    /**
     * Tell a customer what was decided about their trade application.
     *
     * One method for both outcomes: the plumbing that matters — a recipient
     * we can actually write to, transactional headers, and a failure that is
     * logged rather than thrown — is identical, and splitting it in two is how
     * one of them quietly loses its guard.
     *
     * Deliberately never throws. By the time this runs the decision is already
     * committed and the customer's pricing has already changed; a mail server
     * refusing a connection must not turn a saved approval into an error the
     * admin sees, and must never roll one back.
     *
     * @param  \App\Models\User $user     The customer, already at their new status.
     * @param  string           $decision User::TRADE_APPROVED or TRADE_REJECTED.
     * @param  array            $options  For an upgrade application (AccountUpgrade):
     *                                    'reason' - the note on the application, which
     *                                    is where an upgrade keeps it (a rejected
     *                                    upgrade never touches trade_review_note);
     *                                    'still_business' - the customer stays a
     *                                    business account, so the rejection must not
     *                                    tell them they are back on retail prices.
     * @return bool                       Whether the message was handed to the mailer.
     */
    public static function tradeApplicationReviewed($user, $decision, $lang = null, array $options = [])
    {
        $decisions = [
            \App\Models\User::TRADE_APPROVED => [
                'html' => 'mail_templates.trade_approved',
                'text' => 'mail_templates.text.trade_approved',
                'subject' => 'lang.mail_trade_approved_subject',
                'category' => 'trade-approved',
            ],
            \App\Models\User::TRADE_REJECTED => [
                'html' => 'mail_templates.trade_rejected',
                'text' => 'mail_templates.text.trade_rejected',
                'subject' => 'lang.mail_trade_rejected_subject',
                'category' => 'trade-rejected',
            ],
        ];

        if (!isset($decisions[$decision])) {
            /*
             * Suspension and a return to pending are real transitions with no
             * customer email defined for them. Silently doing nothing is the
             * right answer, but it is worth a line when it happens so an
             * unhandled decision does not look like a delivery failure.
             */
            \Illuminate\Support\Facades\Log::info('No trade notification defined for this decision', [
                'user_id' => $user ? $user->id : null,
                'decision' => $decision
            ]);

            return false;
        }

        /*
         * A customer with no address, or one that is not an address, is not a
         * delivery failure — it is an account we were never able to write to.
         * Caught here so it is logged as what it is and never reaches the
         * mailer, which would throw.
         */
        if (!$user || !$user->email || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {

            \Illuminate\Support\Facades\Log::warning('No usable address for trade decision email', [
                'user_id' => $user ? $user->id : null,
                'decision' => $decision
            ]);

            return false;
        }

        $template = $decisions[$decision];

        $brand = MailBrand::make($user->name, $lang);

        $data = new \stdClass();
        $data->receiver = $user->name;
        $data->email = $user->email;
        $data->decision = $decision;

        /*
         * The company the application was made in the name of, when there is
         * one. Read from the profile rather than passed in, so the email
         * cannot describe an application other than the one on file.
         */
        $profile = $user->businessProfile;
        $data->company_name = $profile && $profile->company_name ? $profile->company_name : null;

        /*
         * The note the reviewer left. This is the same text the customer
         * already reads on their dashboard under "Note from us", which is what
         * makes it safe to send — it is a customer-facing decision note, not
         * an internal record. Nothing else from the review is included: no
         * reviewer identity, no internal ids, no verification detail.
         */
        $data->reason = static::customerSafeNote(
            array_key_exists('reason', $options) ? $options['reason'] : $user->trade_review_note
        );

        $data->still_business = !empty($options['still_business']);

        $data->account_url = $brand->account_url;
        $data->shop_url = $brand->shop_url;
        $data->support_email = $brand->support_email;
        $data->contact_url = $brand->contact_url;

        $data->address = $brand->address;
        $data->phone = $brand->phone;
        $data->store_name = $brand->store_name;

        $subject = MailDeliverability::subject(__($template['subject'], [], $lang));

        try {

            Mail::send(
                ['html' => $template['html'], 'text' => $template['text']],
                ['data' => $data, 'brand' => $brand, 'setting' => $brand, 'lang' => $lang],
                function ($message) use ($user, $subject, $brand, $template) {
                    $message->to($user->email, $user->name)
                        ->subject($subject);

                    MailDeliverability::transactional($message, $brand, $template['category']);
                });

        } catch (\Exception $ex) {

            /*
             * The decision stands. Logged with the detail needed to re-send by
             * hand, and swallowed so the admin's request still reports the
             * approval or rejection that did succeed.
             */
            \Illuminate\Support\Facades\Log::error('Trade decision email failed', [
                'user_id' => $user->id,
                'decision' => $decision,
                'message' => $ex->getMessage()
            ]);

            return false;
        }

        return true;
    }

    /**
     * Personal -> business, done by the customer (AccountUpgrade::upgradeToBusiness).
     *
     * The change is already made when this runs, so it is a confirmation -
     * and, because the account type decides what the customer is charged and
     * what their invoices say, a "was this you?" as well.
     *
     * Never throws, like the trade decision above: the upgrade stands whether
     * or not the mail server takes the message.
     */
    public static function accountUpgradedToBusiness($user, $lang = null)
    {
        return static::sendAccountMail($user, [
            'html' => 'mail_templates.account_upgraded_business',
            'text' => 'mail_templates.text.account_upgraded_business',
            'subject' => 'lang.mail_upgrade_business_subject',
            'category' => 'account-upgraded-business',
        ], [], $lang);
    }

    /**
     * An existing customer has applied for trade (AccountUpgrade::applyForTrade).
     *
     * Says the application exists and what happens meanwhile - which differs:
     * a business customer keeps business pricing, a personal one buys at
     * retail. Read from the application, not the account, so the email
     * describes what was actually submitted.
     */
    public static function tradeApplicationReceived($user, $upgrade, $lang = null)
    {
        $details = $upgrade ? (array)$upgrade->details : [];

        return static::sendAccountMail($user, [
            'html' => 'mail_templates.trade_application_received',
            'text' => 'mail_templates.text.trade_application_received',
            'subject' => 'lang.mail_trade_received_subject',
            'category' => 'trade-application-received',
        ], [
            'company_name' => $details['company_name'] ?? null,
            'trade' => $details['trade'] ?? null,
            'from_business' => $upgrade && $upgrade->from_type === 'business',
        ], $lang);
    }

    /**
     * The plumbing the account emails share: a recipient we can write to, the
     * brand fields every template reads, transactional headers, and a failure
     * that is logged rather than thrown.
     *
     * $fields are template-specific values; company_name falls back to the
     * account's profile when the caller does not supply one.
     */
    private static function sendAccountMail($user, array $template, array $fields, $lang = null)
    {
        if (!$user || !$user->email || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {

            \Illuminate\Support\Facades\Log::warning('No usable address for account email', [
                'user_id' => $user ? $user->id : null,
                'category' => $template['category']
            ]);

            return false;
        }

        $brand = MailBrand::make($user->name, $lang);

        $data = new \stdClass();
        $data->receiver = $user->name;
        $data->email = $user->email;

        $profile = $user->businessProfile;
        $data->company_name = $profile && $profile->company_name ? $profile->company_name : null;

        foreach ($fields as $key => $value) {
            $data->{$key} = $value;
        }

        $data->account_url = $brand->account_url;
        $data->shop_url = $brand->shop_url;
        $data->application_url = rtrim($brand->shop_url, '/') . '/user/profile#upgrade';
        $data->support_email = $brand->support_email;
        $data->contact_url = $brand->contact_url;

        $data->address = $brand->address;
        $data->phone = $brand->phone;
        $data->store_name = $brand->store_name;

        $subject = MailDeliverability::subject(__($template['subject'], [], $lang));

        try {

            Mail::send(
                ['html' => $template['html'], 'text' => $template['text']],
                ['data' => $data, 'brand' => $brand, 'setting' => $brand, 'lang' => $lang],
                function ($message) use ($user, $subject, $brand, $template) {
                    $message->to($user->email, $user->name)
                        ->subject($subject);

                    MailDeliverability::transactional($message, $brand, $template['category']);
                });

        } catch (\Exception $ex) {

            \Illuminate\Support\Facades\Log::error('Account email failed', [
                'user_id' => $user->id,
                'category' => $template['category'],
                'message' => $ex->getMessage()
            ]);

            return false;
        }

        return true;
    }


    /**
     * Trim the reviewer's note down to something fit to send.
     *
     * The note is customer-facing by design — the dashboard has always shown
     * it — but "customer-facing" and "arbitrary length" are different
     * promises, and a reviewer pasting half a case file into the box should
     * not produce an email nobody can read. Blank and whitespace-only notes
     * come back as null so the templates leave the block out entirely rather
     * than printing an empty heading.
     */
    public static function customerSafeNote($note, $limit = 600)
    {
        if (!is_string($note)) {
            return null;
        }

        $note = trim(preg_replace('/\s+/u', ' ', $note));

        if ($note === '') {
            return null;
        }

        if (mb_strlen($note) > $limit) {
            $note = rtrim(mb_substr($note, 0, $limit - 1)) . '…';
        }

        return $note;
    }

    /**
     * Ask a supplier to price the procurement lines of an order.
     *
     * The link is the whole credential -- there is no supplier login -- so
     * this goes to the address on the supplier record and nowhere else, and
     * it is transactional rather than bulk: it is a direct consequence of an
     * order, not a mailing.
     *
     * Returns false rather than throwing, because the caller has to be able
     * to try another channel and, failing everything, leave no notification
     * row behind. A supplier recorded as notified is never asked again.
     *
     * @param  array  $items  one line each, already read as "Title xN"
     * @return bool           whether the message was accepted for delivery
     */
    /**
     * "We are holding your items for N more minutes" - sent once, at the
     * 5-minute mark of a basket reservation (App\Models\Helper\CartHold).
     * $recipient is a User or a GuestUser with an email.
     */
    public static function cartHoldReminder($recipient, array $lines, $minutesLeft, $lang = null)
    {
        if (!$recipient || !$recipient->email) {
            return false;
        }

        $brand = MailBrand::make($recipient->name, $lang);

        $data = new \stdClass();
        $data->receiver = $recipient->name;
        $data->lines = $lines;
        $data->minutes_left = (int)$minutesLeft;
        $data->basket_url = rtrim($brand->site_url, '/') . '/cart';

        $subject = MailDeliverability::subject(
            'Your basket is reserved for ' . (int)$minutesLeft . ' more minutes'
        );

        try {
            Mail::send(
                [
                    'html' => 'mail_templates.cart_reminder',
                    'text' => 'mail_templates.text.cart_reminder'
                ],
                ['data' => $data, 'brand' => $brand, 'lang' => $lang],
                function ($message) use ($recipient, $subject, $brand) {
                    $message->to($recipient->email, $recipient->name)
                        ->subject($subject);

                    MailDeliverability::transactional($message, $brand, 'cart-reminder');
                });
        } catch (\Exception $ex) {
            \Illuminate\Support\Facades\Log::error('Basket reminder email failed', [
                'email' => $recipient->email,
                'message' => $ex->getMessage()
            ]);

            return false;
        }

        return true;
    }


    /**
     * Members hear about new products first (Helper\NewProductAlerts).
     * $products are rows with title, image, url and price. Returns whether
     * the message was accepted.
     */
    public static function newProductAlert($user, array $products, $planName, $moreCount = 0, $lang = null)
    {
        if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $brand = MailBrand::make($user->name, $lang);
        $unsubscribe = null;

        try {
            $unsubscribe = \Illuminate\Support\Facades\URL::signedRoute('membership.digest.unsubscribe', ['user' => $user->id]);
        } catch (\Throwable $e) {
            // without the link the email still goes; the header is left off
        }

        $data = new \stdClass();
        $data->receiver = $user->name;
        $data->products = $products;
        $data->plan = $planName;
        $data->more = (int)$moreCount;
        $data->shop_url = rtrim($brand->site_url, '/');
        $data->club_url = rtrim($brand->site_url, '/') . '/user/savers-club';
        $data->unsubscribe_url = $unsubscribe;

        $count = count($products) + (int)$moreCount;
        $subject = MailDeliverability::subject(
            $count === 1 ? 'Just arrived: ' . $products[0]->title : $count . ' new products just arrived'
        );

        try {
            Mail::send(
                [
                    'html' => 'mail_templates.member_new_products',
                    'text' => 'mail_templates.text.member_new_products',
                ],
                ['data' => $data, 'brand' => $brand, 'lang' => $lang],
                function ($message) use ($user, $subject, $brand, $unsubscribe) {
                    $message->to($user->email, $user->name)->subject($subject);

                    MailDeliverability::memberDigest($message, $brand, $unsubscribe);
                }
            );
        } catch (\Exception $ex) {
            \Illuminate\Support\Facades\Log::error('Member new-products email failed', [
                'user' => $user->id,
                'message' => $ex->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    public static function supplierProcurementRequest($supplier, $order, array $items, $link, $expiresInDays, $lang = null)
    {
        if (!$supplier || !filter_var($supplier->email, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Log::warning('Supplier has no usable email address', [
                'supplier_id' => $supplier->id ?? null,
                'order_id' => $order->id ?? null,
            ]);

            return false;
        }

        $brand = MailBrand::make($supplier->name, $lang);

        $subject = MailDeliverability::subject(
            'New fulfilment request - order ' . $order->order
        );

        $data = new \stdClass();
        $data->receiver = $supplier->name;
        $data->order = $order->order;
        $data->items = $items;
        $data->link = $link;
        $data->expires_in_days = $expiresInDays;

        try {
            Mail::send(
                [
                    'html' => 'mail_templates.supplier_procurement_request',
                    'text' => 'mail_templates.text.supplier_procurement_request',
                ],
                ['data' => $data, 'brand' => $brand, 'lang' => $lang],
                function ($message) use ($supplier, $subject, $brand) {
                    $message->to($supplier->email, $supplier->name)->subject($subject);

                    MailDeliverability::transactional($message, $brand, 'procurement-request');
                }
            );

            return true;

        } catch (\Exception $ex) {
            \Illuminate\Support\Facades\Log::warning('Supplier procurement email failed', [
                'supplier_id' => $supplier->id,
                'order_id' => $order->id ?? null,
                'reason' => $ex->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Email gift card codes to whoever they are for: the recipient when the
     * purchase was a gift, otherwise the buyer. Records where each code went.
     * Never throws: a mail failure must not undo a paid purchase.
     */
    public static function giftCardDelivery($order, $codes, $lang = null)
    {
        $buyer = \App\Models\User::find($order->user_id);
        $isGift = !empty($order->recipient_email);

        $email = $isGift ? $order->recipient_email : optional($buyer)->email;
        $name = $isGift ? ($order->recipient_name ?: $order->recipient_email) : optional($buyer)->name;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \Illuminate\Support\Facades\Log::warning('Gift card email has no usable address', ['gift_order_id' => $order->id]);
            return false;
        }

        $brand = MailBrand::make($name, $lang);
        $design = optional(\App\Models\GiftVoucher::find($order->voucher_id))->title ?: 'Gift card';

        $data = new \stdClass();
        $data->receiver = $name;
        $data->is_gift = $isGift;
        $data->sender_name = $isGift ? ($order->sender_name ?: optional($buyer)->name) : null;
        $data->message = $isGift ? $order->message : null;
        $data->design = $design;
        $data->codes = collect($codes)->map(function ($c) {
            return (object)['code' => $c->code, 'amount' => $c->amount];
        })->all();
        $first = collect($codes)->first();
        $data->expires = $first && $first->expires_at ? $first->expires_at->format('j F Y') : '';
        $data->redeem_url = rtrim($brand->site_url, '/') . '/user/vouchers';

        $subject = MailDeliverability::subject($isGift
            ? (($data->sender_name ?: 'Someone') . ' sent you a ' . $brand->store_short . ' gift card')
            : 'Your ' . $brand->store_short . ' gift card');

        try {
            Mail::send(
                ['html' => 'mail_templates.gift_card', 'text' => 'mail_templates.text.gift_card'],
                ['data' => $data, 'brand' => $brand, 'lang' => $lang],
                function ($message) use ($email, $name, $subject, $brand) {
                    $message->to($email, $name)->subject($subject);
                    MailDeliverability::transactional($message, $brand, 'gift-card');
                }
            );

            \App\Models\GiftVoucherCode::whereIn('id', collect($codes)->pluck('id'))
                ->update(['sent_to' => $email, 'sent_at' => now()]);

            return true;

        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Gift card email failed', [
                'gift_order_id' => $order->id,
                'reason' => $ex->getMessage(),
            ]);

            return false;
        }
    }
}
