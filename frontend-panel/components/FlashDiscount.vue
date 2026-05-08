<template>
<div v-if="flash?.active && selectedProducts.length" class="discount-box p-3 position-relative overflow-hidden">
<div class="blob blob-left">
</div>

<!-- RIGHT BLOB -->
<div class="blob blob-right">
</div>

<!-- CONTENT (same as yours) -->
<div class="d-flex justify-content-between align-items-start mb-3 position-relative z-1">

<div class="d-flex gap-2 align-items-start">
  <i class="fa-solid fa-bolt"></i>
  <div>
    <strong>Flash Discount Active!</strong><br>
    <small>Additional {{ flash.value }}% OFF upto {{ flash.max_discount }}</small>
    </br>
    <small>Minimum {{ flash.min_cart_value }} cart amount required</small>
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
    <span>Total Savings</span>
    <span>€{{ savings.toFixed(2) }}</span>
  </div>

  <div class="progress">
    <div class="progress-bar" :style="{ width: progressPercent + '%' }"></div>
  </div>
</div>

<button class="btn btn-discount" :disabled="isApplied" @click="applyFlashDiscount">
  <i class="fa-solid fa-rocket"></i>
  <span v-if="isApplied">
    Applied ✔
  </span>
  <span v-else>
    Checkout & Save €{{ savings.toFixed(2) }}
  </span>
</button>

</div>
</div>
</template>
<script>
import {mapGetters, mapActions} from 'vuex'
export default {
  name: 'FlashDiscount',
  data() {
    return {
      flash: null,
      timeLeft: '',
      interval: null,
      isApplied: false
    }
  },
  computed: {
    selectedProducts() {
      return this.cartProducts.filter(p => parseInt(p.selected) === 1)
    },
    cartProducts() {
      return this.$store.state.cart.cartProducts || []
    },
    cartTotal() {
      return this.selectedProducts.reduce((sum, item) => {
        const p = item.flash_product || {}
        const price = Number(p.offered) > 0 ? Number(p.offered) : Number(p.selling || 0)
        return sum + item.quantity * price
      }, 0)
    },
    savings() {
      if (!this.flash?.active) return 0
      if (this.flash.type === 'percentage') {
        return (this.cartTotal * this.flash.value) / 100
      }
      return Number(this.flash.value || 0)
    },
    progressPercent() {
      if (!this.cartTotal) return 0
      return (this.savings / this.cartTotal) * 100
    }
  },
  async mounted() {
    await this.loadFlash()
  },
  beforeDestroy() {
    if (this.interval) clearInterval(this.interval)
  },
  methods: {
    ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError']),
    calculateFlashDiscount() {
      if (!this.flash?.active) {
        this.clearFlash()
        this.isApplied = false
        return
      }

      const baseTotal = this.cartTotal

      if (!this.selectedProducts.length) {
        this.clearFlash()
        this.isApplied = false
        return
      }

      if (this.flash.min_cart_value && baseTotal < this.flash.min_cart_value) {
        this.clearFlash()
        this.isApplied = false
        return
      }

      const now = new Date()
      const start = new Date(this.flash.start_time)
      const end = new Date(this.flash.end_time)

      if (now < start || now > end) {
        this.clearFlash()
        this.isApplied = false
        return
      }

      let discount = this.flash.type === 'percentage'
        ? (baseTotal * this.flash.value) / 100
        : this.flash.value

      if (this.flash.max_discount) {
        discount = Math.min(discount, this.flash.max_discount)
      }

      this.$store.commit('cart/SET_FLASH_DISCOUNT', {
        amount: discount
      })

      this.isApplied = true
    },
    clearFlash() {
      this.$store.commit('cart/SET_FLASH_DISCOUNT', { amount: 0 })
    },
    applyFlashDiscount() {
      if (!this.flash?.active || this.isApplied) return

      const baseTotal = this.cartTotal

      if (!this.selectedProducts.length) {
        this.clearFlash()
        this.isApplied = false
        this.setToastError('Please select at least one product');
        return
      }

      if (this.flash.min_cart_value && baseTotal < this.flash.min_cart_value) {
        this.clearFlash()
        this.isApplied = false
        this.setToastError(`Minimum €${this.flash.min_cart_value} required to apply discount`);
        return
      }

      const now = new Date()
      const start = new Date(this.flash.start_time)
      const end = new Date(this.flash.end_time)

      if (now < start || now > end) {
        this.clearFlash()
        this.isApplied = false
        this.setToastError(`Flash discount is not active`);
        return
      }

      let discount = this.flash.type === 'percentage'
        ? (baseTotal * this.flash.value) / 100
        : this.flash.value

      if (this.flash.max_discount) {
        discount = Math.min(discount, this.flash.max_discount)
      }

      this.$store.commit('cart/SET_FLASH_DISCOUNT', {
        amount: discount
      })

      this.isApplied = true 

      this.setToastMessage('Discount applied successfully!');
    },
    async loadFlash() {
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const res = await this.$axios.get(`${baseUrl}api/v1/flash-discount`)
        this.flash = res.data
        if (this.flash?.active) {
          this.startTimer()
          this.calculateFlashDiscount()
        }
      } catch (e) {
        console.error('Flash API error:', e)
      }
    },
    startTimer() {
      const end = new Date(this.flash.end_time).getTime()
      this.interval = setInterval(() => {
        const diff = end - Date.now()
        if (diff <= 0) {
          clearInterval(this.interval)
          this.flash.active = false
          this.timeLeft = '00:00:00'
          return
        }
        const h = String(Math.floor(diff / 3600000)).padStart(2, '0')
        const m = String(Math.floor((diff % 3600000) / 60000)).padStart(2, '0')
        const s = String(Math.floor((diff % 60000) / 1000)).padStart(2, '0')
        this.timeLeft = `${h}:${m}:${s}`
      }, 1000)
    }
  },
  watch: {
    selectedProducts: {
      handler() {
        this.calculateFlashDiscount()
      },
      deep: true
    },
    cartTotal() {
      this.calculateFlashDiscount()
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
   BUTTON
========================= */
.btn-discount {
  background: #ff4d4d;
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

.btn-discount:hover {
  background: #e63c3c;
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

  .btn-discount {
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

  .btn-discount {
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
</style>