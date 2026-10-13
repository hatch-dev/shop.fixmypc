<template>
  <div class="revenue-chart card mtb-20 mtb-sm-15">
    <div class="dply-felx sided chart-head mb-20 mb-xs-15">
      <div class="chart-head__left">
        <h5 class="chart-title"><b>{{ $t('index.revenue') }}</b></h5>

        <div v-if="loading" class="chart-summary">
          <skeleton-block width="110px" height="13px" />
          <skeleton-block width="70px" height="13px" />
        </div>
        <!--
          An empty period says so instead of printing a bare 0: a big grey
          "€0" beside a heading reads as a figure, not as an absence. The
          comparison still shows, because a fall to zero from a period that had
          sales is exactly the case worth seeing.
        -->
        <div v-else-if="isEmptyPeriod" class="chart-summary">
          <span class="chart-total is-empty">{{ $t('index.noSalesPeriod') }}</span>
          <delta-pill
            v-if="previous && !previous.baseline_empty"
            :value="previous.change.total"
          />
        </div>
        <div v-else class="chart-summary">
          <span class="chart-total">{{ priceFormatting(summary.received || 0) }}</span>
          <delta-pill v-if="previous" :value="previous.change.received" :muted="previous.baseline_empty" />
          <span class="chart-meta">
            {{ summary.orders || 0 }} {{ ordersWord(summary.orders || 0) }}
            <delta-pill
              v-if="previous"
              :value="previous.change.orders"
              :muted="previous.baseline_empty"
              small
            />
            <template v-if="summary.orders">
              · {{ $t('index.avgOrder') }} {{ priceFormatting(summary.average_order || 0) }}
            </template>
          </span>

          <!--
            The headline is order value. It is not takings while money is still
            outstanding - a cash-on-delivery sale is real the moment it is
            placed, but nothing has been collected until the courier hands the
            cash over - so say what has actually come in whenever the two differ.
          -->
          <span v-if="hasOutstanding" class="chart-money">
            <span class="chart-money__received">
              {{ $t('index.receivedLabel') }} {{ priceFormatting(summary.received || 0) }}
            </span>
            <span class="chart-money__pending">
              {{ $t('index.pendingLabel') }} {{ priceFormatting(summary.pending || 0) }}
            </span>
          </span>
        </div>
      </div>

      <div class="chart-controls">
        <!--
          The native checkbox stays in the markup and keeps the label, the tab
          stop and the space-bar; only its painting is ours, so the focus ring
          hangs off the real control rather than a div pretending to be one.
        -->
        <label
          class="chart-compare"
          :class="{ 'is-on': compare, 'is-disabled': loading }"
          :title="compare ? $t('index.comparingOn') : $t('index.compare')"
        >
          <input
            type="checkbox"
            :checked="compare"
            :disabled="loading"
            @change="$emit('compare-changed', $event.target.checked)"
          >
          <span class="chart-compare__box" aria-hidden="true">
            <i class="fa fa-check"></i>
          </span>
          <span class="chart-compare__text">{{ $t('index.compare') }}</span>
        </label>

        <date-range-picker
          :value="range"
          :applied="appliedRange"
          :max-date="bounds.today"
          :disabled="loading"
          default-preset="d30"
          @change="rangeChanged"
        />
      </div>
    </div>

    <!-- The window the numbers actually describe, straight from the API -->
    <p v-if="!loading && appliedRange" class="chart-applied">
      {{ $t('index.showing') }} <b>{{ appliedLabel }}</b>
      <span class="chart-applied__unit">· {{ unitLabel }}</span>
      <span v-if="previous" class="chart-applied__vs">
        · {{ $t('index.previousPeriod') }} {{ previousLabel }}
        <template v-if="previous.baseline_empty">({{ $t('index.noBaseline') }})</template>
        <template v-else>{{ priceFormatting(previous.total) }} · {{ previous.orders }} {{ ordersWord(previous.orders) }}</template>
      </span>
      <span
        v-if="summary.cancelled_orders"
        class="chart-applied__note"
        :title="cancelledNote"
      >
        <i class="fa fa-circle-info"></i> {{ cancelledNote }}
      </span>
    </p>

    <!-- Loading: placeholder bars keep the card at its real height -->
    <div v-if="loading" class="revenue-graph">
      <div class="revenue-plot" :class="skeletonDensity">
        <div
          v-for="n in skeletonBars"
          :key="`sk-${n}`"
          class="revenue-bar-col is-skeleton"
        >
          <skeleton-block
            width="100%"
            height="100%"
            radius="8px 8px 0 0"
            :style="{ maxWidth: '54px', height: skeletonHeights[(n - 1) % skeletonHeights.length] + '%' }"
          />
        </div>
      </div>
      <div class="revenue-labels" :class="skeletonDensity">
        <span
          v-for="n in skeletonBars"
          :key="`skl-${n}`"
          class="revenue-bar-label"
        >
          <skeleton-block width="28px" height="10px" style="margin: 0 auto" />
        </span>
      </div>
    </div>

    <!-- A range with no orders still draws its axis, so the gap is visible -->
    <div v-else-if="bars.length && !hasAnyValue" class="revenue-graph is-empty">
      <!--
        Orders with nothing collected yet is not "no data" - there is data, the
        money simply has not arrived. Saying "try a wider range" would send a
        reader looking for a problem that is not there.
      -->
      <div class="revenue-empty-overlay">
        <i class="fa" :class="awaitingPayment ? 'fa-hourglass-half' : 'fa-chart-column'"></i>
        <span>{{ awaitingPayment ? $t('index.noRevenueYet') : $t('index.noDataPeriod') }}</span>
        <em v-if="awaitingPayment">{{ $t('index.awaitingCollection', { amount: priceFormatting(summary.pending || 0) }) }}</em>
        <em v-else>{{ $t('index.noSalesHint') }}</em>
      </div>
      <div class="revenue-plot" :class="densityClass">
        <div
          v-for="item in bars"
          :key="`empty-${item.date}`"
          class="revenue-bar-col is-skeleton"
          :data-date="item.date"
        >
          <div class="revenue-bar" style="height: 2%"></div>
        </div>
      </div>
      <div class="revenue-labels" :class="densityClass">
        <span
          v-for="item in bars"
          :key="`emptyl-${item.date}`"
          class="revenue-bar-label"
          :class="{ dense: bars.length > 14 }"
          :data-date="item.date"
        >{{ item.labelHidden ? '' : item.label }}</span>
      </div>
    </div>

    <div v-else-if="bars.length" class="revenue-graph">
      <!--
        Both rows iterate the same array and key off item.date, never the array
        position, so a bar and the label beneath it are the same record by
        construction and a re-render cannot pair them up differently.
      -->
      <div class="revenue-plot" :class="densityClass">
        <button
          v-for="item in bars"
          :key="`bar-${item.date}`"
          type="button"
          class="revenue-bar-col"
          :class="{ active: item.active, selected: selectedDate === item.date }"
          :title="item.tipLabel"
          :data-date="item.date"
          @click="selectBar(item)"
        >
          <span
            v-if="selectedDate === item.date"
            class="revenue-bar-tip"
            :class="{ 'is-below': item.height > 55 }"
            :style="tipStyle(item)"
          >
            <b>{{ item.tipLabel }}</b>
            <span>{{ priceFormatting(item.value) }}</span>
            <em>{{ item.orders }} {{ ordersWord(item.orders) }}</em>
            <em v-if="item.pending > 0">{{ $t('index.pendingLabel') }} {{ priceFormatting(item.pending) }}</em>
          </span>
          <span class="revenue-bar-value">{{ formatCompact(item.value) }}</span>
          <div
            class="revenue-bar"
            :style="{ height: item.height + '%' }"
          />
        </button>
      </div>
      <!--
        Every bucket keeps its axis cell; a thinned tick renders empty text
        rather than being hidden by a class. `hidden` is a contested name in
        this app - a global rule sets display:none on it - and removing cells
        from this flex row let the survivors spread across the full width, so
        the labels stopped sitting under their own bars.
      -->
      <div class="revenue-labels" :class="densityClass">
        <span
          v-for="item in bars"
          :key="`label-${item.date}`"
          class="revenue-bar-label"
          :class="{ active: item.active, dense: bars.length > 14 }"
          :data-date="item.date"
          :title="item.tipLabel"
        >{{ item.labelHidden ? '' : item.label }}</span>
      </div>
    </div>

    <div v-else class="revenue-empty">
      {{ $t('index.noDataPeriod') }}
    </div>
  </div>
</template>

<script>
  import { mapGetters } from 'vuex'
  import util from '~/mixin/util'
  import DateRangePicker from '~/components/DateRangePicker'
  import DeltaPill from '~/components/DeltaPill'
  import SkeletonBlock from '~/components/SkeletonBlock'

  export default {
    name: 'RevenueChart',
    mixins: [util],
    components: {
      DateRangePicker,
      DeltaPill,
      SkeletonBlock
    },
    props: {
      /** Buckets as returned by chart_data.revenue */
      buckets: {
        type: Array,
        default: () => []
      },
      /** chart_data.summary */
      summary: {
        type: Object,
        default: () => ({})
      },
      /** chart_data.range — the window the API actually applied */
      appliedRange: {
        type: Object,
        default: null
      },
      /** chart_data.data_bounds — limits for the custom-range pickers */
      bounds: {
        type: Object,
        default: () => ({})
      },
      /** The control's own state: { preset, start, end } */
      range: {
        type: Object,
        default: () => ({ preset: 'd30', start: null, end: null })
      },
      loading: {
        type: Boolean,
        default: false
      },
      compare: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        // The selection is a date, not a position: a new range replaces the
        // array wholesale, and an index would silently point at a different day.
        selectedDate: null,
        skeletonHeights: [38, 62, 25, 74, 46, 88, 33, 57, 70, 29, 52, 81, 41, 66]
      }
    },
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      unitLabel() {
        return {
          day: this.$t('index.byDay'),
          week: this.$t('index.byWeek'),
          month: this.$t('index.byMonth'),
          year: this.$t('index.byYear')
        }[this.appliedRange?.unit] || ''
      },
      appliedLabel() {
        const r = this.appliedRange
        if (!r) return ''
        return this.formatRange(r.start, r.end)
      },
      cancelledNote() {
        return this.$t('index.cancelledExcluded', {
          count: this.summary.cancelled_orders,
          amount: this.priceFormatting(this.summary.cancelled_amount || 0)
        })
      },
      /** Any money actually received in the window - what the plot draws. */
      hasAnyValue() {
        return this.buckets.some(b => Number(b.received ?? b.value) > 0)
      },
      /** Orders were placed in this window, but none of the money is in yet. */
      awaitingPayment() {
        return !this.hasAnyValue && Number(this.summary.orders || 0) > 0
      },
      /** True while any money for this period is still uncollected. */
      hasOutstanding() {
        return Number(this.summary.pending || 0) > 0
      },
      isEmptyPeriod() {
        return !this.summary.orders
      },
      /** Only present when the API was asked to compare. */
      previous() {
        return this.summary.previous || null
      },
      previousLabel() {
        const p = this.previous
        return p ? this.formatRange(p.start, p.end) : ''
      },
      /**
       * A 30- or 90-bucket range puts far more bars in the plot than the seven
       * the fixed 24px gap was sized for — 90 bars alone need 2136px of gap,
       * which pushed the card sideways. Tighten the gap as the count grows.
       */
      densityClass() {
        // Off buckets rather than bars, for the same reason as labelStride.
        return this.densityFor(this.buckets.length)
      },
      skeletonDensity() {
        return this.densityFor(this.skeletonBars)
      },
      // Past ~20 ticks the axis is unreadable, so print every Nth label only.
      /*
       * Counts off `buckets`, not `bars`. `bars` reads this value to mark which
       * labels to hide, so reading bars.length here made the two computeds
       * depend on each other and the component never rendered at all.
       */
      labelStride() {
        const n = this.buckets.length
        if (n > 60) return 10
        if (n > 40) return 7
        if (n > 20) return 3
        return 1
      },
      skeletonBars() {
        // Keep the previous bucket count while refetching so the card does not
        // change height between range switches.
        return Math.min(this.buckets.length || 7, 14)
      },
      maxValue() {
        return Math.max(...this.buckets.map(m => Number(m.received ?? m.value) || 0), 1)
      },
      /**
       * One entry per API record, carrying its own date. Nothing here derives a
       * date from a position, and the API already guarantees the sequence is
       * complete, ordered and gapless with zero-value days present.
       */
      bars() {
        const max = this.maxValue
        const last = this.buckets.length - 1
        const stride = this.labelStride

        return this.buckets.map((m, i) => {
          /*
           * The bar is money received. The order value is carried alongside
           * it so the tooltip can explain a day that took orders but no cash
           * - a bar at zero with a sale on it is the point of the split.
           */
          const value = Number(m.received ?? m.value) || 0
          const orderValue = Number(m.value) || 0
          const pending = Number(m.pending ?? (orderValue - value)) || 0
          return {
            date: m.date || m.key,
            label: m.label,
            tipLabel: m.full_label || m.label,
            orders: Number(m.orders) || 0,
            value,
            orderValue,
            pending,
            active: i === last,
            // Thin the axis without dropping records: every bucket still
            // renders, only some labels are made invisible.
            labelHidden: stride > 1 && i !== last && (last - i) % stride !== 0,
            height: value > 0 ? Math.max((value / max) * 78, 6) : 2
          }
        })
      },
      ...mapGetters('setting', ['setting'])
    },
    watch: {
      /*
       * A new range means a new set of days. Drop the selection unless the same
       * date still exists, so a tooltip can never survive onto a different day.
       */
      buckets(next) {
        if (!this.selectedDate) return
        const stillThere = (next || []).some(b => (b.date || b.key) === this.selectedDate)
        if (!stillThere) this.selectedDate = null
      }
    },
    methods: {
      /** "1 order" reads better than "1 orders" beside a single sale. */
      ordersWord(n) {
        return Number(n) === 1 ? this.$t('index.periodOrder') : this.$t('index.periodOrders')
      },
      densityFor(n) {
        if (n > 40) return 'is-ultra-dense'
        if (n > 14) return 'is-dense'
        return ''
      },
      formatRange(start, end) {
        const fmt = iso => {
          const d = new Date(iso + 'T00:00:00')
          if (isNaN(d)) return iso
          const day = String(d.getDate()).padStart(2, '0')
          const month = d.toLocaleString(this.$i18n?.locale || 'en', { month: 'short' })
          return `${day} ${month} ${d.getFullYear()}`
        }
        return start === end ? fmt(start) : `${fmt(start)} – ${fmt(end)}`
      },
      // Bar captions have to respect the same currency position as every other
      // amount on the page, and roll over past "k" instead of printing figures
      // like "12345.7k" once a shop passes a million.
      compactNumber(value) {
        const v = Number(value) || 0
        const abs = Math.abs(v)
        if (abs >= 1000000000) return `${(v / 1000000000).toFixed(1)}b`
        if (abs >= 1000000) return `${(v / 1000000).toFixed(1)}m`
        if (abs >= 1000) return `${(v / 1000).toFixed(1)}k`
        return `${Math.round(v)}`
      },
      formatCompact(value) {
        const num = this.compactNumber(value)
        const symbol = this.currencySymbol()
        const position = this.currencyPosition ?? this.setting?.currency_position
        return parseInt(position) === this.currencyPositionsIn.PRE
          ? `${symbol}${num}`
          : `${num}${symbol}`
      },
      // A tooltip pinned above a tall bar overflowed the plot and covered the
      // chart heading, so flip it inside the bar once the bar is tall enough.
      tipStyle(item) {
        return item.height > 55
          ? { bottom: `calc(${item.height}% - 66px)` }
          : { bottom: `calc(${item.height}% + 30px)` }
      },
      selectBar(item) {
        this.selectedDate = this.selectedDate === item.date ? null : item.date
      },
      rangeChanged(payload) {
        this.selectedDate = null
        this.$emit('range-changed', payload)
      }
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/revenue-chart.styl"
</style>
