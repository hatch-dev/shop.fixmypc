<template>
    <div class="bank-payment-page">
        <div class="container-fluid my-4">
            <div class="main-card">

                <!-- Success -->
                <div class="text-center mb-4">
                    <div class="success-icon">
                        <img :src="successImg" class="complete-order-image" alt="Payment Success">
                    </div>
                    <h5 class="mt-3 order-placed">Order placed! Thank you for your payment</h5>
                    <p class="small-text-thanku">Thank you for choosing Financy.com, once the payment is released to us we will ship your order and email you a tracking number.</p>
                </div>

                <!-- Order Box -->
                <div class="order-box d-flex justify-content-between align-items-center mb-4">
                    <div class="small-text-order">
                        <strong>Order No:</strong> #{{ order?.order }}<br>
                        <strong>Order Date:</strong> {{ order?.created }}<br>
                    </div>
                    <button class="btn manage-btn" @click="$router.push(`/user/order/${order?.id}`)">Manage Order</button>
                </div>

                <!-- Address -->
                <div class="row mb-4 mt-5">
                    <div class="col-md-6">
                        <div class="section-title-billing">Billing Address</div>
                    <ul class="billing-address">
                        <li>{{ order?.address?.name }}</li>
                        <li>
                            {{ [
                                order?.address?.address_1,
                                order?.address?.address_2
                            ].filter(Boolean).join(', ') }}
                        </li>
                        <li>
                            {{ order?.address?.city }},
                            {{ order?.address?.state }} -
                            {{ order?.address?.zip }}
                        </li>

                        <li>{{ order?.address?.country }}</li>

                        <li>{{ order?.address?.phone }}</li>

                        <li>{{ order?.address?.email }}</li>
                    </ul>
                    </div>

                    <div class="col-md-6">
                        <div class="section-title-billing"">Shipping Address</div>
                        <ul class="billing-address">
                            <li>
                                {{ [
                                order?.ordered_products?.[0]?.shipping_place?.pickup_address_line_1,
                                order?.ordered_products?.[0]?.shipping_place?.pickup_address_line_2
                                ].filter(Boolean).join(', ') }}
                            </li>

                            <li>
                                {{ order?.ordered_products?.[0]?.shipping_place?.pickup_city }}
                                , {{ order?.ordered_products?.[0]?.shipping_place?.pickup_state }}
                                - {{ order?.ordered_products?.[0]?.shipping_place?.pickup_zip }}
                            </li>

                            <li>{{ order?.ordered_products?.[0]?.shipping_place?.pickup_country }}</li>

                            <li>{{ order?.ordered_products?.[0]?.shipping_place?.pickup_phone }}</li>

                            <li>
                                Delivery in {{ order?.ordered_products?.[0]?.shipping_place?.day_needed }} days
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Payment -->
                <div class="row mb-4 payment-info">
                    <div class="col-md-6 small-text">
                        <div class="section-title-billing">Payment Information</div>
                    <div class="d-flex gap-2">
                        <h6>Payment Amount:</h6> <span class="text-success fw-bold"> €{{ Number(order?.calculated?.total_price || 0).toFixed(2) }}</span>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <h6>Payment Method:</h6>   <span class="payment-method"><i :class="['fa-solid', paymentMethodIcon, 'me-1']"></i>{{ paymentMethodLabel }}</span>
                    </div>
                    </div>

                    <div class="col-md-6 text-end small-text">
                    <div class="d-flex justify-content-end gap-2">
                        <h6 class="mt-1">Payment Status: </h6><span class="status-badge"  :style="{
                            background: order?.payment_done === 1 ? '#05B942' : '#dc3545'
                        }">{{ paymentStatusLabel }}</span><br>
                    </div>
                        <div class="mt-3">
                        <h6> Payment Transaction ID: </h6><strong class="payment-id"> {{ order?.transaction_id || 'N/A' }}</strong><br>
                    </div>
                    
                    </div>
                </div>

                <!-- Timeline -->
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

                <div 
                    v-for="item in order?.ordered_products" 
                    :key="item.id"
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
                            <p>Total Price: <span class="fw-bold">€{{ item.selling }}</span></p>
                        </div>
                        <div class="d-flex justify-content-end price-box-thanku">
                            <p> Shipping Price:<span class="fw-bold">€{{ item.shipping_price || 0 }}</span></p><br>
                        </div>
                        <div class="d-flex justify-content-end price-box-thanku">
                            <p> Tax Price:<span class="fw-bold">€{{ item.tax_price || 0 }}</span></p><br>
                        </div>
                        <div class="d-flex justify-content-end price-box-thanku">
                            <p> Subtotal:<span class="fw-bold">€{{ getItemSubtotal(item) }}</span></p><br>
                        </div>
                    </div>
                </div>

                <!-- Final Total -->
                <div class="d-flex justify-content-end price-box-thanku">
                    <p>Subtotal:<span class="fw-bold"> €{{ order?.total_amount }}</span></p><br>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'
import successImg from '~/assets/images/payment_done.png'

 export default {
    data() {
      return {
        fetching: false,
        order: {},
        successImg
      }
    },
    mixins: [util],
    computed: {
        paymentStatusLabel() {
            if (parseInt(this.order?.order_method) === 7) {
                return this.order?.payment_done === 1
                ? 'Paid'
                : 'Processing'
            }
            return this.order?.payment_done === 1 ? 'Paid' : 'Not Paid'
        },
        paymentMethodIcon() {
            const map = {
                1: 'fa-bolt',
                2: 'fa-money-bill',
                3: 'fa-cc-stripe',
                4: 'fa-cc-paypal',
                7: 'fa-building-columns'
            }

            return map[this.order?.order_method] || 'fa-credit-card'
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

            return map[this.order?.order_method] || 'N/A'
        },
        orderId() {
            return parseInt(this.$route.params.id)
        },
        orderStatusStep() {
            const status = this.order?.status

            const map = {
                placed: 1,
                processing: 2,
                shipping: 3,
                delivered: 4
            }

            return map[status] || 1
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
        getItemSubtotal(item) {
            const total =
                parseFloat(item.selling || 0) +
                parseFloat(item.shipping_price || 0) +
                parseFloat(item.tax_price || 0)

            return total.toFixed(2)
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
                console.log("Fetched Order:", this.order)
            } catch (e) {
                console.log("Order fetch error", e)
            }
            this.fetching = false
        },
        ...mapActions('order', ['getOrderByUser']),
        ...mapActions('user', ['getUserToken'])
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