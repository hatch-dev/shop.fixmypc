<template>
  <client-only>
    <account-layout
      active-route="orders"
      @clicked-orders="loadData"
      class="mb-5"
    >
      <template v-slot:rightArea>

        <!--
          This page had no live loading state: the only spinner in it sits
          inside a commented-out block further down, so the first paint showed
          the tabs with every count at zero and "No Orders Found" underneath,
          before any order had been fetched.
        -->
        <account-skeleton
          v-if="fetchingOrderData"
          :rows="4"
          heading
          label="Loading your orders"
        />

        <div v-else class="orders-wrapper">

          <!-- Heading, search and the status filter. -->
          <header class="ord-head">
            <div class="ord-head-top">
              <div>
                <h1 class="ord-title">My Orders</h1>
                <p class="ord-sub">View and manage your recent orders.</p>
              </div>

              <label class="ord-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only">Search by order number</span>
                <input
                  v-model.trim="search"
                  type="search"
                  placeholder="Search by order number"
                  autocomplete="off"
                >
              </label>
            </div>

            <!--
              Pills that scroll sideways on a phone. They used to wrap onto a
              second line, which read as two separate rows of filters.
            -->
            <div class="ord-tabs" role="tablist" aria-label="Filter orders by status">
              <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                class="ord-tab"
                :class="{ active: activeTab === tab.key }"
                :aria-selected="activeTab === tab.key ? 'true' : 'false'"
                @click="activeTab = tab.key"
              >
                {{ tab.label }}
                <span class="ord-tab-count">{{ countByStatus(tab.key) }}</span>
              </button>
            </div>
          </header>

          <div v-if="filteredOrders.length" class="ord-list">
            <article
              v-for="order in filteredOrders"
              :key="order.id"
              class="ord-card"
            >
              <!-- number, status, and the three facts -->
              <header class="ord-card-head">
                <div class="ord-card-id">
                  <span class="ord-kicker">Order</span>
                  <nuxt-link :to="`/user/order/${order.id}`" class="ord-number">
                    #{{ order.order }}
                  </nuxt-link>
                </div>

                <span class="ord-pill" :class="`ord-pill-${statusInfo(order).tone}`">
                  {{ statusInfo(order).label }}
                </span>

                <dl class="ord-facts">
                  <div>
                    <dt>Placed</dt>
                    <dd :title="order.created">{{ placedDate(order) }}</dd>
                  </div>
                  <div>
                    <dt>Total</dt>
                    <dd class="ord-total">&euro;{{ Number(order.total_amount || 0).toFixed(2) }}</dd>
                  </div>
                  <div>
                    <dt>{{ isCollection(order) ? 'Fulfilment' : 'Ship to' }}</dt>
                    <dd>{{ shipLabel(order) }}</dd>
                  </div>
                </dl>
              </header>

              <div class="ord-card-body">
                <p class="ord-eta" :class="`ord-eta-${etaInfo(order).tone}`">
                  <i class="fa-solid" :class="etaInfo(order).icon" aria-hidden="true"></i>
                  <span v-html="etaInfo(order).html"></span>
                </p>

                <ul class="ord-items">
                  <li
                    v-for="item in order.ordered_products"
                    :key="item.id"
                    class="ord-item"
                  >
                    <nuxt-link :to="productLink(item.product)" class="ord-item-img">
                      <lazy-image
                        :data-src="thumbImageURL(item.product)"
                        :title="item.product.title"
                        :alt="item.product.title"
                      />
                    </nuxt-link>

                    <div class="ord-item-text">
                      <nuxt-link :to="productLink(item.product)" class="ord-item-title">
                        {{ item.product.title }}
                      </nuxt-link>
                      <span class="ord-item-meta">
                        Qty {{ item.quantity }}
                        <span aria-hidden="true">·</span>
                        &euro;{{ Number(item.selling || 0).toFixed(2) }} each
                      </span>
                    </div>
                  </li>
                </ul>

                <!--
                  Five steps with the current one marked. On a phone only the
                  current step is named; five labels at 10px did not fit.
                -->
                <ol
                  v-if="statusInfo(order).step"
                  class="ord-steps"
                  :aria-label="`Progress: step ${statusInfo(order).step} of 5, ${statusInfo(order).label}`"
                >
                  <li
                    v-for="(label, i) in stepLabels(order)"
                    :key="i"
                    class="ord-step"
                    :class="{
                      'is-done': i + 1 < statusInfo(order).step,
                      'is-current': i + 1 === statusInfo(order).step
                    }"
                  >
                    <span class="ord-step-dot" aria-hidden="true"></span>
                    <span class="ord-step-label">{{ label }}</span>
                  </li>
                </ol>
              </div>

              <footer class="ord-card-foot">
                <div class="ord-links">
                  <nuxt-link :to="`/user/order/${order.id}`" class="ord-link">
                    <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
                    View details
                  </nuxt-link>
                  <a
                    :href="`/user/order/${order.id}`"
                    class="ord-link"
                    :aria-busy="invoiceLoading === order.id ? 'true' : 'false'"
                    @click.prevent="openInvoice(order)"
                  >
                    <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                    {{ invoiceLoading === order.id ? 'Opening…' : 'Invoice' }}
                  </a>
                </div>

                <div class="ord-actions">
                  <!--
                    "Buy Again" had no handler and did nothing. It now opens
                    the product, or the order when there is more than one.
                  -->
                  <button
                    v-if="statusInfo(order).closed || statusInfo(order).delivered"
                    type="button"
                    class="ord-btn ord-btn-primary"
                    @click="buyAgain(order)"
                  >
                    Buy again
                  </button>

                  <template v-else>
                    <button
                      type="button"
                      class="ord-btn ord-btn-primary"
                      @click="goToTrack(order)"
                    >
                      <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                      Track
                    </button>

                    <button
                      type="button"
                      class="ord-btn ord-btn-ghost"
                      @click="openCancelPopup(order)"
                    >
                      Cancel order
                    </button>
                  </template>
                </div>
              </footer>
            </article>
          </div>

          <div v-else class="ord-empty">
            <i class="fa-solid fa-box-open" aria-hidden="true"></i>
            <template v-if="(currentOrders || []).length">
              <p class="ord-empty-title">No orders match</p>
              <p class="ord-sub">Try another filter or clear the search.</p>
              <button type="button" class="ord-btn ord-btn-ghost" @click="search = ''; activeTab = 'all'">
                Show all orders
              </button>
            </template>
            <template v-else>
              <p class="ord-empty-title">You have not placed an order yet</p>
              <p class="ord-sub">Everything you buy will be listed here.</p>
              <nuxt-link to="/" class="ord-btn ord-btn-primary">Start shopping</nuxt-link>
            </template>
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
  import AccountSkeleton from '~/components/AccountSkeleton'
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
        // the order whose invoice PDF is being fetched
        invoiceLoading: null,
        selectedOrderId: null,
        payNowOrder: null,
        deactivate: true,
        // True until the first fetch settles; see the skeleton in the template.
        fetchingOrderData: true,
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
      AccountSkeleton,
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
          data = data.filter(order => this.matchesTab(order, this.activeTab))
        }

        if (this.search) {
          // "#2026..." as it is printed works as well as the bare number
          const q = this.search.replace(/^#/, '').toLowerCase()
          data = data.filter(o => String(o.order || '').toLowerCase().includes(q))
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
      /*
       * "Invoice" was href="#". The PDF is the one the order confirmation
       * email attaches, served by /api/v1/user/order/{id}/invoice to the
       * signed-in owner only. It needs the bearer token, so it cannot be a
       * plain link: fetch it, then show it in a new tab.
       *
       * The tab is opened before the request, while the click still counts
       * as a user gesture; opening it after the await gets it blocked as a
       * popup. If the browser refuses the tab anyway, the file downloads.
       */
      async openInvoice(order) {
        if (this.invoiceLoading) return

        this.invoiceLoading = order.id
        const tab = window.open('', '_blank')

        try {
          const token = this.$auth?.strategy?.token?.get()
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const response = await this.$axios.get(`${baseUrl}api/v1/user/order/${order.id}/invoice`, {
            headers: {Authorization: token, Accept: 'application/pdf'},
            responseType: 'blob'
          })

          const url = URL.createObjectURL(new Blob([response.data], {type: 'application/pdf'}))

          if (tab && !tab.closed) {
            tab.location.href = url
          } else {
            const a = document.createElement('a')
            a.href = url
            a.download = `Invoice-${order.order}.pdf`
            document.body.appendChild(a)
            a.click()
            a.remove()
          }

          // Long enough for the tab to load it.
          setTimeout(() => URL.revokeObjectURL(url), 60000)

        } catch (e) {
          if (tab && !tab.closed) tab.close()
          this.setToastError('We could not open the invoice just now. Please try again.')
        } finally {
          this.invoiceLoading = null
        }
      },
      /*
       * One reading of an order's state for the whole page.
       *
       * orderStatus only knows 1-5, and the template read
       * orderStatus[order.status].title directly in six places, so a
       * returned (7), refunded (8) or status-6 cancelled order threw and
       * took the list down. A cancelled order can also keep its old status
       * and carry the cancelled flag instead.
       */
      statusInfo(order) {
        const code = parseInt(order?.status)

        if (parseInt(order?.cancelled) === 1 || code === 6) {
          return {label: 'Cancelled', tone: 'bad', closed: true}
        }
        if (code === 7) return {label: 'Returned', tone: 'bad', closed: true}
        if (code === 8) return {label: 'Refunded', tone: 'bad', closed: true}
        if (code === 5) {
          return {label: this.isCollection(order) ? 'Collected' : 'Delivered', tone: 'good', delivered: true}
        }

        return {label: this.orderStatus[code]?.title || 'Pending', tone: 'info', step: code >= 1 && code <= 4 ? code : 1}
      },
      // Date only: the time made the column too wide on a phone and is on the order page.
      placedDate(order) {
        const d = new Date(order?.created_at)
        return isNaN(d.getTime())
          ? order?.created
          : d.toLocaleDateString('en-IE', {day: 'numeric', month: 'short', year: 'numeric'})
      },
      // Every line on an order shares one fulfilment type; 2 is collection.
      isCollection(order) {
        return parseInt(order?.ordered_products?.[0]?.shipping_type) === 2
      },
      shipLabel(order) {
        if (this.isCollection(order)) return 'Click & Collect'
        return order?.shipping_name || order?.contact_name || '—'
      },
      stepLabels(order) {
        return ['Placed', 'Confirmed', 'Picked up', 'On the way', this.isCollection(order) ? 'Collected' : 'Delivered']
      },
      /*
       * The line under the status. "Estimated Delivery: Soon" was printed for
       * every order: deliveryText compared the numeric status with the
       * string 'delivered', so it never said anything else. The estimate is
       * the placed date plus the slowest line's delivery time, the same rule
       * the tracking page uses, and a missed date says so.
       */
      etaInfo(order) {
        const info = this.statusInfo(order)
        const collect = this.isCollection(order)

        if (info.closed) {
          const text = {
            Cancelled: 'This order was cancelled.',
            Returned: 'This order was returned.',
            Refunded: 'This order has been refunded.'
          }[info.label]
          return {tone: 'bad', icon: 'fa-circle-xmark', html: text}
        }

        if (info.delivered) {
          return {
            tone: 'good',
            icon: 'fa-circle-check',
            html: collect ? 'You collected this order.' : 'This order has been delivered.'
          }
        }

        const days = Math.max(1, ...(order?.ordered_products || [])
          .map(l => parseInt(l?.shipping_place?.day_needed) || 0))
        const placed = new Date(order?.created_at)

        if (isNaN(placed.getTime())) {
          return {tone: 'info', icon: 'fa-clock', html: collect ? 'We will tell you when it is ready.' : 'On its way to you soon.'}
        }

        const due = new Date(placed.getFullYear(), placed.getMonth(), placed.getDate() + days)
        const today = new Date()
        today.setHours(0, 0, 0, 0)
        const when = due.toLocaleDateString('en-IE', {weekday: 'short', day: 'numeric', month: 'short'})

        if (due < today) {
          return {tone: 'warn', icon: 'fa-triangle-exclamation', html: `Running late: it was due <strong>${when}</strong>`}
        }

        return {
          tone: 'info',
          icon: collect ? 'fa-store' : 'fa-truck-fast',
          html: `${collect ? 'Ready to collect by' : 'Arriving by'} <strong>${when}</strong>`
        }
      },
      // One product: open it. Several: the order, where each one links.
      buyAgain(order) {
        const products = (order?.ordered_products || []).map(l => l.product).filter(Boolean)
        const unique = [...new Map(products.map(p => [p.id, p])).values()]

        if (unique.length === 1) {
          this.$router.push(this.productLink(unique[0]))
        } else {
          this.$router.push(`/user/order/${order.id}`)
        }
      },
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
        return status === 'all' ? data.length : data.filter(o => this.matchesTab(o, status)).length
      },
      /*
       * Which tab an order belongs to. Cancelled covers returned and
       * refunded too (statuses 6-8, or the cancelled flag): those used to
       * fall into no tab at all, and the count missed them.
       */
      matchesTab(order, tab) {
        const info = this.statusInfo(order)

        if (tab === 'all') return true
        if (tab === 'cancelled') return !!info.closed
        if (info.closed) return false
        if (tab === 'delivered') return !!info.delivered
        if (tab === 'processing') return [1, 2].includes(info.step)
        if (tab === 'shipped') return [3, 4].includes(info.step)
        return false
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
      ...mapActions('common', ['setToastError']),
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
/*
 * My Orders.
 *
 * Same scale as the dashboard: type and spacing are fluid (clamp), and the
 * breakpoints only change layout. Buttons set their own height and
 * line-height, and hover backgrounds are !important, because base.styl gives
 * every button 50px / 48px and a global hover that outranks scoped rules.
 */

.orders-wrapper {
  --ink: #130E2B;
  --body: #374151;
  --muted: #6B7280;
  --line: #E3E3EF;
  --line-soft: #F0F0F6;
  --head: #F7F7FC;
  --brand: #33319A;
  --brand-soft: #EEEEFA;
  --radius: 16px;
  --pad: clamp(16px, .9rem + .6vw, 24px);
  --gap: clamp(12px, .6rem + .8vw, 20px);
  color: var(--body);
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* ---------- heading, search, tabs ---------- */

.ord-head {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: var(--pad) var(--pad) 0;
  margin-bottom: var(--gap);
}

.ord-head-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 14px 24px;
  flex-wrap: wrap;
}

.ord-title {
  font-size: clamp(22px, 1.1rem + 1.2vw, 30px);
  font-weight: 700;
  line-height: 1.2;
  color: var(--ink);
  margin: 0 0 4px;
}

.ord-sub {
  font-size: clamp(13px, .8rem + .15vw, 14.5px);
  color: var(--muted);
  margin: 0;
}

.ord-search {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 0 1 300px;
  min-width: 220px;
  height: 44px;
  padding: 0 14px;
  margin: 0;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #F8F9FD;
  color: var(--muted);
  cursor: text;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.ord-search:focus-within {
  border-color: var(--brand);
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .12);
  background: #fff;
}

.ord-search input {
  flex: 1 1 auto;
  min-width: 0;
  height: 100%;
  border: 0;
  outline: none;
  background: transparent;
  color: var(--ink);
  font-size: 15px;
}

.ord-tabs {
  display: flex;
  gap: 8px;
  margin-top: clamp(14px, .8rem + .5vw, 20px);
  padding-bottom: var(--pad);
  overflow-x: auto;
  scrollbar-width: none;
}

.ord-tabs::-webkit-scrollbar { display: none; }

.ord-tab {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  line-height: 1;
  padding: 0 14px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: #fff;
  color: var(--body);
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  transition: background-color .15s ease, color .15s ease, border-color .15s ease;
}

.ord-tab:hover {
  background: #F5F5FF !important;
  border-color: #C9CAE6;
  color: var(--brand);
}

.ord-tab.active,
.ord-tab.active:hover {
  background: var(--brand) !important;
  border-color: var(--brand);
  color: #fff;
}

.ord-tab-count {
  min-width: 22px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #F0F0F7;
  color: var(--muted);
  font-size: 12px;
}

.ord-tab.active .ord-tab-count {
  background: rgba(255, 255, 255, .22);
  color: #fff;
}

/* ---------- order card ---------- */

.ord-list {
  display: grid;
  gap: var(--gap);
}

.ord-card {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: var(--radius);
  overflow: hidden;
  transition: box-shadow .2s ease, border-color .2s ease;
}

.ord-card:hover {
  border-color: #D5D6EA;
  box-shadow: 0 8px 24px rgba(19, 14, 43, .06);
}

.ord-card-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  grid-template-areas:
    "id    pill"
    "facts facts";
  gap: 12px 16px;
  align-items: center;
  padding: var(--pad);
  padding-bottom: 14px;
  background: var(--head);
  border-bottom: 1px solid var(--line-soft);
}

.ord-card-id { grid-area: id; min-width: 0; }

.ord-kicker {
  display: block;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 2px;
}

.ord-number {
  font-size: clamp(15px, .85rem + .35vw, 18px);
  font-weight: 700;
  color: var(--ink);
  text-decoration: none;
  white-space: nowrap;
}

.ord-number:hover { color: var(--brand); text-decoration: underline; }

.ord-pill {
  grid-area: pill;
  justify-self: end;
  font-size: 12.5px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 999px;
  white-space: nowrap;
}

.ord-pill-info { background: #EBEBFF; color: var(--brand); }
.ord-pill-good { background: #E6F6EC; color: #1E7E34; }
.ord-pill-bad  { background: #FDECEA; color: #C82333; }

.ord-facts {
  grid-area: facts;
  display: flex;
  flex-wrap: wrap;
  gap: 8px 32px;
  margin: 0;
}

.ord-facts > div { min-width: 0; }

.ord-facts dt {
  font-size: 12px;
  font-weight: 500;
  color: var(--muted);
  margin-bottom: 2px;
}

.ord-facts dd {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
}

.ord-total { font-variant-numeric: tabular-nums; }

.ord-card-body {
  padding: var(--pad);
}

.ord-eta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 16px;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 14px;
  background: #F5F5FF;
  color: var(--ink);
}

.ord-eta i { color: var(--brand); }
.ord-eta-good { background: #EFF9F2; }
.ord-eta-good i { color: #1E7E34; }
.ord-eta-warn { background: #FFF7E6; }
.ord-eta-warn i { color: #B45309; }
.ord-eta-bad  { background: #FDF1F0; }
.ord-eta-bad i  { color: #C82333; }

/* items */

.ord-items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}

.ord-items li {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
  width: 100%;
}

.ord-item-img {
  flex: 0 0 72px;
  width: 72px;
  height: 72px;
  border: 1px solid #EEF0F5;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ord-item-img ::v-deep img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.ord-item-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.ord-item-title {
  font-size: 15px;
  font-weight: 600;
  line-height: 1.35;
  color: var(--ink);
  text-decoration: none;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.ord-item-title:hover { color: var(--brand); text-decoration: underline; }

.ord-item-meta {
  font-size: 13px;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

/* progress */

.ord-steps {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  list-style: none;
  margin: 20px 0 0;
  padding: 0;
}

.ord-step {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  text-align: center;
  min-width: 0;
}

/* the connector to the next step */
.ord-step:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 6px;
  left: calc(50% + 10px);
  right: calc(-50% + 10px);
  height: 3px;
  border-radius: 3px;
  background: #E5E7EB;
}

.ord-step.is-done:not(:last-child)::after { background: var(--brand); }

.ord-step-dot {
  width: 15px;
  height: 15px;
  border-radius: 50%;
  background: #fff;
  border: 3px solid #D9DBE7;
  z-index: 1;
}

.ord-step.is-done .ord-step-dot {
  background: var(--brand);
  border-color: var(--brand);
}

.ord-step.is-current .ord-step-dot {
  background: #fff;
  border-color: var(--brand);
  box-shadow: 0 0 0 4px rgba(51, 49, 154, .15);
}

.ord-step-label {
  font-size: 12.5px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

.ord-step.is-done .ord-step-label { color: var(--body); }
.ord-step.is-current .ord-step-label { color: var(--brand); font-weight: 700; }

/* footer: links and actions */

.ord-card-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 14px var(--pad);
  border-top: 1px solid var(--line-soft);
}

.ord-links {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
}

.ord-link {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 14px;
  font-weight: 600;
  color: var(--brand);
  text-decoration: none;
}

.ord-link:hover { text-decoration: underline; color: var(--brand); }

.ord-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.ord-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 40px;
  line-height: 1;
  padding: 0 18px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
  text-decoration: none;
  cursor: pointer;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}

.ord-btn-primary {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: #fff;
}

.ord-btn-primary:hover {
  background: #28267d !important;
  border-color: #28267d;
  color: #fff;
}

.ord-btn-ghost {
  background: #fff;
  border: 1px solid var(--line);
  color: var(--ink);
}

.ord-btn-ghost:hover {
  background: #F5F5FF !important;
  border-color: var(--brand);
  color: var(--brand);
}

/* empty */

.ord-empty {
  text-align: center;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 48px 20px;
}

.ord-empty i {
  font-size: 32px;
  color: #C7C7D9;
  display: block;
  margin-bottom: 12px;
}

.ord-empty-title {
  font-size: 17px;
  font-weight: 700;
  color: var(--ink);
  margin: 0 0 4px;
}

.ord-empty .ord-sub { margin-bottom: 18px; }

/* ---------- phone ---------- */

@media (max-width: 767.98px) {

  .ord-search {
    flex: 1 1 100%;
    min-width: 0;
    height: 42px;
  }

  /* let the pills run to the card edge so it is clear they scroll */
  .ord-tabs {
    margin-right: calc(var(--pad) * -1);
    padding-right: var(--pad);
  }

  .ord-facts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px 12px;
  }

  .ord-facts dd {
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .ord-eta { font-size: 13.5px; padding: 9px 12px; }

  .ord-item-img { flex-basis: 60px; width: 60px; height: 60px; }
  .ord-item-title { font-size: 14px; }

  /* dots only; the current step is named once, underneath */
  .ord-step-label { display: none; }
  .ord-step.is-current .ord-step-label {
    display: block;
    position: absolute;
    top: 24px;
    font-size: 12px;
  }
  .ord-steps { margin-bottom: 22px; }
  .ord-step:first-child.is-current .ord-step-label { left: 0; text-align: left; }
  .ord-step:last-child.is-current .ord-step-label { right: 0; text-align: right; }

  /* actions full width, links above them */
  .ord-card-foot {
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
  }

  .ord-links { justify-content: space-between; }

  .ord-actions > * { flex: 1 1 0; }
  .ord-btn { height: 44px; }
}

@media (max-width: 359.98px) {
  .ord-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  /* stacked: the row rule flex: 1 1 0 would share out height from zero */
  .ord-actions { flex-direction: column; }
  .ord-actions > * { flex: 0 0 auto; width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
  .ord-card, .ord-tab, .ord-btn, .ord-search { transition: none; }
}
</style>
