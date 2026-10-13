<?php

namespace App\Console\Commands;

use App\Models\Helper\Pricing;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\TaxRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clean up the data the QA audit found
 * (reference/qa/qa-audit-2026-09-09.html).
 *
 * The code fixes stop these from recurring; this repairs what the old code
 * already wrote. It reports by default and changes nothing unless --apply is
 * passed, because two of the five steps remove records.
 */
class QaAuditRemediate extends Command
{
    protected $signature = 'qa:remediate
                            {--apply : Actually write the changes (otherwise report only)}
                            {--step=all : all|vouchers|orphans|zero-priced|cancelled|vat|slugs|procurement}';

    protected $description = 'Repair the order, voucher, pricing and VAT data the QA audit flagged';

    private $apply = false;

    public function handle()
    {
        $this->apply = $this->option('apply');
        $step = $this->option('step');

        if (!$this->apply) {
            $this->warn('DRY RUN — nothing will be written. Re-run with --apply to commit.');
            $this->line('');
        }

        foreach (['vouchers', 'orphans', 'zero-priced', 'cancelled', 'vat', 'slugs', 'procurement'] as $name) {
            if ($step === 'all' || $step === $name) {
                $method = 'step' . str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
                $this->$method();
                $this->line('');
            }
        }

        $this->info($this->apply ? 'Remediation complete.' : 'Dry run complete.');

        return 0;
    }


    /**
     * Products with no slug.
     *
     * ProductsController::all() opened with whereNotNull('slug') while
     * productMain() never required one, so a product created through any path
     * that omitted it was live, orderable and invisible in the admin Products
     * list. The slug is required on save now and the filter is gone, but the
     * rows that got in that way still have nothing to route on.
     *
     * A slug is derived from the title, made unique, and written.
     */
    private function stepSlugs()
    {
        $this->info('[CAT-01] Products with no slug');

        $products = DB::table('products')
            ->where(function ($query) {
                $query->whereNull('slug')->orWhere('slug', '');
            })
            ->select('id', 'title', 'status')
            ->get();

        if ($products->isEmpty()) {
            $this->line('  none');
            return;
        }

        foreach ($products as $p) {

            $base = \Illuminate\Support\Str::slug($p->title);

            if ($base === '') {
                // One row has no title either. It still needs something
                // unique and harmless to route on.
                $base = 'product-' . $p->id;
            }

            $slug = $base;
            $suffix = 2;

            while (DB::table('products')->where('slug', $slug)->where('id', '!=', $p->id)->exists()) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }

            $live = (int)$p->status === (int)Config::get('constants.status.PUBLIC') ? 'live' : 'private';

            $this->line(sprintf('  %-12s %-30s %-8s -> %s',
                $p->id, substr((string)$p->title, 0, 28) ?: '(no title)', $live, $slug));

            if ($this->apply) {
                DB::table('products')->where('id', $p->id)->update(['slug' => $slug]);
            }
        }

        $this->line('  -> ' . ($this->apply ? 'written: ' : 'would write: ') . $products->count());
    }


    /**
     * CPN-01. Expired codes were usable because the window check never fired.
     * Take them out of circulation so the fix cannot be undone by a stale code.
     */
    private function stepVouchers()
    {
        $this->info('[CPN-01] Expired vouchers still marked public');

        $expired = DB::table('vouchers')
            ->whereNotNull('end_time')
            ->where('end_time', '<', now())
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->get();

        if ($expired->isEmpty()) {
            $this->line('  none');
            return;
        }

        foreach ($expired as $v) {
            $this->line("  {$v->code} (id {$v->id}) expired " . substr($v->end_time, 0, 10));
        }

        if ($this->apply) {
            DB::table('vouchers')
                ->whereIn('id', $expired->pluck('id'))
                ->update(['status' => Config::get('constants.status.PRIVATE')]);

            $this->info('  -> set to private: ' . $expired->count());
        }
    }


    /**
     * INT-01. Orders with no line items, written by the checkout path that
     * silently skipped every cart row. They are unpayable and unpickable.
     */
    private function stepOrphans()
    {
        $this->info('[INT-01] Orders with no line items');

        $orphans = DB::table('orders as o')
            ->leftJoin('ordered_products as op', 'op.order_id', '=', 'o.id')
            ->whereNull('op.id')
            ->where('o.total_amount', 0)
            ->where('o.payment_done', 0)
            ->select('o.id', 'o.order', 'o.created_at')
            ->get();

        if ($orphans->isEmpty()) {
            $this->line('  none');
            return;
        }

        foreach ($orphans as $o) {
            $this->line("  order {$o->order} (id {$o->id}) created " . substr($o->created_at, 0, 10));
        }

        $this->warn('  These are unpaid, empty orders. --apply deletes them and their child rows.');

        if ($this->apply) {
            $ids = $orphans->pluck('id')->all();

            DB::transaction(function () use ($ids) {
                DB::table('ordered_products_inventories')->whereIn('order_id', $ids)->delete();
                DB::table('ordered_voucher')->whereIn('order_id', $ids)->delete();
                DB::table('ordered_flash_discount')->whereIn('order_id', $ids)->delete();
                DB::table('cancellations')->whereIn('order_id', $ids)->delete();
                DB::table('order_status_histories')->whereIn('order_id', $ids)->delete();
                DB::table('point_transactions')
                    ->whereIn('reference_id', $ids)->where('source', 'order')->delete();
                DB::table('orders')->whereIn('id', $ids)->delete();
            });

            $this->info('  -> deleted: ' . count($ids));
        }
    }


    /**
     * PRC-02. Products at 0.00 that are live on the storefront — they were
     * created through a path that bypassed productMain() validation, so they
     * are purchasable for nothing.
     */
    private function stepZeroPriced()
    {
        $this->info('[PRC-02] Published products priced 0.00');

        $products = DB::table('products')
            ->where('selling', 0)
            ->where('status', Config::get('constants.status.PUBLIC'))
            ->select('id', 'title', 'selling')
            ->get();

        if ($products->isEmpty()) {
            $this->line('  none');
            return;
        }

        foreach ($products as $p) {
            $this->line("  {$p->id}  {$p->title}");
        }

        $this->warn('  --apply unpublishes these so they cannot be bought for nothing.');
        $this->warn('  Set a real price in the admin panel, then republish.');

        if ($this->apply) {
            DB::table('products')
                ->whereIn('id', $products->pluck('id'))
                ->update(['status' => Config::get('constants.status.PRIVATE')]);

            $this->info('  -> unpublished: ' . $products->count());
        }
    }


    /**
     * ORD-01. Orders cancelled through the old path kept status PENDING, so
     * they still read as live work in every admin list.
     */
    private function stepCancelled()
    {
        $this->info('[ORD-01] Cancelled orders still showing a live status');

        $cancelledStatus = Config::get('constants.orderStatus.CANCELLED');

        $orders = DB::table('orders')
            ->where('cancelled', 1)
            ->where('status', '!=', $cancelledStatus)
            ->select('id', 'status')
            ->get();

        if ($orders->isEmpty()) {
            $this->line('  none');
            return;
        }

        foreach ($orders as $o) {
            $this->line("  order {$o->id} status={$o->status} -> {$cancelledStatus}");
        }

        $this->warn('  Stock for these was never returned; --apply records the status only.');
        $this->warn('  Check stock levels for these orders by hand before trusting them.');

        if ($this->apply) {
            foreach ($orders as $o) {
                DB::transaction(function () use ($o, $cancelledStatus) {
                    DB::table('orders')->where('id', $o->id)->update(['status' => $cancelledStatus]);

                    OrderStatusHistory::create([
                        'order_id' => $o->id,
                        'from_status' => (int)$o->status,
                        'to_status' => $cancelledStatus,
                        'changed_by' => null,
                        'changed_by_type' => 'system',
                        'note' => 'qa:remediate backfill (ORD-01)'
                    ]);
                });
            }

            $this->info('  -> updated: ' . $orders->count());
        }
    }


    /**
     * TAX-02. vatInclusivePrice was written by the hardcoded-23%-on-rule-1
     * branch, so some rows hold 0.00 and others disagree with their tax rule.
     */
    private function stepVat()
    {
        $this->info('[TAX-02] VAT-inclusive prices that disagree with the tax rule');

        $rules = TaxRules::all()->keyBy('id');
        $fixed = 0;

        $products = DB::table('products')
            ->select('id', 'title', 'selling', 'offered', 'tax_rule_id',
                'vatInclusivePrice', 'vatInclusiveOfferedPrice')
            ->get();

        foreach ($products as $p) {
            $rule = $rules->get($p->tax_rule_id);

            $expectedSelling = Pricing::round(
                (float)$p->selling + Pricing::unitTax($rule, (float)$p->selling, true)
            );

            $expectedOffered = (float)$p->offered > 0
                ? Pricing::round((float)$p->offered + Pricing::unitTax($rule, (float)$p->offered, true))
                : null;

            $sellingWrong = abs((float)$p->vatInclusivePrice - $expectedSelling) > 0.02;

            $offeredWrong = is_null($expectedOffered)
                ? ((float)$p->vatInclusiveOfferedPrice > 0)
                : abs((float)$p->vatInclusiveOfferedPrice - $expectedOffered) > 0.02;

            if (!$sellingWrong && !$offeredWrong) {
                continue;
            }

            $fixed++;

            if ($fixed <= 15) {
                $rate = $rule ? (float)$rule->price : 0;

                $this->line(sprintf('  %-10s %-26s  vat%s%%  incl %s -> %s   offerIncl %s -> %s',
                    $p->id,
                    substr($p->title, 0, 24),
                    rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.'),
                    number_format((float)$p->vatInclusivePrice, 2, '.', ''),
                    number_format($expectedSelling, 2, '.', ''),
                    number_format((float)$p->vatInclusiveOfferedPrice, 2, '.', ''),
                    is_null($expectedOffered) ? 'null' : number_format($expectedOffered, 2, '.', '')
                ));
            }

            if ($this->apply) {
                DB::table('products')->where('id', $p->id)->update([
                    'vatInclusivePrice' => number_format($expectedSelling, 2, '.', ''),
                    'vatInclusiveOfferedPrice' => is_null($expectedOffered)
                        ? null
                        : number_format($expectedOffered, 2, '.', ''),
                ]);
            }
        }

        if ($fixed === 0) {
            $this->line('  none');
            return;
        }

        if ($fixed > 15) {
            $this->line('  ... and ' . ($fixed - 15) . ' more');
        }

        $this->info($this->apply ? "  -> recalculated: {$fixed}" : "  would recalculate: {$fixed}");
    }

    /**
     * Procurement requests whose order no longer exists.
     *
     * supplier_notifications has no foreign key to orders, and until the
     * procurement rebuild nothing deleted its rows: rollbackOrder() dropped
     * the order and left them, and so did deleting a supplier. The rows are
     * not inert. They ARE the authorisation table for a supplier's bid link
     * -- BidController checks a token against them -- so an orphan keeps a
     * link alive for an order nobody can look up.
     *
     * Both leaks are closed now. This clears what the old paths left behind.
     *
     * Deliberately not matched on soft-deleted orders: a cancelled order is
     * still an order, and its procurement history is worth keeping. Only
     * rows whose order id is absent from the table entirely are removed.
     */
    private function stepProcurement()
    {
        $this->info('[PRC-03] Procurement requests pointing at deleted orders');

        if (!Schema::hasTable('supplier_notifications')) {
            $this->line('  procurement tables not installed');
            return;
        }

        $orphans = DB::table('supplier_notifications as sn')
            ->leftJoin('orders as o', 'o.id', '=', 'sn.order_id')
            ->whereNull('o.id')
            ->select('sn.id', 'sn.order_id', 'sn.supplier_id', 'sn.product_id', 'sn.created_at')
            ->get();

        if ($orphans->isEmpty()) {
            $this->line('  none');
            return;
        }

        $ids = $orphans->pluck('id')->all();

        $bidCount = Schema::hasTable('supplier_bids')
            ? DB::table('supplier_bids')->whereIn('supplier_notification_id', $ids)->count()
            : 0;

        foreach ($orphans->groupBy('order_id') as $orderId => $rows) {
            $this->line("  order id {$orderId} (gone): {$rows->count()} line(s), supplier "
                . $rows->pluck('supplier_id')->unique()->implode(', ')
                . ', asked ' . substr($rows->min('created_at'), 0, 10));
        }

        $this->warn('  ' . count($ids) . ' request line(s) and ' . $bidCount
            . ' bid(s). --apply deletes them.');

        if ($this->apply) {
            DB::transaction(function () use ($ids) {
                if (Schema::hasTable('supplier_bids')) {
                    DB::table('supplier_bids')->whereIn('supplier_notification_id', $ids)->delete();
                }

                DB::table('supplier_notifications')->whereIn('id', $ids)->delete();
            });

            $this->info('  -> deleted: ' . count($ids) . ' request line(s), ' . $bidCount . ' bid(s)');
        }
    }

}
