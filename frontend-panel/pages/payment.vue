<template>
  <div class="payment-section">
    <div class="container-fluid">
        <div class="checkout">
        <!-- LEFT PANEL -->
        <div class="summary">

        <h2>Order Summary</h2>

        <div class="order-id">
            Order ID:
            <span class="ordr-nmbr">
            #{{ orderId }}
            </span>
        </div>

        <!-- PRODUCTS -->
            <CheckoutProductTile
                v-for="cart in selectedProducts"
                :key="cart.id"
                :cart="cart"
                :checked="checked"
                :isShipping="false"
                />  

            <checkout-right
                ref="checkoutRight"
                route-link="checkout"
                :checked-product="checkedProductWithUpsellPrices"
                :has-shipping="true"
                :hide-btn="true"
                @calculated-price="calculatedPrice"
                class="col-lg-12"
            >
            </checkout-right>
        </div>
        <!-- RIGHT PANEL -->
        <div class="payment">

        <div class="section-title">Payment Method</div>

        <div
            class="method"
            :class="{ active: selectedPayment === 'card' }"
            @click="selectedPayment = 'card'"
            >
            <i class="fa-solid fa-credit-card"></i>
            Card
        </div>

        <div class="form-group">
            <div id="sumup-card"></div>
        </div>

        </div>
    </div>
    </div>
  </div>
</template>
<script>

import { mapGetters, mapActions } from 'vuex'
import productPriceHelper from '~/mixin/productPriceHelper'
import CartProductTile from '~/components/CartProductTile'
import util from '~/mixin/util'
import paymentHelper from '~/mixin/paymentHelper'

export default {
    data() {
      return {
        cartPrice: {
          totalPriceWithOffer: 0,
          shippingPrice: 0,
          exclusiveTax: 0,
          inclusiveTax: 0,
          voucher: 0
        },
        selectedPayment: 'card',
        orderData: null,
        placingOrder: false,
        checkedProductQty: 0
      }
    },
    components: {
        CartProductTile
    },
    mixins: [productPriceHelper, util, paymentHelper],
    async mounted() {
        if (!this.cartProducts.length) {
            await this.fetchCart()
        }

        if (process.client) {
            this.loadSumupScript()
        }

    },
    computed: {
        ...mapGetters('cart', ['cartProducts', 'checked']),
        selectedProducts() {
            return this.cartProducts || []
        },
        orderId() {
            return this.$route.query.checkoutReference || 'N/A'
        },
        checkedProduct() {
            return this.cartProducts.filter(obj => {
                return parseInt(obj.selected) === 1
            })
        },
        checkedProductWithUpsellPrices() {
            return this.checkedProduct.map(product => {
            return {
                ...product,
                upsell_price: product.upsell_price || null
            };
            });
        },
    },
    methods: {
        ...mapActions('cart', ['fetchCart', 'subtractCartProductCount']),
        ...mapActions('user', ['getUserToken']),
        ...mapActions('order', ['orderAction', 'sendOrderEmail', 'paymentDone']),
        ...mapActions('common', ['setToastMessage', 'setToastError']),
        calculatedPrice(evt) {
            this.cartPrice = { ...this.cartPrice, ...evt }
        },
        loadSumupScript(){
            if (window.SumUpCard) {
                this.mountWidget()
                return
            }
        },
        mountWidget(){
            const checkoutId = this.$route.query.checkoutId
            if (!checkoutId) {
                console.error("No checkoutId found")
                return
            }

            window.SumUpCard.mount({
                id: 'sumup-card',
                checkoutId: checkoutId,
                onResponse: async (type, body) => {
                    if (type === 'success') {
                        // alert("Order Placed Successful");
                        var orderId = body.checkout_reference;
                        var transactionId = body.transaction_id;
                        await this.confirmOrder(orderId, transactionId)
                    }

                    if (type === 'error') {
                        alert("Payment Failed");
                        return false;
                    }
                }
            });
        },
        async confirmOrder(orderId, transactionId) {
            if (!this.checkedProduct.length) {
                this.setToastError('No products found')
                return
            }
            try {
                this.placingOrder = true
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
                const userToken = await this.getUserToken()
                const res = await this.orderAction({
                    lang: this.$i18n.locale,
                    payload: {
                        data: this.phpEncryption({
                        user_token: userToken,
                        order_method: this.orderMethods.SUMUP,
                        orderId: orderId,
                        transactionId : transactionId,
                        voucher: '',
                        time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone
                        })
                    }
                });
                
                if (res.status === 200) {
                    this.orderData = res.data

                    setTimeout(async () => {
                        await this.sendOrderEmail({
                            payload: {
                            id: res.data.id,
                            time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                            user_token: userToken
                            },
                            lang: this.$i18n.locale
                        })
                    }, 100);

                    this.subtractCartProductCount({
                        qty: this.checkedProductQty,
                        status: this.status
                    })

                    await this.paymentDoneFn(res.data.id, res.data.id, this.orderMethods.SUMUP)

                    this.$router.push(`/user/order/complete/${res.data.id}`);
                }
            }catch(err){
                this.setToastError('Order creation failed')
            }finally {
                this.placingOrder = false
            }
        }
    },
    watch: {
        cartProducts: {
            immediate: true,
            handler() {
            this.$nextTick(() => {
                if (this.$refs.checkoutRight) {
                this.$refs.checkoutRight.checkedProduct =
                    [...this.checkedProductWithUpsellPrices]
                }
            })
            }
        }
    }
}
</script>
<style scoped>
.checkout {
    display: flex;
    gap: 40px;
}

.payment-section {
    background: #f2f3f5;
}

/* LEFT PANEL */
.summary {
    background: #fff;
    padding: 35px;
    width: 36%;
    box-shadow: 0 0 10px 0 #00000014;
}
.sumup-payment-t0qu6d.sumup-payment-t0qu6d > img {
    height: auto;
}
.summary h2 {
    margin: 0 0 15px;
    font-size: 28px;
    line-height: 1.2em;
    font-weight: 600 !important;
    color: #333;
}
.order-id {
    font-size: 14px;
    color: #333;
    margin-bottom: 40px;
}
.order-id .ordr-nmbr {
    background: #F3F4F6;
    padding: 2px 10px;
    border-radius: 5px;
}


hr {
    border: none;
    border-top: 1px solid #E5E7EB;
    margin: 40px 0;
}
.row-line{
    display:flex;
    justify-content:space-between;
    margin-bottom:10px;
    font-size:14px;
}
.row-line span {
    color: #737987;
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 5px;
}
.row-line.green span {
    color: #16A34A !important;
}
.total {
    margin-top: 50px;
    padding-top: 28px;
    border-top: 2px dashed #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.total .ttl-py {
    font-size: 20px;
    font-weight: 600;
    color: #333;
    line-height: 1.2em;
}
.total .ttl_tx p {
    margin: 0 0 5px;
    font-size: 30px;
    font-weight: 600;
    color: #333;
    line-height: 1.2em;
}
.ttl_tx span {
    font-size: 16px;
    line-height: 24px;
    color: #737987;
}


/* RIGHT PANEL */
.payment {
    width: 64%;
    padding: 35px;
}
.section-title {
    margin: 0 0 18px;
    font-size: 24px;
    line-height: 1.2em;
    font-weight: 600 !important;
    color: #333;
}

/* Card option */
.method {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 12px 25px;
  border: 2px solid #D1D5DB;
  border-radius: 10px;
  margin-bottom: 25px;
  background: #fff;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.method.active {
  border-color: #111827;
  background: #F9FAFB;
  box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}


/* Inputs */
.form-group {
    border: 1px solid #E5E7EB;
    padding: 30px;
    border-radius: 10px;
    background: #fff;
    margin-top: 20px;
}

@media only screen and (max-width:980px) {
    .payment,.summary {
        width: 100% !important;
    }

    .checkout {
        flex-direction: column;
    }
}
 

</style>