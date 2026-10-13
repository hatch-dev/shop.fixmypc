<template>

  <div class="pos-rel">

    <div class="card p-15 p-xs-10 dply-felx sided mb-20 mb-sm-15 mb-xs-10">
      <div class="stat-head">
        <h5><b>{{ $t('index.orderStat') }}</b></h5>
        <span v-if="!fetching && appliedRange" class="stat-head__range">
          {{ appliedLabel }}
        </span>
      </div>
      <date-range-picker
        :value="range"
        :applied="appliedRange"
        :max-date="maxDate"
        :disabled="fetching"
        allow-all
        default-preset="all"
        @change="rangeChanged"
      />
    </div>
    <div class="order-status-wrapper status-cards mb-20 mb-sm-15 mb-xs-10">
      <div
        v-for="card in statusCards"
        :key="card.key"
        class="card p-20 stat-mini"
      >
        <div class="stat-top">
          <p class="stat-label">
            {{ card.label }}
          </p>
          <growth-badge v-if="!fetching" :value="card.growth" />
          <skeleton-block v-else width="34px" height="12px" />
        </div>
        <h3 v-if="!fetching" class="stat-value">{{ card.value }}</h3>
        <skeleton-block v-else width="46px" height="28px" radius="8px" class="mt-5" />
      </div>
    </div>

    <div class="order-status-wrapper mb-20 mb-sm-15 mb-xs-10">
      <div class="card p-20 p-sm-15">
        <h5 class="mb-15">
          <b>{{ $t('index.topCat') }}</b>
        </h5>
        <top-table
          :rows="categories"
          :loading="fetching"
          link-prefix="/categories"
        />
      </div>

      <div class="card p-20 p-sm-15">
        <h5 class="mb-15">
          <b>{{$t('index.topBra')}}</b>
        </h5>
        <top-table
          :rows="brands"
          :loading="fetching"
          link-prefix="/brands"
        />
      </div>
    </div>

    <div class="card p-20 p-sm-15">
      <h5 class="mb-10">
        <b>{{ $t('index.topPro') }}</b>
      </h5>
      <top-table
        :rows="products"
        :loading="fetching"
        link-prefix="/products"
        wide
      />
    </div>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import DateRangePicker from '~/components/DateRangePicker'
  import {mapGetters, mapActions} from 'vuex'
  import GrowthBadge from "../GrowthBadge"
  import SkeletonBlock from "../SkeletonBlock"
  import TopTable from "./TopTable"

  export default {
    name: 'OrderStatistic',
    data() {
      return {
        orderStatistic: null,
        fetching: true,
        // Same shape as the Sales Overview control, resolved server-side.
        range: { preset: 'all', start: null, end: null }
      }
    },
    watch: {
      orderType(){
        this.fetchingData()
      }
    },
    mixins: [util],
    components: {
      GrowthBadge,
      SkeletonBlock,
      TopTable,
      DateRangePicker
    },
    props: {
      orderType: {
        type: String,
        default: ''
      },
    },
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon(){
        return this.setting?.currency_icon || '$'
      },
      appliedRange() {
        return this.orderStatistic?.range || null
      },
      appliedLabel() {
        const r = this.appliedRange
        if (!r) return ''
        const fmt = iso => {
          const d = new Date(iso + 'T00:00:00')
          if (isNaN(d)) return iso
          const day = String(d.getDate()).padStart(2, '0')
          const month = d.toLocaleString(this.$i18n?.locale || 'en', { month: 'short' })
          return `${day} ${month} ${d.getFullYear()}`
        }
        return r.start === r.end ? fmt(r.start) : `${fmt(r.start)} – ${fmt(r.end)}`
      },
      bounds() {
        return this.orderStatistic?.data_bounds || {}
      },
      maxDate() {
        return this.bounds.today || null
      },
      statusCards() {
        return [
          { key: 'cancelled', label: this.$t('index.orderCan'), value: this.cancelled, growth: this.growth.cancelled },
          { key: 'pending', label: this.$t('index.orderPend'), value: this.pending, growth: this.growth[this.orderStatusIn.PENDING] },
          { key: 'confirmed', label: this.$t('index.orderConf'), value: this.confirmed, growth: this.growth[this.orderStatusIn.CONFIRMED] },
          { key: 'picked', label: this.$t('index.packPick'), value: this.pickedUp, growth: this.growth[this.orderStatusIn.PICKED_UP] },
          { key: 'onway', label: this.$t('index.onWay'), value: this.onTheWay, growth: this.growth[this.orderStatusIn.ON_THE_WAY] },
          { key: 'delivered', label: this.$t('index.delivered'), value: this.delivered, growth: this.growth[this.orderStatusIn.DELIVERED] }
        ]
      },
      cancelled() {
        return this.orderStatistic?.cancelled || 0
      },
      pending() {
        return this.statistics[this.orderStatusIn.PENDING] || 0
      },
      confirmed() {
        return this.statistics[this.orderStatusIn.CONFIRMED] || 0
      },
      pickedUp() {
        return this.statistics[this.orderStatusIn.PICKED_UP] || 0
      },
      onTheWay() {
        return this.statistics[this.orderStatusIn.ON_THE_WAY] || 0
      },
      delivered() {
        return this.statistics[this.orderStatusIn.DELIVERED] || 0
      },
      statistics() {
        const stat = {}
        this.orderStatistic?.statistics.forEach(o => {
          stat[o.status] = o.total
        })
        return stat
      },
      growth() {
        return this.orderStatistic?.growth || {}
      },
      categories() {
        return this.orderStatistic?.categories
      },
      brands() {
        return this.orderStatistic?.brands
      },
      products() {
        return this.orderStatistic?.products
      },
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      rangeChanged(payload) {
        this.range = payload
        this.fetchingData()
      },

      async fetchingData() {
        this.fetching = true

        try {
          // The preset key is resolved server-side by the same resolver the
          // Sales Overview uses, so both blocks agree on what a window means.
          const data = await this.getRequest({
            params: {
              order_type: this.orderType,
              time_zone: this.timeZone,
              range_preset: this.range.preset,
              ...(this.range.preset === 'custom'
                ? { range_start: this.range.start, range_end: this.range.end }
                : {})
            },
            api: 'orderStatistic'
          })

          this.orderStatistic = data

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.fetching = false
      },
      ...mapActions('common', ['getRequest'])
    },
    mounted() {
      this.fetchingData()
    }
  }
</script>
