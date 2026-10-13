<template>
  <div class="inventory-alerts">
    <div class="ia-head">
      <div>
        <h2 class="ia-title">{{ $t('index.inventoryAlerts') }}</h2>
        <p v-if="threshold !== null" class="ia-sub">
          {{ $t('index.thresholdNote', { count: threshold }) }}
        </p>
      </div>

      <button
        type="button"
        class="ia-refresh"
        :disabled="loading"
        :title="$t('index.refresh')"
        :aria-label="$t('index.refresh')"
        @click="fetchAlerts"
      >
        <i class="fa fa-rotate" :class="{ 'fa-spin': loading }" aria-hidden="true"></i>
      </button>
    </div>

    <!--
      Counts come from the API over the whole catalogue, so each tab shows the
      real size of its category rather than the length of the current page.
    -->
    <div class="ia-tabs" role="tablist">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        role="tab"
        class="ia-tab"
        :class="[{ 'is-active': filter === t.key }, `is-${t.key}`]"
        :aria-selected="filter === t.key ? 'true' : 'false'"
        @click="select(t.key)"
      >
        {{ t.label }}
        <span class="ia-tab__count">{{ t.count }}</span>
      </button>
    </div>

    <div v-if="loading" class="ia-list">
      <div v-for="n in 8" :key="`sk-${n}`" class="ia-row">
        <skeleton-block width="34px" height="34px" radius="8px" />
        <div class="skeleton-stack">
          <skeleton-block width="46%" height="12px" />
          <skeleton-block width="24%" height="10px" />
        </div>
      </div>
    </div>

    <div v-else-if="failed" class="ia-state is-error">
      <i class="fa fa-triangle-exclamation" aria-hidden="true"></i>
      <span>{{ $t('index.activityFailed') }}</span>
      <button type="button" class="ia-retry" @click="fetchAlerts">{{ $t('index.retry') }}</button>
    </div>

    <div v-else-if="!alerts.length" class="ia-state">
      <i class="fa fa-boxes-stacked" aria-hidden="true"></i>
      <span>{{ filter === 'all' ? $t('index.allStocked') : $t('index.noAlertsFilter') }}</span>
    </div>

    <div v-else class="ia-list">
      <nuxt-link
        v-for="p in alerts"
        :key="p.id"
        :to="`/products/${p.id}`"
        class="ia-row"
        :class="`is-${p.severity}`"
      >
        <span class="ia-icon"><i class="fa" :class="p.icon" aria-hidden="true"></i></span>

        <div class="ia-body">
          <span class="ia-state-label">{{ p.label }}</span>
          <span class="ia-name" :title="p.title">{{ p.title }}</span>
          <span class="ia-detail">
            {{ p.detail }}
            <template v-if="p.variantNote"> · {{ p.variantNote }}</template>
          </span>
        </div>

        <i class="fa fa-chevron-right ia-go" aria-hidden="true"></i>
      </nuxt-link>
    </div>
  </div>
</template>

<script>
  import { mapActions } from 'vuex'
  import SkeletonBlock from '~/components/SkeletonBlock'
import inventoryAlert from '~/mixin/inventory-alert'

  /**
   * The full inventory-alert list behind the dashboard panel's "View all".
   *
   * Classification is the API's - this page only renders it, so the panel and
   * the page can never disagree about what counts as low, out or oversold.
   */
  export default {
    name: 'InventoryAlerts',
    middleware: ['common-middleware', 'auth'],
    mixins: [inventoryAlert],
    components: {
      SkeletonBlock
    },
    data() {
      return {
        loading: true,
        failed: false,
        items: [],
        counts: { negative: 0, out: 0, low: 0, all: 0 },
        threshold: null,
        filter: 'all'
      }
    },
    computed: {
      tabs() {
        return [
          { key: 'all', label: this.$t('index.filterAll'), count: this.counts.all || 0 },
          { key: 'negative', label: this.$t('index.inventoryIssue'), count: this.counts.negative || 0 },
          { key: 'out', label: this.$t('index.outOfStock'), count: this.counts.out || 0 },
          { key: 'low', label: this.$t('index.lowStockState'), count: this.counts.low || 0 }
        ]
      },
      alerts() {
        return (this.items || []).map(p => this.inventoryAlertView(p))
      }
    },
    methods: {
      select(key) {
        if (this.filter === key) return
        this.filter = key
        // Keep the chosen tab in the URL so the page can be linked and reloaded
        this.$router.replace({ query: key === 'all' ? {} : { filter: key } }).catch(() => {})
        this.fetchAlerts()
      },
      async fetchAlerts() {
        this.loading = true
        try {
          const data = await this.getRequest({
            api: 'lowStockProducts',
            params: { filter: this.filter, limit: 200 }
          })
          this.items = data?.items || []
          this.counts = data?.counts || { negative: 0, out: 0, low: 0, all: 0 }
          this.threshold = data?.threshold ?? null
          this.failed = false
        } catch (e) {
          this.failed = !this.items.length
        } finally {
          this.loading = false
        }
      },
      ...mapActions('common', ['getRequest'])
    },
    mounted() {
      const q = this.$route?.query?.filter
      if (['low', 'out', 'negative'].indexOf(q) !== -1) this.filter = q
      this.fetchAlerts()
    }
  }
</script>

<style lang="stylus" scoped>
  .inventory-alerts
    padding 4px 0

  .ia-head
    display flex
    align-items flex-start
    gap 12px
    margin-bottom 16px

  .ia-title
    margin 0
    font-size 20px
    font-weight 700
    color var(--text-strong)

  .ia-sub
    margin 4px 0 0
    font-size 12.5px
    color var(--text-muted)

  .ia-refresh
    margin-left auto
    width 32px
    height 32px
    display inline-flex
    align-items center
    justify-content center
    border 1px solid var(--border-color)
    border-radius 8px
    background var(--surface-color)
    color var(--text-muted)
    font-size 12px
    cursor pointer

    &:hover:not(:disabled)
      border-color var(--primary-color)
      color var(--primary-color)

    &:disabled
      opacity .5
      cursor not-allowed

  .ia-tabs
    display flex
    flex-wrap wrap
    gap 8px
    margin-bottom 14px

  .ia-tab
    display inline-flex
    align-items center
    gap 7px
    height 34px
    padding 0 12px
    border 1px solid var(--border-color)
    border-radius 999px
    background var(--surface-color)
    color var(--text-color)
    font-size 12.5px
    font-weight 600
    font-family inherit
    cursor pointer
    transition all .12s ease

    &:hover
      border-color var(--border-strong)

    &.is-active
      border-color var(--primary-color)
      background var(--primary-soft)
      color var(--primary-color)

  .ia-tab__count
    min-width 20px
    padding 1px 6px
    border-radius 999px
    background var(--hover-color)
    color var(--text-muted)
    font-size 11px
    font-weight 700
    text-align center

  .ia-tab.is-active .ia-tab__count
    background var(--primary-color)
    color var(--primary-foreground)

  .ia-list
    display flex
    flex-direction column
    gap 6px

  .ia-row
    display flex
    align-items flex-start
    gap 12px
    padding 12px 14px
    border 1px solid var(--border-color)
    border-radius 10px
    background var(--surface-color)
    color inherit
    transition border-color .12s ease, background .12s ease

    &:hover
      border-color var(--primary-color)
      background var(--hover-color)

  .ia-icon
    flex none
    width 34px
    height 34px
    border-radius 9px
    display flex
    align-items center
    justify-content center
    font-size 14px
    background var(--warning-bg)
    color var(--warning-text)

  .ia-row.is-out .ia-icon,
  .ia-row.is-negative .ia-icon
    background var(--danger-bg)
    color var(--danger-text)

  .ia-body
    flex 1
    min-width 0

  .ia-state-label
    display block
    font-size 10px
    font-weight 700
    letter-spacing .04em
    text-transform uppercase
    color var(--warning-text)

  .ia-row.is-out .ia-state-label,
  .ia-row.is-negative .ia-state-label
    color var(--danger-text)

  .ia-name
    display block
    font-size 13.5px
    font-weight 600
    color var(--text-strong)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .ia-detail
    display block
    font-size 12px
    color var(--text-muted)

  .ia-go
    flex none
    align-self center
    font-size 11px
    color var(--text-soft)

  .ia-state
    display flex
    flex-direction column
    align-items center
    justify-content center
    gap 8px
    padding 48px 16px
    border 1px solid var(--border-color)
    border-radius 10px
    background var(--surface-color)
    color var(--text-muted)
    font-size 13px

    i
      font-size 24px
      color var(--text-soft)

    &.is-error i
      color var(--warning-text)

  .ia-retry
    padding 6px 14px
    border 1px solid var(--border-color)
    border-radius 7px
    background var(--surface-color)
    color var(--text-color)
    font-size 12.5px
    font-weight 600
    font-family inherit
    cursor pointer

    &:hover
      border-color var(--primary-color)
      color var(--primary-color)

  @media only screen and (max-width: 560px)
    .ia-row
      padding 10px 12px

    .ia-name
      font-size 13px
</style>
