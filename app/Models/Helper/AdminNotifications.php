<?php

namespace App\Models\Helper;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The admin header's notification bell: new orders, and every request that
 * only staff can answer - cancellations, return requests, trade applications,
 * account upgrades, contact messages, reviews awaiting approval, supplier bids
 * and vendor withdrawal requests.
 *
 * Nothing here is written when the thing happens. Each notification is a row
 * that already exists, named by a stable id ("order:85", "return:12"), so an
 * order placed by any path - checkout, Stripe, bank transfer, wallet, a pay
 * link - is covered without that path having to remember to raise one. The
 * only state kept is per admin: which ids were opened
 * (admin_notification_reads) and when "Mark all as read" was last pressed
 * (admin_notification_states).
 *
 * Every source is filtered by the same permission its own admin screen asks
 * for, so the bell never tells an admin about something they cannot open.
 */
class AdminNotifications
{
    /** How far back the list reaches. */
    const LIST_DAYS = 30;

    /**
     * How far back something may still count as unread. Keeps a first visit
     * (or a new admin) from opening on "99+" for last month's orders.
     */
    const UNREAD_DAYS = 7;

    /** Rows read per source; the merged list is cut to the requested limit. */
    const PER_SOURCE = 50;

    /**
     * @return array{unread:int, items:array, pending:array, server_time:string}
     */
    public static function feed($admin, bool $isSuperAdmin, bool $isVendor, $limit = 20)
    {
        $limit = max(1, min((int)$limit ?: 20, 50));
        $now = Carbon::now();

        $events = self::events($admin, $isSuperAdmin, $isVendor, $now->copy()->subDays(self::LIST_DAYS));

        usort($events, function ($a, $b) {
            return $b['_at'] <=> $a['_at'];
        });

        $readIds = DB::table('admin_notification_reads')
            ->where('admin_id', $admin->id)
            ->pluck('notification_id')
            ->flip();

        $clearedAt = DB::table('admin_notification_states')
            ->where('admin_id', $admin->id)
            ->value('cleared_at');

        $cutoff = $now->copy()->subDays(self::UNREAD_DAYS)->getTimestamp();
        if ($clearedAt) {
            $cutoff = max($cutoff, Carbon::parse($clearedAt)->getTimestamp());
        }

        $unread = 0;
        foreach ($events as &$e) {
            $e['read'] = $e['_at'] <= $cutoff || isset($readIds[$e['id']]);
            if (!$e['read']) {
                $unread++;
            }
        }
        unset($e);

        $items = array_map(function ($e) {
            unset($e['_at']);
            return $e;
        }, array_slice($events, 0, $limit));

        return [
            'unread' => $unread,
            'items' => $items,
            'pending' => self::pending($admin, $isSuperAdmin, $isVendor),
            'server_time' => $now->toIso8601ZuluString(),
        ];
    }

    /** Mark the given notification ids read for this admin. */
    public static function markRead($admin, array $ids)
    {
        $now = Carbon::now();
        $rows = [];

        foreach (array_unique($ids) as $id) {
            $id = trim((string)$id);
            // Ids are "<source>:<number>" - anything else is not one of ours.
            if (!preg_match('/^[a-z_]+:\d+$/', $id)) {
                continue;
            }
            $rows[] = ['admin_id' => $admin->id, 'notification_id' => $id, 'read_at' => $now];
        }

        if ($rows) {
            DB::table('admin_notification_reads')->insertOrIgnore($rows);
        }

        return count($rows);
    }

    /**
     * Everything up to now is read. The per-id rows behind the cut-off are no
     * longer needed, so they go, which keeps the table from growing forever.
     */
    public static function markAllRead($admin)
    {
        $now = Carbon::now();

        DB::table('admin_notification_states')->updateOrInsert(
            ['admin_id' => $admin->id],
            ['cleared_at' => $now, 'updated_at' => $now, 'created_at' => $now]
        );

        DB::table('admin_notification_reads')
            ->where('admin_id', $admin->id)
            ->delete();
    }

    /**
     * Orders a vendor may see: only those holding one of their own products.
     * null means "all of them".
     */
    private static function visibleOrderIds($admin, bool $isSuperAdmin)
    {
        if ($isSuperAdmin) {
            return null;
        }

        return DB::table('ordered_products as op')
            ->join('products as p', 'p.id', '=', 'op.product_id')
            ->where('p.admin_id', $admin->id)
            ->distinct()
            ->pluck('op.order_id');
    }

    private static function event($id, $type, $at, array $data)
    {
        $c = Carbon::parse($at);

        return array_merge([
            'id' => $id,
            'type' => $type,
            'at' => $c->toIso8601ZuluString(),
            '_at' => $c->getTimestamp(),
        ], $data);
    }

    private static function events($admin, bool $isSuperAdmin, bool $isVendor, Carbon $since)
    {
        $from = $since->format('Y-m-d H:i:s');
        $n = self::PER_SOURCE;
        $events = [];

        // ------------------------------------------------------------ orders
        if ($admin->can('order.view')) {
            $visible = self::visibleOrderIds($admin, $isSuperAdmin);

            // A replacement order from an exchange is made by staff, not a customer.
            $replacements = DB::table('order_returns')
                ->whereNotNull('replacement_order_id')
                ->pluck('replacement_order_id');

            $orders = DB::table('orders')
                ->whereNull('deleted_at')
                ->where('created_at', '>', $from)
                ->whereNotIn('id', $replacements)
                ->when(!is_null($visible), function ($q) use ($visible) {
                    $q->whereIn('id', $visible);
                })
                ->select('id', 'order', 'status', 'cancelled', 'total_amount', 'payment_done', 'order_method',
                    'contact_name', 'contact_email', 'created_at')
                ->orderByDesc('created_at')
                ->limit($n)
                ->get();

            foreach ($orders as $o) {
                $events[] = self::event('order:' . $o->id, 'order_placed', $o->created_at, [
                    'order_id' => $o->id,
                    'order_no' => $o->order,
                    'amount' => $o->total_amount,
                    'paid' => (int)$o->payment_done === 1,
                    // Today's state, so a since-cancelled order does not still read "awaiting payment".
                    // Same rule as Order::isCancelled(): the customer flag, or status cancelled/refunded.
                    'cancelled' => (int)$o->cancelled === (int)Config::get('constants.status.PUBLIC')
                        || in_array((int)$o->status, [
                            (int)Config::get('constants.orderStatus.CANCELLED'),
                            (int)Config::get('constants.orderStatus.REFUNDED'),
                        ], true),
                    'customer' => (string)($o->contact_name ?: $o->contact_email),
                    'link' => '/orders/' . $o->id,
                ]);
            }

            // Customer cancellations
            $cancels = DB::table('cancellations as c')
                ->join('orders as o', 'o.id', '=', 'c.order_id')
                ->whereNull('o.deleted_at')
                ->where('c.created_at', '>', $from)
                ->when(!is_null($visible), function ($q) use ($visible) {
                    $q->whereIn('c.order_id', $visible);
                })
                ->select('c.id', 'c.order_id', 'c.title', 'c.created_at', 'o.order as order_no',
                    'o.total_amount', 'o.contact_name')
                ->orderByDesc('c.created_at')
                ->limit($n)
                ->get();

            foreach ($cancels as $c) {
                $events[] = self::event('cancel:' . $c->id, 'order_cancelled', $c->created_at, [
                    'order_id' => $c->order_id,
                    'order_no' => $c->order_no,
                    'amount' => $c->total_amount,
                    'customer' => (string)$c->contact_name,
                    'note' => (string)$c->title,
                    'link' => '/orders/' . $c->order_id,
                ]);
            }

            // Return / exchange requests from customers (staff-opened cases carry created_by)
            if (!$isVendor) {
                $returns = DB::table('order_returns as r')
                    ->join('orders as o', 'o.id', '=', 'r.order_id')
                    ->whereNull('r.created_by')
                    ->where('r.created_at', '>', $from)
                    ->select('r.id', 'r.rma_number', 'r.type', 'r.reason', 'r.order_id', 'r.created_at',
                        'o.order as order_no', 'o.contact_name')
                    ->orderByDesc('r.created_at')
                    ->limit($n)
                    ->get();

                foreach ($returns as $r) {
                    $events[] = self::event('return:' . $r->id, 'return_requested', $r->created_at, [
                        'return_type' => $r->type,
                        'rma' => $r->rma_number,
                        'order_id' => $r->order_id,
                        'order_no' => $r->order_no,
                        'customer' => (string)$r->contact_name,
                        'note' => (string)$r->reason,
                        'link' => '/returns/' . $r->id,
                    ]);
                }
            }
        }

        // ------------------------------------------------ customer accounts
        if (!$isVendor && $admin->can('user.view')) {
            // Trade sign-ups: each one is an application waiting for review
            $trade = DB::table('users')
                ->where('account_type', 'trade')
                ->where('created_at', '>', $from)
                ->select('id', 'name', 'email', 'created_at')
                ->orderByDesc('created_at')
                ->limit($n)
                ->get();

            foreach ($trade as $u) {
                $events[] = self::event('trade:' . $u->id, 'trade_application', $u->created_at, [
                    'user_id' => $u->id,
                    'customer' => (string)($u->name ?: $u->email),
                    'link' => '/trade-applications',
                ]);
            }

            // Self-serve upgrades: to trade is an application, to business is done already
            $upgrades = DB::table('account_upgrade_requests as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.created_at', '>', $from)
                ->select('r.id', 'r.from_type', 'r.to_type', 'r.created_at', 'u.name', 'u.email')
                ->orderByDesc('r.created_at')
                ->limit($n)
                ->get();

            foreach ($upgrades as $r) {
                $events[] = self::event('upgrade:' . $r->id, 'account_upgrade', $r->created_at, [
                    'from_type' => $r->from_type,
                    'to_type' => $r->to_type,
                    'customer' => (string)($r->name ?: $r->email),
                    'link' => '/trade-applications?mode=upgrades',
                ]);
            }
        }

        // ---------------------------------------------------- contact messages
        if ($admin->can('message.view')) {
            $messages = DB::table('contact_us')
                ->where('created_at', '>', $from)
                ->select('id', 'name', 'email', 'subject', 'created_at')
                ->orderByDesc('created_at')
                ->limit($n)
                ->get();

            foreach ($messages as $m) {
                $events[] = self::event('message:' . $m->id, 'contact_message', $m->created_at, [
                    'customer' => (string)($m->name ?: $m->email),
                    'note' => (string)$m->subject,
                    'link' => '/user-messages/' . $m->id,
                ]);
            }
        }

        // ------------------------------------------- reviews awaiting approval
        if ($admin->can('rating_review.view')) {
            // A review written by staff is approved on entry and carries admin_id.
            $reviews = DB::table('rating_reviews as r')
                ->join('products as p', 'p.id', '=', 'r.product_id')
                ->whereNull('r.deleted_at')
                ->whereNull('r.admin_id')
                ->where('r.created_at', '>', $from)
                ->when(!$isSuperAdmin, function ($q) use ($admin) {
                    $q->where('p.admin_id', $admin->id);
                })
                ->select('r.id', 'r.rating', 'r.name', 'r.created_at', 'p.title as product')
                ->orderByDesc('r.created_at')
                ->limit($n)
                ->get();

            foreach ($reviews as $r) {
                $events[] = self::event('review:' . $r->id, 'review', $r->created_at, [
                    'rating' => (int)$r->rating,
                    'product' => (string)$r->product,
                    'customer' => (string)$r->name,
                    'link' => '/rating-reviews',
                ]);
            }
        }

        // ------------------------------------------------------ supplier bids
        if ($isSuperAdmin && $admin->can('supplier.view')) {
            $bids = DB::table('supplier_bids as b')
                ->leftJoin('suppliers as s', 's.id', '=', 'b.supplier_id')
                ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
                ->leftJoin('orders as o', 'o.id', '=', 'b.order_id')
                ->where('b.created_at', '>', $from)
                ->select('b.id', 'b.price', 'b.order_id', 'b.created_at', 's.name as supplier',
                    'p.title as product', 'o.order as order_no')
                ->orderByDesc('b.created_at')
                ->limit($n)
                ->get();

            foreach ($bids as $b) {
                $events[] = self::event('bid:' . $b->id, 'supplier_bid', $b->created_at, [
                    'supplier' => (string)$b->supplier,
                    'product' => (string)$b->product,
                    'amount' => $b->price,
                    'order_id' => $b->order_id,
                    'order_no' => $b->order_no,
                    'link' => '/procurement/sourcing',
                ]);
            }
        }

        // ----------------------------------------- vendor withdrawal requests
        if ($isSuperAdmin && $admin->can('withdrawal_request.approve')) {
            $withdrawals = DB::table('withdrawals as w')
                ->leftJoin('admins as a', 'a.id', '=', 'w.admin_id')
                ->where('w.created_at', '>', $from)
                ->select('w.id', 'w.amount', 'w.created_at', 'a.name as vendor')
                ->orderByDesc('w.created_at')
                ->limit($n)
                ->get();

            foreach ($withdrawals as $w) {
                $events[] = self::event('withdrawal:' . $w->id, 'withdrawal_request', $w->created_at, [
                    'customer' => (string)$w->vendor,
                    'amount' => $w->amount,
                    'link' => '/withdrawal-requests',
                ]);
            }
        }

        return $events;
    }

    /**
     * What is still waiting for someone, whatever has been read: the bell's
     * "Needs attention" strip. Each entry links to the queue that clears it,
     * and only queues this admin may open are listed.
     */
    private static function pending($admin, bool $isSuperAdmin, bool $isVendor)
    {
        $out = [];

        if ($admin->can('order.view')) {
            $visible = self::visibleOrderIds($admin, $isSuperAdmin);

            $out['orders'] = DB::table('orders')
                ->whereNull('deleted_at')
                ->where('status', Config::get('constants.orderStatus.PENDING'))
                ->where(function ($q) {
                    $q->whereNull('cancelled')
                        ->orWhere('cancelled', '!=', Config::get('constants.status.PUBLIC'));
                })
                ->when(!is_null($visible), function ($q) use ($visible) {
                    $q->whereIn('id', $visible);
                })
                ->count();

            if (!$isVendor) {
                $out['returns'] = DB::table('order_returns')->where('status', 'requested')->count();
            }
        }

        if (!$isVendor && $admin->can('user.view')) {
            $out['trade'] = DB::table('users')
                ->where('account_type', 'trade')
                ->where(function ($q) {
                    $q->where('trade_status', 'pending')->orWhereNull('trade_status');
                })
                ->count();

            $out['upgrades'] = DB::table('account_upgrade_requests')->where('status', 'pending')->count();
        }

        if ($admin->can('message.view')) {
            $out['messages'] = DB::table('contact_us')
                ->where(function ($q) {
                    // Nothing in the panel sets replied; viewed is what the Message count uses.
                    $q->whereNull('viewed')
                        ->orWhere('viewed', '!=', Config::get('constants.status.PUBLIC'));
                })
                ->count();
        }

        if ($admin->can('rating_review.view')) {
            $out['reviews'] = DB::table('rating_reviews as r')
                ->join('products as p', 'p.id', '=', 'r.product_id')
                ->whereNull('r.deleted_at')
                ->where('r.approved', 0)
                ->when(!$isSuperAdmin, function ($q) use ($admin) {
                    $q->where('p.admin_id', $admin->id);
                })
                ->count();
        }

        if ($isSuperAdmin && $admin->can('supplier.view')) {
            $out['bids'] = DB::table('supplier_bids')->where('status', 'pending')->count();
        }

        if ($isSuperAdmin && $admin->can('withdrawal_request.approve')) {
            $out['withdrawals'] = DB::table('withdrawals')
                ->where('status', Config::get('constants.withdrawalStatus.REQUESTED'))
                ->count();
        }

        return $out;
    }
}
