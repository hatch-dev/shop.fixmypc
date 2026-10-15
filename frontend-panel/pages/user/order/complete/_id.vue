<template>
    <div class="bank-payment-page">
        <div class="container-fluid my-4">

            <!--
                The order is fetched on mount, so the card used to draw itself
                against an empty object first: no order number, no address,
                "N/A" against every figure. That reads as a broken order rather
                than one still on its way.
            -->
            <div v-if="fetching" class="order-skeleton">
                <skeleton-base :height="64" :width="64" circle class="order-skeleton-badge" />
                <skeleton-base :height="24" width="260px" :mb="12" />
                <skeleton-base :height="14" width="340px" :mb="28" />
                <skeleton-card :image="false" :lines="4" />
                <skeleton-card class="order-skeleton-gap" :image="false" :lines="3" />
            </div>

            <!--
                An id in the URL is not proof the order exists, nor that it
                belongs to whoever is looking. The API answers a missing order
                with "No order found." and someone else's with "Not your
                order." - both were swallowed here, so the success card drew
                itself against an empty object: a "Payment Successful" heading
                over a blank order number, a blank date and "Not Paid". Anyone
                editing the id in the address bar got a confirmation for an
                order that does not exist.
            -->
            <div v-else-if="loadFailed" class="order-loading">
                <div class="order-error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h5 class="order-error-title">{{ errorTitle }}</h5>
                <p class="order-loading-failed">{{ errorMessage }}</p>
                <button class="btn manage-btn" @click="$router.push('/user/orders')">
                    My Orders
                </button>
            </div>

            <div v-else class="main-card">

                <!-- Success -->
                <div class="text-center mb-4">
                    <div class="success-icon">
                        <img
                            :src="successImg"
                            class="complete-order-image"
                            :alt="isPaid ? 'Payment successful' : 'Order placed successfully'"
                        >
                    </div>
                    <!--
                        Was: "Thank you for your payment ... Thank you for
                        choosing Financy.com ... we will ship your order and
                        email you a tracking number." Three things wrong with
                        that on this order: nothing has been paid on a cash or
                        bank order, Financy.com is not this shop, and a
                        collection order is never shipped anywhere.
                    -->
                    <!--
                        The heading follows payment_done, not the existence of
                        an order. Cash on a collection order and bank transfer
                        are both placed long before any money moves, and
                        "Payment successful" over one of those tells the
                        shopper they have paid when they have not - the page
                        contradicted its own "Not Paid" status a few lines
                        further down.
                    -->
                    <h5 class="mt-3 order-placed">
                        <template v-if="isPaid">Payment successful</template>
                        <template v-else>Order placed successfully</template>
                    </h5>
                    <p class="small-text-thanku">
                        Thank you for choosing {{ storeName }}.
                        <template v-if="isCollection">
                            We'll email you as soon as it's ready to collect.
                            <!-- Cash is only offered on collection orders, so
                                 this is the one case where the shopper pays
                                 over the counter. Saying so is the difference
                                 between "Not Paid" reading as a problem and
                                 reading as the arrangement. -->
                            <template v-if="isCash && !isPaid">
                                You'll pay in store when you collect.
                            </template>
                        </template>
                        <template v-else-if="isPaid">
                            We'll email you a tracking number the moment it ships.
                        </template>
                        <template v-else>
                            Once payment reaches us we'll ship your order and email
                            you a tracking number.
                        </template>
                    </p>
                </div>

                <!-- Order Box -->
                <div class="order-box d-flex justify-content-between align-items-center mb-4">
                    <div class="small-text-order">
                        <strong>Order No:</strong> #{{ order?.order }}<br>
                        <strong>Order Date:</strong> {{ order?.created }}<br>
                        <!--
                            The customer's finance department will not pay an
                            invoice that does not quote this, so the reference
                            they typed at checkout belongs beside the order
                            number rather than nowhere at all.
                        -->
                        <template v-if="order?.purchase_order_number">
                            <strong>PO Number:</strong> {{ order.purchase_order_number }}<br>
                        </template>
                    </div>
                    <button
                        v-if="isGuestOrder"
                        class="btn manage-btn"
                        @click="$router.push({ path: '/track-order', query: { order_id: order?.order } })"
                    >
                        Track Order
                    </button>
                    <button
                        v-else
                        class="btn manage-btn"
                        @click="$router.push(`/user/order/${order?.id}`)"
                    >
                        Manage Order
                    </button>
                </div>

                <!--
                    A guest confirmed their email before paying, so the order
                    opened an account on that address and put this order in it.
                    The password is in their inbox and nowhere else, which is
                    the one thing worth saying here.
                -->
                <div
                    v-if="isGuestOrder"
                    class="claim-box mb-4"
                >
                    <div class="claim-copy">
                        <div class="claim-title">Your account is ready</div>
                        <p class="claim-sub mb-0">
                            We've set up an account on
                            <strong>{{ guestEmail || 'your email address' }}</strong>
                            and put this order in it. Your sign-in password is in
                            that inbox &mdash; change it to something of your own
                            once you're in.
                        </p>
                    </div>
                    <button
                        class="btn manage-btn claim-btn"
                        @click="goToSignIn"
                    >
                        Sign in
                    </button>
                </div>

                <!--
                    The account this order was placed by, as it stood at the
                    time. Every field is read off the order, never off the
                    customer's live profile: a company that is renamed, a VAT
                    number that is corrected or a trade approval that is later
                    withdrawn must not rewrite a receipt that has already been
                    sent.
                -->
                <div v-if="isBusinessOrder" class="account-box mb-4">
                    <div class="section-title-billing">Account</div>
                    <ul class="account-facts">
                        <li>
                            <span class="fact-label">Account Type</span>
                            <span class="fact-value">{{ accountTypeLabel }}</span>
                        </li>
                        <li v-if="order?.company_name">
                            <span class="fact-label">Company</span>
                            <span class="fact-value">{{ order.company_name }}</span>
                        </li>
                        <li v-if="order?.vat_number">
                            <span class="fact-label">VAT Number</span>
                            <span class="fact-value">{{ order.vat_number }}</span>
                        </li>
                        <li v-if="order?.cro_number">
                            <span class="fact-label">Company Reg. No.</span>
                            <span class="fact-value">{{ order.cro_number }}</span>
                        </li>
                        <li v-if="order?.purchase_order_number">
                            <span class="fact-label">PO Number</span>
                            <span class="fact-value">{{ order.purchase_order_number }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Address -->
                <div class="row mb-4 mt-5">
                    <div class="col-md-6">

                        <!--
                            A collection order has no address on it - nothing is
                            being posted - so the address block rendered six
                            empty lines under a "Billing Address" heading. The
                            contact details captured at checkout are what the
                            store actually holds for that order, so they are
                            what is shown.
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
                        </template>
                    </div>

                    <div class="col-md-6">
                        <!--
                            The same pickup address was headed "Shipping
                            Address" either way, and always promised delivery in
                            N days - on a collection order that is the store the
                            customer is coming to, and nothing is being
                            delivered at all.
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
                    <!--
                        The order's own total, the same figure the summary
                        below prints. This read `calculated.total_price` while
                        the summary read `calculations.total` - two separately
                        computed numbers on one screen, which is exactly the
                        kind of disagreement this page exists to rule out.
                    -->
                    <div class="d-flex gap-2">
                        <h6>Payment Amount:</h6> <span class="text-success fw-bold"> {{ money(order?.calculations?.total) }}</span>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <h6>Payment Method:</h6>   <span class="payment-method"><i :class="['fa-solid', paymentMethodIcon, 'me-1']"></i>{{ paymentMethodLabel }}</span>
                    </div>
                    </div>

                    <div class="col-md-6 text-end small-text">
                    <div class="d-flex justify-content-end gap-2">
                        <h6 class="mt-1">Payment Status: </h6><span class="status-badge"  :style="{
                            background: paymentBadge.color
                    }">{{ paymentBadge.label }}</span><br>
                    </div>
                        <div class="mt-3">
                        <h6> Payment Transaction ID: </h6><strong class="payment-id"> {{ order?.trans_id || 'N/A' }}</strong><br>
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

                <!--
                    One line per ordered product, in the order and under the
                    labels the checkout summary uses: Unit Price, Original
                    Price, Product Discount, Shipping, Tax, Subtotal.
                    Deliberately the same words in the same sequence, so the
                    customer can lay this beside the basket they approved and
                    read down both.

                    Every figure comes from the order itself, decorated onto
                    the line server-side by OrderSummary::decorate(). Nothing
                    here multiplies, subtracts or looks anything up in the
                    catalogue: the previous version worked the discount out in
                    the browser from actual_price and selling, which is a
                    second opinion about a number the order already holds.
                -->
                <div
                    v-for="(item, index) in order?.ordered_products"
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

                        <!--
                            "(256GB / Black / Very Good)" told the customer
                            three values and not one of the things they are
                            values of. The basket lists them as Storage, Colour
                            and Condition; so does this.
                        -->
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

                        <!-- The units a buy-X-get-Y-free bundle gave away. -->
                        <span v-if="item.bundle_offer > 0" class="product-model fw-normal d-block">
                            Includes {{ item.bundle_offer }} free
                        </span>
                        </div>
                    </div>

                    <div
                      v-for="line in [lineView(item)]"
                      :key="`prices-${item.id || index}`"
                      class="mt-2 product-thanks-prices"
                    >
                      <!--
                        In the order's basis, the same as the summary below: net with the
                        VAT on its own row for a business, the price paid for everyone
                        else. Shipping is the order's, stated once in the summary.
                      -->
                      <div class="d-flex justify-content-end price-box-thanku">
                        <p>
                          Unit Price:
                          <span class="fw-bold">
                            {{ money(line.unit) }}<small v-if="line.exTag" class="fw-normal"> Ex-VAT</small>
                            <s v-if="line.discount > 0" class="unit-was">{{ money(line.unitList) }}</s>
                          </span>
                        </p>
                      </div>

                      <div class="d-flex justify-content-end price-box-thanku">
                        <p>
                          Original Price:
                          <span class="fw-bold">{{ money(line.list) }}</span>
                        </p>
                      </div>

                      <div v-if="line.discount > 0" class="d-flex justify-content-end price-box-thanku">
                        <p>
                          Product Discount:
                          <span class="fw-bold text-success">- {{ money(line.discount) }}</span>
                        </p>
                      </div>

                      <div v-if="line.vat > 0" class="d-flex justify-content-end price-box-thanku">
                        <p>
                          {{ line.vatLabel }}:
                          <span class="fw-bold">+ {{ money(line.vat) }}</span>
                        </p>
                      </div>

                      <div class="d-flex justify-content-end price-box-thanku line-subtotal">
                        <p>
                          Subtotal{{ line.totalTag }}:
                          <span class="fw-bold">{{ money(line.total) }}</span>
                        </p>
                      </div>
                    </div>
                </div>

                <!-- Final Total -->
                <!-- <div class="d-flex justify-content-end price-box-thanku">
                    <p>Subtotal:<span class="fw-bold"> €{{ order?.total_amount }}</span></p><br>
                </div> -->
                <!-- Order Summary -->
                <div class="order-summary-box mt-4">

                    <!--
                      The goods and what they net down to, in the order's basis:

                        Products - Discount = Net Products

                      Ex-VAT for a business, with the VAT added on its own row; gross for
                      everyone else, with the VAT inside it. The discount is the whole
                      saving against the list price, so a buy-X-get-Y-free bundle's value
                      is inside it rather than counted twice.
                    -->
                    <div class="summary-row">
                      <span>Products{{ orderGoods.tag }}</span>
                      <span>{{ money(orderGoods.list) }}</span>
                    </div>

                    <div v-if="Number(orderGoods.discount) > 0" class="summary-row">
                      <span>Discount</span>
                      <span class="text-success">-{{ money(orderGoods.discount) }}</span>
                    </div>

                    <div class="summary-rule"></div>

                    <div class="summary-row">
                      <span>Net Products{{ orderGoods.tag }}</span>
                      <span>{{ money(orderGoods.net) }}</span>
                    </div>

                    <!--
                      Business: added on top, one row per rate. Everyone else: already
                      inside the figure above, and not mentioned at all when there is none.
                    -->
                    <template v-if="orderIsNet">
                      <div v-for="row in orderVatRows" :key="row.label" class="summary-row">
                        <span>VAT ({{ row.label }})</span>
                        <span>+ {{ money(row.vat) }}</span>
                      </div>
                    </template>
                    <div v-else-if="orderHasVat" class="summary-row summary-row-sub">
                      <span>
                        VAT included in products<template v-if="orderVatRows.length === 1"> ({{ orderVatRows[0].label }})</template>
                      </span>
                      <span>{{ money(orderCalc.vat) }}</span>
                    </div>

                    <div v-if="showRow('Flash Discount')" class="summary-row">
                        <span>Flash Discount</span>
                        <span class="text-success">
                            - {{ money(order?.calculations?.flash_discount) }}
                        </span>
                    </div>

                    <div v-if="showRow('Bundle Saving')" class="summary-row">
                        <span>Bundle Saving</span>
                        <span class="text-success">
                            - {{ money(order?.calculations?.bundle_discount) }}
                        </span>
                    </div>

                    <div v-if="showRow('Voucher Discount')" class="summary-row">
                        <span>Voucher Discount</span>
                        <span class="text-success">
                            - {{ money(order?.calculations?.voucher) }}
                        </span>
                    </div>

                    <!--
                      Labelled with the wording frozen on the order when it was
                      placed, so a receipt keeps saying what the customer was
                      told even if the group is renamed later.
                    -->
                    <div v-if="showRow('Loyalty Discount')" class="summary-row">
                        <span>{{ order?.calculations?.loyalty_label || 'Loyalty Discount' }}</span>
                        <span class="text-success">
                            - {{ money(order?.calculations?.loyalty_discount) }}
                        </span>
                    </div>

                    <div v-if="showRow('Points')" class="summary-row">
                        <span>Cherry Points</span>
                        <span class="text-success">
                            - {{ money(order?.calculations?.points_discount) }}
                        </span>
                    </div>

                    <!--
                        Stated even when it is nothing: a missing shipping row
                        and free shipping look the same on a receipt.
                    -->
                    <div class="summary-row">
                        <span>Shipping</span>
                        <span v-if="!(Number(order?.calculations?.shipping) > 0)" class="text-success">FREE</span>
                        <span v-else>{{ money(order?.calculations?.shipping) }}</span>
                    </div>

                    <div class="summary-row total-row">
                        <span>Total{{ orderGrossTag }}</span>
                        <span>
                            {{ money(order?.calculations?.total) }}
                        </span>
                    </div>

                    <!-- A split payment: how the total was paid. -->
                    <template v-if="Number(order?.calculations?.wallet_paid) > 0">
                        <div class="summary-row">
                            <span>Paid from wallet</span>
                            <span class="text-success">- {{ money(order?.calculations?.wallet_paid) }}</span>
                        </div>
                        <div class="summary-row">
                            <span>Paid by your chosen method</span>
                            <span>{{ money(order?.calculations?.amount_due) }}</span>
                        </div>
                    </template>

                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'
import orderVatView from '~/mixin/orderVatView'
import successImg from '~/assets/images/payment_done.png'

 export default {
    data() {
      return {
        // Starts true: mounted() fetches, and the gap before it does is the
        // whole reason this flag exists.
        fetching: true,
        order: {},
        /*
         * null while the order loaded. Otherwise { answered, message }:
         * `answered` marks a reply the API actually gave us - "No order
         * found." / "Not your order." - as opposed to a request that never
         * completed, which is the difference between "this order isn't yours"
         * and "try again in a moment".
         */
        loadError: null,
        successImg
      }
    },
    mixins: [util, orderVatView],
    computed: {
        // The order the shared VAT statement (mixin/orderVatView) reads.
        vatOrder() {
            return this.order
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
        /* Nothing to confirm unless a real order came back. */
        loadFailed() {
            return !!this.loadError || !this.order || !this.order.id
        },
        errorTitle() {
            return this.loadError?.answered === false
                ? 'Order details unavailable'
                : 'Order not found'
        },
        errorMessage() {
            if (this.loadError?.answered === false) {
                return "We couldn't reach the store to load this order. Please try "
                    + 'again in a moment, or open it from My Orders.'
            }

            /* "No order found." / "Not your order.", already translated. */
            return this.loadError?.message
                || "We couldn't find an order with this reference on your account. "
                    + 'Check the link in your confirmation email, or open the order '
                    + 'from My Orders.'
        },
        isGuestOrder() {
            return !this.$auth?.loggedIn
        },
        /* Every line on an order shares one fulfilment type; 2 is collection. */
        isCollection() {
            return parseInt(this.order?.ordered_products?.[0]?.shipping_type) === 2
        },
        isPaid() {
            return parseInt(this.order?.payment_done) === 1
        },
        /* constants.paymentMethod.CASH_ON_DELIVERY */
        isCash() {
            return parseInt(this.order?.order_method) === 2
        },
        storeName() {
            return this.site_setting?.site_name || 'us'
        },
        /*
         * Read off the order, not off the signed-in customer. The account that
         * placed the order is what the receipt is about; the account reading it
         * may since have been downgraded, suspended or renamed.
         */
        accountTypeLabel() {
            return {
                business: 'Business',
                trade: 'Trade',
                personal: 'Personal'
            }[this.order?.account_type] || 'Personal'
        },
        shippingPlace() {
            return this.order?.ordered_products?.[0]?.shipping_place
        },
        /*
         * Who the store contacts about this order. The columns on the order
         * are filled in at checkout for a collection; a delivery carries the
         * address instead, and a signed-in shopper has their account.
         */
        contact() {
            return {
                name: this.order?.contact_name
                    || this.order?.guest_user?.name
                    || this.order?.address?.name
                    || this.order?.user?.name
                    || '',
                email: this.order?.contact_email
                    || this.order?.guest_user?.email
                    || this.order?.address?.email
                    || this.order?.user?.email
                    || '',
                phone: this.order?.contact_phone
                    || this.order?.guest_user?.phone
                    || this.order?.address?.phone
                    || ''
            }
        },
        /* Delivery orders carry the address; collection ones only the guest. */
        guestEmail() {
            return this.contact.email
        },
        progressStyle() {
            // three gaps between four steps
            const progress = (this.orderStatusStep - 1) * (100 / 3)

            return {
                '--progress-width': `${Math.max(0, Math.min(progress, 100))}%`
            }
        },
        /*
         * orders.status is an integer (constants.orderStatus: 1 Pending,
         * 2 Confirmed, 3 Picked up, 4 On the way, 5 Delivered, 6 Cancelled,
         * 7 Returned, 8 Refunded). This was keyed on strings - 'placed',
         * 'processing' - so every lookup missed and every order, at every
         * stage, fell back to step 1. It only looked right because a freshly
         * placed order is step 1 anyway.
         */
        orderStatusStep() {

            const map = {
                1: 1,   // Pending      → Order Placed
                2: 2,   // Confirmed    → Order Processing
                3: 3,   // Picked up    → On Delivery
                4: 3,   // On the way   → On Delivery
                5: 4    // Delivered    → Delivery
            }

            return map[parseInt(this.order?.status)] || 1
        },
        ...mapGetters('order', ['ordered']),
        ...mapGetters('language', ['langCode']),
        ...mapGetters('common', ['setting', 'site_setting', 'currencyIcon', 'currencyPosition'])
    },
    async mounted() {
        await this.fetchOrder()
    },
    methods: {
        goToSignIn() {
            this.$router.push('/login')
        },
        /*
         * Every figure here was written with a hard-coded €, which is wrong
         * for any shop not selling in euro and ignores the currency position
         * and decimal format the admin sets.
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
         * actual_price is the list price and selling is what was charged, both
         * per unit. Clamped at zero so a product whose list price sits below
         * the charged price can never render as a negative "discount".
         */
        /*
         * A summary that lists "Flash Discount - €0.00", "Voucher Discount -
         * €0.00" and "Tax + €0.00" on an order that had none of those makes
         * the reader check three lines that say nothing. Only rows that
         * carry a figure are shown; Subtotal and Total always are.
         */
        showRow(label) {

            const key = {
                'Product Discount': 'discount',
                'Bundle Offer': 'bundle_offer',
                'Flash Discount': 'flash_discount',
                // The Essentials Bundle saving. 'Bundle Offer' above is the
                // other feature: the value of the units a buy-X-get-Y-free
                // deal gave away.
                'Bundle Saving': 'bundle_discount',
                'Voucher Discount': 'voucher',
                'Loyalty Discount': 'loyalty_discount',
                'Points': 'points_discount',
                'Tax': 'tax'
            }[label]

            return Number(this.order?.calculations?.[key] || 0) > 0
        },
        /*
         * The variation the customer bought, as label/value pairs.
         *
         * The attribute carries the question ("Storage") and the value carries
         * the answer ("256GB"). This page joined the answers with a slash and
         * threw the questions away, so a phone read "(256GB / Black / Very
         * Good)" - three facts about the device with nothing saying which was
         * the condition and which the colour.
         */
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
         * The picture of the ordered product.
         *
         * Two conventions live in products.image: a bare file name written by
         * the older uploader, and one already prefixed with "uploads/" written
         * by the newer one. img_src_url ends in /uploads/, so the prefixed form
         * resolved to /uploads/uploads/... and rendered as a broken image.
         * Strip it, and fall back to the store's placeholder when the column is
         * empty.
         */
        productImage(item) {
            const image = (item?.product?.image || '').replace(/^\/?uploads\//, '')

            return image ? this.getImageURL(image) : this.getImageURL()
        },
        /*
         * A file that is no longer on disk. Several of this store's products
         * point at images that were never migrated, and the browser's own
         * broken-image icon beside a €6,740 line reads as a broken order.
         * Same treatment LazyImage already gives every other picture.
         */
        onImageError(event) {
            const fallback = this.getImageURL()

            // Guard against a placeholder that is itself missing, which would
            // otherwise fire this handler forever.
            if (event.target.src !== fallback) {
                event.target.src = fallback
            }
        },
        async fetchOrder() {
            this.fetching = true
            this.loadError = null
            this.order = {}

            /*
             * /user/order/complete/abc parses to NaN, which serialises as null
             * and reads on the API as "no order_id given" - the list branch,
             * answering 200 with every order on the account. Nothing to ask
             * for, so don't ask.
             */
            if (!Number.isInteger(this.orderId) || this.orderId <= 0) {
                this.loadError = { answered: true, message: null }
                this.fetching = false

                return
            }

            try {
                const response = await this.getOrderByUser({
                payload: {
                    order_id: this.orderId,
                    user_token: await this.getUserToken(),
                    time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone
                },
                lang: this.langCode
                })

                /*
                 * 200 is the only status that carries an order. A missing id
                 * comes back 201 "No order found." and another shopper's order
                 * 403 "Not your order."; the store leaves `ordered` empty for
                 * both, so reading it blind handed this page an empty object
                 * and it rendered the confirmation anyway.
                 */
                if (response?.status === 200 && this.ordered?.id) {
                    this.order = this.ordered
                } else {
                    this.loadError = {
                        answered: true,
                        message: response?.message || null
                    }
                }
            } catch (e) {
                this.loadError = { answered: false, message: null }
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

/*
 * The confirmation page drawn before the order arrives: the badge, the
 * headline, then the two panels it is about to fill. The page keeps its
 * height, so the receipt does not drop into view from a collapsed block.
 */
.order-skeleton {
    min-height: 340px;
    padding: 40px 20px;
    text-align: left;
}

.order-skeleton-badge {
    margin: 0 auto 22px;
}

.order-skeleton-gap {
    margin-top: 16px;
}

.order-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    min-height: 340px;
    padding: 40px 20px;
    text-align: center;
    color: #565a75;
    font-size: 15px;
}

.order-loading p {
    margin: 0;
}

.order-loading-failed {
    max-width: 420px;
    line-height: 1.6;
}

.order-error-icon {
    font-size: 44px;
    line-height: 1;
    color: #d64550;
}

.order-error-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: #232159;
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

.claim-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    background: #F0FBF4;
    border: 1px solid #C6EBD4;
    border-radius: 20px;
    padding: 20px;
}

.claim-title {
    font-weight: 600;
    font-size: 16px;
    color: #232159;
    margin-bottom: 4px;
}

.claim-sub {
    font-size: 14px;
    color: #415A80;
    font-weight: 500;
    max-width: 60ch;
}

.claim-btn {
    white-space: nowrap;
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

/*
    The account block. A quiet panel rather than another address column: it is
    reference data a business customer checks once, not something they read.
*/
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

/*
    A company name or a long VAT number must wrap rather than push the panel
    wider than the card, which is what "Business information does not break the
    layout" means on a 390px phone.
*/
.fact-value {
    font-size: 14.5px;
    font-weight: 600;
    color: #232159;
    overflow-wrap: anywhere;
}

/* The media half of a product row: image plus details, never wider than its
   share of the row, so a long title cannot push the prices off screen. */
.product-thanks-media {
    min-width: 0;
    flex: 1 1 auto;
}

.product-thanks-prices {
    flex: 0 0 auto;
    min-width: 230px;
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

/* Separates the goods from what they net down to. */
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

.total-product-price {
    border-top: 1px solid #EEEEF3;
    margin-top: 10px;
    padding-top: 10px;
}

/* ---------------------------------------------------------------------------
   Order confirmation, small screens.

   The page had no media query at all: 50px of card padding, a 9rem gap on the
   timeline wrapper, 60px between four progress steps and a product row built
   as two columns pushed apart. On a 390px phone that overflowed sideways and
   spent most of the first screen on padding. Everything below scales the
   spacing and the type down; the desktop layout is untouched.
   --------------------------------------------------------------------------- */

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

    .complete-order-image {
        width: 96px;
        height: 75px;
    }
    .order-placed {
        font-size: 17px;
        line-height: 1.35;
    }
    .small-text-thanku {
        font-size: 12.5px;
    }

    /* the button sat beside two lines of text and squeezed both */
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
    /* the two address columns stack, so they need air between them */
    .row.mb-4.mt-5 > [class*="col-"] + [class*="col-"] {
        margin-top: 18px;
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
    /* the rails hang off the centre of the first and last circle, which with
       four equal columns sits an eighth of the way in from each edge */
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

    /* the reference panel becomes one column of label-over-value pairs */
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

    /* image, details and prices in one column instead of two pushed apart */
    .product-thanks {
        flex-direction: column;
        gap: 12px;
    }
    .product-thanks-prices {
        min-width: 0;
    }
    .unit-was {
        margin-left: 4px;
        font-size: 12px;
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
    .order-placed {
        font-size: 15.5px;
    }
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

.text-success {
    color: #05B942;
}
</style>