<template>
  <div class="live-activity mtb-20">
    <div class="live-activity__head">
      <h4 class="live-activity__title">{{ $t('index.liveActivity') }}</h4>

      <!--
        No connection badge: this panel has no live channel behind it, so a
        "Live" light would be decoration claiming something untrue. What a
        reader of a manually refreshed list actually needs is how old it is.
      -->
      <span v-if="lastUpdated" class="live-activity__stamp">
        {{ $t('index.updatedAgo', { time: lastUpdatedLabel }) }}
      </span>

      <button
        class="live-activity__refresh"
        type="button"
        :disabled="loading"
        @click="refresh"
        :title="$t('index.refresh')"
        :aria-label="$t('index.refresh')"
      >
        <i class="fa fa-rotate" :class="{ 'fa-spin': loading }"></i>
      </button>
    </div>

    <div v-if="loading && !items.length" class="live-activity__list">
      <div v-for="n in 6" :key="`sk-${n}`" class="activity-row">
        <skeleton-block width="30px" height="30px" radius="8px" />
        <div class="skeleton-stack">
          <skeleton-block :width="skeletonWidths[n - 1]" height="11px" />
          <skeleton-block width="55%" height="10px" />
        </div>
        <skeleton-block width="38px" height="10px" />
      </div>
    </div>

    <!-- A failure states itself and offers a way out, rather than looking empty -->
    <div v-else-if="failed && !items.length" class="live-activity__state is-error">
      <i class="fa fa-triangle-exclamation" aria-hidden="true"></i>
      <span>{{ $t('index.activityFailed') }}</span>
      <button type="button" class="live-activity__retry" @click="refresh">
        {{ $t('index.retry') }}
      </button>
    </div>

    <div v-else-if="!items.length" class="live-activity__state">
      <i class="fa fa-wave-square" aria-hidden="true"></i>
      <span>{{ $t('index.noActivity') }}</span>
    </div>

    <div v-else ref="list" class="live-activity__list">
      <component
        :is="a.link ? 'nuxt-link' : 'div'"
        v-for="a in items"
        :key="a.id"
        :to="a.link || null"
        class="activity-row"
        :class="{ 'is-clickable': a.link }"
      >
        <span
          class="activity-row__icon"
          :style="{ background: a.color + '1f', color: a.color }"
        ><i class="fa" :class="a.icon" aria-hidden="true"></i></span>

        <div class="activity-row__body">
          <div class="activity-row__title">{{ a.title }}</div>
          <div v-if="a.meta" class="activity-row__meta">{{ a.meta }}</div>
          <div v-if="a.detail" class="activity-row__detail" :title="a.detail">{{ a.detail }}</div>
        </div>

        <time class="activity-row__time" :datetime="a.at" :title="a.exact">{{ a.ago }}</time>
      </component>
    </div>

    <nuxt-link to="/orders" class="live-activity__all">
      {{ $t('index.viewAllActivity') }} <i class="fa fa-arrow-right" aria-hidden="true"></i>
    </nuxt-link>
  </div>
</template>

<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
  import SkeletonBlock from '~/components/SkeletonBlock'

  /**
   * How many rows the dashboard panel shows, and how much history is held
   * behind them for de-duplication. The panel is a glance, not a log - the
   * rest is a click away on "View all activity".
   */
  const VISIBLE = 5
  const KEEP = 120

  /**
   * The dashboard's recent-activity panel.
   *
   * It loads once and then only when the reader asks - there is no polling and
   * no socket, because the application has no broadcast layer to listen to
   * (BROADCAST_DRIVER=log, no events, no Echo client). Everything shown is a
   * row that already exists in the database; nothing is synthesised.
   */
  export default {
    name: 'LiveActivity',
    mixins: [util],
    components: {
      SkeletonBlock
    },
    data() {
      return {
        loading: true,
        failed: false,
        /**
         * id -> event. The Map is the de-duplication: the backend's stable id
         * is the key, so refreshing repeatedly can never show the same event
         * twice, however much the responses overlap.
         */
        byId: new Map(),
        /** server clock minus browser clock, in ms - see relativeTime(). */
        skew: 0,
        lastUpdated: null,
        now: Date.now(),
        skeletonWidths: ['70%', '58%', '78%', '64%', '52%', '72%']
      }
    },
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      lastUpdatedLabel() {
        return this.relativeTime(this.lastUpdated, { lower: true })
      },
      /**
       * One presentation rule per order status. The feed covers the whole
       * lifecycle - placed, confirmed, packed, shipped, delivered, cancelled,
       * returned, refunded - because every one of those writes a history row.
       */
      statusPresentation() {
        const s = this.orderStatusIn
        return {
          [s.PENDING]: { title: this.$t('index.orderPlaced'), icon: 'fa-cart-shopping', color: '#D97706' },
          [s.CONFIRMED]: { title: this.$t('index.orderConfirmed'), icon: 'fa-circle-check', color: '#2563EB' },
          [s.PICKED_UP]: { title: this.$t('index.orderPacked'), icon: 'fa-box', color: '#7C3AED' },
          [s.ON_THE_WAY]: { title: this.$t('index.orderShipped'), icon: 'fa-truck-fast', color: '#0891B2' },
          [s.DELIVERED]: { title: this.$t('index.orderDelivered'), icon: 'fa-circle-check', color: '#059669' },
          [s.CANCELLED]: { title: this.$t('index.orderCancelledAct'), icon: 'fa-circle-xmark', color: '#DC2626' },
          [s.RETURNED]: { title: this.$t('index.orderReturned'), icon: 'fa-rotate-left', color: '#B45309' },
          [s.REFUNDED]: { title: this.$t('index.refunded'), icon: 'fa-arrow-rotate-left', color: '#0F766E' }
        }
      },
      /**
       * Always sorted on the event's own timestamp, never on the order the
       * rows happened to arrive in: the endpoint merges several tables, and a
       * status change can be written before an order that is only now read.
       */
      sorted() {
        return Array.from(this.byId.values())
          .sort((a, b) => String(b.at || '').localeCompare(String(a.at || '')))
      },
      items() {
        return this.sorted.slice(0, VISIBLE).map(e => this.present(e))
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      // ---------------------------------------------------------- presentation
      present(e) {
        const base = {
          id: e.id,
          at: e.at,
          ago: this.relativeTime(e.at),
          exact: this.exactTime(e.at)
        }

        if (e.type === 'review') {
          const rating = Math.max(0, Math.min(5, Number(e.rating) || 0))
          return {
            ...base,
            title: this.$t('index.reviewSubmitted'),
            meta: '★'.repeat(rating) + '☆'.repeat(5 - rating),
            detail: e.product || '',
            icon: 'fa-star',
            color: '#7C3AED',
            // The reviews screen is a list with no per-review route
            link: '/rating-reviews'
          }
        }

        if (e.type === 'customer_registered') {
          return {
            ...base,
            title: this.$t('index.customerRegistered'),
            meta: e.customer,
            detail: e.account_type || '',
            icon: 'fa-user-plus',
            color: '#2563EB',
            link: '/registered-users'
          }
        }

        if (e.type === 'trade_reviewed') {
          const map = {
            approved: { title: this.$t('index.tradeApproved'), icon: 'fa-circle-check', color: '#059669' },
            rejected: { title: this.$t('index.tradeRejected'), icon: 'fa-circle-xmark', color: '#DC2626' },
            suspended: { title: this.$t('index.tradeSuspended'), icon: 'fa-ban', color: '#B45309' },
            pending: { title: this.$t('index.tradePending'), icon: 'fa-hourglass-half', color: '#D97706' }
          }
          const p = map[e.trade_status] || map.pending
          return {
            ...base,
            title: p.title,
            meta: e.customer,
            detail: e.note || '',
            icon: p.icon,
            color: p.color,
            link: '/trade-applications'
          }
        }

        if (e.type === 'product_changed') {
          return {
            ...base,
            title: e.created ? this.$t('index.productCreated') : this.$t('index.productUpdated'),
            meta: e.product || '',
            detail: '',
            icon: e.created ? 'fa-plus' : 'fa-pen',
            color: '#6366F1',
            // Only link where the product is still identifiable
            link: e.product_id ? `/products/${e.product_id}` : null
          }
        }

        if (e.type === 'inventory_changed') {
          return {
            ...base,
            title: this.$t('index.stockUpdated'),
            meta: e.product || e.sku || '',
            detail: this.$t('index.inStock', { count: e.quantity }),
            icon: 'fa-boxes-stacked',
            color: e.quantity > 0 ? '#0891B2' : '#DC2626',
            link: e.product_id ? `/products/${e.product_id}` : null
          }
        }

        if (e.type === 'order_cancelled') {
          return {
            ...base,
            title: this.$t('index.orderCancelledAct'),
            meta: this.orderMeta(e),
            detail: [e.refunded ? this.$t('index.refunded') : '', e.note].filter(Boolean).join(' · '),
            icon: 'fa-circle-xmark',
            color: '#DC2626',
            link: this.orderLink(e)
          }
        }

        if (e.type === 'order_status') {
          const p = this.statusPresentation[e.status] || {
            title: this.$t('index.orderUpdated'), icon: 'fa-arrows-rotate', color: '#6B7280'
          }
          return {
            ...base,
            title: p.title,
            meta: this.orderMeta(e),
            detail: [e.customer, e.actor ? `${this.$t('index.by')} ${e.actor}` : '', e.note]
              .filter(Boolean).join(' · '),
            icon: p.icon,
            color: p.color,
            link: this.orderLink(e)
          }
        }

        // order_placed
        return {
          ...base,
          title: this.$t('index.orderPlaced'),
          meta: this.orderMeta(e),
          detail: e.customer || '',
          icon: 'fa-cart-shopping',
          color: e.paid ? '#059669' : '#D97706',
          link: this.orderLink(e)
        }
      },
      /** "#20261109Qb9h… · €699" - the identifiers, then the money. */
      orderMeta(e) {
        const no = e.order_no ? `#${e.order_no}` : ''
        const amount = (e.amount !== undefined && e.amount !== null)
          ? this.priceFormatting(e.amount)
          : ''
        return [no, amount].filter(Boolean).join(' · ')
      },
      /**
       * Only link where there is something to open. A row whose order has
       * since been deleted keeps its place in the history but stops being a
       * link, rather than navigating to a page that will 404.
       */
      orderLink(e) {
        return e.order_id ? `/orders/${e.order_id}` : null
      },

      // ------------------------------------------------------------- time
      /**
       * Relative to the SERVER's clock, not the browser's. Events carry a UTC
       * instant; a browser running a few minutes fast would otherwise render a
       * just-written event as "in 3 minutes". `skew` is measured on each load.
       */
      relativeTime(iso, { lower = false } = {}) {
        if (!iso) return ''
        const then = Date.parse(iso)
        if (isNaN(then)) return ''

        const now = this.now + this.skew
        const secs = Math.round((now - then) / 1000)

        if (secs < 45) {
          const s = this.$t('index.justNow')
          return lower ? s.toLowerCase() : s
        }
        if (secs < 3600) return this.$t('index.minAgo', { count: Math.max(1, Math.round(secs / 60)) })

        const tz = this.timeZone
        const locale = this.$i18n?.locale || 'en'
        const time = new Date(then).toLocaleTimeString(locale, {
          hour: '2-digit', minute: '2-digit', timeZone: tz
        })

        // "Today" is today in the viewer's timezone - not in UTC, and not in
        // whatever zone the browser happens to sit in.
        const dayOf = ms => new Date(ms).toLocaleDateString('en-CA', { timeZone: tz })
        if (dayOf(then) === dayOf(now)) {
          return `${this.$t('index.todayAt')}, ${time}`
        }

        /*
         * "11 Sep", not "Sep 11". Assembled from the parts rather than taken
         * from toLocaleDateString, whose order follows the locale - an en-US
         * browser would otherwise render a different shape from an en-GB one
         * in the same shop, for the same event.
         */
        const parts = new Intl.DateTimeFormat(locale, {
          day: 'numeric', month: 'short', timeZone: tz
        }).formatToParts(new Date(then))
        const pick = t => (parts.find(p => p.type === t) || {}).value || ''
        return `${pick('day')} ${pick('month')}, ${time}`
      },
      /** The hover tooltip: the same day-first shape as the visible label. */
      exactTime(iso) {
        if (!iso) return ''
        const t = Date.parse(iso)
        if (isNaN(t)) return ''

        const locale = this.$i18n?.locale || 'en'
        const d = new Date(t)
        const parts = new Intl.DateTimeFormat(locale, {
          day: 'numeric', month: 'short', year: 'numeric', timeZone: this.timeZone
        }).formatToParts(d)
        const pick = k => (parts.find(p => p.type === k) || {}).value || ''
        const time = d.toLocaleTimeString(locale, {
          hour: '2-digit', minute: '2-digit', timeZone: this.timeZone
        })
        return `${pick('day')} ${pick('month')} ${pick('year')}, ${time}`
      },

      // ------------------------------------------------------------ fetching
      async load({ showSpinner = false } = {}) {
        if (this.inFlight) return
        this.inFlight = true
        if (showSpinner) this.loading = true

        try {
          const payload = await this.getRequest({
            api: 'activity',
            params: { limit: 50, time_zone: this.timeZone }
          })

          // Tolerate both the object form and the older bare array.
          const body = Array.isArray(payload) ? { events: payload } : (payload || {})
          const events = Array.isArray(body.events) ? body.events : []

          if (body.server_time) {
            const server = Date.parse(body.server_time)
            if (!isNaN(server)) this.skew = server - Date.now()
          }

          this.merge(events)
          this.now = Date.now()
          this.lastUpdated = body.server_time || new Date().toISOString()
          this.failed = false
        } catch (e) {
          // Keep whatever is already on screen: a failed refresh must not
          // blank a list the reader is looking at.
          this.failed = !this.byId.size
        } finally {
          this.loading = false
          this.inFlight = false
        }
      },
      /** Merge by stable id, so a refresh never repeats what is already held. */
      merge(events) {
        if (!events.length) return

        const next = new Map(this.byId)
        events.forEach(e => {
          if (e && e.id) next.set(e.id, e)
        })

        // Bound the map so a long-lived tab cannot grow without limit.
        if (next.size > KEEP) {
          const keep = Array.from(next.values())
            .sort((a, b) => String(b.at || '').localeCompare(String(a.at || '')))
            .slice(0, KEEP)
          this.byId = new Map(keep.map(e => [e.id, e]))
          return
        }

        // Map mutation is not reactive in Vue 2; replace the reference.
        this.byId = next
      },
      refresh() {
        return this.load({ showSpinner: true })
      },
      ...mapActions('common', ['getRequest'])
    },
    mounted() {
      this.inFlight = false
      this.load({ showSpinner: true })

      /*
       * Local only - it re-renders the "2 min ago" labels so they stay true,
       * and never touches the network.
       */
      this.ticker = setInterval(() => { this.now = Date.now() }, 60000)
    },
    beforeDestroy() {
      if (this.ticker) clearInterval(this.ticker)
    }
  }
</script>

<style lang="stylus" scoped>
  .live-activity
    display flex
    flex-direction column
    min-height 0
    max-height 100%
    overflow hidden
    background var(--surface-color)
    border 1px solid var(--border-color)
    border-radius 12px
    padding 16px
    box-shadow 0 1px 3px var(--shadow-color)

  .live-activity__head
    display flex
    align-items center
    gap 8px
    margin-bottom 10px

  .live-activity__title
    margin 0
    font-size 15px
    font-weight 700
    color var(--text-strong)

  .live-activity__stamp
    font-size 10.5px
    font-weight 600
    color var(--text-soft)
    white-space nowrap

  .live-activity__refresh
    margin-left auto
    width 26px
    height 26px
    display inline-flex
    align-items center
    justify-content center
    border 1px solid var(--border-color)
    border-radius 7px
    background transparent
    color var(--text-muted)
    font-size 11px
    cursor pointer
    transition all .12s ease

    &:hover:not(:disabled)
      border-color var(--primary-color)
      color var(--primary-color)

    &:disabled
      opacity .5
      cursor not-allowed

  .live-activity__state
    flex 1
    display flex
    flex-direction column
    align-items center
    justify-content center
    gap 7px
    padding 18px 0
    color var(--text-muted)
    font-size 12.5px

    i
      font-size 20px
      color var(--text-soft)

    &.is-error i
      color var(--warning-text)

  .live-activity__retry
    padding 4px 12px
    border 1px solid var(--border-color)
    border-radius 6px
    background var(--surface-color)
    color var(--text-color)
    font-size 11.5px
    font-weight 600
    font-family inherit
    cursor pointer

    &:hover
      border-color var(--primary-color)
      color var(--primary-color)

  .live-activity__list
    flex 1 1 auto
    min-height 0
    overflow-y auto
    display flex
    flex-direction column
    gap 1px

  .live-activity__all
    margin-top 10px
    padding-top 10px
    border-top 1px solid var(--border-color)
    display inline-flex
    align-items center
    justify-content center
    gap 6px
    font-size 12px
    font-weight 600
    color var(--primary-color)

    i
      font-size 9px

    &:hover
      text-decoration underline

  .activity-row
    display flex
    align-items flex-start
    gap 10px
    padding 8px 6px
    border-radius 8px
    color inherit
    transition background .12s ease

    &.is-clickable
      cursor pointer

    &:hover
      background var(--hover-color)

  .activity-row__icon
    flex-shrink 0
    width 30px
    height 30px
    border-radius 8px
    display flex
    align-items center
    justify-content center
    font-size 13px

  .activity-row__body
    flex 1
    min-width 0

  .activity-row__title
    font-size 12.5px
    font-weight 700
    color var(--text-strong)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .activity-row__meta
    font-size 11.5px
    font-weight 600
    color var(--text-color)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .activity-row__detail
    font-size 11px
    color var(--text-muted)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .activity-row__time
    flex-shrink 0
    align-self flex-start
    padding-top 1px
    font-size 10.5px
    font-weight 600
    color var(--text-soft)
    white-space nowrap
    text-align right

  @media only screen and (max-width: 560px)
    .live-activity
      padding 14px

    .activity-row
      gap 8px

    .activity-row__time
      font-size 10px
</style>
