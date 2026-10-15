<?php

namespace App\Models\Helper;

use App\Models\Order;
use App\Models\OrderedProduct;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Mail;

/**
 * Builds and sends one order email. Nothing else.
 *
 * Every method here THROWS on failure, and that is the point. The previous
 * arrangement sent the receipt from inside a controller action that caught
 * everything and turned it into a JSON error, so the one caller who needed to
 * know whether the send had worked -- and whether the failure was worth
 * retrying -- got back an HTTP body and had to parse a status code out of it.
 * A queued job cannot retry what it is not told about.
 *
 * Deciding *whether* to send (idempotency, a usable recipient) belongs to
 * OrderEmailDispatcher; deciding whether a failure is worth repeating belongs
 * to MailFailure. This class only renders and hands to the mailer.
 */
class OrderMailer
{
    /**
     * Send the email for an event.
     *
     * @param  Order  $order
     * @param  string $event     An OrderEmailEvent constant.
     * @param  string $recipient Already validated by the dispatcher.
     * @param  string $name
     * @param  string $lang
     * @param  array  $context   Event-specific extras (refund amount, decline reason).
     * @return bool
     *
     * @throws \Throwable Whatever the mailer threw, unchanged, so the caller
     *                    can classify it.
     */
    public static function send(Order $order, $event, $recipient, $name, $lang = null, array $context = [])
    {
        switch ($event) {

            case OrderEmailEvent::ORDER_PLACED:
                return static::orderPlaced($order, $recipient, $name, $lang, $context);

            case OrderEmailEvent::ORDER_DELIVERED:
                return static::delivered($order, $recipient, $name, $lang);

            case OrderEmailEvent::PAYMENT_FAILED:
                return static::paymentFailed($order, $recipient, $name, $lang, $context);

            case OrderEmailEvent::PAYMENT_RECEIVED:
                return static::paymentReceived($order, $recipient, $name, $lang);

            default:
                /*
                 * Confirmed, picked up, on the way, cancelled, returned and
                 * refunded all render from the one status template. They differ
                 * only in a label and a tone the template derives from it, and
                 * six near-identical blades is six places for one of them to
                 * drift.
                 */
                return static::statusChanged($order, $event, $recipient, $name, $lang, $context);
        }
    }

    /**
     * The receipt.
     *
     * Lifted wholesale out of OrdersController::sendOrderEmail so the same
     * code serves the queued job and the public endpoint. Behaviour is
     * unchanged -- the vendor copies, the optional PDF and the translated-PDF
     * switch all work exactly as they did -- except that a failure now leaves
     * as an exception instead of a 200 with an error body.
     */
    public static function orderPlaced(Order $order, $recipient, $name, $lang = null, array $context = [])
    {
        $request = static::contextRequest($context, $lang);

        $mailDataLang = MailHelper::sendingOrderEmail($request, $order->id, $lang);
        $mailData = MailHelper::sendingOrderEmail($request, $order->id);

        if (is_null($mailData) || is_null($mailDataLang)) {
            throw new \InvalidArgumentException(
                'Order ' . $order->id . ' has no mailable data'
            );
        }

        $setting = $mailData['setting'];
        $orderData = $mailData['order'];

        $mailDataLang['bankTransfer'] = static::bankTransferDetails($order);

        $adminSetting = Setting::select('attach_pdf', 'send_seller_email', 'translate_pdf')->first();

        $subject = MailDeliverability::subject(
            __('lang.mail_subject_order', ['order' => $orderData['order']], $lang)
        );

        /*
         * Vendor copies first, and inside their own guard.
         *
         * These go to staff, not to the customer. A vendor address that no
         * longer exists must not cost the customer their receipt, and must not
         * mark the whole event failed and have the queue replay it -- which
         * would send the customer a second copy of an email that did arrive.
         */
        if ($adminSetting && $adminSetting->send_seller_email) {
            try {
                static::vendorCopies($order, $mailDataLang, $lang);

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Vendor order email failed', [
                    'order_id' => $order->id,
                    'message' => MailFailure::summarise($e),
                ]);
            }
        }

        $pdf = null;

        if ($adminSetting && $adminSetting->attach_pdf) {

            $html = View::make(
                'mail_templates.order_pdf',
                $adminSetting->translate_pdf ? $mailDataLang : $mailData
            )->render();

            $pdf = new \Mpdf\Mpdf(['tempDir' => public_path('/uploads')]);
            $pdf->autoScriptToLang = true;
            $pdf->autoLangToFont = true;
            $pdf->WriteHTML($html);
        }

        Mail::send(
            ['html' => 'mail_templates.order_placed', 'text' => 'mail_templates.text.order_placed'],
            $mailDataLang,
            function ($message) use ($setting, $pdf, $orderData, $subject, $name, $recipient) {

                $message->to($recipient, $name)->subject($subject);

                if ($pdf) {
                    $message->attachData($pdf->Output('', 'S'), $orderData['order'] . '.pdf');
                }

                MailDeliverability::transactional($message, $setting,
                    OrderEmailEvent::category(OrderEmailEvent::ORDER_PLACED));
            });

        return true;
    }

    /**
     * Where to send the money, for a bank transfer order still waiting on it.
     *
     * The receipt used to say "Bank transfer" and nothing else, so a customer
     * who closed the bank details page had no record of the account, the
     * amount or the reference. Null for every other order, and for a bank
     * order already marked paid.
     */
    public static function bankTransferDetails(Order $order)
    {
        if ((int)$order->order_method !== (int)Config::get('constants.paymentMethod.BANK')
            || (int)$order->payment_done === 1) {
            return null;
        }

        $bank = Payment::publicSettings();

        if (!$bank) {
            return null;
        }

        $compact = function ($value) {
            return strtoupper(preg_replace('/\s+/', '', (string)$value));
        };

        $iban = $bank['iban'] ?? null;

        // Settings saved before the IBAN field existed kept it here.
        if (!$iban && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $compact($bank['account_number'] ?? ''))) {
            $iban = $bank['account_number'];
        }

        $accountNumber = $bank['account_number'] ?? null;

        if ($accountNumber && $compact($accountNumber) === $compact($iban)) {
            $accountNumber = null;
        }

        return array_filter([
            'account_name' => $bank['account_name'] ?? null,
            'bank_name' => $bank['bank_name'] ?? null,
            'iban' => $iban,
            'bic_swift' => $bank['bic_swift'] ?? null,
            'account_number' => $accountNumber,
            'hold_hours' => \App\Console\Commands\CancelUnpaidBankTransfers::holdHours(),
        ]);
    }

    /**
     * Tell the shop a customer says they have sent a bank transfer.
     *
     * Nothing used to: the reference sat on the order until someone happened
     * to open it. It matters more since the unpaid-transfer sweep leaves an
     * order with a reference alone -- from then on the order is waiting on
     * staff, and this is how they find out. Plain text to the support
     * address, synchronous, and never the reason the customer's save fails.
     *
     * @return bool whether a message was handed to the mailer
     */
    public static function staffTransferReference(Order $order, $reference, $previous = null)
    {
        $brand = MailBrand::make();
        $to = $brand->support_email;

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // The same figure the customer's bank page asks them to send.
        $amount = $brand->currency_icon . number_format((float)Utils::calcPrice($order)['total_price'], 2);
        $adminUrl = rtrim((string)config('env.url.APP_URL'), '/') . '/admin/orders/' . $order->id;

        $lines = [
            $previous
                ? 'A customer has corrected the bank transfer reference on an order.'
                : 'A customer says they have sent a bank transfer.',
            '',
            'Order: ' . $order->order,
            'Amount due: ' . $amount,
            'Reference given: ' . $reference,
        ];

        if ($previous) {
            $lines[] = 'Previous reference: ' . $previous;
        }

        $lines = array_merge($lines, [
            'Customer: ' . trim(($order->contact_name ?: '') . ' ' . ($order->contact_email ? '<' . $order->contact_email . '>' : '')),
            '',
            'Check the bank account, then mark the order paid:',
            $adminUrl,
            '',
            'This order will not be cancelled automatically while it has a reference.',
        ]);

        Mail::raw(implode("\n", $lines), function ($message) use ($to, $order) {
            $message->to($to)->subject('Bank transfer to check: order ' . $order->order);
        });

        return true;
    }

    /**
     * Tell the shop a customer has been charged for an order that is already
     * cancelled, so someone refunds (or reinstates) it and speaks to them.
     *
     * Reached when a gateway confirms a payment after the order was
     * cancelled -- the customer was on the card screen when the
     * unpaid-transfer sweep or an admin cancelled it. It used to be a log
     * line. Always logged as well, and never throws: it runs inside a payment
     * confirmation, and a mail problem must not turn a recorded payment into
     * an error.
     *
     * @return bool whether a message was handed to the mailer
     */
    public static function alertPaidCancelledOrder(Order $order)
    {
        \Illuminate\Support\Facades\Log::warning('Payment taken for a cancelled order; it needs refunding', [
            'order_id' => $order->id,
            'order_method' => (int)$order->order_method,
            'trans_id' => $order->trans_id,
        ]);

        try {
            $brand = MailBrand::make();
            $to = $brand->support_email;

            if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            $adminUrl = rtrim((string)config('env.url.APP_URL'), '/') . '/admin/orders/' . $order->id;

            $lines = [
                'A customer has been charged for an order that is already cancelled.',
                '',
                'Order: ' . $order->order,
                'Amount charged: ' . $brand->currency_icon . number_format((float)$order->total_amount, 2),
                'Paid by: ' . static::paymentMethodLabel($order),
                'Gateway reference: ' . ($order->trans_id ?: 'none recorded'),
                'Customer: ' . trim(($order->contact_name ?: '') . ' ' . ($order->contact_email ? '<' . $order->contact_email . '>' : '')),
                '',
                'The customer has not been sent a payment confirmation. Please refund',
                'the payment through the gateway, or reinstate the order, and let them know:',
                $adminUrl,
                '',
                'In the admin order list, the Paid + Cancelled filters show every order like this.',
            ];

            Mail::raw(implode("\n", $lines), function ($message) use ($to, $order) {
                $message->to($to)->subject('Action needed: payment taken for cancelled order ' . $order->order);
            });

            return true;

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Paid-cancelled order alert could not be sent', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * One copy per vendor, carrying only the lines that vendor is responsible
     * for. Unchanged from the controller version it was moved out of.
     */
    private static function vendorCopies(Order $order, $mailDataLang, $lang = null)
    {
        $query = OrderedProduct::query();

        if ($lang) {

            $query = $query->with(['product_with_admin' => function ($q) use ($lang) {
                $q->with('admin');
                $q->leftJoin('product_langs as pl', function ($join) use ($lang) {
                    $join->on('products.id', '=', 'pl.product_id');
                    $join->where('pl.lang', $lang);
                })
                    ->select('products.id', 'products.title', 'products.image', 'products.selling',
                        'products.offered', 'products.shipping_rule_id', 'products.admin_id',
                        'products.bundle_deal_id', 'products.unit', 'pl.title');
            }]);

            $query = $query->with(['updated_inventory.inventory_attributes.attribute_value' => function ($q) use ($lang) {
                $q->leftJoin('attribute_value_langs as avl', function ($join) use ($lang) {
                    $join->on('attribute_values.id', '=', 'avl.attribute_value_id');
                    $join->where('avl.lang', $lang);
                })
                    ->with(['attribute' => function ($q2) use ($lang) {
                        $q2->leftJoin('attribute_langs as al', function ($join) use ($lang) {
                            $join->on('attributes.id', '=', 'al.attribute_id');
                            $join->where('al.lang', $lang);
                        })
                            ->select('attributes.id',
                                \DB::raw('COALESCE(al.title, attributes.title) as title'));
                    }])
                    ->select('attribute_values.*',
                        \DB::raw('COALESCE(avl.title, attribute_values.title) as title'));
            }]);

        } else {

            $query = $query->with('product_with_admin.admin')
                ->with('updated_inventory.inventory_attributes.attribute_value.attribute');
        }

        $lines = $query->with('shipping_place')
            ->where('order_id', $order->id)
            ->get();

        $byAdmin = [];

        foreach ($lines as $line) {

            /*
             * A line whose product or admin has since been deleted has no
             * address to write to. The original dereferenced this chain
             * unguarded, so one removed product threw and took the customer's
             * receipt down with it.
             */
            if (!$line->product_with_admin || !$line->product_with_admin->admin) {
                continue;
            }

            $email = $line->product_with_admin->admin->email;

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (!array_key_exists($email, $byAdmin)) {
                $byAdmin[$email] = [];
            }

            $byAdmin[$email][] = $line;
        }

        /*
         * What each vendor's lines were charged, from the order's own rows
         * (OrderSummary). The template used to add these up itself: each
         * line's shipping row at today's price, so a two-line order showed
         * delivery twice when it is charged once; no VAT; and no discount -
         * a total the customer was never asked for.
         */
        $summaryLines = [];

        foreach (OrderSummary::for($mailDataLang['order'])['lines'] as $summaryLine) {
            $summaryLines[$summaryLine['product_id'] . ':' . $summaryLine['inventory_id']] = $summaryLine;
        }

        $orderLineCount = count($mailDataLang['order']['ordered_products'] ?? []);

        $base = clone $mailDataLang['order'];
        unset($base['ordered_products']);
        unset($base['setting']);

        foreach ($byAdmin as $email => $vendorLines) {

            $adminName = $vendorLines[0]->product_with_admin->admin->name;

            // a copy each: the same object went to every vendor
            $orderCopy = clone $base;
            $orderCopy['ordered_products'] = $vendorLines;

            /*
             * The whole order is this vendor's: they get the customer's own
             * totals (vendor_totals null). Part of it: what their lines were
             * charged - goods, the VAT added to them and any delivery charged
             * on them. Order-wide discounts belong to no one line and are
             * left out, which the email says.
             */
            $orderCopy['vendor_totals'] = null;

            if (count($vendorLines) < $orderLineCount) {
                $share = ['goods' => 0.0, 'tax' => 0.0, 'shipping' => 0.0];

                foreach ($vendorLines as $vendorLine) {
                    $line = $summaryLines[$vendorLine->product_id . ':' . $vendorLine->inventory_id] ?? null;

                    if ($line) {
                        $share['goods'] += $line['line_total'] - $line['bundle_offer_value'];
                        $share['tax'] += $line['tax_total'];
                        $share['shipping'] += $line['shipping_price'];
                    }
                }

                $share = array_map([Pricing::class, 'round'], $share);
                $share['total'] = Pricing::round($share['goods'] + $share['tax'] + $share['shipping']);

                $orderCopy['vendor_totals'] = $share;
            }

            $payload = [];
            $payload['setting'] = clone $mailDataLang['setting'];
            $payload['order'] = $orderCopy;
            $payload['lang'] = $lang;
            $payload['setting']->receiver = $adminName;
            $payload['setting']->receiver_first = explode(' ', trim($adminName))[0];
            $payload['brand'] = $payload['setting'];

            $subject = MailDeliverability::subject(
                __('lang.mail_subject_vendor_order', ['order' => $mailDataLang['order']['order']], $lang)
            );

            Mail::send(
                [
                    'html' => 'mail_templates.order_placed_to_vendor',
                    'text' => 'mail_templates.text.order_placed_to_vendor',
                ],
                $payload,
                function ($message) use ($payload, $email, $adminName, $subject) {
                    $message->to($email, $adminName)->subject($subject);

                    MailDeliverability::transactional($message, $payload['setting'], 'vendor-order');
                });
        }
    }

    /**
     * "Your order has been delivered." Its own template because it is the one
     * status with something to say beyond the status itself.
     */
    public static function delivered(Order $order, $recipient, $name, $lang = null)
    {
        $request = static::contextRequest([], $lang);

        $mailData = MailHelper::sendingOrderEmail($request, $order->id, $lang);

        if (is_null($mailData)) {
            throw new \InvalidArgumentException(
                'Order ' . $order->id . ' has no mailable data'
            );
        }

        $subject = MailDeliverability::subject(
            __('lang.mail_subject_delivered', ['order' => $mailData['order']['order']], $lang)
        );

        Mail::send(
            ['html' => 'mail_templates.package_delivered', 'text' => 'mail_templates.text.package_delivered'],
            $mailData,
            function ($message) use ($mailData, $recipient, $name, $subject) {
                $message->to($recipient, $name)->subject($subject);

                MailDeliverability::transactional($message, $mailData['setting'],
                    OrderEmailEvent::category(OrderEmailEvent::ORDER_DELIVERED));
            });

        return true;
    }

    /**
     * Confirmed / picked up / on the way / cancelled / returned / refunded.
     *
     * One template, driven by a label and whatever extra rows the event
     * carries -- a refund puts the amount and the provider reference in the
     * summary panel, because "your order is Refunded" without a figure is the
     * email that generates the support ticket it was meant to prevent.
     */
    public static function statusChanged(Order $order, $event, $recipient, $name, $lang = null, array $context = [])
    {
        $status = isset($context['status'])
            ? (int)$context['status']
            : (int)OrderEmailEvent::statusFor($event);

        $labels = Config::get('constants.orderStatusIn');
        $label = isset($labels[$status]) ? $labels[$status] : 'Updated';

        $setting = Setting::first();

        // Draws the header, footer, logo, colours and the greeting's name.
        $brand = MailBrand::make($name, $lang);

        $data = new \stdClass();
        $data->receiver = $name;
        $data->name = $name;
        $data->email = $recipient;
        $data->order = $order->order;
        $data->order_id = $order->id;
        $data->status = $status;
        $data->status_label = $label;
        $data->total_amount = $order->total_amount;
        $data->currency = $order->currency;
        $data->currency_icon = $setting ? $setting->currency_icon : '';
        $data->store_name = $brand->store_name;
        $data->extra_rows = static::extraRows($event, $context, $setting, $lang);

        $subject = MailDeliverability::subject(__('lang.order_status_subject', [
            'order' => $order->order,
            'status' => $label,
        ], $lang));

        Mail::send(
            [
                'html' => 'mail_templates.order_status_changed',
                'text' => 'mail_templates.text.order_status_changed',
            ],
            [
                'data' => $data,
                'setting' => $brand,
                'brand' => $brand,
                'lang' => $lang,
            ],
            function ($message) use ($recipient, $name, $subject, $brand, $event) {
                $message->to($recipient, $name)->subject($subject);

                MailDeliverability::transactional($message, $brand,
                    OrderEmailEvent::category($event));
            });

        return true;
    }

    /**
     * Money that arrived after the receipt had already gone out.
     *
     * Rendered by the status template with its own label rather than a status
     * number, because "payment received" is not one of the eight order
     * statuses and pretending otherwise would put a wrong Status: line in
     * front of the customer.
     */
    public static function paymentReceived(Order $order, $recipient, $name, $lang = null)
    {
        $setting = Setting::first();
        $brand = MailBrand::make($name, $lang);

        $data = new \stdClass();
        $data->receiver = $name;
        $data->name = $name;
        $data->email = $recipient;
        $data->order = $order->order;
        $data->order_id = $order->id;
        $data->status = (int)$order->status;
        $data->status_label = __('lang.mail_payment_received_label', [], $lang);
        $data->total_amount = $order->total_amount;
        $data->currency = $order->currency;
        $data->currency_icon = $setting ? $setting->currency_icon : '';
        $data->store_name = $brand->store_name;
        $data->extra_rows = [];

        $subject = MailDeliverability::subject(
            __('lang.mail_payment_received_subject', ['order' => $order->order], $lang)
        );

        Mail::send(
            [
                'html' => 'mail_templates.order_status_changed',
                'text' => 'mail_templates.text.order_status_changed',
            ],
            ['data' => $data, 'setting' => $brand, 'brand' => $brand, 'lang' => $lang],
            function ($message) use ($recipient, $name, $subject, $brand) {
                $message->to($recipient, $name)->subject($subject);

                MailDeliverability::transactional($message, $brand,
                    OrderEmailEvent::category(OrderEmailEvent::PAYMENT_RECEIVED));
            });

        return true;
    }

    /**
     * A charge the gateway would not accept.
     *
     * Its own template: the customer needs to know the order is held rather
     * than lost, and what to do about it. Note what is NOT in it -- no gateway
     * error text, no decline code, no card detail. The internal reason is
     * logged for support; the customer is told their payment did not go
     * through, which is the only part of it they can act on.
     */
    public static function paymentFailed(Order $order, $recipient, $name, $lang = null, array $context = [])
    {
        $setting = Setting::first();
        $brand = MailBrand::make($name, $lang);

        $data = new \stdClass();
        $data->receiver = $name;
        $data->name = $name;
        $data->email = $recipient;
        $data->order = $order->order;
        $data->order_id = $order->id;
        $data->total_amount = $order->total_amount;
        $data->currency_icon = $setting ? $setting->currency_icon : '';
        $data->store_name = $brand->store_name;
        $data->method = static::paymentMethodLabel($order, $lang);

        $subject = MailDeliverability::subject(
            __('lang.mail_payment_failed_subject', ['order' => $order->order], $lang)
        );

        Mail::send(
            [
                'html' => 'mail_templates.payment_failed',
                'text' => 'mail_templates.text.payment_failed',
            ],
            ['data' => $data, 'setting' => $brand, 'brand' => $brand, 'lang' => $lang],
            function ($message) use ($recipient, $name, $subject, $brand) {
                $message->to($recipient, $name)->subject($subject);

                MailDeliverability::transactional($message, $brand,
                    OrderEmailEvent::category(OrderEmailEvent::PAYMENT_FAILED));
            });

        return true;
    }

    /**
     * The rows an event adds to the summary panel beyond order/status/total.
     */
    private static function extraRows($event, array $context, $setting, $lang = null)
    {
        $rows = [];

        // Why an order was cancelled, when it was not the customer's doing.
        if ($event === OrderEmailEvent::ORDER_CANCELLED && !empty($context['reason'])) {
            $rows[] = [
                'label' => 'Reason',
                'value' => (string)$context['reason'],
            ];
        }

        if ($event === OrderEmailEvent::ORDER_REFUNDED && isset($context['refund_amount'])) {

            $icon = $setting ? $setting->currency_icon : '';

            $rows[] = [
                'label' => __('lang.mail_refunded_amount', [], $lang),
                'value' => $icon . number_format((float)$context['refund_amount'], 2),
            ];

            /*
             * The provider's reference, which is what the customer's bank will
             * ask them for. Safe to send: it identifies the refund, not the
             * card or the account it is going back to.
             */
            if (!empty($context['refund_reference'])) {
                $rows[] = [
                    'label' => __('lang.mail_refund_reference', [], $lang),
                    'value' => (string)$context['refund_reference'],
                ];
            }
        }

        return $rows;
    }

    private static function paymentMethodLabel(Order $order, $lang = null)
    {
        $keys = Config::get('constants.paymentMethodKLangIn');

        if (isset($keys[$order->order_method])) {
            return __('lang.' . $keys[$order->order_method], [], $lang);
        }

        $names = Config::get('constants.paymentMethodIn');

        return isset($names[$order->order_method]) ? $names[$order->order_method] : '';
    }

    /**
     * MailHelper::sendingOrderEmail() reads a Request for the shopper's time
     * zone, so the receipt shows the order date in their own clock rather than
     * the server's. A queued job has no request of its own, so the time zone
     * is captured at the point the event happens and carried in the context.
     */
    private static function contextRequest(array $context, $lang = null)
    {
        $request = new Request();
        $request->merge(['token' => null]);

        if (!empty($context['time_zone'])) {
            $request->merge(['time_zone' => $context['time_zone']]);
        }

        if ($lang) {
            $request->headers->set('language', $lang);
        }

        return $request;
    }
}
