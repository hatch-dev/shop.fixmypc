<template>
  <!--
    The products in the Order Summary, one numbered table per way VAT applies:
      included  the price already contains its VAT; nothing is added
      added     the price is ex-VAT; VAT is added, line by line
      none      no VAT either way (zero-rated or margin scheme)
    each with its subtotal and the sum written out ("€1 + €799 = €800").

    Presentation only. Every figure arrives from CheckoutRight's productGroups,
    which sums the server's own per-line split (Pricing::lineVat); nothing here
    applies a rate or rounds an amount.

    `collapsible` is the phone fold: each group opens from its subtotal, so a
    long basket does not push the total out of the panel.
  -->
  <div class="spg" :class="{ 'is-collapsible': collapsible }">
    <section
      v-for="(group, index) in ordered"
      :key="group.key"
      class="spg-group"
      :class="`is-${group.key}`"
    >
      <component
        :is="collapsible ? 'button' : 'div'"
        :type="collapsible ? 'button' : null"
        class="spg-head"
        :aria-expanded="collapsible ? (isOpen(group.key) ? 'true' : 'false') : null"
        :aria-controls="collapsible ? `spg-${uid}-${group.key}` : null"
        @click="collapsible && toggle(group.key)"
      >
        <span v-if="!collapsible" class="spg-num" aria-hidden="true">{{ index + 1 }}</span>
        <span class="spg-head-text">
          <span class="spg-title">{{ $t(`ui.checkoutSummary.groups.${group.key}.title`) }}</span>
          <span class="spg-tag">
            {{ $t(`ui.checkoutSummary.groups.${group.key}.tag`) }}
            <template v-if="collapsible"> · {{ $tc('ui.checkoutSummary.groups.items', group.count, { count: group.count }) }}</template>
          </span>
        </span>
        <!-- Closed on a phone: the group's subtotal stands in for its lines. -->
        <span v-if="collapsible" class="spg-head-value">
          <price-format :price="group.inc" :decimals="2" />
          <svg class="spg-chev" width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
          </svg>
        </span>
      </component>

      <!-- the group's VAT, still visible while it is closed -->
      <p v-if="collapsible && !isOpen(group.key) && group.key !== 'none'" class="spg-peek">
        <i18n v-if="group.key === 'included'" path="ui.checkoutSummary.groups.peekIncluded" tag="span">
          <template #vat><price-format :price="group.vat" :decimals="2" /></template>
        </i18n>
        <i18n v-else path="ui.checkoutSummary.groups.peekAdded" tag="span">
          <template #ex><price-format :price="group.ex" :decimals="2" /></template>
          <template #vat><price-format :price="group.vat" :decimals="2" /></template>
        </i18n>
      </p>

      <div
        v-show="!collapsible || isOpen(group.key)"
        :id="collapsible ? `spg-${uid}-${group.key}` : null"
        class="spg-body"
      >
        <!-- VAT included / no VAT: one row per product at the price paid, then the subtotal -->
        <table v-if="group.key !== 'added'" class="spg-table">
          <thead>
            <tr>
              <th scope="col">{{ $t('ui.checkoutSummary.table.product') }}</th>
              <th scope="col" class="spg-num-col">
                {{ group.key === 'included' ? $t('ui.checkoutSummary.table.priceIncVat') : $t('ui.checkoutSummary.table.price') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in group.lines" :key="`${group.key}-${line.id}`">
              <td class="spg-name-cell">
                <span class="spg-name">
                  <img v-if="line.thumb" :src="line.thumb" alt="" class="spg-thumb" loading="lazy" width="28" height="28">
                  <span>
                    {{ line.title }}<span v-if="line.quantity > 1" class="spg-qty"> &times; {{ line.quantity }}</span>
                    <small v-if="line.variant" class="spg-variant">{{ line.variant }}</small>
                  </span>
                </span>
              </td>
              <td class="spg-num-col"><price-format :price="line.inc" :decimals="2" /></td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="spg-sub">
              <th scope="row">{{ $t('ui.checkoutSummary.table.subtotal') }}</th>
              <td class="spg-num-col"><price-format :price="group.inc" :decimals="2" /></td>
            </tr>
          </tfoot>
        </table>

        <!-- VAT added: per product, the ex-VAT price + its VAT = its price incl. VAT -->
        <table v-else class="spg-table is-added">
          <thead>
            <tr>
              <th scope="col">{{ $t('ui.checkoutSummary.table.details') }}</th>
              <th scope="col" class="spg-num-col">{{ $t('ui.checkoutSummary.table.amount') }}</th>
            </tr>
          </thead>
          <tbody v-for="line in group.lines" :key="`${group.key}-${line.id}`" class="spg-line-block">
            <tr>
              <td class="spg-name-cell">
                <span class="spg-name">
                  <img v-if="line.thumb" :src="line.thumb" alt="" class="spg-thumb" loading="lazy" width="28" height="28">
                  <span>
                    {{ $t('ui.checkoutSummary.table.priceExVat', { name: line.title }) }}<span v-if="line.quantity > 1" class="spg-qty"> &times; {{ line.quantity }}</span>
                    <small v-if="line.variant" class="spg-variant">{{ line.variant }}</small>
                  </span>
                </span>
              </td>
              <td class="spg-num-col"><price-format :price="line.ex" :decimals="2" /></td>
            </tr>
            <tr>
              <td>{{ $t('ui.checkoutSummary.table.vat') }} <span class="spg-vat-badge">{{ line.rateLabel }}</span></td>
              <td class="spg-num-col"><span class="spg-op">+</span> <price-format :price="line.vat" :decimals="2" /></td>
            </tr>
            <tr class="spg-line-total">
              <th scope="row">{{ $t('ui.checkoutSummary.table.finalIncVat', { name: line.title }) }}</th>
              <td class="spg-num-col"><price-format :price="line.inc" :decimals="2" /></td>
            </tr>
            <tr class="spg-calc-row">
              <td colspan="2">
                <span class="spg-calc">
                  <price-format :price="line.ex" :decimals="2" /><span class="spg-calc-op">+</span><price-format :price="line.vat" :decimals="2" /><span class="spg-calc-op">=</span><price-format :price="line.inc" :decimals="2" />
                </span>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="group.lines.length > 1">
            <tr class="spg-sub">
              <th scope="row">{{ $t('ui.checkoutSummary.groups.subtotalIncVat') }}</th>
              <td class="spg-num-col"><price-format :price="group.inc" :decimals="2" /></td>
            </tr>
          </tfoot>
        </table>

        <!-- the subtotal written out, where there is more than one figure to add -->
        <p v-if="group.lines.length > 1" class="spg-calc spg-calc-sum">
          <template v-for="(line, i) in group.lines">
            <span v-if="i > 0" :key="`op-${line.id}`" class="spg-calc-op">+</span><price-format :key="`v-${line.id}`" :price="line.inc" :decimals="2" />
          </template>
          <span class="spg-calc-op">=</span><strong><price-format :price="group.inc" :decimals="2" /></strong>
        </p>
        <p v-if="group.key === 'included' && group.vat > 0.004" class="spg-note">
          <i18n path="ui.checkoutSummary.table.includedVat" tag="span">
            <template #vat><price-format :price="group.vat" :decimals="2" /></template>
          </i18n>
        </p>
      </div>
    </section>
  </div>
</template>

<script>
import PriceFormat from './PriceFormat'

let seq = 0

/* The order the tables are read in: what is already final, what VAT is added to, what has none. */
const DISPLAY_ORDER = ['included', 'added', 'none']

export default {
  name: 'SummaryProductGroups',
  components: { PriceFormat },
  props: {
    /* CheckoutRight.productGroups: empty groups left out */
    groups: {
      type: Array,
      default: () => []
    },
    /* the phone fold: each group opens from its subtotal */
    collapsible: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      open: {},
      // ids for aria-controls, unique per instance: the card and the fold
      // are both in the DOM at once
      uid: ++seq
    }
  },
  computed: {
    ordered() {
      return DISPLAY_ORDER.map(key => this.groups.find(g => g.key === key)).filter(Boolean)
    }
  },
  methods: {
    isOpen(key) {
      return !!this.open[key]
    },
    toggle(key) {
      this.$set(this.open, key, !this.open[key])
    }
  }
}
</script>

<style scoped>
/*
  Logical properties throughout (inline-start/-end, text-align: start/end), so
  the tables follow `.rtl { direction: rtl }` in Arabic without rules of their own.
*/
.spg-group {
  padding: 6px 0 12px;
}
.spg-head {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 10px;
  margin: 0 0 8px;
  border: 0;
  border-radius: 10px;
  background: #EEF2FF;
  color: #1E1B4B;
  text-align: start;
}
.spg-num {
  flex: 0 0 auto;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--primary-color, #33319A);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.spg-head-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1 1 auto;
}
.spg-title {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.3;
}
.spg-tag {
  font-size: 11.5px;
  color: #4B5563;
  line-height: 1.3;
}
.spg-head-value {
  margin-inline-start: auto;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  white-space: nowrap;
}
.is-collapsible .spg-head {
  cursor: pointer;
}
.spg-chev {
  transition: transform .15s ease;
}
.spg-head[aria-expanded='true'] .spg-chev {
  transform: rotate(180deg);
}
.spg-peek {
  margin: -4px 0 6px;
  padding-inline-start: 10px;
  font-size: 12px;
  color: #6B7280;
}

/* --- tables ---------------------------------------------------------------- */
.spg-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
  overflow: hidden;
  font-size: 13px;
  table-layout: auto;
}
.spg-table th,
.spg-table td {
  padding: 7px 10px;
  border-bottom: 1px solid #EEF0F4;
  vertical-align: middle;
  text-align: start;
  color: #1F2937;
  font-weight: 400;
}
.spg-table thead th {
  background: #F5F7FB;
  font-size: 12px;
  font-weight: 700;
  color: #374151;
}
.spg-table tr:last-child > th,
.spg-table tr:last-child > td {
  border-bottom: 0;
}
.spg-table .spg-num-col {
  text-align: end;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  width: 1%;
}
.spg-name {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.spg-name > span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.spg-thumb {
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  object-fit: contain;
  border-radius: 6px;
  background: #F3F4F6;
}
.spg-qty {
  color: #6B7280;
  white-space: nowrap;
}
.spg-variant {
  display: block;
  font-size: 11.5px;
  color: #6B7280;
}
.spg-vat-badge {
  display: inline-block;
  padding: 1px 7px;
  margin-inline-end: 6px;
  border-radius: 999px;
  background: #E0E7FF;
  color: var(--primary-color, #33319A);
  font-size: 11px;
  font-weight: 700;
}
.spg-op {
  color: #6B7280;
}
.spg-line-total > th,
.spg-line-total > td {
  font-weight: 700 !important;
  background: #F8FAFF;
}
.spg-sub > th,
.spg-sub > td {
  font-weight: 700 !important;
  background: #EEF2FF;
  color: #111827 !important;
  border-top: 1px solid #E3E8F0;
}
.spg-calc-row > td {
  padding-top: 4px;
  padding-bottom: 8px;
  background: #fff;
}
.spg-calc {
  display: inline-block;
  font-size: 12px;
  color: #4B5563;
  font-variant-numeric: tabular-nums;
}
/* the sum written out, set apart from the table as working rather than another row */
.spg-calc-sum {
  display: block;
  margin: 6px 2px 0;
  padding: 5px 10px;
  border-radius: 8px;
  background: #F5F7FB;
}
.spg-calc-op {
  margin: 0 5px;
  color: #6B7280;
  font-weight: 700;
}
.spg-note {
  margin: 4px 2px 0;
  font-size: 12px;
  color: #6B7280;
}

@media (max-width: 480px) {
  .spg-table th,
  .spg-table td {
    padding: 6px 8px;
  }
  .spg-thumb {
    display: none;
  }
}
</style>
