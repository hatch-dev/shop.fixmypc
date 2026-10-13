<template>
  <div class="dash-insights">

    <!-- Top Products by Revenue -->
    <div class="dash-panel card">
      <div class="dash-panel__head">
        <h4 class="dash-panel__title">{{ $t('index.topProdRev') }}</h4>
        <span class="dash-panel__hint">{{ $t('index.thisMonth') }}</span>
      </div>

      <div v-if="loadingProducts">
        <div v-for="n in 4" :key="`tps-${n}`" class="tp-row is-skeleton">
          <skeleton-block width="22px" height="22px" radius="6px" />
          <skeleton-block width="38px" height="38px" radius="8px" />
          <div class="skeleton-stack">
            <skeleton-block :width="skeletonWidths[n - 1]" height="12px" />
            <skeleton-block width="40%" height="10px" />
          </div>
          <skeleton-block width="58px" height="12px" />
        </div>
      </div>

      <div v-else-if="!topProducts.length" class="dash-panel__state">{{ $t('index.noSales') }}</div>

      <nuxt-link
        v-else
        v-for="(p, i) in topProducts"
        :key="'tp' + p.id"
        :to="`/products/${p.id}`"
        class="tp-row"
      >
        <span class="tp-rank" :class="{ 'is-top': i < 3 }">{{ i + 1 }}</span>
        <entity-thumb :image="p.image" :fallback-image="p.fallback_image" :title="p.title" size="38px" />
        <div class="tp-body">
          <span class="tp-name" :title="p.title">{{ p.title }}</span>
          <span class="tp-orders">{{ $t('index.soldUnits', { count: p.total }) }}</span>
        </div>
        <span class="tp-rev">
          {{ priceFormatting(p.received_price || 0) }}
          <!-- The panel ranks by revenue, so an uncollected line reads zero
               here; the amount still owed is named rather than silently lost. -->
          <em v-if="Number(p.pending_price) > 0" class="tp-pending">
            {{ $t('index.pendingLabel') }} {{ priceFormatting(p.pending_price) }}
          </em>
        </span>
      </nuxt-link>
    </div>

    <!-- Low Stock Alerts -->
    <div class="dash-panel card">
      <div class="dash-panel__head">
        <h4 class="dash-panel__title">
          {{ $t('index.lowStock') }}
          <!-- Products needing attention, not the size of this preview -->
          <span v-if="lowStockTotal" class="dash-panel__count">{{ lowStockTotal }}</span>
        </h4>
        <button
          type="button"
          class="dash-panel__refresh"
          :disabled="loadingStock"
          :title="$t('index.refresh')"
          :aria-label="$t('index.refresh')"
          @click="fetchLowStock"
        >
          <i class="fa fa-rotate" :class="{ 'fa-spin': loadingStock }" aria-hidden="true"></i>
        </button>
        <nuxt-link to="/inventory-alerts" class="dash-panel__link">{{ $t('index.viewAll') }}</nuxt-link>
      </div>

      <div v-if="loadingStock">
        <div v-for="n in 4" :key="`lss-${n}`" class="ls-row is-skeleton">
          <skeleton-block width="26px" height="26px" radius="8px" />
          <div class="skeleton-stack">
            <skeleton-block :width="skeletonWidths[n - 1]" height="12px" />
            <skeleton-block width="30%" height="10px" />
          </div>
          <skeleton-block width="30px" height="12px" />
        </div>
      </div>

      <div v-else-if="!lowStock.length" class="dash-panel__state">{{ $t('index.allStocked') }}</div>

      <template v-else>
        <!--
          Three distinct states, each saying what the number means. The old row
          printed a bare "0" or "-13" beside a product name, which told a reader
          neither what was wrong nor what to do about it.
        -->
        <nuxt-link
          v-for="p in alerts"
          :key="'ls' + p.id"
          :to="`/products/${p.id}`"
          class="ls-row"
          :class="`is-${p.severity}`"
        >
          <span class="ls-icon">
            <i class="fa" :class="p.icon" aria-hidden="true"></i>
          </span>
          <div class="ls-body">
            <span class="ls-state">{{ p.label }}</span>
            <span class="ls-name" :title="p.title">{{ p.title }}</span>
            <span class="ls-detail">
              {{ p.detail }}
              <template v-if="p.variantNote"> · {{ p.variantNote }}</template>
            </span>
          </div>
        </nuxt-link>

        <!-- Oversell is a data problem, so name it rather than hiding it -->
        <p v-if="counts.negative" class="ls-note">
          <i class="fa fa-circle-info" aria-hidden="true"></i>
          {{ $t('index.lowStockNote') }}
        </p>
      </template>
    </div>

  </div>
</template>

<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
import inventoryAlert from '~/mixin/inventory-alert'
  import EntityThumb from '~/components/EntityThumb'
  import SkeletonBlock from '~/components/SkeletonBlock'

  export default {
    name: 'DashboardInsights',
    mixins: [util, inventoryAlert],
    components: {
      EntityThumb,
      SkeletonBlock
    },
    data() {
      return {
        loadingProducts: true,
        loadingStock: true,
        topProducts: [],
        lowStock: [],
        lowStockTotal: 0,
        counts: { negative: 0, out: 0, low: 0, all: 0 },
        threshold: null,
        skeletonWidths: ['64%', '48%', '72%', '55%']
      }
    },
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '€'
      },
      /**
       * Presentation only. The severity itself is decided by the backend from
       * the inventory rows - the browser must not re-derive it from a single
       * number, because "0", "-13" and "2" are three different business states
       * and a product's state comes from its worst variant, not from a sum.
       */
      alerts() {
        return (this.lowStock || []).map(p => this.inventoryAlertView(p))
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      async fetchTopProducts() {
        this.loadingProducts = true
        try {
          // The panel is labelled "This month", so ask for this month - it used
          // to send no time filter at all and render all-time figures under a
          // monthly heading.
          const now = new Date()
          const data = await this.getRequest({
            api: 'orderStatistic',
            params: {
              time_zone: this.timeZone,
              time: new Date(now.getFullYear(), now.getMonth(), 1)
            }
          })
          this.topProducts = (data?.products || []).slice(0, 5)
        } catch (e) {
          // best-effort widget
        } finally {
          this.loadingProducts = false
        }
      },
      async fetchLowStock() {
        this.loadingStock = true
        try {
          const data = await this.getRequest({ api: 'lowStockProducts' })
          // `total` is the full count of products needing attention; `items` is
          // the truncated preview, so the badge never reports the page size.
          this.lowStock = data?.items || (Array.isArray(data) ? data : [])
          this.lowStockTotal = Number(data?.total) || 0
          this.counts = data?.counts || { negative: 0, out: 0, low: 0, all: 0 }
          this.threshold = data?.threshold ?? null
        } catch (e) {
          // best-effort widget
        } finally {
          this.loadingStock = false
        }
      },
      ...mapActions('common', ['getRequest'])
    },
    mounted() {
      this.fetchTopProducts()
      this.fetchLowStock()
    }
  }
</script>

<style lang="stylus" scoped>
  .dash-insights
    display grid
    grid-template-columns 1fr 1fr
    gap 16px
    margin 16px 0

  .dash-panel
    background var(--surface-color)
    border 1px solid var(--border-color)
    border-radius 12px
    padding 18px 20px
    box-shadow 0 1px 3px var(--shadow-color)

  .dash-panel__head
    display flex
    align-items center
    justify-content space-between
    gap 12px
    margin-bottom 12px

  .dash-panel__title
    display flex
    align-items center
    gap 8px
    margin 0
    font-size 15px
    font-weight 700
    color var(--text-strong)

  .dash-panel__count
    display inline-flex
    align-items center
    justify-content center
    min-width 20px
    height 20px
    padding 0 6px
    border-radius 10px
    background var(--danger-text)
    color #fff
    font-size 11px
    font-weight 700

  .dash-panel__hint
    font-size 12px
    color var(--text-soft)

  .dash-panel__link
    font-size 12px
    font-weight 600
    color var(--primary-color)
    padding 5px 10px
    border 1px solid var(--border-color)
    border-radius var(--radius-md)

    &:hover
      background var(--hover-color)

  .dash-panel__state
    padding 28px 0
    text-align center
    color var(--text-muted)
    font-size 13px

  .tp-row,
  .ls-row
    display flex
    align-items center
    gap 10px
    padding 8px 6px
    border-radius 8px
    color inherit
    transition background .12s ease

    &:not(.is-skeleton):hover
      background var(--hover-color)

  .tp-rank
    flex none
    width 22px
    height 22px
    display flex
    align-items center
    justify-content center
    border-radius 6px
    background var(--hover-color)
    color var(--text-muted)
    font-size 11px
    font-weight 700

    &.is-top
      background var(--primary-soft)
      color var(--primary-color)

  .tp-body,
  .ls-body
    flex 1
    min-width 0
    display flex
    flex-direction column

  .tp-name,
  .ls-name
    font-size 13px
    font-weight 600
    color var(--text-strong)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .tp-orders,
  .ls-sku
    font-size 11px
    color var(--text-soft)

  .tp-rev
    flex none
    font-size 13px
    font-weight 700
    color var(--success-text)
    white-space nowrap

  .tp-pending
    display block
    margin-top 1px
    font-size 10.5px
    font-style normal
    font-weight 600
    color var(--warning-text)

  .ls-icon
    flex none
    width 26px
    height 26px
    border-radius 8px
    display flex
    align-items center
    justify-content center
    font-size 12px
    background var(--warning-bg)
    color var(--warning-text)

  // Low stock is a restocking task (amber); an empty shelf and an oversell are
  // both faults (red), and the oversell carries the heavier icon.
  .ls-row.is-out .ls-icon,
  .ls-row.is-negative .ls-icon
    background var(--danger-bg)
    color var(--danger-text)

  .ls-row
    align-items flex-start

  .ls-state
    display block
    font-size 10px
    font-weight 700
    letter-spacing .04em
    text-transform uppercase
    color var(--warning-text)

  .ls-row.is-out .ls-state,
  .ls-row.is-negative .ls-state
    color var(--danger-text)

  .ls-detail
    display block
    font-size 11px
    font-weight 600
    color var(--text-muted)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .dash-panel__refresh
    margin-left auto
    width 24px
    height 24px
    display inline-flex
    align-items center
    justify-content center
    border 1px solid var(--border-color)
    border-radius 6px
    background transparent
    color var(--text-muted)
    font-size 10px
    cursor pointer
    transition all .12s ease

    &:hover:not(:disabled)
      border-color var(--primary-color)
      color var(--primary-color)

    &:disabled
      opacity .5
      cursor not-allowed

  .ls-note
    display flex
    align-items flex-start
    gap 7px
    margin 10px 2px 0
    padding-top 10px
    border-top 1px solid var(--border-color)
    font-size 11px
    line-height 1.45
    color var(--text-muted)

    i
      margin-top 2px
      color var(--text-soft)

  @media only screen and (max-width: 900px)
    .dash-insights
      grid-template-columns 1fr
</style>
