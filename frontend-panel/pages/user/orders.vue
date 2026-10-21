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
          v-if="fetchingOrderData && !orderedList"
          :rows="4"
          heading
          :label="$t('myOrders.loading')"
        />

        <div v-else class="orders-wrapper">

          <!-- Heading, search and the status filter. -->
          <header class="ord-head">
            <div class="ord-head-top">
              <div>
                <h1 class="ord-title">{{ $t('myOrders.title') }}</h1>
                <p class="ord-sub">{{ $t('myOrders.sub') }}</p>
              </div>

              <label class="ord-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only">{{ $t('myOrders.searchLabel') }}</span>
                <input
                  v-model.trim="searchText"
                  type="search"
                  :placeholder="$t('myOrders.searchLabel')"
                  autocomplete="off"
                  @input="searchChanged"
                >
              </label>
            </div>

            <!--
              Pills that scroll sideways on a phone. The counts come from the
              server over every page of orders (OrderState), not from the one
              page the browser holds, so they match what each tab lists.
            -->
            <div class="ord-tabs" role="tablist" :aria-label="$t('myOrders.tabsLabel')">
              <button
                v-for="tab in tabs"
                :key="tab"
                type="button"
                role="tab"
                class="ord-tab"
                :class="{ active: activeTab === tab }"
                :aria-selected="activeTab === tab ? 'true' : 'false'"
                @click="selectTab(tab)"
              >
                {{ $t('myOrders.tabs.' + tab) }}
                <span class="ord-tab-count">{{ counts[tab] || 0 }}</span>
              </button>
            </div>
          </header>

          <div v-if="loadError" class="ord-empty" role="alert">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <p class="ord-empty-title">{{ $t('myOrders.loadFailed') }}</p>
            <button type="button" class="ord-btn ord-btn-ghost" @click="fetchingData">{{ $t('myOrders.retry') }}</button>
          </div>

          <div v-else-if="currentOrders.length" class="ord-list" :class="{ 'is-loading': fetchingOrderData }" :aria-busy="fetchingOrderData ? 'true' : 'false'">
            <article
              v-for="order in currentOrders"
              :key="order.id"
              class="ord-card"
            >
              <!-- number, statuses, and the three facts -->
              <header class="ord-card-head">
                <div class="ord-card-id">
                  <span class="ord-kicker">{{ $t('myOrders.order') }}</span>
                  <nuxt-link :to="`/user/order/${order.id}`" class="ord-number">
                    #{{ order.order }}
                  </nuxt-link>
                </div>

                <!--
                  Order, return and refund status are separate facts, so each
                  has its own badge: a cancelled order that was refunded shows
                  both, a delivered order with a return stays "Delivered".
                -->
                <div class="ord-badges">
                  <span class="ord-pill" :class="`ord-pill-${statusInfo(order).tone}`">
                    {{ statusInfo(order).label }}
                  </span>
                  <span v-if="returnInfo(order)" class="ord-pill" :class="`ord-pill-${returnInfo(order).tone}`">
                    {{ returnInfo(order).label }}
                  </span>
                  <span v-if="refundInfo(order)" class="ord-pill" :class="`ord-pill-${refundInfo(order).tone}`">
                    {{ refundInfo(order).label }}
                  </span>
                </div>

                <dl class="ord-facts">
                  <div>
                    <dt>{{ $t('myOrders.placed') }}</dt>
                    <dd :title="order.created">{{ placedDate(order) }}</dd>
                  </div>
                  <div>
                    <dt>{{ $t('myOrders.total') }}</dt>
                    <dd class="ord-total">{{ money(order.total_amount) }}</dd>
                  </div>
                  <div>
                    <dt>{{ isCollection(order) ? $t('myOrders.fulfilment') : $t('myOrders.shipTo') }}</dt>
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
                        :title="item.product && item.product.title"
                        :alt="item.product && item.product.title"
                      />
                    </nuxt-link>

                    <div class="ord-item-text">
                      <nuxt-link :to="productLink(item.product)" class="ord-item-title">
                        {{ item.product && item.product.title }}
                      </nuxt-link>
                      <span class="ord-item-meta">
                        {{ $t('myOrders.qtyEach', { qty: item.quantity, price: money(item.selling) }) }}
                      </span>
                    </div>
                  </li>
                </ul>

                <!--
                  Five steps with the current one marked, for an order still
                  on its way. On a phone only the current step is named.
                -->
                <ol
                  v-if="statusInfo(order).step && !statusInfo(order).delivered"
                  class="ord-steps"
                  :aria-label="$t('myOrders.progress', { step: statusInfo(order).step, label: statusInfo(order).label })"
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
                    {{ $t('myOrders.viewDetails') }}
                  </nuxt-link>
                  <a
                    :href="`/user/order/${order.id}`"
                    class="ord-link"
                    :aria-busy="invoiceLoading === order.id ? 'true' : 'false'"
                    @click.prevent="openInvoice(order)"
                  >
                    <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                    {{ invoiceLoading === order.id ? $t('myOrders.opening') : $t('myOrders.invoice') }}
                  </a>
                </div>

                <div class="ord-actions">
                  <button
                    v-if="statusInfo(order).closed || statusInfo(order).delivered"
                    type="button"
                    class="ord-btn ord-btn-primary"
                    @click="buyAgain(order)"
                  >
                    {{ $t('myOrders.buyAgain') }}
                  </button>

                  <template v-else>
                    <button
                      type="button"
                      class="ord-btn ord-btn-primary"
                      @click="goToTrack(order)"
                    >
                      <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                      {{ $t('myOrders.track') }}
                    </button>

                    <!-- Only while the shop can still stop it: cancelOrder refuses anything past Pending. -->
                    <button
                      v-if="canCancel(order)"
                      type="button"
                      class="ord-btn ord-btn-ghost"
                      @click="openCancelPopup(order)"
                    >
                      {{ $t('myOrders.cancelOrder') }}
                    </button>
                  </template>
                </div>
              </footer>
            </article>
          </div>

          <div v-else-if="fetchingOrderData" class="ord-empty" aria-busy="true">
            <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
            <p class="ord-sub">{{ $t('myOrders.loading') }}</p>
          </div>

          <div v-else class="ord-empty">
            <i class="fa-solid fa-box-open" aria-hidden="true"></i>
            <template v-if="searchText">
              <p class="ord-empty-title">{{ $t('myOrders.noMatch') }}</p>
              <p class="ord-sub">{{ $t('myOrders.noMatchSub') }}</p>
              <button type="button" class="ord-btn ord-btn-ghost" @click="clearFilters">
                {{ $t('myOrders.showAll') }}
              </button>
            </template>
            <template v-else-if="activeTab !== 'all' && counts.all">
              <p class="ord-empty-title">{{ $t('myOrders.empty.' + activeTab) }}</p>
              <button type="button" class="ord-btn ord-btn-ghost" @click="clearFilters">
                {{ $t('myOrders.showAll') }}
              </button>
            </template>
            <template v-else>
              <p class="ord-empty-title">{{ $t('myOrders.noneTitle') }}</p>
              <p class="ord-sub">{{ $t('myOrders.noneSub') }}</p>
              <nuxt-link to="/" class="ord-btn ord-btn-primary">{{ $t('myOrders.startShopping') }}</nuxt-link>
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
          <!-- keyed: a new tab or search starts its own paging from the URL -->
          <pagination
            v-if="!changedSelectedOrder"
            :key="paginationKey"
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

  // The tabs, in order; the server (OrderState::TABS) decides what each holds.
  const TABS = ['all', 'processing', 'shipped', 'delivered', 'cancelled', 'returns']

  export default {
    middleware: ['common-middleware'],
    head() {
      return {
        title: this.$t('myOrders.title'),
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
        fetchingOrderData: true,
        loadError: false,
        changedSelectedOrder: false,
        rateProductId: 0,
        rateOrderId: 0,
        orderParams: {},
        orders: [],
        tabs: TABS,
        activeTab: TABS.includes(this.$route.query.tab) ? this.$route.query.tab : 'all',
        /*
         * The search box. Not routeParamHelper's `search`, which
         * settingRouteParam() resets from the URL on every fetch: the box was
         * wiped before its text could reach the server, so search only ever
         * filtered the one page already loaded.
         */
        searchText: this.$route.query.q || '',
        searchTimer: null,
        // the newest request: an older answer that lands later is ignored
        requestSeq: 0
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
      totalPage() {
        return this.orderedList?.last_page
      },
      // Only the answer for the tab on screen: never another tab's orders.
      currentOrders() {
        return this.orderedList && this.orderedList.tab === this.activeTab ? (this.orderedList.data || []) : []
      },
      counts() {
        return this.orderedList?.counts || {}
      },
      paginationKey() {
        return this.activeTab + '|' + this.searchText
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('order', ['orderedList']),
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting'])
    },
    methods: {
      money(value) {
        return this.priceFormat(this.currencyPosition, this.currencyIcon, Number(value || 0).toFixed(2), this.setting, 2)
      },
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
          this.setToastError(this.$t('myOrders.invoiceFailed'))
        } finally {
          this.invoiceLoading = null
        }
      },
      /*
       * The order's own status, from the server's state (OrderState).
       *
       * Cancelled is an order status; Refunded is a refund status. This used
       * to put every "closed" status (6 Cancelled, 7 Returned, 8 Refunded)
       * under Cancelled, and a refund moved a cancelled order to 8, so the
       * Cancelled tab showed "Refunded". Each fact now has its own badge.
       */
      statusInfo(order) {
        const st = order?.state || {}
        const key = st.order || 'processing'
        const collect = this.isCollection(order)

        switch (key) {
          case 'cancelled':
            return {key, label: this.$t('myOrders.status.cancelled'), tone: 'bad', closed: true}
          case 'returned':
            return {key, label: this.$t('myOrders.status.returned'), tone: 'muted', closed: true}
          case 'refunded':
            return {key, label: this.$t('myOrders.status.refunded'), tone: 'muted', closed: true}
          case 'delivered':
            return {key, label: this.$t(collect ? 'myOrders.status.collected' : 'myOrders.status.delivered'), tone: 'good', delivered: true, step: 5}
          case 'shipped':
            return {key, label: this.stepLabels(order)[(st.step || 4) - 1], tone: 'ship', step: st.step || 4}
          default:
            return {key: 'processing', label: this.stepLabels(order)[(st.step || 1) - 1] || this.$t('myOrders.status.processing'), tone: 'info', step: st.step || 1}
        }
      },
      // The latest return or exchange on the order, if there is one.
      returnInfo(order) {
        const r = order?.state?.return
        if (!r) return null

        const tone = {completed: 'good', rejected: 'bad', withdrawn: 'muted'}[r.key] || 'warn'
        const kind = this.$t(r.type === 'exchange' ? 'myOrders.exchangeKind' : 'myOrders.returnKind')

        return {key: r.key, tone, label: `${kind}: ${this.$t('myOrders.return.' + r.key)}`}
      },
      // Money owed or sent back, only as the server's records say.
      refundInfo(order) {
        const r = order?.state?.refund
        if (!r) return null

        const tone = {completed: 'good', partial: 'good', failed: 'bad'}[r.key] || 'warn'

        return {key: r.key, tone, label: this.$t('myOrders.refund.' + r.key), amount: r.amount}
      },
      // Date only: the time made the column too wide on a phone and is on the order page.
      placedDate(order) {
        const d = new Date(order?.created_at)
        return isNaN(d.getTime())
          ? order?.created
          : d.toLocaleDateString(this.langCode || 'en-IE', {day: 'numeric', month: 'short', year: 'numeric'})
      },
      // Every line on an order shares one fulfilment type; 2 is collection.
      isCollection(order) {
        return parseInt(order?.ordered_products?.[0]?.shipping_type) === 2
      },
      shipLabel(order) {
        if (this.isCollection(order)) return this.$t('myOrders.clickCollect')
        return order?.shipping_name || order?.contact_name || '—'
      },
      stepLabels(order) {
        return ['placed', 'confirmed', 'pickedUp', 'onTheWay', this.isCollection(order) ? 'collected' : 'delivered']
          .map(k => this.$t('myOrders.steps.' + k))
      },
      canCancel(order) {
        return parseInt(order?.status) === 1 && order?.state?.order === 'processing'
      },
      /*
       * The line under the badges. For a closed order or one with a return,
       * what is happening with it; otherwise the delivery estimate (placed
       * date plus the slowest line's delivery time, as the tracking page does).
       */
      etaInfo(order) {
        const info = this.statusInfo(order)
        const ret = order?.state?.return
        const refund = order?.state?.refund
        const amount = refund && refund.amount ? this.money(refund.amount) : null
        const collect = this.isCollection(order)
        const t = (k, p) => this.$t('myOrders.msg.' + k, p)

        if (info.key === 'cancelled') {
          if (refund && refund.key === 'completed') return {tone: 'good', icon: 'fa-circle-check', html: amount ? t('cancelledRefundedAmount', {amount}) : t('cancelledRefunded')}
          if (refund && refund.key === 'failed') return {tone: 'bad', icon: 'fa-circle-exclamation', html: t('cancelledRefundFailed')}
          if (refund) return {tone: 'warn', icon: 'fa-hourglass-half', html: t('cancelledRefundPending')}
          return {tone: 'bad', icon: 'fa-circle-xmark', html: t('cancelled')}
        }

        if (refund && refund.key === 'failed') return {tone: 'bad', icon: 'fa-circle-exclamation', html: t('refundFailed')}
        if (refund && refund.key === 'processing') return {tone: 'warn', icon: 'fa-hourglass-half', html: t('refundProcessing')}

        if (ret) {
          if (ret.key === 'requested') return {tone: 'warn', icon: 'fa-hourglass-half', html: t('returnRequested')}
          if (ret.key === 'approved') return {tone: 'warn', icon: 'fa-box', html: t('returnApproved', {rma: ret.rma_number})}
          if (ret.key === 'item_received') return {tone: 'warn', icon: 'fa-hourglass-half', html: t('returnReceived')}
          if (ret.key === 'awaiting_payment') return {tone: 'warn', icon: 'fa-credit-card', html: t('exchangePayment')}
          if (ret.key === 'rejected') return {tone: 'bad', icon: 'fa-circle-xmark', html: t('returnRejected')}
          if (ret.key === 'completed' && refund && ['completed', 'partial'].includes(refund.key)) {
            return {tone: 'good', icon: 'fa-circle-check', html: amount ? t('returnRefundedAmount', {amount}) : t('returnRefunded')}
          }
          if (ret.key === 'completed' && ret.type === 'exchange') return {tone: 'good', icon: 'fa-circle-check', html: t('exchangeDone')}
        }

        if (info.key === 'returned') return {tone: 'muted', icon: 'fa-rotate-left', html: t('returned')}
        if (info.key === 'refunded') return {tone: 'good', icon: 'fa-circle-check', html: t('refunded')}

        if (info.delivered) {
          return {tone: 'good', icon: 'fa-circle-check', html: collect ? t('collected') : t('delivered')}
        }

        const days = Math.max(1, ...(order?.ordered_products || [])
          .map(l => parseInt(l?.shipping_place?.day_needed) || 0))
        const placed = new Date(order?.created_at)

        if (isNaN(placed.getTime())) {
          return {tone: 'info', icon: 'fa-clock', html: collect ? t('collectSoon') : t('soon')}
        }

        const due = new Date(placed.getFullYear(), placed.getMonth(), placed.getDate() + days)
        const today = new Date()
        today.setHours(0, 0, 0, 0)
        const when = `<strong>${due.toLocaleDateString(this.langCode || 'en-IE', {weekday: 'short', day: 'numeric', month: 'short'})}</strong>`

        if (due < today) {
          return {tone: 'warn', icon: 'fa-triangle-exclamation', html: t('late', {when})}
        }

        return {
          tone: 'info',
          icon: collect ? 'fa-store' : 'fa-truck-fast',
          html: collect ? t('readyBy', {when}) : t('arriving', {when})
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
      // A tab, or a new search, starts again at page 1; both live in the URL.
      setQuery(changes) {
        const query = {...this.$route.query, ...changes, page: 1}
        Object.keys(query).forEach(k => { if (query[k] === '' || query[k] === null || query[k] === undefined) delete query[k] })
        if (query.tab === 'all') delete query.tab
        return this.$router.replace({query}).catch(() => {})
      },
      async selectTab(tab) {
        if (tab === this.activeTab) return
        this.activeTab = tab
        await this.setQuery({tab})
        this.fetchingData()
      },
      searchChanged() {
        clearTimeout(this.searchTimer)
        this.searchTimer = setTimeout(async () => {
          await this.setQuery({q: this.searchText})
          this.fetchingData()
        }, 350)
      },
      async clearFilters() {
        this.searchText = ''
        this.activeTab = 'all'
        await this.setQuery({q: null, tab: null})
        this.fetchingData()
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
      /*
       * The server does the filtering, the counting and the paging (tab, q,
       * page). Requests are numbered so that switching tabs quickly never
       * leaves an older tab's answer on screen.
       */
      async fetchingData() {
        const seq = ++this.requestSeq
        this.fetchingOrderData = true
        this.loadError = false

        try {
          this.settingRouteParam()
          const data = await this.getOrderByUser({
            payload: {
              time_zone: this.timeZone,
              order_by: this.orderBy,
              type: this.orderByType,
              page: this.page,
              tab: this.activeTab,
              q: this.searchText,
              user_token: await this.getUserToken(),
            },
            lang: this.langCode
          })

          if (seq !== this.requestSeq) return

          if (data?.status !== 200) {
            this.loadError = true
            this.hasError(data)
          } else if (this.orderedList && this.orderedList.tab !== this.activeTab) {
            // an older request's answer was committed last: ask again for this tab
            return this.fetchingData()
          }
        } catch (e) {
          if (seq === this.requestSeq) this.loadError = true
        }

        if (seq === this.requestSeq) {
          this.changedSelectedOrder = false
          this.fetchingOrderData = false
        }
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('common', ['setToastError']),
      ...mapActions('order', ['getOrderByUser']),
    },

    async mounted(){
      await this.fetchingData()
      window.scrollTo(0,0);
    },
    beforeDestroy() {
      clearTimeout(this.searchTimer)
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
.ord-pill-warn { background: #FFF4DB; color: #B45309; }
.ord-pill-ship { background: #F1EAFE; color: #6D28D9; }
.ord-pill-muted { background: #F0F0F6; color: #4B5563; }

/* order, return and refund status side by side; they wrap on a phone */
.ord-badges {
  grid-area: pill;
  justify-self: end;
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 6px;
}
.ord-badges .ord-pill { grid-area: auto; justify-self: auto; }
.ord-eta-muted { background: #F5F5F8; }
.ord-eta-muted i { color: #4B5563; }
.ord-list.is-loading { opacity: .55; pointer-events: none; transition: opacity .15s; }

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
