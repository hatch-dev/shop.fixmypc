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

        <div
          class="spinner-wrapper flex"
          v-if="fetchingOrderData"
        >
          <spinner
            :radius="100"
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
          <div class="p-20 p-sm-15 pt-20">
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

                <button v-if="!isDelivered"
                    aria-label="submit"
                    class="btn manage-btn plr-30 plr-sm-15"
                    @click="cancelPopup = true"
                  >
                    {{ cancellationBtnText }}
                </button>
            </div>

            <!-- Address -->
            <div class="row mb-4 mt-5">
                <div class="col-md-6">
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
                </div>

                <div class="col-md-6">
                    <div class="section-title-billing"">Shipping Address</div>
                    <ul class="billing-address">
                        <li>
                            {{ [
                            ordered?.ordered_products?.[0]?.shipping_place?.pickup_address_line_1,
                            ordered?.ordered_products?.[0]?.shipping_place?.pickup_address_line_2
                            ].filter(Boolean).join(', ') }}
                        </li>

                        <li>
                            {{ ordered?.ordered_products?.[0]?.shipping_place?.pickup_city }}
                            , {{ ordered?.ordered_products?.[0]?.shipping_place?.pickup_state }}
                            - {{ ordered?.ordered_products?.[0]?.shipping_place?.pickup_zip }}
                        </li>

                        <li>{{ ordered?.ordered_products?.[0]?.shipping_place?.pickup_country }}</li>

                        <li>{{ ordered?.ordered_products?.[0]?.shipping_place?.pickup_phone }}</li>

                        <li>
                            Delivery in {{ ordered?.ordered_products?.[0]?.shipping_place?.day_needed }} days
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Payment -->
            <div class="row mb-4 payment-info">
                <div class="col-md-6 small-text">
                    <div class="section-title-billing">Payment Information</div>
                <div class="d-flex gap-2">
                    <h6>Payment Amount:</h6> <span class="text-success fw-bold"> €{{ Number(ordered?.calculated?.total_price || 0).toFixed(2) }}</span>
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
                    <h6> Payment Transaction ID: </h6><strong class="payment-id"> {{ ordered?.transaction_id || 'N/A' }}</strong><br>
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

            <div 
              v-for="(item, index) in ordered.ordered_products" 
              :key="index"
              class="product-thanks d-flex justify-content-between mb-20"
            >
              <div class="d-flex">
                <img 
                  :src="getImageURL(item.product?.image)" 
                  class="ordered-product-image"
                >

                <div class="product-info ms-4 mt-2">
                  <span class="product-thanku-tittle fw-semibold">
                    {{ item.product?.title }}
                  </span><br>

                  <span class="product-model fw-normal">
                    ({{ item?.updated_inventory?.inventory_attributes
                      ?.map(i => i.attribute_value.title).join(' / ') }})
                  </span><br>

                  <span class="fw-semibold mobile-quantity">
                    Quantity: {{ item.quantity }}
                  </span>
                </div>
              </div>

              <div class="mt-2">
                <div class="d-flex justify-content-end price-box-thanku">
                  <p>Total Price: 
                    <span class="fw-bold">
                      €{{ formatPrice(item.selling) }}
                    </span>
                  </p>
                </div>

                <div class="d-flex justify-content-end price-box-thanku">
                  <p>Shipping Price: 
                    <span class="fw-bold">
                      €{{ formatPrice(item.shipping_price) }}
                    </span>
                  </p>
                </div>

                <div class="d-flex justify-content-end price-box-thanku">
                  <p>Tax Price: 
                    <span class="fw-bold">
                      €{{ formatPrice(item.tax_price) }}
                    </span>
                  </p>
                </div>

                <div class="d-flex justify-content-end price-box-thanku">
                  <p>Subtotal: 
                    <span class="fw-bold">
                      €{{ formatPrice(getItemSubtotal(item)) }}
                    </span>
                  </p>
                </div>
              </div>
            </div>

            <div class="flex right no-space">
              <ul
                class="mx-w-400x order-details order-price"
              >
                <!-- <li>
                  <span>
                    {{ $t('order.subtotal') }}
                  </span>
                  <span class="semi-bold">
                    <price-format
                      :price="subtotalPrice"
                    />
                  </span>
                </li> -->
                <!-- <li>
                  <span>
                    {{ $t('order.shippingCost') }}
                  </span>
                  <span class="semi-bold">
                    <span
                      v-if="isFreeShipping"
                      class="color-free">
                      {{ $t('invent.fre') }}
                    </span>
                    <price-format
                      v-else
                      :price="shippingPrice"
                    />
                  </span>
                </li> -->
                <!-- <li v-if="bundleOffer">
                  <span>
                    {{ $t('cartProductTile.bundleOffer') }}
                  </span>
                  <span class="semi-bold">
                    <price-format
                      :price="bundleOffer"
                    />
                  </span>
                </li> -->
                <!-- <li v-if="voucherPrice">
                  <span>
                     {{ $t('checkoutRight.voucher') }}
                  </span>
                  <span class="semi-bold">
                    <price-format
                      :price="voucherPrice"
                    />
                  </span>
                </li> -->
                <!-- <li v-if="taxPrice">
                  <span>
                     {{ $t('cart.tax') }}
                  </span>
                  <span class="semi-bold">
                    <price-format
                      :price="taxPrice"
                    />
                  </span>
                </li> -->
                <!-- <li
                  class="mb-0" -->
                  <!-- <span>
                    {{ $t('checkoutRight.total') }}
                  </span> -->
                  <!-- <span class="semi-bold f-11"> -->
                    <!-- <price-format
                      :price="totalPrice"
                    /> -->
                    <div class="d-flex justify-content-end price-box-thanku">
                      <p>
                        Subtotal:
                        <span class="fw-bold">
                          €{{ formatPrice(subtotalPrice) }}
                        </span>
                      </p>
                    </div>

                    <div class="d-flex justify-content-end price-box-thanku">
                      <p>
                        Shipping:
                        <span class="fw-bold">
                          €{{ formatPrice(shippingPrice) }}
                        </span>
                      </p>
                    </div>

                    <div class="d-flex justify-content-end price-box-thanku">
                      <p>
                        Tax:
                        <span class="fw-bold">
                          €{{ formatPrice(taxPrice) }}
                        </span>
                      </p>
                    </div>

                    <div class="d-flex justify-content-end price-box-thanku">
                      <p>
                        Voucher:
                        <span class="fw-bold">
                          <price-format
                            :price="voucherPrice"
                          />
                        </span>
                      </p>
                    </div>

                    <div class="d-flex justify-content-end price-box-thanku">
                      <p>
                        Total:
                        <span class="fw-bold text-success">
                          €{{ formatPrice(totalPrice) }}
                        </span>
                      </p>
                    </div>
                  <!-- </span> -->
                <!-- </li> -->
                <!-- <li
                  v-if="!isDelivered"
                  class="pb-0 mb-0 j-end mt-15 mt-sm"
                >
                  <button
                    aria-label="submit"
                    class="outline-btn plr-30 plr-sm-15"
                    @click="cancelPopup = true"
                  >
                    {{ cancellationBtnText }}
                  </button>
                </li> -->
              </ul>
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
  import Spinner from "~/components/Spinner"
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
      Spinner,
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
      orderStatusStep() {
        const status = this.ordered?.status

        const map = {
            placed: 1,
            processing: 2,
            shipping: 3,
            delivered: 4
        }

        return map[status] || 1
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
        return this.orderCancelled ? this.$t('order.cancellationMessage') : this.$t('order.cancelOrder')
      },
      isDelivered() {
        return parseInt(this.ordered?.status) === this.orderStatusIn.DELIVERED
      },
      refunded() {
        return parseInt(this.ordered?.cancellation?.refunded) === this.status.PUBLIC || false
      },
      orderCancelled() {
        return parseInt(this.ordered.cancelled) === this.status.PUBLIC
      },
      totalPrice() {
        return this.ordered.calculated.total_price
      },
      voucherPrice() {
        return this.ordered.calculated.voucher_price
      },
      bundleOffer() {
        return this.ordered.calculated.bundle_offer
      },
      shippingPrice() {
        return this.ordered.calculated.shipping_price
      },
      taxPrice() {
        return this.ordered.calculated.tax
      },
      subtotalPrice() {
        return this.ordered.calculated.subtotal
      },
      orderId() {
        return parseInt(this.$route.params.id)
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('order', ['ordered']),
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    methods: {
      formatPrice(value) {
        return Number(value || 0).toFixed(2)
      },
      getItemSubtotal(item) {
          const total =
              parseFloat(item.selling || 0) +
              parseFloat(item.shipping_price || 0) +
              parseFloat(item.tax_price || 0)

          return total.toFixed(2)
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
</style>
