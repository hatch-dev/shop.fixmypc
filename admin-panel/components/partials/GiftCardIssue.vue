<template>
  <pop-over
    :title="$t('pages.giftCards.issueTitle')"
    elem-id="gift-card-issue"
    :layer="true"
    class="gcm-popup"
    @close="$emit('close')"
  >
    <template v-slot:content>
      <form v-if="!issued" class="gci-form" @submit.prevent="submit">
        <label>{{ $t('pages.giftCards.design') }}
          <select v-model="form.design_id" required>
            <option v-for="d in designs" :key="d.id" :value="d.id">{{ d.title }}</option>
          </select>
        </label>
        <div class="gci-pair">
          <label>{{ $t('pages.giftCards.value') }}
            <input v-model="form.amount" type="number" min="1" max="5000" step="0.01" required>
          </label>
          <label>{{ $t('pages.giftCards.quantity') }}
            <input v-model="form.quantity" type="number" min="1" max="20" step="1" required>
          </label>
        </div>
        <label>{{ $t('pages.giftCards.expiresAt') }}
          <input v-model="form.expires_at" type="date" :min="tomorrow">
        </label>
        <label>{{ $t('pages.giftCards.customerEmail') }}
          <input v-model="form.customer_email" type="email" maxlength="190">
        </label>
        <div class="gci-pair">
          <label>{{ $t('pages.giftCards.recipientName') }}
            <input v-model="form.recipient_name" type="text" maxlength="120">
          </label>
          <label>{{ $t('pages.giftCards.recipientEmail') }}
            <input v-model="form.recipient_email" type="email" maxlength="190">
          </label>
        </div>
        <label v-if="form.recipient_email">{{ $t('pages.giftCards.message') }}
          <textarea v-model="form.message" maxlength="300" rows="2"></textarea>
        </label>
        <label>{{ $t('pages.giftCards.reason') }}
          <input v-model="form.reason" type="text" maxlength="255" required>
        </label>
        <label class="gci-check">
          <input v-model="form.send_email" type="checkbox">
          {{ $t('pages.giftCards.sendEmail') }}
        </label>
        <p v-if="error" class="gcm-error" role="alert">{{ error }}</p>
        <div class="gci-actions">
          <button type="submit" class="primary-btn gcm-btn" :disabled="busy">{{ $t('pages.giftCards.issueButton') }}</button>
        </div>
      </form>
      <div v-else class="gci-done">
        <p>{{ issued.message }}</p>
        <ul>
          <li v-for="code in issued.codes" :key="code"><span class="gc-code">{{ code }}</span></li>
        </ul>
      </div>
    </template>
  </pop-over>
</template>

<script>
import PopOver from '~/components/PopOver'
import {mapActions} from 'vuex'

/*
 * Issue gift cards with no purchase behind them (goodwill, a replacement, a
 * prize). The server records each as issued by this admin, with the reason;
 * nothing is charged, so such a card can be voided but not refunded.
 */
export default {
  name: 'GiftCardIssue',
  components: {PopOver},
  data() {
    return {
      designs: [],
      busy: false,
      error: '',
      issued: null,
      form: {
        design_id: '', amount: '', quantity: 1, expires_at: '', customer_email: '',
        recipient_name: '', recipient_email: '', message: '', reason: '', send_email: true
      }
    }
  },
  computed: {
    tomorrow() {
      const d = new Date()
      d.setDate(d.getDate() + 1)
      return d.toISOString().slice(0, 10)
    }
  },
  methods: {
    ...mapActions('common', ['getRequest', 'setRequest']),
    async submit() {
      this.error = ''
      this.busy = true
      try {
        const res = await this.setRequest({params: this.form, api: 'issueGiftCard'})
        if (res && res.status === 201) {
          this.error = res.message
          return
        }
        this.issued = {message: this.$t('pages.giftCards.issuedDone'), codes: (res && res.codes) || []}
        this.$emit('changed')
      } catch (e) {
        this.error = e?.message || this.$t('pages.giftCards.saveFailed')
      } finally {
        this.busy = false
      }
    }
  },
  async mounted() {
    try {
      this.designs = (await this.getRequest({params: {}, api: 'giftCardDesigns'})) || []
      const active = this.designs.find(d => d.is_active) || this.designs[0]
      if (active) this.form.design_id = active.id
    } catch (e) {
      this.error = e?.message || this.$t('pages.giftCards.loadFailed')
    }
  }
}
</script>

<style scoped>
.gci-form { display: flex; flex-direction: column; gap: 10px; }
.gci-form label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; color: #374151; }
.gci-form input, .gci-form select, .gci-form textarea { font-weight: 400; }
.gci-pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.gci-check { flex-direction: row !important; align-items: center; gap: 8px !important; }
.gci-check input { width: auto; }
.gci-actions { display: flex; justify-content: flex-end; }
.gcm-btn { width: auto; height: 38px; line-height: 36px; padding: 0 16px; }
.gcm-error { color: #B91C1C; font-size: 13px; overflow-wrap: anywhere; }
.gci-done ul { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.gc-code { font-family: monospace; background: #F3F4F6; border-radius: 6px; padding: 4px 8px; }
@media (max-width: 576px) {
  .gci-pair { grid-template-columns: 1fr; }
}
</style>
