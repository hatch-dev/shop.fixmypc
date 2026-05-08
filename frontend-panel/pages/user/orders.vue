<template>
  <client-only>
    <account-layout
      active-route="orders"
      @clicked-orders="loadData"
      class="mb-5"
    >
      <template v-slot:rightArea>

        <div class="container">
          <div class="my-orders-box p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
              <div>
                <h5 class="mb-0 order-hrading">My Orders</h5>
                <small class="text-muted">View and manage your recent orders.</small>
              </div>
              <div class="search-box d-flex align-items-center">
                <i class="fa-solid fa-magnifying-glass me-2"></i>
                <input type="text" v-model="search" placeholder="Search by Order ID">
              </div>
            </div>

            <div class="orders-tabs d-flex gap-4 flex-wrap">
              <a v-for="tab in tabs" :key="tab.key" href="#" @click.prevent="activeTab = tab.key" :class="['tab-link', { active: activeTab === tab.key }]">
                {{ tab.label }}
                (<span class="count">{{ countByStatus(tab.key) }}</span>)
              </a>
            </div>
          </div>
          <div v-if="filteredOrders.length">
            <div
              v-for="order in filteredOrders"
              :key="order.id"
              class="Frequently-card-box mb-4"
            >
              <div class="section-header d-flex justify-content-between flex-wrap gap-2">
                <div class="d-flex gap-4 flex-wrap small text-muted">
                  <div>
                    <div class="order-placed">ORDER PLACED</div>
                    <strong class="order-placed-deatils">{{ order.created }}</strong>
                  </div>
                  <div>
                    <div class="order-placed">TOTAL</div>
                    <strong class="order-placed-deatils">
                      €{{ order.total_amount }}
                    </strong>
                  </div>
                  <div>
                    <div class="order-placed">SHIP TO</div>
                    <strong class="order-placed-deatils">
                      {{ order.shipping_name }}
                    </strong>
                  </div>
                </div>
                <div class="text-end small">
                  <div class="order-number">ORDER #{{ order.order }}</div>
                  <nuxt-link :to="`/user/order/${order.id}`" class="order-details">
                    View Order Details
                  </nuxt-link>
                    |
                  <a href="#" class="order-details">
                    Invoice
                  </a>
                </div>
              </div>
              <div class="p-4 pb-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                  <div>
                    <h6 class="mb-1 processing-text">
                      <span v-if="parseInt(order.cancelled) === 1">
                        Cancelled
                      </span>
                      <span v-else>
                        {{ orderStatus[order.status].title }}
                      </span>
                    </h6>

                    <p class="text-muted">
                      <span v-if="parseInt(order.cancelled) === 1">
                        This order was cancelled.
                      </span>
                      <span v-else-if="orderStatus[order.status].title === 'Delivered'">
                        Package was handed to resident.
                      </span>
                      <span v-else>
                        Estimated Delivery: <strong>{{ deliveryText(order) }}</strong>
                      </span>
                    </p>
                  </div>
                  <div class="d-flex gap-2">

                    <!-- CANCELLED -->
                    <button
                      v-if="parseInt(order.cancelled) === 1"
                      class="track-package-btn"
                    >
                      Buy Again
                    </button>

                    <!-- DELIVERED -->
                    <button
                      v-else-if="orderStatus[order.status].title === 'Delivered'"
                      class="track-package-btn"
                    >
                      Buy Again
                    </button>

                    <button v-else-if="orderStatus[order.status].title === 'Delivered'"
                      class="track-package-cancel-btn"
                    >
                      Write a Review
                    </button>

                    <!-- NORMAL FLOW -->
                    <template v-else>
                      <button class="track-package-btn" @click="goToTrack(order)">
                        Track Package
                      </button>

                      <button
                        class="track-package-cancel-btn"
                        @click="openCancelPopup(order)"
                      >
                        Cancel Order
                      </button>
                    </template>
                  </div>
                </div>
                <div
                  v-for="item in order.ordered_products"
                  :key="item.id"
                  class="d-flex align-items-center gap-3 mb-4"
                >
                  <div class="product-order-img">
                    <nuxt-link :to="productLink(item.product)">
                      <lazy-image
                        :data-src="thumbImageURL(item.product)"
                        :title="item.product.title"
                        :alt="item.product.title"
                      />
                    </nuxt-link>
                  </div>
                  <div>
                    <p class="order-product-name">
                      <nuxt-link :to="productLink(item.product)">
                        {{ item.product.title }}
                      </nuxt-link>
                    </p>
                    <small class="text-muted">
                      Sold by: {{ item.product.vendor || 'Store' }}
                    </small>
                    <div class="d-flex gap-2 mt-1">
                      <span class="order-quantity">
                        Quantity: {{ item.quantity }}
                      </span>
                      <span class="order-quantity">
                        €{{ item.selling }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              <div class="progress-wrapper" v-if="['Pending', 'Confirmed', 'Picked up', 'On the way'].includes(orderStatus[order.status].title) && parseInt(order.cancelled) !== 1">
                <div class="progress-track">
                  <div class="progress-fill" :style="{ width: progressWidth(orderStatus[order.status].title) }"></div>
                </div>
                <div class="progress-labels">
                  <span v-for="(value, index) in orderStatus" :key="index">
                    {{ value.title }}
                  </span>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="text-center py-5">
            No Orders Found
          </div>
        </div>

        <!-- <transition name="fade" mode="out-in">
          <rate-popup
            v-if="rateProductId"
            :order-id="rateOrderId"
            :product-id="rateProductId"
            @close="rateProductId = 0"
          />
        </transition>
        <order-tabbing
          ref="orderTab"
          @fetch-data="fetchingData"
        />
        <div
          class="spinner-wrapper flex layer-white"
          v-if="fetchingOrderData"
        >
          <spinner
            :radius="100"
          />
        </div>
        <div
          v-else-if="currentOrders && !currentOrders.length"
          class="info-msg"
        >
          {{ $t('orders.noOrderYet') }}
        </div>
        <div v-else>
          <div
            v-for="(value, index) in currentOrders"
            :key="index"
            class="card mb-15"
          >
            <div class="flex sided b-b ptb-10 plr-20 plr-sm-15 block-xs">
              <div>
                <nuxt-link
                  :to="`/user/order/${value.id}`"
                  class="block"
                >
                  {{ $t('order.order') }}
                  <span class="link-color">
                  #{{ value.order }}
                </span>
                </nuxt-link>
                <span class="color-lite f-9">{{ $t('filter.placed', {date: value.created}) }}</span>
              </div>

              <div>
                <nuxt-link
                  :to="`/user/order/${value.id}`"
                  class="link-color mt-xs-5"
                >
                  {{ $t('orders.manageOrder') }}
                </nuxt-link>
              </div>
            </div>

            <div
              v-for="(ordered, i) in value.ordered_products"
              :key="i"
              class="flex sided ptb-10 plr-20 plr-sm-15"
            >
              <div class="flex grow gap-15">
                <div class="w-80x">
                  <nuxt-link
                    :to="productLink(ordered.product)"
                    class="img-wrapper w-100"
                  >
                    <lazy-image
                      :data-src="thumbImageURL(ordered.product)"
                      :title="ordered.product.title"
                      :alt="ordered.product.title"
                    />
                  </nuxt-link>
                </div>
                <div class="flex grow sided block-xs">
                  <div>
                    <h5>
                      <nuxt-link
                        :to="productLink(ordered.product)"
                        :title="ordered.product.title"
                      >
                        {{ ordered.product.title }}
                      </nuxt-link>
                    </h5>
                    <button
                      v-if="!!!value.cancelled"
                      aria-label="submit"
                      class="link-color "
                      @click.prevent="rateNow(ordered)"
                    >
                      {{ $t('ratePopup.rateNow') }}
                    </button>
                  </div>

                  <div class="flex start">
                    <h5 class="mr-20 mr-sm-15">
                    <span class="color-lite f-9 mr-5">
                      {{ $t('orders.qty') }}
                    </span>
                      {{ ordered.quantity }}
                    </h5>

                    <h5 class="">
                    <span class="color-lite f-9 mr-5">
                      {{ $t('detailRight.price') }}
                    </span>

                      <price-format
                        :price="parseInt(ordered.selling)"
                      />

                    </h5>
                  </div>
                </div>
              </div>
            </div>

            <div
              class="flex sided block-xs b-t ptb-10 plr-20 plr-sm-15 pos-rel"
            >
              <p
                v-if="parseInt(dataFromObject(value, 'cancelled', 0)) === status.PUBLIC"
                class="color-danger mr-15"
              >
                {{ $t('order.orderCancelled') }}
              </p>
              <p
                v-else
                class="mr-15"
              >
              <span
                class="color-lite f-8 mr-5"
              >
                {{ $t('orders.shippingStatus') }}
              </span>
                <span class="">
                {{ orderStatus[value.status].title }}
              </span>
              </p>
              <div class="flex sided">
                <p>
                <span
                  class="color-lite f-8 mr-5"
                >
                  {{ $t('order.paymentStatus') }}
                </span>
                  <span
                    class=" mr-5"
                  >
                  {{ paymentStatus[value.payment_done] }}
                </span>
                </p>
                <pay-button
                  v-if="parseInt(dataFromObject(value, 'cancelled', 0)) !== status.PUBLIC
                    && parseInt(value.payment_done) === paymentStatusIn.UNPAID
                    && parseInt(value.order_method) !== orderMethods.CASH_ON_DELIVERY"
                  :order="value"
                />
              </div>
            </div>
          </div>
        </div> -->

        <transition name="fade" mode="out-in">
          <order-cancel-popup
            v-if="cancelPopup"
            :order-id="selectedOrderId"
            @success="handleCancelSuccess"
            @close="cancelPopup = false"
          />
        </transition>

        <div class="flow-hidden">
          <pagination
            v-if="!changedSelectedOrder"
            ref="orderPagination"
            :total-page="totalPage"
            @fetching-data="fetchingData"
          />
        </div>
      </template>
    </account-layout>
  </client-only>

</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import metaHelper from '~/mixin/metaHelper'
  import LazyImage from '~/components/LazyImage'
  import RatePopup from '~/components/RatePopup'
  import PaymentPopup from '~/components/PaymentPopup'
  import AccountLayout from '~/components/AccountLayout'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import Pagination from '~/components/Pagination'
  import OrderTabbing from "~/components/OrderTabbing";
  import Spinner from "~/components/Spinner";
  import PayButton from "~/components/PayButton";
  import PriceFormat from "~/components/PriceFormat";
  import global from '~/mixin/global'
  import OrderCancelPopup from "~/components/OrderCancelPopup"

  export default {
    middleware: ['common-middleware'],
    head() {
      return {
        title: 'Orders',
        meta: []
      }
    },
    data() {
      return {
        cancelPopup: false,
        selectedOrderId: null,
        payNowOrder: null,
        deactivate: true,
        fetchingOrderData: false,
        changedSelectedOrder: false,
        rateProductId: 0,
        rateOrderId: 0,
        orderParams: {},
        orders: [],
        search: '',
        activeTab: 'all',
        tabs: [
          { key: 'all', label: 'All Orders' },
          { key: 'processing', label: 'Processing' },
          { key: 'shipped', label: 'Shipped' },
          { key: 'delivered', label: 'Delivered' },
          { key: 'cancelled', label: 'Cancelled' }
        ]
      }
    },
    watch: {},
    components: {
      PriceFormat,
      PayButton,
      Spinner,
      OrderTabbing,
      LazyImage,
      RatePopup,
      AccountLayout,
      Pagination,
      PaymentPopup,
      OrderCancelPopup
    },
    mixins: [util, metaHelper, routeParamHelper, global],
    computed: {
      filteredOrders() {
        let data = this.currentOrders || []
        if (this.activeTab !== 'all') {
          data = data.filter(order => {

            const title = this.orderStatus[order.status]?.title
            const isCancelled = parseInt(order.cancelled) === 1

            if (this.activeTab === 'cancelled') {
              return isCancelled
            }

            if (isCancelled) return false

            if (this.activeTab === 'delivered') {
              return title === 'Delivered'
            }

            if (this.activeTab === 'processing') {
              return ['Pending', 'Confirmed'].includes(title)
            }

            if (this.activeTab === 'shipped') {
              return ['Picked up', 'On the way'].includes(title)
            }

            return true
          })
        }

        if (this.search) {
          data = data.filter(o =>
            o.order.toLowerCase().includes(this.search.toLowerCase())
          )
        }

        return data
      },
      totalPage() {
        return this.orderedList?.last_page
      },
      currentOrders() {
        return this.orderedList?.data
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('order', ['orderedList']),
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    methods: {
      goToTrack(order) {
        this.$router.push({
          path: '/track-order',
          query: {
            order_id: order.order
          }
        })
      },
      handleCancelSuccess() {
        this.cancelPopup = false
        this.fetchingData()
      },
      openCancelPopup(order) {
        this.selectedOrderId = order.id
        this.cancelPopup = true
      },
      countByStatus(status) {
        const data = this.currentOrders || []

        if (status === 'all') return data.length

        return data.filter(order => {
          const title = this.orderStatus[order.status]?.title
          const isCancelled = parseInt(order.cancelled) === 1

          if (status === 'cancelled') {
            return isCancelled
          }

          if (isCancelled) return false

          if (status === 'delivered') {
            return title === 'Delivered'
          }

          if (status === 'processing') {
            return ['Pending', 'Confirmed'].includes(title)
          }

          if (status === 'shipped') {
            return ['Picked up', 'On the way'].includes(title)
          }

          return false
        }).length
      },
      progressWidth(status) {
        const map = {
          'Pending': '20%',
          'Confirmed': '40%',
          'Picked up': '60%',
          'On the way': '80%',
          'Delivered': '100%'
        }
        return map[status] || '10%'
      },
      deliveryText(order) {
        if (order.status === 'delivered') {
          return 'Delivered'
        }
        return 'Soon'
      },
      cancelOrder(order) {
        // call API
        console.log('Cancel order', order.id)
      },
      async generateParam() {
        this.changedSelectedOrder = true
        await this.fetchingData()
      },
      rateNow(ordered) {
        this.rateProductId = ordered.product.id
        this.rateOrderId = parseInt(ordered.order_id)
      },
      loadData() {
        this.$refs.orderPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingOrderData = true
        setTimeout(async () => {
          try {
            this.settingRouteParam()
            const params = {
              ...{
                time_zone: this.timeZone,
                order_by: this.orderBy,
                type: this.orderByType,
                page: this.page,
                q: this.search,
                user_token: await this.getUserToken(),
              },
              ...this.$refs.orderTab?.generateParam()
            }
            const data = await this.getOrderByUser({
              payload: params,
              lang: this.langCode
            })
            if (data?.status !== 200) {
              this.hasError(data)
            }
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.changedSelectedOrder = false
          this.fetchingOrderData = false
        }, 100)
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('order', ['getOrderByUser']),
    },

    async mounted(){
      await this.fetchingData()
        window.scrollTo(0,0);
    },
    async asyncData({store, error, $auth}) {
      try {

        if(!store.state?.common?.setting?.guest_checkout) {
          if (!$auth.loggedIn) {
            $auth.redirect('login')
            return false
          }
        }

        if(!store.state.common.paymentGateway){
          const data = await store.dispatch('common/getRequest', {
            params: {},
            api: 'paymentGateway'
          })

          store.commit('common/SET_PAYMENT_GATEWAY', data.data)
        }
      } catch (e) {
        error(e)
      }
    },
  }
</script>

<style scoped>
.my-orders-box {
    background-color: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
}   
.search-box {
    background: #F7F7FA;
    border: 1px solid #E3E3EF;
    border-radius: 10px;
    padding: 0px 10px;
}
h5.order-hrading {
    font-size: 24px;
    font-weight: 400;
    color: #130E2B;
}
.search-box input {
    border: none;
    outline: none;
    background: transparent;
}

/* Tabs */
.orders-tabs {
    border-bottom: 1px solid #e6e8f0;
}
.orders-tabs a {
    text-decoration: none;
    color: #666;
    font-size: 14px;
    padding-bottom: 6px;
    position: relative;
}
.orders-tabs a.active {
    color: #4b49ac;
    font-weight: 500;
}
.orders-tabs a.active::after {
    content: "";
    position: absolute;
    left: 0;
    bottom: -6px;
    width: 100%;
    height: 2px;
    background: #4b49ac;
}

/* Table */
.table-box {
    background: #fff;
    border-radius: 15px;
    padding: 20px;
}

/* Status */
.status {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
}

/* FULL WIDTH PROGRESS */
.progress-wrapper {
    padding: 0 20px;
    margin-bottom: 8px;
}

.progress-track {
    width: 100%;
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #4b49ac;
    border-radius: 10px;
}
.order-number {
    font-weight: 400;
    font-size: 12px;
    color: #6B7280;
}
.order-details {
    font-weight: 400;
    font-size: 14px;
    color: #130E2B;
    text-decoration: none;
}
.order-placed {
    font-size: 12px;
    font-weight: 400;
    color: #6B7280;
}
.order-placed-deatils {
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
}
button.track-package-btn {
    background-color: #33319A;
    border-radius: 8px;
    color: #fff;
    font-weight: 400;
    font-size: 14px;
    padding: 0px 20px;
    border: none;
}
button.track-package-btn:hover {
    background-color: #05B942;
}
button.track-package-cancel-btn {
    background-color: transparent;
    border-radius: 8px;
    color: #130E2B;
    font-weight: 400;
    font-size: 14px;
    padding: 0px 20px;
    border: 1px solid #E3E3EF;
}
button.track-package-cancel-btn:hover {
    background-color:#33319A;
    color: #fff;
}
h6.processing-text {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}
span.delivery-time {
    font-weight: 600;
    color: #130E2B;
    font-size: 14px;
}
span.order-quantity {
    background-color: #F5F5FF;
    font-size: 12px;
    font-weight: 400;
    color: #404040;
    padding: 2px 12px;
    border-radius: 4px;
}
span.order-progess {
    font-size: 12px;
    font-weight: 400;
    color: #130E2B;
}
p.order-product-name {
    margin-bottom: 4px;
    font-size: 16px;
    font-weight: 400;
    color: #130E2B;
}
.order-details:hover {
    color: #05B942;
}

.product-order-img img {
  width: 100px;
  height: 100px;
  object-fit: contain;
}

.product-order-img{
  border: 1px solid #E3E3EF;
  border-radius: 10px;
}

.section-header {
    background: #F3F3FA;
    padding: 12px 20px;
    border-top-left-radius: 15px;
    border-top-right-radius: 15px;
    font-weight: 400;
    font-size: 18px;
    color: #130E2B;
}

.progress-labels {
  display: flex;
  justify-content: space-between;
  margin-top: 8px;
  font-size: 12px;
  color: #130E2B;
}

.progress-labels span {
  flex: 1;
  text-align: center;
}
</style>
