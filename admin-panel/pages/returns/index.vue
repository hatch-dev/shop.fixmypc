<template>
  <div class="returns-page">

    <div v-if="stats" class="rt-stats">
      <div class="rt-stat" :class="{alert: stats.requested > 0}">
        <span>{{ $t('pages.returns.toReview') }}</span><strong>{{ stats.requested }}</strong><small>{{ $t('pages.returns.askedByCustomers') }}</small>
      </div>
      <div class="rt-stat" :class="{alert: stats.awaiting_return > 0}">
        <span>{{ $t('pages.returns.statusAwaitingReturn') }}</span><strong>{{ stats.awaiting_return }}</strong><small>{{ $t('pages.returns.parcelNotArrived') }}</small>
      </div>
      <div class="rt-stat" :class="{alert: stats.received > 0}">
        <span>{{ $t('pages.returns.toRefund') }}</span><strong>{{ stats.received }}</strong><small>{{ $t('pages.returns.receivedNotRefunded') }}</small>
      </div>
      <div class="rt-stat" :class="{bad: stats.refunding > 0}">
        <span>{{ $t('pages.returns.stuckRefunding') }}</span><strong>{{ stats.refunding }}</strong><small>{{ $t('pages.returns.checkBeforeRefunding') }}</small>
      </div>
      <div class="rt-stat">
        <span>{{ $t('pages.returns.refunded') }}</span><strong>{{ currencyIcon }}{{ money(stats.refunded_total) }}</strong><small>{{ $t('pages.returns.byReturnsAllTime') }}</small>
      </div>
    </div>

    <list-page
      ref="listPage"
      list-api="getOrderReturns"
      route-name="returns"
      :name="$t('pages.returns.returns')"
      gate="order"
      :add-button="false"
      :order-options="orderOptions"
    >
      <template v-slot:extra-filters>
        <div class="rt-filters">
          <select v-model="status" :aria-label="$t('pages.returns.status')" @change="applyFilters">
            <option value="">{{ $t('pages.returns.allReturns') }}</option>
            <option value="open">{{ $t('pages.returns.filterOpen') }}</option>
            <option value="requested">{{ $t('pages.returns.toReview') }}</option>
            <option value="awaiting_payment">{{ $t('pages.returns.statusAwaitingPayment') }}</option>
            <option value="awaiting_return">{{ $t('pages.returns.statusAwaitingReturn') }}</option>
            <option value="received">{{ $t('pages.returns.statusReceived') }}</option>
            <option value="refunding">{{ $t('pages.returns.statusRefunding') }}</option>
            <option value="refunded">{{ $t('pages.returns.statusRefunded') }}</option>
            <option value="closed">{{ $t('pages.returns.statusClosed') }}</option>
            <option value="rejected">{{ $t('pages.returns.statusRejected') }}</option>
            <option value="cancelled">{{ $t('pages.returns.cancelledByCustomer') }}</option>
            <option value="exchanged">{{ $t('pages.returns.statusExchanged') }}</option>
          </select>
        </div>
      </template>

      <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('pages.returns.returnHeading') }}</th>
          <th>{{ $t('pages.returns.type') }}</th>
          <th>{{ $t('pages.returns.order') }}</th>
          <th>{{ $t('pages.returns.customer') }}</th>
          <th>{{ $t('pages.returns.reason') }}</th>
          <th>{{ $t('pages.returns.units') }}</th>
          <th>{{ $t('pages.returns.status') }}</th>
          <th>{{ $t('pages.returns.refund') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="value.id">
          <td class="w-50x mx-w-50x">{{ from + index }}</td>
          <td>
            <nuxt-link class="link" :to="`/returns/${value.id}`"><h5>{{ value.rma_number }}</h5></nuxt-link>
            <span class="sub-note">{{ formatDate(value.created_at) }}</span>
          </td>
          <td>{{ value.type === 'exchange' ? $t('pages.returns.exchange') : $t('pages.returns.returnHeading') }}</td>
          <td><nuxt-link class="link" :to="`/orders/${value.order_id}`">#{{ value.order_number }}</nuxt-link></td>
          <td>
            {{ value.customer || $t('pages.returns.guest') }}
            <span class="sub-note">{{ value.customer_email }}</span>
          </td>
          <td>{{ value.reason_label }}</td>
          <td>{{ value.units }}</td>
          <td><span class="pill" :class="pillClass(value.status)">{{ value.status_label }}</span></td>
          <td>
            <template v-if="value.refund_amount !== null">{{ currencyIcon }}{{ money(value.refund_amount) }}</template>
            <span v-else class="sub-note">—</span>
          </td>
          <td><button class="lite-btn" @click.prevent="$router.push(`/returns/${value.id}`)">{{ $t('pages.returns.open') }}</button></td>
        </tr>
      </template>
    </list-page>

    <p class="rt-hint">{{ $t('pages.returns.startHintBefore') }} <strong>{{ $t('pages.returns.startReturn') }}</strong>.</p>
  </div>
</template>

<script>
import ListPage from '~/components/partials/ListPage'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

export default {
  name: 'returns',
  middleware: ['common-middleware', 'auth'],
  components: { ListPage },
  mixins: [util],

  data() {
    return {
      stats: null,
      status: this.$route.query.status || '',
      orderOptions: {
        created_at: { title: this.$t('pages.returns.date') }
      }
    }
  },

  computed: {
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    ...mapGetters('setting', ['setting'])
  },

  methods: {
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    formatDate(value) {
      const d = new Date(String(value).replace(' ', 'T'))
      return isNaN(d.getTime()) ? value : d.toLocaleDateString(this.$i18n?.locale || 'en', { day: 'numeric', month: 'short', year: 'numeric' })
    },
    pillClass(status) {
      return {
        awaiting_return: 'pill-wait',
        received: 'pill-wait',
        refunding: 'pill-bad',
        refunded: 'pill-on',
        exchanged: 'pill-on',
        requested: 'pill-wait',
        awaiting_payment: 'pill-wait',
        cancelled: 'pill-off',
        closed: 'pill-off',
        rejected: 'pill-off'
      }[status] || 'pill-off'
    },
    applyFilters() {
      const query = { ...this.$route.query }
      if (this.status) query.status = this.status; else delete query.status
      delete query.page
      this.$router.push({ query }).catch(() => {}).then(() => this.$refs.listPage?.fetchingData())
    },
    async loadStats() {
      try {
        this.stats = await this.getRequest({ api: 'getOrderReturnStats', params: {} })
      } catch (e) {
        this.stats = null
      }
    },
    ...mapActions('common', ['getRequest'])
  },

  mounted() {
    this.loadStats()
  }
}
</script>

<style scoped>
.rt-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; padding: 20px 20px 0; }
.rt-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; gap: 2px; }
.rt-stat span { font-size: 12px; color: #6b7280; }
.rt-stat strong { font-size: 22px; font-weight: 700; color: #111827; }
.rt-stat small { font-size: 11px; color: #9ca3af; }
.rt-stat.alert strong { color: #b54708; }
.rt-stat.bad strong { color: #b42318; }
.rt-filters select { height: 40px; padding: 0 10px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; font-size: 14px; }
.rt-hint { margin: 0 20px 20px; font-size: 13px; color: #6b7280; }
.sub-note { display: block; font-size: 11px; color: #6b7280; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.pill-on { background: #ecfdf3; color: #027a48; }
.pill-wait { background: #fffaeb; color: #b54708; }
.pill-off { background: #f2f4f7; color: #475467; }
.pill-bad { background: #fef3f2; color: #b42318; }
</style>
