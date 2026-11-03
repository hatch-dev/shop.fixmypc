<template>
  <pop-over
    :title="$t('pages.giftCards.manageTitle', { code: card.code })"
    elem-id="gift-card-manage"
    :layer="true"
    class="gcm-popup"
    @close="$emit('close')"
  >
    <template v-slot:content>
      <div v-if="loading" class="gcm-loading">{{ $t('pages.giftCards.loadingHistory') }}</div>
      <template v-else-if="history">
        <div class="gcm-figures">
          <div><span>{{ $t('pages.giftCards.valueLabel') }}</span><strong>{{ currencyIcon }}{{ history.value }}</strong></div>
          <div><span>{{ $t('pages.giftCards.spentLabel') }}</span><strong>{{ currencyIcon }}{{ history.spent }}</strong></div>
          <div><span>{{ $t('pages.giftCards.balanceLabel') }}</span><strong>{{ currencyIcon }}{{ history.balance }}</strong></div>
          <div><span>{{ $t('pages.giftCards.expiresLabel') }}</span><strong>{{ shortDate(history.expires) || '—' }}</strong></div>
        </div>
        <p v-if="history.purchase" class="gcm-purchase">
          {{ $t('pages.giftCards.purchaseLine', { id: history.purchase.id, method: methodLabel(history.purchase.payment_method), status: history.purchase.status }) }}
        </p>
        <p v-if="!history.log_ready" class="gcm-warn">{{ $t('pages.giftCards.logPending') }}</p>

        <h5 class="gcm-h">{{ $t('pages.giftCards.historyTitle') }}</h5>
        <ol v-if="history.events.length" class="gcm-events">
          <li v-for="(e, i) in history.events" :key="i">
            <span class="gcm-when">{{ shortDate(e.at) }}</span>
            <span class="gcm-what">
              {{ eventLabel(e) }}
              <strong v-if="e.amount !== null && e.amount !== undefined">{{ signed(e) }}</strong>
            </span>
            <span v-if="e.reason || e.by" class="gcm-why">{{ [e.reason, e.by].filter(Boolean).join(' · ') }}</span>
          </li>
        </ol>
        <p v-else class="gcm-muted">{{ $t('pages.giftCards.noHistory') }}</p>

        <template v-if="canEdit && history.log_ready && history.state !== 'void'">
          <form class="gcm-form" @submit.prevent="submit('adjust')">
            <h5 class="gcm-h">{{ $t('pages.giftCards.adjustTitle') }}</h5>
            <div class="gcm-row">
              <input v-model="adjust.amount" type="number" step="0.01" :placeholder="$t('pages.giftCards.adjustAmount')" :aria-label="$t('pages.giftCards.adjustAmount')">
              <input v-model="adjust.reason" type="text" maxlength="255" :placeholder="$t('pages.giftCards.reason')" :aria-label="$t('pages.giftCards.reason')">
              <button type="submit" class="primary-btn gcm-btn" :disabled="busy">{{ $t('pages.giftCards.save') }}</button>
            </div>
          </form>
          <form class="gcm-form" @submit.prevent="submit('expiry')">
            <h5 class="gcm-h">{{ $t('pages.giftCards.changeExpiryTitle') }}</h5>
            <div class="gcm-row">
              <input v-model="expiry.date" type="date" :min="tomorrow" :aria-label="$t('pages.giftCards.newExpiry')">
              <input v-model="expiry.reason" type="text" maxlength="255" :placeholder="$t('pages.giftCards.reason')" :aria-label="$t('pages.giftCards.reason')">
              <button type="submit" class="primary-btn gcm-btn" :disabled="busy">{{ $t('pages.giftCards.save') }}</button>
            </div>
          </form>
        </template>
        <form v-if="canEdit && history.log_ready && card.refundable" class="gcm-form" @submit.prevent="submit('refund')">
          <h5 class="gcm-h">{{ $t('pages.giftCards.refundTitle') }}</h5>
          <p class="gcm-muted">{{ $t('pages.giftCards.refundHelp') }}</p>
          <div class="gcm-row">
            <input v-model="refund.reason" type="text" maxlength="255" :placeholder="$t('pages.giftCards.reason')" :aria-label="$t('pages.giftCards.reason')">
            <button type="submit" class="delete-btn lite-btn gcm-btn" :disabled="busy">{{ $t('pages.giftCards.refundButton') }}</button>
          </div>
        </form>
        <p v-if="error" class="gcm-error" role="alert">{{ error }}</p>
      </template>
    </template>
  </pop-over>
</template>

<script>
import PopOver from '~/components/PopOver'
import {mapActions} from 'vuex'

/*
 * One gift card: what it is worth, everything that happened to it, and the
 * changes staff can make (GiftCardAdmin on the server): balance, expiry, and
 * refunding its purchase. Every change asks for a reason; the server records
 * it with the admin and the time, and refuses anything that would leave the
 * card below zero.
 */
export default {
  name: 'GiftCardManage',
  components: {PopOver},
  props: {
    card: {type: Object, required: true},
    currencyIcon: {type: String, default: '€'}
  },
  data() {
    return {
      history: null,
      loading: true,
      busy: false,
      error: '',
      adjust: {amount: '', reason: ''},
      expiry: {date: '', reason: ''},
      refund: {reason: ''}
    }
  },
  computed: {
    canEdit() {
      return this.$can('gift_card', 'edit')
    },
    tomorrow() {
      const d = new Date()
      d.setDate(d.getDate() + 1)
      return d.toISOString().slice(0, 10)
    }
  },
  methods: {
    ...mapActions('common', ['getRequest', 'setRequest']),
    async load() {
      this.loading = true
      try {
        this.history = await this.getRequest({params: {id: this.card.id}, api: 'giftCardHistory'})
      } catch (e) {
        this.error = e?.message || this.$t('pages.giftCards.loadFailed')
      } finally {
        this.loading = false
      }
    },
    async submit(kind) {
      this.error = ''
      const request = {
        adjust: {api: 'adjustGiftCard', params: {id: this.card.id, amount: this.adjust.amount, reason: this.adjust.reason}},
        expiry: {api: 'giftCardExpiry', params: {id: this.card.id, expires_at: this.expiry.date, reason: this.expiry.reason}},
        refund: {api: 'refundGiftCard', params: {order_id: this.card.order_id, reason: this.refund.reason}}
      }[kind]
      if (kind === 'refund' && !(await this.$dialog.confirm(this.$t('pages.giftCards.refundConfirm'), {confirmLabel: this.$t('pages.giftCards.refundButton')}))) {
        return
      }
      this.busy = true
      try {
        const res = await this.setRequest(request)
        if (res && res.status === 201) {
          this.error = res.message
          return
        }
        this.adjust = {amount: '', reason: ''}
        this.expiry = {date: '', reason: ''}
        this.refund = {reason: ''}
        this.$emit('changed')
        await this.load()
      } catch (e) {
        this.error = e?.message || this.$t('pages.giftCards.saveFailed')
      } finally {
        this.busy = false
      }
    },
    shortDate(iso) {
      if (!iso) return ''
      // a bare date is a calendar day, not midnight UTC shifted into another one
      const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso + 'T12:00:00' : iso)
      return isNaN(d) ? iso : d.toLocaleDateString(this.$i18n.locale, {day: 'numeric', month: 'short', year: 'numeric'})
    },
    methodLabel(method) {
      return {
        wallet: this.$t('pages.giftCards.paidByWallet'),
        sumup: this.$t('pages.giftCards.paidByCard'),
        admin: this.$t('pages.giftCards.issuedByStaff')
      }[method] || method
    },
    signed(e) {
      const value = Number(e.amount)
      if (['balance'].includes(e.type)) {
        return (value > 0 ? '+' : '−') + this.currencyIcon + Math.abs(value).toFixed(2)
      }
      if (['spent'].includes(e.type)) return '−' + this.currencyIcon + value.toFixed(2)
      if (['released', 'returned'].includes(e.type)) return '+' + this.currencyIcon + value.toFixed(2)
      return this.currencyIcon + value.toFixed(2)
    },
    eventLabel(e) {
      const ev = 'pages.giftCards.ev.'
      switch (e.type) {
        case 'purchased': return this.$t(ev + 'purchased', {method: this.methodLabel(e.method)})
        case 'emailed': return this.$t(ev + 'emailed', {to: e.to || '—'})
        case 'resend': return this.$t(ev + 'resend', {to: e.new_value || '—'})
        case 'spent': return this.$t(ev + 'spent', {order: e.order})
        case 'released': return this.$t(ev + 'released', {order: e.order})
        case 'returned': return this.$t(ev + 'returned', {order: e.order})
        case 'expiry': return this.$t(ev + 'expiry', {date: this.shortDate(e.new_value)})
        default: return this.$te(ev + e.type) ? this.$t(ev + e.type) : e.type
      }
    }
  },
  mounted() {
    this.load()
  }
}
</script>

<style scoped>
.gcm-loading, .gcm-muted { color: #6B7280; font-size: 13px; }
.gcm-figures { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
.gcm-figures div { background: #F8F9FC; border-radius: 8px; padding: 8px 10px; display: flex; flex-direction: column; gap: 2px; }
.gcm-figures span { font-size: 12px; color: #6B7280; }
.gcm-figures strong { font-size: 15px; color: #111827; }
.gcm-purchase { font-size: 13px; color: #374151; margin: 0 0 8px; }
.gcm-warn { background: #FFF7ED; border: 1px solid #FED7AA; color: #9A3412; border-radius: 8px; padding: 8px 10px; font-size: 13px; }
.gcm-h { font-size: 14px; font-weight: 700; margin: 14px 0 6px; }
.gcm-events { list-style: none; padding: 0; margin: 0; max-height: 220px; overflow-y: auto; border: 1px solid #EEF0F4; border-radius: 8px; }
.gcm-events li { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 2px 10px; padding: 7px 10px; border-bottom: 1px solid #F1F3F5; font-size: 13px; }
.gcm-events li:last-child { border-bottom: 0; }
.gcm-when { color: #6B7280; }
.gcm-what strong { margin-left: 6px; }
.gcm-why { grid-column: 2; color: #6B7280; font-size: 12px; overflow-wrap: anywhere; }
.gcm-row { display: flex; gap: 8px; flex-wrap: wrap; }
.gcm-row input { flex: 1 1 140px; min-width: 0; height: 38px; }
.gcm-btn { width: auto; height: 38px; line-height: 36px; padding: 0 16px; }
.gcm-error { color: #B91C1C; font-size: 13px; margin-top: 10px; overflow-wrap: anywhere; }
@media (max-width: 576px) {
  .gcm-figures { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
