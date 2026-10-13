<?php

namespace App\Console\Commands;

use App\Models\Helper\Pricing;
use App\Models\Product;
use App\Models\TaxRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fill in `ordered_products.tax_rate` / `tax_inclusive` on orders placed
 * before those columns existed.
 *
 * Read this before running it. The columns were added precisely so that an
 * invoice states the VAT that was charged at the time rather than whatever
 * the product's tax rule says today — and the only source this backfill has
 * for a historic order is today's `products.excludeVAT` and today's
 * `tax_rules` row. Writing them is therefore an assertion that the tax
 * treatment has not changed since the order was placed. That is a claim about
 * the business, not about the code, and nothing here can verify it.
 *
 * What the command can do is refuse to guess where the order itself
 * contradicts today's rule, and say plainly which rows it is certain about.
 * Every candidate row lands in one of three buckets:
 *
 *   corroborated  The line is ex-VAT (excludeVAT = 1), so checkout recorded
 *                 the VAT it added in `tax_price`. Re-deriving that figure
 *                 from today's rule reproduces the stored one to the cent, so
 *                 the rule that priced this order is demonstrably the rule in
 *                 force now. Safe to write.
 *
 *   assumed       The line is inc-VAT (excludeVAT = 0), so `tax_price` is
 *                 zero — nothing was added, the VAT was already inside the
 *                 price. Zero is what this line would store under *any* rate,
 *                 so the order carries no evidence of its own rate at all.
 *                 The 23% comes purely from today's tax rule. Needs --assumed.
 *
 *   contradicted  Re-deriving disagrees with what the order stored: an ex-VAT
 *                 line whose recorded tax is not today's percentage of the
 *                 price, or an inc-VAT line that nonetheless had tax added.
 *                 The treatment demonstrably changed since. Never written,
 *                 by any flag — these are the rows a blind `UPDATE` would
 *                 silently restate.
 *
 * Rows that already record a treatment are never touched, so the command is
 * safe to re-run and cannot overwrite what checkout froze.
 */
class BackfillVatTreatment extends Command
{
    protected $signature = 'orders:backfill-vat-treatment
                            {--apply : Actually write the changes (otherwise report only)}
                            {--assumed : Also write inc-VAT lines, whose rate cannot be corroborated}
                            {--order= : Limit to a single order id}
                            {--chunk=500 : Rows to read per batch}';

    protected $description = 'Record tax_rate / tax_inclusive on order lines placed before those columns existed';

    /** A cent. Money is stored to two decimals, so anything under this is rounding. */
    const TOLERANCE = 0.005;

    private $apply = false;
    private $assumed = false;

    /** @var array id => TaxRules|null, so a 2,000-line order does not run 2,000 lookups. */
    private $ruleCache = [];

    private $counts = [
        'corroborated' => 0,
        'assumed' => 0,
        'contradicted' => 0,
        'no-product' => 0,
        'written' => 0,
    ];

    /** @var array The contradicted rows, reported in full — they are the ones that matter. */
    private $contradictions = [];

    public function handle()
    {
        $this->apply = (bool)$this->option('apply');
        $this->assumed = (bool)$this->option('assumed');

        if (!$this->apply) {
            $this->warn('DRY RUN — nothing will be written. Re-run with --apply to commit.');
        }

        if ($this->assumed) {
            $this->warn('--assumed: inc-VAT lines will be written from TODAY\'s tax rule.');
            $this->warn('Those orders carry no record of the rate they were charged at.');
        }

        $this->line('');

        $query = DB::table('ordered_products as op')
            ->leftJoin('products as p', 'p.id', '=', 'op.product_id')
            ->whereNull('op.tax_rate')
            ->whereNull('op.tax_inclusive')
            ->select(
                'op.id', 'op.order_id', 'op.product_id', 'op.selling', 'op.tax_price',
                'p.id as product_exists', 'p.excludeVAT', 'p.tax_rule_id'
            )
            ->orderBy('op.id');

        if ($this->option('order')) {
            $query->where('op.order_id', (int)$this->option('order'));
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No order lines are missing a VAT treatment. Nothing to do.');
            return 0;
        }

        $this->line("Candidate lines (tax_rate and tax_inclusive both NULL): {$total}");
        $this->line('');

        $bar = $this->output->createProgressBar($total);

        // chunkById on the joined query: ordered_products.id is unique, so the
        // cursor is stable even as rows below it are updated.
        $query->chunkById((int)$this->option('chunk'), function ($rows) use ($bar) {
            $writes = [];

            foreach ($rows as $row) {
                $verdict = $this->classify($row);
                $this->counts[$verdict['bucket']]++;

                if ($verdict['bucket'] === 'contradicted') {
                    $this->contradictions[] = $verdict + ['row' => $row];
                }

                $writable = $verdict['bucket'] === 'corroborated'
                    || ($verdict['bucket'] === 'assumed' && $this->assumed);

                if ($writable) {
                    $writes[] = $verdict + ['row' => $row];
                }

                $bar->advance();
            }

            if ($this->apply && $writes) {
                $this->write($writes);
            }
        }, 'op.id', 'id');

        $bar->finish();
        $this->line('');
        $this->line('');

        $this->report();

        return 0;
    }

    /**
     * Decide what this line's VAT treatment was, and how much the order itself
     * backs that up.
     *
     * Mirrors OrdersController's checkout derivation exactly — excludeVAT = 1
     * means the price was ex-VAT and the rate was added on top — so a line
     * written by this command is indistinguishable from one written at
     * checkout under the same rule.
     */
    private function classify($row)
    {
        if (is_null($row->product_exists)) {
            return [
                'bucket' => 'no-product',
                'reason' => 'product row no longer exists; nothing to derive the treatment from',
            ];
        }

        $rule = $this->rule($row->tax_rule_id);

        // Checkout: $productTaxable = ((int)$excludeVAT === 1), and
        // tax_inclusive was stored as its negation.
        $taxable = (int)$row->excludeVAT === 1;
        $inclusive = !$taxable;
        $rate = Pricing::vatRate($rule);

        $storedUnitTax = (float)$row->tax_price;
        $charged = (float)$row->selling;

        if ($taxable) {
            // Ex-VAT line: checkout stored the VAT it added, so re-deriving it
            // from today's rule either reproduces that figure or proves the
            // rule has moved.
            $expected = Pricing::unitTax($rule, $charged, true);

            if (abs($expected - $storedUnitTax) > self::TOLERANCE) {
                return [
                    'bucket' => 'contradicted',
                    'rate' => $rate,
                    'inclusive' => $inclusive,
                    'reason' => sprintf(
                        'ex-VAT line: order recorded %.2f unit VAT, today\'s rule gives %.2f',
                        $storedUnitTax, $expected
                    ),
                ];
            }

            return [
                'bucket' => 'corroborated',
                'rate' => $rate,
                'inclusive' => $inclusive,
                'reason' => sprintf('unit VAT %.2f matches %.2f%% of %.2f', $storedUnitTax, $rate, $charged),
            ];
        }

        // Inc-VAT line: checkout added nothing, so tax_price should be zero.
        // A non-zero figure means this line did have VAT added on top, which
        // is not how excludeVAT = 0 behaves now.
        if ($storedUnitTax > self::TOLERANCE) {
            return [
                'bucket' => 'contradicted',
                'rate' => $rate,
                'inclusive' => $inclusive,
                'reason' => sprintf(
                    'inc-VAT line, yet the order added %.2f unit VAT — it was ex-VAT when sold',
                    $storedUnitTax
                ),
            ];
        }

        return [
            'bucket' => 'assumed',
            'rate' => $rate,
            'inclusive' => $inclusive,
            'reason' => 'inc-VAT line: tax_price is 0 under every rate, so the order evidences no rate',
        ];
    }

    /**
     * Write one batch.
     *
     * The NULL guards are repeated in the WHERE so a concurrent checkout or a
     * second copy of this command cannot have its freshly-frozen treatment
     * overwritten between the read and the write.
     */
    private function write(array $writes)
    {
        DB::transaction(function () use ($writes) {
            foreach ($writes as $w) {
                $this->counts['written'] += DB::table('ordered_products')
                    ->where('id', $w['row']->id)
                    ->whereNull('tax_rate')
                    ->whereNull('tax_inclusive')
                    ->update([
                        'tax_rate' => $w['rate'],
                        'tax_inclusive' => $w['inclusive'] ? 1 : 0,
                    ]);
            }
        });
    }

    private function rule($id)
    {
        if (is_null($id)) {
            return null;
        }

        if (!array_key_exists($id, $this->ruleCache)) {
            $this->ruleCache[$id] = TaxRules::find($id);
        }

        return $this->ruleCache[$id];
    }

    private function report()
    {
        $this->table(
            ['Bucket', 'Lines', 'What it means'],
            [
                ['corroborated', $this->counts['corroborated'], 'ex-VAT line; stored VAT matches today\'s rule'],
                ['assumed', $this->counts['assumed'], 'inc-VAT line; rate taken from today\'s rule alone'],
                ['contradicted', $this->counts['contradicted'], 'order disagrees with today\'s rule — never written'],
                ['no-product', $this->counts['no-product'], 'product deleted; treatment cannot be derived'],
            ]
        );

        if ($this->contradictions) {
            $this->line('');
            $this->error('Lines whose recorded VAT disagrees with today\'s tax rule:');

            $rows = [];
            foreach (array_slice($this->contradictions, 0, 50) as $c) {
                $rows[] = [$c['row']->order_id, $c['row']->id, $c['row']->product_id, $c['reason']];
            }
            $this->table(['Order', 'Line', 'Product', 'Why'], $rows);

            if (count($this->contradictions) > 50) {
                $this->line('... and ' . (count($this->contradictions) - 50) . ' more.');
            }

            $this->line('');
            $this->warn('These are left NULL. Their invoices keep saying the treatment was not');
            $this->warn('recorded, which is true, rather than restating VAT that was never charged.');
        }

        $this->line('');

        if (!$this->apply) {
            $skipped = $this->assumed ? 0 : $this->counts['assumed'];
            $would = $this->counts['corroborated'] + ($this->assumed ? $this->counts['assumed'] : 0);

            $this->info("Would write {$would} line(s). Re-run with --apply to commit.");

            if ($skipped > 0) {
                $this->line("{$skipped} inc-VAT line(s) held back; add --assumed to include them.");
            }

            return;
        }

        $this->info("Wrote {$this->counts['written']} line(s).");

        if (!$this->assumed && $this->counts['assumed'] > 0) {
            $this->line("{$this->counts['assumed']} inc-VAT line(s) left NULL; add --assumed to include them.");
        }
    }
}
