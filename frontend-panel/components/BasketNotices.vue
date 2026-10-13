<template>
  <!--
    What changed the basket without the shopper: emptied after 20 idle
    minutes, a line cut back for want of stock, guest items added at sign-in.
    The server keeps each notice until it is dismissed and sends the pending
    ones with every basket read, so it shows on whichever page is open.
  -->
  <div
    v-if="basketNotices.length"
    class="basket-notices"
    role="region"
    :aria-label="$t('basketNotices.region')"
  >
    <div
      v-for="notice in basketNotices"
      :key="notice.id"
      class="basket-notice"
      :class="`is-${notice.kind}`"
      role="status"
    >
      <p class="basket-notice-title">{{ title(notice) }}</p>
      <p class="basket-notice-body">{{ body(notice) }}</p>

      <ul v-if="lines(notice).length" class="basket-notice-lines">
        <li v-for="(line, i) in lines(notice)" :key="i">
          {{ line.quantity }} &times; {{ line.title }}
        </li>
      </ul>

      <div class="basket-notice-actions">
        <button
          v-if="notice.kind === 'expired' && lines(notice).length"
          type="button"
          class="basket-notice-btn is-primary"
          :disabled="busy === notice.id"
          @click="restore(notice)"
        >
          {{ busy === notice.id ? $t('basketNotices.addingBack') : $t('basketNotices.addBack') }}
        </button>
        <button
          type="button"
          class="basket-notice-btn"
          :disabled="busy === notice.id"
          @click="dismiss(notice)"
        >
          {{ $t('basketNotices.dismiss') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { mapActions, mapGetters } from 'vuex'

/* Pages that only make sense with the basket still there. */
const CHECKOUT_PAGES = ['/shipping', '/guest-checkout', '/checkout', '/payment']

export default {
  name: 'BasketNotices',
  data () {
    return {
      busy: null
    }
  },
  computed: {
    ...mapGetters('cart', ['basketNotices'])
  },
  watch: {
    basketNotices: 'leaveCheckoutIfEmptied',
    '$route.path': 'leaveCheckoutIfEmptied'
  },
  methods: {
    lines (notice) {
      return Array.isArray(notice.lines) ? notice.lines : []
    },
    title (notice) {
      switch (notice.kind) {
        case 'expired': return this.$t('basketNotices.expiredTitle')
        case 'merged': return this.$t('basketNotices.mergedTitle')
        default: return this.$t('basketNotices.trimmedTitle')
      }
    },
    body (notice) {
      switch (notice.kind) {
        case 'expired':
          return this.$t('basketNotices.expiredBody', { minutes: notice.minutes || 20 })
        case 'merged':
          return this.$t('basketNotices.mergedBody')
        default:
          return Number(notice.to) > 0
            ? this.$t('basketNotices.trimmedReduced', { title: notice.title, from: notice.from, to: notice.to })
            : this.$t('basketNotices.trimmedRemoved', { title: notice.title })
      }
    },
    /*
     * A basket emptied while the shopper was in checkout: the page they are on
     * is pricing, shipping or charging for lines that are gone. Take them to
     * the basket, where this notice explains it and offers to add them back.
     * The SumUp return page (checkoutId) is settling a payment already taken
     * and is left to finish.
     */
    leaveCheckoutIfEmptied () {
      if (!this.basketNotices.some(n => n.kind === 'expired')) return

      const path = this.$route.path
      const onCheckout = CHECKOUT_PAGES.some(p => path === p || path.startsWith(p + '/'))

      if (onCheckout && !this.$route.query.checkoutId) {
        this.$router.replace('/cart')
      }
    },
    dismiss (notice) {
      this.dismissBasketNotices([notice.id])
    },
    async restore (notice) {
      this.busy = notice.id

      try {
        const data = await this.restoreBasketNotice(notice.id)
        const restored = data?.data?.restored || []
        const refused = (data?.data?.refused || []).map(r => r.title)

        if (restored.length) {
          this.setToastMessage(this.$t('basketNotices.restored', { items: restored.join(', ') }))
        }

        if (refused.length) {
          this.setToastError(this.$t('basketNotices.notRestored', { items: refused.join(', ') }))
        }

        if (!restored.length && !refused.length && data?.status !== 200) {
          this.setToastError(data?.message || this.$t('basketNotices.notRestored', { items: '' }))
        }
      } catch (e) {
        this.setToastError(this.$t('basketNotices.notRestored', { items: '' }))
      } finally {
        this.busy = null
      }
    },
    ...mapActions('cart', ['dismissBasketNotices', 'restoreBasketNotice']),
    ...mapActions('common', ['setToastMessage', 'setToastError'])
  }
}
</script>

<style scoped>
.basket-notices {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 1001;
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 360px;
  max-width: calc(100vw - 32px);
  max-height: calc(100vh - 48px);
  overflow-y: auto;
}

.basket-notice {
  padding: 14px 16px;
  border: 1px solid #E4E5F0;
  border-left: 4px solid #33319A;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 8px 24px rgba(19, 14, 43, 0.12);
  color: #130E2B;
}

.basket-notice.is-expired,
.basket-notice.is-trimmed {
  border-left-color: #B45309;
}

.basket-notice-title {
  margin: 0 0 4px;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.3;
}

.basket-notice-body {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
  color: #374151;
}

.basket-notice-lines {
  margin: 8px 0 0;
  padding-left: 18px;
  font-size: 13px;
  line-height: 1.5;
  color: #130E2B;
}

.basket-notice-actions {
  display: flex;
  gap: 8px;
  margin-top: 12px;
}

/* Height and line-height are set here: base.styl gives every button 50/48. */
.basket-notice-btn {
  height: 34px;
  line-height: 32px;
  padding: 0 14px;
  border: 1px solid #D1D5DB;
  border-radius: 8px;
  background: #fff;
  color: #130E2B;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.basket-notice-btn.is-primary {
  border-color: #33319A;
  background: #33319A;
  color: #fff;
}

/* The global button hover outranks anything short of !important. */
.basket-notice-btn:hover:not(:disabled) {
  background: #F1F1FC !important;
  color: #33319A !important;
}

.basket-notice-btn.is-primary:hover:not(:disabled) {
  background: #2A2880 !important;
  color: #fff !important;
}

.basket-notice-btn:disabled {
  opacity: 0.6;
  cursor: default;
}

/* Phones: full width, above the bottom tab bar. */
@media (max-width: 767px) {
  .basket-notices {
    right: 12px;
    left: 12px;
    bottom: 76px;
    width: auto;
    max-width: none;
    max-height: calc(100vh - 140px);
  }
}
</style>
