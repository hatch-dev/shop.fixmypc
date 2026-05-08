<template>
  <client-only>
  <account-layout
    @clicked-vouchers="loadData"
    active-route="savers-club"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <div class="card-box-club p-4 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1 order-hrading">Savers Club</h5>
            <small class="text-muted">
                Your wallet, rewards, and exclusive membership benefits
            </small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-statement">
                <i class="fa-solid fa-download me-1" style="color: rgb(82, 90, 107);"></i> Statement
            </button>
            <button class="btn-add-money">
                <i class="fa-solid fa-plus me-1" style="color: #fff;"></i> Add Money
            </button>
        </div>
      </div>
      <div class="wallet-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

            <!-- Left -->
            <div>
                <p class="wallet-balance">Wallet Balance</p>
                <h2 class="my-2 wallet-amount">{{ currencyIcon }}{{ wallet?.balance || 0 }}</h2>
                <p class="wallet-balance">Available to spend</p>
            </div>

            <!-- Right -->
            <div class="text-end">
                <div class="permium-member-btn mb-2">
                    👑 Premium Member
                </div>
                <p class="member-since">Member since Jan 2024</p>
            </div>

        </div>
        <div class="wallet-divider"></div>
        <div class="row mt-3">
          <div class="col-md-4">
              <small>Cherry Points</small>
              <h6 class="mb-1 cherry-points-total">{{ points?.points || 0 }} pts</h6>
              <small class="opacity-75">≈ {{ currencyIcon }}{{ points?.value || 0 }} value</small>
          </div>
          <div class="col-md-4">
              <small>Total Saved</small>
              <h6 class="mb-1 cherry-points-total">{{ currencyIcon }}{{ transactions?.totalSaved || 0 }}</h6>
              <small class="opacity-75">Lifetime savings</small>
          </div>
          <div class="col-md-4">
              <small>Free Shipping</small>
              <h6 class="mb-1 cherry-points-total">∞</h6>
              <small class="opacity-75">With membership</small>
          </div>
        </div>
      </div>
      <div class="row g-4">
          <div class="col-md-6">
              <div class="card-box-club p-4 h-100">
                  <div class="d-flex justify-content-between mb-3">
                      <div>
                          <h6 class="mb-1 wallet-money">Add Money to Wallet</h6>
                          <small class="wallet-topup">Top up your wallet balance instantly</small>
                      </div>
                      <span class="icon-box"><i class="fa-regular fa-credit-card"
                              style="color: rgb(82, 90, 107);"></i></span>
                  </div>
                  <div class="d-flex gap-2 mb-3">
                      <button
                        v-for="amt in presetAmounts"
                        :key="amt"
                        @click="selectAmount(amt)"
                        :class="['amount-btn', { active: selectedAmount === amt }]"
                      >
                        €{{ amt }}
                      </button>
                  </div>
                  <label class="wallet-topup mb-1">Custom Amount</label>
                  <input
                    type="number"
                    v-model="customAmount"
                    @focus="selectCustom"
                    class="form-control custom-input-wallet mb-3"
                    placeholder="€ 0.00"
                  />
                  <button
                    class="btn-add-wallet w-100"
                    :disabled="!finalAmount || processingPayment"
                    @click="submitTopup"
                  >
                    <span v-if="processingPayment">Processing...</span>
                    <span v-else>Add €{{ finalAmount || 0 }}</span>
                  </button>
              </div>
          </div>
          <div class="col-md-6">
              <div class="card-box-club p-4 h-100">
                  <div class="d-flex justify-content-between mb-3">
                      <div>
                          <h6 class="mb-1 wallet-money">Cherry Points</h6>
                          <small class="wallet-topup">Earn & redeem reward points</small>
                      </div>
                      <span class="icon-box"><i class="fa-regular fa-credit-card"
                              style="color: rgb(82, 90, 107);"></i></span>
                  </div>
                  <div class="points-box mb-3">
                      <div class="d-flex justify-content-between mb-1">
                          <small>Current Points</small>
                          <strong>{{ points?.points || 0 }}</strong>
                      </div>
                      <div class="progress-track">
                          <div class="progress-fill green" :style="{ width: (points?.points / 20000 * 100) + '%' }"></div>
                      </div>
                      <small class="text-muted">20000 points to next reward tier</small>
                  </div>
                  <div class="d-flex justify-content-between small mb-1">
                      <span class="conversion-rate">Conversion Rate</span>
                      <span class="pts">100 pts = €1</span>
                  </div>
                  <div class="d-flex justify-content-between small mb-3">
                      <span class="conversion-rate">Points Value</span>
                      <span class="pts">{{ currencyIcon }}{{ points?.value || 0 }}</span>
                  </div>
                  <button class="btn-redeem-point w-100">Redeem Points</button>
              </div>
          </div>
      </div>
      <div class="card-box-club p-0 mb-4 mt-3">
        <div class="section-header p-4">
            <h6 class="mb-1 wallet-money">Premium Membership</h6>
            <small class="wallet-topup ">
                Unlock exclusive benefits and special discounts
            </small>
        </div>
        <div class="p-4 premium-member-box">
            <div class="row text-center mb-4">
                <div class="col-md-4">
                    <div class="feature-icon"><i class="fa-regular fa-credit-card"
                            style="color: rgb(19, 14, 43);"></i></div>
                    <h6 class="mt-2">Free Shipping</h6>
                    <small class="text-muted">On all orders, no minimum</small>
                </div>
                <div class="col-md-4">
                    <div class="feature-icon">%</div>
                    <h6 class="mt-2">Exclusive Discounts</h6>
                    <small class="text-muted">Up to 25% off member prices</small>
                </div>
                <div class="col-md-4">
                    <div class="feature-icon">⭐</div>
                    <h6 class="mt-2">Early Access</h6>
                    <small class="text-muted">To sales and new products</small>
                </div>
            </div>
            <div class="plan-box">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <div>
                        <strong>Current Plan: Premium</strong>
                        <span class="badge-active ms-2">ACTIVE</span><br>
                        <small class="text-muted">
                            Your membership renews on March 15, 2025
                        </small>
                    </div>
                    <div class="text-end">
                        <h5 class="mb-0">€9.99</h5>
                        <small class="text-muted">per month</small>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3 flex-wrap">
                    <button class="btn-Manage-Subscription">
                        Manage Subscription
                    </button>
                    <button class="btn btn-light">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
      </div>
      <div class="card-box-club p-0  mt-4 ">
        <div class=" d-flex justify-content-between align-items-center p-4">
            <div>
                <h6 class="mb-1">Recent Transactions</h6>
                <small class="text-muted">Your wallet activity and purchase history</small>
            </div>
            <button class="btn btn-light btn-sm">Last 30 days</button>
        </div>
        <div class="table-responsive">
          <table class="table custom-table mb-0">
            <thead>
                <tr class="recent-table">
                    <th>DATE</th>
                    <th>DESCRIPTION</th>
                    <th>TYPE</th>
                    <th>POINTS</th>
                    <th>AMOUNT</th>
                    <th>BALANCE</th>
                </tr>
            </thead>
            <tbody>
              <tr v-for="item in transactions" :key="item.id">
                <td>{{ formatDate(item.created_at) }}</td>
                <td>{{ item.source }}</td>
                <td>
                  <span :class="item.type === 'credit' ? 'badge credit' : 'badge debit'">
                    {{ item.type.toUpperCase() }}
                  </span>
                </td>
                <td>{{ item.points || '-' }}</td>
                <td :class="item.type === 'credit' ? 'text-success' : 'text-danger'">
                  {{ item.type === 'credit' ? '+' : '-' }}€{{ item.amount }}
                </td>
                <td>€{{ item.balance_after }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center p-3 flex-wrap gap-2">
          <small class="text-muted">
            Showing {{ transactions.length }} of {{ pagination.total }} transactions
          </small>
          <div class="d-flex align-items-center gap-2">
            <button
              class="page-btn"
              :disabled="!pagination.prev_page_url"
              @click="fetchTransactions(pagination.current_page - 1)"
            >
              Prev
            </button>
            <button
              v-for="page in pagination.last_page"
              :key="page"
              @click="fetchTransactions(page)"
              :class="['page-number', { active: page === pagination.current_page }]"
            >
              {{ page }}
            </button>
            <button
              class="page-btn"
              :disabled="!pagination.next_page_url"
              @click="fetchTransactions(pagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </div>
      <div class="card-box-club p-4 mt-4 mb-4">
        <h6 class="mb-4 wallet-money"">Qualifying Products</h6>
        <div class="row g-3">
          <div v-if="!productLoading && !qualifyingProducts.length" class="text-center py-4">
            No qualifying products available
          </div>
          <div
            class="col-md-3"
            v-for="product in qualifyingProducts"
            :key="product.id"
          >
            <div class="product-card">
              <span class="points-badge">
                {{ Number(product.required_points) }} pts
              </span>
              <div class="product-img">
                <lazy-image
                :data-src="getImageURL(product.image)"
                :title="product.title"
                :alt="product.slug"
                style="width: 100%; height: 100%; object-fit: contain;"
                />
              </div>
              <p class="product-name">
                {{ product.title }}
              </p>
              <div class="progress-track mb-2">
                <div
                  class="progress-fill green"
                  :style="{
                    width: Math.min(
                      100,
                      (Number(userPoints) / Number(product.required_points)) * 100
                    ) + '%'
                  }"
                ></div>
              </div>
              <div class="d-flex gap-2 align-items-center justify-content-between">
                <strong>
                  {{ currencyIcon }}{{ product.offered }}
                </strong>
                <button
                  v-if="product.is_unlocked"
                  class="btn btn-outline-primary btn-sm product-view-btn"
                  @click="$router.push(`/${product.slug}/product/${product.id}`)"
                >
                  View
                </button>
                <div v-else class="locked-info">
                  <small class="text-muted">
                    {{ product.points_needed }} pts
                  </small>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="text-center mt-4" v-if="hasMoreProducts">
            <button class="btn btn-primary px-4 Qualifying Products-btn" @click="loadMoreProducts" :disabled="productLoading">
              <span v-if="productLoading">Loading...</span>
              <span v-else>View More Products</span>
            </button>
        </div>
        <!-- <div class="row g-3">
          <div class="col-md-3">
            <div class="product-card">
                <span class="points-badge">+2000 pts</span>
                <div class="product-img">💻</div>
                <p class="product-name">Dell Gaming Laptop Pro</p>
                <div class="d-flex gap-4 align-items-center">
                    <strong>€899</strong>
                    <button class="btn btn-outline-primary btn-sm product-view-btn">View</button>
                </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="product-card">
                <span class="points-badge">+2000 pts</span>
                <div class="product-img">🎧</div>
                <p class="product-name">Premium Noise Cancelling</p>
               <div class="d-flex gap-4 align-items-center">
                    <strong>€899</strong>
                    <button class="btn btn-outline-primary btn-sm product-view-btn">View</button>
                </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="product-card">
                <span class="points-badge">+2000 pts</span>
                <div class="product-img">📱</div>
                <p class="product-name">Latest Flagship Phone</p>
                <div class="d-flex gap-4 align-items-center">
                    <strong>€899</strong>
                    <button class="btn btn-outline-primary btn-sm product-view-btn">View</button>
                </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="product-card">
                <span class="points-badge">+2000 pts</span>
                <div class="product-img">🎮</div>
                <p class="product-name">RGB Gaming Keyboard</p>
                <div class="d-flex gap-4 align-items-center">
                    <strong>€899</strong>
                    <button class="btn btn-outline-primary btn-sm product-view-btn">View</button>
                </div>
            </div>
          </div>
        </div> -->
        
      </div>
      <div class="row g-4">
        <div class="col-md-6">
          <div class="card-box-club p-4 h-100">
            <h6 class="mb-4 wallet-money"">How Cherry Points Work</h6>
            <div class="step-item d-flex mb-3">
                <div class="step-circle">1</div>
                <div>
                    <strong>Earn Points</strong><br>
                    <small class="text-muted">Get 100 points for every €1 spent on qualifying products</small>
                </div>
            </div>
            <div class="step-item d-flex mb-3">
                <div class="step-circle">2</div>
                <div>
                    <strong>Accumulate</strong><br>
                    <small class="text-muted">Points never expire as long as your account is active</small>
                </div>
            </div>
            <div class="step-item d-flex mb-3">
                <div class="step-circle">3</div>
                <div>
                    <strong>Redeem</strong><br>
                    <small class="text-muted">Convert 100 points to €1 and add to your wallet balance</small>
                </div>
            </div>
            <div class="step-item d-flex">
                <div class="step-circle">4</div>
                <div>
                    <strong>Shop</strong><br>
                    <small class="text-muted">Use wallet balance on any purchase across our store</small>
                </div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card-box-club p-4 h-100">
              <h6 class="mb-4 wallet-money"">Membership Benefits</h6>
              <div class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  Unlimited free standard shipping
              </div>
              <div class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  Exclusive member-only discounts up to 25%
              </div>
              <div class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  Early access to flash sales and new arrivals
              </div>
              <div class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  Bonus points on qualifying purchases
              </div>
              <div class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  Priority customer support
              </div>
              <div class="benefit-item">
                  <span class="check-icon"><i class="fa-solid fa-check" style="color: #Fff;"></i></span>
                  Birthday special vouchers
              </div>
          </div>
        </div>
      </div>
      <div class="card-box-club p-4 mb-4 mt-4">
        <h6 class="mb-4 wallet-money"">Frequently Asked Questions</h6>
        <div class="faq-item">
            <strong>How do I add money to my wallet?</strong>
            <p>
                You can add money using the quick amount buttons or enter a custom amount. Payment is processed instantly through secure payment gateway.
            </p>
        </div>
        <div class="faq-item">
            <strong>Can I withdraw money from my wallet?</strong>
            <p>
                Wallet balance can only be used for purchases on our platform. However, you can request a refund to your original payment method for returned orders.
            </p>
        </div>
        <div class="faq-item">
            <strong>Do Cherry Points expire?</strong>
            <p>
                No, Cherry Points never expire as long as your account remains active with at least one purchase per year.
            </p>
        </div>
        <div class="faq-item border-0">
            <strong>Can I cancel my membership anytime?</strong>
            <p>
                Yes, you can cancel your membership at any time. You’ll continue to have access until the end of your current billing period.
            </p>
        </div>
      </div>
    </template>
  </account-layout>
  </client-only>
</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import RatePopup from '~/components/RatePopup'
  import AccountLayout from '~/components/AccountLayout'
  import Pagination from '~/components/Pagination'
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: 'Saver Club',
        meta: []
      }
    },
    data() {
      return {
        productsPage: 1,
        hasMoreProducts: true,
        qualifyingProducts: [],
        userPoints: 0,
        productLoading: false,
        wallet: null,
        points: null,
        transactions: [],
        loading: false,
        pagination: {
          current_page: 1,
          last_page: 1,
          next_page_url: null,
          prev_page_url: null,
          total: 0
        },
        selectedAmount: null,
        customAmount: null,
        isCustom: false,
        processingPayment: false,
        presetAmounts: [25, 50, 100]
      }
    },
    watch: {
      '$route.query.checkoutId'(val) {
        if (val) {
          this.fetchWallet()
          this.fetchTransactions()
        }
      }
    },
    components: {
      LazyImage,
      RatePopup,
      AccountLayout,
      Pagination
    },
    mixins: [util, global],
    computed: {
      finalAmount() {
        if (this.isCustom) {
          return parseFloat(this.customAmount || 0)
        }
        return this.selectedAmount
      },
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    methods: {
      loadMoreProducts() {
        if (!this.hasMoreProducts) return

        this.fetchQualifyingProducts(this.productsPage + 1, true)
      },
      async fetchQualifyingProducts(page = 1, append = false) {
        try {
          this.productLoading = true
          const token = this.$auth?.strategy?.token?.get()
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

          const { data } = await this.$axios.get(
            `${baseUrl}api/v1/user/product/qualifying?page=${page}`,
            {
              headers: { Authorization: `Bearer ${token}` }
            }
          )
          this.userPoints = data.points || 0
          if (append) {
            this.qualifyingProducts = [
              ...this.qualifyingProducts,
              ...(data.data || [])
            ]
          } else {
            this.qualifyingProducts = data.data || []
          }
          this.hasMoreProducts = data.current_page < data.last_page
          this.productsPage = data.current_page
        } catch (e) {
          console.error(e)
        } finally {
          this.productLoading = false
        }
      },
      selectAmount(amount) {
        this.selectedAmount = amount
        this.customAmount = null
        this.isCustom = false
      },

      selectCustom() {
        this.selectedAmount = null
        this.isCustom = true
      },

      async submitTopup() {
        try {
          if (!this.finalAmount || this.finalAmount <= 0) {
            alert('Enter valid amount')
            return
          }

          this.processingPayment = true

          const token = this.$auth?.strategy?.token?.get()
          const baseUrl = process.env.apiBase

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
              query: {
                checkoutId: data.id,
                checkoutReference: data.checkout_reference
              }
            })
          }

        } catch (e) {
          console.error(e)
          alert('Payment error')
        } finally {
          this.processingPayment = false
        }
      },

      formatDate(date) {
        if (!date) return '-'

        return new Date(date).toLocaleDateString('en-US', {
          month: 'short',
          day: '2-digit',
          year: 'numeric'
        })
      },
      async fetchWallet() {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase

        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/wallet`,
          { headers: { Authorization: `Bearer ${token}` } }
        )

        this.wallet = data
      },

      async fetchPoints() {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase

        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/points`,
          { headers: { Authorization: `Bearer ${token}` } }
        )

        this.points = data
      },

      async fetchTransactions(page = 1) {
        const token = this.$auth?.strategy?.token?.get()
        const baseUrl = process.env.apiBase

        const { data } = await this.$axios.get(
          `${baseUrl}api/v1/user/wallet/transactions?page=${page}`,
          { headers: { Authorization: `Bearer ${token}` } }
        )

        this.transactions = data.data
        this.pagination = {
          current_page: data.current_page,
          last_page: data.last_page,
          next_page_url: data.next_page_url,
          prev_page_url: data.prev_page_url,
          total: data.total
        }
      },
      async loadData() {
        setTimeout(()=>{
          this.$refs.voucherPagination.loadData()
        },100)
      },
    },
    async mounted() {
      await this.fetchWallet()
      await this.fetchPoints()
      await this.fetchTransactions(1)
      await this.fetchQualifyingProducts(1)
    }
  }
</script>

<style scoped>
.card-box-club {
    background: #fff;
    border-radius: 15px;
    border: 1px solid #e6e8f0;
}

button.btn-statement {
    background-color: #F7F7FA;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
    padding: 0px 15px;
}

button.btn-add-money {
    background-color: #33319A;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
    padding: 0px 15px;
    color: #fff;
}

button.btn-add-money:hover {
    background-color: #05B942;
}

.section-header {
    background: #F3F3FA;
    border-top-left-radius: 15px;
    border-top-right-radius: 15px;
}

/* Feature icon */
.feature-icon {
    width: 60px;
    height: 60px;
    background: #f1f2f6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 20px;
}

/* Plan box */
.plan-box {
    background: #f7f8fc;
    border-radius: 12px;
    padding: 20px;
}

/* Active badge */
.badge-active {
    background: #4b49ac;
    color: #fff;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Buttons */
.btn-success {
    background: #28a745;
    border: none;
    border-radius: 8px;
}

.btn-light {
    background: #eef0f6;
    border: none;
    width: 10%;
}

/* Header strip */
.section-header {
    background: #f1f2f6;
}

/* Table */
.custom-table thead {
    background: #f7f8fc;
}

.custom-table th {
    font-size: 12px;
    color: #130E2B;
    font-weight: 600;
}

.custom-table td {
    vertical-align: middle;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
}
/* Badges */
.badge {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

.badge.credit {
    background: #e6f7ee;
    color: #28a745;
}

.badge.debit {
    background: #fdecea;
    color: #dc3545;
}

/* Pagination */
.page-number {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f1f2f6;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: #333;
}

.page-number.active {
    background: #333199;
    color: #fff;
}

.page-btn {
    color: #666;
    text-decoration: none;
    font-size: 14px;
}
.product-card {
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 15px;
    position: relative;
    transition: 0.2s;
}

.product-card:hover {
    border-color: #4b49ac;
    transform: translateY(-2px);
}

/* Points badge */
.points-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #130E2B;
    color: #fff;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Image */
.product-img {
    height: 120px;
    background: #f1f2f6;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    margin-bottom: 10px;
}

/* Name */
.product-name {
    font-size: 14px;
    margin-bottom: 8px;
}

/* Button */
.btn-outline-primary {
    border-color: #4b49ac;
    color: #4b49ac;
}

.btn-outline-primary:hover {
    background: #4b49ac;
    color: #fff;
}

/* Primary button */
.btn-primary {
    background: #4b49ac;
    border: none;
}
.step-item {
    gap: 12px;
}

.step-circle {
    width: 32px;
    height: 32px;
    background: #4b49ac;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

/* Benefits */
.benefit-item {
    background: #f7f8fc;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Check icon */
.check-icon {
    background: #28a745;
    color: #fff;
    font-size: 12px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
button.btn-Manage-Subscription {
    background-color: #05B942;
    border: none;
    color: #fff;
    font-weight: 500;
    font-size: 14px;
    width: 89%;
    border-radius: 8px;
}
button.btn-Manage-Subscription:hover {
    background-color: #33319A;
}
.faq-item {
    padding: 12px 0;
    border-bottom: 1px solid #e6e8f0;
}

.faq-item strong {
    font-size: 14px;
    display: block;
    margin-bottom: 5px;
    font-weight: 400;
}
tr.recent-table th {
    background-color: aliceblue;
    border: 1px solid #E3E3EF;
    padding: 12px 20px;
}

.faq-item p {
    font-size: 13px;
    color: #666;
    margin: 0;
}
button.Qualifying.Products-btn {
    background-color: #33319A;
    color: #fff;
    font-weight: 400;
    border-radius: 8px;
    font-size: 14px;
    padding: 9px 0;
}
button.Qualifying.Products-btn:hover {
    background-color: #05B942;
}
button.product-view-btn {
    border: 1px solid #333199;
    border-radius: 80px;
    color: #333199;
    font-size: 12px;
    font-weight: 500;
    padding: 3px 20px;
}
button.product-view-btn:hover {
    color: #fff;
    background-color: #333199;
}
.wallet-card {
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg, #9E1C82, #33319A);
}
 
/* Divider */
.wallet-card {
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg, #9E1C82, #33319A);
}
h6.wallet-money {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}
small.wallet-topup {
    font-size: 14px;
    font-weight: 400;
    color: #6B7280;
}
 
/* Amount buttons */
.amount-btn {
    flex: 1;
    border-radius: 8px;
    border: 2px solid #E3E3EF;
    background: #fff;
    transition: 0.2s;
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}
 
.amount-btn:hover,
.amount-btn.active {
    border-color: #4b49ac;
    color: #4b49ac;
}
 
/* Input */
.custom-input-wallet {
    background: #F7F7FA;
    border-radius: 10px;
    border: 1px solid #e6e8f0;
    font-size: 14px !important;
    padding: 15px 12px !important;
}
button.btn-add-wallet {
    background-color: #33319A;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
}
button.btn-add-wallet:hover {
    background-color: #05B942;
}
 
/* Points box */
.points-box {
    border: 1px solid #E3E3EF;
    background-color: #F7F7FA;
    border-radius: 14px;
    padding: 10px 15px;
}
span.conversion-rate {
    font-size: 14px;
    font-weight: 400;
    color: #6B7280;
}
span.pts {
    font-size: 14px;
    color: #130E2B;
    font-weight: 400;
}
/* Progress */
.progress-track {
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    margin: 6px 0;
}
 
.progress-fill {
    height: 100%;
    border-radius: 10px;
}
 
.progress-fill.green {
    background:#24C15A;
}
button.btn-redeem-point {
    background-color: #E4E5ED;
    color: #130E2B;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
}
button.btn-redeem-point:hover {
    background-color: #33319A;
    color: #fff;
}
 
/* Divider */
.wallet-divider {
    height: 1px;
    background: rgba(255,255,255,0.2);
}
.permium-member-btn {
    background-color: #FFFFFF1A;
    font-size: 14px;
    color: #FFFFFF;
    font-weight: 400;
    padding: 10px 10px;
}
p.member-since {
    font-size: 12px;
    font-weight: 400;
    color: #FFFFFF;
}
p.wallet-balance {
    font-size: 14px;
    font-weight: 400;
    color: #FFFFFF;
    margin-bottom: 0px;
}
.cherry-points-total {
    font-size: 24px;
    font-weight: 400;
    color: #FFFFFF;
    margin: 7px 0;
}
 
.wallet-amount {
  color: #fff;
  font-weight: 600;
}

.locked-info {
  background: #f1f2f6;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 500;
}
</style>
