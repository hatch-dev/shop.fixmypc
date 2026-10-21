<template>
  <client-only>
    <div>
      <navigation-step />
      <div class="container-fluid my-5">
        <div class="row g-4">
          <div class="col-lg-8">
            <!--
              The Payment step is a review, not a second Details page: the
              choices made there are restated as a compact panel - who, where,
              how - each with its own Change, and the items underneath. The big
              delivery tiles belong to /shipping; here the method is changed
              with a small switch, and the address on the Details page.
            -->
            <section class="review-panel mb-3" aria-labelledby="review-panel-title">
              <header class="review-panel-head">
                <h2 id="review-panel-title" class="review-panel-title">{{ $t('ui.checkoutPage.reviewTitle') }}</h2>
                <p class="review-panel-sub">{{ $t('ui.checkoutPage.reviewSub') }}</p>
              </header>

              <dl class="review-rows">
                <!-- Ship to / Collect from -->
                <div class="review-row" :class="{ 'is-missing': !isCollection && !selectedAddress }">
                  <dt class="review-row-label">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="9.5" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>
                    {{ isCollection ? $t('ui.checkoutPage.collectFrom') : $t('ui.checkoutPage.shipTo') }}
                  </dt>
                  <dd class="review-row-value">
                    <template v-if="isCollection">
                      <template v-if="collectionPoint">
                        <span>{{ collectionPoint.lines.join(', ') }}</span>
                        <span v-if="collectionPoint.phone" class="review-muted">{{ $t('ui.checkoutPage.storePhone', { phone: collectionPoint.phone }) }}</span>
                      </template>
                      <span v-else class="review-muted">{{ $t('ui.checkoutPage.ourStore') }}</span>
                      <!-- who collects, and how we reach them when it is ready -->
                      <span v-if="contactSummary" class="review-contact">
                        <span class="review-strong">{{ contactSummary.name }}</span>
                        <span v-if="contactSummary.detail" class="review-muted">{{ contactSummary.detail }}</span>
                      </span>
                    </template>
                    <template v-else-if="selectedAddress">
                      <span>
                        <span class="review-strong">{{ selectedAddress.name }}</span>
                        <span class="review-tag">{{ selectedAddress.type || $t('ui.checkoutPage.home') }}</span>
                      </span>
                      <span>{{ addressLines.join(', ') }}</span>
                      <span v-if="shipToContact" class="review-muted">{{ shipToContact }}</span>
                    </template>
                    <span v-else class="review-warning">
                      {{ $t('ui.checkoutPage.noAddress') }}
                    </span>
                  </dd>
                  <dd class="review-row-action">
                    <button type="button" class="review-change" @click="goToShipping">
                      {{ !isCollection && !selectedAddress ? $t('ui.checkoutPage.add') : $t('ui.checkoutPage.change') }}
                    </button>
                  </dd>
                </div>

                <!-- Method -->
                <div class="review-row">
                  <dt class="review-row-label">
                    <svg v-if="isCollection" viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M4 9.5 5.5 4h13L20 9.5M4 9.5h16M4 9.5V20h16V9.5M9.5 20v-5h5v5" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M3 6h11v10H3zM14 9h4l3 3.5V16h-7" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="1.8" fill="#fff" stroke="currentColor" stroke-width="1.6"/><circle cx="17.5" cy="17.5" r="1.8" fill="#fff" stroke="currentColor" stroke-width="1.6"/></svg>
                    {{ $t('ui.checkoutPage.method') }}
                  </dt>
                  <dd class="review-row-value">
                    <span class="review-strong">{{ isCollection ? $t('ui.checkoutPage.collectionInStore') : $t('ui.checkoutPage.sendByPost') }}</span>
                    <span class="review-muted">
                      <template v-if="isCollection">{{ $t('ui.checkoutPage.readyEmail') }}</template>
                      <template v-else-if="deliveryDays">{{ $tc('ui.checkoutPage.deliveredWithin', deliveryDays, { days: deliveryDays }) }}</template>
                      <template v-else>{{ $t('ui.checkoutPage.trackedDelivery') }}</template>
                    </span>

                    <!-- the method switch, only while changing it -->
                    <div
                      v-if="editingDelivery"
                      id="delivery-options"
                      class="review-switch"
                      role="radiogroup"
                      :aria-label="$t('ui.checkoutPage.deliveryOrCollection')"
                    >
                      <button
                        type="button"
                        role="radio"
                        :aria-checked="String(deliveryType === 'delivery')"
                        :class="['review-switch-opt', { active: deliveryType === 'delivery' }]"
                        @click="setDeliveryType('delivery')"
                      >
                        {{ $t('ui.checkoutPage.sendByPost') }}
                      </button>
                      <button
                        type="button"
                        role="radio"
                        :aria-checked="String(deliveryType === 'collection')"
                        :class="['review-switch-opt', { active: deliveryType === 'collection' }]"
                        @click="setDeliveryType('collection')"
                      >
                        {{ $t('ui.checkoutPage.collectInStore') }} &middot; {{ $t('ui.checkoutPage.free') }}
                      </button>
                    </div>
                  </dd>
                  <dd class="review-row-action review-row-action-price">
                    <span class="review-price" :class="{ 'is-free': !(cartPrice.shippingPrice > 0) }">
                      {{ cartPrice.shippingPrice > 0 ? money(cartPrice.shippingPrice) : $t('ui.checkoutPage.free') }}
                    </span>
                    <button
                      id="delivery-choice"
                      type="button"
                      class="review-change"
                      :aria-expanded="String(editingDelivery)"
                      @click="editingDelivery = !editingDelivery"
                    >
                      {{ editingDelivery ? $t('ui.checkoutPage.done') : $t('ui.checkoutPage.change') }}
                    </button>
                  </dd>
                </div>
              </dl>
            </section>

            <section class="review-items mb-3" aria-labelledby="review-items-title">
              <div class="review-items-head">
                <h2 id="review-items-title" class="review-items-title">
                  {{ $t('ui.checkoutPage.items') }} <span class="review-items-count">{{ itemCount }}</span>
                </h2>
                <nuxt-link to="/cart" class="review-change">{{ $t('ui.checkoutPage.editBasket') }}</nuxt-link>
              </div>
              <checkout-review-item
                v-for="line in checkedProduct"
                :key="line.id"
                :cart="line"
              />
            </section>

            <!--
              Purchase order number.

              Shown only to the business and trade accounts that asked at
              signup to be invoiced against one (user_business_profiles
              .requires_po). Everyone else never sees the field, and the server
              decides whether it is required from the same stored flag — a
              browser claiming an account does not need a PO is not evidence
              about that account.
            -->
            <div v-if="needsPurchaseOrder" class="accordion mt-3 mb-3">
              <div class="accordion-item">
                <h5 class="accordion-header">
                  <button type="button" class="accordion-button" disabled>
                    <span class="delivery-heading">{{ $t('ui.checkoutPage.purchaseOrder') }}</span>
                  </button>
                </h5>
                <div class="po-body">
                  <label class="po-label" for="po-number">
                    {{ $t('ui.checkoutPage.purchaseOrderNumber') }} <span class="po-required">*</span>
                  </label>
                  <input
                    id="po-number"
                    v-model.trim="purchaseOrderNumber"
                    class="po-input"
                    :class="{ invalid: purchaseOrderError }"
                    maxlength="64"
                    :placeholder="$t('ui.checkoutPage.poPlaceholder')"
                    autocomplete="off"
                    @input="purchaseOrderError = ''"
                  >
                  <small v-if="purchaseOrderError" class="po-error">
                    {{ purchaseOrderError }}
                  </small>
                  <small v-else class="po-help">
                    {{ $t('ui.checkoutPage.poHelp') }}
                  </small>
                </div>
              </div>
            </div>

            <!--
              A card like the two above it, not a collapsible panel: there is
              nothing to hide on the step where the choice is made. The
              methods are one joined list, the chosen row tinted with an
              accent on its leading edge. id kept: goToPayment and the
              phone bar scroll here.
            -->
            <section id="paymentAccordion" class="review-pay mb-3" aria-labelledby="review-pay-title">
              <div class="review-pay-head">
                <h2 id="review-pay-title" class="review-pay-title">{{ $t('ui.checkoutPage.payment') }}</h2>
                <p class="review-pay-sub">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3" stroke="currentColor" stroke-width="1.8"/></svg>
                  {{ $t('ui.checkoutPage.secureNote') }}
                </p>
              </div>
              <div class="review-pay-body">
                  <payment-gateways
                          ref="paymentGateways"
                          :total-price="totalPrice"
                          :voucher="voucherResult"
                          :preselect="$store.state.cart.paymentMethod"
                          :require-choice="true"
                          :external-confirm="true"
                          :collection-order="isCollection"
                          :blocked="needsDeliveryAddress"
                          :purchase-order-number="purchaseOrderNumber"
                          :blocked-reason="$t('ui.checkoutPage.blockedReason')"
                          @field-error="onFieldError"
                          @confirm-state="payState = $event"
                          @wallet-applied="walletApplied = $event"
                        />
              </div>
            </section>
          </div>
          <!--
            mobile-bar: the total is the last thing on this page on a phone,
            below the address, the payment methods and the button that charges
            the card - so the one figure the shopper is agreeing to was the one
            they could not see while agreeing to it.

            The bar's button goes TO the payment section, it does not submit.
            `hide-btn` is still set, because the control that places the order
            belongs to the gateway and there is a different one per gateway -
            PayPal and Flutterwave render their own. A bar that submitted would
            be a second, partial order path; a bar that scrolls is a way back
            to the only one.
          -->
          <checkout-right
            ref="checkoutRight"
            route-link="checkout"
            :checked-product="checkedProductWithUpsellPrices"
            :has-shipping="true"
            :voucher-result="voucherResult"
            :hide-btn="true"
            mobile-bar
            :mobile-btn-text="$t('checkout.goToPayment')"
            :points-discount="$refs.paymentGateways?.usePoints ? $refs.paymentGateways?.pointsDiscount || 0 : 0"
            :wallet-applied="walletApplied"
            @calculated-price="calculatedPrice"
            @go-next="goToPayment"
          >
            <template v-slot:checkout>
              <div
                class="voucher-apply"
                :class="{invalid: !!voucherError}"
              >
                <form
                  class="voucher-form"
                  @submit.prevent="voucher && !voucherError && !voucherResult && checkVoucher()"
                >
                  <input
                    class="voucher-input"
                    :placeholder="$t('checkout.voucherCode')"
                    type="text"
                    v-model="voucher">

                  <ajax-button
                    class="primary-btn voucher-apply-btn"
                    type="button"
                    :fetching-data="submitting"
                    loading-text=""
                    :disabled="!voucher || !!voucherError || !!voucherResult"
                    :text="$t('checkout.apply')"
                    @clicked="checkVoucher"
                  />
                </form>

                <span
                  v-if="voucherError"
                  class="error voucher-error"
                >
                  {{ voucherError }}
                </span>
              </div>
            <div class="voucher-list-wrapper mt-10">
              <button
                class="show-voucher-btn"
                type="button"
                @click="showVoucherList = !showVoucherList"
              >
                <span v-if="!showVoucherList">
                  {{ $t('ui.checkoutPage.showVouchers') }}
                </span>
                <span v-else>
                  {{ $t('ui.checkoutPage.hideVouchers') }}
                </span>
              </button>
              <transition name="fade">
                <div
                  v-if="showVoucherList"
                  class="voucher-list"
                >
                  <!--
                    Voucher rows at the size the real ones will be, so the
                    dropdown does not open as a one-line strip of text and then
                    grow under the pointer once the list arrives.
                  -->
                  <div
                    v-if="loadingVouchers"
                    class="voucher-loading"
                  >
                    <skeleton-list
                      :rows="3"
                      :avatar="false"
                      :lines="2"
                      trailing
                    />
                  </div>
                  <div
                    v-else-if="!activeVouchers.length"
                    class="empty-voucher-state"
                  >
                    <i class="fa fa-ticket"></i>
                    <h6>
                      {{ $t('ui.checkoutPage.noVouchers') }}
                    </h6>
                    <p>
                      {{ $t('ui.checkoutPage.noVouchersText') }}
                    </p>
                  </div>
                  <div
                    v-for="item in activeVouchers"
                    :key="item.id"
                    class="voucher-card"
                  >
                    <div class="voucher-left">
                      <div class="voucher-header">
                        <h5 class="voucher-title">
                          {{ item.title }}
                        </h5>
                      </div>
                      <div class="voucher-meta">
                        <span class="voucher-code">
                          {{ item.code }}
                        </span>
                        <span class="voucher-offer">
                          {{ voucherOffer(item) }}
                        </span>
                      </div>
                      <p v-if="item.is_gift_card" class="voucher-description">
                        <span>{{ $t('ui.checkoutPage.giftCardNoMin') }}</span>
                      </p>
                      <p v-else class="voucher-description">
                        <span>
                          {{ $t('ui.checkoutPage.minSpend', { amount: money(item.min_spend) }) }}
                        </span>
                        <span v-if="voucherCap(item)">
                          {{ $t('ui.checkoutPage.maxDiscount', { amount: money(item.capped_price) }) }}
                        </span>
                      </p>
                      <small class="voucher-expiry">
                        {{ $t('ui.checkoutPage.validTill', { date: item.end_time }) }}
                      </small>
                    </div>
                    <button
                      class="apply-voucher-btn"
                      :class="{
                        applied:
                          appliedVoucherCode?.trim()?.toLowerCase()
                          ===
                          item.code?.trim()?.toLowerCase()
                      }"
                      @click="
                        appliedVoucherCode?.trim()?.toLowerCase()
                        ===
                        item.code?.trim()?.toLowerCase()
                          ? removeVoucher()
                          : applyVoucher(item)
                      "
                    >
                      <span
                        v-if="
                          appliedVoucherCode?.trim()?.toLowerCase()
                          ===
                          item.code?.trim()?.toLowerCase()
                        "
                      >
                        {{ $t('ui.checkoutPage.remove') }}
                      </span>
                      <span v-else>
                        {{ $t('ui.checkoutPage.apply') }}
                      </span>
                    </button>
                  </div>
                </div>
              </transition>
            </div>

            <!--
              The order button, under the total it charges. The payment panel
              still decides what it says and does (confirm-state /
              confirmAction); only where it sits moved. PayPal, Flutterwave and
              Iyzico draw their own button in the panel, so none shows here.
            -->
            <div v-if="payState && payState.available" class="summary-pay">
              <p v-if="payState.blockedReason" class="summary-pay-blocked">{{ payState.blockedReason }}</p>
              <ajax-button
                class="primary-btn summary-pay-btn"
                type="button"
                :fetching-data="payState.loading"
                :disabled="payState.disabled"
                :text="payState.text"
                @clicked="$refs.paymentGateways && $refs.paymentGateways.confirmAction()"
              />
            </div>
            </template>
          </checkout-right>
        </div>
      </div>
    </div>
  </client-only>
</template>
<script>
  import '~/assets/css/cart-flow.css'
  import CheckoutRight from '~/components/CheckoutRight'
  import StripePayment from '~/components/StripePayment'
  import RazorpayPayment from '~/components/RazorpayPayment'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import productHelper from "~/mixin/productHelper"
  import productPriceHelper from "~/mixin/productPriceHelper"
  import paymentHelper from '~/mixin/paymentHelper'
  import PaymentGateways from "~/components/PaymentGateways";
  import AjaxButton from "~/components/AjaxButton";
  import global from '~/mixin/global'
  import loyaltyHelper from '~/mixin/loyaltyHelper'
  import CheckoutReviewItem from '~/components/CheckoutReviewItem'


  export default {
    middleware: ['common-middleware'],
    head() {
      return {
        link: [
          {
            rel: 'preload',
            as: 'script',
            href:
              `https://www.paypal.com/sdk/js?client-id=${this.paymentGateway?.paypal_key}&components=buttons,marks&disable-funding=paylater,card`
          },
          {
            rel: 'preload',
            as: 'script',
            href: 'https://checkout.flutterwave.com/v3.js'
          },
        ],
      }
    },
    data() {
      return {
        editingDelivery: false,
        // the order button's label and state, from the payment panel
        payState: null,
        // part of the order the shopper chose to pay from their wallet
        walletApplied: 0,
        purchaseOrderNumber: '',
        purchaseOrderError: '',
        appliedVoucherCode: null,
        availableVouchers: [],
        loadingVouchers: false,
        showVoucherList: false,
        loading: false,
        paypaLoaded: false,
        voucher: '',
        voucherError: null,
        voucherResult: null,
        submitting: false,
        placingOrder: false,
        cartPrice: {
          totalPriceWithOffer: 0,
          shippingPrice: 0,
          exclusiveTax: 0,
          inclusiveTax: 0,
          voucher: 0
        },
        checkedProductQty: 0
      }
    },
    watch: {
      voucher(newVal, oldVal) {
        if (!newVal && oldVal) {
          return
        }
        this.voucherError = null
      },
      // Watch for cart products changes to recalculate prices
      cartProducts: {
        immediate: true,
        handler() {
          this.$nextTick(() => {
            this.forcePriceRecalculation();
          });
        }
      },
      checkedProduct: {
        handler(val) {
          if (!val.length) {
            // Nothing ticked means nothing to discount, so the opt-in goes
            // with it, or the order would still be told to apply one.
            this.$store.commit('cart/SET_FLASH_DISCOUNT_OPT_IN', false)
            this.$store.commit('cart/SET_LOYALTY_DISCOUNT', null)
          }
        },
        deep: true
      },
      /*
       * A loyalty group can carry a minimum cart value and a cap, so the
       * discount changes as the basket does. Re-ask whenever the subtotal
       * moves, rather than pricing the basket the shopper started with.
       */
      'cartPrice.totalPriceWithOffer': {
        handler() {
          this.refreshLoyaltyDiscount()
        }
      },
    },
    components: {
      AjaxButton,
      PaymentGateways,
      RazorpayPayment,
      StripePayment,
      CheckoutRight,
      CheckoutReviewItem
    },
    mixins: [util, productHelper, paymentHelper, productPriceHelper, global, loyaltyHelper],
    computed: {
      activeVouchers() {
        return this.availableVouchers;
        // const now = new Date()
        // return this.availableVouchers.filter(v => {
        //   if (!v.end_time) {
        //     return false
        //   }
        //   const endDate = new Date(v.end_time)
        //   return endDate >= now
        // })
      },
      selectedAddress() {
        return this.$store.state.cart.selectedAddress
      },
      /*
       * Who the order is for: the collection contact on a collection order,
       * otherwise the name on the address and the account's email and phone.
       */
      contactSummary() {
        if (this.isCollection && this.collectionContact) {
          const c = this.collectionContact
          return { name: c.name, detail: [c.email, c.phone].filter(Boolean).join(' · ') }
        }

        const a = this.selectedAddress
        const email = this.$auth?.user?.email || this.profile?.email || a?.email
        const name = a?.name || this.$auth?.user?.name || this.profile?.name

        if (!name && !email) return null

        return { name: name || email, detail: [name ? email : null, a?.phone].filter(Boolean).join(' · ') }
      },
      // Email and phone under the delivery address (the name is already on it).
      shipToContact() {
        const a = this.selectedAddress
        const email = this.$auth?.user?.email || this.profile?.email || a?.email

        return [email, a?.phone].filter(Boolean).join(' · ')
      },
      itemCount() {
        return this.checkedProduct.reduce((sum, line) => sum + parseInt(line.quantity || 0), 0)
      },
      /*
       * The address as it is printed on a parcel, one line each, with the
       * county and country by name - the record holds codes ("D", "IE").
       */
      addressLines() {
        const a = this.selectedAddress

        if (!a) return []

        const country = this.countryList?.[a.country]
        const county = a.county || a.stateTitle || country?.states?.[a.state]?.name || a.state
        const countryName = a.countryTitle || country?.name || a.country

        return [
          [a.address_1, a.address_2].filter(Boolean).join(', '),
          [a.city, county].filter(Boolean).join(', '),
          a.zip,
          countryName
        ].filter(line => line && String(line).trim())
      },
      // The longest "day needed" among the lines' shipping places.
      deliveryDays() {
        const days = this.checkedProduct
          .map(line => parseInt(line?.shipping_place?.day_needed || 0))
          .filter(day => day > 0)

        return days.length ? Math.max(...days) : 0
      },
      // The collection point the server saved on the lines for this order.
      collectionPoint() {
        for (const line of this.checkedProduct) {
          const places = [line?.shipping_place]

          for (const place of places) {
            if (!place || parseInt(place.pickup_point) !== 1) continue

            const lines = [
              place.pickup_address_line_1,
              place.pickup_address_line_2,
              [place.pickup_city, place.pickup_state].filter(Boolean).join(', '),
              place.pickup_zip,
              place.pickup_country
            ].filter(item => item && String(item).trim())

            if (lines.length) {
              return { lines, phone: place.pickup_phone }
            }
          }
        }

        return null
      },
      needsDeliveryAddress() {
        return !this.isCollection && !this.selectedAddress
      },
      collectionContact() {
        return this.$store.state.cart.collectionContact
      },
      isCollection() {
        return this.deliveryType === 'collection'
      },
      deliveryType() {
        const products = this.cartProducts.filter(p => parseInt(p.selected) === 1)
        if (!products.length) return 'delivery'
        const first = products[0]
        return first?.shipping_type === 2 ? 'collection' : 'delivery'
      },
      noPaymentMethod() {
        return parseInt(this.paymentGateway?.card_payment) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.cash_on_delivery) !== this.status.PUBLIC
      },
      productPrice() {
        return this.cartPrice.totalPriceWithOffer + this.cartPrice.shippingPrice + this.cartPrice.exclusiveTax
      },
      flashDiscount() {
        return this.$store.getters['cart/flashDiscountAmount'] || 0
      },
      /*
       * This is the figure the payment gateways are handed, so it has to match
       * what the server will charge. The loyalty discount is taken off here for
       * the same reason the flash discount is: an order priced without it
       * charges the card the undiscounted amount and then records a discounted
       * order, which is the mismatch the server refuses to confirm.
       */
      totalPrice() {
        if (this.productPrice) {
          return Math.max(
            this.productPrice
            - this.cartPrice.voucher
            - this.flashDiscount
            - this.loyaltyDiscount,
            0
          )
        }
        return 0
      },
      checkedProduct() {
        return this.cartProducts.filter(obj => {
          return parseInt(obj.selected) === 1
        })
      },
      // Create a new array with upsell prices to trigger reactivity
      checkedProductWithUpsellPrices() {
        return this.checkedProduct.map(product => {
          return {
            ...product,
            // Ensure upsell_price is properly handled
            upsell_price: product.upsell_price || null
          };
        });
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting', 'currency', 'paymentGateway']),
      ...mapGetters('cart', {cartProducts: 'checkoutProducts'}),
      ...mapGetters('user', ['profile']),
      ...mapGetters('resource', ['countryList']),

      /*
       * Does this customer get invoiced against a purchase order?
       *
       * Read from the profile the server sends, which mirrors the flag the
       * account set at signup. The server applies the same test before it will
       * create the order, so this only decides whether to show the field.
       */
      needsPurchaseOrder() {
        const account = this.profile?.account
        const business = this.profile?.business_profile

        if (!account || !business) return false

        return account.account_type !== 'personal'
          && !!Number(business.requires_po)
      },
    },
    methods: {

      /*
       * A refusal about a field this page owns.
       *
       * The server keys a refusal by the field it is about, so a missing
       * purchase order number arrives as
       * {purchase_order_number: ["Enter the purchase order number..."]}.
       * Putting it on the input marks the box red and puts the reason under
       * it, rather than leaving the customer to find which of the accordions
       * the banner at the bottom is talking about.
       */
      /*
       * The phone bar's action: put the payment section on screen.
       *
       * Deliberately NOT a submit. PaymentGateways renders one confirm button
       * per gateway, each with its own handler, and two of them (PayPal,
       * Flutterwave) are SDK buttons that cannot be driven from outside at
       * all. Dispatching from here would mean a second copy of that branching
       * and a second way to place an order; scrolling means there stays
       * exactly one.
       *
       * preventScroll on the focus, or the browser jumps to the control and
       * cancels the smooth scroll it was asked for a line earlier.
       */
      goToPayment() {
        const section = document.getElementById('paymentAccordion')

        if (!section) return

        section.scrollIntoView({ block: 'start', behavior: 'smooth' })

        const control = section.querySelector(
          'input:not([type="hidden"]):not([disabled]), button:not([disabled])'
        )

        if (control) {
          try {
            control.focus({ preventScroll: true })
          } catch (e) {
            // older Safari has no options bag; the scroll above still happened
          }
        }
      },

      onFieldError(data) {
        const messages = data?.purchase_order_number

        if (!messages) return

        this.purchaseOrderError = Array.isArray(messages) ? messages[0] : messages

        // Scroll it into view: on a long checkout the field is usually above
        // the fold the customer is looking at when the button fails.
        this.$nextTick(() => {
          const field = document.getElementById('po-number')

          if (field) {
            field.scrollIntoView({ block: 'center', behavior: 'smooth' })
            field.focus()
          }
        })
      },
      removeVoucher() {
        this.voucher = ''
        this.voucherResult = null
        this.voucherError = null
        this.appliedVoucherCode = null
        this.cartPrice = {
          ...this.cartPrice,
          voucher: 0
        }
        this.$nextTick(() => {
          this.calculatedPrice({
            voucher: 0
          })
        })
        this.setToastMessage(
          this.$t('ui.checkoutPage.toast.voucherRemoved')
        )
      },
      async loadVouchers() {
        this.loadingVouchers = true
        try {
          const token = this.$auth?.strategy?.token?.get()
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const { data } = await this.$axios.get(
              `${baseUrl}api/v1/user/user-vouchers?order_by=created_at&type=desc`,
              {
                  headers: {
                      Authorization: `Bearer ${token}`
                  }
              }
          )
          const vouchers = data?.data?.data || []
          const now = new Date()
          this.availableVouchers = vouchers.filter(v => {
            if (!v.end_time) {
              return false
            }
            // a gift card with nothing left on it
            if (v.is_gift_card && !(Number(v.gift_balance) > 0)) {
              return false
            }
            const endDate = new Date(v.end_time)
            return endDate >= now
          })
        } catch (e) {
          console.log(e)
        } finally {
          this.loadingVouchers = false
        }
      },
      async applyVoucher(voucher) {
        if (
          this.voucher === voucher.code ||
          this.voucherResult?.code === voucher.code
        ) {
          return
        }
        this.voucher = voucher.code
        await this.checkVoucher()
      },

      /*
       * What the code is worth, in its own terms: "10.00 off" for a flat
       * voucher, "15% off" for a percentage one.
       *
       * Deliberately NOT the saving this basket would get. That figure belongs
       * to the server - Pricing::voucherDiscount applies the cap, floors it at
       * the value of the goods and rounds it - and a second copy of the rule
       * here would be free to disagree with what is actually charged. The real
       * amount appears on the Voucher row of the summary the moment the code
       * is applied, which is the only place it can be quoted safely.
       */
      voucherOffer(item) {
        if (item.is_gift_card) {
          return this.$t('ui.checkoutPage.offer.balance', { amount: this.money(item.gift_balance) })
        }

        if (Number(item.type) === 2) {
          const rate = parseFloat(item.price)

          if (!(rate > 0)) {
            return ''
          }

          // 15.00 -> "15%", 12.50 -> "12.5%"
          return this.$t('ui.checkoutPage.offer.percentOff', { rate: String(rate.toFixed(2)).replace(/\.?0+$/, '') })
        }

        return this.$t('ui.checkoutPage.offer.amountOff', { amount: this.money(item.price) })
      },

      /*
       * A percentage voucher's ceiling. A flat voucher carries no cap - its
       * amount already IS the ceiling - so there is nothing to state twice.
       */
      voucherCap(item) {
        return Number(item.type) === 2 && Number(item.capped_price) > 0
      },
      /*
       * A guest gave their details on /guest-checkout, not /shipping, so Edit
       * has to take them back to the screen they actually filled in - /shipping
       * would show them an address picker they never used.
       */
      goToShipping() {

        if (!this.$auth?.loggedIn && this.setting?.guest_checkout) {
          this.$router.push('/guest-checkout')
          return
        }

        this.$router.push('/shipping')
      },
      /*
       * The tiles below call this, but it did not exist on this page - every
       * click threw. They are also inert until Edit is pressed, so the choice
       * cannot be changed by brushing past them on the way to paying.
       */
      setDeliveryType(type) {
        if (!this.editingDelivery || type === this.deliveryType) {
          this.editingDelivery = false
          return
        }

        const shippingType = type === 'collection' ? 2 : 1
        const previousType = this.deliveryType === 'collection' ? 2 : 1

        this.cartProducts
          .filter(product => parseInt(product.selected) === 1)
          .forEach(product => {
            this.$store.commit('cart/SET_PRODUCT_SHIPPING_TYPE', {
              id: product.id,
              shipping_type: shippingType
            })
          })

        if (type === 'collection') {
          // a collection order has no address, so it needs someone to contact
          if (!this.collectionContact) {
            const account = this.$auth?.user || {}

            this.$store.commit('cart/SET_COLLECTION_CONTACT', {
              name: account.name || '',
              email: account.email || '',
              phone: account.phone || ''
            })
          }
        } else {
          this.$store.commit('cart/SET_COLLECTION_CONTACT', null)
        }

        this.editingDelivery = false

        this.saveDeliveryChoice(shippingType, previousType)
      },
      /*
       * The switch above used to change only this page: the basket kept the
       * choice made on /shipping, and that is what the order was placed with.
       * It is saved now, and the server decides the row and price again -
       * which also re-checks "sell only in" for the new way of receiving it.
       * A posted order with no address yet is left to the "Add" prompt.
       */
      async saveDeliveryChoice(shippingType, previousType) {
        const lines = this.cartProducts.filter(product => parseInt(product.selected) === 1)
        const address = this.selectedAddress
        const contact = this.collectionContact

        if (!lines.length || (shippingType === 1 && !address?.id)) {
          return
        }

        const revert = () => {
          lines.forEach(product => {
            this.$store.commit('cart/SET_PRODUCT_SHIPPING_TYPE', {
              id: product.id,
              shipping_type: previousType
            })
          })
        }

        try {
          const data = await this.updateCartShipping({
            cart: lines.map(line => ({cart: line.id, shipping_type: shippingType, shipping_place: null})),
            shipping_type: shippingType,
            user_token: await this.getUserToken(),
            selected_address: shippingType === 1 ? address.id : null,
            contact_name: shippingType === 2 ? contact?.name : null,
            contact_email: shippingType === 2 ? contact?.email : null,
            contact_phone: shippingType === 2 ? contact?.phone : null
          })

          if (data?.status !== 200) {
            revert()

            const product = data?.data?.product?.[0]
            const first = product ? Object.values(product)[0] : data?.data?.form?.[0]

            this.setToastError((Array.isArray(first) ? first[0] : first) || this.$t('ui.checkoutPage.toast.deliveryUnavailable'))
          }
        } catch (e) {
          revert()
          this.setToastError(this.$t('ui.checkoutPage.toast.deliverySaveFailed'))
        }
      },
      async checkVoucher() {
        this.submitting = true
        
        // Ensure we have the latest prices before applying voucher
        await this.forcePriceRecalculation();
        const res = await this.voucherValidity({
          payload: {
            voucher: this.voucher,
            user_token: await this.getUserToken(),
            price: this.cartPrice.totalPrice - this.flashDiscount + this.cartPrice.shippingPrice + this.cartPrice.exclusiveTax
          },
          lang: this.langCode
        })
        this.submitting = false
        if (res?.status === 201) {
          this.voucherError = res.data.form[0]
        } else {
          this.voucherResult = res.data
          this.appliedVoucherCode = this.voucherResult.code || this.voucher

          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })

          await this.$nextTick()

          this.forcePriceRecalculation()

          this.cartPrice.voucher = this.voucherResult.offered || 0
          // The flash discount is a getter now, derived from the basket and
          // the live promotion, so re-committing it onto itself is no longer a
          // thing that has to happen after a voucher lands.
        }
      },
      calculatedPrice(evt) {
        this.cartPrice = {...this.cartPrice, ...evt}
      },

      refreshLoyaltyDiscount() {
        // The bundle saving rides along: both are re-read whenever the basket
        // that earns them changes.
        this.$store.dispatch('cart/loadBundleDiscount')

        return this.loadLoyaltyDiscount(this.cartPrice?.totalPriceWithOffer)
      },
      
      // Force price recalculation in CheckoutRight component
      forcePriceRecalculation() {
        // if (this.$refs.checkoutRight) {
        //   // Create a new array to trigger reactivity in CheckoutRight
        //   const updatedProducts = [...this.checkedProductWithUpsellPrices];
        //   // This will force CheckoutRight to recalculate prices
        //   this.$refs.checkoutRight.checkedProduct = updatedProducts;
        // }
      },

      ...mapActions('user', ['userAddressAll', 'userAddressDelete', 'getUserToken']),
      ...mapActions('common', ['setToastMessage', 'setToastError']),
      ...mapActions('order', ['orderAction', 'voucherValidity', 'sendOrderEmail', 'paymentDone']),
      ...mapActions('cart', ['getCartByUser', 'subtractCartProductCount', 'emptyCartProduct', 'updateCartShipping']),
    },
    async asyncData({store, $auth, error}) {
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

    async mounted() {
      /*
       * The store-wide promotion, so the summary on this page can show the
       * discount the shopper opted into on the basket. Only the basket
       * carries the banner, so without this a checkout reached directly --
       * a deep link, or a refresh -- had no promotion to derive the amount
       * from and quietly dropped the row.
       */
      this.$store.dispatch('cart/loadFlashPromotion')

      this.orderId = ''
      this.voucherError = null
      this.voucherResult = null
      await this.loadVouchers()
      try {
        if (this.cartProducts.length === 0) {
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })
        }
        
        // Force price recalculation after component is mounted
        this.$nextTick(() => {
          this.forcePriceRecalculation();
          this.refreshLoyaltyDiscount();
        });
      } catch (e) {
        return this.$nuxt.error(e)
      }
    },

    beforeDestroy() {
      /*
       * The discount belongs to this basket at this moment. Leaving it in the
       * store would show a stale figure on the next visit, before the fresh
       * lookup comes back.
       */
      this.$store.commit('cart/SET_LOYALTY_DISCOUNT', null)
    },
  }
</script>

<style scoped>
/* ---- review panel: who / where / how -------------------------------------- */
.review-panel,
.review-items {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
}

.review-panel-head {
  padding: 18px 22px 14px;
  border-bottom: 1px solid #EEF0F5;
  background: linear-gradient(180deg, #F7F7FE 0%, #fff 100%);
  border-radius: 14px 14px 0 0;
}

.review-panel-title {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  color: #130E2B;
}

.review-panel-sub {
  margin: 3px 0 0;
  font-size: 13.5px;
  color: #6B7C93;
}

.review-rows {
  margin: 0;
}

.review-row {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr) auto;
  align-items: start;
  gap: 4px 18px;
  padding: 16px 22px;
}

.review-row + .review-row {
  border-top: 1px solid #EEF0F5;
}

.review-row.is-missing {
  background: #FFF8EB;
}

.review-row-label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: .02em;
  text-transform: uppercase;
  color: #6B7C93;
}

.review-row-label svg {
  flex: none;
  color: #33319A;
}

.review-row-value {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0;
  font-size: 14.5px;
  line-height: 1.45;
  color: #232159;
  overflow-wrap: anywhere;
}

.review-row-action {
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 0;
}

.review-strong {
  font-weight: 600;
  color: #130E2B;
}

.review-muted {
  font-size: 13.5px;
  color: #6B7C93;
}

.review-contact {
  display: flex;
  flex-direction: column;
  gap: 1px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed #E3E3EF;
}

.review-warning {
  font-weight: 500;
  color: #9A5B00;
}

.review-tag {
  margin-left: 8px;
  padding: 1px 8px;
  border-radius: 6px;
  background: #EEEDFB;
  font-size: 12px;
  font-weight: 600;
  color: #33319A;
  text-transform: capitalize;
}

.review-price {
  font-size: 15px;
  font-weight: 700;
  color: #130E2B;
  font-variant-numeric: tabular-nums;
}

.review-price.is-free {
  color: #05B942;
}

/* A text link, not a pill: the page's weight belongs to paying. */
.review-change {
  height: auto;
  padding: 0;
  border: 0;
  background: none;
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.4;
  color: #33319A;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}

.review-change:hover {
  color: #1F1D6B;
}

/* ---- method switch --------------------------------------------------------- */
.review-switch {
  display: inline-flex;
  align-self: flex-start;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
  padding: 4px;
  border-radius: 10px;
  background: #F1F2F8;
}

.review-switch-opt {
  height: auto;
  padding: 7px 14px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.3;
  color: #4B5874;
  cursor: pointer;
}

.review-switch-opt.active {
  background: #fff;
  color: #33319A;
  box-shadow: 0 1px 3px rgba(19, 14, 43, .12);
}

/* ---- payment card -------------------------------------------------------- */
.review-pay {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  scroll-margin-top: 150px;
}

.review-pay-head {
  padding: 16px 22px 12px;
  border-bottom: 1px solid #EEF0F5;
}

.review-pay-title {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: #130E2B;
}

.review-pay-sub {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 3px 0 0;
  font-size: 13px;
  color: #6B7C93;
}

.review-pay-sub svg {
  color: #05B942;
}

.review-pay-body {
  padding: 16px 22px 6px;
}

/*
 * The shared payment panel's rows, restyled for this page only: one joined
 * list instead of a two-column grid of boxes. Two classes deep plus the
 * scoped attribute, so it outranks PaymentGateways' own rules.
 */
.review-pay .review-pay-body ::v-deep .payment-options {
  display: flex;
  flex-direction: column;
  gap: 0;
  border: 1px solid #E3E3EF;
  border-radius: 12px;
  overflow: hidden;
}

.review-pay .review-pay-body ::v-deep .payment-option {
  gap: 14px;
  padding: 16px 18px;
  border: 0;
  border-radius: 0;
  background: #fff;
  box-shadow: none;
}

.review-pay .review-pay-body ::v-deep .payment-option + .payment-option {
  border-top: 1px solid #EEF0F5;
}

.review-pay .review-pay-body ::v-deep .payment-option:hover {
  background: #FAFAFE;
}

.review-pay .review-pay-body ::v-deep .payment-option.active {
  background: #F4F4FE;
  box-shadow: inset 3px 0 0 #33319A;
}

.review-pay .review-pay-body ::v-deep .payment-option.active .payment-title {
  color: #33319A;
}

.review-pay .review-pay-body ::v-deep .payment-option:focus-within {
  box-shadow: inset 3px 0 0 #33319A, inset 0 0 0 2px rgba(51, 49, 154, .18);
}

/*
 * The Stripe card form reads as the selected card row opening up: the same
 * tint and left bar, indented to the row's text (padding + radio + gap).
 */
.review-pay .review-pay-body ::v-deep .stripe-card-panel {
  padding: 2px 18px 18px 50px;
  border: 0;
  border-radius: 0;
  background: #F4F4FE;
  box-shadow: inset 3px 0 0 #33319A;
}

.review-pay .review-pay-body ::v-deep .stripe-card-panel + .payment-option {
  border-top: 1px solid #EEF0F5;
}

@media (max-width: 575px) {
  .review-pay .review-pay-body ::v-deep .stripe-card-panel {
    padding: 2px 14px 16px 17px;
  }
}

.review-pay .review-pay-body ::v-deep .payment-option.is-disabled,
.review-pay .review-pay-body ::v-deep .payment-option.is-disabled:hover {
  background: #F9FAFC;
}

.review-pay .review-pay-body ::v-deep .payment-title {
  font-size: 15px;
}

.review-pay .review-pay-body ::v-deep .payment-sub {
  font-size: 13px;
}

/* ---- the order button in the Order Summary -------------------------------- */
.summary-pay {
  margin-top: 14px;
}

.summary-pay-btn {
  width: 100%;
}

/* phones: the summary folds into the bottom bar; the button stays under the payment options */
@media (max-width: 767px) {
  .summary-pay {
    display: none;
  }
}

.summary-pay-blocked {
  margin: 0 0 8px;
  font-size: 13px;
  color: #9A5B00;
}

/* ---- items ----------------------------------------------------------------- */
.review-items {
  padding: 16px 22px 6px;
}

.review-items-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 4px;
  border-bottom: 1px solid #EEF0F5;
}

.review-items-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  font-size: 17px;
  font-weight: 700;
  color: #130E2B;
}

.review-items-count {
  min-width: 24px;
  padding: 1px 8px;
  border-radius: 12px;
  background: #EEEDFB;
  font-size: 13px;
  font-weight: 700;
  color: #33319A;
  text-align: center;
}

@media (max-width: 576px) {
  .review-pay-head {
    padding: 14px 14px 10px;
  }

  .review-pay-body {
    padding: 12px 14px 4px;
  }

  .review-pay .review-pay-body ::v-deep .payment-option {
    padding: 14px;
  }

  .review-panel-head {
    padding: 14px 14px 12px;
  }

  .review-panel-title {
    font-size: 17px;
  }

  .review-row {
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 14px;
  }

  .review-row-label {
    grid-column: 1 / -1;
    font-size: 12px;
  }

  .review-row-value {
    font-size: 14px;
  }

  .review-items {
    padding: 12px 14px 4px;
  }
}
</style>
