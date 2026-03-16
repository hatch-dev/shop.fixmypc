<template>

  <div class="pos-rel">

    <div
      v-if="fetching"
      class="h-100 dply-felx j-center abs-centered"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <div class="card p-15 p-xs-10 dply-felx mb-20 mb-sm-15 mb-xs-10">
      <h5><b>{{ $t('index.orderStat') }}</b></h5>
      <dropdown
        class="right-dropdown"
        :selected-key="selected"
        :options="dashboardOptions"
        @clicked="dropdownSelected"
      />
    </div>
    <div class="order-status-wrapper mb-20 mb-sm-15 mb-xs-10">

      <div class="card p-20">
        <p>
          {{ $t('index.orderCan') }}
        </p>
        <h3>{{ cancelled }}</h3>
      </div>
      <div class="card p-20">
        <p>
          {{ $t('index.orderPend') }}
        </p>
        <h3>{{ pending }}</h3>
      </div>
      <div class="card p-20">
        <p>
        <p>
          {{ $t('index.orderConf') }}
        </p>
        <h3>{{ confirmed }}</h3>
      </div>
      <div class="card p-20">
        <p>
          {{ $t('index.packPick') }}
        </p>
        <h3>{{ pickedUp }}</h3>
      </div>
      <div class="card p-20">
        <p>{{ $t('index.onWay') }}</p>
        <h3>{{ onTheWay }}</h3>
      </div>
      <div class="card p-20">
        <p>{{ $t('index.delivered') }}</p>
        <h3>{{ delivered }}</h3>
      </div>
    </div>

    <div class="order-status-wrapper mb-20 mb-sm-15 mb-xs-10">
      <div class="card p-20 p-sm-15">
        <h5 class="mb-15">
          <b>{{ $t('index.topCat') }}</b>
        </h5>
        <p
          v-if="categories && !categories.length"
          class="mb-10"
        >
          {{ $t('index.nf') }}
        </p>
        <div v-else class="table-wrapper">
          <table
            class="mn-w-400x mb-10 mt-0 tr-border"
          >
            <tr class="lite-bold">
              <th>{{ $t('index.title') }}</th>
              <th>{{ $t('index.sold') }}</th>
              <th>{{ $t('index.amount') }}</th>
            </tr>
            <tr
              v-for="(value, index) in categories"
              :key="index"
            >
              <td class="">
                <nuxt-link
                  :to="`/categories/${value.id}`"
                  class="dply-felx j-left link"
                >
                  <lazy-image
                    class="mr-20"
                    :data-src="getThumbImageURL(value.image)"
                    :alt="value.title"
                  />
                  <h5 class="mx-w-300x">{{ value.title }}</h5>
                </nuxt-link>
              <td>
                {{ value.total }}
              </td>
              <td>
                {{ priceFormatting(value.total_price) }}
              </td>
            </tr>
          </table>
        </div>
      </div>

      <div class="card p-20 p-sm-15">
        <h5 class="mb-15">
          <b>{{$t('index.topBra')}}</b>
        </h5>
        <p
          v-if="brands && !brands.length"
          class="mb-10"
        >
          {{ $t('index.nf') }}
        </p>
        <div v-else class="table-wrapper">
          <table
            class="mn-w-400x mb-10 mt-0 tr-border"
          >
            <tr class="lite-bold">
              <th>{{ $t('index.title') }}</th>
              <th>{{ $t('index.sold') }}</th>
              <th>{{ $t('index.amount') }}</th>
            </tr>
            <tr v-for="(value, index) in brands"
                :key="index">
              <td class="">
                <nuxt-link
                  :to="`/brands/${value.id}`"
                  class="dply-felx j-left link"
                >
                  <lazy-image
                    class="mr-20"
                    :data-src="getThumbImageURL(value.image)"
                    :alt="value.title"
                  />
                  <h5 class="mx-w-300x">{{ value.title }}</h5>
                </nuxt-link>

              </td>
              <td>
                {{ value.total }}
              </td>
              <td>
                {{ priceFormatting(value.total_price) }}
              </td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <div class="card p-20 p-sm-15">
      <h5 class="mb-10">
        <b>{{ $t('index.topPro') }}</b>
      </h5>
      <p
        v-if="products && !products.length"
        class="mb-10"
      >
        {{ $t('index.nf') }}
      </p>
      <div v-else class="table-wrapper">
        <table
          class="mn-w-800x mb-10 mt-0 tr-border"
        >
          <tr class="lite-bold">
            <th>{{ $t('index.title') }}</th>
            <th>{{ $t('index.sold') }}</th>
            <th>{{ $t('index.amount') }}</th>
          </tr>
          <tr v-for="(value, index) in products"
              :key="index">
            <td>
              <nuxt-link
                class="dply-felx j-left link"
                :to="`/products/${value.id}`"
              >
                <lazy-image
                  class="mr-20"
                  :data-src="getThumbImageURL(value.image)"
                  :alt="value.title"
                />
                <h5 class="mx-w-800x">{{ value.title }}</h5>
              </nuxt-link>
            </td>
            <td>
              {{ value.total }}
            </td>
            <td>
              {{ priceFormatting(value.total_price) }}
            </td>
          </tr>
        </table>
      </div>

    </div>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import Dropdown from '~/components/Dropdown'
  import {mapGetters, mapActions} from 'vuex'
  import Spinner from '~/components/Spinner'
  import LazyImage from "../LazyImage";

  export default {
    name: 'OrderStatistic',
    data() {
      return {
        orderStatistic: null,
        fetching: true,
        time: null,
        selected: '9999',
        dashboardOptions: {
          '9999': {title: this.$t('index.all') },
          '1': {title: this.$t('index.today')},
          '7': {title: this.$t('index.lw')},
          '30': {title: this.$t('index.lm')},
          '365': {title: this.$t('index.ly')}
        }
      }
    },
    watch: {
      orderType(){
        this.fetchingData()
      }
    },
    mixins: [util],
    components: {
      LazyImage,
      Dropdown,
      Spinner
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
      cancelled() {
        return this.orderStatistic?.cancelled
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
      calcDate(days) {
        if (days < 9999) {
          var d = new Date(new Date().setHours(0, 0, 0, 0))
          return new Date(d.getTime() - ((days - 1) * 24 * 60 * 60 * 1000))
        }
        return null
      },
      dropdownSelected(evt) {
        this.time = this.time = this.calcDate(parseInt(evt.key))
        this.fetchingData()
      },

      async fetchingData() {
        this.fetching = true

        try {

          const data = await this.getRequest({
            params: {
              order_type: this.orderType,
              time: this.time,
              time_zone: this.timeZone,
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
    destroyed() {

    },
    mounted() {
      this.fetchingData()
    }
  }
</script>

<style lang="stylus">
</style>
