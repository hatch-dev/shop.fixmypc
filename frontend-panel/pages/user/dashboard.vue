<template>
    <account-layout
        class="user-profile-wrapper"
        active-route="dashboard"
        :class="{'email-login': !loggedInWithEmail}"
    >
        <template v-slot:rightArea>

            <!--
              Nothing real is rendered until every request has settled.

              The dashboard used to paint itself immediately with placeholder
              zeroes and then rewrite them one by one as the responses landed,
              so a customer with a wallet balance and points was shown "EUR 0"
              and "0 pts" first. The skeleton mirrors the finished layout, so
              the page does not jump when the data arrives.
            -->
            <account-skeleton
                v-if="loading"
                :cards="3"
                :rows="4"
                :panels="2"
                heading
                label="Loading your dashboard"
            />

            <div v-else class="dash">

                <!--
                  Greeting and the four places a customer goes next. The quick
                  links scroll sideways on a phone rather than wrapping into a
                  block of buttons.
                -->
                <header class="dash-hero">
                    <div class="dash-hero-top">
                        <div class="dash-hero-text">
                            <p class="dash-hello">{{ greeting }},</p>
                            <h1 class="username">{{ firstName }}</h1>
                            <p class="dash-sub">
                                Here's what's happening with your account today.
                            </p>
                        </div>

                        <!-- Real session data, or the line is not shown at all. -->
                        <p v-if="lastLoginLabel" class="last-login">
                            Last login
                            <strong>{{ lastLoginLabel }}</strong>
                        </p>
                    </div>

                    <nav class="dash-quick" aria-label="Quick links">
                        <nuxt-link to="/user/orders" class="dash-chip">
                            <span class="dash-chip-ico" aria-hidden="true"><i class="fa-solid fa-bag-shopping"></i></span>
                            <span class="dash-chip-label"><span class="lbl-long">My orders</span><span class="lbl-short">Orders</span></span>
                        </nuxt-link>
                        <nuxt-link :to="trackLink" class="dash-chip">
                            <span class="dash-chip-ico" aria-hidden="true"><i class="fa-solid fa-truck-fast"></i></span>
                            <span class="dash-chip-label"><span class="lbl-long">Track order</span><span class="lbl-short">Track</span></span>
                        </nuxt-link>
                        <nuxt-link to="/user/wishlists" class="dash-chip">
                            <span class="dash-chip-ico" aria-hidden="true"><i class="fa-solid fa-heart"></i></span>
                            <span class="dash-chip-label"><span class="lbl-long">Wishlist</span><span class="lbl-short">Wishlist</span></span>
                        </nuxt-link>
                        <nuxt-link to="/user/addresses" class="dash-chip">
                            <span class="dash-chip-ico" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                            <span class="dash-chip-label"><span class="lbl-long">Addresses</span><span class="lbl-short">Address</span></span>
                        </nuxt-link>
                    </nav>
                </header>

                <!--
                  Account type and where it stands.

                  The dashboard said nothing at all about this, so a trade
                  customer who had applied and was waiting had no way to tell
                  whether the application existed, and a suspended account
                  found out at the checkout button. Everything here is decided
                  by the server (User::accountSummary) — the page never infers
                  entitlement from the account type on its own.
                -->
                <section
                    v-if="showAccountPanel"
                    class="acct-panel"
                    :class="`acct-${accountTone}`"
                >
                    <div class="acct-head">
                        <span class="acct-type">{{ accountTypeLabel }} account</span>
                        <span class="acct-tag" :class="`acct-tag-${accountTone}`">
                            {{ accountStateLabel }}
                        </span>
                    </div>

                    <p v-if="account?.headline" class="acct-lede">
                        {{ account.headline }}
                    </p>

                    <p
                        v-if="account?.trade_review_note"
                        class="acct-note"
                    >
                        <strong>Note from us:</strong> {{ account.trade_review_note }}
                    </p>

                    <p
                        v-else-if="account?.status_note"
                        class="acct-note"
                    >
                        <strong>Note from us:</strong> {{ account.status_note }}
                    </p>

                    <dl v-if="businessProfile" class="acct-facts">
                        <div v-if="businessProfile.company_name">
                            <dt>Company</dt>
                            <dd>{{ businessProfile.company_name }}</dd>
                        </div>
                        <div v-if="businessProfile.vat_number">
                            <dt>VAT number</dt>
                            <dd>{{ businessProfile.vat_number }}</dd>
                        </div>
                        <div v-if="businessProfile.cro_number">
                            <dt>Company reg. number</dt>
                            <dd>{{ businessProfile.cro_number }}</dd>
                        </div>
                        <div v-if="businessProfile.sector || businessProfile.trade">
                            <dt>{{ account?.account_type === 'trade' ? 'Trade' : 'Sector' }}</dt>
                            <dd>{{ businessProfile.trade || businessProfile.sector }}</dd>
                        </div>
                        <div v-if="businessProfile.billing_address" class="acct-wide">
                            <dt>Business address</dt>
                            <dd>{{ businessProfile.billing_address }}</dd>
                        </div>
                    </dl>

                    <!--
                      Only claimed when the server says the entitlement is
                      live, never merely because the account says "business".
                    -->
                    <p v-if="account?.has_business_pricing" class="acct-entitled">
                        Your account pricing is active. Quantity break prices are shown
                        on each product and applied automatically in your basket.
                    </p>

                    <nuxt-link
                        v-if="canUpgrade && account.account_type === 'business'"
                        to="/user/profile#upgrade"
                        class="acct-upgrade-link"
                    >
                        Trade account: apply or check your application
                        <i class="fa-solid fa-arrow-right" aria-hidden="true" />
                    </nuxt-link>
                </section>

                <!--
                  A personal account has no panel above, so the way up is offered
                  on its own line. The upgrade itself lives on the profile page.
                -->
                <nuxt-link
                    v-else-if="canUpgrade && account.account_type === 'personal'"
                    to="/user/profile#upgrade"
                    class="acct-upgrade-prompt"
                >
                    <span>
                        <strong>Buying for a business or a trade?</strong>
                        Upgrade your account for business pricing, VAT invoices and more.
                    </span>
                    <i class="fa-solid fa-arrow-right" aria-hidden="true" />
                </nuxt-link>

                <!-- Summary cards -->
                <div class="stat-grid">

                    <!-- Recent order -->
                    <div class="stat-card stat-card-order">
                        <div class="stat-body">
                            <p class="stat-label">Recent Order</p>
                            <h2
                                class="stat-value stat-value-order"
                                :title="latestOrder ? `#${latestOrder.order}` : null"
                            >
                                <template v-if="latestOrder">#{{ latestOrder.order }}</template>
                                <template v-else>No orders yet</template>
                            </h2>

                            <span
                                v-if="latestOrder"
                                class="status"
                                :class="orderStatusClass(latestOrder)"
                            >
                                {{ orderStatusLabel(latestOrder) }}
                            </span>

                            <div class="stat-actions">
                                <nuxt-link
                                    v-if="latestOrder"
                                    :to="`/user/order/${latestOrder.id}`"
                                    class="link-action"
                                >
                                    View order
                                </nuxt-link>
                                <nuxt-link v-else to="/" class="link-action">
                                    Start shopping
                                </nuxt-link>
                            </div>
                        </div>

                        <img src="@/assets/images/Order.png" alt="" class="stat-icon" aria-hidden="true" />
                    </div>

                    <!-- Wallet -->
                    <div class="stat-card stat-card-wallet">
                        <div class="stat-body">
                            <p class="stat-label">Wallet Balance</p>
                            <h2 class="stat-value">&euro;{{ Number(wallet?.balance || 0).toFixed(2) }}</h2>
                            <span class="stat-meta">
                                + &euro;{{ Number(wallet?.pending || 0).toFixed(2) }} pending
                            </span>

                            <div class="stat-actions">
                                <button type="button" class="btn-top-up" @click="openTopup">
                                    Top Up
                                </button>
                                <button type="button" class="btn-history" @click="openHistory">
                                    History
                                </button>
                            </div>
                        </div>

                        <img src="@/assets/images/Balance.png" alt="" class="stat-icon" aria-hidden="true" />
                    </div>

                    <!-- Points -->
                    <div class="stat-card stat-card-points">
                        <div class="stat-body">
                            <p class="stat-label">Cherry Points</p>
                            <h2 class="stat-value">{{ points?.points || 0 }} pts</h2>
                            <span class="stat-meta">
                                Value: &euro;{{ Number(points?.value || 0).toFixed(2) }}
                            </span>

                            <div class="stat-actions">
                                <nuxt-link to="/user/orders" class="link-action">
                                    Earned on delivery
                                </nuxt-link>
                            </div>
                        </div>

                        <img src="@/assets/images/Points.png" alt="" class="stat-icon" aria-hidden="true" />
                    </div>
                </div>

                <!-- Recent orders -->
                <section class="panel mt-4">
                    <div class="panel-head">
                        <h2 class="panel-title">Recent Orders</h2>
                        <nuxt-link to="/user/orders" class="link-action">View All Orders</nuxt-link>
                    </div>

                    <!--
                      Scrolls sideways on a tablet and collapses to stacked rows
                      on a phone, where five columns cannot fit. The data-label
                      attributes become the row headings at that size.
                    -->
                    <div v-if="recentOrders.length" class="table-scroll">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th scope="col">Order ID</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Total</th>
                                    <th scope="col" class="ta-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="order in recentOrders" :key="order.id">
                                    <td data-label="Order ID" class="order-ref">#{{ order.order }}</td>
                                    <td data-label="Date">{{ formatDate(order.created) }}</td>
                                    <td data-label="Status">
                                        <span class="status" :class="orderStatusClass(order)">
                                            {{ orderStatusLabel(order) }}
                                        </span>
                                    </td>
                                    <td data-label="Total">&euro;{{ Number(order.total_amount || 0).toFixed(2) }}</td>
                                    <td data-label="Action" class="ta-right">
                                        <nuxt-link
                                            :to="`/user/order/${order.id}`"
                                            class="icon-link"
                                            :aria-label="`View order ${order.order}`"
                                        >
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                            <span class="icon-link-text">View</span>
                                        </nuxt-link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!--
                      Phones get a list instead of the table: each order is one
                      tappable card, the whole of it the link, rather than a
                      five-column row squeezed into two lines with a small
                      "View" to aim at.
                    -->
                    <ul v-if="recentOrders.length" class="order-list">
                        <li v-for="order in recentOrders" :key="`m-${order.id}`">
                            <nuxt-link
                                :to="`/user/order/${order.id}`"
                                class="order-item"
                                :aria-label="`Order ${order.order}, ${orderStatusLabel(order)}, ${formatDate(order.created)}, total €${Number(order.total_amount || 0).toFixed(2)}`"
                            >
                                <span class="order-item-ico" :class="`order-ico-${orderStatusClass(order)}`" aria-hidden="true">
                                    <i class="fa-solid fa-box"></i>
                                </span>

                                <span class="order-item-main">
                                    <!-- the number gets the whole line: customers quote it -->
                                    <span class="order-item-ref">#{{ order.order }}</span>
                                    <span class="order-item-meta">
                                        <span class="status" :class="orderStatusClass(order)">
                                            {{ orderStatusLabel(order) }}
                                        </span>
                                        <span>{{ formatDate(order.created) }}</span>
                                    </span>
                                    <strong class="order-item-total">&euro;{{ Number(order.total_amount || 0).toFixed(2) }}</strong>
                                </span>

                                <i class="fa-solid fa-chevron-right order-item-go" aria-hidden="true"></i>
                            </nuxt-link>
                        </li>
                    </ul>

                    <!-- A table of headings with no rows under it explains nothing. -->
                    <div v-else class="empty-state">
                        <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                        <p>You have not placed an order yet.</p>
                        <nuxt-link to="/" class="btn-top-up">Browse products</nuxt-link>
                    </div>
                </section>

                <div class="split-grid mt-4 mb-5">

                    <!-- Default shipping address -->
                    <section class="panel">
                        <div class="panel-head">
                            <h2 class="panel-title">Default Shipping Address</h2>
                            <nuxt-link to="/user/addresses" class="link-action">
                                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                Edit
                            </nuxt-link>
                        </div>

                        <div v-if="defaultAddress" class="media-row">
                            <div class="icon-circle" aria-hidden="true">
                                <i class="fa-solid fa-location-dot"></i>
                            </div>
                            <div class="media-body">
                                <strong>{{ defaultAddress.name }}</strong>
                                <p class="muted-text" v-html="formatAddress(defaultAddress)" />
                                <p class="muted-text mb-0">Tel: {{ defaultAddress.phone }}</p>
                            </div>
                        </div>

                        <div v-else class="empty-state empty-state-sm">
                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                            <p>No delivery address saved yet.</p>
                            <nuxt-link to="/user/addresses" class="btn-top-up">Add an address</nuxt-link>
                        </div>
                    </section>

                    <!-- Account security -->
                    <section class="panel">
                        <h2 class="panel-title panel-title-solo">Account Security</h2>

                        <div class="security-item">
                            <div class="media-row">
                                <div class="icon-circle" aria-hidden="true">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <div class="media-body">
                                    <strong>Password</strong>
                                    <!--
                                      "Last changed 3 months ago" used to sit here
                                      for every customer. Nothing in the schema
                                      records when a password was changed, so there
                                      is no honest value to show.
                                    -->
                                    <span class="muted-text">Change your sign-in password</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="shipping-update"
                                @click="$router.push({ path: '/user/profile', hash: '#password' })"
                            >
                                Update
                            </button>
                        </div>

                        <div class="security-item">
                            <div class="media-row">
                                <div class="icon-circle" aria-hidden="true">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <div class="media-body">
                                    <strong>Email Preference</strong>
                                    <span class="muted-text">{{ newsletterLabel }}</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="shipping-update"
                                @click="$router.push({ path: '/user/profile', hash: '#preferences' })"
                            >
                                Manage
                            </button>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Top Up Modal -->
            <div
                v-if="showTopupModal"
                class="topup-modal-overlay"
                role="dialog"
                aria-modal="true"
                aria-label="Top up wallet"
                @click.self="closeTopup"
            >
                <div class="topup-modal">
                    <div class="panel-head">
                        <h2 class="panel-title">Top Up Wallet</h2>
                        <button type="button" class="modal-close" aria-label="Close" @click="closeTopup">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="preset-amounts">
                        <button
                            v-for="amt in presetAmounts"
                            :key="amt"
                            type="button"
                            :class="['preset-btn', selectedAmount == amt ? 'active' : '']"
                            @click="selectAmount(amt)"
                        >
                            &euro;{{ amt }}
                        </button>
                        <button
                            type="button"
                            :class="['preset-btn', isCustom ? 'active' : '']"
                            @click="selectCustom"
                        >
                            Custom
                        </button>
                    </div>

                    <div v-if="isCustom" class="custom-amount">
                        <label class="input-label" for="topup-amount">Enter Amount</label>
                        <input
                            id="topup-amount"
                            ref="topupAmount"
                            type="number"
                            step="0.01"
                            min="0"
                            v-model="customAmount"
                            class="form-control custom-input"
                            placeholder="e.g. 75.50"
                        />
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-history" @click="closeTopup">
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="btn-top-up"
                            :disabled="!finalAmount || processingPayment"
                            @click="submitTopup"
                        >
                            <span v-if="processingPayment">Processing</span>
                            <span v-else>Pay &euro;{{ Number(finalAmount || 0).toFixed(2) }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Wallet History Modal -->
            <div
                v-if="showHistoryModal"
                class="topup-modal-overlay"
                role="dialog"
                aria-modal="true"
                aria-label="Wallet history"
                @click.self="showHistoryModal = false"
            >
                <div class="topup-modal topup-modal-wide">
                    <div class="panel-head">
                        <h2 class="panel-title">Wallet History</h2>
                        <button
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="showHistoryModal = false"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div v-if="walletHistory.length" class="table-scroll">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in walletHistory" :key="item.id">
                                    <td data-label="Date">{{ formatDate(item.created_at) }}</td>
                                    <td data-label="Type">
                                        <span :class="item.type === 'credit' ? 'text-success' : 'text-danger'">
                                            {{ item.type === 'credit' ? 'Credit' : 'Debit' }}
                                        </span>
                                    </td>
                                    <td data-label="Amount">
                                        <span :class="item.type === 'credit' ? 'text-success' : 'text-danger'">
                                            {{ item.type === 'credit' ? '+' : '-' }}&euro;{{ Number(item.amount || 0).toFixed(2) }}
                                        </span>
                                    </td>
                                    <td data-label="Status">
                                        <span class="status" :class="`wallet-${item.status}`">
                                            {{ item.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="empty-state empty-state-sm">
                        <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                        <p>No transactions yet.</p>
                    </div>

                    <div v-if="walletHistory.length" class="pager">
                        <button
                            type="button"
                            class="btn-history"
                            :disabled="!pagination.prev_page_url"
                            @click="fetchWalletHistory(pagination.current_page - 1)"
                        >
                            Prev
                        </button>

                        <span class="pager-info">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                        </span>

                        <button
                            type="button"
                            class="btn-history"
                            :disabled="!pagination.next_page_url"
                            @click="fetchWalletHistory(pagination.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </template>

    </account-layout>
</template>
<script>
import {mapGetters, mapActions} from 'vuex'
import addressHelper from '~/mixin/addressHelper'
import util from '~/mixin/util'
import AccountSkeleton from '~/components/AccountSkeleton'
export default {
    head() {
      return {
        title: 'Dashboard',
        meta: []
      }
    },
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        walletHistory: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            next_page_url: null,
            prev_page_url: null
        },
        showHistoryModal: false,
        showTopupModal: false,
        presetAmounts: [25, 50, 100, 500],
        selectedAmount: null,
        customAmount: null,
        isCustom: false,
        processingPayment: false,
        orders: [],
        wallet: null,
        points: null,
        address: null,
        lastLoginAt: null,
        /*
         * Starts true. The page used to render immediately with placeholder
         * zeroes -- "€0", "0 pts", "No address available" -- and then rewrite
         * them as each request landed, so a customer with money in their wallet
         * was told they had none for the first second.
         */
        loading: true
      }
    },
    computed: {
      ...mapGetters('address', ['addresses']),
      ...mapGetters('order', ['orderedList']),
      ...mapGetters('user', ['profile']),

      /*
       * The server's view of this account: type, status, verification, trade
       * standing and whether business pricing is actually live. Never derived
       * from account_type on the client — the whole point of the object is
       * that entitlement is the backend's answer, not the browser's guess.
       */
      account() {
        return this.profile?.account || null
      },

      businessProfile() {
        return this.profile?.business_profile || null
      },

      // Personal accounts in good standing have nothing to report here.
      showAccountPanel() {
        if (!this.account) return false

        return this.account.account_type !== 'personal'
          || this.account.status !== 'active'
      },

      // Personal and business accounts in good standing may move up a type.
      canUpgrade() {
        return !!this.account
          && this.account.status === 'active'
          && ['personal', 'business'].includes(this.account.account_type)
      },

      accountTypeLabel() {
        const labels = {personal: 'Personal', business: 'Business', trade: 'Trade'}
        return labels[this.account?.account_type] || 'Personal'
      },

      /*
       * What to call the state, in the customer's terms. Account status
       * outranks trade status: an account that cannot be used at all is not
       * usefully described as "trade approved".
       */
      accountStateLabel() {
        const status = this.account?.status

        if (status === 'suspended') return 'Suspended'
        if (status === 'rejected') return 'Not accepted'
        if (status === 'pending') return 'Awaiting review'

        if (this.account?.account_type === 'trade') {
          const trade = {
            approved: 'Trade pricing active',
            rejected: 'Application not approved',
            suspended: 'Trade pricing suspended',
            pending: 'Application under review'
          }
          return trade[this.account?.trade_status] || 'Application under review'
        }

        if (this.account?.account_type === 'business') {
          return this.account?.has_business_pricing ? 'Business pricing active' : 'Active'
        }

        return 'Active'
      },

      // Drives the panel colour only.
      accountTone() {
        const status = this.account?.status

        if (status === 'suspended' || status === 'rejected') return 'bad'
        if (status === 'pending') return 'wait'

        if (this.account?.account_type === 'trade') {
          if (this.account?.trade_status === 'approved') return 'good'
          if (this.account?.trade_status === 'rejected'
            || this.account?.trade_status === 'suspended') return 'bad'
          return 'wait'
        }

        return this.account?.has_business_pricing ? 'good' : 'neutral'
      },
      ...mapGetters('resource', ['countryList', 'phoneList']),
      finalAmount() {
        if (this.isCustom) {
            return parseFloat(this.customAmount || 0)
        }
        return this.selectedAmount
      },
      defaultAddress() {
        if (!this.addresses || !this.addresses.length) return null
        const defaultAddr = this.addresses.find(a => parseInt(a.default) === 1)
        return defaultAddr || this.addresses[0]
      },
      firstName() {
        const name = (this.profile?.name || '').trim()
        return name ? name.split(/\s+/)[0] : 'there'
      },
      // By the customer's own clock, not the server's.
      greeting() {
        const hour = new Date().getHours()
        if (hour < 12) return 'Good morning'
        if (hour < 18) return 'Good afternoon'
        return 'Good evening'
      },
      trackLink() {
        return this.latestOrder
          ? { path: '/track-order', query: { order_id: this.latestOrder.order } }
          : '/track-order'
      },
      recentOrders() {
        return this.orderedList?.data?.slice(0, 3) || []
      },
      latestOrder() {
        return this.orderedList?.data?.[0] || null
      },
      loggedInWithEmail() {
        return this.profile && !this.profile?.facebook_id && !this.profile?.google_id
      },
      /*
       * Real session data, or nothing. Never a placeholder time.
       */
      lastLoginLabel() {
        if (!this.lastLoginAt) {
          return null
        }

        const when = new Date(this.lastLoginAt)

        if (isNaN(when.getTime())) {
          return null
        }

        const time = when.toLocaleTimeString('en-GB', {
          hour: '2-digit',
          minute: '2-digit'
        })

        const isToday = when.toDateString() === new Date().toDateString()

        return isToday
          ? `Today, ${time}`
          : `${when.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}, ${time}`
      },
      /*
       * Reads the customer's actual preference column. The panel used to state
       * "Subscribed to newsletter" to everyone, including people who had opted
       * out.
       *
       * The API sends a boolean, and parseInt(true) is NaN, so a strict
       * parseInt(...) === 1 told every subscribed customer they were not.
       * Accepts true, 1 and '1'.
       */
      newsletterLabel() {
        const value = this.profile?.pref_email_offers

        return value === true || parseInt(value) === 1
          ? 'Subscribed to offers and news'
          : 'Not subscribed to offers'
      },
    },
    components: {
      AccountSkeleton
    },
    methods:{
        ...mapActions('order', ['getOrderByUser']),
        ...mapActions('user', ['getUserToken']),
        ...mapActions('address', ['getAddressByUser']),
        ...mapActions('common', ['getRequest', 'setToastError']),
        ...mapActions('resource', ['setCountryList', 'setPhoneList']),
        openHistory() {
            this.showHistoryModal = true
            this.fetchWalletHistory(1)
        },
        async fetchPoints() {
            try {
                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
                const { data } = await this.$axios.get(
                    `${baseUrl}api/v1/user/points`,
                    {
                        headers: {
                            Authorization: `Bearer ${token}`
                        }
                    }
                )
                this.points = data
            } catch (e) {
                console.error('Points error:', e)
            }
        },
        async fetchWalletHistory(page = 1) {
            try {
                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

                const { data } = await this.$axios.get(
                    `${baseUrl}api/v1/user/wallet/transactions?page=${page}`,
                    {
                        headers: {
                            Authorization: `Bearer ${token}`
                        }
                    }
                )

                this.walletHistory = data.data
                this.pagination = {
                    current_page: data.current_page,
                    last_page: data.last_page,
                    next_page_url: data.next_page_url,
                    prev_page_url: data.prev_page_url
                }

            } catch (e) {
                console.error('History error:', e)
            }
        },
        async fetchWallet() {
            try {
                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

                const { data } = await this.$axios.get(
                    `${baseUrl}api/v1/user/wallet`,
                    {
                        headers: {
                            Authorization: `Bearer ${token}`
                        }
                    }
                )

                this.wallet = data

            } catch (e) {
                console.error('Wallet fetch error:', e)
            }
        },
        async submitTopup() {
            try {
                if (!this.finalAmount || this.finalAmount <= 0) {
                    this.setToastError('Enter an amount above zero')
                    return
                }

                this.processingPayment = true

                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

                const { data } = await this.$axios.post(
                    `${baseUrl}api/create-sumup-checkout`,
                    {
                        amount: this.finalAmount,
                        currency: 'EUR',
                        is_topup: true
                    },
                    {
                        headers: {
                        Authorization: `Bearer ${token}`
                        }
                    }
                )

                // SumUp's refusal comes back as a 200 with no id; it used to do nothing.
                if (!data?.id) {
                    this.setToastError('We could not start the payment. Please try again.')
                    return
                }

                this.$router.push({
                    path: '/user/topup-checkout',
                    query: {
                        checkoutId: data.id,
                        checkoutReference: data.checkout_reference,
                        amount: Number(this.finalAmount).toFixed(2)
                    }
                })

            } catch (e) {
                console.error(e)
                this.setToastError('We could not start the payment. Please try again.')
            } finally {
                this.processingPayment = false
            }
        },
        openTopup() {
            this.showTopupModal = true
        },
        closeTopup() {
            this.showTopupModal = false
            this.selectedAmount = null
            this.customAmount = null
            this.isCustom = false
        },
        selectAmount(amount) {
            this.selectedAmount = amount
            this.customAmount = null
            this.isCustom = false
        },
        selectCustom() {
            this.selectedAmount = null
            this.isCustom = true
            this.$nextTick(() => {
                this.$refs.topupAmount?.focus()
            })
        },
        /*
         * Waits for the auth user to appear, but gives up after 5s.
         *
         * This used to poll every 100ms forever. If the user object never
         * arrived -- an expired token, a failed /me call -- the dashboard sat
         * on a blank screen for as long as the tab stayed open, with no error
         * and no way out.
         */
        async waitForAuth(timeoutMs = 5000) {
            const startedAt = Date.now()

            return new Promise((resolve) => {
                const check = () => {
                    if (this.$auth?.user?.id || Date.now() - startedAt > timeoutMs) {
                        resolve()
                        return
                    }
                    setTimeout(check, 100)
                }
                check()
            })
        },

        /**
         * The previous sign-in, for the "Last login" line in the header.
         *
         * That line was hardcoded to "Today, 10:23 AM" for every customer on
         * every visit. user_sessions already records this properly, so it now
         * reads the most recent session other than the one being used right
         * now; when there is no earlier session the line is hidden rather than
         * invented.
         */
        async fetchLastLogin() {
            try {
                const token = this.$auth?.strategy?.token?.get()
                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

                /*
                 * /api/v1/sessions, not /user/sessions: the old path was a 404,
                 * so "Last login" never appeared. Session-Id is what lets the
                 * server mark this tab's own session as current; without it the
                 * current visit is reported back as the "last" login.
                 */
                const sessionId = localStorage.getItem('session_id')

                if (!sessionId) {
                    this.lastLoginAt = null
                    return
                }

                const { data } = await this.$axios.get(`${baseUrl}api/v1/sessions`, {
                    headers: { Authorization: `Bearer ${token}`, 'Session-Id': sessionId }
                })

                const previous = (data?.data || [])
                    .filter(s => !s.is_current)
                    .sort((a, b) => new Date(b.last_activity) - new Date(a.last_activity))[0]

                this.lastLoginAt = previous?.last_activity || null

            } catch (e) {
                this.lastLoginAt = null
            }
        },
        async fetchAddresses() {
            try {
                // await this.getAddressByUser()
                const id = this.$auth?.user?.id
                const token = this.$auth?.strategy?.token?.get()
                if (!id || !token) {
                    console.warn('Missing id or token')
                    return
                }

                const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
                const { data } = await this.$axios.get(`${baseUrl}api/v1/user/address/all`, {
                    headers: {
                        Authorization: `Bearer ${token}`,
                        Accept: 'application/json'
                    }
                })

                const list = data?.data?.data || []

                this.$store.commit('address/SET_ADDRESSES', list)
            } catch (e) {
                console.error(e)
            }
        },
        /*
         * Colour for an order status badge.
         *
         * The old map was {0:processing, 1:delivered, 2:cancelled}, which does
         * not match the statuses the API sends: 1 is PENDING, not delivered,
         * and 2 is CONFIRMED, not cancelled. Every pending order on this
         * dashboard was therefore painted green as though it had arrived, and
         * a confirmed one red as though it had been cancelled.
         *
         * 1 pending, 2 confirmed, 3 picked up, 4 on the way,
         * 5 delivered, 6 cancelled, 7 returned, 8 refunded.
         */
        orderStatusClass(order) {
            if (parseInt(order?.cancelled) === 1) {
                return 'cancelled'
            }

            const code = parseInt(order?.status)

            if (code === 5) {
                return 'delivered'
            }

            if ([6, 7, 8].includes(code)) {
                return 'cancelled'
            }

            return 'processing'
        },
        /*
         * The badge text. util's orderStatus only knows 1-5, so a cancelled,
         * returned or refunded order showed an empty badge in the table and
         * "Pending" on the Recent Order card. A cancelled order also keeps
         * its old status code and carries the cancelled flag instead, which
         * this page ignored; the orders page already checks the flag.
         */
        orderStatusLabel(order) {
            if (parseInt(order?.cancelled) === 1) {
                return 'Cancelled'
            }

            const code = parseInt(order?.status)
            const closed = {6: 'Cancelled', 7: 'Returned', 8: 'Refunded'}

            return closed[code] || this.orderStatus[code]?.title || 'Pending'
        },
        async fetchOrders() {
            try {
            const params = {
                page: 1,
                user_token: await this.getUserToken()
            }

            await this.getOrderByUser({
                payload: params,
                lang: this.$store.getters['language/langCode']
            })

            } catch (e) {
            console.error(e)
            }
        },
        formatDate(date) {
            return new Date(date).toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric'
            })
        }
    },
    mixins: [util, addressHelper],
    watch: {
      profile(value) {
        if (this.profile) {
          this.email = value?.email
          this.name = value?.name
        }
      },
      '$route.query.checkoutId'(val) {
            if (val) {
                this.fetchWallet()
                this.fetchPoints()
            }
        },
      addresses(val) {
        }
    },
    async mounted() {
        try {
            if (!this.countryList || !this.phoneList) {
                const { data } = await this.getRequest({
                    params: null,
                    lang: this.$store.getters['language/langCode'],
                    api: 'countriesPhones'
                })

                this.$store.dispatch('resource/setCountryList', data?.countries)
                this.$store.dispatch('resource/setPhoneList', data?.phones)
            }

            if (this.profile) {
                this.email = this.profile?.email
                this.name = this.profile?.name
            }

            await this.waitForAuth()

            /*
             * All five in parallel, not one after another. They do not depend
             * on each other, and awaiting them in sequence made the customer
             * wait for the sum of five round trips rather than the slowest one.
             *
             * allSettled, not all: a failing wallet or points call should cost
             * its own card, not the whole dashboard.
             */
            await Promise.allSettled([
                this.fetchWallet(),
                this.fetchPoints(),
                this.fetchOrders(),
                this.fetchAddresses(),
                this.fetchLastLogin()
            ])

        } catch (e) {
            console.error('Dashboard load failed:', e)
        } finally {
            // In finally so a thrown request can never strand the loader.
            this.loading = false
        }
    },
}
</script>
<style scoped>
.acct-upgrade-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  font-size: 14px;
  font-weight: 600;
  color: #33319A;
  text-decoration: none;
}

.acct-upgrade-link:hover {
  text-decoration: underline;
}

.acct-upgrade-prompt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin: 0 0 20px;
  padding: 14px 18px;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  background: #F5F5FF;
  color: #374151;
  font-size: 14px;
  line-height: 1.5;
  text-decoration: none;
}

.acct-upgrade-prompt strong {
  color: #130E2B;
}

.acct-upgrade-prompt i {
  flex: none;
  color: #33319A;
}

.acct-upgrade-prompt:hover {
  border-color: #33319A;
}

/*
 * Dashboard.
 *
 * One scale for the whole page, fluid between a 360px phone and a wide
 * desktop: type and spacing use clamp(), so there is no jump at a breakpoint.
 * Breakpoints are only for layout changes:
 *
 *   >= 1200  three summary cards in a row, full table
 *   768-1199 three cards, smaller icons
 *   < 768    order card full width, wallet + points side by side,
 *            orders as two-line rows, security actions inline
 *   < 400    everything single column
 *
 * Buttons set their own height and line-height, and hover backgrounds are
 * !important: base.styl gives every button 50px / 48px and a global hover
 * that outranks any scoped rule (see memory: base-styl-hover).
 */

/* on the page wrapper and the modal overlay, the two roots this file owns */
.dash,
.topup-modal-overlay {
    --ink: #130E2B;
    --body: #374151;
    --muted: #6B7280;
    --line: #E3E3EF;
    --line-soft: #F0F0F6;
    --brand: #33319A;
    --brand-2: #5653D1;
    --brand-soft: #EEEEFA;
    --go: #05B942;
    --go-dark: #049636;
    --radius: 16px;
    --pad: clamp(16px, 1.1rem + .6vw, 24px);
    --gap: clamp(12px, .6rem + .8vw, 20px);
}

.mt-4 { margin-top: var(--gap); }
.mb-5 { margin-bottom: 40px; }
.mb-0 { margin-bottom: 0; }
.ta-right { text-align: right; }

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* ---------- greeting hero ---------- */

.dash-hero {
    position: relative;
    overflow: hidden;
    border-radius: var(--radius);
    padding: var(--pad);
    margin-bottom: var(--gap);
    color: #fff;
    background:
        radial-gradient(120% 140% at 100% 0%, rgba(255, 255, 255, .16) 0, rgba(255, 255, 255, 0) 55%),
        linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
    box-shadow: 0 10px 30px rgba(51, 49, 154, .18);
}

.dash-hero-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px 24px;
    flex-wrap: wrap;
}

.dash-hero-text { min-width: 0; }

.dash-hello {
    margin: 0;
    font-size: clamp(13px, .8rem + .2vw, 15px);
    opacity: .85;
}

.username {
    color: #fff;
    font-size: clamp(22px, 1.1rem + 1.4vw, 32px);
    font-weight: 700;
    line-height: 1.2;
    margin: 2px 0 6px;
    overflow-wrap: anywhere;
}

.dash-sub {
    margin: 0;
    font-size: clamp(13px, .8rem + .15vw, 14.5px);
    opacity: .85;
}

.last-login {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.5;
    text-align: right;
    opacity: .9;
}

.last-login strong {
    display: block;
    font-weight: 600;
    font-size: 13.5px;
}

.dash-quick {
    display: flex;
    gap: 8px;
    margin-top: clamp(14px, .8rem + .5vw, 20px);
    flex-wrap: wrap;
}

.dash-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 38px;
    padding: 0 14px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .14);
    border: 1px solid rgba(255, 255, 255, .22);
    color: #fff;
    font-size: 13.5px;
    font-weight: 500;
    text-decoration: none;
    white-space: nowrap;
    transition: background-color .15s ease, transform .15s ease;
}

.dash-chip:hover,
.dash-chip:focus-visible {
    background: rgba(255, 255, 255, .26);
    color: #fff;
    text-decoration: none;
}

.dash-chip-ico { display: inline-flex; }
.lbl-short { display: none; }
.dash-chip i { font-size: 13px; opacity: .9; }

/* a global p colour outranks these on specificity, hence !important */
.dash-hero .dash-hello,
.dash-hero .dash-sub,
.dash-hero .last-login {
    color: rgba(255, 255, 255, .88) !important;
}

.dash-hero .last-login strong { color: #fff; }

/* ---------- account type panel ---------- */

.acct-panel {
    border: 1px solid var(--line);
    border-left: 4px solid #9CA3AF;
    border-radius: var(--radius);
    background: #fff;
    padding: var(--pad);
    margin-bottom: var(--gap);
}

.acct-good { border-left-color: #059669; }
.acct-wait { border-left-color: #D97706; }
.acct-bad  { border-left-color: #DC2626; }

.acct-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}

.acct-type {
    color: var(--ink);
    font-size: clamp(14.5px, .85rem + .2vw, 16px);
    font-weight: 600;
}

.acct-tag {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    background: #F3F4F6;
    color: var(--body);
    white-space: nowrap;
}

.acct-tag-good { background: #ECFDF5; color: #047857; }
.acct-tag-wait { background: #FFFBEB; color: #B45309; }
.acct-tag-bad  { background: #FEF2F2; color: #B91C1C; }

.acct-lede {
    color: var(--muted);
    font-size: 14px;
    line-height: 1.6;
    margin: 0;
}

.acct-note {
    color: var(--body);
    font-size: 14px;
    line-height: 1.6;
    margin: 12px 0 0;
    padding: 10px 12px;
    background: #F9FAFB;
    border-radius: 8px;
}

.acct-entitled {
    color: #047857;
    font-size: 13px;
    line-height: 1.6;
    margin: 12px 0 0;
}

.acct-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px 20px;
    margin: 16px 0 0;
}

.acct-facts > div { min-width: 0; }
.acct-wide { grid-column: 1 / -1; }

.acct-facts dt {
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
    margin-bottom: 2px;
}

.acct-facts dd {
    color: var(--ink);
    font-size: 14px;
    margin: 0;
    word-break: break-word;
}

/* ---------- summary cards ---------- */

.stat-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--gap);
}

.stat-card {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    min-width: 0;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--pad);
    transition: box-shadow .2s ease, border-color .2s ease;
}

.stat-card:hover {
    border-color: #D5D6EA;
    box-shadow: 0 8px 24px rgba(19, 14, 43, .06);
}

.stat-body {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}

.stat-label {
    color: var(--muted);
    font-size: 13px;
    font-weight: 500;
    letter-spacing: .01em;
    margin: 0 0 6px;
}

.stat-value {
    color: var(--ink);
    font-size: clamp(22px, 1.1rem + 1vw, 30px);
    font-weight: 700;
    line-height: 1.2;
    margin: 0 0 6px;
    font-variant-numeric: tabular-nums;
}

/*
 * An order number is one token: it used to break mid-word
 * ("#20262309Qv4HU6 / 0"). One line, ellipsis if the card is too narrow,
 * the full number in the title attribute.
 */
.stat-value-order {
    font-size: clamp(16px, .85rem + .5vw, 20px);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

.stat-body > .status { align-self: flex-start; }

.stat-meta {
    display: block;
    color: var(--muted);
    font-size: 12.5px;
}

.stat-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: auto;
    padding-top: 12px;
}

.stat-icon {
    width: clamp(44px, 2rem + 2vw, 72px);
    height: clamp(44px, 2rem + 2vw, 72px);
    object-fit: contain;
    flex-shrink: 0;
    opacity: .9;
}

/* ---------- buttons and links ---------- */

.link-action {
    color: var(--brand);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.link-action:hover { text-decoration: underline; }

.btn-top-up,
.btn-history,
.shipping-update {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    line-height: 1;
    padding: 0 14px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: background-color .15s ease, color .15s ease, border-color .15s ease;
}

.btn-top-up {
    background: var(--go);
    color: #fff;
    border: 1px solid var(--go);
}

.btn-top-up:hover:enabled,
a.btn-top-up:hover {
    background: var(--go-dark) !important;
    border-color: var(--go-dark);
    color: #fff;
}

.btn-history,
.shipping-update {
    background: #fff;
    color: var(--ink);
    border: 1px solid var(--line);
}

.btn-history:hover:enabled,
.shipping-update:hover:enabled {
    background: #F5F5FF !important;
    border-color: var(--brand);
    color: var(--brand);
}

.btn-top-up:disabled,
.btn-history:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.modal-close {
    width: 34px;
    height: 34px;
    line-height: 1;
    padding: 0;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: #fff;
    color: var(--muted);
    cursor: pointer;
    flex-shrink: 0;
}

.modal-close:hover {
    background: #F5F5FF !important;
    color: var(--ink);
}

/* ---------- panels ---------- */

.panel {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--pad);
    height: 100%;
    min-width: 0;
}

.panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.panel-title {
    font-size: clamp(16px, .9rem + .3vw, 18px);
    font-weight: 700;
    color: var(--ink);
    margin: 0;
}

.panel-title-solo { margin-bottom: 14px; }

.split-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--gap);
}

/* ---------- orders table ---------- */

.table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table th {
    background: #F5F5FF;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 10px 12px;
    text-align: left;
    white-space: nowrap;
}

.orders-table th:first-child { border-radius: 10px 0 0 10px; }
.orders-table th:last-child  { border-radius: 0 10px 10px 0; }

.orders-table td {
    font-size: 14px;
    color: var(--ink);
    padding: 14px 12px;
    border-bottom: 1px solid var(--line-soft);
    vertical-align: middle;
    white-space: nowrap;
}

.orders-table tbody tr:last-child td { border-bottom: 0; }

/* the phone list; hidden until the table is */
.order-list {
    display: none;
    list-style: none;
    margin: 0;
    padding: 0;
}

.order-list li + li { margin-top: 10px; }

/* a global li rule sizes items to their content; these must fit the panel */
.order-list li {
    display: block;
    width: 100%;
    min-width: 0;
}

.order-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-width: 0;
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: #fff;
    color: var(--ink);
    text-decoration: none;
    transition: border-color .15s ease, background-color .15s ease;
}

.order-item:hover,
.order-item:focus-visible {
    border-color: #C9CAE6;
    background: #FAFAFF;
    color: var(--ink);
    text-decoration: none;
}

.order-item:active { background: #F3F3FC; }

.order-item-ico {
    flex: 0 0 42px;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    background: var(--brand-soft);
    color: var(--brand);
}

.order-ico-delivered { background: #E8F6EC; color: #1E7E34; }
.order-ico-cancelled { background: #FDECEA; color: #C82333; }

.order-item-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
    flex: 1 1 auto;
}

.order-item-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-width: 0;
}

.order-item-ref {
    font-size: 14.5px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}

.order-item-meta .status { font-size: 11.5px; padding: 2px 9px; }

.order-item-meta {
    display: flex;
    flex-wrap: wrap;           /* the total drops a line rather than being cut */
    align-items: center;
    gap: 4px 8px;
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
}

.order-item-total {
    font-size: 14px;
    font-weight: 700;
    color: var(--ink);
    font-variant-numeric: tabular-nums;
}

.order-item-go {
    flex: 0 0 auto;
    font-size: 12px;
    color: #A3A6C4;
}
.orders-table tbody tr:hover { background: #FAFAFF; }

.order-ref { font-weight: 600; }

.icon-link {
    color: var(--brand);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: 13.5px;
}

.icon-link:hover { text-decoration: underline; }
.icon-link-text { display: none; }

.status {
    display: inline-block;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    text-transform: capitalize;
    white-space: nowrap;
}

.processing, .wallet-pending { background: #EBEBFF; color: var(--brand); }
.delivered,  .wallet-success { background: #D4EDDA; color: #1E7E34; }
.cancelled,  .wallet-failed  { background: #FDECEA; color: #C82333; }

.text-success { color: #1E7E34; font-weight: 500; }
.text-danger  { color: #C82333; font-weight: 500; }

/* ---------- address / security ---------- */

.media-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    min-width: 0;
}

.media-body { min-width: 0; }
.media-body strong { display: block; color: var(--ink); font-size: 14.5px; }

.muted-text {
    display: block;
    color: var(--muted);
    font-size: 13px;
    margin: 2px 0 0;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.icon-circle {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    background: var(--brand-soft);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: var(--brand);
}

.security-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    background: #F8F9FD;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 10px;
}

.security-item:last-child { margin-bottom: 0; }
.security-item .media-row { flex: 1 1 auto; align-items: center; }
.security-item .shipping-update { flex-shrink: 0; }

/* ---------- empty states ---------- */

.empty-state {
    text-align: center;
    padding: 32px 16px;
    color: var(--muted);
}

.empty-state-sm { padding: 20px 12px; }

.empty-state i {
    font-size: 26px;
    color: #C7C7D9;
    margin-bottom: 10px;
    display: block;
}

.empty-state p { font-size: 14px; margin: 0 0 14px; }

/* ---------- modals ---------- */

.topup-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(19, 14, 43, .55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    z-index: 9999;
}

.topup-modal {
    background: #fff;
    padding: var(--pad);
    border-radius: var(--radius);
    width: 100%;
    max-width: 460px;
    max-height: 90vh;
    overflow-y: auto;
}

.topup-modal-wide { max-width: 640px; }

.preset-amounts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(84px, 1fr));
    gap: 10px;
}

.preset-btn {
    height: 44px;
    line-height: 1;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    color: var(--ink);
    transition: border-color .15s ease, background-color .15s ease;
}

.preset-btn:hover {
    border-color: var(--go);
    background: #F3FCF6 !important;
}

.preset-btn.active,
.preset-btn.active:hover {
    background: var(--go) !important;
    color: #fff;
    border-color: var(--go);
}

.custom-amount { margin-top: 16px; }

.input-label {
    font-size: 13px;
    margin-bottom: 6px;
    display: block;
    color: var(--body);
}

.custom-input {
    height: 46px;
    width: 100%;
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 0 12px;
    font-size: 16px; /* 16px stops iOS zooming into the field */
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.pager {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.pager-info { font-size: 13px; color: var(--muted); }

/* ---------- tablet ---------- */

@media (max-width: 1199.98px) {
    .stat-card { flex-direction: column-reverse; }
    .stat-icon { position: absolute; top: var(--pad); right: var(--pad); }
    .stat-body { width: 100%; }
    .stat-label, .stat-value-order { padding-right: 52px; }
}

@media (max-width: 991.98px) {
    .split-grid { grid-template-columns: minmax(0, 1fr); }

    /* order card across the top so the number fits, wallet and points below */
    .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stat-card-order { grid-column: 1 / -1; }
    .stat-label, .stat-value-order { padding-right: 0; }
    .stat-card-order .stat-body { padding-right: 60px; }
}

/* ---------- phone ---------- */

@media (max-width: 767.98px) {

    .last-login { text-align: left; }
    .last-login strong { display: inline; margin-left: 4px; }

    /*
     * Four tiles across, icon over label, all visible at once. The row used
     * to scroll sideways, which cut "Wishlist" in half and hid "Addresses"
     * with nothing to say there was more.
     */
    .dash-quick {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }

    .dash-chip {
        flex-direction: column;
        justify-content: center;
        gap: 6px;
        height: auto;
        min-height: 68px;
        padding: 10px 4px;
        border-radius: 14px;
        text-align: center;
        font-size: 12px;
        font-weight: 600;
        white-space: normal;
        line-height: 1.2;
    }

    .dash-chip-ico {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, .18);
    }

    .dash-chip i { font-size: 14px; opacity: 1; }

    /* one word each, so the four tiles stay one line and the same height */
    .lbl-long { display: none; }
    .lbl-short { display: inline; }

    .dash-chip-label {
        max-width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* last login as its own quiet line under the greeting */
    .dash-hero .last-login {
        width: 100%;
        padding-top: 10px;
        border-top: 1px solid rgba(255, 255, 255, .16);
        font-size: 12px;
    }

    .dash-hero .last-login strong { font-size: 12.5px; }

    /* half-width cards: a smaller icon so "Wallet Balance" stays on one line */
    .stat-card-wallet .stat-icon,
    .stat-card-points .stat-icon { width: 30px; height: 30px; }

    .stat-card-wallet .stat-label,
    .stat-card-points .stat-label { padding-right: 34px; }

    /* two cards side by side leave ~150px each: stack the wallet buttons */
    .stat-card-wallet .stat-actions { flex-direction: column; align-items: stretch; }
    .stat-card-wallet .stat-actions .btn-top-up,
    .stat-card-wallet .stat-actions .btn-history { width: 100%; }

    /* the table gives way to the card list */
    .table-scroll { display: none; }
    .order-list { display: block; }

    .security-item { padding: 12px; }
    .security-item .media-row { gap: 10px; }
    .icon-circle { width: 36px; height: 36px; font-size: 14px; border-radius: 10px; }

    .modal-actions button,
    .pager button { flex: 1 1 auto; }
}

/* ---------- small phones ---------- */

@media (max-width: 359.98px) {
    .stat-grid { grid-template-columns: minmax(0, 1fr); }

    .stat-card-wallet .stat-label,
    .stat-card-points .stat-label { padding-right: 52px; }

    .security-item { flex-wrap: wrap; }
    .security-item .shipping-update { margin-left: 46px; }
}

@media (prefers-reduced-motion: reduce) {
    .dash-chip, .stat-card, .btn-top-up, .btn-history, .shipping-update { transition: none; }
}
</style>
