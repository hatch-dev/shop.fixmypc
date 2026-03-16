<template>
  <div>

      <div
        style="height: 0; overflow: hidden"
      >
        <div
          v-for="(data, index) in pdfList"
          :key="index"
        >
          <invoice
            :ref="`invoice${data.id}`"
            :order="data"
          />
        </div>
      </div>

      <list-page
        ref="listPage"
        list-api="getVendorOrders"
        delete-api="deleteOrder"
        route-name="orders"
        :name="$t('fSale.orderD')"
        :order-options="orderByOrders"
        gate="order"
        @deleted="deletedOrder"
        @list="itemList = $event"
      >
        <template
          v-slot:table-top="{orderOptions}"
        >
          <div class="dply-felx gap-10 f-wrap mb-15">
            <div class="dply-felx gap-10 f-wrap">

            <table-sort
              :order-by-options="orderOptions"
            />

            <inline-pop-over
              :arrow="true"
              class="bulk-action mt-md-10"
              ref="bulkDelete"
            >
              <template
                v-slot:button
              >
                {{ $t('title.act') }}
              </template>
              <template
                v-slot:content
              >
                <button @click.prevent="downloadPdf" class="outline-btn">
                  {{ $t('setting.pi') }}
                </button>
              </template>
            </inline-pop-over>
            </div>

            <date-filter
              @date-changed="filterChanged"
            />
          </div>

          <ul class="order-cb test">
            <li
              v-for="v in filterOptions"
              :key="v.key"
            >
              <input
                class="styled-checkbox"
                :id="`checkbox-${v.key}`"
                type="checkbox"
                :value="v.key"
                v-model="checkedFilter"
                @change="filterChanged"
              >
              <label
                :for="`checkbox-${v.key}`"
              >
                {{ v.title }}
              </label>
            </li>
          </ul>

        </template>

        <template v-slot:table="{list}">
          <tr class="lite-bold">
            <th>
              <input type="checkbox" @change="checkAll">
            </th>
            <th>{{ $t('fSale.orderUp') }}</th>
            <th>{{ $t('category.status') }}</th>
            <th>{{ $t('fSale.pMethod') }}</th>
            <th>{{ $t('fSale.pStatus') }}</th>
            <th>{{ $t('fSale.voucher') }}</th>
            <th>{{ $t('fSale.user') }}</th>
            <th>{{ $t('fSale.amount') }}({{ currencyIcon }})</th>
            <th>{{ $t('category.created') }}</th>
            <th />
          </tr>

          <tr
            v-for="(item, index) in list"
            :key="index"
            :class="{'new-data': !parseInt(item.viewed)}"
          >
            <td>
              <input type="checkbox" :value="item.id" v-model="cbList">
            </td>
            <td>
              <nuxt-link
                class="dply-felx j-left link"
                :to="`/orders/${item.id}`"
              >
                #{{ item.order }}
              </nuxt-link>

            </td>
            <td>{{ orderStatus[item.status].title }}</td>
            <td class="mn-w-80x">{{ paymentTypes[item.order_method] }}</td>
            <td
              :class="{'color-success': parseInt(item.payment_done) === status.PUBLIC}"
            >
              {{ parseInt(item.payment_done) === status.PUBLIC ? $t('fSale.paid') : $t('fSale.unpaid') }}
            </td>
            <td>{{ voucherStr(item) }}</td>
            <td>
              <span class="ellipsis mx-w-150x">{{ userStr(item) }}</span>
            </td>
            <td class="mn-w-90x">{{ item.total_amount }}</td>
            <td class="mn-w-90x">{{ item.created }}</td>
            <td class="ptb-10">
              <button
                v-if="$can('order', 'view')"
                @click.prevent="$refs.listPage.editItem(item.id)"
                class="lite-btn"
              >
                {{ $t('fSale.view') }}</button>

            </td>
          </tr>
        </template>
      </list-page>
    </div>

</template>

<script>
  import util from "~/mixin/util"
  import ListPage from "~/components/partials/ListPage"
  import TableSort from "~/components/partials/TableSort"
  import VendorOrders from "~/components/partials/VendorOrders"
  import {mapGetters, mapActions} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";
  import InlinePopOver from "~/components/InlinePopOver";
  import Invoice from "../../components/partials/Invoice";
  import DateFilter from "../DateFilter";

  export default {
    name: "OrderList",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        pdfList: [],
        loaded: false,
        orderByOrders: {
          order_method: { title: this.$t('fSale.pMethod') },
          status: { title: this.$t('category.status') },
          total_amount: { title: this.$t('brand.price') },
          user_id: { title: this.$t('fSale.user') },
          created_at: { title: this.$t('category.date') },
        },
        checkedFilter:[],
        filterOptions:[
          {
            key: 'cancelled',
            title: this.$t('index.orderCan')
          },
          {
            key: 'cash_on_delivery',
            title: this.$t('fSale.cod')
          },
          {
            key: 'card_payment',
            title: this.$t('fSale.card')
          },
          {
            key: 'paypal',
            title: this.$t('fSale.paypal')
          },
          {
            key: 'paid',
            title: this.$t('fSale.paid')
          },
          {
            key: 'unpaid',
            title: this.$t('fSale.unpaid')
          }
        ]
      }
    },
    mixins: [util, bulkDelete],
    components: {
      DateFilter,
      Invoice,
      InlinePopOver,
      VendorOrders,
      ListPage,
      TableSort
    },
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods:{
      downloadPdf() {
        this.pdfList = []
        if(this.cbList?.length){
          const orderList = []

          this.itemList.forEach(i => {
            orderList[i.id] = i
          });
          this.cbList.forEach(i => {
            if(orderList[i]){
              this.pdfList.push(orderList[i])
            }
          })
        }
        this.$refs.bulkDelete.closePop()

        let i = 0

        const self = this

        const downloadInterval = setInterval(() => {
          if(self.cbList.length > i){
            const invStr = `invoice${self.cbList[i]}`

            if(self.$refs[invStr].length > 0){
              self.$refs[invStr][0]?.generatingPdf()
            }
          } else {
            clearInterval(downloadInterval)
          }
          i++
        }, 200);

      },
      deleteAll() {
        this.$refs.bulkDelete.closePop()
        this.deleteBulk()
      },
      deletedOrder(){
        this.emptyDashboard()
      },
      filterChanged(filter = null){
        this.$router.push({
          query: {
            ...this.$route.query,
            ...filter,
        ...{
          page: 1,
          orderBy: 'created_at',
          orderByType: 'desc',
          filter: this.checkedFilter.join(','),
        }

          }
        })
      },
      userStr(order) {
        return order?.address?.name ?? order?.address?.email
      },
      voucherStr(order) {
        if(order.voucher) {
          return `${order.offered} (${order.voucher})`
        }
        return this.$t('prod.na')
      },
      ...mapActions('dashboard', ['emptyDashboard'])
    },
    mounted() {
      this.checkedFilter = this.$route?.query?.filter?.split(',') || []
    }
  }
</script>

<style scoped>

</style>
