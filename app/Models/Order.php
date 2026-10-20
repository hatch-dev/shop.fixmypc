<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    /*
     * Deleting an order hides it instead of destroying it. Every query made
     * through this model excludes deleted orders automatically; the reporting
     * queries that join the `orders` table directly have to say so themselves,
     * which is what withoutDeleted() is for.
     */
    use SoftDeletes;


    protected $casts = [
        'payment_done' => 'integer',
        'viewed' => 'integer',
        'cancelled' => 'integer'
    ];


    protected $fillable = [
        'status', 'order_method', 'user_id', 'voucher_id', 'payment_done', 'order',
        'payment_token', 'currency', 'total_amount', 'user_address_id', 'user_token', 'viewed',
        'trans_id', 'pos_order_id', 'purchase_order_number', 'paid_at',
        // Who the customer was when the order was placed; see
        // 2026_09_16_100000_add_customer_snapshot_to_orders_table.
        'account_type', 'company_name', 'vat_number', 'cro_number',
        // Collection orders carry no address, so the pickup contact lives here.
        'contact_name', 'contact_email', 'contact_phone'
    ];


    /**
     * Exclude deleted orders from a query that joins `orders` by hand.
     *
     * The SoftDeletes global scope only reaches queries made through this
     * model. The dashboard charts, the vendor earnings, the withdrawal
     * reporting and the customer spend figures all join the table directly, so
     * without this a deleted order would start counting towards revenue — the
     * opposite of what deleting it meant.
     *
     * $alias is whatever the join called the table ('o', 'ao', 'orders').
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function withoutDeleted($query, $alias = 'orders')
    {
        return $query->whereNull($alias . '.deleted_at');
    }


    /**
     * The columns that record a payment, decided in one place.
     *
     * Six different call sites set `payment_done` — two gateways, the SumUp
     * confirmation, the POS, the cash-on-delivery handover and the admin
     * screen — and a payment time is only worth having if every one of them
     * records it. Merging this into the update, rather than each site writing
     * its own timestamp, is what stops the seventh from forgetting.
     *
     * Marking an order unpaid clears the time rather than leaving a stale one
     * behind: an order that is not paid has no payment date.
     */
    public static function paymentStamp($paid)
    {
        return [
            'payment_done' => $paid ? 1 : 0,
            'paid_at' => $paid ? \Carbon\Carbon::now() : null,
        ];
    }


    /**
     * Which status moves are legal.
     *
     * QA audit ORD-03. updateStatus() accepted any integer and applied it
     * unconditionally, so an order could go from DELIVERED back to PENDING, or
     * out of a terminal state entirely.
     */
    public static function allowedTransitions()
    {
        $s = \Illuminate\Support\Facades\Config::get('constants.orderStatus');

        return [
            $s['PENDING']    => [$s['CONFIRMED'], $s['CANCELLED']],
            $s['CONFIRMED']  => [$s['PICKED_UP'], $s['ON_THE_WAY'], $s['CANCELLED']],
            $s['PICKED_UP']  => [$s['ON_THE_WAY'], $s['DELIVERED'], $s['CANCELLED']],
            $s['ON_THE_WAY'] => [$s['DELIVERED'], $s['CANCELLED']],
            $s['DELIVERED']  => [$s['RETURNED'], $s['REFUNDED']],
            $s['RETURNED']   => [$s['REFUNDED']],
            $s['CANCELLED']  => [$s['REFUNDED']],
            $s['REFUNDED']   => [],
        ];
    }


    public static function canTransition($from, $to)
    {
        $map = self::allowedTransitions();

        if (!array_key_exists((int)$from, $map)) {
            // Legacy rows carrying a status outside the enum: let them move to
            // any valid status once, so they can be tidied up.
            return in_array((int)$to,
                array_values(\Illuminate\Support\Facades\Config::get('constants.orderStatus')), true);
        }

        return in_array((int)$to, $map[(int)$from], true);
    }


    /**
     * Put back what an order took when it is cancelled, returned or refunded.
     *
     * QA audit ORD-01. None of this used to happen: stock stayed deducted,
     * points stayed credited, and the voucher redemption stayed consumed.
     */
    /**
     * Re-tally the flash sale allowances this order's products count
     * against.
     *
     * Called AFTER the status write, never before: the tally reads the
     * orders table, so refreshing while the row still says 'placed'
     * would count a cancellation as a sale. It is separate from
     * restoreStockAndRewards() for that reason -- that one runs before
     * the update on one of its three callers, and it refuses to run
     * twice, which is right for stock and wrong for a recomputed total.
     */
    public function refreshFlashAllowances()
    {
        \App\Models\Helper\FlashSaleAllowance::refresh(
            OrderedProduct::where('order_id', $this->id)->pluck('product_id')
        );
    }


    public function restoreStockAndRewards()
    {
        /*
         * Only the first move into a releasing state gives stock back.
         * CANCELLED -> REFUNDED is a legal second move, and without this it
         * would credit the same units twice.
         */
        $releasing = [
            \Illuminate\Support\Facades\Config::get('constants.orderStatus.CANCELLED'),
            \Illuminate\Support\Facades\Config::get('constants.orderStatus.RETURNED'),
            \Illuminate\Support\Facades\Config::get('constants.orderStatus.REFUNDED'),
        ];

        /*
         * ...counted from the last time the order was put back, if it was.
         * A late bank transfer can reinstate an order the sweep cancelled
         * (CANCELLED -> PENDING, the one move out of a releasing state), and
         * that takes the stock again; a later cancellation has to give it
         * back again, not find the first release and stop.
         */
        $lastReinstated = OrderStatusHistory::where('order_id', $this->id)
            ->whereIn('from_status', $releasing)
            ->whereNotIn('to_status', $releasing)
            ->max('id');

        $alreadyReleased = OrderStatusHistory::where('order_id', $this->id)
            ->whereIn('to_status', $releasing)
            ->when($lastReinstated, function ($query) use ($lastReinstated) {
                $query->where('id', '>', $lastReinstated);
            })
            ->exists();

        if ($alreadyReleased) {
            return;
        }

        /*
         * Less the units a return case already dealt with. A finished case
         * decided every unit it holds -- back on the shelf, or written off as
         * damaged -- and one still open may have restocked some already.
         * Crediting the whole line again would count those twice, or put a
         * damaged unit back on sale.
         */
        $done = "'" . implode("','", OrderReturn::DONE) . "'";
        $restocked = OrderReturnItem::join('order_returns as r', 'r.id', '=', 'order_return_items.order_return_id')
            ->where('r.order_id', $this->id)
            ->groupBy('order_return_items.ordered_product_id')
            ->selectRaw("order_return_items.ordered_product_id, SUM(CASE WHEN r.status IN ($done)"
                . " THEN order_return_items.quantity ELSE order_return_items.restocked_qty END) as qty")
            ->pluck('qty', 'ordered_product_id');

        foreach (OrderedProduct::where('order_id', $this->id)->get() as $line) {
            $quantity = (int)$line->quantity - (int)($restocked[$line->id] ?? 0);

            if ($quantity > 0) {
                UpdatedInventory::where('id', $line->inventory_id)
                    ->increment('quantity', $quantity);
            }
        }

        /*
         * Points a partial return already moved: earned points it took back
         * (a debit) and spent points it gave back (a credit). Netted off the
         * reversal below so nothing moves twice.
         */
        $returnMoves = PointTransaction::where('reference_id', $this->id)
            ->where('source', 'order_return')->get();
        $alreadyMoved = [
            'credit' => (int)$returnMoves->where('type', 'debit')->sum('points'),
            'debit' => (int)$returnMoves->where('type', 'credit')->sum('points'),
        ];

        /*
         * Reverse the loyalty movement rather than deleting it, so the
         * customer's points history still explains itself.
         */
        $movements = PointTransaction::where('reference_id', $this->id)
            ->where('source', 'order')
            ->get()
            // points USED on this order go back first, so they count towards
            // what the customer has when the earned ones are taken back below
            ->sortBy(function ($m) { return $m->type === 'debit' ? 0 : 1; });

        foreach ($movements as $movement) {
            $points = (int)$movement->points;

            $netted = min($points, $alreadyMoved[$movement->type] ?? 0);
            $alreadyMoved[$movement->type] = ($alreadyMoved[$movement->type] ?? 0) - $netted;
            $points -= $netted;

            /*
             * Earned points are taken back only as far as the customer still
             * has them. If they were already spent on another order, the rest
             * is forgiven rather than left as a shortfall: a shortfall showed
             * as a negative balance, or silently ate the next points earned.
             */
            if ($movement->type === 'credit') {
                $points = min($points, PointTransaction::balanceFor($movement->user_id));
            }

            if ($points <= 0) {
                continue;
            }

            PointTransaction::create([
                'user_id' => $movement->user_id,
                'type' => $movement->type === 'credit' ? 'debit' : 'credit',
                'points' => $points,
                'value' => round($points * 0.01, 2),
                'source' => 'order_reversal',
                'reference_id' => $this->id,
            ]);
        }

        /*
         * A one-time loyalty discount spent on this order goes back to the
         * customer. Without this a cancelled or refunded order costs them the
         * benefit permanently, which is the same class of bug ORD-01 fixed for
         * stock, points and vouchers.
         */
        \App\Models\Helper\Loyalty::release($this->id);

        /*
         * The wallet part of a split payment, when the order was never paid
         * (a bank transfer that never came, cash never collected, a gateway
         * that never completed). A paid order's money moves only through a
         * refund (Refunder), never here, so nothing is returned twice.
         */
        \App\Models\Helper\WalletSplit::release($this);
    }


    public function status_history()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id', 'id');
    }


    /**
     * The audit trail, oldest first, with the admin who made each move.
     *
     * Rows have been written on every admin status change since ORD-03, and
     * nothing has ever read them back: the admin order screen had no way to
     * say who confirmed an order or when, which is the first question asked
     * about any disputed delivery.
     *
     * Only the admin's id and name are selected. The rest of that record —
     * email, password hash, two-factor secret — is nobody's business here.
     */
    public function status_trail()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id', 'id')
            ->with(['admin' => function ($query) {
                $query->select(['id', 'name']);
            }])
            ->orderBy('created_at')
            ->orderBy('id');
    }


    public function pos_order()
    {
        return $this->hasOne(PosOrder::class, 'id', 'pos_order_id');
    }

    /**
     * Who to contact about this order: name, email and phone.
     *
     * Delivery orders have always taken these from the address record. A
     * collection order has no address, so the same three fields are stored on
     * the order itself and stand in at exactly the point the address used to.
     * The account is still preferred over both, which is the precedence the
     * email paths already used (QA audit EML-01).
     */
    public function contactDetails()
    {
        $this->loadMissing(['address', 'user', 'guest_user']);

        $sources = [
            $this->user_id && $this->user ? $this->user : null,
            $this->guest_user && $this->guest_user->email ? $this->guest_user : null,
            $this->contact_email ? (object)[
                'name' => $this->contact_name,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
            ] : null,
            $this->address,
        ];

        foreach ($sources as $source) {
            if ($source && !empty($source->email)) {
                return [
                    'name' => $source->name ?? '',
                    'email' => $source->email,
                    'phone' => $source->phone ?? '',
                ];
            }
        }

        return ['name' => '', 'email' => '', 'phone' => ''];
    }
    public function address()
    {
        return $this->hasOne(UserAddress::class, 'id', 'user_address_id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }


    public function guest_user()
    {
        return $this->hasOne(GuestUser::class, 'user_token', 'user_token');
    }


    public function user_info()
    {
        return $this->hasOne(User::class, 'id', 'user_id')
            ->select(['id', 'name', 'email']);
    }

    public function voucher()
    {
        return $this->hasOne(Voucher::class, 'id', 'voucher_id');
    }

    /*
     * The discounts that were taken off this order but are not on the line
     * items. Relations rather than ad-hoc queries so the order lists, which
     * summarise many orders at once, can eager-load them instead of running
     * three more queries per row.
     */
    public function ordered_flash_discount()
    {
        return $this->hasMany(OrderedFlashDiscount::class, 'order_id', 'id');
    }

    /**
     * The voucher discount as it was granted, which is not the same thing as
     * the voucher. `voucher` points at the live coupon and its current rate and
     * cap; this is the amount that actually came off this order.
     */
    public function ordered_voucher()
    {
        return $this->hasMany(OrderedVoucher::class, 'order_id', 'id');
    }

    public function ordered_loyalty_discount()
    {
        return $this->hasMany(OrderedLoyaltyDiscount::class, 'order_id', 'id');
    }

    /**
     * The Essentials Bundle discount as it was granted. Like the flash and
     * loyalty rows beside it, this is the amount that came off THIS order --
     * the bundle it names may since have been re-priced or deleted.
     */
    public function ordered_bundle_discount()
    {
        return $this->hasMany(OrderedBundleDiscount::class, 'order_id', 'id');
    }

    /**
     * Points the customer spent on this order, as money.
     *
     * Debits only, and reversals are excluded by source: a cancelled order
     * writes an 'order_reversal' credit rather than deleting the original, so
     * summing every movement would double-count.
     */
    public function spent_points()
    {
        return $this->hasMany(PointTransaction::class, 'reference_id', 'id')
            ->where('source', 'order')
            ->where('type', 'debit');
    }

    public function ordered_p()
    {
        return $this->hasMany(OrderedProduct::class, 'order_id', 'id');
    }


    public function ordered_products()
    {
        /*
         * `id` is selected because the storefront keys its list of lines on it.
         * Without it every row on the order confirmation rendered with
         * :key="undefined", so Vue could not tell two lines of the same order
         * apart.
         */
        return $this->hasMany(OrderedProduct::class, 'order_id', 'id')
            ->select([ 'id', 'product_id', 'inventory_id', 'quantity', 'shipping_place_id',
                'shipping_type', 'selling', 'shipping_price', 'tax_price',
                // How the line was treated for VAT, so the ex/VAT/inc split can
                // be made from what the order recorded rather than from the
                // product's tax rule as it stands today.
                'tax_rate', 'tax_inclusive',
                'bundle_offer', 'order_id']);
    }

    public function cancellation()
    {
        return $this->hasOne(Cancellation::class, 'order_id', 'id');
    }

    /** The parcel and its AfterShip tracking; see ShipmentTracking. */
    /** Return cases opened against this order. */
    public function returns()
    {
        return $this->hasMany(OrderReturn::class, 'order_id', 'id');
    }

    public function shipment()
    {
        return $this->hasOne(OrderShipment::class, 'order_id', 'id');
    }

    /**
     * Whether the order is off, however it got there.
     *
     * The `cancelled` flag is only set when the customer cancels. An admin, or
     * the unpaid-bank-transfer sweep, moves the status instead and leaves the
     * flag alone, so reading either one on its own misses the other half.
     */
    public function isCancelled()
    {
        return (int)$this->cancelled === (int)\Illuminate\Support\Facades\Config::get('constants.status.PUBLIC')
            || in_array((int)$this->status, [
                (int)\Illuminate\Support\Facades\Config::get('constants.orderStatus.CANCELLED'),
                (int)\Illuminate\Support\Facades\Config::get('constants.orderStatus.REFUNDED'),
            ], true);
    }

    public function ordered_price()
    {
        return $this->hasOne(OrderedProduct::class)
            ->selectRaw('ordered_products.order_id, SUM(ordered_products.selling) as total')
            ->groupBy('ordered_products.order_id');
    }
}
