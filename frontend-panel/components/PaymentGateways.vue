<template>
  <div
    class="pos-rel"
  >
    <stripe-payment
      v-if="showStripe"
      :stripe-key="paymentGateway.stripe_key"
      :order-id="orderId"
      :amount="parseFloat(amount)"
      :currency="currencyData"
      :name="userName"
      :site-name="site_setting.siteName"
      :email="userEmail"
      @success="orderPlaced('success', $event)"
      @closed="orderPlaced('closed', $event)"
    />
    
    <razorpay-payment
      v-else-if="showRazorpay"
      :order-id="orderId"
      :razorpay-key="paymentGateway.razorpay_key"
      :razorpay-payment-token="razorpayPaymentToken"
      :amount="parseFloat(amount)"
      :site-name="site_setting.siteName"
      :currency="currencyData"
      :name="userName"
      :email="userEmail"
      @success="orderPlaced('success', $event)"
      @closed="orderPlaced('closed', $event)"
      @error="orderPlaced('error', $event)"
    />

    <transition
      name="fade"
      mode="out-in"
    >
      <div
        class="spinner-wrapper flex layer-white"
        v-if="loading || payFastLoader"
      >
        <spinner
          :radius="100"
        />
      </div>
    </transition>

    <p
      v-if="orderError"
      class="f-13 error mb-15"
    >
      <span v-for="i in orderError" class="block">{{ i }}</span>
    </p>
    <form
      v-if="paymentGateway"
    >
      <p
        v-if="noPaymentMethod"
        class="info mt-15"
      >
        {{ $t('checkout.noPayment') }}
      </p>

      <div
        class="heading-tab-wrapper"
      >
        <div
          class="tab-heading"
        >

          <label
            v-if="parseInt(paymentGateway.stripe) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.STRIPE}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.STRIPE"
              v-model="paymentType"
            >
            <i class="icon stripe-icon"/>
            <span>{{ $t('payment.stripe') }}</span>
          </label>

          <label
            v-if="parseInt(paymentGateway.sumup) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.SUMUP}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.SUMUP"
              v-model="paymentType"
            >
            <svg width="85" height="45" viewBox="0 0 444 128" fill="none" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
              <path d="M299.853 35.6328H299.577C294.658 35.6328 290.198 37.6507 287.026 40.9069C283.854 37.6966 279.394 35.6787 274.475 35.6787H274.199C264.544 35.6787 256.637 43.4752 256.637 53.0603V86.9979H256.683C256.821 89.612 258.981 91.7217 261.648 91.7217C264.314 91.7217 266.475 89.612 266.613 86.9979H266.659V53.0603C266.659 48.9327 270.061 45.5389 274.245 45.5389H274.521C278.613 45.5389 281.923 48.7034 282.061 52.7392C282.061 52.831 282.061 52.9227 282.061 53.0144V86.9979C282.107 88.0069 282.428 88.8783 282.98 89.6579C283.9 90.942 285.371 91.7675 287.026 91.7675C289.692 91.7675 291.853 89.6579 291.991 87.0438H292.037V53.1061C292.037 53.0144 292.037 52.8768 292.037 52.7851C292.175 48.7951 295.531 45.5848 299.577 45.5848H299.853C304.037 45.5848 307.439 48.9327 307.439 53.1061V86.7228C307.439 86.7686 307.439 86.7686 307.439 86.8145C307.439 86.8603 307.439 86.9062 307.439 86.9062V87.0438C307.577 89.6579 309.737 91.7675 312.404 91.7675C315.07 91.7675 317.231 89.6579 317.369 87.0438H317.415V53.0603C317.369 43.4293 309.508 35.6328 299.853 35.6328Z" fill="black">
              </path>
              <path d="M240.819 35.6328C238.107 35.6328 235.946 37.7425 235.808 40.3566V74.3401C235.808 78.4676 232.36 81.8155 228.13 81.8155H227.855C223.625 81.8155 220.223 78.4676 220.223 74.3401V40.6776C220.223 40.6317 220.223 40.6317 220.223 40.5859V40.4942V40.3566C220.085 37.7425 217.924 35.6328 215.212 35.6328C212.499 35.6328 210.338 37.7425 210.2 40.3566H210.154V74.3401C210.154 83.9252 218.108 91.7216 227.9 91.7216H228.176C237.969 91.7216 245.923 83.9252 245.923 74.3401V40.3566C245.693 37.7425 243.532 35.6328 240.819 35.6328Z" fill="black">
              </path>
              <path d="M359.108 35.6328C356.396 35.6328 354.235 37.7425 354.097 40.3566H354.051V74.3401C354.051 78.4676 350.603 81.8155 346.419 81.8155H346.144C341.914 81.8155 338.512 78.4676 338.512 74.3401V40.6776C338.512 40.6317 338.512 40.6317 338.512 40.5859V40.4942V40.3566C338.374 37.7425 336.213 35.6328 333.501 35.6328C330.788 35.6328 328.627 37.7425 328.489 40.3566H328.443V74.3401C328.443 83.9252 336.397 91.7216 346.19 91.7216H346.465C356.258 91.7216 364.212 83.9252 364.212 74.3401V40.3566H364.166C364.028 37.7425 361.821 35.6328 359.108 35.6328Z" fill="black">
              </path>
              <path d="M392.396 35.6328H392.12C382.19 35.6328 374.19 43.6127 374.19 53.3813V73.9273C374.19 74.5235 374.144 75.1197 374.19 75.7159V114.836C374.19 117.542 376.443 119.743 379.202 119.743C381.96 119.743 384.213 117.542 384.213 114.836V89.2451C386.098 90.8961 389.27 91.7216 392.12 91.7216H392.396C402.327 91.7216 409.913 83.2372 409.913 73.4687V52.9227C409.913 43.1083 402.327 35.6328 392.396 35.6328ZM400.166 73.9732C400.166 79.339 396.672 81.6321 392.396 81.6321H392.12C387.845 81.6321 384.351 79.339 384.351 73.9732V53.4271C384.351 49.2079 387.845 45.7682 392.12 45.7682H392.396C396.672 45.7682 400.166 49.2079 400.166 53.4271V73.9732Z" fill="black">
              </path>
              <path d="M185.553 57.784C179.898 55.5827 176.45 54.0692 176.45 50.7672C176.45 48.1531 178.565 45.4931 183.3 45.4931C186.289 45.4931 188.771 46.7772 190.564 49.2079C191.76 50.7672 193.093 51.5468 194.564 51.5468C197.414 51.5468 199.805 49.2537 199.805 46.5479C199.805 45.4931 199.529 44.53 198.978 43.7503C195.851 38.9807 189.415 35.6328 183.3 35.6328C174.887 35.6328 166.381 40.8152 166.381 50.7672C166.381 60.9943 174.841 64.2505 181.599 66.8646C187.024 68.9743 191.714 70.7629 191.714 75.2573C191.714 78.5594 188.633 81.9073 182.702 81.9073C180.909 81.9073 177.507 81.4945 175.255 78.7887C174.059 77.4128 172.634 76.6332 171.255 76.6332C168.542 76.6332 166.014 79.018 166.014 81.6321C166.014 82.6869 166.335 83.65 166.979 84.6131C170.105 89.291 177.231 91.7675 182.657 91.7675C191.943 91.7675 201.782 85.989 201.782 75.3032C201.782 64.1129 192.771 60.6274 185.553 57.784Z" fill="black">
              </path>
              <path d="M119.902 0H9.2409C4.13772 0 0 4.08169 0 9.12648V118.874C0 123.918 4.13772 128 9.2409 128H119.902C125.005 128 129.143 123.918 129.143 118.874V9.12648C129.097 4.08169 125.005 0 119.902 0ZM81.0992 96.3554C69.8355 107.546 51.8134 108.05 39.9519 97.823C39.906 97.7772 39.814 97.7313 39.7681 97.6396C39.0325 96.9058 39.0325 95.7592 39.7221 95.0254L79.766 55.3092C80.5016 54.6213 81.6509 54.6213 82.3865 55.3551C92.8687 67.1415 92.409 85.1193 81.0992 96.3554ZM89.4206 32.9287L49.3767 72.6449C48.6412 73.3329 47.4918 73.3329 46.7562 72.5991C36.274 60.8585 36.6878 42.8807 48.0435 31.6446C59.3073 20.4543 77.3293 19.9957 89.1908 30.177C89.2367 30.2229 89.3287 30.2687 89.3747 30.3604C90.1103 31.0484 90.1103 32.1949 89.4206 32.9287Z" fill="black">
              </path>
              <path d="M433.221 35.6328C427.704 35.6328 423.244 40.0814 423.244 45.5389C423.244 50.9965 427.704 55.4451 433.221 55.4451C438.738 55.4451 443.197 50.9965 443.197 45.5389C443.197 40.0814 438.738 35.6328 433.221 35.6328ZM433.221 53.0602C429.037 53.0602 425.681 49.7123 425.681 45.5389C425.681 41.4114 429.083 38.0635 433.221 38.0635C437.404 38.0635 440.76 41.4114 440.76 45.5389C440.76 49.6665 437.358 53.0602 433.221 53.0602Z" fill="black">
              </path>
              <path d="M434.328 45.9106C435.616 45.6812 436.443 44.718 436.443 43.3878C436.443 41.6907 435.248 40.5898 433.409 40.5898H430.926C430.374 40.5898 429.914 40.9568 429.914 41.5989V48.5709C429.914 49.3048 430.328 49.6718 430.926 49.6718C431.523 49.6718 431.937 49.3048 431.937 48.5709V46.0023H431.983L434.512 49.1672C434.788 49.4883 434.972 49.6718 435.478 49.6718C436.26 49.6718 436.535 49.0296 436.535 48.7085C436.535 48.3875 436.397 48.1123 436.122 47.837L434.328 45.9106ZM433.317 44.4887H431.983V42.5163H433.317C433.914 42.5163 434.374 42.8833 434.374 43.4796C434.374 44.03 433.96 44.4887 433.317 44.4887Z" fill="black">
              </path>
            </svg>
            <span class="sumup">SumUp</span>
          </label>


          <label
            v-if="parseInt(paymentGateway.flutterwave) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.FLUTTERWAVE}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.FLUTTERWAVE"
              v-model="paymentType"
            >
            <i class="icon flutterwave-icon"/>
            <span>{{ $t('payment.flutterwave') }}</span>
          </label>


          <label
            v-if="parseInt(paymentGateway.razorpay) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.RAZORPAY}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.RAZORPAY"
              v-model="paymentType"
            >
            <i class="icon razorpay-icon"/>
            <span>{{ $t('payment.razorpay') }}</span>
          </label>


          <label
            v-if="parseInt(paymentGateway.paypal) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.PAYPAL}"
          >
            <span
              class="spinner-wrapper flex layer-white"
              v-if="!paypaLoaded && paymentType === orderMethods.PAYPAL"
            >
              <spinner
                :radius="50"
              />
            </span>

            <input
              type="radio"
              name="payment"
              :value="orderMethods.PAYPAL"
              v-model="paymentType"
            >
            <i class="icon paypal-icon"/>
            <span>{{$t('payment.paypal')}}</span>
          </label>


          <label
            v-if="parseInt(paymentGateway.iyzico_payment) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.IYZICO_PAYMENT}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.IYZICO_PAYMENT"
              v-model="paymentType"
            >
            <i class="icon iyzico-icon"/>
            <span>{{ $t('filter.payIyzico') }}</span>
          </label>


          <label
            v-if="parseInt(paymentGateway.payfast_payment) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.PAYFAST}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.PAYFAST"
              v-model="paymentType"
            >
            <i class="icon payfast-icon"/>
            <span>{{ $t('invent.pf') }}</span>
          </label>



          <label
            v-if="parseInt(paymentGateway.cash_on_delivery) === status.PUBLIC"
            :class="{active: paymentType === orderMethods.CASH_ON_DELIVERY}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.CASH_ON_DELIVERY"
              v-model="paymentType"
            >
            <i class="icon cod-icon"/>
            <span>{{ $t('orderTabbing.cod') }}</span>
          </label>


          <label
            v-if="parseInt(paymentGateway.bank) === status.PUBLIC && orderMethod !== orderMethods.BANK"
            :class="{active: paymentType === orderMethods.BANK}"
          >
            <input
              type="radio"
              name="payment"
              :value="orderMethods.BANK"
              v-model="paymentType"
            >
            <i class="icon bank-icon mb-5"/>
            <span>{{ $t('date.bt') }}</span>
          </label>

        </div>

        <div
          class="tab-content"
        >
          <ajax-button
            v-if="paymentType === orderMethods.STRIPE"
            class="primary-btn  plr-30 plr-sm-15"
            type="button"
            :fetching-data="placingOrder"
            :disabled="!totalPrice || noPaymentMethod"
            :text="paymentBtnText"
            @clicked="initStripe"
          />

          <ajax-button
            v-else-if="paymentType === orderMethods.SUMUP"
            class="primary-btn plr-30 plr-sm-15"
            type="button"
            :fetching-data="placingOrder"
            :disabled="!totalPrice || noPaymentMethod"
            :text="paymentBtnText"
            @clicked="initSumup"
          />

          <ajax-button
            v-else-if="paymentType === orderMethods.RAZORPAY"
            class="primary-btn  plr-30 plr-sm-15"
            type="button"
            :fetching-data="placingOrder"
            :disabled="!totalPrice || noPaymentMethod"
            :text="paymentBtnText"
            @clicked="initRazorpay"
          />

          <div
            v-else-if="paymentType === orderMethods.PAYFAST"
          >

            <ajax-button
              class="primary-btn  plr-30 plr-sm-15"
              type="button"
              :fetching-data="placingOrder"
              :disabled="!totalPrice || noPaymentMethod"
              :text="paymentBtnText"
              @clicked="initPayFast"
            />

            <div v-if="payFastData" ref="payFastContainer" v-html="payFastForm"></div>

          </div>


          <div
            v-else-if="paymentType === orderMethods.CASH_ON_DELIVERY"
          >
            <ajax-button
              class="primary-btn  plr-30 plr-sm-15"
              type="button"
              :fetching-data="placingOrder"
              :disabled="!totalPrice || noPaymentMethod"
              :text="$t('checkout.confirmOrder')"
              @clicked="confirmOrder"
            />
          </div>


            <ajax-button
              v-else-if="paymentType === orderMethods.BANK"
              class="primary-btn  plr-30 plr-sm-15"
              type="button"
              :fetching-data="placingOrder"
              :disabled="!totalPrice || noPaymentMethod"
              :text="$t('checkout.confirmOrder')"
              @clicked="confirmOrder"
            />


          <div
            v-else-if="paymentType === orderMethods.IYZICO_PAYMENT"
          >
            <iyzico-payment
              ref="iyzicoPayment"
              :order="orderData"
              :btn-text="paymentBtnText"
              @clicked="payWithIyzicoPayment"
              @success="izcoOrderPlaces"
              @closed="orderPlaced('closed', $event)"
              @error="orderPlaced('error', $event)"
            />
          </div>


          <div
            v-else-if="paymentType === orderMethods.FLUTTERWAVE"
          >
            <flutterwave-pay-btn
              ref="flutterWave"
              :order="orderData"
              :public-key="paymentGateway.fw_public_key"
              :amount="parseFloat(amount)"
              :currency="currency"
              :btn-text="paymentBtnText"
              :name="userName"
              :loading="!flutterwaveLoaded"
              :user-id="`${userId}`"
              :email="userEmail"
              :site-name="siteName"
              :header-logo="headerLogo"
              @clicked="payWithFlutterWave"
              @success="orderPlaced('success', $event)"
              @closed="orderPlaced('closed', $event)"
              @error="orderPlaced('error', $event)"
            />
          </div>

          <div
            v-if="parseInt(paymentGateway.paypal) === status.PUBLIC"
            class="paypal-tab"
            :class="{'paypal-active': paymentType === orderMethods.PAYPAL}"
          >
            <div
              ref="paypal"
            />
          </div>

        </div>

      </div>
    </form>
  </div>
</template>
<script>
  import StripePayment from '~/components/StripePayment'
  import RazorpayPayment from '~/components/RazorpayPayment'
  import util from '~/mixin/util'
  import {mapActions, mapGetters} from 'vuex'
  import productHelper from "~/mixin/productHelper"
  import productPriceHelper from "~/mixin/productPriceHelper"
  import paymentHelper from '~/mixin/paymentHelper'
  import Spinner from "./Spinner";
  import AjaxButton from "./AjaxButton";
  import FlutterwavePayBtn from "./FlutterwavePayBtn";
  import IyzicoPayment from "./IyzicoPayment";

  export default {
    middleware: ['auth'],
    data() {
      return {
        loading: false,
        payFastLoader: false,
        payFastData: null,
        flutterwaveLoaded: false,
        paypaLoaded: false,
        showRazorpay: false,
        showStripe: false,
        paymentType: 1,
        orderData: null,
        orderError: null,
        submitting: false,
        placingOrder: false,
        checkedProductQty: 0,
      }
    },
    props: {
      voucher: {
        type: Object,
        default() {
          return null
        }
      },
      order: {
        type: Object,
        default() {
          return null
        }
      },
      page: {
        type: String,
        default: 'checkout'
      },
      totalPrice: {
        type: Number,
        default: 0
      }
    },
    watch: {
      payFastForm() {
        this.$nextTick(() => {
          this.$refs.payFastContainer?.querySelector('#frmPayment')?.submit()
          //this.payFastLoader = false
        })
      }
    },
    components: {
      IyzicoPayment,
      FlutterwavePayBtn,
      AjaxButton,
      Spinner,
      RazorpayPayment,
      StripePayment
    },
    mixins: [util, productHelper, paymentHelper, productPriceHelper],
    computed: {
      payFastForm(){
        return this.payFastData?.payfast
      },
      orderMethod(){
        return this.order?.order_method
      },
      paymentBtnText() {
        return this.$t('checkout.confirmOrderAnd', {amount: this.formattedPrice})
      },
      formattedPrice() {
        return this.priceFormat(this.currencyPosition, this.currencyIcon, this.totalPrice, this.setting)
      },
      voucherResult() {
        return this.voucher
      },
      isCheckout() {
        return this.page === 'checkout'
      },
      userEmail() {
        return this.orderData?.email || this.profile?.email || this.$auth?.user?.email
      },
      headerLogo() {
        return this.imageURL({'image': this.site_setting.header_logo})
      },
      siteName() {
        return this.site_setting?.site_name
      },
      currencyData() {
        return this.orderData?.currency || this.currency
      },
      userId() {
        return this.$auth?.user?.id
      },
      userName() {
        return this.orderData?.userName || this.$auth?.user?.name
      },
      razorpayPaymentToken() {
        return this.orderData?.payment_token || null
      },
      amount() {
        return parseFloat(this.orderData?.total_amount).toFixed(2) || 0
      },
      orderId() {
        return this.orderData?.id || null
      },
      noPaymentMethod() {
        return parseInt(this.paymentGateway?.stripe) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.razorpay) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.paypal) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.iyzico_payment) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.flutterwave) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.bank) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.cash_on_delivery) !== this.status.PUBLIC&&
          parseInt(this.paymentGateway?.payfast_payment) !== this.status.PUBLIC
      },
      checkedProduct() {
        return this.cartProducts.filter(obj => {
          return parseInt(obj.selected) === 1
        })
      },
      ...mapGetters('user', ['profile']),
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'setting', 'currency', 'currencyPosition', 'paymentGateway', 'site_setting']),
      ...mapGetters('cart', ['cartProducts']),
    },
    methods: {
      async initSumup(){
        try {
          this.placingOrder = true

          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const response = await this.$axios.post(
            `${baseUrl}api/create-sumup-checkout`,
            {
              "amount": this.totalPrice,
              "currency": this.currency
            },
            {
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
              }
            }
          );
          const data = response?.data;
          if (data?.id) {
            this.$router.push({
              path: '/payment',
              query: { checkoutId: data.id, checkoutReference: data.checkout_reference }
            })
          }
        }catch (e) {
          console.log(e.message)
          this.setToastError('Payment initialization failed')
        }finally {
          this.placingOrder = false
        }
      },
      async payWithIyzicoPayment() {
        await this.confirmOrder()
        this.$refs.iyzicoPayment.makePayment(!!this.isCheckout)
      },
      async payWithFlutterWave() {
        await this.confirmOrder()
        this.$refs.flutterWave.makePayment()
      },
      setLoaded(resp) {
        window.paypal
          .Buttons({
            style: {
              label: 'pay'
            },
            createOrder: async (data, actions) => {
              if (this.isCheckout) {
                return this.confirmOrder()
                  .then(() => {
                    return actions.order.create({
                      purchase_units: [
                        {
                          description: '',
                          amount: {
                            currency_code: this.currency,
                            value: this.amount
                          }
                        }
                      ]
                    });
                  })
              } else {
                this.orderData = this.order
                return actions.order.create({
                  purchase_units: [
                    {
                      description: '',
                      amount: {
                        currency_code: this.currency,
                        value: this.amount
                      }
                    }
                  ]
                });
              }
            },
            onApprove: async () => {
              await this.paymentDoneFn(this.orderId, this.orderId, this.orderMethods.PAYPAL)
              this.orderPlaced('success', this.orderId)
            },
            onError: err => {
              this.orderPlaced('closed', err)
            }
          })
          .render(this.$refs.paypal)
      },
      async initIyzico() {
        try {
          await this.confirmOrder()
        } catch (e) {
          console.log(e)
        }
      },
      async initPayFast() {
        try {
          this.payFastData = await this.confirmOrder()

          console.log(this.payFastData)
          this.payFastLoader = true
        } catch (e) {
          console.log(e)
        }
      },
      async initRazorpay() {
        try {
          await this.confirmOrder()
          this.showStripe = false
          this.showRazorpay = true
        } catch (e) {
          console.log(e)
        }
      },
      async initStripe() {
        try {
          await this.confirmOrder()
          this.showRazorpay = false
          this.showStripe = true
        } catch (e) {
          console.log(e)
        }
      },
      confirmOrder() {
        return new Promise(async resolve => {
          if (this.isCheckout) {
            this.orderError = ''
            this.placeOrder()
              .then(result => {
                const data = result?.data
                if (parseInt(data.order_method) !== this.orderMethods.CASH_ON_DELIVERY ||
                  parseInt(data.order_method) !== this.orderMethods.BANK
                ) {
                  data['total_amount'] = data.amount
                }
                this.orderData = data
                resolve(data)
              })
          } else {
            this.orderData = this.order

            let payDone = null

            if (parseInt(this.paymentType) === this.orderMethods.CASH_ON_DELIVERY) {
              this.placingOrder = true
              await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.CASH_ON_DELIVERY)
              this.placingOrder = false
              this.orderPlaced('success', this.order.id)

            }else if (parseInt(this.paymentType) === this.orderMethods.BANK) {
              this.placingOrder = true
              await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.BANK)
              this.placingOrder = false
              this.orderPlaced('success', this.order.id)

            } else if (parseInt(this.paymentType) === this.orderMethods.IYZICO_PAYMENT) {
              const {data} = await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.IYZICO_PAYMENT)

              this.orderData = {...this.orderData, ...data}

            }else if (parseInt(this.paymentType) === this.orderMethods.PAYFAST) {
              const {data} = await this.paymentDoneFn(this.order.id, this.order.id, this.orderMethods.PAYFAST)
              payDone = data
            }
            resolve(payDone)
          }
        })
      },
      izcoOrderPlaces(evt) {
        this.orderPlaced('success', evt?.id, evt?.redirect, false)
      },
      orderPlaced(type = 'success', event, redirect = true, showToast = true) {
        if (type === 'success') {

          if(showToast){
            this.setToastMessage(this.$t('payButton.placedSuccess'))
          }

          if (redirect) {
            this.$router.push({path: '/user/order/' + event})
          }

          this.$emit('order-status', true)

        } else if (type === 'error') {

          this.$router.push({path: '/user/order/' + this.orderId})
          this.setToastError(event)

        } else if (type === 'closed') {

          // this.setToastMessage(this.$t('payButton.placedSuccess'))
          this.$router.push({path: '/user/order/' + this.orderId})
          this.$emit('order-status', false)
        }
      },
      async placeOrder() {
        return new Promise(((resolve, reject) => {
          if (this.checkedProduct.length) {
            const params = []
            this.checkedProduct.forEach(async (obj) => {

              let shippingPrice = 0
              if (parseInt(obj.shipping_type) === 1) {
                shippingPrice = parseInt(obj?.shipping_place?.price)
              } else if (parseInt(obj.shipping_type) === 2) {
                shippingPrice = parseInt(obj?.shipping_place?.pickup_price)
              }
              const currentInventoryPrice = this.currentInventoryPriceCalc(obj.inventory, obj.flash_product)
              const currentPrice = parseInt(obj?.quantity) * currentInventoryPrice
              const currentOffer = currentInventoryPrice * parseInt(obj?.offered)
              this.checkedProductQty += parseInt(obj?.quantity)

              params.push({
                cart: obj.id,
                bundle_offer: currentOffer,
                shipping_price: shippingPrice,
                selling: currentPrice,
              })
            })
            this.loading = true


            this.getUserToken()
              .then(userToken => {
                this.orderAction({
                  lang: this.langCode,
                  payload: {
                    data: this.phpEncryption({
                      user_token: userToken,
                      order_method: this.paymentType,
                      voucher: this.voucherResult?.voucher || '',
                      time_zone: this.timeZone
                    })
                  }
                })
                  .then(res => {
                    this.loading = false
                    if (res.status === 200) {

                      setTimeout(async () => {
                        this.sendOrderEmail({
                          payload: {
                            id: res.data.id,
                            time_zone: this.timeZone,
                            user_token: await this.getUserToken()
                          },
                          lang: this.langCode,
                        })
                      }, 100)

                      this.subtractCartProductCount({
                        qty: this.checkedProductQty,
                        status: this.status
                      })

                      if (parseInt(res.data.order_method) === this.orderMethods.CASH_ON_DELIVERY ||
                        parseInt(res.data.order_method) === this.orderMethods.BANK
                      ) {
                        this.orderPlaced('success', res.data.id)
                      }
                      resolve(res)

                    } else if (res.status === 201) {

                      if (res?.data?.form) {
                        this.orderError = res?.data?.form

                      } else if (res?.data?.product) {
                        const productError = []
                        Object.values(res?.data?.product[0]).forEach((obj) => {
                          obj.forEach(o => {
                            productError.push(o)
                          })
                        })

                        this.orderError = productError
                      }
                      reject()
                    }
                  })
              })
          } else {
            this.setToastError(this.$t('listingLayout.noProductFound'))
            this.$router.push('cart')
          }
        }))
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'postRequest']),
      ...mapActions('order', ['orderAction', 'voucherValidity', 'sendOrderEmail', 'paymentDone']),
      ...mapActions('cart', ['getCartByUser', 'subtractCartProductCount', 'emptyCartProduct']),
    },
    async mounted() {

      //DEFAULT SELECTED
      if(this.paymentGateway?.default){
        this.paymentType = this.paymentGateway?.default

      } else if (parseInt(this.paymentGateway?.stripe) === this.status.PUBLIC) {

        this.paymentType = this.orderMethods.STRIPE
      }

      if (parseInt(this.paymentGateway?.paypal) === this.status.PUBLIC) {
        const recaptchaScript = document.createElement('script')
        recaptchaScript.setAttribute('src',
          `https://www.paypal.com/sdk/js?client-id=${this.paymentGateway?.paypal_key}&components=buttons,marks&disable-funding=paylater,card`
        )
        recaptchaScript.setAttribute('async', true)
        document.head.appendChild(recaptchaScript)
        recaptchaScript.addEventListener("load", () => {
          this.setLoaded()
          this.paypaLoaded = true
        });
      } else {
        this.paypaLoaded = true
      }

      if (parseInt(this.paymentGateway?.flutterwave) === this.status.PUBLIC) {

        const recaptchaScript = document.createElement('script')
        recaptchaScript.setAttribute('src',
          `https://checkout.flutterwave.com/v3.js`
        )
        recaptchaScript.setAttribute('async', true)
        document.head.appendChild(recaptchaScript)
        recaptchaScript.addEventListener("load", () => {
          this.flutterwaveLoaded = true
        });

      } else {
        this.flutterwaveLoaded = true
      }
    },
  }
</script>
<style>
.sumup{
  font-weight: bold;
  margin-top: 5px;
  opacity: 0.8;
}
</style>
