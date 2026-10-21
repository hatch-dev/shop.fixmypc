<template>
  <div>
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

    <order-list
      v-if="$store.state.admin.isVendor"
    />

    <div v-else>
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
        list-api="getOrders"
        delete-api="deleteOrder"
        :delete-message="$t('fSale.dltOrder')"
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
          <div class="table-top filter-toolbar orders-toolbar">

            <div class="orders-toolbar__row">
              <div class="orders-toolbar__left">
                <table-sort
                  :order-by-options="orderOptions"
                />

                <inline-pop-over
                  :arrow="true"
                  class="bulk-action"
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
                    <button @click.prevent="deleteAll" class="outline-btn">
                      {{ $t('category.delete') }}
                    </button>

                    <button @click.prevent="downloadPdf" class="outline-btn">
                      {{ $t('setting.pi') }}
                    </button>
                  </template>
                </inline-pop-over>
              </div>

              <div class="orders-toolbar__right">
                <date-filter
                  @date-changed="filterChanged"
                />
              </div>
            </div>

            <div class="orders-toolbar__chips">
              <label
                v-for="v in filterOptions"
                :key="v.key"
                class="order-chip"
                :class="{ 'is-active': checkedFilter.includes(v.key) }"
              >
                <input
                  type="checkbox"
                  :value="v.key"
                  v-model="checkedFilter"
                  @change="filterChanged"
                >
                <span>{{ v.title }}</span>
              </label>
            </div>

          </div>

        </template>

        <template v-slot:table="{list, from}">
          <tr class="lite-bold">
            <th>
              <input type="checkbox" @change="checkAll">
            </th>
            <th class="w-50x mx-w-50x">#</th>
            <!--
              Customer and account type sit beside the order number, because
              "who is this and what are they entitled to" is the question the
              list is scanned for. The money is broken out far enough to see a
              discounted order at a glance without opening it.
            -->
            <th>{{ $t('fSale.orderUp') }}</th>
            <th>{{ $t('fSale.user') }}</th>
            <th>{{ $t('fSale.accountType') }}</th>
            <th>{{ $t('category.status') }}</th>
            <th>{{ $t('fSale.pMethod') }}</th>
            <th>{{ $t('fSale.pStatus') }}</th>
            <th>{{ $t('fSale.fulfilment') }}</th>
            <th>{{ $t('fSale.poNo') }}</th>
            <th>{{ $t('fSale.discount') }}({{ currencyIcon }})</th>
            <th>{{ $t('fSale.tax') }}({{ currencyIcon }})</th>
            <!--
              "Amount" said nothing about whether the figure included VAT.
              It is the total the customer pays, so it says so.
            -->
            <th>{{ $t('fSale.totalIncVat') }}({{ currencyIcon }})</th>
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
            <td class="w-50x mx-w-50x">{{ from + index }}</td>
            <td>
              <nuxt-link
                class="dply-felx j-left link"
                :to="`/orders/${item.id}`"
              >
                #{{ item.order }}
              </nuxt-link>

            </td>
            <td>
              <span class="ellipsis mx-w-150x" :title="customerEmail(item)">{{ userStr(item) }}</span>
              <small class="row-sub">{{ customerEmail(item) }}</small>
            </td>
            <td>
              <span class="account-pill" :class="'is-' + accountType(item)">
                {{ accountTypeLabel(item) }}
              </span>
            </td>
            <!--
              statusTitle, not orderStatus[...].title: the map stops at 5, so a
              cancelled, returned or refunded order read `.title` off undefined
              and took the whole list down with a TypeError.
            -->
            <!--
              Order, return and refund status apart (OrderState): a cancelled
              order that was refunded shows both, and a delivered order with
              a return in progress still reads Delivered.
            -->
            <td class="mn-w-120x">
              <span class="state-pill" :class="'is-' + orderTone(item)">{{ orderStateLabel(item) }}</span>
              <span v-if="item.state && item.state.return" class="state-pill is-warn" :class="{ 'is-good': item.state.return.key === 'completed', 'is-muted': ['rejected', 'withdrawn'].includes(item.state.return.key) }">
                {{ item.state.return.type === 'exchange' ? 'Exchange' : 'Return' }}: {{ returnLabels[item.state.return.key] || item.state.return.key }}
              </span>
              <span v-if="item.state && item.state.refund" class="state-pill" :class="'is-' + ({ completed: 'good', partial: 'good', failed: 'bad' }[item.state.refund.key] || 'warn')">
                {{ refundLabels[item.state.refund.key] }}
              </span>
            </td>
            <td class="mn-w-80x">{{ paymentTypes[item.order_method] }}</td>
            <td
              :class="{'color-success': parseInt(item.payment_done) === status.PUBLIC}"
            >
              {{ parseInt(item.payment_done) === status.PUBLIC ? $t('fSale.paid') : $t('fSale.unpaid') }}
            </td>
            <td>{{ fulfilmentLabel(item) }}</td>
            <td>
              <span v-if="item.purchase_order_number">{{ item.purchase_order_number }}</span>
              <span v-else class="color-muted">{{ $t('prod.na') }}</span>
            </td>
            <td class="mn-w-90x">
              <span v-if="discountOf(item) > 0" class="color-success">
                - {{ discountOf(item).toFixed(2) }}
              </span>
              <span v-else class="color-muted">&mdash;</span>
            </td>
            <td class="mn-w-90x">{{ Number(item.calculated && item.calculated.tax || 0).toFixed(2) }}</td>
            <td class="mn-w-90x">{{ item.total_amount }}</td>
            <td class="mn-w-90x">{{ item.created }}</td>
            <td class="ptb-10">
              <button
                v-if="$can('order', 'view')"
                @click.prevent="$refs.listPage.editItem(item.id)"
                class="lite-btn"
              >
                {{ $t('fSale.view') }}</button>
              <button
                v-if="$can('order', 'delete')"
                @click.prevent="$refs.listPage.deleteItem(item.id)"
                class="delete-btn lite-btn"
              >
                {{ $t('category.delete') }}</button>
            </td>
          </tr>
        </template>
      </list-page>
    </div>


  </div>
</template>

<script>

  import util from "~/mixin/util"
  import ListPage from "~/components/partials/ListPage"
  import TableSort from "~/components/partials/TableSort"
  import {mapGetters, mapActions} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";
  import InlinePopOver from "~/components/InlinePopOver";
  import Invoice from "~/components/partials/Invoice";
  import OrderList from "~/components/partials/OrderList";
  import Dropdown from "~/components/Dropdown";
  import AjaxButton from "../../components/AjaxButton";
  import DateFilter from "../../components/DateFilter";

  export default {
    name: "orders",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {

        pdfList: [],
        selectedOrderType: 'website',
        loaded: false,
        orderByOrders: {
          order_method: { title: this.$t('fSale.pMethod') },
          status: { title: this.$t('category.status') },
          total_amount: { title: this.$t('brand.price') },
          user_id: { title: this.$t('fSale.user') },
          created_at: { title: this.$t('category.date') },
        },
        checkedFilter:[],
        returnLabels: {
          requested: 'To review', approved: 'Awaiting return', item_received: 'Received', awaiting_payment: 'Awaiting payment',
          completed: 'Completed', rejected: 'Rejected', withdrawn: 'Withdrawn'
        },
        refundLabels: {
          pending: 'Refund pending', processing: 'Refund processing', completed: 'Refunded', partial: 'Partly refunded', failed: 'Refund failed'
        },
        filterOptions:[
          {
            key: 'cancelled',
            title: this.$t('index.orderCan')
          },
          {
            // return/exchange cases, returned orders, and cancelled orders with money owed or sent back
            key: 'returns',
            title: 'Returns & refunds'
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
            key: 'bank_transfer',
            title: 'Bank transfer'
          },
          {
            // Bank transfer, unpaid, not cancelled: the ones to check the
            // bank account for. Includes those with a reference in.
            key: 'awaiting_transfer',
            title: 'Awaiting transfer'
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
      AjaxButton,
      Dropdown,
      OrderList,
      Invoice,
      InlinePopOver,
      ListPage,
      TableSort
    },
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('admin', ['posSetting']),
      ...mapGetters('setting', ['setting'])
    },
    methods:{

      selectedOption({key}){
        this.selectedOrderType = key
        this.$router.push({
          query: {
            ...this.$route.query,
            ...{
              order_type: key
            }
          }
        })
      },
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
      /*
       * The customer, decided server-side: the account first, then the contact
       * captured at checkout, then the address. This read `address.name` and
       * fell back to `address.email`, so every click-and-collect order - which
       * has no address, because nothing is being posted - showed a blank
       * Customer cell.
       */
      userStr(order) {
        return order?.customer?.name || order?.customer?.email || this.$t('prod.na')
      },
      customerEmail(order) {
        return order?.customer?.email || ''
      },
      accountType(order) {
        return order?.customer?.account_type || 'personal'
      },
      accountTypeLabel(order) {
        return {
          business: this.$t('fSale.business'),
          trade: this.$t('fSale.trade'),
          personal: this.$t('fSale.personal'),
          guest: this.$t('fSale.guest')
        }[this.accountType(order)] || this.$t('fSale.personal')
      },
      /* Every line on an order shares one fulfilment type; 2 is collection. */
      fulfilmentLabel(order) {
        return parseInt(order?.ordered_products?.[0]?.shipping_type) === this.shippingTypeIn.pickup
          ? this.$t('fSale.collection')
          : this.$t('fSale.delivery')
      },
      /* The product discount frozen on the order, not recomputed from today. */
      discountOf(order) {
        return Number(order?.calculated?.discount || 0)
      },
      /*
       * util.orderStatus only maps 1-5. Cancelled, returned and refunded are
       * statuses orders genuinely reach.
       */
      // The order's own status from OrderState; the raw number for an older API answer.
      orderStateLabel(item) {
        const key = item?.state?.order
        if (!key) return this.statusTitle(item.status)
        if (key === 'processing' || key === 'shipped') return this.statusTitle(item.status)
        return { delivered: this.statusTitle(item.status), cancelled: this.$t('index.orderCan'),
          returned: this.$t('fSale.returned'), refunded: this.$t('fSale.refUp') }[key]
      },
      orderTone(item) {
        return { cancelled: 'bad', delivered: 'good', shipped: 'ship', returned: 'muted', refunded: 'muted' }[item?.state?.order] || 'info'
      },
      statusTitle(status) {
        const key = parseInt(status)

        const terminal = {
          [this.orderStatusIn.CANCELLED]: this.$t('index.orderCan'),
          [this.orderStatusIn.RETURNED]: this.$t('fSale.returned'),
          [this.orderStatusIn.REFUNDED]: this.$t('fSale.refUp')
        }

        return this.orderStatus[key]?.title || terminal[key] || ''
      },
      ...mapActions('dashboard', ['emptyDashboard'])
    },
    mounted() {
      if(this.$route?.query?.order_type){
        this.selectedOrderType =  this.$route?.query?.order_type
      }

      this.checkedFilter = this.$route?.query?.filter?.split(',') || []


    }
  }
</script>

<style scoped>
  /* order / return / refund status pills (OrderState) */
  .state-pill { display: inline-block; margin: 1px 4px 1px 0; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; background: #eef2ff; color: #33319a; }
  .state-pill.is-good { background: #ecfdf3; color: #027a48; }
  .state-pill.is-bad { background: #fef3f2; color: #b42318; }
  .state-pill.is-warn { background: #fffaeb; color: #b54708; }
  .state-pill.is-ship { background: #f4ebff; color: #6941c6; }
  .state-pill.is-muted { background: #f2f4f7; color: #475467; }
.orders-toolbar__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.orders-toolbar__left {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.orders-toolbar__right {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

.orders-toolbar__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--border-color);
}

.order-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  height: 34px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
  user-select: none;
  background: var(--surface-color);
  color: var(--text-muted);
  transition: background 0.12s ease, color 0.12s ease, border-color 0.12s ease;
}

.order-chip input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.order-chip:hover {
  border-color: var(--primary-color);
  background: var(--hover-color);
}

.order-chip.is-active {
  background: var(--primary-soft);
  border-color: var(--primary-color);
  color: var(--primary-color);
  font-weight: 600;
}

.order-chip.is-active span::before {
  content: "";
  display: inline-block;
  width: 6px;
  height: 6px;
  margin-right: 7px;
  border-radius: 50%;
  background: var(--primary-color);
  vertical-align: 1px;
}
</style>
