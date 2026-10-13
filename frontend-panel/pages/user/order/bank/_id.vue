<template>
    <div class="bank-payment-page">
       <bank-popup
         v-if="showReference"
         :order="order"
         @close="referenceClosed"
       />
       <navigation-step />
       <div class="container-fluid bank-payment-container">
            <div class="row g-4 payment-main-row">
                <div class="col-lg-8">
                    <div class="page-heading">
                        <div class="icon-circle">
                            <i class="fa-solid fa-building-columns"></i>
                        </div>
                        <div>
                            <div class="page-title">Bank Transfer Payment</div>
                            <div class="order-id">Order #{{ order?.order }}</div>
                        </div>
                    </div>
                    <div v-if="paymentState === 'paid'" class="card-box state-box state-paid">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>We have received your payment for this order. Thank you.</span>
                    </div>
                    <div v-else-if="paymentState === 'cancelled'" class="card-box state-box state-cancelled">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span>This order was cancelled because no bank transfer arrived within {{ holdHours }} hours. Please do not send a payment for it. If you already have, contact us and we will sort it out.</span>
                    </div>

                    <div class="card-box info-box">
                        <div class="section-heading mb-2">
                            <span class="info-icon">
                               <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 24C18.623 24 24 18.623 24 12C24 5.37702 18.623 0 12 0C5.37702 0 0 5.37702 0 12C0 18.623 5.37702 24 12 24ZM10.125 15.75H11.25V12.75H10.125C9.50156 12.75 9 12.2484 9 11.625Cnan nan 9.50156 10.5 10.125 10.5H12.375C12.9984 10.5 13.5 11.0016 13.5 11.625V15.75H13.875C14.4984 15.75 15 16.2516 15 16.875Cnan nan 14.4984 18 13.875 18H10.125C9.50156 18 9 17.4984 9 16.875Cnan nan 9.50156 15.75 10.125 15.75ZM12 6C12.8279 6 13.5 6.67213 13.5 7.5C13.5 8.32787 12.8279 9 12 9C11.1721 9 10.5 8.32787 10.5 7.5C10.5 6.67213 11.1721 6 12 6Z" fill="#130E2B"/>
                                </svg>
                          </span>
                            <div class="info-title">Important Payment Information</div>
                        </div>
                        <ul class="payment-list mb-0">
                            <li>Please complete your bank transfer within {{ holdHours }} hours to secure your order</li>
                            <li>You must include your Order Number as the payment reference</li>
                            <li>If payment is not received within {{ holdHours }} hours, your order will be automatically cancelled</li>
                            <li>Please note: The same pricing and availability may not be guaranteed if you need to reorder</li>
                        </ul>
                    </div>

                    <div class="card-box bank-details-card">
                        <div class="bank-card-header">
                            <i class="fa-solid fa-building-columns"></i>
                            <div class="header-title">Bank Account Details</div>
                        </div>

                        <div class="bank-card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="bank-tittle">Bank Name</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.bank_name" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.bank_name, 'bank_name')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['bank_name'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="bank-tittle">Account Name</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.account_name" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.account_name, 'account_name')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['account_name'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>

                                <div class="col-md-6" v-if="showAccountNumber">
                                    <div class="bank-tittle">Account Number</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.account_number" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.account_number, 'account_number')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['account_number'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>

                                <div class="col-md-6" v-if="payment && payment.sort_code">
                                    <div class="bank-tittle">Sort Code</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.sort_code" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.sort_code, 'sort_code')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['sort_code'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>

                                <div class="col-md-6" v-if="iban">
                                    <div class="bank-tittle">IBAN</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="iban" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(iban, 'iban')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['iban'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>

                                <div class="col-md-6" v-if="payment && payment.bic_swift">
                                    <div class="bank-tittle">BIC/SWIFT Code</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.bic_swift" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.bic_swift, 'bic_swift')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['bic_swift'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>

                                <div class="col-12 amount-field">
                                    <div class="bank-tittle">Payment Amount</div>
                                    <div class="input-box position-relative">
                                        <input type="text" class="form-control form-account-deatils pe-5" :value="order?.calculated?.total_price ? '\u20ac' + order.calculated.total_price : ''" readonly>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(order?.calculated?.total_price, 'total_price')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['total_price'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                </div>
                            </div>

                            <div class="payment-ref">
                                <div class="payment-ref-label">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <span>Payment Reference</span>
                                </div>
                                <div class="payment-ref-field">
                                    <div class="ref-box position-relative">
                                        <span>{{ order?.order }}</span>
                                        <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(order?.order, 'order')"></i>
                                        <span class="copy-msg" :style="{ opacity: copyState['order'] ? 1 : 0 }">Copied!</span>
                                    </div>
                                    <small>
                                        <i class="fa-solid fa-circle-info"></i>
                                        You MUST include this order number as your payment reference
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                          <div class="card-box instruction-card">
                        <div class="section-heading">
                            <i class="fa-solid fa-list-check"></i>
                            <h6>Step-by-Step Payment Instructions</h6>
                        </div>

                        <div class="step">
                            <div class="step-number">1</div>
                            <div class="step-login-tittle">
                                <h5>Log in to your online banking</h5>
                                <span class="step-login-subtittle">Access your bank's online banking platform or mobile app</span>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">2</div>
                            <div class="step-login-tittle">
                                <h5>Set up a new payment or transfer</h5>
                                <span class="step-login-subtittle">Select the option to make a payment to a new beneficiary</span>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <div class="step-login-tittle">
                                <h5>Enter the bank account details</h5>
                                <span class="step-login-subtittle">Copy the IBAN and BIC provided above</span>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">4</div>
                            <div class="step-login-tittle">
                                <h5>Enter the payment amount</h5>
                                <span class="step-login-subtittle">Transfer exactly &euro;{{ order?.calculated?.total_price }} to ensure proper processing</span>
                            </div>
                        </div>
                        <div class="step featured-step">
                            <div class="step-number">5</div>
                            <div class="step-login-tittle">
                                <h5>
                                    Add payment reference
                                    <span class="required-badge">REQUIRED</span>
                                </h5>
                                <span class="step-login-subtittle">In the reference/description field, enter: {{ order?.order }}</span>
                                <small>This is critical for tracking your payment to your order</small>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">6</div>
                            <div class="step-login-tittle">
                                <h5>Review and confirm the payment</h5>
                                <span class="step-login-subtittle">Double-check all details before authorizing the transfer</span>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">7</div>
                            <div class="step-login-tittle">
                                <h5>Save your payment receipt</h5>
                                <span class="step-login-subtittle">Keep a screenshot or PDF of your transaction confirmation for your records</span>
                            </div>
                        </div>
                    </div>

                    <div class="card-box next-card">
                        <div class="section-heading mb-3">
                            <i class="fa-solid fa-clock"></i>
                            <div class="info-title">What Happens Next?</div>
                        </div>
                        <ul class="list-unstyled info-list">
                            <li>
                                <i class="fa-solid fa-circle-check"></i>
                                <span class="happen-next-text">We will monitor our bank account for incoming payments matching your order number</span>
                            </li>
                            <li>
                                <i class="fa-solid fa-circle-check"></i>
                                <span class="happen-next-text">Once your payment is confirmed (usually within 1-2 business days), we'll send you an email confirmation</span>
                            </li>
                            <li>
                                <i class="fa-solid fa-circle-check"></i>
                                <span class="happen-next-text">Your order will be processed and shipped according to our standard delivery times</span>
                            </li>
                            <li>
                                <i class="fa-solid fa-circle-check"></i>
                                <span class="happen-next-text">You can track your order status in your account dashboard under "My Orders"</span>
                            </li>
                        </ul>
                    </div>

                    <div class="card-box policy-card">
                        <div class="policy-header">
                            <div class="icon-warning">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <h5 class="plicy-heading">Automatic Cancellation Policy</h5>
                        </div>
                        <div class="plicy-subheading">
                            <p>
                                If we do not receive your payment within {{ holdHours }} hours from the time of order placement, your order will be automatically cancelled to free up inventory for other customers.
                            </p>
                            <p>
                                Please note that pricing, promotional offers, and product availability may change. If your order is cancelled and you wish to place a new order, the same pricing and deals may no longer be available.
                            </p>
                            <p class="mb-0 policy-note">
                                <i class="fa-solid fa-circle-info"></i>
                                To avoid cancellation and secure your current pricing, please complete your bank transfer as soon as possible.
                            </p>
                        </div>
                    </div>

                    <div class="card-box help-section">
                        <div class="section-heading mb-3">
                            <i class="fa-solid fa-circle-question"></i>
                            <h5 class="need-help">Need Help?</h5>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="help-card">
                                    <div>
                                        <i class="fa-solid fa-phone"></i>
                                        <strong>Call Us</strong>
                                    </div>
                                    <span>01 504 7000</span>
                                    <span>0874523344</span>
                                    <small>Mon-Fri: 9am-6pm</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="help-card">
                                    <div>
                                        <i class="fa-solid fa-envelope"></i>
                                        <strong>Email Us</strong>
                                    </div>
                                    <span>support@fixmypc.ie</span>
                                    <small>We respond within 24 hours</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="payment-actions">
                        <a href="#" class="return-link">
                            <i class="fa-solid fa-angle-left"></i>
                            Return to payment methods
                        </a>
                        <!-- Asks for the transfer reference first. It went straight
                             on to "complete" and the reference was never taken, so
                             staff were not told and the order was cancelled for
                             non-payment by a customer who had paid. -->
                        <button class="btn btn-primary-custom" @click="askForReference">
                            <i class="fa-solid fa-check"></i>
                            I Have Made the Payment
                        </button>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="timer-card" v-if="paymentState === 'awaiting' && order?.transfer_deadline">
                        <i class="fa-solid fa-hourglass-half timer-icon"></i>
                        <h5>Payment Deadline</h5>
                        <p class="timer-subtitle">Complete payment within</p>
                        <div class="timer-box text-center">
                            <div class="time">{{ formattedTime }}</div>
                            <div class="label">HOURS : MINUTES : SECONDS</div>
                        </div>
                        <p class="order-placed">Order placed: {{ order?.created }}</p>
                    </div>
                    <!-- The sweep does not cancel an order once a reference
                         is in: we are the ones it is waiting on. -->
                    <div class="timer-card" v-else-if="paymentState === 'checking'">
                        <i class="fa-solid fa-magnifying-glass-dollar timer-icon"></i>
                        <h5>Checking your transfer</h5>
                        <p class="timer-subtitle">You gave us reference {{ order.trans_id }}. We will email you as soon as it arrives.</p>
                        <p class="order-placed">Order placed: {{ order?.created }}</p>
                    </div>

                    <div class="summary-box">
                        <h6>Order Summary</h6>

                        <div
                            v-for="item in order?.ordered_products"
                            :key="item.id"
                            class="summary-product"
                        >
                            <img :src="getImageURL(item.product?.image)" class="product-img">

                            <div class="summary-product-info">
                                <strong>{{ item.product?.title }}</strong>
                                <span class="text-muted-small">
                                    {{ item?.updated_inventory?.inventory_attributes?.map(i => i.attribute_value.title).join(' / ') }}
                                </span>
                                <span class="price">&euro;{{ item.selling }}</span>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="discount-box">
                            <input type="text" class="form-control" placeholder="Discount code">
                            <button type="button">Apply</button>
                        </div>

                        <div class="summary-line">
                            <span>Subtotal</span>
                            <h6 class="subtotal-price">&euro;{{ order?.calculated?.subtotal }}</h6>
                        </div>
                        <div class="summary-line">
                            <span>Shipping</span>
                            <h6 class="subtotal-price">&euro;{{ order?.calculated?.shipping_price }}</h6>
                        </div>
                        <div class="summary-line">
                            <span>Tax (Excl. VAT)</span>
                            <h6 class="subtotal-price">&euro;{{ order?.calculated?.tax }}</h6>
                        </div>

                        <div class="divider"></div>

                        <div class="summary-total">
                            <div>
                                <div class="total-label">Total</div>
                                <small>Including &euro;{{ order?.calculated?.tax }} in taxes</small>
                            </div>
                            <div class="total-text">&euro;{{ order?.calculated?.total_price }}</div>
                        </div>

                        <div class="order-info">
                            <span class="order-info-text">
                                <strong>Order Number:</strong>
                                <span>{{ order?.order }}</span>
                            </span>
                            <span class="order-info-text">
                                <strong>Order Date:</strong>
                                <span>{{ order?.created }}</span>
                            </span>
                            <span class="order-info-text">
                                <strong>Payment Method:</strong>
                                <span>Bank Transfer</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'
import BankPopup from '~/components/BankPopup'

 export default {
    data() {
      return {
        fetching: false,
        order: {},
        payment:{},
        copyState: {},
        remainingTime: 0,
        timerInterval: null,
        showReference: false
      }
    },
    components: { BankPopup },
    mixins: [util],
    computed: {
        orderId() {
            return parseInt(this.$route.params.id)
        },
        formattedTime() {
            const hours = String(Math.floor(this.remainingTime / 3600)).padStart(2, '0')
            const minutes = String(Math.floor((this.remainingTime % 3600) / 60)).padStart(2, '0')
            const seconds = String(this.remainingTime % 60).padStart(2, '0')
            return `${hours}:${minutes}:${seconds}`
        },
        ...mapGetters('order', ['ordered']),
        ...mapGetters('language', ['langCode']),
        ...mapGetters('common', ['setting']),
        holdHours() {
            return parseInt(this.payment?.bank_transfer_hours) || 24
        },
        paymentState() {
            if (parseInt(this.order?.payment_done) === 1) return 'paid'
            if (parseInt(this.order?.status) === 6 || parseInt(this.order?.cancelled) === 1) return 'cancelled'
            if (this.order?.trans_id) return 'checking'
            return 'awaiting'
        },
        /*
         * The IBAN is what a SEPA transfer is addressed by. Older settings
         * only had account_number, and the IBAN was typed in there.
         */
        iban() {
            if (this.payment?.iban) return this.payment.iban
            return this.looksLikeIban(this.payment?.account_number) ? this.payment.account_number : ''
        },
        // Only when it says something the IBAN does not.
        showAccountNumber() {
            const number = this.payment?.account_number
            return !!number && this.compact(number) !== this.compact(this.iban)
        }
    },
    async mounted() {
        console.log("Route ID:", this.$route.params.id)
        await this.fetchOrder()
    },
    methods: {
        compact(value) {
            return String(value || '').replace(/\s+/g, '').toUpperCase()
        },
        looksLikeIban(value) {
            return /^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(this.compact(value))
        },
        askForReference() {
            // Nothing to send once it is paid or off; just move on.
            if (this.paymentState === 'paid' || this.paymentState === 'cancelled') {
                return this.$router.push(`/user/order/complete/${this.order?.id}`)
            }
            this.showReference = true
        },
        /*
         * The popup closes the same way whether the reference was saved or
         * the customer backed out, so the order is read again to tell which.
         * Saved: on to "complete". Backed out: stay here, details on screen.
         */
        async referenceClosed() {
            this.showReference = false
            await this.fetchOrder()

            if (this.order?.trans_id) {
                this.$router.push(`/user/order/complete/${this.order.id}`)
            }
        },
        startTimer() {
            // fetchOrder() runs again after the reference popup; one clock only.
            if (this.timerInterval) {
                clearInterval(this.timerInterval)
                this.timerInterval = null
            }
            /*
             * The server's deadline, the same moment the sweep cancels at.
             * This used to parse order.created, a display string such as
             * "06:10 am, 23 Sep, 26" that Date cannot read.
             */
            if (!this.order?.transfer_deadline) return

            const expiryTime = new Date(this.order.transfer_deadline).getTime()
            if (isNaN(expiryTime)) return

            this.updateRemainingTime(expiryTime)

            this.timerInterval = setInterval(() => {
            this.updateRemainingTime(expiryTime)
            }, 1000)
        },
        updateRemainingTime(expiryTime) {
            const now = new Date().getTime()
            const diff = Math.floor((expiryTime - now) / 1000)

            if (diff <= 0) {
            this.remainingTime = 0
            clearInterval(this.timerInterval)
            } else {
            this.remainingTime = diff
            }
        },
        async fetchPaymentDetails() {
            try {
                this.fetching = true
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
                const response = await this.$axios.get(`${baseUrl}api/v1/payment-gateway`);
                this.payment = response?.data?.data;

            } catch (error) {
                console.error("API Error:", error)
            } finally {
                this.fetching = false
            }
        },
        async fetchOrder() {
            this.fetching = true
            try {
                await this.getOrderByUser({
                payload: {
                    order_id: this.orderId,
                    user_token: await this.getUserToken(),
                    time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone
                },
                lang: this.langCode
                })
                this.order = this.ordered
                this.startTimer()
                await this.fetchPaymentDetails();
            } catch (e) {
                console.log("Order fetch error", e)
            }
            this.fetching = false
        },
        async copyToClipboard(text, index) {
            if (!text) return
            try {
            await navigator.clipboard.writeText(text)

            this.$set(this.copyState, index, true)

            setTimeout(() => {
                this.$set(this.copyState, index, false)
            }, 1500)

            } catch (e) {
            console.log("Copy failed", e)
            }
        },
        ...mapActions('order', ['getOrderByUser']),
        ...mapActions('user', ['getUserToken'])
    },
    beforeDestroy() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval)
        }
    },
 }
</script>
<style scoped>
.state-box {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 18px;
    margin-bottom: 16px;
    font-size: 14px;
    line-height: 21px;
}
.state-box i {
    font-size: 18px;
    margin-top: 1px;
    flex: 0 0 auto;
}
.state-paid {
    background: #effaf4;
    border: 1px solid #c9ecda;
    color: #166c48;
}
.state-cancelled {
    background: #fdf1f1;
    border: 1px solid #f3cccc;
    color: #9b2c2c;
}

.bank-payment-page {
    background: #f7f7fa;
    color: #15142f;
    font-family: Inter, "Segoe UI", Arial, sans-serif;
    min-height: 100vh;
    padding: 13px 0 28px;
}

.bank-payment-container {
    max-width: 1298px;
    padding-left: 2px;
    padding-right: 20px;
}

.payment-main-row {
    align-items: flex-start;
}

.payment-support-row {
    margin-top: 24px;
}

.page-heading {
    align-items: center;
    display: flex;
    gap: 11px;
    margin-bottom: 18px;
}

.icon-circle {
    align-items: center;
    background: #05b942;
    border-radius: 50%;
    color: #fff;
    display: flex;
    flex: 0 0 38px;
    height: 38px;
    justify-content: center;
    width: 38px;
}

.icon-circle i {
    color: #fff;
    font-size: 18px;
}

.page-title {
    color: #161532;
    font-size: 24px;
    font-weight: 400;
    line-height: 1.15;
}

.order-id {
    color: #74798a;
    font-size: 12px;
    font-weight: 400;
    line-height: 1.4;
    margin-top: 2px;
}

.card-box,
.summary-box {
    background: #fff;
    border: 1px solid #e1e3f0;
    border-radius: 10px;
    margin-bottom: 18px;
}

.card-box {
    padding: 19px 24px;
}

.section-heading,
.bank-card-header,
.policy-header {
    align-items: center;
    display: flex;
}

.section-heading {
    gap: 10px;
}

.section-heading h6,
.section-heading h5,
.info-title,
.header-title {
    color: #15142f;
    font-size: 16px;
    font-weight: 400;
    line-height: 1.3;
    margin: 0;
}

.info-box {
    min-height: 150px;
}
/* 
.info-icon {
    align-items: center;
    background: #15142f;
    border-radius: 50%;
    color: #fff;
    display: inline-flex;
    height: 20px;
    justify-content: center;
    width: 20px;
} */
/* 
.info-icon i {
    color: #fff;
    font-size: 11px;
} */

.payment-list {
    color: #74798a;
    font-size: 12px;
    line-height: 1.45;
    padding-left: 34px;
}

.payment-list li + li {
    margin-top: 6px;
}

.bank-details-card {
    overflow: hidden;
    padding: 0;
}

.bank-card-header {
    background: #f0eff9;
    border-bottom: 1px solid #e1e3f0;
    gap: 10px;
    min-height: 48px;
    padding: 0 18px;
}

.bank-card-header i,
.instruction-card .section-heading i,
.next-card .section-heading i,
.help-section .section-heading i {
    color: #33319a;
    font-size: 15px;
}

.bank-card-body {
    padding: 22px 24px;
}

.bank-tittle {
    color: #15142f;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.2;
    margin-bottom: 8px;
}

.input-box {
    align-items: center;
    background: #fff;
    border: 1px solid #e1e3f0;
    border-radius: 8px;
    display: flex;
    height: 45px;
    justify-content: space-between;
    padding: 0 15px;
}

input.form-account-deatils {
    background: transparent;
    border: 0;
    color: #15142f;
    font-size: 12px;
    font-weight: 400;
    height: 100%;
    padding-left: 0;
}

input.form-account-deatils:focus,
.discount-box input:focus,
input.form-control:focus {
    border-color: #e1e3f0;
    box-shadow: none !important;
    outline: none !important;
}

.amount-field {
    margin-top: 8px;
}

.amount-field input.form-account-deatils {
    font-size: 20px;
}

.copy-icon {
    color: #5f636b;
    cursor: pointer;
    font-size: 14px;
}

.copy-msg {
    background: #15142f;
    border-radius: 4px;
    color: #fff;
    font-size: 11px;
    opacity: 0;
    padding: 3px 6px;
    position: absolute;
    right: 0;
    top: -24px;
    transition: opacity 0.2s ease;
    white-space: nowrap;
}

.payment-ref {
    align-items: center;
    background: #05bf47;
    border-radius: 10px;
    color: #fff;
    display: grid;
    gap: 18px;
    grid-template-columns: 235px minmax(0, 1fr);
    margin-top: 24px;
    min-height: 89px;
    padding: 13px 17px;
}

.payment-ref-label {
    align-items: center;
    display: flex;
    font-size: 12px;
    font-weight: 600;
    gap: 10px;
}

.payment-ref-label i {
    font-size: 12px;
}

.payment-ref-field {
    min-width: 0;
}

.ref-box {
    align-items: center;
    background: #fff;
    border-radius: 7px;
    color: #15142f;
    display: flex;
    font-size: 14px;
    font-weight: 400;
    height: 44px;
    justify-content: space-between;
    letter-spacing: 0;
    padding: 0 14px 0 17px;
    width: 100%;
}

.payment-ref-field small {
    align-items: center;
    color: #fff;
    display: flex;
    font-size: 10px;
    font-weight: 400;
    gap: 6px;
    line-height: 1.2;
    margin-top: 7px;
}

.timer-card {
    background: linear-gradient(116deg, #252376 0%, #8a0074 100%);
    border-radius: 10px;
    color: #fff;
    min-height: 255px;
    padding: 28px 24px 24px;
    text-align: center;
}

.timer-icon {   
    color: #fff;
    font-size: 27px;
    margin-bottom: 15px;
}

.timer-card h5 {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.2;
    margin: 0;
}

.timer-subtitle,
.order-placed {
    color: #fff;
    font-size: 10px;
    font-weight: 400;
    line-height: 1.3;
    margin: 4px 0 0;
}

.timer-box {
    background: #fff;
    border-radius: 14px;
    color: #15142f;
    display: block;
    margin: 22px auto 16px;
    max-width: 314px;
    padding: 10px 20px 11px;
    width: 100%;
}

.timer-box .time {
    color: #33319a;
    font-size: 30px;
    font-weight: 700;
    letter-spacing: 3px;
    line-height: 1.05;
}

.timer-box .label {
    color: #15142f;
    font-size: 10px;
    font-weight: 400;
    line-height: 1.2;
    margin-top: 4px;
}

.summary-box {
    margin-top: 20px;
    padding: 19px 20px 16px;
}

.summary-box h6 {
    color: #15142f;
    font-size: 20px;
    font-weight: 400;
    line-height: 1.25;
    margin: 0;
}

.summary-product {
    display: flex;
    gap: 15px;
    margin-top: 18px;
}

.product-img {
    border: 1px solid #e7e8f1;
    border-radius: 7px;
    flex: 0 0 77px;
    height: 75px;
    object-fit: contain;
    width: 77px;
}

.summary-product-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding-top: 7px;
}

.summary-product-info strong {
    color: #232159;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.3;
}

.text-muted-small {
    color: #74798a;
    font-size: 12px;
    line-height: 1.35;
    margin-top: 6px;
}

.price {
    color: #15142f;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.35;
    margin-top: 5px;
}

.divider {
    border-top: 1px solid #ececf3;
    margin: 19px 0;
}

.discount-box {
    display: flex;
    height: 41px;
    margin-bottom: 20px;
}

.discount-box input {
    border: 1px solid #e6e6ed;
    border-radius: 8px 0 0 8px;
    color: #15142f;
    font-size: 12px;
    height: 100%;
    padding: 0 10px;
}

.discount-box input::placeholder {
    color: #71717a;
}

.discount-box button {
    background: #9998d1;
    border: 0;
    border-radius: 0 8px 8px 0;
    color: #fff;
    flex: 0 0 65px;
    font-size: 13px;
    font-weight: 600;
}

.summary-line,
.summary-total,
.order-info-text {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.summary-line {
    color: #15142f;
    font-size: 14px;
    line-height: 1.3;
    margin-bottom: 17px;
}

h6.subtotal-price {
    color: #15142f;
    font-size: 14px;
    font-weight: 400;
    margin: 0;
}

.total-label {
    color: #15142f;
    font-size: 16px;
    font-weight: 400;
    line-height: 1.25;
}

.summary-total small {
    color: #74798a;
    display: block;
    font-size: 9px;
    line-height: 1.2;
    margin-top: 1px;
}

.total-text {
    color: #15142f;
    font-size: 20px;
    font-weight: 400;
    line-height: 1.2;
}

.order-info {
    background: #f0f1f8;
    border: 1px solid #e4e5f0;
    border-radius: 8px;
    color: #15142f;
    font-size: 12px;
    margin-top: 21px;
    padding: 13px 15px;
}

.order-info-text + .order-info-text {
    margin-top: 9px;
}

.order-info-text strong {
    color: #666672;
    font-weight: 400;
}

.instruction-card {
    min-height: 256px;
    padding: 28px 31px 22px;
}

.instruction-card .section-heading {
    margin-bottom: 15px;
}

.step {
    align-items: flex-start;
    display: flex;
    gap: 14px;
    margin-bottom: 13px;
}

.step-number {
    background: #33319a;
    border-radius: 50%;
    color: #fff;
    flex: 0 0 26px;
    font-size: 12px;
    font-weight: 400;
    height: 26px;
    line-height: 26px;
    text-align: center;
    width: 26px;
}

.step-login-tittle h5 {
    align-items: center;
    color: #15142f;
    display: flex;
    flex-wrap: wrap;
    font-size: 14px;
    font-weight: 400;
    gap: 8px;
    line-height: 1.3;
    margin: 0 0 3px;
}

.step-login-tittle span,
.step-login-tittle small {
    color: #74798a;
    display: block;
    font-size: 12px;
    font-weight: 400;
    line-height: 1.35;
}

.featured-step {
    background: #f0eff9;
    border-radius: 9px;
    margin-bottom: 17px;
    max-width: 528px;
    padding: 15px 16px;
}

.required-badge {
    background: #05bf47;
    border-radius: 3px;
    color: #fff !important;
    display: inline-block !important;
    font-size: 9px !important;
    font-weight: 600 !important;
    letter-spacing: 0;
    line-height: 1 !important;
    padding: 4px 7px;
}

.next-card {
    padding: 23px 29px 19px;
}

.info-list {
    margin: 0;
}

.info-list li {
    align-items: flex-start;
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}

.info-list li:last-child {
    margin-bottom: 0;
}

.info-list i {
    color: #05b942;
    flex: 0 0 auto;
    font-size: 11px;
    margin-top: 4px;
}

span.happen-next-text {
    color: #15142f;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.45;
}

.policy-card {
    padding: 21px 24px 17px;
}

.policy-header {
    gap: 13px;
}

.icon-warning {
    align-items: center;
    background: #f0eff9;
    border-radius: 50%;
    color: #33319a;
    display: flex;
    flex: 0 0 38px;
    height: 38px;
    justify-content: center;
    width: 38px;
}

.icon-warning i {
    font-size: 17px;
}

h5.plicy-heading {
    color: #15142f;
    font-size: 16px;
    font-weight: 500;
    line-height: 1.35;
    margin: 0;
}

.plicy-subheading {
    color: #15142f;
    font-size: 12px;
    font-weight: 400;
    line-height: 1.35;
    margin-left: 60px;
    margin-top: 2px;
}

.plicy-subheading p {
    margin-bottom: 8px;
}

.policy-note {
    align-items: center;
    display: flex;
    gap: 5px;
}

.policy-note i {
    color: #05b942;
    font-size: 11px;
}

.help-section {
    padding: 21px 23px 22px;
}

h5.need-help {
    color: #232159;
    font-size: 16px;
    font-weight: 400;
    line-height: 1.3;
    margin: 0;
}

.help-card {
    background: #f0eff9;
    border-radius: 6px;
    color: #15142f;
    display: flex;
    flex-direction: column;
    font-size: 12px;
    height: 104px;
    justify-content: center;
    line-height: 1.55;
    padding: 15px;
}

.help-card div {
    align-items: center;
    display: flex;
    gap: 10px;
    margin-bottom: 5px;
}

.help-card i {
    color: #525252;
    font-size: 14px;
}

.help-card strong {
    font-size: 14px;
    font-weight: 400;
}

.help-card small {
    color: #15142f;
    font-size: 10px;
    line-height: 1.3;
    margin-top: 3px;
}

.payment-actions {
    align-items: center;
    display: flex;
    justify-content: space-between;
    margin-top: 2px;
}

.return-link {
    align-items: center;
    color: #15142f;
    display: inline-flex;
    font-size: 12px;
    gap: 7px;
    text-decoration: none;
}

.return-link:hover {
    color: #33319a;
    text-decoration: none;
}

.btn-primary-custom {
    align-items: center;
    background: #33319a;
    border: 0;
    border-radius: 6px;
    box-shadow: 0 8px 18px rgba(51, 49, 154, 0.24);
    color: #fff;
    display: inline-flex;
    font-size: 12px;
    font-weight: 400;
    gap: 8px;
    justify-content: center;
    min-height: 41px;
    padding: 10px 24px;
}

.btn-primary-custom:hover {
    background: #252376;
    color: #fff;
}

@media (max-width: 1199.98px) {
    .bank-payment-container {
        padding-left: 12px;
        padding-right: 12px;
    }

    .payment-ref {
        grid-template-columns: 190px minmax(0, 1fr);
    }
}

@media (max-width: 991.98px) {
    .bank-payment-page {
        padding-top: 14px;
    }

    .payment-support-row {
        margin-top: 0;
    }

    .timer-card {
        margin-top: 0;
    }

    .summary-box {
        margin-bottom: 18px;
    }
}

@media (max-width: 767.98px) {
    .bank-payment-container {
        padding-left: 10px;
        padding-right: 10px;
    }

    .card-box,
    .summary-box {
        border-radius: 9px;
        margin-bottom: 14px;
    }
    .bank-card-header {
        padding: 0 16px;
    }

    .page-title {
        font-size: 18px;
    }

    .payment-list {
        padding-left: 20px;
    }

    .payment-ref {
        grid-template-columns: 1fr;
        gap: 10px;
        padding: 14px;
    }

    .timer-box .time {
        font-size: 27px;
        letter-spacing: 2px;
    }

    .summary-product {
        gap: 12px;
    }

    .product-img {
        flex-basis: 68px;
        height: 68px;
        width: 68px;
    }

    .featured-step {
        max-width: none;
    }

    .plicy-subheading {
        margin-left: 0;
        margin-top: 12px;
    }

    .policy-header {
        align-items: center;
    }

    .payment-actions {
        align-items: stretch;
        flex-direction: column-reverse;
        gap: 14px;
    }

    .btn-primary-custom {
        width: 100%;
    }
}

@media (max-width: 420px) {
    .timer-box {
        padding-left: 12px;
        padding-right: 12px;
    }

    .timer-box .time {
        font-size: 24px;
        letter-spacing: 1px;
    }

    .summary-total,
    .summary-line,
    .order-info-text {
        gap: 12px;
    }

    .order-info-text span {
        text-align: right;
    }
}
</style>
