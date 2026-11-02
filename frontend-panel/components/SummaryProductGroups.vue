<template>
  <!--
    The products in the Order Summary, in three groups by how VAT applies to
    them, always in this order:

      none      0% - zero-rated or margin scheme, no VAT either way
      included  the price already contains its VAT; nothing is added
      added     the price is ex-VAT and VAT is added at checkout

    Presentation only. Every figure arrives from CheckoutRight's productGroups,
    which sums the server's own per-line split (Pricing::vatBreakdown); nothing
    here applies a rate or rounds an amount.

    `collapsible` is the phone fold: each group opens from its subtotal, so a
    long basket does not push the total out of the panel.
  -->
  <div class="spg" :class="{ 'is-collapsible': collapsible }">
    <section
      v-for="group in groups"
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
        <i18n
          v-if="group.key === 'included'"
          path="ui.checkoutSummary.groups.peekIncluded"
          tag="span"
        >
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
        <ul class="spg-lines">
          <li v-for="line in group.lines" :key="`${group.key}-${line.id}`" class="spg-line">
            <div class="spg-line-main">
              <span class="spg-line-name">
                {{ line.title }}
                <span class="spg-line-qty">&times; {{ line.quantity }}</span>
                <small v-if="line.variant" class="spg-line-variant">{{ line.variant }}</small>
              </span>
              <span class="spg-line-value">
                <price-format :price="line.amount" :decimals="2" />
              </span>
            </div>

            <i18n
              v-if="line.unit !== null"
              path="ui.checkoutSummary.groups.each"
              tag="p"
              class="spg-line-detail"
            >
              <template #price><price-format :price="line.unit" :decimals="2" /></template>
            </i18n>

            <i18n
              v-if="group.key === 'included'"
              path="ui.checkoutSummary.groups.lineIncluded"
              tag="p"
              class="spg-line-detail"
            >
              <template #ex><price-format :price="line.ex" :decimals="2" /></template>
              <template #rate>{{ line.rateLabel }}</template>
              <template #vat><price-format :price="line.vat" :decimals="2" /></template>
            </i18n>

            <i18n
              v-else-if="group.key === 'added'"
              path="ui.checkoutSummary.groups.lineAdded"
              tag="p"
              class="spg-line-detail is-added"
            >
              <template #rate>{{ line.rateLabel }}</template>
              <template #vat><price-format :price="line.vat" :decimals="2" /></template>
              <template #inc><price-format :price="line.inc" :decimals="2" /></template>
            </i18n>
          </li>
        </ul>

        <div class="spg-totals">
          <!-- ① -->
          <div v-if="group.key === 'none'" class="spg-row is-strong">
            <span>{{ $t('ui.checkoutSummary.groups.subtotalWithoutVat') }}</span>
            <price-format :price="group.inc" :decimals="2" />
          </div>

          <!-- ② -->
          <template v-else-if="group.key === 'included'">
            <div class="spg-row is-strong">
              <span>{{ $t('ui.checkoutSummary.groups.subtotalIncVat') }}</span>
              <price-format :price="group.inc" :decimals="2" />
            </div>
            <div class="spg-row">
              <span>{{ $t('ui.checkoutSummary.groups.vatIncludedInPrices') }}</span>
              <price-format :price="group.vat" :decimals="2" />
            </div>
            <div v-for="r in multiRate(group)" :key="`inc-rate-${r.rate}`" class="spg-row is-rate">
              <span>{{ $t('ui.checkoutSummary.groups.atRate', { rate: r.rateLabel }) }}</span>
              <price-format :price="r.vat" :decimals="2" />
            </div>
          </template>

          <!-- ③ -->
          <template v-else>
            <div class="spg-row">
              <span>{{ $t('ui.checkoutSummary.groups.subtotalExVat') }}</span>
              <price-format :price="group.ex" :decimals="2" />
            </div>
            <div class="spg-row">
              <span>{{ $t('ui.checkoutSummary.groups.vatAdded') }}</span>
              <span>+ <price-format :price="group.vat" :decimals="2" /></span>
            </div>
            <div v-for="r in multiRate(group)" :key="`add-rate-${r.rate}`" class="spg-row is-rate">
              <span>{{ $t('ui.checkoutSummary.groups.atRate', { rate: r.rateLabel }) }}</span>
              <span>+ <price-format :price="r.vat" :decimals="2" /></span>
            </div>
            <div class="spg-row is-strong">
              <span>{{ $t('ui.checkoutSummary.groups.subtotalIncVat') }}</span>
              <price-format :price="group.inc" :decimals="2" />
            </div>
          </template>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import PriceFormat from './PriceFormat'

let seq = 0

export default {
  name: 'SummaryProductGroups',
  components: { PriceFormat },
  props: {
    /* CheckoutRight.productGroups: already ordered, empty groups left out */
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
  methods: {
    isOpen(key) {
      return !!this.open[key]
    },
    toggle(key) {
      this.$set(this.open, key, !this.open[key])
    },
    /*
     * The per-rate rows under a group's VAT figure, only when the group holds
     * more than one rate - with one, the row above already is that rate.
     */
    multiRate(group) {
      return group.rates.length > 1 ? group.rates : []
    }
  }
}
</script>

<style scoped>
/*
  Logical properties throughout (inline-start/-end, text-align: start), so the
  groups follow `.rtl { direction: rtl }` in Arabic without rules of their own.
*/
.spg-group {
  padding: 8px 0 10px;
  border-bottom: 1px solid #E3E8F0;
}

.spg-group:first-child {
  padding-top: 2px;
}

.spg-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  margin: 0 0 6px;
  padding: 0;
  border: 0;
  background: none;
  text-align: start;
  font: inherit;
  color: inherit;
}

/* base.styl gives every <button> height and line-height 50px */
button.spg-head {
  height: auto;
  min-height: 40px;
  line-height: 1.3;
  cursor: pointer;
}

button.spg-head:focus-visible {
  outline: 2px solid #1A56DB;
  outline-offset: 2px;
  border-radius: 6px;
}

.spg-head-text {
  min-width: 0;
}

.spg-title {
  display: block;
  font-size: 12.5px;
  font-weight: 800;
  letter-spacing: .04em;
  line-height: 1.3;
  text-transform: uppercase;
  color: #0F2547;
}

/* the group's own colour, as a marker before the title */
.spg-title::before {
  content: '';
  display: inline-block;
  width: 8px;
  height: 8px;
  margin-inline-end: 7px;
  border-radius: 2px;
  vertical-align: 1px;
  background: #94A3B8;
}

.is-included .spg-title::before {
  background: #16A34A;
}

.is-added .spg-title::before {
  background: #D97706;
}

.spg-tag {
  display: block;
  margin-top: 1px;
  padding-inline-start: 15px;
  font-size: 12px;
  line-height: 1.35;
  color: #6B7C93;
}

.spg-head-value {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14.5px;
  font-weight: 700;
  white-space: nowrap;
  color: #0F2547;
}

.spg-chev {
  color: #6B7C93;
  transition: transform .2s ease;
}

button[aria-expanded="true"] .spg-chev {
  transform: rotate(180deg);
}

.spg-peek {
  margin: -2px 0 0;
  padding-inline-start: 15px;
  font-size: 12.5px;
  color: #6B7C93;
}

.spg-lines {
  margin: 0;
  padding: 0;
  list-style: none;
}

.spg-line {
  padding: 4px 0;
}

.spg-line + .spg-line {
  border-top: 1px dashed #EEF1F6;
}

.spg-line-main {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.spg-line-name {
  min-width: 0;
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.35;
  color: #0F2547;
  overflow-wrap: anywhere;
}

.spg-line-qty {
  font-weight: 400;
  color: #6B7C93;
  white-space: nowrap;
}

.spg-line-variant {
  display: block;
  font-size: 12px;
  font-weight: 400;
  color: #6B7C93;
}

.spg-line-value {
  flex: 0 0 auto;
  font-size: 13.5px;
  font-weight: 700;
  white-space: nowrap;
  color: #0F2547;
}

.spg-line-detail {
  margin: 1px 0 0;
  font-size: 12px;
  line-height: 1.4;
  color: #4B5D78;
}

.spg-line-detail.is-added {
  color: #92400E;
}

.spg-totals {
  margin-top: 6px;
  padding: 6px 10px;
  border-radius: 8px;
  background: #F7F9FC;
}

.spg-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 2px 0;
  font-size: 13px;
  line-height: 1.4;
  color: #33486A;
}

.spg-row > :last-child {
  flex: 0 0 auto;
  white-space: nowrap;
}

.spg-row.is-strong {
  font-weight: 700;
  color: #0F2547;
}

.spg-row.is-rate {
  padding-inline-start: 12px;
  font-size: 12px;
  color: #6B7C93;
}

/* the phone fold is narrower and already scrolls: a little tighter */
.is-collapsible .spg-group {
  padding: 6px 0 8px;
}

.is-collapsible .spg-head {
  margin: 0;
}

.is-collapsible .spg-body {
  margin-top: 6px;
}

@media (prefers-reduced-motion: reduce) {
  .spg-chev {
    transition: none;
  }
}
</style>
