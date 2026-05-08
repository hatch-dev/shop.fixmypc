<template>
    <div class="bank-payment-page">
        <navigation-step /> 
        <div class="container-fluid my-4">
            <div class="row g-4">
                <!-- LEFT -->
                <div class="col-lg-8">
                    <!-- Header -->
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <!-- Green Icon -->
                        <div class="icon-circle">
                        <i class="fa-solid fa-building-columns"></i>
                        </div>
                        <!-- Text -->
                        <div>
                        <div class="page-title">Bank Transfer Payment</div>
                        <div class="order-id">
                                Order #{{ order?.order }}
                            </div>
                        </div>
                    </div>
                    <!-- Info -->
                    <div class="card-box info-box">
                        <!-- Heading -->
                        <div class="d-flex align-items-center mb-2">
                        <div class="info-icon me-2">
                            <i class="fa-solid fa-circle-info"></i>
                        </div>
                        <div class="info-title">Important Payment Information</div>
                        </div>
                        <!-- List -->
                        <ul class="payment-list mb-0 ms-3">
                        <li>Please complete your bank transfer within 24 hours to secure your order</li>
                        <li>You must include your Order Number as the payment reference</li>
                        <li>If payment is not received within 24 hours, your order will be automatically cancelled</li>
                        <li>Please note: The same pricing and availability may not be guaranteed if you need to reorder</li>
                        </ul>
                    </div>
                    <!-- Bank Details -->
                    <div class="card-box">
                        <!-- Header -->
                        <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="fa-solid fa-building-columns"></i>
                        <div class="header-title">Bank Account Details</div>
                        </div>
                        <!-- Fields -->
                        <div class="row g-3">
                        <!-- Bank Name -->
                        <div class="col-md-6">
                            <div class="bank-tittle">Bank Name</div>
                                <div class="input-box position-relative">
                                    <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.bank_name" readonly>
                                    <i  class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.bank_name, 'bank_name')"></i>
                                    <span class="copy-msg" :style="{ opacity: copyState['bank_name'] ? 1 : 0 }">Copied!</span>
                                </div>
                        </div>
                        <!-- Account Name -->
                        <div class="col-md-6">
                            <div class="bank-tittle">Account Name</div>
                            <div class="input-box position-relative">
                                <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.account_name" readonly>
                                <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.account_name, 'account_name')"></i>
                                <span class="copy-msg" :style="{ opacity: copyState['account_name'] ? 1 : 0 }">Copied!</span>
                            </div>
                        </div>
                        <!-- Account Number -->
                        <div class="col-md-6">
                            <div class="bank-tittle">Account Number</div>
                            <div class="input-box position-relative">
                                <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.account_number" readonly>
                                <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.account_number, 'account_number')"></i>
                                <span class="copy-msg" :style="{ opacity: copyState['account_number'] ? 1 : 0 }">Copied!</span>
                            </div>
                        </div>
                        <!-- Sort Code -->
                        <div class="col-md-6">
                            <div class="bank-tittle">Sort Code</div>
                            <div class="input-box position-relative">
                                <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.sort_code" readonly>
                                <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.sort_code, 'sort_code')"></i>
                                <span class="copy-msg" :style="{ opacity: copyState['sort_code'] ? 1 : 0 }">Copied!</span>
                            </div>
                        </div>
                        <!-- IBAN -->
                        <div class="col-md-6">
                            <div class="bank-tittle">IBAN</div>
                            <div class="input-box position-relative">
                                <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.iban" readonly>
                                <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.iban, 'iban')"></i>
                                <span class="copy-msg" :style="{ opacity: copyState['iban'] ? 1 : 0 }">Copied!</span>
                            </div>
                        </div>
                        <!-- BIC/SWIFT Code -->
                        <div class="col-md-6">
                            <div class="bank-tittle">BIC/SWIFT Code</div>
                            <div class="input-box position-relative">
                                <input type="text" class="form-control form-account-deatils pe-5" :value="payment?.bic_swift" readonly>
                                <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(payment?.bic_swift, 'bic_swift')"></i>
                                <span class="copy-msg" :style="{ opacity: copyState['bic_swift'] ? 1 : 0 }">Copied!</span>
                            </div>
                        </div>
                        <!-- Amount -->
                        <div class="col-12">
                            <div class="bank-title">Payment Amount</div>
                            <div class="input-box position-relative">
                                <input type="text" class="form-control form-account-deatils pe-5" :value="order?.calculated?.total_price" readonly>
                                <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(order?.calculated?.total_price, 'total_price')"></i>
                                <span class="copy-msg" :style="{ opacity: copyState['total_price'] ? 1 : 0 }">Copied!</span>
                            </div>
                        </div>
                        </div>
                        <!-- Payment Reference -->
                        <div class="payment-ref d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="text-white">
                            <div class="fw-semibold">Payment Reference</div>
                            <small>You MUST include this order number as your payment reference</small>
                        </div>
                        <div class="ref-box">
                            {{ order?.order }}
                            <i class="fa-regular fa-copy copy-icon" @click="copyToClipboard(order?.order, 'order')"></i>
                            <span class="copy-msg" :style="{ opacity: copyState['order'] ? 1 : 0 }">Copied!</span>
                        </div>
                        </div>
                    </div>
                    <!-- Steps -->
                    <div class="card-box">
                        <div class="d-flex gap-2">
                        <i class="fa-solid fa-list-check" style="color: #525252;"></i>
                        <h6>Step-by-Step Payment Instructions</h6>
                        </div>
                        <div class="step mt-3">
                        <div class="step-number">1</div>
                        <div class="step-login-tittle">
                            <h5>Log in to your online banking</h5>
                            <span class="step-login-subtittle">Access your bank's online banking platform or mobile app</span>
                        </div>
                        </div>
                        <div class="step mt-3">
                        <div class="step-number">2</div>
                        <div class="step-login-tittle">
                            <h5>Set up a new payment or transfer</h5>
                            <span class="step-login-subtittle">Select the option to make a payment to a new beneficiary</span>
                        </div>
                        </div>
                        <div class="step mt-3">
                        <div class="step-number">3</div>
                        <div class="step-login-tittle">
                            <h5>Enter the bank account details</h5>
                            <span class="step-login-subtittle">Copy the account details provided above (Account Number, Sort Code, or IBAN)</span>
                        </div>
                        </div>
                        <div class="step mt-3">
                        <div class="step-number">4</div>
                        <div class="step-login-tittle">
                            <h5>Enter the payment amount</h5>
                            <span class="step-login-subtittle">Transfer exactly €{{ order?.calculated?.total_price }} to ensure proper processing</span>
                        </div>
                        </div>
                        <div class="step mt-3">
                        <div class="step-number">5</div>
                        <div class="step-login-tittle">
                            <h5>Add payment reference</h5>
                            <span class="step-login-subtittle">In the reference/description field, enter: #{{ order?.order }}</span>
                        </div>
                        </div>
                        <div class="step mt-3">
                        <div class="step-number">6</div>
                        <div class="step-login-tittle">
                            <h5>Review and confirm the payment</h5>
                            <span class="step-login-subtittle">Double-check all details before authorizing the transfer</span>
                        </div>
                        </div>
                    </div>
                    <!------------------------>
                    <!---------------- What Happens Next? ------------>
                    <div class="card-box">
                        <!-- Title -->
                        <div class="d-flex align-items-center mb-3">
                        <div class="clock-icon me-2">
                            <i class="fa-solid fa-clock fs-5" style="color: rgb(51, 49, 153);"></i>
                        </div>
                        <div class="info-title">What Happens Next?</div>
                        </div>
                        <!-- List -->
                        <ul class="list-unstyled info-list">
                        <li>
                            <i class="fa-solid fa-circle-check fs-5" style="color: rgb(5, 185, 66);"></i>
                            <span class="happen-next-text ms-1">We will monitor our bank account for incoming payments matching your order number</span>
                        </li>
                        <li>
                            <i class="fa-solid fa-circle-check fs-5" style="color: rgb(5, 185, 66);"></i>
                            <span class="happen-next-text ms-1">Once your payment is confirmed (usually within 1-2 business days), we'll send you an email confirmation</span>
                        </li>
                        <li>
                            <i class="fa-solid fa-circle-check fs-5" style="color: rgb(5, 185, 66);"></i>
                            <span class="happen-next-text ms-1">Your order will be processed and shipped according to our standard delivery times</span>
                        </li>
                        <li>
                            <i class="fa-solid fa-circle-check fs-5" style="color: rgb(5, 185, 66);"></i>
                            <span class="happen-next-text ms-1">You can track your order status in your account dashboard under "My Orders"</span>
                        </li>
                        </ul>
                    </div>
                    <!--------------------->
                    <!-- Automatic Cancellation -->
                    <div class="card-box mb-4">
                        <div class="d-flex align-items-start mb-2">
                        <div class="icon-circle-Cancellation  icon-warning me-3">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <h5 class="mb-0 plicy-heading">Automatic Cancellation Policy</h5>
                        </div>
                        <div class="plicy-subheading"">
                        <p class="text-mut mb-2">
                            If we do not receive your payment within 24 hours from the time of order placement, your order will be automatically cancelled to free up inventory for other customers.
                        </p>
                        <p class="text-muted mb-2">
                            Please note that pricing, promotional offers, and product availability may change. If your order is cancelled and you wish to place a new order, the same pricing and deals may no longer be available.
                        </p>
                        <p class="mb-0">
                            <i class="fa-solid fa-circle-info"></i>
                            To avoid cancellation and secure your current pricing, please complete your bank transfer as soon as possible.
                        </p>
                        </div>
                    </div>
                    <!------------------>
                    <!-- Need Help -->
                    <div class="card-box mb-4">
                        <div class="d-flex align-items-center mb-3">
                        <div class=" me-3">
                            <i class="fa-solid fa-circle-question fs-5" style="color: rgb(51, 49, 153);"></i>
                        </div>
                        <h5 class="mb-0 need-help">Need Help?</h5>
                        </div>
                        <div class="row g-3">
                        <!-- Call Us -->
                        <div class="col-md-6">
                            <div class="help-card">
                                <div class="d-flex align-items-start">
                                    <div>
                                        <i class="fa-solid fa-phone"></i>
                                    <strong>Call Us</strong><br>
                                    01 504 7000<br>
                                    0874523344<br>
                                    <small class="text-muted">Mon-Fri: 9am-6pm</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Email -->
                        <div class="col-md-6">
                            <div class="help-card">
                                <div class="d-flex align-items-start">
                                    <div>
                                        <i class="fa-solid fa-envelope"></i>
                                    <strong>Email Us</strong><br>
                                    support@fixmypc.ie<br>
                                    <small class="text-muted">We respond within 24 hours</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                    <!----------------->
                    <!-- Bottom Section -->
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="#" class="text-decoration-none text-dark">
                        <i class="fa-solid fa-angle-left me-2" style="color: rgb(19, 14, 43);"></i> Return to payment methods
                        </a>
                        <button class="btn btn-primary-custom" @click="$router.push(`/user/order/complete/${order?.id}`)">
                        <i class="fa-solid fa-check"></i> I Have Made the Payment
                        </button>
                    </div>
                    <!---------------->
                </div>
                <!-- RIGHT -->
                <div class="col-lg-4">
                    <!-- Timer -->
                    <div class="timer-card py-4">
                        <i class="fa-solid fa-hourglass-half fs-4" style="color: rgb(255, 255, 255);"></i>
                        <h5 class="mt-2" style="color: #fff;">Payment Deadline</h5>
                        <div class="timer-box text-center">
                        <div class="time">{{ formattedTime }}</div>
                        <div class="label">HOURS : MINUTES : SECONDS</div>
                        </div>
                        <p class="text-light mt-2 label">Order placed: {{ order?.created }}</p>
                    </div>
                    <!-- Summary -->
                    <div class="summary-box mt-3">
                        <!-- Title -->
                        <h6>Order Summary</h6>
                        <!-- Product -->
                        <div 
                            v-for="item in order?.ordered_products" 
                            :key="item.id" 
                            class="d-flex mt-3"
                            >
                            <img :src="getImageURL(item.product?.image)" class="product-img">

                            <div class="ms-3">
                                <strong>{{ item.product?.title }}</strong><br>

                                <span class="text-muted-small">
                                {{ item?.updated_inventory?.inventory_attributes?.map(i => i.attribute_value.title).join(' / ') }}
                                </span><br>

                                <span class="price">
                                €{{ item.selling }}
                                </span>
                            </div>
                        </div>
                        <div class="divider"></div>
                        <!-- Discount -->
                        <!-- <div class="discount-box">
                        <input type="text" class="form-control" placeholder="Discount code">
                        <button>Apply</button>
                        </div> -->
                        <!-- Pricing -->
                        <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal</span>
                        <h6 class="subtotal-price">€{{ order?.calculated?.subtotal }}</h6>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                        <span>Shipping</span>
                        <h6 class="subtotal-price">€{{ order?.calculated?.shipping_price }}</h6>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                        <span>Tax (Excl. VAT)</span>
                        <h6 class="subtotal-price">€{{ order?.calculated?.tax }}</h6>
                        </div>
                        <div class="divider"></div>
                        <!-- Total -->
                        <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold">Total</div>
                        </div>
                        <div class="total-text">€{{ order?.calculated?.total_price }}</div>
                        </div>
                        <!-- Order Info -->
                        <div class="order-info mt-3">
                        <span class="d-flex justify-content-between order-info-text"><strong>Order Number:</strong> #{{ order?.order }}</span>
                        <span class="d-flex justify-content-between order-info-text"><strong>Order Date:</strong> {{ order?.created }}</span>
                        <span class="d-flex justify-content-between order-info-text"><strong>Payment Method:</strong> Bank Transfer</span>
                        </div>
                    </div>
                    <!-- Secure -->
                    <div class="card-box secure-box mt-3">
                        <i class="fa-solid fa-shield-halved fs-2" style="color: rgb(75, 85, 99);"></i><br>
                        <h6 class="mt-3 subtotal-price">Secure Transaction</h6>
                        <p class="payment-info">Your payment information is protected with bank-level security</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'

 export default {
    data() {
      return {
        fetching: false,
        order: {},
        payment:{},
        copyState: {},
        remainingTime: 0,
        timerInterval: null
      }
    },
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
        ...mapGetters('common', ['setting'])
    },
    async mounted() {
        console.log("Route ID:", this.$route.params.id)
        await this.fetchOrder()
    },
    methods: {
        startTimer() {
            if (!this.order?.created) return

            const orderTime = new Date(this.order.created).getTime()
            const expiryTime = orderTime + (24 * 60 * 60 * 1000)

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
                const response = await this.$axios.get(`${baseUrl}api/v1/payment/find`);
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
/* Card */
.card-box {
  background: var(--background-color);
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 15px;
  border: 1px solid #e6e8ef;
}

 /* Header */
    .header-title {
      font-weight: 600;
      font-size: var(--product-model);
    }

    /* Input style */
    .input-box {
      background: var(--background-color);
      border: 1px solid #E3E3EF;
      border-radius: 10px;
      padding: 7px 15px 7px 6px;
      font-size: 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 400;
      color:var(--product-title);
    }

    /* Label BELOW */
    .bank-tittle {
      font-size: 14px;
      color: var(--product-title);
      font-weight: 500;
      margin-bottom: 10px;
    }

    /* Copy icon */
    .copy-icon {
      cursor: pointer;
      color: #6c757d;
    }

    /* Payment reference */
    .payment-ref {
      background: #16a34a;
      border-radius: 12px;
      padding: 15px;
      margin-top: 20px;
    }

    /* White reference box */
    .ref-box {
      background: #fff;
      color: #000;
      padding: 8px 12px;
      border-radius: 8px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    input.form-account-deatils {
      background-color: var(--background-color);
      border: none;
    }

    input.form-account-deatils:focus {
      outline: none !important;
      box-shadow: none !important;
    }

    .copy-msg {
      position: absolute;
      right: 0px;
      top: -24px;
      background: #000;
      color: #fff;
      font-size: 11px;
      padding: 3px 6px;
      border-radius: 4px;
      opacity: 0;
      transition: 0.3s;
    }

    .copy-msg[style*="opacity: 1"] {
  transform: translateY(0);
}


/* Green box */
.payment-ref {
  background: #1fb655;
  color: #fff;
  border-radius: 10px;
  padding: 15px;
  font-weight: 600;
}

/* Steps */
.step {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}

.step-number {
    width: 32px;
    height: 32px;
    background: #33319A;
    color: #fff;
    border-radius: 50%;
    text-align: center;
    line-height: 32px;
    font-size: 14px;
    font-weight: 400;
}
.step-login-tittle h5 {
    font-size: var(--product-model);
    font-weight: 400;
    color: var(--product-title);
    margin-bottom: 3px;

}
.step-login-tittle span {
    font-size: 14px;
    font-weight: 400;
    color: var(--subheading-color);
}
/* Timer card */
.timer-card {
  background: linear-gradient(135deg, #252376, #800073);
  color: #fff;
  border-radius: 12px;
  padding: 20px;
  text-align: center;
}
/* background: linear-gradient(93.64deg,  0%,  100%); */

.timer-box {
  background: #fff;
  color: #000;
  border-radius: 10px;
  padding: 10px;
  font-weight: 700;
  font-size: var(--product-font);
  margin: 10px 0;
}

/* Summary box */
 .summary-box {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 20px;
}
  .product-img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    object-fit: contain;
  }

  .summary-box h6 {
    font-size: 18px;
    font-weight: 600;
  }

  .text-muted-small {
    font-size: 13px;
    color: var(--subheading-color);
  }

  .price {
    font-weight: 600;
    color: #111827;
  }

  .divider {
    border-top: 1px solid #e5e7eb;
    margin: 15px 0;
  }

  .discount-box {
    display: flex;
    margin: 15px 0;
  }

  .discount-box input {
    border-radius: 8px 0 0 8px;
    border: 1px solid #e5e7eb;
    padding: 10px;
  }

  .discount-box button {
    border-radius: 0 8px 8px 0;
    background: #8b8fc9;
    color: #fff;
    border: none;
    padding: 0 20px;
  }

  .total-text {
    font-size: 18px;
    font-weight: 700;
  }

  .order-info {
    background: #eef0f6;
    border-radius: 10px;
    padding: 15px;
    margin-top: 15px;
    font-size: 14px;
  }

  .order-info span {
    display: block;
    margin-bottom: 5px;
  }

/* Secure */
.secure-box {
  text-align: center;
  padding: 15px;
  font-size: 13px;
}
.icon-circle i {
    background-color: #05B942;
    color: #fff !important;
    padding: 13px 15px;
    border-radius: 100px;
    font-size: 26px;
}
ul.payment-list li {
    color: var(--subheading-color);
    font-size: 14px;
    font-weight: 400;
}
.info-icon i {
    background-color: var(--product-title);
    border-radius: 100px;
    font-size: 20px;
    padding: 4px 5px;
}
.info-title {
    font-size: 18px;
    color: var(--product-title);
    font-weight: 400;
}
ul.payment-list li {
    font-size: 14px;
    color: var(--subheading-color);
    font-weight: 400;
}
/* what happen next-----*/
   .info-box {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #E3E3EF;
}

    .info-title {
      font-weight: 600;
      font-size: 18px;
    }

    .info-list li {
      margin-bottom: 10px;
      display: flex;
      align-items: start;
      gap: 10px;
    }

    .info-list i {
      color: #28a745;
      font-size: 16px;
      margin-top: 3px;
    }
span.happen-next-text {
    font-size: 14px;
    font-weight: 400;
    color: var(--product-title);
    margin-top: 3px;
}
    /*-----------*/
.icon-warning {
    background: #F1F0F9;
    color: #4f46e5;
    width: 48px;
    height: 48px;
    border-radius: 100px;
    text-align: center;
    font-size: 21px;
    line-height: 45px;
}

 .help-card {
    background: #F0EFF8;
    border-radius: 9px;
    padding: 20px;
    height: 130px;
}

  .btn-primary-custom {
    background: #4338ca;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    color: #fff;
  }

  .btn-primary-custom:hover {
    background: #000;
    color: #fff;
  }
h5.mb-0.plicy-heading {
    font-size: 18px;
    font-weight: 500;
    color: var(--product-title);
    margin-top: 14px;
}
.plicy-subheading {
    margin-left: 62px;
    font-size: 14px;
    font-weight: 400;
    color: var(--product-title);
}
h5.need-help {
    font-size: 18px;
    font-weight: 400;
    color: #232159;
    margin-top: -3px;
}
.timer-box {
    background: #fff;
    border-radius: 20px;
    padding: 9px 25px;
    display: inline-block;
    min-width: 320px;
}

.timer-box .time {
    font-size: 36px;
    font-weight: 700;
    color: #33319A;
    letter-spacing: 3.8px;
}

.timer-box .label {
    font-size: 12px;
    margin-top: -3px;
    color: var(--product-title);
    font-weight: 400;
}
input.form-control:focus {
    outline: none !important;
    box-shadow: none !important;
    border-color: #e5e7eb;
}
span.order-info-text {
    font-size: 14px;
    font-weight: 400;
    color: #171717;
}
span.order-info-text strong {
    color: #525252 !important;
}
h6.subtotal-price {
    font-size: 16px;
    font-weight: 400;
    color: var(--product-title);
}
p.payment-info {
    color: #525252;
    font-weight: 400;
    font-size: 12px;
}
</style>