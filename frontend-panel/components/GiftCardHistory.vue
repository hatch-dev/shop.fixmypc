<template>
  <div class="gch">
    <button
      type="button"
      class="gch-toggle"
      :aria-expanded="open ? 'true' : 'false'"
      @click="toggle"
    >
      <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
      {{ open ? $t('ui.vouchersPanel.history.hide') : $t('ui.vouchersPanel.history.show') }}
    </button>

    <div v-if="open" class="gch-body">
      <p v-if="loading" class="gch-muted">{{ $t('ui.vouchersPanel.history.loading') }}</p>
      <p v-else-if="error" class="gch-error">{{ error }}</p>
      <template v-else-if="history">
        <p class="gch-summary">
          {{ $t('ui.vouchersPanel.history.summary', { value: money(history.value), spent: money(history.spent), balance: money(history.balance) }) }}
        </p>
        <ol v-if="history.events.length" class="gch-events">
          <li v-for="(e, i) in history.events" :key="i">
            <span class="gch-when">{{ date(e.at) }}</span>
            <span class="gch-what">{{ label(e) }}</span>
            <span v-if="e.amount !== null && e.amount !== undefined" class="gch-amount" :class="sign(e) === '−' ? 'is-out' : ''" dir="ltr">
              {{ sign(e) }}{{ money(e.amount) }}
            </span>
          </li>
        </ol>
        <p v-else class="gch-muted">{{ $t('ui.vouchersPanel.history.empty') }}</p>
      </template>
    </div>
  </div>
</template>

<script>
import {mapGetters} from 'vuex'

/*
 * One gift card's history for its owner, fetched when opened: bought, added
 * to vouchers, spent on orders, given back by a cancellation or return, and
 * changes made by the shop. Amounts and dates only - who in the shop made a
 * change, and why, stays with the shop (WalletController::giftCardHistory).
 */
export default {
  name: 'GiftCardHistory',
  props: {
    // {voucher_id} for a card in the voucher list, {code_id} for one bought
    query: {type: Object, required: true}
  },
  data() {
    return {open: false, loading: false, error: '', history: null}
  },
  computed: {
    ...mapGetters('language', ['langCode']),
    ...mapGetters('setting', ['setting']),
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    }
  },
  methods: {
    async toggle() {
      this.open = !this.open
      if (!this.open || this.history) return
      this.loading = true
      this.error = ''
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const {data} = await this.$axios.get(`${baseUrl}api/v1/user/gift-card-history`, {
          params: this.query,
          headers: this.langCode ? {language: this.langCode} : {}
        })
        this.history = data?.data || null
      } catch (e) {
        this.error = e?.response?.data?.message || this.$t('ui.vouchersPanel.history.failed')
      } finally {
        this.loading = false
      }
    },
    money(v) {
      return this.currencyIcon + Number(v || 0).toFixed(2)
    },
    date(iso) {
      if (!iso) return ''
      // a bare date is a calendar day, not midnight UTC shifted into another one
      const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso + 'T12:00:00' : iso)
      return isNaN(d) ? '' : d.toLocaleDateString(this.$i18n.locale, {day: 'numeric', month: 'short', year: 'numeric'})
    },
    sign(e) {
      if (e.type === 'spent') return '−'
      if (e.type === 'released' || e.type === 'returned') return '+'
      if (e.type === 'balance') return Number(e.amount) < 0 ? '−' : '+'
      return ''
    },
    label(e) {
      const key = 'ui.vouchersPanel.history.ev.' + e.type
      const values = {order: e.order || '', date: this.date(e.new_value)}
      return this.$te(key) ? this.$t(key, values) : e.type
    }
  }
}
</script>

<style scoped>
.gch { margin-top: 10px; }
.gch-toggle {
  background: none !important;
  border: 0;
  padding: 0;
  color: var(--primary-color, #33319a);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.gch-body { margin-top: 8px; border: 1px solid #EEF0F4; border-radius: 10px; padding: 10px 12px; background: #FCFCFD; }
.gch-summary { font-size: 13px; color: #374151; margin: 0 0 8px; }
.gch-muted { font-size: 13px; color: #6B7280; margin: 0; }
.gch-error { font-size: 13px; color: #B91C1C; margin: 0; }
.gch-events { list-style: none; padding: 0; margin: 0; }
.gch-events li {
  display: grid;
  grid-template-columns: 92px minmax(0, 1fr) auto;
  gap: 10px;
  padding: 6px 0;
  border-top: 1px solid #F1F3F5;
  font-size: 13px;
}
.gch-events li:first-child { border-top: 0; }
.gch-when { color: #6B7280; }
.gch-what { color: #111827; overflow-wrap: anywhere; }
.gch-amount { font-weight: 600; color: #047857; white-space: nowrap; }
.gch-amount.is-out { color: #374151; }
@media (max-width: 480px) {
  .gch-events li { grid-template-columns: minmax(0, 1fr) auto; }
  .gch-when { grid-column: 1 / -1; }
}
</style>
