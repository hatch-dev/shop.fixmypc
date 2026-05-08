<template>
    <account-layout
        class="user-profile-wrapper"
        active-route="dashboard"
        :class="{'email-login': !loggedInWithEmail}"
    >
        <template v-slot:rightArea>

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-0 username">
                        Hello, {{ profile?.name || 'Guest User' }}!
                    </h5>
                    <small class="text-muted">Here's what's happening with your account today.</small>
                </div>
                <small class="text-muted">Last login<br><strong>Today, 10:23 AM</strong></small>
            </div>

            <!-- Alert -->
            <!-- <div class="custom-alert mb-4  align-items-center">
                <div>
                    <strong class="text-danger"><i class="fa-solid fa-circle-exclamation me-2" style="color: rgb(211, 47, 47);"></i>Payment Action Required</strong><br>
                    <p class="ms-4">Your payment for order #20261801 failed. Please update your payment method to process the order.</p> 
                </div>
                <button class="btn btn-danger btn-sm ms-4">Resolve Issue</button>
            </div> -->

            <!-- Cards -->
            <div class="row g-3 mb-4">

                <!---- Card 1 -->
                <div class="col-md-4">
                    <div class="recent-card-box d-flex justify-content-between">
                        <div>
                            <p class="Recent-order">Recent Order</p>
                            <h5 class="order-id">#{{ latestOrder?.order || '----' }}</h5>
                            <span class="order-processing">{{ latestOrder?.status ? orderStatus[latestOrder.status]?.title : 'N/A' }}</span><br>
                            <div class="mt-2">
                                <nuxt-link to="/track-order" class="order-track mt-4">Track Order →</nuxt-link>
                            </div>
                        </div>
                        <img
                            src="@/assets/images/Order.png"
                            class="icon-img"
                        />
                    </div>
                
                </div>

               <!---- Card 2 -->
                <div class="col-md-4">
                    <div class="recent-card-box d-flex justify-content-between">
                        <div >
                            <p class="Recent-order">Wallet Balance</p>
                            <h5 class="order-id">€{{ wallet?.balance || 0.00 }}</h5>
                            <span class="order-pending">+ €{{ wallet?.pending || 0.00 }} pending</span><br>
                            <div class="mt-2">
                                <button class="btn-top-up" @click="openTopup">Top Up</button>
                                <button class="btn-history" @click="openHistory">History</button>
                            </div>
                        </div>
                        <img
                            src="@/assets/images/Balance.png"
                            class="icon-img"
                        />

                    </div>
                
                </div>

                 <!---- Card 3 -->
                <div class="col-md-4">
                    <div class="recent-card-box d-flex justify-content-between">
                        <div >
                            <p class="Recent-order">Cherry Points</p>
                            <h5 class="order-id">{{ points?.points || 0 }} pts</h5>
                            <span class="order-pending">Value: €{{ points?.value || '0.00' }}</span><br>
                            <div class="mt-2">
                                <nuxt-link to="/track-order" class="order-track mt-4">Track Order →</nuxt-link>
                            </div>
                        </div>
                        <img
                            src="@/assets/images/Points.png"
                            class="icon-img"
                        />
                    </div>
                
                </div>
            </div>

            <!-- Table -->
            <div class="table-box">
                <div class="d-flex justify-content-between mb-3">
                    <h6 class="table-heading">Recent Orders</h6>
                    <nuxt-link to="/user/orders" class="view-all-order">View All Orders</nuxt-link>
                </div>

                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th class="table-heading">Order ID</th>
                            <th class="table-heading">Date</th>
                            <th class="table-heading">Status</th>
                            <th class="table-heading">Total</th>
                            <th class="table-heading">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            class="table-details"
                            v-for="order in recentOrders"
                            :key="order.id"
                        >
                            <td>#{{ order.order }}</td>
                            <td>{{ formatDate(order.created) }}</td>

                            <td>
                                <span class="status" :class="orderStatusClass(order.status)">
                                {{ orderStatus[order.status]?.title }}
                                </span>
                            </td>

                            <td>€{{ order.total_amount }}</td>

                            <td>
                                <nuxt-link :to="`/user/order/${order.id}`">
                                <i class="fa-solid fa-eye fs-5"></i>
                                </nuxt-link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="row g-4 mt-3 mb-5">

                <!-- Default Shipping Address -->
                <div class="col-md-6">
                    <div class="Shipping-card-box h-100 position-relative">
                        
                        <div class="d-flex justify-content-between mb-3">
                            <h6 class="mb-0 default-shipping">Default Shipping Address</h6>
                            <nuxt-link to="/user/addresses" class="shipping-edit">
                              <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                            </nuxt-link>
                        </div>

                        <div class="d-flex">
                            <div class="icon-circle me-3">
                                <i class="fa-solid fa-location-dot" style="color: rgb(0, 0, 0);"></i>
                            </div>

                            <div v-if="defaultAddress">
                                <strong>{{ defaultAddress.name }}</strong>
                                <p class="mb-1 small" v-html="formatAddress(defaultAddress)" />
                                <p class="small mb-0">
                                    tel: {{ defaultAddress.phone }}
                                </p>
                            </div>

                            <div v-else>
                                <p>No address available</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Account Security -->
                <div class="col-md-6">
                    <div class="Shipping-card-box h-100">

                        <h6 class="mb-3 default-shipping">Account Security</h6>

                        <!-- Password -->
                        <div class="security-item d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center">
                                <div class="icon-circle me-3"><i class="fa-solid fa-lock" style="color: rgb(0, 0, 0);"></i></div>
                                <div>
                                    <strong>Password</strong><br>
                                    <span class="small text-muted">Last changed 3 months ago</span>
                                </div>
                            </div>
                            <button class="shipping-update" @click="$router.push('/user/profile')">
                                Update
                            </button>
                        </div>

                        <!-- Email Preference -->
                        <div class="security-item d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="icon-circle me-3"><i class="fa-solid fa-envelope" style="color: rgb(0, 0, 0);"></i></div>
                                <div>
                                    <strong>Email Preference</strong><br>
                                    <span class="small text-muted">Subscribed to newsletter</span>
                                </div>
                            </div>
                            <button class="shipping-update">Manage</button>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Top Up Modal -->
            <div v-if="showTopupModal" class="topup-modal-overlay">
                <div class="topup-modal">

                    <h5 class="mb-3">Top Up Wallet</h5>

                    <!-- Preset Amounts -->
                    <div class="preset-amounts mb-3">
                        <button 
                            v-for="amt in presetAmounts" 
                            :key="amt"
                            @click="selectAmount(amt)"
                            :class="['preset-btn', selectedAmount == amt ? 'active' : '']"
                        >
                            €{{ amt }}
                        </button>
                        <button 
                            @click="selectCustom"
                            :class="['preset-btn', isCustom ? 'active' : '']"
                        >
                            Custom
                        </button>
                    </div>

                    <!-- Custom Input -->
                    <div v-if="isCustom" class="mb-3">
                        <label class="input-label">Enter Amount</label>
                        <input 
                            type="number" 
                            step="0.01"
                            v-model="customAmount"
                            class="form-control custom-input"
                            placeholder="e.g. 75.50"
                        />
                    </div>

                    <!-- Actions -->
                    <div class="d-flex justify-content-end gap-2">
                        <button class="btn btn-light" @click="closeTopup">
                            Cancel
                        </button>

                        <button 
                            class="btn btn-success"
                            :disabled="!finalAmount || processingPayment"
                            @click="submitTopup"
                        >
                            <span v-if="processingPayment">Processing...</span>
                            <span v-else>Pay €{{ finalAmount || 0 }}</span>
                        </button>
                    </div>

                </div>
            </div>

            <!-- Wallet History Modal -->
            <div v-if="showHistoryModal" class="topup-modal-overlay">
                <div class="topup-modal">

                    <div class="d-flex justify-content-between mb-3">
                        <h5>Wallet History</h5>
                        <button class="btn btn-light" @click="showHistoryModal = false">X</button>
                    </div>

                    <table class="table">
                        <thead>
                            <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="item in walletHistory" :key="item.id">
                            <td>{{ formatDate(item.created_at) }}</td>

                            <td>
                                <span v-if="item.type === 'credit'" class="text-success">
                                Credit
                                </span>
                                <span v-else class="text-danger">
                                Debit
                                </span>
                            </td>

                            <td>
                                <span v-if="item.type === 'credit'" class="text-success">
                                +€{{ item.amount }}
                                </span>
                                <span v-else class="text-danger">
                                -€{{ item.amount }}
                                </span>
                            </td>

                            <td>
                                <span :class="{
                                'text-warning': item.status === 'pending',
                                'text-success': item.status === 'success',
                                'text-danger': item.status === 'failed'
                                }">
                                {{ item.status }}
                                </span>
                            </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="d-flex justify-content-between align-items-center mt-3">

                    <!-- Previous -->
                    <button 
                        class="btn btn-light"
                        :disabled="!pagination.prev_page_url"
                        @click="fetchWalletHistory(pagination.current_page - 1)"
                    >
                        ← Prev
                    </button>

                    <!-- Page Info -->
                    <span>
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>

                    <!-- Next -->
                    <button 
                        class="btn btn-light"
                        :disabled="!pagination.next_page_url"
                        @click="fetchWalletHistory(pagination.current_page + 1)"
                    >
                        Next →
                    </button>

                    </div>

                    <div v-if="!walletHistory.length" class="text-center py-3">
                        No transactions found
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
        loading: false
      }
    },
    computed: {
      ...mapGetters('address', ['addresses']),
      ...mapGetters('order', ['orderedList']),
      ...mapGetters('user', ['profile']),
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
      recentOrders() {
        return this.orderedList?.data?.slice(0, 3) || []
      },
      latestOrder() {
        return this.orderedList?.data?.[0] || null
      },
      loggedInWithEmail() {
        return this.profile && !this.profile?.facebook_id && !this.profile?.google_id
      },
    },
    components: {
    },
    methods:{
        ...mapActions('order', ['getOrderByUser']),
        ...mapActions('user', ['getUserToken']),
        ...mapActions('address', ['getAddressByUser']),
        ...mapActions('common', ['getRequest']),
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
                alert('Enter valid amount')
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

                if (data?.id) {
                    this.$router.push({
                        path: '/user/topup-checkout',
                        query: { checkoutId: data.id, checkoutReference: data.checkout_reference }
                    })
                }

            } catch (e) {
                console.error(e)
                alert('Payment error')
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
                this.$el.querySelector('input')?.focus()
            })
        },
        async waitForAuth() {
            return new Promise((resolve) => {
                const check = () => {
                    if (this.$auth?.user?.id) {
                        resolve()
                    } else {
                        setTimeout(check, 100)
                    }
                }
                check()
            })
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
        orderStatusClass(status) {
            const map = {
            0: 'processing',
            1: 'delivered',
            2: 'cancelled'
            }
            return map[status] || 'processing'
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
        await this.fetchWallet()
        await this.fetchPoints()
        await this.fetchOrders()
        await this.fetchAddresses()
    },
}
</script>
<style scoped>
.recent-card-box {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    height: 100%;
    border: 1px solid #E3E3EF;
}
p.Recent-order {
    color: #6B7280;
    font-size: 14px;
    font-weight: 400;
    margin-bottom: 5px;
}
h5.order-id {
    color: #130E2B;
    font-size: 24px;
    font-weight: 500;
}
span.order-processing {
    color: #33319A;
    font-size: 12px;
    font-weight: 400;
    background-color: #F5F5FF;
    padding: 10px 16px;
}
a.order-track {
    text-decoration: none;
    color: #33319A;
    font-weight: 400;
    font-size: 14px;
}
span.order-pending {
    color: #6B7280;
    font-size: 12px;
    font-weight: 400;
}
button.btn-top-up {
    background-color: #05B942;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0px 12px;
    font-size: 12px;
    font-weight: 400;
}
button.btn-top-up:hover {
    background-color: #33319A;
    color: #fff;
    border: none;
}
button.btn-history {
    background-color: transparent;
    color: #000000;
    border: 1px solid #E3E3EF;
    border-radius: 8px;
    padding: 0px 12px;
    font-size: 12px;
    font-weight: 400;
}
button.btn-history:hover {
    background-color: #05B942;
    color: #fff;
}

.custom-alert {
    background: #FDEAEA;
    border-radius: 14px;
    padding: 15px 20px;
    border: 1px solid #F5F5F5;
}

.table-box {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    border: 1px solid #E3E3EF;
}
h6.table-heading {
    font-size: 18px;
    font-weight: 400;
    color: #171717;
}
a.view-all-order {
    font-size: 14px;
    color: #33319A;
    font-weight: 400;
    text-decoration: none;
}
th.table-heading {
    background-color: #F5F5FF;
    font-size: 14px;
    font-weight: 600;
    color: #130E2B;
    padding: 11px 8px;
}
tr.table-details td {
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
    padding: 13px 13px;
}
.status {
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 6px;
}
.processing {
    background: #EBEBFF;
    color: #33319A;
    font-size: 12px;
    font-weight: 400;
}
.delivered { background:#d4edda; color:#28a745; }
.cancelled { background:#fdecea; color:#dc3545; }

.Shipping-card-box {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
    height: 100%;
    border: 1px solid #E3E3EF;
}
.default-shipping {
    font-size: 18px;
    font-weight: 500;
    color: #130E2B;
}
a.shipping-edit {
    text-decoration: none;
    color: #33319A;
    font-weight: 400;
    font-size: 12px;
}
.icon-circle {
    width: 42px;
    height: 42px;
    background: #EEEEFA;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

/* Security items */
.security-item {
    background: #F3F5FC;
    padding: 12px 15px;
    border-radius: 10px;
    border: 1px solid #E3E3EF;
}
button.shipping-update {
    border: 1px solid #E3E3EF;
    border-radius: 4px;
    background-color: #fff;
    font-weight: 400;
    font-size: 12px;
    color: #171717;
    padding: 0px 10px;
}
button.shipping-update:hover {
    background-color:#05B942;
    color: #fff;
  
}
p.shipping-address {
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
}
span.user-mob {
    color: #6B7280;
}

.icon-img {
    height: 100px;
    width: 100px;
    position: absolute;
    right: 10px;
    top: 0;
}

.topup-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.topup-modal {
  background: #fff;
  padding: 25px;
  border-radius: 12px;
  width: 475px;
  max-width: 90%;
}

.preset-amounts {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.preset-btn {
  min-width: 75px;
  height: 42px;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
  color: #130E2B;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
}

.preset-btn {
  flex: 1 1 calc(20% - 8px);
  max-width: calc(20% - 8px);
}

.preset-btn:hover {
  border-color: #05B942;
}

.preset-btn.active {
  background: #05B942;
  color: #fff;
  border-color: #05B942;
}

.input-label {
  font-size: 13px;
  margin-bottom: 5px;
  display: block;
  color: #555;
}

.custom-input {
  height: 45px;
  border-radius: 10px;
  margin-top: 5px;
}
</style>