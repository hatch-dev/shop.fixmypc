<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Helper\MailBrand;
use App\Models\Helper\Pricing;
use App\Models\OrderedProduct;
use App\Models\SupplierBid;
use App\Models\SupplierNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The supplier side of procurement.
 *
 * A supplier has no account. The WhatsApp link is the whole credential: an
 * encrypted {order_id, supplier_id} that only this application can mint.
 * Both endpoints therefore derive who is asking from the token and never
 * from the request body -- the bid page used to hold a supplier_id of its
 * own, and it was always the empty string.
 */
class BidController extends Controller
{
    /**
     * How long a procurement link stays usable.
     *
     * The link is a bearer credential sitting in a WhatsApp thread forever,
     * and what it opens is a customer's order. Quotes are wanted in hours,
     * so anything this side of a month is well past useful.
     *
     * Age is measured from the notification rows rather than from the token,
     * because links minted before this existed carry no timestamp and their
     * rows do. From the NEWEST row: asking a supplier again about the same
     * order is what re-opens the link.
     */
    const LINK_TTL_DAYS = 30;

    public function orderDetail(Request $request, $token)
    {
        $context = $this->contextFor($token);

        if (!is_array($context)) {
            return $context;
        }

        $products = SupplierNotification::where([
            'supplier_id' => $context['supplier_id'],
            'order_id'    => $context['order_id'],
        ])
        ->with([
            'product' => function ($query) {
                $query->with('product_images.attributes')
                      ->select(
                          'id',
                          'title',
                          'image',
                          'unit'
                      );
            },

            'inventory.inventory_attributes.attribute_value.attribute',
            'bid',
        ])
        ->get();

        /*
         * One query for the order lines instead of one per item. The page
         * shows a handful, but this runs on a link anyone holding it can
         * reload.
         */
        $lines = OrderedProduct::where('order_id', $context['order_id'])
            ->get(['product_id', 'inventory_id', 'quantity', 'selling', 'tax_inclusive'])
            ->keyBy(function ($row) {
                return $row->product_id . ':' . (int)$row->inventory_id;
            });

        $products->each(function ($item) use ($lines) {
            $key = $item->product_id . ':' . (int)$item->inventory_id;

            $line = isset($lines[$key]) ? $lines[$key] : null;

            $item->quantity = $line ? (int)$line->quantity : 0;

            /*
             * What this line was charged at, per unit, so the form can open
             * on that figure rather than an empty box.
             *
             * ordered_products.selling is the charged unit price and is
             * written for every line -- the same column OrderSummary treats
             * as the source of truth for what the customer paid. Sent as
             * recorded, with the line's own VAT treatment beside it: this
             * catalogue runs two conventions and the line says which one
             * applied, so no VAT arithmetic is done here.
             */
            $item->ordered_price = $line ? Pricing::round((float)$line->selling) : null;

            $item->ordered_price_includes_vat = $line
                ? (bool)$line->tax_inclusive
                : null;
        });

        return response()->json([
            'order_id'     => $context['order_id'],
            'order_number' => Order::where('id', $context['order_id'])->value('order'),
            'supplier_id'  => $context['supplier_id'],
            'total_items'  => $products->count(),
            'grades'       => SupplierBid::GRADES,

            /*
             * Who the price goes to.
             *
             * A supplier opens this from a message, with no account and
             * no branding around the form, so "Price sent" on its own
             * does not say sent to whom. Taken from the site settings,
             * the same source the emails are branded from.
             */
            'store'        => MailBrand::make()->store_short,

            'data'         => $products,
        ]);
    }

    /**
     * Record what a supplier will supply the line for.
     *
     * Idempotent per notified line: a supplier revising a price updates their
     * bid rather than stacking a second one, which is what the unique index
     * on supplier_bids enforces.
     */
    public function submitBid(Request $request)
    {
        $context = $this->contextFor($request->token);

        if (!is_array($context)) {
            return $context;
        }

        $request->validate([
            'product_id'   => 'required|integer',
            'inventory_id' => 'nullable|integer',
            'price'        => 'required|numeric|min:0.01|max:9999999.99',
            'grade'        => ['required', Rule::in(SupplierBid::GRADES)],
        ]);

        /*
         * The line has to be one this supplier was actually asked about.
         * Without this the token for order A would let a supplier quote on
         * any product id they cared to name.
         */
        $notification = SupplierNotification::where([
            'supplier_id' => $context['supplier_id'],
            'order_id'    => $context['order_id'],
            'product_id'  => $request->product_id,
        ])
        ->where(function ($query) use ($request) {
            $inventoryId = $request->inventory_id;

            return $inventoryId
                ? $query->where('inventory_id', $inventoryId)
                : $query->whereNull('inventory_id');
        })
        ->first();

        if (!$notification) {
            return response()->json([
                'status'  => 404,
                'message' => 'That item is not part of this request.',
            ], 404);
        }

        $bid = SupplierBid::updateOrCreate(
            ['supplier_notification_id' => $notification->id],
            [
                'supplier_id'  => $notification->supplier_id,
                'order_id'     => $notification->order_id,
                'product_id'   => $notification->product_id,
                'inventory_id' => $notification->inventory_id,
                'price'        => $request->price,
                'grade'        => $request->grade,
            ]
        );

        return response()->json([
            'status'  => 200,
            'message' => 'Thanks - your price has been sent.',
            'data'    => $bid,
        ]);
    }

    /**
     * Turn a link token into the order and supplier it stands for.
     *
     * Returns an array on success, or the JSON response to send back. Every
     * failure reads the same from outside: the token is either good for a
     * live request or it is not, and saying which part failed would let a
     * holder probe for orders.
     */
    private function contextFor($token)
    {
        if (!is_string($token) || $token === '') {
            return $this->linkError();
        }

        try {
            $decoded = json_decode(Crypt::decryptString($token), true);

        } catch (\Throwable $ex) {
            /*
             * A bad token is an ordinary event -- an old link, a truncated
             * paste. It used to come back as a 500 carrying the decryption
             * error verbatim.
             */
            return $this->linkError();
        }

        if (!is_array($decoded)
            || empty($decoded['order_id'])
            || empty($decoded['supplier_id'])) {

            return $this->linkError();
        }

        $context = [
            'order_id'    => (int)$decoded['order_id'],
            'supplier_id' => (int)$decoded['supplier_id'],
        ];

        /*
         * The notification rows are the record that this supplier was asked
         * about this order at all. No rows means the request was withdrawn,
         * the supplier was deleted, or the token names a pairing that never
         * happened.
         */
        $lastAsked = SupplierNotification::where([
            'supplier_id' => $context['supplier_id'],
            'order_id'    => $context['order_id'],
        ])->max('created_at');

        if (!$lastAsked) {
            return $this->linkError();
        }

        if (now()->diffInDays($lastAsked) > self::LINK_TTL_DAYS) {

            Log::info('Expired procurement link used', [
                'order_id'    => $context['order_id'],
                'supplier_id' => $context['supplier_id'],
                'asked_at'    => (string)$lastAsked,
            ]);

            return response()->json([
                'status'  => 410,
                'message' => 'This request has expired. Please contact us for a new link.',
            ], 410);
        }

        return $context;
    }

    private function linkError()
    {
        return response()->json([
            'status'  => 404,
            'message' => 'This link is no longer valid.',
        ], 404);
    }
}
