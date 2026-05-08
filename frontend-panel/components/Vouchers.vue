<template>

  <div class="container-fluid">
    <transition name="fade" mode="out-in">
      <div class="spinner-wrapper flex" v-if="fetchingVoucherData">
        <spinner :radius="100"/>
      </div>
    </transition>

    <div class="card-box p-4 mb-4 d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1 order-hrading">My Vouchers</h5>
            <small class="text-muted">Manage your discount vouchers and gift codes</small>
        </div>
        <select v-model="filterType" class="sort-select">
          <option value="active">Active</option>
          <option value="expired">Expired</option>
          <option value="all">All</option>
        </select>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
          <div class="voucher-stat position-relative">
              <div class="stat-icon"><i class="fa-solid fa-ticket"
                      style="color: rgb(82, 90, 107);"></i></div>
              <small>TOTAL VOUCHERS</small>
              <h5 class="total-vouchers">{{ totalVouchers }}</h5>
              <span class="text-muted small">Active & Expired</span>
          </div>
      </div>
      <div class="col-md-4">
          <div class="voucher-stat position-relative">
              <div class="stat-icon"><i class="fa-solid fa-circle-check"
                      style="color: rgb(82, 90, 107);"></i></div>

              <small>ACTIVE VOUCHERS</small>
              <h5 class="total-vouchers">{{ activeVouchers }}</h5>
              <span class="text-muted small">Ready to use</span>
          </div>
      </div>
      <div class="col-md-4">
          <div class="voucher-stat position-relative">
              <div class="stat-icon"><i class="fa-solid fa-euro-sign"
                      style="color: rgb(82, 90, 107);"></i></div>

              <small>TOTAL SAVED</small>
              <h5 class="total-vouchers"> {{ currencyIcon }}{{ totalSaved }}</h5>
              <span class="text-muted small">Lifetime savings</span>
          </div>
      </div>
    </div>

    <div class="card-box voucher-box p-4">
        <h6 class="mb-3">Apply Voucher Code</h6>
        <div class="voucher-form mb-2">
            <input type="text" class="voucher-input" placeholder="Enter voucher code">
            <button class="apply-btn">Apply Code</button>
        </div>
        <small class="text-muted d-block">
            Enter a valid voucher code to add it to your account
        </small>
    </div>
    
    <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
        <h6 class="mb-0">
          {{ filterType === 'active' ? 'Active Vouchers' :
            filterType === 'expired' ? 'Expired Vouchers' :
            'All Vouchers' }}
        </h6>

        <div class="d-flex align-items-center gap-2">
            <small class="text-muted">Sort by:</small>
            <select v-model="sortBy" class="sort-select" :disabled="filterType === 'expired'">
              <option value="high_discount">Discount High → Low</option>
              <option value="low_discount">Discount Low → High</option>
              <option value="expiring_soon">Expiring Soon</option>
              <option value="best_value">Best Value</option>
            </select>
        </div>
    </div>
    <div v-if="filterType !== 'expired' && !filteredActiveList.length" class="text-muted mt-3">
      No active vouchers
    </div>
    <div v-if="filterType !== 'expired'">
        <div v-for="item in filteredActiveList" :key="item.id" class="voucher-card mt-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="d-flex gap-2">
                        <h6 class="off-next-purchase">{{ item.title }}</h6>
                        <span class="btn-active" v-if="getDaysLeft(item) !== 'Expired'">
                          ACTIVE
                        </span>
                    </div>
                    <p class="text-muted small mt-1 mb-3">
                        Valid on orders above {{ currencyIcon + item.min_spend }}. Cannot be combined with other offers.
                    </p>
                    <div class="d-flex gap-5 flex-wrap expire-boxed">
                        <div>
                            <small class="text-muted">Code</small><br>
                            <div class="code-box">
                                {{ item.code }}
                              </div>
                              <span 
                                class="copy-icon-wrapper"
                                @click="copyCode(item)"
                              >
                                <i class="fa-solid fa-copy"></i>

                                <span class="tooltip-text">
                                  {{ copiedVouchedId === item.id ? 'Copied!' : 'Copy' }}
                                </span>
                              </span>
                        </div>
                        <div>
                            <small class="text-muted">Discount</small><br>
                            <strong>{{ item.type === 2 ? item.price + '%' : currencyIcon + item.price }}</strong>
                        </div>
                        <div>
                            <small class="text-muted">Expires</small><br>
                            <strong>{{ item.end_time }}</strong>
                        </div>
                    </div>
                </div>
                <button class="btn-use-now">Use Now</button>
            </div>

            <div class="voucher-progress-wrapper mt-3">
                <div class="voucher-progress">
                    <div class="voucher-fill" :style="{ width: getProgress(item) + '%', background: getProgressColor(item) }"></div>
                </div>
                <span class="days-left">{{ getDaysLeft(item) }}</span>
            </div>
        </div>
    </div>
    <div class="mt-3" v-if="filterType !== 'active'">
      <h6>Expired Vouchers</h6>
    </div>
    <div v-if="filterType !== 'active' && !expiredList.length" class="text-muted mt-3">
      No expired vouchers
    </div>
    <div v-if="filterType !== 'active'" v-for="item in expiredList" :key="item.id" class="voucher-card expired-voucher mt-3">
      <div class="d-flex justify-content-between align-items-start flex-wrap">
          <div>
              <strong>{{ item.title }}</strong>
              <span class="badge-expired ms-2">EXPIRED</span>
              <p class="text-muted small mt-1 mb-3">
                  Welcome discount for new customers. Valid on first purchase only.
              </p>
              <div class="d-flex gap-5 flex-wrap expire-boxed">
                  <div>
                      <small class="text-muted">Code</small><br>
                      <div class="code-box">{{ item.code }}</div>
                  </div>
                  <div>
                      <small class="text-muted">Discount</small><br>
                      <strong>{{ item.type === 2 ? item.price + '%' : currencyIcon + item.price }}</strong>
                  </div>
                  <div>
                      <small class="text-muted">Expired On</small><br>
                      <strong>{{ item.end_time }}</strong>
                  </div>
              </div>
          </div>
          <button class="btn btn-light btn-sm" disabled>Expired</button>
      </div>
    </div>

    <div class="card-box p-4 mb-4 mt-4">
      <div class="mb-4">
          <h6 class="mb-1">Gift a Voucher</h6>
          <small class="text-muted">
              Purchase a gift voucher for friends and family. You can use your Savers Club balance to
              buy vouchers.
          </small>
      </div>

      <div class="row g-3">
        <div
          class="col-md-4"
          v-for="voucher in giftVouchers"
          :key="voucher.id"

        >
          <div class="voucher-card">

            <div class="voucher-img">
              <lazy-image :data-src="getImageURL(voucher.image)" :alt="voucher.title" :title="voucher.title" />
            </div>

            <div class="voucher-body">

              <h6 class="voucher-title">
                {{ voucher.title }}
              </h6>

              <p class="voucher-desc">
                {{ voucher.description }}
              </p>

              <div class="voucher-amounts mb-2">
                <button
                  v-for="amt in voucher.amounts"
                  :key="amt"
                  @click="selectAmount(voucher.id, amt)"
                  :class="[
                    'amount-chip',
                    { active: selectedVoucherAmounts[voucher.id] === amt }
                  ]"
                >
                  {{ currencyIcon }}{{ amt }}
                </button>
              </div>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="qty-box">
                  <button @click="decreaseQty(voucher)">-</button>
                  <span>
                    {{ selectedVoucherQty[voucher.id] || voucher.min_quantity }}
                  </span>
                  <button @click="increaseQty(voucher)">+</button>
                </div>
                <small class="text-muted">
                  Min: {{ voucher.min_quantity }} | Max: {{ voucher.max_quantity }}
                </small>
              </div>
              <div class="payment-method mb-2">
                <label>
                  <input type="radio"
                    :name="'payment_'+voucher.id"
                    value="wallet"
                    v-model="paymentMethod[voucher.id]"
                    :disabled="(wallet?.balance || 0) < getVoucherTotal(voucher)"
                  >
                  Wallet (Available: {{ currencyIcon }}{{ wallet?.balance || 0 }})
                </label>

                <label class="ml-10">
                  <input type="radio"
                    :name="'payment_'+voucher.id"
                    value="sumup"
                    v-model="paymentMethod[voucher.id]"
                  >
                  Card
                </label>
              </div>
              <button
                class="btn btn-purchase w-100"
                :disabled="!selectedVoucherAmounts[voucher.id]"
                @click="purchaseVoucher(voucher)"
              >
                Purchase Now
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- <div class="row g-3" v-if="giftVouchers.length">
          <div class="col-md-4" v-for="voucher in giftVouchers" :key="voucher.id">
              <div class="gift-card text-center">
                  <h4 class="gift-voucher-heading ">€25</h4>
                  <small class="gift-voucher-subheading d-block mb-3">Gift Voucher</small>

                  <button class="btn-purchase w-100">Purchase</button>
              </div>
          </div>
          <div class="col-md-4">
              <div class="gift-card text-center">
                  <h4 class="gift-voucher-heading ">€50</h4>
                  <small class="gift-voucher-subheading d-block mb-3">Gift Voucher</small>

                  <button class="btn-purchase w-100">Purchase</button>
              </div>
          </div>
          <div class="col-md-4">
              <div class="gift-card text-center">
                  <h4 class="gift-voucher-heading ">€100</h4>
                  <small class="gift-voucher-subheading d-block mb-3">Gift Voucher</small>

                  <button class="btn-purchase w-100">Purchase</button>
              </div>
          </div>
      </div> -->
    </div>

    <div class="voucher-card p-4 mb-4">
        <h6 class="mb-3  voucher-terms-heading">Voucher Terms & Conditions</h6>
        <ul class="vouchers-terms-list">
            <li>Vouchers are valid for single use only unless otherwise specified</li>
            <li>Multiple vouchers cannot be combined in a single transaction</li>
            <li>Vouchers cannot be exchanged for cash or credit</li>
            <li>Check individual voucher terms for specific restrictions and minimum purchase
                requirements</li>
            <li>Expired vouchers cannot be reactivated or extended</li>
            <li>Gift vouchers purchased with Savers Club balance are non-refundable</li>
        </ul>
    </div>

    <!-- <div
      v-if="voucherList && !voucherList.length"
      class="info-msg"
    >
      {{ $t('vouchers.noVoucher') }}
    </div>

    <div class="flex wrap start align-start">
      <div
        class="card p-15 pt-10 pb-5 mb-15"
        v-for="(value, index) in voucherList"
        :key="index"
      >
        <div class="flex sided gap-15">
          <h5 class="semi-bold mx-w-400x mb-5">
            {{ value.title }}
          </h5>
          <h4 class="semi-bold mb-5">
            {{ getPriceType(value) }}
          </h4>
        </div>
        <div class="flex sided f-9">
          <h6 class="semi-bold voucher mb-5">{{ value.code }}</h6>
          <button
            aria-label="submit"
            @click.prevent="copyTpClipboard(value)"
            class="lite-btn mb-5"
          >
            {{ copiedVouchedId === value.id ? $t('filter.copied') : $t('filter.copy') }}
          </button>
        </div>

        <div class="flex sided f-9 gap-15">
          <p class="mb-5 color-lite">
              <span class=" mr-5">
                {{ $t('vouchers.minSpend') }}
              </span>
            <b>
              <price-format
                :price="value.min_spend"
              />
            </b>
          </p>
          <p class="f-9 mb-5 color-danger">
              <span class="mr-5">
                {{ $t('vouchers.valid') }}
              </span>
            {{ value.end_time }}
          </p>
        </div>
      </div>
    </div> -->
    <div class="flow-hidden">
      <pagination
        ref="voucherPagination"
        :total-page="totalPage"
        :page="currentPage"
        :changing-route="changingRoute"
        @fetching-data="fetchingData"
      />
    </div>
  </div>

</template>

<script>
  import util from '~/mixin/util'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import productHelper from '~/mixin/productHelper'
  import {mapGetters, mapActions} from 'vuex'
  import Pagination from "./Pagination";
  import Spinner from "./Spinner";
  import PriceFormat from "./PriceFormat";

  export default {
    name: 'Vouchers',
    data() {
      return {
        fetchingVoucherData: false,
        copiedVouchedId: '',
        sortBy: 'expiring_soon',
        filterType: 'all',
        giftVouchers: [],
        selectedVoucherAmounts: {},
        selectedVoucherQty: {},
        paymentMethod: {},
        wallet: {},
      }
    },
    watch: {
      selectedVoucherAmounts: {
        deep: true,
        handler() {
          this.checkWalletAvailability()
        }
      },
      selectedVoucherQty: {
        deep: true,
        handler() {
          this.checkWalletAvailability()
        }
      }
    },
    props: {
      changingRoute: {
        type: Boolean,
        default: true
      }
    },
    components: {
      PriceFormat,
      Spinner,
      Pagination
    },
    computed: {
      filteredActiveList() {
        let list = [...this.activeList]

        if (this.sortBy === 'high_discount') {
          list.sort((a, b) => Number(b.price) - Number(a.price))
        }

        if (this.sortBy === 'low_discount') {
          list.sort((a, b) => Number(a.price) - Number(b.price))
        }

        if (this.sortBy === 'expiring_soon') {
          list.sort((a, b) => {
            return this.getDaysLeftNumber(a) - this.getDaysLeftNumber(b)
          })
        }

        if (this.sortBy === 'best_value') {
          list.sort((a, b) => {
            const scoreA = Number(a.price) * this.getDaysLeftNumber(a)
            const scoreB = Number(b.price) * this.getDaysLeftNumber(b)
            return scoreB - scoreA
          })
        }

        return list
      },
      activeList() {
        const now = new Date()
        return this.voucherList.filter(v => {
          const end = this.parseDate(v.end_time)
          return end && end >= now
        })
      },
      expiredList() {
        const now = new Date()
        return this.voucherList.filter(v => {
          const end = this.parseDate(v.end_time)
          return !end || end < now
        })
      },
      currentPage() {
        return this.vouchers?.current_page
      },
      totalPage() {
        return this.vouchers?.last_page
      },
      voucherList() {
        return this.vouchers?.data || []
      },
      totalVouchers() {
        return this.voucherList.length
      },
      activeVouchers() {
        return this.activeList.length
      },
      totalSaved() {
        return this.voucherList.reduce((sum, v) => {
          return sum + Number(v.price || 0)
        }, 0)
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
      ...mapGetters('user', ['vouchers']),
    },
    mixins: [util, routeParamHelper, productHelper],
    methods: {
      ...mapActions('common', ['setToastMessage', 'setToastError']),
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
      checkWalletAvailability() {
        this.giftVouchers.forEach(voucher => {
          const total = this.getVoucherTotal(voucher)

          if ((this.wallet?.balance || 0) < total) {
            this.$set(this.paymentMethod, voucher.id, 'sumup')
          }
        })
      },
      async purchaseVoucher(voucher) {

        const amount = this.selectedVoucherAmounts[voucher.id]
        const qty = this.selectedVoucherQty[voucher.id] || voucher.min_quantity
        const method = this.paymentMethod[voucher.id] || 'wallet'

        if (!amount) {
          this.setToastError('Select amount')
          return
        }

        if (qty < voucher.min_quantity || qty > voucher.max_quantity) {
          this.setToastError('Invalid quantity')
          return
        }

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          if(method === 'sumup'){
            const res = await this.$axios.post(`${baseUrl}api/create-sumup-checkout`, {
              voucher_id: voucher.id,
              amount: amount * qty,
              quantity: qty,
              payment_method: method,
              currency: 'EUR',
              is_gift_voucher: true,
            })

            const { id, checkout_reference } = res.data

            this.$router.push({
              path: '/user/topup-checkout',
              query: {
                checkoutId: id,
                checkoutReference: checkout_reference
              }
            })
          }else{
            const res = await this.$axios.post(`${baseUrl}api/v1/user/purchase-gift-voucher`, {
              voucher_id: voucher.id,
              amount: amount * qty,
              quantity: qty,
              payment_method: method,
              currency: 'EUR',
              is_gift_voucher: true
            })

            this.setToastMessage('Voucher purchased successfully')

            this.$delete(this.selectedVoucherAmounts, voucher.id)
            this.$set(this.selectedVoucherQty, voucher.id, voucher.min_quantity)
            this.$set(this.paymentMethod, voucher.id, 'wallet')
            await this.fetchWallet()
          }

        } catch (e) {
          this.setToastError(e.response?.data?.message || 'Purchase failed')
        }
      },
      getVoucherTotal(voucher) {
        const amount = this.selectedVoucherAmounts[voucher.id] || 0
        const qty = this.selectedVoucherQty[voucher.id] || voucher.min_quantity
        return amount * qty
      },
      selectAmount(voucherId, amount) {
        this.$set(this.selectedVoucherAmounts, voucherId, amount)
      },

      increaseQty(voucher) {
        let current = this.selectedVoucherQty[voucher.id] || voucher.min_quantity

        if (current < voucher.max_quantity) {
          this.$set(this.selectedVoucherQty, voucher.id, current + 1)
        }
      },

      decreaseQty(voucher) {
        let current = this.selectedVoucherQty[voucher.id] || voucher.min_quantity

        if (current > voucher.min_quantity) {
          this.$set(this.selectedVoucherQty, voucher.id, current - 1)
        }
      },
      async fetchGiftVouchers() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const res = await this.$axios.get(`${baseUrl}api/v1/user/gift-vouchers`)
        this.giftVouchers = res.data.data || []
        this.giftVouchers.forEach(v => {
          this.$set(this.paymentMethod, v.id, 'wallet')
        })
      },
      parseDate(dateStr) {
        if (!dateStr) return null
        return new Date(dateStr.replace(',', '').replace(/\b(\d{2})$/, '20$1'))
      },
      getProgressColor(item) {
        const days = this.getDaysLeftNumber(item)
        if (days < 5) return '#dc3545'
        if (days < 10) return '#ffc107'
        return '#28a745'
      },
      getProgress(item) {
        const start = this.parseDate(item.start_time)
        const end = this.parseDate(item.end_time)
        const now = new Date()

        if (!start || !end) return 0

        const total = end - start
        const passed = now - start

        if (total <= 0) return 0

        return Math.min(Math.max((passed / total) * 100, 0), 100)
      },
      getDaysLeftNumber(item) {
        const end = this.parseDate(item.end_time)
        const now = new Date()
        if (!end || end < now) return 0
        const days = Math.ceil((end - now) / (1000 * 60 * 60 * 24))
        return days;
      },
      getDaysLeft(item) {
        const end = this.parseDate(item.end_time)
        const now = new Date()
        if (!end || end < now) return 'Expired'
        const days = Math.ceil((end - now) / (1000 * 60 * 60 * 24))
        return `${days} days left`
      },
      copyCode(item) {
        navigator.clipboard.writeText(item.code)

        this.copiedVouchedId = item.id

        setTimeout(() => {
          this.copiedVouchedId = ''
        }, 2000)
      },
      loadData() {
        this.$refs.voucherPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingVoucherData = true
        setTimeout(async () => {
          try {
            this.settingRouteParam()
            const data = await this.userVouchers({
              params: {
                time_zone: this.timeZone,
                order_by: this.orderBy,
                type: this.orderByType,
                page: this.page,
                q: this.search
              },
              lang: this.langCode,
            })
            if (data?.status === 201) {
              this.hasError(data)
            }
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.fetchingVoucherData = false
        }, 100)
      },
      ...mapActions('user', ['userVouchers', 'user']),
    },
    created() {
    },
    async mounted() {
      if (!this.vouchers) {
        await this.fetchingData()
      }
      await this.fetchWallet()
      await this.fetchGiftVouchers()
    }
  }
</script>
<style scoped>
.card-box {
    background: #fff;
    border-radius: 15px;
    border: 1px solid #e6e8f0;
}

/* Stats */
.voucher-stat {
    background: #fff;
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 15px;
    position: relative;
}

button.btn-Filter {
    background-color: #F7F7FA;
    border: none;
    font-weight: 400;
    font-size: 14px;
    color: #6B7280;
    padding: 0px 20px;
}

/* ICON TOP RIGHT */
.stat-icon {
    position: absolute;
    top: 12px;
    right: 12px;
    font-size: 16px;

    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.voucher-stat {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 15px;
}

.voucher-stat small {
    font-size: 12px;
    color: #888;
}

.voucher-stat h5 {
    margin: 5px 0;
}

/* Input */
.voucher-input {
    background: #f7f8fc;
    border: 1px solid #e6e8f0;
    border-radius: 10px;
    padding: 10px;
}

/* Wrapper */
.voucher-box {
    border-radius: 14px;
    border: 1px solid #E3E3EF;
}

/* Form layout */
.voucher-form {
    display: flex;
    gap: 12px;
}

h5.total-vouchers {
    font-size: 24px;
    color: #171717;
    font-weight: 400;
}

/* Input */
.voucher-input {
    flex: 1;
    height: 44px;
    background: #F7F7FA;
    border: 1px solid #E3E3EF;
    border-radius: 10px;
    padding: 0 15px;
    font-size: 14px;
    outline: none;
    color: #6B7280;
    font-weight: 400;
}

/* Button */
.apply-btn {
    padding: 0px 22px;
    background: #05B942;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    white-space: nowrap;
    transition: 0.2s;
    font-weight: 400;
}

.apply-btn:hover {
    background: #33319A;
}

/* Voucher card */
.voucher-card {
    border: 1px solid #e6e8f0;
    border-radius: 12px;
    padding: 18px;
    background: #fff;
    transition: all 0.25s ease;
}

/* ACTIVE badge */
.badge-active {
    background: #eef0f6;
    color: #333;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Code box */
.code-box {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f7f8fc;
    border: 1px solid #e6e8f0;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 13px;
    margin-right: 5px;
}

.copy-icon {
    cursor: pointer;
}

/* Progress */
.voucher-progress {
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.voucher-fill {
    height: 100%;
}

/* Sort dropdown */
.sort-select {
    border: 1px solid #E3E3EF;
    border-radius: 8px;
    padding: 9px 12px;
    background: #fff;
    font-size: 12px;
    font-weight: 400;
}


/* Expired card style */
.expired-voucher {
    opacity: 0.6;
    filter: grayscale(50%);
    pointer-events: none;
    /* disables clicks */
}

/* Expired badge */
.badge-expired {
    background: #e6e8f0;
    color: #777;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
}

/* Button style */
.expired-voucher .btn {
    background: #e6e8f0;
    color: #777;
    border: none;
    padding: 0px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
}

/* Optional: remove pointer block for button only */
.expired-voucher .btn {
    pointer-events: auto;
}

button.btn-use-now {
    background-color: #33319A;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0px 20px;
    font-size: 14px;
    font-weight: 400;
}

button.btn-use-now:hover {
    background-color: #05B942;
}

h6.off-next-purchase {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}

span.btn-active {
    border-radius: 4px;
    background-color: #F5F5F5;
    font-size: 10px;
    font-weight: 400;
    color: #130E2B;
    padding: 7px 10px;
    margin-bottom: 0px;
    height: 29px;
}

/* Wrapper for alignment */
.voucher-progress-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Progress bar full width */
.voucher-progress {
    flex: 1;
    height: 6px;
    background: #e6e8f0;
    border-radius: 10px;
    overflow: hidden;
}

/* Fill */
.voucher-fill {
    height: 100%;
    border-radius: 10px;
}

/* Text right */
.days-left {
    font-size: 12px;
    color: #888;
    white-space: nowrap;
}

.expire-boxed {
    gap: 118px !important;
}

.gift-card {
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 25px 20px;
}

button.btn-purchase {
    background-color: #33319A;
    border-radius: 4px;
    border: none;
    color: #fff;
    font-size: 14px;
    font-weight: 400;
}

button.btn-purchase:hover {
    background-color: #05B942;
}

h4.gift-voucher-heading {
    font-size: 24px;
    color: #130E2B;
    font-weight: 400;
}

small.gift-voucher-subheading {
    font-size: 12px;
    color: #6B7280;
    font-weight: 400;
}

h6.voucher-terms-heading {
    font-size: 18px;
    font-weight: 400;
    color: #130E2B;
}

ul.vouchers-terms-list {
    padding-left: 18px;
    color: #6B7280;
    font-size: 14px;
    line-height: 20px;
    font-weight: 400;
    margin-bottom: 0px;
}

ul.vouchers-terms-list li{
  display: list-item;
  list-style: disc;
}

.copy-icon-wrapper {
  position: relative;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

/* Tooltip */
.tooltip-text {
  position: absolute;
  bottom: 130%;
  left: 50%;
  transform: translateX(-50%);
  background: #130E2B;
  color: #fff;
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 6px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: 0.2s;
}

/* Show on hover */
.copy-icon-wrapper:hover .tooltip-text {
  opacity: 1;
}

/* Small arrow */
.tooltip-text::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border-width: 5px;
  border-style: solid;
  border-color: #130E2B transparent transparent transparent;
}

.voucher-card {
  border: 1px solid #e6e8f0;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  transition: 0.2s;
}

.voucher-card:hover {
  transform: translateY(-3px);
  border-color: #4b49ac;
}

/* Image */
.voucher-img img {
  width: 100%;
  height: 160px;
  object-fit: contain;
}

/* Body */
.voucher-body {
  padding: 12px;
}

.voucher-title {
  font-size: 14px;
  font-weight: 600;
}

.voucher-desc {
  font-size: 12px;
  color: #666;
  margin-bottom: 10px;
}

/* Amount chips */
.voucher-amounts {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.amount-chip {
  border: 1px solid #4b49ac;
  background: transparent;
  color: #4b49ac;
  border-radius: 8px;
  padding: 0px 15px;
  font-size: 12px;
  cursor: pointer;
}

.amount-chip:hover {
  background: #4b49ac;
  color: #fff;
}
.amount-chip.active {
  background: #4b49ac;
  color: #fff;
}

.qty-box {
  display: flex;
  align-items: center;
  gap: 10px;
}

.qty-box button {
  width: 40px;
  border: none;
  background: #eef0f6;
  border-radius: 6px;
}

.qty-box span {
  min-width: 20px;
  text-align: center;
}
</style>

