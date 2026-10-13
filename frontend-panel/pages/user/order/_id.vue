<template>
  <client-only>
    <account-layout
      active-route="orders"
      class="mb-20 mb-sm-15"
    >
      <template v-slot:rightArea>

        <bank-popup
          v-if="verifyPayment"
          :order="ordered"
          @close="closeBankVerify"
        />

        <!--
          An order page is a known shape - a summary block, an address block
          and then the lines - so it can be drawn before the order arrives.
          The spinner that used to sit here ran *beside* the real markup
          rather than instead of it, so the page showed a spinner over a
          half-built order with empty fields; the content now waits its turn.
        -->
        <div v-if="fetchingOrderData" class="p-20 p-sm-15 pt-20">
          <skeleton-base :height="22" width="240px" :mb="18" />
          <skeleton-card :image="false" :lines="3" />
          <skeleton-card class="mt-20" :image="false" :lines="2" />
          <skeleton-card
            v-for="index in 3"
            :key="`sk-line-${index}`"
            class="mt-20"
            horizontal
            :thumb-width="90"
            image-height="90px"
            :lines="2"
          />
        </div>
        <p
          v-if="orderCancelled"
          class="info-msg danger-msg order-wrapper mb-15"
        >
          {{ $t('order.orderCancelled') }}
        </p>
        <p
          v-if="refunded"
          class="info-msg success-msg order-wrapper mb-15"
        >
          {{ $t('order.orderRefunded') }}
        </p>

        <!-- <div
          v-if="Object.keys(ordered).length"
          class="card"
        > -->
          <div v-if="!fetchingOrderData" class="p-20 p-sm-15 pt-20">
            <!-- <div class="flex f-reverse sided block-md mb-30 mb-sm-15">
              <ul class="mx-w-400x order-details mb-md-15">
                <li>
                  <span>
                    {{ $t('order.order') }}
                  </span>
                  <span>#{{ ordered.order }}</span>
                </li>
                <li>
                  <span>
                    {{ $t('order.deliveryStatus') }}
                  </span>
                  <span>{{ orderStatus[ordered.status].title }}</span>
                </li>
                <li>
                  <span>
                    {{ $t('order.orderMethod') }}
                  </span>
                  <span>{{ orderMethodsIn[ordered.order_method] }}</span>
                </li>
                <li>
                  <span>
                    {{ $t('order.orderDate') }}
                  </span>
                  <span>{{ ordered.created }}</span>
                </li>
                <li>
                  <span>
                    {{ $t('order.orderAmount') }}
                  </span>
                  <span>
                    <price-format
                      :price="totalPrice"
                    />
                  </span>
                </li>
                <li v-if="parseInt(ordered.order_method) === orderMethods.BANK">
                  <span>
                    {{ $t('date.ti') }}
                  </span>
                  <span>{{ ordered.trans_id }}</span>
                </li>
                <li>
                  <span>
                    {{ $t('order.paymentStatus') }}
                  </span>
                  <span>
                    {{ paymentStatus[ordered.payment_done] }}
                    <pay-button
                      v-if="!orderCancelled && parseInt(ordered.payment_done) === paymentStatusIn.UNPAID
                        && parseInt(ordered.order_method) !== orderMethods.CASH_ON_DELIVERY"
                      class="block mt-10"
                      :order="ordered"
                    />

                    <button
                      v-if="!orderCancelled && parseInt(ordered.payment_done) === paymentStatusIn.UNPAID
                        && parseInt(ordered.order_method) === orderMethods.BANK"
                      @click.prevent="verifyPayment = !verifyPayment"
                      class="link mt-15 bold f-9"
                    >
                      {{ $t('date.vp') }}
                    </button>
                </span>
                </li>
              </ul>
              <p
                class="mx-w-400x lh-2 mr-15"
              >
                <b>{{ dataFromObject(ordered.address, 'name') }}</b>
                <span class="block">{{ generateAddress(ordered.address) }}</span>
                <span
                  v-if="orderEmail"
                  class="block">{{ $t('addressPopup.email') }}: {{ orderEmail }}
                </span>
                <span
                  class="block">{{ $t('addressPopup.phone') }}: {{ dataFromObject(ordered.address, 'phone', 'n/a') }}</span>

                <span v-if="hasPickupPlace && pickupPlace">
                  <span class="bold">{{$t('date.pa') }}</span>
                  <span v-html="pickupPlace" ></span>
                </span>
              </p>

            </div> -->

            <!-- <div class="mb-15">
              <ordered-status
                :status-of-order="ordered.status"
              />

            </div> -->

            <div class="order-box d-flex justify-content-between align-items-center mb-4">
                <div class="small-text-order">
                    <strong>Order No:</strong> #{{ ordered?.order }}<br>
                    <strong>Order Date:</strong> {{ ordered?.created }}<br>
                    <!--
                        The reference the customer's finance department needs
                        to match this to their own paperwork. Captured at
                        checkout, stored on the order, and shown here, on the
                        confirmation and on the invoice.
                    -->
                    <template v-if="ordered?.purchase_order_number">
                        <strong>PO Number:</strong> {{ ordered.purchase_order_number }}<br>
                    </template>
                </div>
                <pay-button
                  v-if="!orderCancelled && parseInt(ordered.payment_done) === paymentStatusIn.UNPAID
                    && parseInt(ordered.order_method) !== orderMethods.CASH_ON_DELIVERY"
                  class="mr-10"
                  :order="ordered"
                />

                <button
                  v-if="!orderCancelled && parseInt(ordered.payment_done) === paymentStatusIn.UNPAID
                    && parseInt(ordered.order_method) === orderMethods.BANK"
                  @click.prevent="verifyPayment = !verifyPayment"
                  class="btn manage-btn mr-10"
                >
                  {{ $t('date.vp') }}
                </button>

                <button v-if="!isDelivered && !cancelledByShop"
                    aria-label="submit"
                    class="btn manage-btn plr-30 plr-sm-15"
                    @click="cancelPopup = true"
                  >
                    {{ cancellationBtnText }}
                </button>
            </div>

            <!--
                The account this order was placed by, as it stood at the time.
                Read off the order, never off the customer's live profile: a
                company that is renamed, a VAT number that is corrected or a
                trade approval that is later withdrawn must not rewrite a
                receipt that has already been sent.
            -->
            <div v-if="isBusinessOrder" class="account-box mb-4">
                <div class="section-title-billing">Account</div>
                <ul class="account-facts">
                    <li>
                        <span class="fact-label">Account Type</span>
                        <span class="fact-value">{{ accountTypeLabel }}</span>
                    </li>
                    <li v-if="ordered?.company_name">
                        <span class="fact-label">Company</span>
                        <span class="fact-value">{{ ordered.company_name }}</span>
                    </li>
                    <li v-if="ordered?.vat_number">
                        <span class="fact-label">VAT Number</span>
                        <span class="fact-value">{{ ordered.vat_number }}</span>
                    </li>
                    <li v-if="ordered?.cro_number">
                        <span class="fact-label">Company Reg. No.</span>
                        <span class="fact-value">{{ ordered.cro_number }}</span>
                    </li>
                    <li v-if="ordered?.purchase_order_number">
                        <span class="fact-label">PO Number</span>
                        <span class="fact-value">{{ ordered.purchase_order_number }}</span>
                    </li>
                </ul>
            </div>

            <!-- Address -->
            <div class="row mb-4 mt-5">
                <div class="col-md-6">

                    <!--
                        A collection order has no address on it - nothing is
                        being posted - so this rendered six empty lines under a
                        "Billing Address" heading. The contact details captured
                        at checkout are what the store actually holds for that
                        order, so they are what is shown. Same treatment as the
                        order-complete page.
                    -->
                    <template v-if="isCollection">
                        <div class="section-title-billing">Contact Details</div>
                        <ul class="billing-address">
                            <li>{{ contact.name }}</li>
                            <li v-if="contact.phone">{{ contact.phone }}</li>
                            <li v-if="contact.email">{{ contact.email }}</li>
                        </ul>
                    </template>

                    <template v-else>
                        <div class="section-title-billing">Billing Address</div>
                        <ul class="billing-address">
                            <li>{{ ordered?.address?.name }}</li>
                            <li>
                                {{ [
                                    ordered?.address?.address_1,
                                    ordered?.address?.address_2
                                ].filter(Boolean).join(', ') }}
                            </li>
                            <li>
                                {{ ordered?.address?.city }},
                                {{ ordered?.address?.state }} -
                                {{ ordered?.address?.zip }}
                            </li>

                            <li>{{ ordered?.address?.country }}</li>

                            <li>{{ ordered?.address?.phone }}</li>

                            <li>{{ ordered?.address?.email }}</li>
                        </ul>
                    </template>
                </div>

                <div class="col-md-6">
                    <!--
                        The same pickup address was headed "Shipping Address"
                        either way, and always promised delivery in N days - on
                        a collection order that is the store the customer is
                        coming to, and nothing is being delivered at all.
                    -->
                    <div class="section-title-billing">
                        {{ isCollection ? 'Collect From' : 'Shipping Address' }}
                    </div>
                    <ul class="billing-address">
                        <li>
                            {{ [
                            shippingPlace?.pickup_address_line_1,
                            shippingPlace?.pickup_address_line_2
                            ].filter(Boolean).join(', ') }}
                        </li>

                        <li>
                            {{ shippingPlace?.pickup_city }}
                            , {{ shippingPlace?.pickup_state }}
                            - {{ shippingPlace?.pickup_zip }}
                        </li>

                        <li>{{ shippingPlace?.pickup_country }}</li>

                        <li>{{ shippingPlace?.pickup_phone }}</li>

                        <li v-if="isCollection">
                            We'll email you when it's ready to collect
                        </li>
                        <li v-else-if="shippingPlace?.day_needed">
                            Delivery in {{ shippingPlace.day_needed }} days
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Payment -->
            <div class="row mb-4 payment-info">
                <div class="col-md-6 small-text">
                    <div class="section-title-billing">Payment Information</div>
                <div class="d-flex gap-2">
                    <!-- The order's own total, the same figure the summary
                         below prints. -->
                    <h6>Payment Amount:</h6> <span class="text-success fw-bold"> {{ money(ordered?.calculations?.total) }}</span>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <h6>Payment Method:</h6>   <span class="payment-method"><i :class="['fa-solid', paymentMethodIcon, 'me-1']"></i>{{ paymentMethodLabel }}</span>
                </div>
                </div>

                <div class="col-md-6 text-end small-text">
                <div class="d-flex justify-content-end gap-2">
                    <h6 class="mt-1">Payment Status: </h6><span class="status-badge"  :style="{
                        background: ordered?.payment_done === 1 ? '#05B942' : '#dc3545'
                    }">{{ paymentStatusLabel }}</span><br>
                </div>
                    <div class="mt-3">
                    <h6> Payment Transaction ID: </h6><strong class="payment-id"> {{ ordered?.trans_id || 'N/A' }}</strong><br>
                </div>
                
                </div>
            </div>

            <div class="timeline">
                <div class="steps" :style="progressStyle">

                <!-- Step 1 -->
                <div class="step"
                    :class="{ active: orderStatusStep >= 1 }">
                    <div class="circle">
                    {{ orderStatusStep > 1 ? '✓' : '1' }}
                    </div>
                    <div class="step-label">Order Placed</div>
                </div>

                <!-- Step 2 -->
                <div class="step"
                    :class="{ active: orderStatusStep >= 2 }">
                    <div class="circle">
                    {{ orderStatusStep > 2 ? '✓' : '2' }}
                    </div>
                    <div class="step-label">Order Processing</div>
                </div>

                <!-- Step 3 -->
                <div class="step"
                    :class="{ active: orderStatusStep >= 3 }">
                    <div class="circle">
                    {{ orderStatusStep > 3 ? '✓' : '3' }}
                    </div>
                    <div class="step-label">On Delivery</div>
                </div>

                <!-- Step 4 -->
                <div class="step"
                    :class="{ active: orderStatusStep >= 4 }">
                    <div class="circle">
                    {{ orderStatusStep > 4 ? '✓' : '4' }}
                    </div>
                    <div class="step-label">Delivery</div>
                </div>

                </div>
            </div>


            <!-- <div class="flow-auto mtb-15">
              <table class="mn-w-600x no-bg w-100 mtb-0">
                <tr class="lite-bold">
                  <th>{{ $t('order.image') }}</th>
                  <th>{{ $t('orderCancelPopup.title') }}</th>
                  <th>{{ $t('order.shipTo') }}</th>
                  <th>{{ $t('order.deliveryFee') }}</th>
                  <th>{{ $t('detailRight.quantity') }}</th>
                  <th>{{ $t('cartProductTile.bundleOffer') }}</th>
                  <th>{{ $t('detailRight.price') }}</th>
                  <th>{{ $t('checkoutRight.total') }}</th>
                </tr>

                <ordered-product
                  v-for="(value, index) in ordered.ordered_products"
                  :key="index"
                  :ordered="ordered"
                  :cart="value"
                  @rate-now="rateProductId = $event"
                />
              </table>
            </div> -->

            <!--
                The same product rows, under the same labels and in the same
                order, as the order confirmation and the checkout summary. This
                screen used to print "Total Price / Shipping Price / Tax Price /
                Subtotal" against a different arithmetic from the confirmation's
                "Original Price / Product Discount", so the two screens
                described one order in two vocabularies and two sets of figures.

                Every number is read off the order; see
                OrderSummary::decorate() for where each one is written.
            -->
            <div
              v-for="(item, index) in ordered.ordered_products"
              :key="item.id || index"
              class="product-thanks d-flex justify-content-between mb-20"
            >
              <div class="d-flex product-thanks-media">
                <img
                  :src="productImage(item)"
                  :alt="item.product?.title || 'Product'"
                  class="ordered-product-image"
                  @error="onImageError"
                >

                <div class="product-info ms-4 mt-2">
                  <span class="product-thanku-tittle fw-semibold">
                    {{ item.product?.title }}
                  </span><br>

                  <span
                    v-for="(attr, i) in itemAttributes(item)"
                    :key="`attr-${i}`"
                    class="product-model fw-normal d-block"
                  >
                    {{ attr.label }}: {{ attr.value }}
                  </span>

                  <span v-if="item?.updated_inventory?.sku" class="product-model fw-normal d-block">
                    SKU: {{ item.updated_inventory.sku }}
                  </span>

                  <span class="fw-semibold mobile-quantity d-block mt-1">
                    Quantity: {{ item.quantity }}
                  </span>

                  <span v-if="item.bundle_offer > 0" class="product-model fw-normal d-block">
                    Includes {{ item.bundle_offer }} free
                  </span>
                </div>
              </div>

              <div class="mt-2 product-thanks-prices">

                <div class="d-flex justify-content-end price-box-thanku">
                  <p>
                    Unit Price:
                    <span class="fw-bold">
                      {{ money(item.price) }}
                      <s
                        v-if="Number(item.line_discount || 0) > 0"
                        class="unit-was"
                      >{{ money(item.actual_price) }}</s>
                    </span>
                  </p>
                </div>

                <div class="d-flex justify-content-end price-box-thanku">
                  <p>
                    Original Price:
                    <span class="fw-bold">
                      {{ money(Number(item.actual_price || 0) * Number(item.quantity || 0)) }}
                    </span>
                  </p>
                </div>

                <div
                  v-if="Number(item.line_discount || 0) > 0"
                  class="d-flex justify-content-end price-box-thanku"
                >
                  <p>
                    Product Discount:
                    <span class="fw-bold text-success">
                      - {{ money(item.line_discount) }}
                    </span>
                  </p>
                </div>

                <div
                  v-if="Number(item.shipping_price || 0) > 0"
                  class="d-flex justify-content-end price-box-thanku"
                >
                  <p>
                    Shipping:
                    <span class="fw-bold">
                      + {{ money(item.shipping_price) }}
                    </span>
                  </p>
                </div>

                <div
                  v-if="Number(item.tax_total || 0) > 0"
                  class="d-flex justify-content-end price-box-thanku"
                >
                  <p>
                    Tax:
                    <span class="fw-bold">
                      + {{ money(item.tax_total) }}
                    </span>
                  </p>
                </div>

                <div class="d-flex justify-content-end price-box-thanku line-subtotal">
                  <p>
                    Subtotal:
                    <span class="fw-bold">
                      {{ money(item.line_total) }}
                    </span>
                  </p>
                </div>

              </div>
            </div>

            <!--
                The order summary, in the shape of the checkout summary.

                Was: Subtotal, Shipping, Tax, Voucher, Total, drawn from
                `calculated` - whose subtotal is the goods at the price charged,
                with no line naming the discount that got them there. The
                confirmation screen drew Subtotal, Product Discount, ... from
                `calculations`, whose subtotal is the goods at list price. Same
                order, same word, two different numbers, one screen apart.

                Both now read `calculations`, which is the one summary the
                server builds from the order's own rows.
            -->
            <div class="order-summary-box mt-4">

              <!--
                The goods, gross, and what they net down to:

                  Products (Inc-VAT) - Discount = Net Products (Inc-VAT)

                Both ends come from OrderSummary, split from what this order
                recorded. The discount is the whole saving against the list
                price, so what used to be a separate Bundle Offer row is
                inside it rather than counted twice.
              -->
              <div class="summary-row">
                <span>Products (Inc-VAT)</span>
                <span>{{ money(ordered?.calculations?.subtotal_inc_vat) }}</span>
              </div>

              <div v-if="Number(ordered?.calculations?.discount_inc_vat) > 0" class="summary-row">
                <span>Discount</span>
                <span class="text-success">
                  -{{ money(ordered?.calculations?.discount_inc_vat) }}
                </span>
              </div>

              <div class="summary-rule"></div>

              <div class="summary-row">
                <span>Net Products (Inc-VAT)</span>
                <span>{{ money(ordered?.calculations?.inc_vat) }}</span>
              </div>

              <!--
                Already inside the figure above, not added to it. Named against
                the products because any order-wide discount below came off the
                gross after VAT was charged.
              -->
              <div class="summary-row summary-row-sub">
                <span>
                  VAT included in products<template v-if="vatRateLabel"> ({{ vatRateLabel }})</template>
                </span>
                <span>{{ money(ordered?.calculations?.vat) }}</span>
              </div>

              <div v-if="showRow('Flash Discount')" class="summary-row">
                <span>Flash Discount</span>
                <span class="text-success">
                  - {{ money(ordered?.calculations?.flash_discount) }}
                </span>
              </div>

              <div v-if="showRow('Bundle Saving')" class="summary-row">
                <span>Bundle Saving</span>
                <span class="text-success">
                  - {{ money(ordered?.calculations?.bundle_discount) }}
                </span>
              </div>

              <div v-if="showRow('Voucher Discount')" class="summary-row">
                <span>Voucher Discount</span>
                <span class="text-success">
                  - {{ money(ordered?.calculations?.voucher) }}
                </span>
              </div>

              <!--
                Labelled with the wording frozen on the order when it was
                placed, so a receipt keeps saying what the customer was told
                even if the group is renamed later.
              -->
              <div v-if="showRow('Loyalty Discount')" class="summary-row">
                <span>{{ ordered?.calculations?.loyalty_label || 'Loyalty Discount' }}</span>
                <span class="text-success">
                  - {{ money(ordered?.calculations?.loyalty_discount) }}
                </span>
              </div>

              <div v-if="showRow('Points')" class="summary-row">
                <span>Cherry Points</span>
                <span class="text-success">
                  - {{ money(ordered?.calculations?.points_discount) }}
                </span>
              </div>

              <!--
                Stated even when it is nothing, because a missing shipping row
                and free shipping look the same and only one is good news.
              -->
              <div class="summary-row">
                <span>Shipping</span>
                <span v-if="!(Number(ordered?.calculations?.shipping) > 0)" class="text-success">FREE</span>
                <span v-else>{{ money(ordered?.calculations?.shipping) }}</span>
              </div>

              <div class="summary-row total-row">
                <span>Total (Inc-VAT)</span>
                <span>{{ money(ordered?.calculations?.total) }}</span>
              </div>

              <!-- A split payment: how the total was paid. -->
              <template v-if="Number(ordered?.calculations?.wallet_paid) > 0">
                <div class="summary-row">
                  <span>Paid from wallet</span>
                  <span class="text-success">- {{ money(ordered?.calculations?.wallet_paid) }}</span>
                </div>
                <div class="summary-row">
                  <span>Paid by your chosen method</span>
                  <span>{{ money(ordered?.calculations?.amount_due) }}</span>
                </div>
              </template>

            </div>

          </div>
        <!-- </div> -->

        <transition name="fade" mode="out-in">
          <order-cancel-popup
            v-if="cancelPopup"
            :order-id="orderId"
            @success="orderCancelling"
            @close="cancelPopup = false"
          />
        </transition>
        <transition name="fade" mode="out-in">
          <rate-popup
            v-if="rateProductId"
            :order-id="orderId"
            :product-id="rateProductId"
            @close="rateProductId = 0"
          />
        </transition>
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
  import AccountLayout from '~/components/AccountLayout'
  import PayButton from "~/components/PayButton"
  import OrderCancelPopup from "~/components/OrderCancelPopup"
  import PriceFormat from "~/components/PriceFormat"
  import OrderedStatus from "~/components/OrderedStatus"
  import PopOver from "~/components/PopOver";
  import BankPopup from "~/components/BankPopup";
  import global from '~/mixin/global'
  import OrderedProduct from "../../../components/OrderedProduct";

  export default {
    middleware: ['common-middleware'],
    head() {
      return {
        title: 'Order',
        meta: []
      }
    },
    data() {
      return {
        verifyPayment: false,
        cancelPopup: false,
        fetchingOrderData: false,
        rateProductId: 0
      }
    },
    components: {
      OrderedProduct,
      BankPopup,
      PopOver,
      OrderedStatus,
      PriceFormat,
      OrderCancelPopup,
      PayButton,
      LazyImage,
      RatePopup,
      AccountLayout
    },
    mixins: [util, metaHelper, global],
    computed: {
      paymentStatusLabel() {
        if (parseInt(this.ordered?.order_method) === 7) {
            return this.ordered?.payment_done === 1
            ? 'Paid'
            : 'Processing'
        }
        return this.ordered?.payment_done === 1 ? 'Paid' : 'Not Paid'
      },
      paymentMethodIcon() {
        const map = {
            1: 'fa-bolt',
            2: 'fa-money-bill',
            3: 'fa-cc-stripe',
            4: 'fa-cc-paypal',
            7: 'fa-building-columns'
        }

        return map[this.ordered?.order_method] || 'fa-credit-card'
      },
      paymentMethodLabel() {
        const map = {
            1: 'Razorpay',
            2: 'Cash on Delivery',
            3: 'Stripe',
            4: 'PayPal',
            5: 'Flutterwave',
            6: 'Iyzico',
            7: 'Bank Transfer',
            8: 'Payfast',
            9: 'Credit / Debit Card (SumUp)'
        }

        return map[this.ordered?.order_method] || 'N/A'
      },
      orderId() {
        return parseInt(this.$route.params.id)
      },
      /*
       * orders.status is an integer (constants.orderStatus: 1 Pending,
       * 2 Confirmed, 3 Picked up, 4 On the way, 5 Delivered, 6 Cancelled,
       * 7 Returned, 8 Refunded). This was keyed on strings - 'placed',
       * 'processing' - so every lookup missed and every order, at every stage,
       * fell back to step 1: a delivered order still showed "Order Placed" as
       * the only completed step. The confirmation page had the same bug and
       * has already been corrected; this is the same map.
       */
      orderStatusStep() {

        const map = {
            1: 1,   // Pending      → Order Placed
            2: 2,   // Confirmed    → Order Processing
            3: 3,   // Picked up    → On Delivery
            4: 3,   // On the way   → On Delivery
            5: 4    // Delivered    → Delivery
        }

        return map[parseInt(this.ordered?.status)] || 1
      },
      /* Every line on an order shares one fulfilment type; 2 is collection. */
      isCollection() {
        return parseInt(this.ordered?.ordered_products?.[0]?.shipping_type) === 2
      },
      /*
       * Read off the order, not off the signed-in customer. The account that
       * placed the order is what the receipt is about; the account reading it
       * may since have been downgraded, suspended or renamed.
       */
      /*
       * The rate this order's VAT was charged at, for the summary label.
       *
       * Read from the rates the server split off the order's own lines, and
       * suppressed when they disagree — one percentage across a mixed-rate
       * order would be a fiction. Never computed from today's tax rules.
       */
      vatRateLabel() {
          const rates = (this.order?.calculations?.vat_breakdown || [])
              .map(row => Number(row.rate))
              .filter(rate => rate > 0)

          if (rates.length !== 1) {
              return null
          }

          return String(rates[0].toFixed(2)).replace(/\.?0+$/, '') + '%'
      },
      isBusinessOrder() {
        const type = this.ordered?.account_type

        return !!type && type !== 'personal'
      },
      accountTypeLabel() {
        return {
          business: 'Business',
          trade: 'Trade',
          personal: 'Personal'
        }[this.ordered?.account_type] || 'Personal'
      },
      shippingPlace() {
        return this.ordered?.ordered_products?.[0]?.shipping_place
      },
      /*
       * Who the store contacts about this order. The columns on the order are
       * filled in at checkout for a collection; a delivery carries the address
       * instead, and a signed-in shopper has their account.
       */
      contact() {
        return {
          name: this.ordered?.contact_name
            || this.ordered?.guest_user?.name
            || this.ordered?.address?.name
            || this.ordered?.user?.name
            || '',
          email: this.ordered?.contact_email
            || this.ordered?.guest_user?.email
            || this.ordered?.address?.email
            || this.ordered?.user?.email
            || '',
          phone: this.ordered?.contact_phone
            || this.ordered?.guest_user?.phone
            || this.ordered?.address?.phone
            || ''
        }
      },
      hasPickupPlace(){
        const index = this.ordered?.ordered_products?.findIndex(i => {
          return parseInt(i.shipping_type) === 1
        });
        return !(index !== -1)
      },
      pickupPlace() {

        if(!this.ordered?.ordered_products?.length){
          return null
        }

        const sp = this.ordered?.ordered_products[0]?.shipping_place

        if(!sp?.pickup_point){
          return null
        }

        const addrArr = [sp.pickup_address_line_1, sp.pickup_address_line_2,
          sp.pickup_zip, sp.pickup_state, sp.pickup_city, sp.pickup_country]

        let addr = addrArr.filter(i => i).join(', ')

        if(sp.pickup_phone) {
          addr = `${addr}, <span class="block">${this.$t('date.tl')}: ${sp.pickup_phone}</span>`
        }
        return  addr
      },
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      orderEmail() {
        return this.ordered?.user?.email
      },
      cancellationBtnText() {
        return this.customerCancelled ? this.$t('order.cancellationMessage') : this.$t('order.cancelOrder')
      },
      // Only a cancellation the customer asked for has a message to view.
      customerCancelled() {
        return parseInt(this.ordered.cancelled) === this.status.PUBLIC
      },
      // Cancelled by the shop or the unpaid-transfer sweep: nothing to request.
      cancelledByShop() {
        return this.orderCancelled && !this.customerCancelled
      },
      isDelivered() {
        return parseInt(this.ordered?.status) === this.orderStatusIn.DELIVERED
      },
      refunded() {
        return parseInt(this.ordered?.cancellation?.refunded) === this.status.PUBLIC || false
      },
      orderCancelled() {
        /*
         * The flag is only set when the customer cancels. An admin, or the
         * unpaid-bank-transfer sweep, moves the status to 6 (Cancelled) and
         * leaves the flag alone - which kept the Pay buttons on the order.
         */
        return parseInt(this.ordered.cancelled) === this.status.PUBLIC ||
          parseInt(this.ordered.status) === 6
      },
      /*
       * Every figure below reads through `?.` because `ordered` is {} until the
       * fetch in mounted() lands, and getOrderByUser() commits EMPTY_ORDERED
       * before each request -- so the template renders at least once with no
       * summary on it.
       *
       * Reading it directly threw "Cannot read properties of undefined
       * (reading 'subtotal')" out of the first render, which Nuxt turned into
       * the full-page error screen. The order itself was fine; the page just
       * could not survive the moment before it arrived.
       *
       * They read `calculations` -- the summary built from the order's own
       * rows -- rather than `calculated`, so this screen, the order
       * confirmation and the checkout summary are the same three numbers.
       * `isFreeShipping` and the bank-transfer popup are what still use them.
       */
      totalPrice() {
        return Number(this.ordered?.calculations?.total || 0)
      },
      voucherPrice() {
        return Number(this.ordered?.calculations?.voucher || 0)
      },
      loyaltyDiscount() {
        return Number(this.ordered?.calculations?.loyalty_discount || 0)
      },
      loyaltyLabel() {
        return this.ordered?.calculations?.loyalty_label || 'Loyalty Discount'
      },
      pointsDiscount() {
        return Number(this.ordered?.calculations?.points_discount || 0)
      },
      bundleOffer() {
        return Number(this.ordered?.calculations?.bundle_offer || 0)
      },
      shippingPrice() {
        return Number(this.ordered?.calculations?.shipping || 0)
      },
      taxPrice() {
        return Number(this.ordered?.calculations?.tax || 0)
      },
      subtotalPrice() {
        return Number(this.ordered?.calculations?.subtotal || 0)
      },
      /*
       * `orderId` was declared twice in this object, identically. The second
       * silently replaced the first, so nothing misbehaved -- but a duplicate
       * option key is how this codebase loses a computed without a warning,
       * and the next edit to either copy would have been the one that hurt.
       */
      ...mapGetters('language', ['langCode']),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('order', ['ordered']),
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting'])
    },
    methods: {
      formatPrice(value) {
        return Number(value || 0).toFixed(2)
      },
      /*
       * Every figure on this page was written with a hard-coded €, which is
       * wrong for any shop not selling in euro and ignores the currency
       * position and decimal format the admin sets. Same helper the order
       * confirmation uses, so the two screens are formatted alike as well as
       * calculated alike.
       */
      money(value) {
        return this.priceFormat(
          this.currencyPosition,
          this.currencyIcon,
          Number(value || 0).toFixed(2),
          this.setting
        )
      },
      /*
       * A summary that lists "Flash Discount - €0.00" and "Tax + €0.00" on an
       * order that had neither makes the reader check two lines that say
       * nothing. Only rows carrying a figure are shown; Subtotal, Shipping and
       * Total always are.
       */
      showRow(label) {

        const key = {
          'Product Discount': 'discount',
          'Bundle Offer': 'bundle_offer',
          'Flash Discount': 'flash_discount',
          // The Essentials Bundle saving. 'Bundle Offer' above is the other
          // feature: what a buy-X-get-Y-free deal gave away.
          'Bundle Saving': 'bundle_discount',
          'Voucher Discount': 'voucher',
          'Loyalty Discount': 'loyalty_discount',
          'Points': 'points_discount',
          'Tax': 'tax'
        }[label]

        return Number(this.ordered?.calculations?.[key] || 0) > 0
      },
      /* The variation bought, as label/value pairs rather than a slash list. */
      itemAttributes(item) {
        const attributes = item?.updated_inventory?.inventory_attributes || []

        return attributes
          .map(row => ({
            label: row?.attribute_value?.attribute?.title || '',
            value: row?.attribute_value?.title || ''
          }))
          .filter(attr => attr.value)
      },
      /*
       * products.image carries two conventions: a bare file name, and one
       * already prefixed with "uploads/". img_src_url ends in /uploads/, so the
       * prefixed form resolved to /uploads/uploads/... and broke.
       */
      productImage(item) {
        const image = (item?.product?.image || '').replace(/^\/?uploads\//, '')

        return image ? this.getImageURL(image) : this.getImageURL()
      },
      /* A file no longer on disk shows the placeholder, not a broken icon. */
      onImageError(event) {
        const fallback = this.getImageURL()

        if (event.target.src !== fallback) {
          event.target.src = fallback
        }
      },
      currentImage(value){
        const invAttr = value?.updated_inventory?.inventory_attributes;

        if(invAttr?.length && invAttr[0]?.attribute_value) {

          const item = value?.product_images.find(i => {
            return i.attribute_value_id === invAttr[0]?.attribute_value.id
          })

          return item?.image?.image ?? value.product?.image

        }
        return value.product?.image;
      },
      closeBankVerify(evt) {
        this.verifyPayment = false
        this.updateOrderData({trans_id: evt})
      },
      orderCancelling() {
        this.cancelPopup = false
        this.fetchingData()
      },
      generateAddress(obj) {
        if (!obj) {
          return ''
        }
        let addArr = []
        addArr.push(obj?.address_1 || '')
        if (obj?.address_2) {
          addArr.push(obj?.address_2)
        }
        addArr.push(obj?.city + '-' + obj?.zip)

        if (this.countryList[obj?.country]) {
          const country = this.countryList[obj?.country]

          if (country.states[obj?.state]) {
            addArr.push(country?.states[obj?.state]?.name)
          }
          addArr.push(country?.name)
        }
        this.ordered['formatted_address'] = addArr.join(', ')
        return this.ordered['formatted_address']
      },
      generatingAttribute(attr) {
        return attr?.updated_inventory?.inventory_attributes?.map(i => {
          return [i?.attribute_value?.attribute?.title, i?.attribute_value?.title]
        })
      },
      async fetchingData() {
        this.fetchingOrderData = true
        try {
          const data = await this.getOrderByUser({
            payload: {
              order_id: this.orderId,
              user_token: await this.getUserToken(),
              time_zone: this.timeZone
            },
            lang: this.langCode
          })

          if(data?.status === 403 && !this.$auth?.loggedIn){
            this.$auth.redirect('login')
            return
          }

          if (data?.status !== 200) {
            this.hasError(data)
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.fetchingOrderData = false
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('user', ['getUserToken']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('order', ['getOrderByUser', 'cancelOrder', 'updateOrderData']),
    },
    async mounted() {
      if (!this.countryList || !this.phoneList) {
        this.fetchingOrderData = true

        const {data} = await this.getRequest({
          params: null,
          lang: this.langCode,
          api: 'countriesPhones'
        })

        this.setCountryList(data?.countries)
        this.setPhoneList(data?.phones)
        this.fetchingOrderData = false
      }

      await this.fetchingData()
    },
    async asyncData({store, error, $auth}) {
      try {
        if (!store.state?.common?.setting?.guest_checkout) {
          if (!$auth.loggedIn) {
            $auth.redirect('login')
            return false
          }
        }

        if (!store.state.common.paymentGateway) {
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
span.product-thanku-tittle {
    color: #232159;
    font-size: 22px;
}
span.product-model {
    color: #232159;
    font-size: 16px;
}
.mobile-quantity {
    color: #232159;
}
body {
    background: #f5f6fa;
    font-family: 'Segoe UI', sans-serif;
}

.main-card {
    background: #fff;
    border-radius: 20px;
    padding: 40px 50px 40px 50px;
    max-width: 1200px;
    margin: 40px auto;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.order-box {
    background: #F3F8FF;
    border-radius: 20px;
    padding: 20px 20px 20px 20px;
}

.manage-btn {
    background: #33319A;
    color: #fff;
    border-radius: 20px;
    padding: 6px 18px;
    font-size: 14px;
    font-weight: 600;
}
.manage-btn:hover {
    background: #05B942;
    color: #Fff;
}

.section-title-billing {
    font-weight: 500;
    margin-bottom: 10px;
    font-size: var( --product-font);
    color: #232159;
}

.small-text-thanku {
    font-size: 14px;
    color: #415A80;
    font-weight: 500;
}

.timeline {
    display: flex;
    justify-content: center;
    margin: 25px 0;
    gap: 9rem;
    border-top: 1px solid #EEEEF3;
    border-bottom: 1px solid #EEEEF3;
    padding: 30px 0;
}

.price-box-thnaku { 
    font-size: 13px;
}

.subtotal {
    text-align: right;
    font-weight: 600;
    margin-top: 20px;
}
.order-placed {
    font-size: 24px;
    font-weight: 600;
    color: #232159;
}
.small-text-order {
    font-size: 16px;
    font-weight: 600;
    color: #232159;
}
.small-text-order strong {
    font-weight: 500;
}
ul.billing-address {
    font-size: 16px;
    font-weight: 500;
    color: #232159;
    line-height: 32px;
}

.billing-address li {
    display: flex;
    font-size: 14px;
    color: #232159;
}
.status-badge {
    color: #fff;
    font-size: 14px;
    padding: 5px 17px;
    border-radius: 100px;
    font-weight: 600;
}
strong.payment-id {
    color: #232159;
    font-size: 16px;
    font-weight: 700;
}

.payment-method{
    font-size: 14px;
    color: #232159;
}
.price-box-thanku p {
    font-size: 16px;
    font-weight: 500;
    color: #232159;
    margin-bottom: 3px;
}

.ordered-product-image{
    width: 126px;
    height: 156px;
    object-fit: contain;
    border-radius: 10px;
}
.complete-order-image{
    width: 128px;
    height: 100px;
}

.payment-info { 
    border-top: 1px solid #eeeef3;
    padding-top: 30px;
}
.steps {
    display: flex;
    align-items: center;
    position: relative;
    gap: 60px;
}

/* Line (full) */
.steps::before {
    content: "";
    position: absolute;
    top: 18px;
    left: 35px;
    right: 35px;
    height: 2px;
    background: #e0e0e0;
    z-index: 0;
}

/* Active line */
.steps::after {
    content: "";
    position: absolute;
    top: 18px;
    left: 35px;
    height: 2px;
    background: #4f46e5;
    z-index: 1;
    transition: width 0.3s ease;
    width: var(--progress-width, 0%);
}

/* Step */
.step {
    text-align: center;
    position: relative;
    z-index: 2;
}

/* Circle */
.circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: #555;
    margin: auto;
}

/* Active / Completed */
.step.active .circle,
.step.completed .circle {
    background: #33319A;
    color: #fff;
}

/* Label */
.step-label {
    margin-top: 8px;
    font-size: 14px;
    color: #6b7280;
}

/* ---------------------------------------------------------------------------
   The order summary and the product rows, matched to the order confirmation.

   This screen carried no styles for either: the summary rows it now draws had
   no rule at all, so the label and the amount ran together on one line instead
   of being pushed apart, and there was no media query anywhere in the file -
   50px of card padding, a 9rem timeline gap and a two-column product row on a
   390px phone.
   --------------------------------------------------------------------------- */

/* The line's own total, set off from the rows that build up to it. */
.line-subtotal {
    border-top: 1px solid #EEEEF3;
    margin-top: 8px;
    padding-top: 8px;
}

/* The unit price the line was discounted from, beside what was charged. */
.unit-was {
    margin-left: 6px;
    font-weight: 500;
    color: #8A8FA3;
}

.product-thanks-media {
    min-width: 0;
    flex: 1 1 auto;
}

.product-thanks-prices {
    flex: 0 0 auto;
    min-width: 230px;
}

.account-box {
    background: #F8F9FC;
    border: 1px solid #EEEEF3;
    border-radius: 14px;
    padding: 18px 20px;
}

ul.account-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 10px 28px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.account-facts li {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.fact-label {
    font-size: 12.5px;
    font-weight: 500;
    color: #737987;
}

/* A long company name or VAT number wraps rather than widening the panel. */
.fact-value {
    font-size: 14.5px;
    font-weight: 600;
    color: #232159;
    overflow-wrap: anywhere;
}

.order-summary-box {
    width: 100%;
    max-width: 420px;
    margin-left: auto;
    border-top: 1px solid #EEEEF3;
    padding-top: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    font-size: 15px;
    color: #232159;
}

/* Separates the goods from what they net down to, matching the rule
   .total-row draws above the payable figure. */
.summary-rule {
    border-top: 1px dashed #DCDCE6;
    margin: 15px 0;
}

/* Describes the line above rather than adding a charge of its own. */
.summary-row-sub {
    margin-top: -6px;
    padding-left: 12px;
    font-size: 13.5px;
    color: #737987;
}

.total-row {
    border-top: 1px solid #EEEEF3;
    padding-top: 15px;
    margin-top: 15px;
    font-size: 18px;
    font-weight: 700;
}

.text-success {
    color: #05B942;
}

@media (max-width: 991px) {
    .main-card {
        padding: 28px 24px;
        margin: 20px auto;
    }
    .timeline {
        gap: 0;
        padding: 24px 0;
    }
    .steps {
        gap: 40px;
    }
}

@media (max-width: 767px) {
    .main-card {
        padding: 18px 14px;
        margin: 12px auto;
        border-radius: 14px;
    }

    /* the buttons sat beside two lines of text and squeezed both */
    .order-box {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
        padding: 14px;
        border-radius: 14px;
    }
    .small-text-order {
        font-size: 13px;
        line-height: 1.6;
    }
    .manage-btn {
        width: 100%;
        font-size: 13px;
        margin-right: 0 !important;
    }

    .section-title-billing {
        font-size: 14px;
        margin-bottom: 6px;
    }
    ul.billing-address {
        font-size: 13px;
        line-height: 1.7;
        padding-left: 0;
        list-style: none;
    }
    .billing-address li {
        font-size: 12.5px;
    }
    .row.mb-4.mt-5 > [class*="col-"] + [class*="col-"] {
        margin-top: 18px;
    }

    .account-box {
        padding: 14px;
        border-radius: 14px;
    }
    ul.account-facts {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .fact-label {
        font-size: 11.5px;
    }
    .fact-value {
        font-size: 13px;
    }

    .payment-info {
        padding-top: 18px;
    }
    .payment-info h6 {
        font-size: 13px;
        margin-bottom: 0;
    }
    /* text-end is a desktop alignment; stacked, it reads as a stray column */
    .payment-info .text-end {
        margin-top: 14px;
        text-align: left !important;
    }
    .payment-info .text-end .d-flex {
        justify-content: flex-start !important;
    }
    .status-badge {
        font-size: 12px;
        padding: 4px 12px;
    }
    .payment-method,
    strong.payment-id {
        font-size: 13px;
    }

    /* four steps at a 60px gap do not fit 390px; let them share the width */
    .timeline {
        margin: 18px 0;
        padding: 20px 0;
        overflow: hidden;
    }
    .steps {
        width: 100%;
        gap: 0;
    }
    .step {
        flex: 1 1 0;
        min-width: 0;
    }
    .circle {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    .steps::before {
        top: 14px;
        left: 12.5%;
        right: 12.5%;
    }
    .steps::after {
        top: 14px;
        left: 12.5%;
        width: calc(var(--progress-width, 0%) * 0.75);
    }
    .step-label {
        margin-top: 6px;
        font-size: 10.5px;
        line-height: 1.25;
        padding: 0 2px;
    }

    /* image, details and prices in one column instead of two pushed apart */
    .product-thanks {
        flex-direction: column;
        gap: 12px;
    }
    .product-thanks-prices {
        min-width: 0;
    }
    .ordered-product-image {
        width: 78px;
        height: 92px;
    }
    .product-info {
        margin-left: 12px !important;
        margin-top: 0 !important;
        min-width: 0;
    }
    span.product-thanku-tittle {
        font-size: 14px;
        line-height: 1.35;
    }
    span.product-model,
    .mobile-quantity {
        font-size: 12px;
    }
    .unit-was {
        margin-left: 4px;
        font-size: 12px;
    }
    .price-box-thanku {
        justify-content: space-between !important;
    }
    .price-box-thanku p {
        display: flex;
        justify-content: space-between;
        width: 100%;
        gap: 12px;
        font-size: 12.5px;
        margin-bottom: 5px;
    }

    /* the summary is pinned right on desktop; on a phone it is the full width */
    .order-summary-box {
        max-width: none;
        margin-left: 0;
        padding-top: 14px;
    }
    .summary-row {
        margin-bottom: 9px;
        font-size: 13px;
    }
    .total-row {
        margin-top: 12px;
        padding-top: 12px;
        font-size: 15px;
    }
}

@media (max-width: 400px) {
    .step-label {
        font-size: 9.5px;
    }
    .circle {
        width: 26px;
        height: 26px;
        font-size: 11px;
    }
    .steps::before,
    .steps::after {
        top: 13px;
    }
}
</style>
