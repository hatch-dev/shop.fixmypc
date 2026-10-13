<template>
  <div class="recent-orders card mtb-20">
    <div class="recent-orders__head">
      <h4 class="recent-orders__title">{{ $t('index.recentOrders') }}</h4>
      <nuxt-link to="/orders" class="recent-orders__link">
        {{ $t('index.viewAllOrders') }}
        <i class="fa fa-arrow-right-long"></i>
      </nuxt-link>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="recent-orders__list">
      <div v-for="n in 5" :key="`ro-sk-${n}`" class="ro-card is-skeleton">
        <skeleton-block width="38px" height="38px" radius="50%" />
        <div class="skeleton-stack">
          <skeleton-block :width="skeletonWidths[n - 1]" height="13px" />
          <skeleton-block width="45%" height="11px" />
        </div>
        <skeleton-block width="64px" height="13px" />
        <skeleton-block width="70px" height="22px" radius="999px" />
      </div>
    </div>

    <div v-else-if="!orders.length" class="recent-orders__state">
      {{ $t('list.noData', { data: $t('fSale.orderD') }) }}
    </div>

    <!--
      Was a plain five-column table that overflowed into a horizontal scrollbar
      on anything narrower than a desktop. A row per order carries the same
      fields in a layout that reflows instead.
    -->
    <div v-else class="recent-orders__list">
      <div
        v-for="o in orders"
        :key="o.id"
        class="ro-card"
        role="link"
        tabindex="0"
        :title="$t('index.viewOrder')"
        @click="open(o)"
        @keydown.enter="open(o)"
        @keydown.space.prevent="open(o)"
      >
        <span class="ro-avatar" :style="avatarTone(o)">{{ initials(customerName(o)) }}</span>

        <div class="ro-main">
          <div class="ro-line">
            <span class="ro-customer">{{ customerName(o) }}</span>
            <span class="ro-order">#{{ o.order }}</span>
          </div>
          <div class="ro-line ro-line--sub">
            <span v-if="customerEmail(o) && customerEmail(o) !== customerName(o)" class="ro-email">
              {{ customerEmail(o) }}
            </span>
            <span class="ro-dot" v-if="itemCount(o)">·</span>
            <span v-if="itemCount(o)" class="ro-items">
              {{ itemCount(o) }} {{ itemCount(o) === 1 ? $t('index.item') : $t('index.items') }}
            </span>
            <span class="ro-dot">·</span>
            <span class="ro-date">{{ shortDate(o.created) }}</span>
          </div>
        </div>

        <div class="ro-money">
          <span class="ro-amount">{{ priceFormatting(o.total_amount || 0) }}</span>
          <span class="ro-pay" :class="isPaid(o) ? 'is-paid' : 'is-unpaid'">
            <i class="fa" :class="isPaid(o) ? 'fa-circle-check' : 'fa-clock'"></i>
            {{ isPaid(o) ? $t('index.paid') : $t('index.unpaid') }}
          </span>
        </div>

        <span class="ro-badge" :class="'ro-badge--' + statusColor(o)">
          {{ statusTitle(o) }}
        </span>

        <i class="fa fa-chevron-right ro-chevron"></i>
      </div>
    </div>
  </div>
</template>

<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
  import SkeletonBlock from '~/components/SkeletonBlock'

  export default {
    name: 'RecentOrders',
    mixins: [util],
    components: {
      SkeletonBlock
    },
    data() {
      return {
        loading: true,
        orders: [],
        skeletonWidths: ['58%', '70%', '46%', '64%', '52%']
      }
    },
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '€'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      open(o) {
        this.$router.push(`/orders/${o.id}`)
      },
      // An order placed without a saved address still carries the buyer on the
      // order itself (contact_name/contact_email) and on the linked account.
      // The old chain only read the address and a `user_name` key the orders API
      // never returns, so every guest/contact-checkout order rendered as "—".
      customerName(o) {
        return o.address?.name
          || o.contact_name
          || o.user?.name
          || o.guest_user?.name
          || this.customerEmail(o)
          || this.$t('index.noCustomer')
      },
      customerEmail(o) {
        return o.address?.email || o.contact_email || o.user?.email || o.guest_user?.email || ''
      },
      itemCount(o) {
        const lines = o.ordered_products || []
        return lines.reduce((n, l) => n + (Number(l.quantity) || 0), 0)
      },
      isPaid(o) {
        return parseInt(o.payment_done) === 1
      },
      initials(name) {
        return (name || '')
          .split(' ')
          .filter(Boolean)
          .slice(0, 2)
          .map(w => w[0])
          .join('')
          .toUpperCase() || '?'
      },
      // Stable colour per customer so the same buyer keeps one chip colour.
      avatarTone(o) {
        const palette = [
          { background: '#EEF2FF', color: '#3730A3' },
          { background: '#ECFDF5', color: '#047857' },
          { background: '#FEF3C7', color: '#92400E' },
          { background: '#FCE7F3', color: '#9D174D' },
          { background: '#E0F2FE', color: '#075985' },
          { background: '#F3E8FF', color: '#6B21A8' }
        ]
        const key = this.customerName(o) || ''
        let hash = 0
        for (let i = 0; i < key.length; i++) {
          hash = (hash * 31 + key.charCodeAt(i)) % 100000
        }
        return palette[hash % palette.length]
      },
      statusTitle(o) {
        if (parseInt(o.cancelled) === this.status.PUBLIC) {
          return this.$t('index.orderCan')
        }
        return this.orderStatus?.[o.status]?.title || '—'
      },
      statusColor(o) {
        if (parseInt(o.cancelled) === this.status.PUBLIC) {
          return 'red'
        }
        const map = { 1: 'orange', 2: 'blue', 3: 'violet', 4: 'cyan', 5: 'green' }
        return map[o.status] || 'gray'
      },
      shortDate(created) {
        // "02:31 pm, 23 Jun, 26" -> "23 Jun"
        const parts = (created || '').split(',').map(s => s.trim()).filter(Boolean)
        return parts[1] || parts[0] || ''
      },
      async fetchOrders() {
        this.loading = true
        try {
          const data = await this.getRequest({
            api: 'getOrders',
            params: { orderby: 'created_at', type: 'desc', time_zone: this.timeZone, limit: 5 }
          })
          this.orders = (data?.data || []).slice(0, 5)
        } catch (e) {
          // best-effort widget
        } finally {
          this.loading = false
        }
      },
      ...mapActions('common', ['getRequest'])
    },
    mounted() {
      this.fetchOrders()
    }
  }
</script>

<style lang="stylus" scoped>
  .recent-orders
    padding 20px
    background var(--surface-color)
    border 1px solid var(--border-color)
    border-radius 12px
    box-shadow 0 1px 3px var(--shadow-color)

  .recent-orders__head
    display flex
    align-items center
    justify-content space-between
    gap 12px
    margin-bottom 14px

  .recent-orders__title
    margin 0
    font-size 15px
    font-weight 700
    color var(--text-strong)

  .recent-orders__link
    display inline-flex
    align-items center
    gap 6px
    font-size 12px
    font-weight 600
    color var(--primary-color)
    padding 6px 12px
    border 1px solid var(--border-color)
    border-radius var(--radius-md)
    transition background .12s ease, border-color .12s ease

    i
      font-size 10px
      transition transform .16s ease

    &:hover
      background var(--hover-color)
      border-color var(--primary-color)

      i
        transform translateX(2px)

  .recent-orders__state
    padding 34px 0
    text-align center
    color var(--text-muted)
    font-size 13px

  .recent-orders__list
    display flex
    flex-direction column
    gap 6px

  .ro-card
    display flex
    align-items center
    gap 12px
    padding 11px 12px
    border 1px solid var(--border-color)
    border-radius var(--radius-lg)
    background var(--surface-color)
    transition border-color .14s ease, box-shadow .14s ease, transform .14s ease

    &:not(.is-skeleton)
      cursor pointer

      &:hover
        border-color var(--primary-color)
        box-shadow 0 2px 10px var(--shadow-color)

        .ro-chevron
          color var(--primary-color)
          transform translateX(2px)

      &:focus-visible
        outline 2px solid var(--primary-color)
        outline-offset 2px

  .ro-avatar
    flex none
    width 38px
    height 38px
    border-radius 50%
    display flex
    align-items center
    justify-content center
    font-size 12px
    font-weight 700
    letter-spacing .02em

  .ro-main
    flex 1 1 auto
    min-width 0
    display flex
    flex-direction column
    gap 3px

  .ro-line
    display flex
    align-items baseline
    gap 8px
    min-width 0

  .ro-line--sub
    gap 5px

  .ro-customer
    font-size 13.5px
    font-weight 600
    color var(--text-strong)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .ro-order
    flex none
    font-size 11px
    font-weight 600
    color var(--text-muted)
    font-family ui-monospace, SFMono-Regular, Menlo, monospace
    background var(--hover-color)
    border-radius 5px
    padding 1px 6px

  .ro-email
    font-size 11.5px
    color var(--text-soft)
    overflow hidden
    text-overflow ellipsis
    white-space nowrap

  .ro-dot
    flex none
    color var(--text-soft)
    font-size 11px

  .ro-items,
  .ro-date
    flex none
    font-size 11.5px
    color var(--text-soft)
    white-space nowrap

  .ro-money
    flex none
    display flex
    flex-direction column
    align-items flex-end
    gap 3px

  .ro-amount
    font-size 14px
    font-weight 700
    color var(--text-strong)
    white-space nowrap

  .ro-pay
    display inline-flex
    align-items center
    gap 4px
    font-size 10.5px
    font-weight 600
    white-space nowrap

    i
      font-size 9px

    &.is-paid
      color var(--success-text)

    &.is-unpaid
      color var(--warning-text)

  .ro-badge
    flex none
    display inline-flex
    align-items center
    padding 4px 11px
    border-radius 999px
    font-size 11px
    font-weight 600
    white-space nowrap

    &--orange
      background var(--warning-bg)
      color var(--warning-text)

    &--blue,
    &--cyan,
    &--violet
      background var(--info-bg)
      color var(--info-text)

    &--green
      background var(--success-bg)
      color var(--success-text)

    &--red
      background var(--danger-bg)
      color var(--danger-text)

    &--gray
      background var(--hover-color)
      color var(--text-muted)

  .ro-chevron
    flex none
    font-size 11px
    color var(--text-soft)
    transition transform .14s ease, color .14s ease

  @media only screen and (max-width: 768px)
    .recent-orders
      padding 16px

    .ro-card
      flex-wrap wrap
      row-gap 10px

    .ro-main
      flex 1 1 60%

    .ro-money
      margin-left auto

    .ro-badge
      order 5

    .ro-chevron
      display none

  @media only screen and (max-width: 480px)
    .ro-avatar
      width 32px
      height 32px

    .ro-order
      display none
</style>
