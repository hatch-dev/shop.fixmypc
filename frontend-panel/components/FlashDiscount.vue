<template>
<div v-if="showBanner" class="discount-box p-3 position-relative overflow-hidden">
<div class="blob blob-left"></div>
<div class="blob blob-right"></div>

<div class="d-flex justify-content-between align-items-start mb-3 position-relative z-1">

  <div class="d-flex gap-2 align-items-start">
    <i class="fa-solid fa-bolt"></i>
    <div>
      <strong>{{ applied ? 'Flash Discount Applied!' : 'Flash Discount Available!' }}</strong><br>

      <!--
        Percentage and fixed read differently, and the old copy printed both
        as "N% OFF" -- a EUR 20 fixed discount was advertised as 20% off.
      -->
      <small v-if="isPercentage">
        Additional {{ promotion.value }}% off<template v-if="cap">
          up to <price-format :price="cap" :decimals="2"/></template>
      </small>
      <small v-else>
        <price-format :price="fixedValue" :decimals="2"/> off your order
      </small>

      <template v-if="minCartValue > 0">
        <br>
        <small>
          Minimum <price-format :price="minCartValue" :decimals="2"/> cart amount required
        </small>
      </template>
    </div>
  </div>

  <div class="text-end small">
    <div>Expires in</div>
    <strong>{{ timeLeft }}</strong>
  </div>

</div>

<div class="nav-links d-flex gap-3 align-items-center position-relative z-1">

  <div class="progress-box flex-grow-1">
    <div class="d-flex justify-content-between small mb-1">
      <span>{{ progressLabel }}</span>
      <span><price-format :price="applied ? amount : offer" :decimals="2"/></span>
    </div>

    <div class="progress">
      <div class="progress-bar" :style="{ width: progressPercent + '%' }"></div>
    </div>
  </div>

  <!--
    The discount is offered, not imposed. Nothing comes off the basket, and
    the order is not told to discount anything, until this is pressed.
  -->
  <button
    v-if="eligible && !applied"
    type="button"
    class="status-pill offer"
    @click="apply"
  >
    <i class="fa-solid fa-rocket"></i>
    <span>Apply and save <price-format :price="offer" :decimals="2"/></span>
  </button>

  <button
    v-else-if="applied"
    type="button"
    class="status-pill applied"
    @click="remove"
  >
    <i class="fa-solid fa-circle-check"></i>
    <span>Saving <price-format :price="amount" :decimals="2"/> &middot; Remove</span>
  </button>

  <div v-else class="status-pill shortfall">
    <i class="fa-solid fa-cart-plus"></i>
    <span v-if="!selectedProducts.length">Select an item to start saving</span>
    <span v-else>
      Add <price-format :price="shortfall" :decimals="2"/> more to unlock this discount
    </span>
  </div>

</div>
</div>
</template>

<script>
import { mapGetters, mapState } from 'vuex'

export default {
  name: 'FlashDiscount',
  data() {
    return {
      interval: null,
      /*
       * A reactive clock, so the banner closes itself the moment the window
       * does rather than waiting for a navigation.
       */
      now: Date.now()
    }
  },
  computed: {
    ...mapState('cart', {
      promotion: state => state.flashPromotion,
      applied: state => state.flashDiscountOptIn
    }),
    /*
     * Every figure comes from the store, so the banner, the Order Summary and
     * the checkout cannot disagree -- they read the same getters, and the
     * order endpoint recomputes the amount from the same rule.
     */
    ...mapGetters('cart', {
      selectedProducts: 'selectedCartProducts',
      cartTotal: 'selectedGoodsTotal',
      isLive: 'flashPromotionLive',
      minCartValue: 'flashMinCartValue',
      cap: 'flashCap',
      eligible: 'flashDiscountEligible',
      offer: 'flashDiscountOffer',
      amount: 'flashDiscountAmount',
      shortfall: 'flashDiscountShortfall'
    }),
    isPercentage() {
      return !!this.promotion && this.promotion.type === 'percentage'
    },
    fixedValue() {
      return Number((this.promotion && this.promotion.value) || 0)
    },
    showBanner() {
      // `now` is read so the banner re-evaluates as the clock moves.
      return this.now > 0 && this.isLive && this.selectedProducts.length > 0
    },
    progressLabel() {
      if (this.applied) return 'Total savings'

      return this.eligible ? 'Available saving' : 'Progress to minimum'
    },
    /*
     * Progress towards the minimum the basket has to clear. It used to plot
     * savings over basket total, which for a percentage discount is the
     * percentage itself -- a bar that read 15% whatever was in the basket.
     */
    progressPercent() {
      if (!this.minCartValue) return this.eligible ? 100 : 0

      return Math.min(100, (this.cartTotal / this.minCartValue) * 100)
    },
    timeLeft() {
      if (!this.promotion) return ''

      const diff = new Date(this.promotion.end_time).getTime() - this.now

      if (diff <= 0) return '00:00:00'

      const pad = n => String(n).padStart(2, '0')
      const days = Math.floor(diff / 86400000)

      // A three-day promotion counted down from "72:00:00" before.
      const hours = days ? Math.floor((diff % 86400000) / 3600000) : Math.floor(diff / 3600000)

      return (days ? days + 'd ' : '')
        + pad(hours) + ':'
        + pad(Math.floor((diff % 3600000) / 60000)) + ':'
        + pad(Math.floor((diff % 60000) / 1000))
    }
  },
  async mounted() {
    await this.$store.dispatch('cart/loadFlashPromotion')

    this.interval = setInterval(() => { this.now = Date.now() }, 1000)
  },
  beforeDestroy() {
    if (this.interval) clearInterval(this.interval)
  },
  methods: {
    apply() {
      if (!this.eligible) return

      this.$store.commit('cart/SET_FLASH_DISCOUNT_OPT_IN', true)
    },
    remove() {
      this.$store.commit('cart/SET_FLASH_DISCOUNT_OPT_IN', false)
    }
  },
  watch: {
    /*
     * A basket that stops qualifying gives the discount back.
     *
     * Without this an opt-in taken on a EUR 600 basket would still be sent to
     * the order after the shopper unticked everything down to EUR 50 -- and
     * the order would refuse it, leaving the summary promising a saving the
     * charge did not contain.
     */
    eligible(now) {
      if (!now && this.applied) this.remove()
    }
  }
}
</script>
<style scoped>
.discount-box {
  background: linear-gradient(135deg, #ff4d4d, #f59e0b);
  border-radius: 18px;
  color: #fff;
  position: relative;
}

/* =========================
   BLOBS
========================= */
.blob {
  position: absolute;
  pointer-events: none;
  z-index: 0;
}

/* LEFT BLOB */
.blob-left {
  top: -40px;
  left: -120px;
  width: 320px;
  height: 320px;
  background: rgba(255,255,255,0.08);
  border-radius: 50%;
  transform: rotate(-45deg);
}

/* RIGHT BLOB */
.blob-right {
  bottom: -60px;
  right: -80px;
  width: 260px;
  height: 260px;
  background: rgba(255,255,255,0.08);
  border-radius: 50%;
  transform: rotate(45deg);
}

/* =========================
   CONTENT LAYER
========================= */
.z-1 {
  position: relative;
  z-index: 1;
}

/* =========================
   PROGRESS BOX
========================= */
.progress-box {
  background: #fff;
  border-radius: 12px;
  padding: 12px;
  color: #111;
}

.progress {
  height: 6px;
  background: #e5e7eb;
  border-radius: 10px;
}

.progress-bar {
  background: #16a34a;
  border-radius: 10px;
}

/* =========================
   STATUS PILL
========================= */
.status-pill {
  border: 1px solid rgba(255, 255, 255, 0.35);
  color: #fff;
  border-radius: 12px;
  padding: 12px 20px;
  font-weight: 500;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 8px;
  height: 100%;
}



/* =========================
   RESPONSIVE
========================= */

/* Tablet */
@media (max-width: 992px) {
  .nav-links {
    flex-direction: column;
    align-items: stretch;
  }

  .status-pill {
    width: 100%;
    justify-content: center;
  }
}

/* Mobile */
@media (max-width: 576px) {

  .discount-box {
    padding: 15px;
  }

  .nav-links {
    flex-direction: column;
    gap: 10px;
  }

  .progress-box {
    width: 100%;
  }

  .status-pill {
    width: 100%;
    font-size: 14px;
    padding: 10px;
  }

  /* smaller blobs */
  .blob-left {
    width: 200px;
    height: 200px;
    left: -80px;
  }

  .blob-right {
    width: 180px;
    height: 180px;
    right: -60px;
  }
}

/* The call to action: the discount is not applied until this is pressed. */
.status-pill.offer {
  background: #ff4d4d;
  border-color: #ff4d4d;
  cursor: pointer;
}

.status-pill.offer:hover {
  background: #e63c3c;
}

.status-pill.applied {
  background: #16a34a;
  border-color: #16a34a;
  cursor: pointer;
}

.status-pill.applied:hover {
  background: #15803d;
}

.status-pill.shortfall {
  background: rgba(0, 0, 0, 0.18);
  border-color: rgba(255, 255, 255, 0.35);
  cursor: default;
}

.status-pill span {
  white-space: nowrap;
}
</style>