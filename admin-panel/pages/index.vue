<template>
  <div class="dashboard">
    <div class="dashboard-head mb-20 mb-xs-15">
      <div>
        <h2 class="dashboard-title">
          {{ $t('index.dashboard') }}
        </h2>
        <p class="dashboard-subtitle">
          {{ $t('index.welcome') }}
        </p>
      </div>
      <dropdown
        v-if="posSetting"
        class="right-dropdown"
        :selected-key="selectedOrderType"
        :options="orderTypes"
        @clicked="selectedOption"
      />
    </div>

    <div class="card-wrapper">
      <div
        v-for="card in cards"
        :key="card.key"
        v-show="card.visible"
        class="dashboard-card"
      >
        <div class="card-inner card">
          <div class="stat-top">
            <p class="stat-label">
              {{ card.label }}
            </p>
            <growth-badge v-if="!loadingDashboard" :value="card.growth" />
            <skeleton-block v-else width="38px" height="12px" />
          </div>
          <h3 v-if="!loadingDashboard" class="stat-value">{{ card.value }}</h3>
          <skeleton-block v-else width="86px" height="30px" radius="8px" class="mt-5" />

          <!-- What the headline figure deliberately excludes -->
          <p v-if="!loadingDashboard && card.note" class="stat-note">{{ card.note }}</p>
        </div>
      </div>
    </div>

    <div class="dashboard-main">
      <revenue-chart
        :buckets="revenueBuckets"
        :summary="revenueSummary"
        :applied-range="appliedRange"
        :bounds="dataBounds"
        :range="revenueRange"
        :loading="loadingChart"
        :compare="compareEnabled"
        @range-changed="revenueRangeChanged"
        @compare-changed="compareChanged"
      />
      <live-activity />
    </div>
    <order-statistic
      :order-type="selectedOrderType"
    />
    <dashboard-insights />
    <recent-orders />
  </div>
</template>

<script>
  import OrderStatistic from '~/components/partials/OrderStatistic'
  import RevenueChart from '~/components/partials/RevenueChart'
  import LiveActivity from '~/components/partials/LiveActivity'
  import RecentOrders from '~/components/partials/RecentOrders'
  import DashboardInsights from '~/components/partials/DashboardInsights'
  import GrowthBadge from '~/components/GrowthBadge'
  import SkeletonBlock from '~/components/SkeletonBlock'
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import Dropdown from "../components/Dropdown";


  export default {
    name: 'Index',
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        selectedOrderType: 'all',
        chartData: null,
        dashboard: null,
        chartMonth: null,
        /*
         * Sales Overview window. The dashboard opens on the last 30 days as a
         * preset rather than as dates computed here, because only the server
         * knows what "the last 30 days" resolves to in the viewer's timezone -
         * it then echoes the resolved dates back for the picker to display.
         * Every later change is an explicit custom range.
         */
        revenueRange: { preset: 'd30', start: null, end: null },
        // Off by default: the comparison costs two more aggregate queries and
        // most visits do not need it.
        compareEnabled: false,
        loadingDashboard: true,
        loadingChart: true
      }
    },
    components: {
      Dropdown,
      RevenueChart,
      LiveActivity,
      RecentOrders,
      DashboardInsights,
      GrowthBadge,
      SkeletonBlock,
      OrderStatistic
    },
    mixins: [util],
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      revenueBuckets() {
        return this.chartData?.revenue || this.chartData?.monthly_revenue || []
      },
      revenueSummary() {
        return this.chartData?.summary || {}
      },
      appliedRange() {
        return this.chartData?.range || null
      },
      dataBounds() {
        return this.chartData?.data_bounds || {}
      },
      usersCount() {
        return this.dashboard?.users || 0
      },
      productCount() {
        return this.dashboard?.products || 0
      },
      orderCount() {
        return this.dashboard?.orders || 0
      },
      /** Order value of the eligible orders - what was sold. */
      orderAmount() {
        return (parseFloat(this.dashboard?.orders_amount || 0)).toFixed(2)
      },
      /**
       * Money actually collected. This is what Total Sells reports: an
       * unpaid cash-on-delivery order is a real sale but not yet takings,
       * and adding it here overstated the shop by the whole order value.
       */
      orderReceived() {
        return (parseFloat(this.dashboard?.orders_received || 0)).toFixed(2)
      },
      orderPending() {
        return (parseFloat(this.dashboard?.orders_pending || 0)).toFixed(2)
      },
      growth() {
        return this.dashboard?.growth || {}
      },
      cards() {
        return [
          {
            key: 'products',
            label: this.$t('index.tProduct'),
            value: this.productCount,
            growth: this.growth.products,
            visible: true
          },
          {
            key: 'users',
            label: this.$t('index.tUsers'),
            value: this.usersCount,
            growth: this.growth.users,
            visible: this.$store.state.admin.isSuperAdmin
          },
          {
            key: 'orders',
            label: this.$t('index.tOrders'),
            value: this.orderCount,
            growth: this.growth.orders,
            visible: true
          },
          {
            key: 'sells',
            label: this.$t('index.tSells'),
            value: this.priceFormatting(this.orderReceived),
            // Only worth saying when something is actually outstanding.
            note: Number(this.orderPending) > 0
              ? this.$t('index.pendingLabel') + ' ' + this.priceFormatting(this.orderPending)
              : null,
            growth: this.growth.sells,
            visible: true
          }
        ]
      },
      ...mapGetters('admin', ['posSetting']),
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      selectedOption({key}){
        this.selectedOrderType = key
        this.dashboard = false
        this.loadingDashboard = true
        this.fetchingData()
      },
      revenueRangeChanged(payload) {
        this.revenueRange = payload
        this.fetchingData()
      },
      compareChanged(on) {
        this.compareEnabled = !!on
        this.fetchingData()
      },
      async fetchingData() {
        this.loadingChart = true

        try {
          // Do not blank chartData first: that unmounts the whole revenue graph
          // for the length of the request, so changing the range collapsed the
          // card and shunted everything below it up the page.
          const data = await this.getRequest({
            params: {
              ...this.chartMonth,
              ...{order_type: this.selectedOrderType},
              ...{time_zone: this.timeZone},
              ...{range_preset: this.revenueRange.preset},
              ...(this.revenueRange.preset === 'custom'
                ? {range_start: this.revenueRange.start, range_end: this.revenueRange.end}
                : {}),
              ...(this.compareEnabled ? {compare: 1} : {}),
              ...{dashboard: !!this.dashboard}
            },
            api: 'dashboard'
          })

          if(data?.dashboard) {
            this.dashboard = data.dashboard
          }

          this.chartData = data.chart_data

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.loadingDashboard = false
          this.loadingChart = false
        }
      },
      ...mapActions('dashboard', ['getDashboard']),
      ...mapActions('common', ['getRequest'])
    },
    mounted() {
      let date = new Date()
      this.chartMonth = {
        year: date.getFullYear(),
        month: date.getMonth() + 1
      }
      this.fetchingData()
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/dashboard.styl"

  .dashboard-main
    display grid
    grid-template-columns minmax(0, 1fr) 340px
    gap 16px
    align-items stretch
    margin-bottom 16px

  @media only screen and (max-width: 1200px)
    .dashboard-main
      grid-template-columns 1fr
</style>
