<template>
  <client-only>
    <div>
      <navigation-step />
      <div class="container-fluid my-5">
        <div class="row g-4">
          <div class="col-lg-8">
            <div class="delivery-box mb-3">
              <div class="delivery-header">
                <div class="delivery-left">
                  <div class="check-circle">✓</div>
                  <div>
                    <div class="delivery-title mb-2">Delivery or Collection?</div>
                    <div class="delivery-sub">
                      For Delivery <br />
                      <strong>
                        <div v-for="(item, index) in productSummary" :key="index">
                          {{ item.text }}
                        </div>
                      </strong>
                    </div>
                  </div>
                </div>
                <button class="delivery-edit-btn" @click="goToCart">Edit</button>
              </div>
              <div class="delivery-options">
                <div
                  :class="['delivery-option', { active: deliveryType === 'delivery' }]"
                >
                  <div class="icon-circle">
                    <i class="fa-solid fa-file-pen"></i>
                  </div>
                  <span class="delivery-type">Send by Post 
                    <small>
                      ({{ deliveryPrice > 0 ? '€' + formattedDeliveryPrice : 'Free' }})
                    </small>
                  </span>
                </div>

                <div
                  :class="['delivery-option', { active: deliveryType === 'collection' }]"
                >
                  <div class="icon-circle">
                    <i class="fa-solid fa-file-pen"></i>
                  </div>
                  <span class="delivery-type">Collection Store <small>(Free)</small></span>
                </div>
              </div>
            </div>
            <div v-if="selectedAddress" class="delivery-box mb-3">
              <div class="delivery-header">
                <div class="delivery-left">
                  <div class="check-circle">✓</div>
                  <div>
                    <div class="delivery-title mb-2">Delivery Address</div>
                    <div class="delivery-sub">
                      <strong>
                        {{ selectedAddress.name }}
                        <span class="address-tag">
                          {{ selectedAddress.type || 'Home' }}
                        </span>
                      </strong>
                      <div>
                        {{ selectedAddress.address_1 }}
                        <span v-if="selectedAddress.address_2">
                          , {{ selectedAddress.address_2 }}
                        </span>,
                        {{ selectedAddress.city }},
                        {{ selectedAddress.state }},
                        {{ selectedAddress.country }}
                        <span v-if="selectedAddress.zip">
                          , {{ selectedAddress.zip }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- RIGHT -->
                <button class="delivery-edit-btn" @click="goToShipping">
                  Edit
                </button>

              </div>
            </div>
            <div class="accordion mt-3 mb-3" id="paymentAccordion">
              <div class="accordion-item ">
                <h5 class="accordion-header"  id="headingPayment">
                  <button type="button" class="accordion-button" data-bs-toggle="collapse" data-bs-target="#collapsePayment" aria-expanded="true" aria-controls="collapsePayment">
                    <span class="delivery-heading">Payment Method</span>
                  </button>
                </h5>
                <div id="collapsePayment" class="accordion-collapse collapse show" aria-labelledby="headingPayment" data-bs-parent="#paymentAccordion">
                  <div class="container mt-2">
                    <div class="container ">
                      <div class="row g-3">
                        <payment-gateways
                          ref="paymentGateways"
                          :total-price="totalPrice"
                          :voucher="voucherResult"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <checkout-right
            ref="checkoutRight"
            route-link="checkout"
            :checked-product="checkedProductWithUpsellPrices"
            :has-shipping="true"
            :voucher-result="voucherResult"
            :hide-btn="true"
            @calculated-price="calculatedPrice"
          >
            <template v-slot:checkout>
              <div :class="{invalid: !!voucherError}">
                <form
                  class="mt-15 btn-input"
                >
                  <input
                    class="pl-15 pr-80"
                    :placeholder="$t('checkout.voucherCode')"
                    type="text"
                    v-model="voucher">

                  <ajax-button
                    class="primary-btn plr-15"
                    type="button"
                    :fetching-data="submitting"
                    loading-text=""
                    :disabled="!voucher || !!voucherError || !!voucherResult"
                    :text="$t('checkout.apply')"
                    @clicked="checkVoucher"
                  />
                </form>
              </div>
              <span
                v-if="voucherError"
                class="error"
              >
              {{ voucherError }}
            </span>
            <div class="voucher-list-wrapper mt-10">
              <button
                class="show-voucher-btn"
                type="button"
                @click="showVoucherList = !showVoucherList"
              >
                <span v-if="!showVoucherList">
                  Show Available Vouchers
                </span>
                <span v-else>
                  Hide Available Vouchers
                </span>
              </button>
              <transition name="fade">
                <div
                  v-if="showVoucherList"
                  class="voucher-list"
                >
                  <div
                    v-if="loadingVouchers"
                    class="voucher-loading"
                  >
                    Loading vouchers...
                  </div>
                  <div
                    v-else-if="!activeVouchers.length"
                    class="empty-voucher-state"
                  >
                    <i class="fa fa-ticket"></i>
                    <h6>
                      No Active Vouchers
                    </h6>
                    <p>
                      You currently don't have any available vouchers for this order.
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
                      <p class="voucher-code">
                        {{ item.code }}
                      </p>
                      <p class="voucher-description">
                        Valid on orders above
                        <strong>
                          {{ currencyIcon + item.min_spend }}
                        </strong>
                      </p>
                      <small class="voucher-expiry">
                        Valid till {{ item.end_time }}
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
                        Remove
                      </span>
                      <span v-else>
                        Apply
                      </span>
                    </button>
                  </div>
                </div>
              </transition>
            </div>
            </template>
          </checkout-right>
        </div>
      </div>
    </div>
    <!-- <div class="container-fluid mtb-20 mtb-sm-15">

      <div class="product-detail checkout-detail">
        <div
          class="area detail-left pt-10 plr-20 plr-sm-15 pb-20 pb-sm-15 mr-20 mr-sm mb-sm-15"
        >
          <h5
            class="b-b pb-10 mb-15 bold"
          >
            {{ $t('checkout.selectPayment') }}
          </h5>
          <payment-gateways
            ref="paymentGateways"
            :total-price="totalPrice"
            :voucher="voucherResult"
          />
        </div>
      </div>
    </div> -->
  </client-only>
</template>
<script>
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
            this.$store.commit('cart/SET_FLASH_DISCOUNT', { amount: 0 })
          }
        },
        deep: true
      }
    },
    components: {
      AjaxButton,
      PaymentGateways,
      RazorpayPayment,
      StripePayment,
      CheckoutRight
    },
    mixins: [util, productHelper, paymentHelper, productPriceHelper, global],
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
      deliveryType() {
        const products = this.cartProducts.filter(p => parseInt(p.selected) === 1)
        if (!products.length) return 'delivery'
        const first = products[0]
        return first?.shipping_type === 2 ? 'collection' : 'delivery'
      },
      productSummary() {
        if (!this.checkedProduct.length) return []
        return this.checkedProduct.map(p => ({
          text: `${p.quantity} x ${p?.flash_product?.title}`
        }))
      },
      formattedDeliveryPrice() {
        return this.deliveryPrice.toFixed(2)
      },
      deliveryPrice() {
        const products = this.cartProducts.filter(p => parseInt(p.selected) === 1)

        let total = 0

        products.forEach(p => {
          const sp =
            p.shipping_place ||
            p.available_shipping?.[0]

          if (sp?.price) {
            total += Number(sp.price)
          }
        })

        return total
      },
      noPaymentMethod() {
        return parseInt(this.paymentGateway?.card_payment) !== this.status.PUBLIC &&
          parseInt(this.paymentGateway?.cash_on_delivery) !== this.status.PUBLIC
      },
      productPrice() {
        return this.cartPrice.totalPriceWithOffer + this.cartPrice.shippingPrice + this.cartPrice.exclusiveTax
      },
      flashDiscount() {
        return this.$store.state.cart.flashDiscount?.amount || 0
      },
      totalPrice() {
        if (this.productPrice) {
          return Math.max(
            this.productPrice
            - this.cartPrice.voucher
            - this.flashDiscount,
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
      ...mapGetters('common', ['currencyIcon', 'setting', 'currency', 'paymentGateway']),
      ...mapGetters('cart', ['cartProducts']),
    },
    methods: {
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
          'Voucher removed successfully'
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
      goToShipping() {
        this.$router.push('/shipping')
      },
      goToCart() {
        this.$router.push('/cart')
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
          this.$store.commit('cart/SET_FLASH_DISCOUNT', this.$store.state.cart.flashDiscount)
        }
      },
      calculatedPrice(evt) {
        this.cartPrice = {...this.cartPrice, ...evt}
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
      ...mapActions('cart', ['getCartByUser', 'subtractCartProductCount', 'emptyCartProduct']),
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
        });
      } catch (e) {
        return this.$nuxt.error(e)
      }
    },
  }
</script>
<style>
.delivery-box {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  border: 1px solid #E3E3EF;
}

.delivery-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.delivery-left {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.check-circle {
  width: 30px;
  height: 30px;
  background: #16a34a;
  color: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}

.delivery-title {
  font-size: 20px;
  font-weight: 600;
  color: #130E2B;
}

.delivery-sub {
  font-size: 14px;
  font-weight: 500;
  color: #232159;
}

.delivery-options {
  display: flex;
  gap: 20px;
}

.delivery-option {
  flex: 1;
  background: #E9F6EE;
  border-radius: 14px;
  padding: 15px;
  display: flex;
  align-items: center;
  gap: 20px;
  cursor: pointer;
  transition: 0.3s;
}

.delivery-option.active {
  background: #b4d8c7;
}

.icon-circle {
  width: 40px;
  height: 40px;
  background: #16a34a;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
}

.delivery-type{
  font-weight: 600;
  color: #130E2B;
  font-size: 20px;
}
.delivery-edit-btn {
    border: 1px solid #33319A;
    padding: 0px 20px;
    border-radius: 100px;
    color: #33319A;
}
.address-tag {
  background: #4f46e5;
  color: #fff;
  font-size: 12px;
  padding: 2px 10px;
  border-radius: 12px;
  margin-left: 10px;
}

.show-voucher-btn {
  width: 100%;
  border: 1px solid #D7D7E0;
  background: #F7F7FA;
  border-radius: 10px;
  padding: 0px 15px;
  font-weight: 600;
  transition: 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.show-voucher-btn:hover {
  background: #efeff8;
}

.voucher-list {
  margin-top: 12px;
  border: 1px solid #E7E7EE;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}

.empty-voucher-state {
  padding: 30px 20px;
  text-align: center;
}

.empty-voucher-state i {
  font-size: 28px;
  color: #B8B8C7;
  margin-bottom: 10px;
}

.empty-voucher-state h6 {
  margin-bottom: 5px;
  color: #130E2B;
}

.empty-voucher-state p {
  font-size: 13px;
  color: #777;
  margin: 0;
}

.apply-voucher-btn.applied {
  background: #dc2626;
  cursor: pointer;
  opacity: 1;
}

.apply-voucher-btn.applied:hover {
  background: #b91c1c;
}

.voucher-card {
  padding: 15px;
  border-bottom: 1px solid #eee;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 15px;
}

.voucher-header {
  display: flex;
  align-items: center;
  gap: 10px;
}

.voucher-title {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 0;
}

.voucher-badge {
  background: #dcfce7;
  color: #15803d;
  font-size: 10px;
  padding: 3px 8px;
  border-radius: 20px;
  font-weight: 700;
}

.voucher-code {
  color: #16a34a;
  font-weight: 700;
  margin: 5px 0;
}

.voucher-expiry {
  color: #777;
}

.apply-voucher-btn {
  background: #33319A;
  color: white;
  border: none;
  border-radius: 8px;
  padding: 0px 18px;
  min-width: 90px;
  transition: 0.2s;
}

.apply-voucher-btn:hover {
  background: #24217d;
}

.voucher-description {
  font-size: 12px;
  color: #6B7280;
  line-height: 1.5;
  margin-bottom: 8px;
  margin-top: 4px;
}

.voucher-description strong {
  color: #111827;
}
</style>