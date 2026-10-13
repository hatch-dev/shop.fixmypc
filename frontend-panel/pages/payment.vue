<template>
  <div class="payment-section">
    <navigation-step />

    <div class="container-fluid">
      <div class="pay-head">
        <h1 class="pay-title">Complete your payment</h1>
        <div class="order-id">
          Order ID:
          <span class="ordr-nmbr">#{{ orderId }}</span>
        </div>
      </div>

      <div class="pay-grid">
        <!-- ORDER ITEMS -->
        <section class="pay-card pay-items">
          <header class="card-head">
            <h2 class="card-title">Your Order</h2>
            <span class="item-count">{{ itemCountLabel }}</span>
          </header>

          <CheckoutProductTile
            v-for="cart in selectedProducts"
            :key="cart.id"
            :cart="cart"
            :checked="checked"
            :isShipping="false"
          />
        </section>

        <!-- TOTALS -->
        <checkout-right
          ref="checkoutRight"
          route-link="checkout"
          :checked-product="checkedProductWithUpsellPrices"
          :has-shipping="true"
          :hide-btn="true"
          @calculated-price="calculatedPrice"
          @calculated-total="orderTotal = $event"
          class="pay-summary"
        >
        </checkout-right>

        <!-- PAYMENT -->
        <section class="pay-card pay-method-card">
          <header class="card-head">
            <h2 class="card-title">Payment Method</h2>
            <span class="amount-due" v-if="orderTotal > 0">
              <span class="amount-due-label">Amount to pay</span>
              <price-format :price="orderTotal" :decimals="2" />
            </span>
          </header>

          <div class="method-list">
            <button
              type="button"
              class="method"
              :class="{ active: selectedPayment === 'card' }"
              :aria-pressed="String(selectedPayment === 'card')"
              @click="selectedPayment = 'card'"
            >
              <span class="method-icon">
                <i class="fa-solid fa-credit-card"></i>
              </span>
              <span class="method-body">
                <span class="method-name">Card</span>
                <span class="method-hint">Visa, Mastercard, Maestro</span>
              </span>
              <span class="method-check"></span>
            </button>
          </div>

          <!--
            SumUp's own refusal, kept on the page. A toast disappears while the
            shopper is still reading the card form, and "Payment Failed" told
            them nothing about which field or which decline it was.
          -->
          <div v-if="gatewayError" class="pay-alert" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>
              <path d="M12 7.5v5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              <circle cx="12" cy="16.4" r="1.15" fill="currentColor"/>
            </svg>
            <span>
              {{ gatewayError }}
              <em v-if="gatewayErrorCode" class="pay-alert-code">({{ gatewayErrorCode }})</em>
            </span>
          </div>

          <div class="form-group">
            <div v-if="sdkLoading" class="form-loading">
              <spinner :radius="34" />
              <p class="form-loading-text">Loading the secure card form…</p>
            </div>

            <div v-else-if="sdkError" class="form-failed">
              <p class="form-failed-text">{{ sdkError }}</p>
              <button type="button" class="pay-overlay-dismiss" @click="loadSumupScript">
                Try again
              </button>
            </div>

            <!--
              v-show, not v-if: SumUp mounts into this node by id, so it has to
              exist in the DOM before the widget is asked to draw into it.
            -->
            <div id="sumup-card" v-show="!sdkLoading && !sdkError"></div>
          </div>

          <p class="pay-note">
            <svg width="12" height="13" viewBox="0 0 11 12" aria-hidden="true">
              <path
                d="M3.375 3.375V4.5H7.125V3.375C7.125 2.33906 6.28594 1.5 5.25 1.5C4.21406 1.5 3.375 2.33906 3.375 3.375V3.375M1.875 4.5V3.375C1.875 1.51172 3.38672 0 5.25 0C7.11328 0 8.625 1.51172 8.625 3.375V4.5H9C9.82734 4.5 10.5 5.17266 10.5 6V10.5C10.5 11.3273 9.82734 12 9 12H1.5C0.672656 12 0 11.3273 0 10.5V6C0 5.17266 0.672656 4.5 1.5 4.5H1.875V4.5"
                fill="#6B7280" />
            </svg>
            Your card details are encrypted and handled by SumUp. We never store them.
          </p>
        </section>
      </div>
    </div>

    <!--
      The widget hands control back the moment the card clears, and confirming
      the order against SumUp can take several seconds. Without this the shopper
      sits on a live card form with no sign anything happened - and a second
      submit there is a second charge.
    -->
    <div class="pay-overlay" v-if="overlayVisible" role="alert" aria-live="assertive">
      <div class="pay-overlay-box">
        <spinner :radius="56" />
        <h3 class="pay-overlay-title">{{ overlayTitle }}</h3>
        <p class="pay-overlay-note">Please do not close or refresh this page.</p>

        <!--
          Only ever offered while we are waiting on the gateway, never while
          the order is being confirmed - and only after long enough that a
          missed widget callback, rather than a slow bank, is the likely
          explanation. Without it a swallowed event would trap the shopper
          behind a spinner with no way back to the form.
        -->
        <button
          v-if="canDismissOverlay"
          type="button"
          class="pay-overlay-dismiss"
          @click="stopGatewayWait"
        >
          Back to card details
        </button>
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
        orderTotal: 0,
        confirmingText: 'Confirming your payment…',
        gatewayWaiting: false,
        gatewayError: null,
        gatewayErrorCode: null,
        overlayEscape: false,
        escapeTimer: null,
        submitFallbackTimer: null,
        submitHandler: null,
        sdkLoading: true,
        sdkError: null,
        sdkTimer: null,
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
      /*
       * The store-wide promotion, so the summary on this page can show the
       * discount the shopper opted into on the basket. Only the basket
       * carries the banner, so without this a checkout reached directly --
       * a deep link, or a refresh -- had no promotion to derive the amount
       * from and quietly dropped the row.
       */
      this.$store.dispatch('cart/loadFlashPromotion')

        /*
         * Refresh the basket from the server, always.
         *
         * Two things were wrong here. It dispatched `cart/fetchCart`, which
         * does not exist — Vuex logged "unknown action type" and the call
         * resolved to undefined, so this page was the only one in the flow
         * that never re-read the basket. And it only tried at all when the
         * store was empty, which is the one case that did not need it.
         *
         * The cart module is persisted to localStorage (plugins/
         * vuex-persisted.js), so a non-empty store is not a fresh one: it can
         * be a snapshot written by an older build, from before the VAT split
         * was part of the payload. Those rows still carry a price and a
         * quantity, which is why the symptom was so quiet — Products Total was
         * right while VAT and the original price both read 0.00, on the last
         * screen before the customer commits.
         *
         * Every other page in the flow (cart, checkout, shipping) refreshes
         * this way; this one now does too.
         */
        await this.getCartByUser({
            lang: this.langCode,
            params: {
                user_token: await this.getUserToken()
            }
        })

        if (process.client) {
            this.loadSumupScript()
        }

    },
    computed: {
        ...mapGetters('cart', {cartProducts: 'checkoutProducts', checked: 'checked'}),
        /*
         * paymentHelper already reads this.langCode and nothing mapped it, so
         * it was undefined here — the basket and the order were both asked for
         * without a language. Harmless on the default locale, and the reason a
         * translated checkout fell back to English on this page alone.
         */
        ...mapGetters('language', ['langCode']),
        selectedProducts() {
            return this.cartProducts || []
        },
        overlayVisible() {
            return this.placingOrder || this.gatewayWaiting
        },
        overlayTitle() {
            return this.placingOrder
                ? this.confirmingText
                : 'Contacting your bank…'
        },
        canDismissOverlay() {
            return this.gatewayWaiting && !this.placingOrder && this.overlayEscape
        },
        itemCountLabel() {
            const count = this.selectedProducts.reduce((sum, cart) => {
                return sum + (parseInt(cart?.quantity) || 0)
            }, 0)
            return count === 1 ? '1 item' : count + ' items'
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
        ...mapActions('cart', ['getCartByUser', 'subtractCartProductCount']),
        ...mapActions('user', ['getUserToken']),
        ...mapActions('order', ['orderAction', 'sendOrderEmail', 'paymentDone']),
        ...mapActions('common', ['setToastMessage', 'setToastError']),
        /*
         * Insurance for widget versions that do not emit 'sent'. The delay is
         * what keeps it honest: SumUp's own field validation refuses in well
         * under a second and would have cleared the wait by then, so only a
         * request that is genuinely in flight still reaches this.
         */
        bindWidgetSubmit() {
            const host = document.getElementById('sumup-card')

            if (!host) {
                return
            }

            // "Try again" re-mounts the widget, and a second listener here
            // would start the wait twice.
            if (this.submitHandler) {
                host.removeEventListener('click', this.submitHandler, true)
            }

            this.submitHandler = (evt) => {
                const button = evt.target && evt.target.closest
                    ? evt.target.closest('button')
                    : null

                if (!button) {
                    return
                }

                const isPay = button.type === 'submit'
                    || /pay/i.test(button.textContent || '')

                if (!isPay) {
                    return
                }

                clearTimeout(this.submitFallbackTimer)
                this.submitFallbackTimer = setTimeout(() => {
                    if (!this.gatewayWaiting && !this.placingOrder && !this.gatewayError) {
                        this.startGatewayWait()
                    }
                }, 1200)
            }

            host.addEventListener('click', this.submitHandler, true)
        },
        startGatewayWait() {
            this.gatewayError = null
            this.gatewayErrorCode = null
            this.gatewayWaiting = true
            this.overlayEscape = false

            clearTimeout(this.escapeTimer)
            this.escapeTimer = setTimeout(() => {
                this.overlayEscape = true
            }, 8000)
        },
        stopGatewayWait() {
            this.gatewayWaiting = false
            this.overlayEscape = false
            clearTimeout(this.escapeTimer)
            this.escapeTimer = null
        },
        /*
         * SumUp answers with a message, a list of them, or only a code such as
         * MULTIPLE_INVALID_PARAMETERS. Its own wording is the most accurate
         * thing available, so it is preferred; a bare code is made readable
         * rather than guessed at, and kept on screen for support.
         */
        setGatewayError(body, fallback) {
            const payload = Array.isArray(body) ? body[0] : body

            let message = null
            let code = null

            if (typeof payload === 'string') {
                message = payload
            } else if (payload && typeof payload === 'object') {
                message = payload.message
                    || payload.error_message
                    || payload.detail
                    || (Array.isArray(payload.errors)
                        ? payload.errors.map(e => e?.message || e).filter(Boolean).join(', ')
                        : null)

                code = payload.error_code || payload.code || payload.status || null
            }

            if (!message && code) {
                message = String(code)
                    .replace(/[_-]+/g, ' ')
                    .toLowerCase()
                    .replace(/^./, c => c.toUpperCase())
            }

            console.error('SumUp widget refused the payment', { type: code, body })

            this.gatewayError = message || fallback
            this.gatewayErrorCode = code && message !== String(code) ? code : null
        },
        calculatedPrice(evt) {
            this.cartPrice = { ...this.cartPrice, ...evt }
        },
        /*
         * The SDK is a deferred <script> in the document head, while Nuxt's own
         * bundle runs at the end of the body - so on a direct load of this page
         * the app mounts first and window.SumUpCard does not exist yet. This
         * used to return quietly at that point, leaving an empty white box
         * where the card form should be, with nothing on screen to say so.
         */
        async loadSumupScript(){
            clearTimeout(this.sdkTimer)
            this.sdkError = null

            if (!this.$route.query.checkoutId) {
                this.sdkLoading = false
                this.sdkError = 'This payment session has expired. Please go back to checkout and start again.'
                return
            }

            this.sdkLoading = true

            try {
                await this.waitForSumup()
                this.sdkLoading = false

                this.$nextTick(() => {
                    this.mountWidget()
                })
            } catch (e) {
                console.error('SumUp SDK never became available', e)
                this.sdkLoading = false
                this.sdkError = 'We could not load the secure card form. Check your connection and try again.'
            }
        },
        waitForSumup(timeout = 15000) {
            return new Promise((resolve, reject) => {

                if (window.SumUpCard) {
                    resolve()
                    return
                }

                const startedAt = Date.now()

                const tick = () => {
                    if (window.SumUpCard) {
                        resolve()
                        return
                    }

                    if (Date.now() - startedAt > timeout) {
                        reject(new Error('SumUp SDK not available'))
                        return
                    }

                    this.sdkTimer = setTimeout(tick, 150)
                }

                tick()
            })
        },
        mountWidget(){
            const checkoutId = this.$route.query.checkoutId

            if (!checkoutId) {
                this.sdkError = 'This payment session has expired. Please go back to checkout and start again.'
                return
            }

            this.bindWidgetSubmit()

            window.SumUpCard.mount({
                id: 'sumup-card',
                checkoutId: checkoutId,
                /*
                 * The widget reports its progress through here: 'sent' once the
                 * card details are on their way to SumUp, 'auth-screen' when
                 * the bank takes over for 3-D Secure, 'invalid' for its own
                 * field validation, then 'error' or 'success'. The loader is
                 * driven off those states so it covers the whole time SumUp is
                 * being waited on, and lifts the moment the shopper is the one
                 * being waited on.
                 */
                onResponse: async (type, body) => {

                    if (type === 'sent') {
                        this.startGatewayWait()
                        return
                    }

                    if (type === 'auth-screen') {
                        // The bank's 3-D Secure step needs the shopper, so the
                        // overlay has to come off or they cannot reach it.
                        this.stopGatewayWait()
                        return
                    }

                    if (type === 'invalid') {
                        this.stopGatewayWait()
                        this.setGatewayError(
                            body,
                            'Please check the card details and try again.'
                        )
                        return false
                    }

                    if (type === 'error') {
                        this.stopGatewayWait()
                        this.setGatewayError(
                            body,
                            'Your payment could not be completed. Your card has not been charged.'
                        )
                        this.setToastError(this.gatewayError)
                        return false
                    }

                    if (type === 'success') {
                        /*
                         * The widget can report success more than once - a
                         * re-mount replays it - and a second run would build a
                         * second order for the one payment.
                         */
                        if (this.placingOrder) {
                            return
                        }
                        this.stopGatewayWait()
                        this.gatewayError = null
                        this.gatewayErrorCode = null
                        this.placingOrder = true
                        await this.confirmOrder(body.checkout_reference, body.transaction_id)
                    }
                }
            });
        },
        async confirmOrder(orderId, transactionId) {
            if (!this.checkedProduct.length) {
                this.setToastError('No products found')
                this.placingOrder = false
                return
            }
            try {
                this.placingOrder = true
                this.confirmingText = 'Creating your order…'
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

                /*
                 * Set by /checkout when the SumUp checkout was created. The
                 * order has to be built with the same voucher and points the
                 * card was charged under, or the server's total will not match
                 * what SumUp took and it will refuse to mark the order paid.
                 */
                const pending = this.$store.state.cart.pendingPayment || {}

                const res = await this.orderAction({
                    lang: this.$i18n.locale,
                    payload: {
                        data: this.phpEncryption({
                        user_token: userToken,
                        order_method: this.orderMethods.SUMUP,
                        orderId: orderId,
                        transactionId : transactionId,
                        /*
                         * What the server verifies the payment against. Without
                         * it the order cannot be confirmed, and is refused
                         * rather than created unpaid.
                         */
                        checkout_id: this.$route.query.checkoutId,
                        voucher: pending.voucher || '',
                        use_points: pending.use_points || 0,
                        points_discount: pending.points_discount || 0,
                        // part paid from the wallet; the card took the rest
                        use_wallet: pending.use_wallet || 0,
                        // Stashed at /checkout; this page has no form of its own.
                        purchase_order_number: pending.purchase_order_number || '',
                        // Stashed with the rest of the choices at /checkout:
                        // the card was charged under this one too.
                        apply_flash_discount: pending.apply_flash_discount || 0,
                        time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone
                        })
                    }
                });
                
                /*
                 * The server now confirms the charge with SumUp before it will
                 * create the order, so anything other than 200 here means the
                 * payment did not complete - there is no order to send anyone
                 * to, and the basket is still intact behind this page.
                 */
                if (res.status !== 200) {
                    const refusal = Array.isArray(res?.data?.form)
                        ? res.data.form.join(', ')
                        : (res?.message || 'Your payment could not be completed.')

                    console.error('Order refused after payment', res)

                    this.gatewayError = refusal
                    this.gatewayErrorCode = null
                    this.setToastError(refusal)
                    return
                }

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

                    /*
                     * The SumUp checkout id, not the order id. The server
                     * confirms the payment by fetching that checkout back from
                     * SumUp - handed an order id the lookup finds nothing, so
                     * the order stayed payment_done = 0 with no transaction id
                     * even though the card had been charged.
                     */
                    this.confirmingText = 'Confirming your payment…'

                    let confirmed = await this.paymentDoneFn(
                        res.data.id,
                        this.$route.query.checkoutId,
                        this.orderMethods.SUMUP
                    )

                    /*
                     * SumUp can still be reporting the checkout as PENDING for
                     * a moment after the widget says success, and the server
                     * answers that with a refusal. Confirming is idempotent -
                     * an order already marked paid returns success - so one
                     * retry costs nothing and saves a paid order from being
                     * recorded as unpaid.
                     */
                    if (parseInt(confirmed?.status) !== 200) {
                        await new Promise(resolve => setTimeout(resolve, 3000))

                        confirmed = await this.paymentDoneFn(
                            res.data.id,
                            this.$route.query.checkoutId,
                            this.orderMethods.SUMUP
                        )
                    }

                    /*
                     * paymentDoneFn reports a refused confirmation by emitting
                     * on the component, and nothing listens to a page. The card
                     * has been charged by this point, so a failure here has to
                     * be said out loud rather than left to read as "Not Paid".
                     */
                    if (parseInt(confirmed?.status) !== 200) {
                        /*
                         * The reason matters here - "Gateway is not accepted"
                         * means the server did not recognise SumUp as the
                         * method, which is a configuration fault, not a card
                         * problem - so it is shown rather than swallowed.
                         */
                        const reason = Array.isArray(confirmed?.data?.form)
                            ? confirmed.data.form.join(', ')
                            : (confirmed?.message || '')

                        console.error('SumUp confirmation refused', {
                            order: res.data.id,
                            checkoutId: this.$route.query.checkoutId,
                            response: confirmed
                        })

                        this.setToastError(
                            'Your payment went through, but we could not confirm it against your order'
                            + (reason ? ' (' + reason + ')' : '')
                            + '. Our team has been notified.'
                        )
                    }

                    this.$store.commit('cart/SET_PENDING_PAYMENT', null)

                    this.$router.push(`/user/order/complete/${res.data.id}`);
                }
            }catch(err){
                /*
                 * The card has been charged before this method runs, so a
                 * failure here is never "nothing happened" - say so plainly
                 * instead of the old "Order creation failed".
                 */
                console.error('Order confirmation failed after payment', err)
                this.setToastError(
                    'Your payment went through, but we could not complete your order. Please contact support with your order reference.'
                )
            }finally {
                this.placingOrder = false
            }
        }
    },
    beforeDestroy() {
        clearTimeout(this.escapeTimer)
        clearTimeout(this.submitFallbackTimer)
        clearTimeout(this.sdkTimer)

        const host = document.getElementById('sumup-card')

        if (host && this.submitHandler) {
            host.removeEventListener('click', this.submitHandler, true)
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
.payment-section {
    background: #F5F6F8;
    padding-bottom: 60px;
    min-height: 60vh;
}

/* HEAD */
.pay-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px 20px;
    margin-bottom: 20px;
}
.pay-title {
    margin: 0;
    font-size: 28px;
    line-height: 1.2em;
    font-weight: 600;
    color: #130E2B;
}
.order-id {
    font-size: 14px;
    color: #737987;
}
.order-id .ordr-nmbr {
    display: inline-block;
    background: #fff;
    border: 1px solid #E3E3EF;
    color: #130E2B;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 999px;
    margin-left: 4px;
}

/*
 * One grid drives both breakpoints: on a phone the three cards read in DOM
 * order (items, totals, then the card form), on desktop the totals move to a
 * sticky right rail beside them.
 */
.pay-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}

.pay-card {
    background: #fff;
    border: 1px solid #E7E7EF;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 1px 2px rgba(19, 14, 43, .04);
}

.card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 14px;
    border-bottom: 1px solid #EDEDF3;
}
.card-title {
    margin: 0;
    font-size: 20px;
    line-height: 1.2em;
    font-weight: 600;
    color: #130E2B;
}
.item-count {
    font-size: 13px;
    color: #737987;
    white-space: nowrap;
}

/* PAYMENT METHOD */
.method-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
    margin-top: 20px;
}
.method {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    text-align: left;
    padding: 14px 16px;
    border: 1.5px solid #E0E0EA;
    border-radius: 12px;
    background: #fff;
    cursor: pointer;
    transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
}
.method:hover {
    border-color: #C7C7D8;
}
.method.active {
    border-color: #33319A;
    background: #FBFBFF;
    box-shadow: 0 0 0 3px rgba(51, 49, 154, .08);
}
.method-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    border-radius: 10px;
    background: #F2F2F8;
    color: #33319A;
    font-size: 16px;
}
.method-body {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}
.method-name {
    font-size: 15px;
    font-weight: 600;
    color: #130E2B;
    line-height: 1.3em;
}
.method-hint {
    font-size: 12.5px;
    color: #737987;
    line-height: 1.4em;
}
.method-check {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    border-radius: 50%;
    border: 1.5px solid #D1D1DF;
    position: relative;
}
.method.active .method-check {
    border-color: #33319A;
    background: #33319A;
    box-shadow: inset 0 0 0 3px #fff;
}

/* CARD WIDGET */
.form-group {
    border: 1px solid #ECECF3;
    padding: 20px;
    border-radius: 12px;
    background: #FCFCFE;
    margin-top: 20px;
}
/*
 * SumUp injects its widget into #sumup-card, so those nodes never carry this
 * component's scope attribute - the rules have to be deep ones.
 */
.form-group >>> img {
    height: auto;
    max-width: 100%;
}
.form-group >>> input,
.form-group >>> select {
    max-width: 100%;
    width: 100%;
    border-radius: 10px;
    /* under 16px iOS zooms the whole page when a field takes focus */
    font-size: 16px;
}
.form-group >>> input:focus,
.form-group >>> select:focus {
    outline: none;
    border-color: var(--primary-color, #33319A);
    box-shadow: 0 0 0 3px rgba(51, 49, 154, .12);
}
/* the widget draws its own submit button; only its shape is ours to set */
.form-group >>> button {
    border-radius: 10px;
}

/* WIDGET LOADING / FAILED */
.form-loading,
.form-failed {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    min-height: 180px;
    padding: 20px;
}
.form-loading-text {
    margin: 16px 0 0;
    font-size: 14px;
    color: #737987;
}
.form-failed-text {
    margin: 0 0 4px;
    max-width: 360px;
    font-size: 14px;
    line-height: 1.5em;
    color: #A6322B;
}

/* GATEWAY ERROR */
.pay-alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 18px;
    padding: 14px 16px;
    border: 1px solid #F4C7C3;
    border-radius: 12px;
    background: #FDECEB;
    color: #A6322B;
    font-size: 14px;
    line-height: 1.5em;
}
.pay-alert svg {
    flex: 0 0 16px;
    margin-top: 2px;
}
.pay-alert-code {
    display: inline-block;
    margin-left: 4px;
    font-style: normal;
    font-size: 12px;
    opacity: .75;
}

/* CONFIRMING OVERLAY */
.pay-overlay {
    position: fixed;
    inset: 0;
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(19, 14, 43, .55);
    backdrop-filter: blur(2px);
}
.pay-overlay-box {
    background: #fff;
    border-radius: 16px;
    padding: 34px 30px;
    text-align: center;
    max-width: 360px;
    width: 100%;
    box-shadow: 0 18px 40px rgba(19, 14, 43, .18);
}
.pay-overlay-title {
    margin: 18px 0 6px;
    font-size: 18px;
    font-weight: 600;
    color: #130E2B;
}
.pay-overlay-note {
    margin: 0;
    font-size: 13.5px;
    line-height: 1.5em;
    color: #737987;
}
.pay-overlay-dismiss {
    margin-top: 18px;
    padding: 10px 18px;
    border: 1px solid #D7D7E0;
    border-radius: 10px;
    background: #F7F7FA;
    color: #130E2B;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.2em;
}
.pay-overlay-dismiss:hover {
    background: #EFEFF5;
}

/* AMOUNT DUE */
.amount-due {
    display: flex;
    align-items: baseline;
    gap: 8px;
    font-size: 20px;
    font-weight: 700;
    color: #130E2B;
    white-space: nowrap;
}
.amount-due-label {
    font-size: 13px;
    font-weight: 400;
    color: #737987;
}

.pay-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 16px 0 0;
    font-size: 13px;
    line-height: 1.5em;
    color: #6B7280;
}
.pay-note svg {
    flex: 0 0 12px;
    margin-top: 3px;
}

/* DESKTOP RAIL */
@media only screen and (min-width: 992px) {
    .pay-grid {
        grid-template-columns: minmax(0, 1fr) 380px;
        gap: 24px;
    }
    .pay-items {
        grid-column: 1;
        grid-row: 1;
    }
    .pay-method-card {
        grid-column: 1;
        grid-row: 2;
    }
    .pay-summary {
        grid-column: 2;
        grid-row: 1 / span 2;
        position: sticky;
        top: 20px;
    }
    .pay-card {
        padding: 28px;
    }
}

/* checkout-right ships with bootstrap column widths it does not need here */
.pay-summary {
    max-width: none;
    flex: none;
    padding-left: 0;
    padding-right: 0;
    width: 100%;
}

@media only screen and (max-width: 767px) {
    .payment-section {
        padding-bottom: 40px;
    }
    .pay-head {
        margin-bottom: 16px;
    }
    .pay-title {
        font-size: 22px;
    }
    .pay-card {
        padding: 18px 16px;
        border-radius: 12px;
    }
    .card-title {
        font-size: 17px;
    }
    .method-list {
        grid-template-columns: minmax(0, 1fr);
        margin-top: 16px;
    }
    .form-group {
        padding: 14px;
        margin-top: 16px;
    }
    .card-head {
        flex-wrap: wrap;
        gap: 4px 12px;
    }
    .amount-due {
        font-size: 18px;
    }
}
</style>
