<template>
  <div class="dashboard">
    <div
      v-if="posSetting"
      class="mb-15 dply-felx sided"
    >
      <h4>
        {{ $t('error.orders') }}
      </h4>
      <dropdown
        class="right-dropdown"
        :selected-key="selectedOrderType"
        :options="orderTypes"
        @clicked="selectedOption"
      />
    </div>

    <div class="card-wrapper">
      <div class="dashboard-card">
        <div class="card-inner card">
          <i
            class="icon products"
          />
          <p class="f-1-2">
            {{ $t('index.tProduct') }}
          </p>
          <h3><b>{{ productCount }}</b></h3>
        </div>
      </div>
      <div v-if="$store.state.admin.isSuperAdmin" class="dashboard-card">
        <div class="card-inner card">
          <i
            class="icon users"
          />
          <p class="f-1-2">
            {{ $t('index.tUsers') }}
          </p>
          <h3><b>{{ usersCount }}</b></h3>
        </div>
      </div>
      <div class="dashboard-card">
        <div class="card-inner card">
          <i
            class="icon orders"
          />
          <p class="f-1-2">
            {{ $t('index.tOrders') }}
          </p>
          <h3><b>{{ orderCount }}</b></h3>
        </div>
      </div>
      <div class="dashboard-card">
        <div class="card-inner card">
          <i
            class="icon withdrawal"
          />
          <p class="f-1-2">
            {{ $t('index.tSells') }}
          </p>
          <h3><b>{{priceFormatting(orderAmount)}}</b></h3>
        </div>
      </div>
    </div>
    <order-chart
      v-if="chartMonth"
      :chart-month="chartMonth"
      :monthly-order="monthlyOrder"

      @month-changed="monthChanged"
    />
    <order-statistic
      :order-type="selectedOrderType"
    />
  </div>
</template>

<script>
  import OrderStatistic from '~/components/partials/OrderStatistic'
  import OrderChart from '~/components/partials/OrderChart'
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
        fetching: false
      }
    },
    components: {
      Dropdown,
      OrderChart,
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
      monthlyOrder() {
        return this.chartData?.monthly_order || null
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
      orderAmount() {
        return (parseFloat(this.dashboard?.orders_amount || 0)).toFixed(2)
      },
      ...mapGetters('admin', ['posSetting']),
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      selectedOption({key}){
        this.selectedOrderType = key
        this.dashboard = false
        this.fetchingData()
      },
      monthChanged(evt) {
        this.chartMonth = evt
        this.fetchingData()
      },
      async fetchingData() {
        try {

          this.chartData = null
          const data = await this.getRequest({
            params: {
              ...this.chartMonth,
              ...{order_type: this.selectedOrderType},
              ...{time_zone: this.timeZone},
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
</style>
