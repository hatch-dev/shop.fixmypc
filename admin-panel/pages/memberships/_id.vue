<template>
  <div class="member-page p-20">
    <nuxt-link to="/memberships" class="md-back">← {{ $t('pages.memberships.backToMembers') }}</nuxt-link>

    <div v-if="loading" class="md-card">{{ $t('pages.memberships.loading') }}</div>
    <div v-else-if="loadError" class="md-card md-error">{{ loadError }}</div>

    <template v-else-if="m">
      <div class="md-card md-head">
        <div>
          <h3 class="md-title">{{ m.user ? m.user.name : $t('pages.memberships.customer') }}</h3>
          <p class="md-sub">
            {{ m.user ? m.user.email : '' }}
            <span v-if="m.user && m.user.status === 'suspended'" class="pill pill-bad">{{ $t('pages.memberships.accountSuspended') }}</span>
          </p>
        </div>
        <span class="pill" :class="pillClass">{{ m.status_label }}</span>
      </div>

      <div class="md-grid">
        <div class="md-card">
          <h4>{{ $t('pages.memberships.membership') }}</h4>
          <dl class="md-dl">
            <dt>{{ $t('pages.memberships.plan') }}</dt><dd>{{ m.plan ? m.plan.name : '—' }}</dd>
            <dt>{{ $t('pages.memberships.billing') }}</dt>
            <dd v-if="m.source === 'stripe'">{{ currencyIcon }}{{ money(m.price) }} {{ m.interval_label }} (Stripe)</dd>
            <dd v-else>{{ $t('pages.memberships.grantedNoPayment') }}</dd>
            <dt>{{ $t('pages.memberships.benefitsNow') }}</dt><dd>{{ m.entitled ? $t('pages.memberships.yes') : $t('pages.memberships.no') }}</dd>
            <dt>{{ $t('pages.memberships.started') }}</dt><dd>{{ date(m.created_at) }}</dd>
            <dt>{{ m.source === 'stripe' && !m.cancel_at_period_end ? $t('pages.memberships.renews') : $t('pages.memberships.ends') }}</dt>
            <dd>{{ m.current_period_end ? date(m.current_period_end) : $t('pages.memberships.noEndDate') }}</dd>
            <template v-if="m.trial_ends_at"><dt>{{ $t('pages.memberships.trialEnds') }}</dt><dd>{{ date(m.trial_ends_at) }}</dd></template>
            <template v-if="m.ended_at"><dt>{{ $t('pages.memberships.ended') }}</dt><dd>{{ date(m.ended_at) }}</dd></template>
            <template v-if="m.note"><dt>{{ $t('pages.memberships.note') }}</dt><dd>{{ m.note }}</dd></template>
            <template v-if="m.stripe_subscription_id">
              <dt>Stripe</dt>
              <dd><a :href="m.stripe_url" target="_blank" rel="noopener" class="link">{{ m.stripe_subscription_id }}</a></dd>
              <dt>{{ $t('pages.memberships.lastSynced') }}</dt><dd>{{ m.last_synced_at ? dateTime(m.last_synced_at) : $t('pages.memberships.never') }}</dd>
            </template>
          </dl>
        </div>

        <div v-if="$can('membership', 'edit')" class="md-card">
          <h4>{{ $t('pages.memberships.actions') }}</h4>
          <p v-if="actionError" class="md-error-line" role="alert">{{ actionError }}</p>

          <template v-if="m.source === 'stripe'">
            <div class="md-actions">
              <button v-if="m.can_cancel" type="button" class="outline-btn xs" :disabled="busy" @click="run('cancel_at_period_end')">{{ $t('pages.memberships.cancelAtPeriodEnd') }}</button>
              <button v-if="m.can_resume" type="button" class="outline-btn xs" :disabled="busy" @click="run('resume')">{{ $t('pages.memberships.keepMembership') }}</button>
              <button v-if="m.status !== 'canceled'" type="button" class="outline-btn xs md-danger" :disabled="busy" @click="confirmEnd = true">{{ $t('pages.memberships.endNow') }}</button>
              <button type="button" class="outline-btn xs" :disabled="busy" @click="run('sync')">{{ $t('pages.memberships.rereadStripe') }}</button>
            </div>
            <p class="md-hint">{{ $t('pages.memberships.refundHint') }}</p>
          </template>

          <template v-else>
            <label class="md-label">{{ $t('pages.memberships.endDate') }}</label>
            <div class="md-inline">
              <input type="date" v-model="endsAt" :min="tomorrow">
              <button type="button" class="outline-btn xs" :disabled="busy" @click="run('extend', {ends_at: endsAt || null})">
                {{ m.status === 'canceled' ? $t('pages.memberships.restart') : $t('pages.memberships.saveEndDate') }}
              </button>
            </div>
            <p class="md-hint">{{ $t('pages.memberships.emptyNoEndDate') }}</p>
            <button v-if="m.status !== 'canceled'" type="button" class="outline-btn xs md-danger" :disabled="busy" @click="confirmEnd = true">{{ $t('pages.memberships.endNow') }}</button>
          </template>

          <template v-if="m.source === 'admin' || m.can_change_plan">
            <label class="md-label">{{ m.source === 'stripe' ? $t('pages.memberships.planAndBilling') : $t('pages.memberships.plan') }}</label>
            <div class="md-inline">
              <select v-model="newChoice">
                <option v-for="c in choices" :key="c.key" :value="c.key" :disabled="c.key === currentChoice">{{ c.label }}</option>
              </select>
              <button type="button" class="outline-btn xs" :disabled="busy || newChoice === currentChoice" @click="changeChoice">{{ $t('pages.memberships.change') }}</button>
            </div>
            <p v-if="m.source === 'stripe'" class="md-hint">
              {{ $t('pages.memberships.prorateHint') }}
            </p>
          </template>
        </div>
      </div>

      <div v-if="m.source === 'stripe'" class="md-card">
        <h4>{{ $t('pages.memberships.invoices') }}</h4>
        <p v-if="!m.invoices.length" class="md-hint">{{ $t('pages.memberships.noInvoices') }}</p>
        <table v-else class="md-table">
          <tr><th>{{ $t('pages.memberships.colPeriod') }}</th><th>{{ $t('pages.memberships.colNumber') }}</th><th>{{ $t('pages.memberships.colStatus') }}</th><th>{{ $t('pages.memberships.colPaid') }}</th><th>&nbsp;</th></tr>
          <tr v-for="inv in m.invoices" :key="inv.id">
            <td>{{ date(inv.period_start) }} – {{ date(inv.period_end) }}</td>
            <td>{{ inv.number || '—' }}</td>
            <td>{{ inv.status }}</td>
            <td>{{ currencyIcon }}{{ money(inv.amount_paid) }} <span v-if="inv.amount_paid < inv.amount_due" class="md-hint">{{ $t('pages.memberships.ofAmount', { amount: money(inv.amount_due) }) }}</span></td>
            <td><a v-if="inv.url" :href="inv.url" target="_blank" rel="noopener" class="link">{{ $t('pages.memberships.view') }}</a></td>
          </tr>
        </table>
      </div>

      <div class="md-card">
        <h4>{{ $t('pages.memberships.history') }}</h4>
        <ul class="md-history">
          <li v-for="(e, i) in m.events" :key="i">
            <span class="md-when">{{ dateTime(e.at) }}</span>
            <strong>{{ eventLabel(e.action) }}</strong>
            <span v-if="e.detail"> — {{ e.detail }}</span>
            <span v-if="e.admin" class="md-hint"> {{ $t('pages.memberships.byAdmin', { admin: e.admin }) }}</span>
          </li>
        </ul>
      </div>
    </template>

    <confirm-dialog
      :open="confirmEnd"
      :title="$t('pages.memberships.endConfirmTitle')"
      :confirm-label="$t('pages.memberships.endNow')"
      :busy-label="$t('pages.memberships.ending')"
      :busy="busy"
      :warning="m && m.source === 'stripe' ? $t('pages.memberships.endWarningStripe') : $t('pages.memberships.endWarning')"
      @cancel="confirmEnd = false"
      @confirm="endNow"
    >
      <template v-slot:subject>{{ m && m.user ? m.user.name : '' }} · {{ m && m.plan ? m.plan.name : '' }}</template>
    </confirm-dialog>
  </div>
</template>

<script>
import ConfirmDialog from '~/components/ConfirmDialog'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

// Event actions with a label; the text lives in pages.memberships.events.<action>
const EVENTS = [
  'subscribed', 'granted', 'status', 'cancel_scheduled', 'cancel_withdrawn', 'plan_changed',
  'billing_changed', 'payment_failed', 'expired', 'admin_cancel_at_period_end', 'admin_resume',
  'admin_cancel_now', 'admin_change_plan', 'admin_sync', 'admin_extend'
]

export default {
  name: 'membership-detail',
  middleware: ['common-middleware', 'auth'],
  components: { ConfirmDialog },
  mixins: [util],

  data() {
    return { m: null, loading: true, loadError: '', busy: false, actionError: '', confirmEnd: false, endsAt: '', newChoice: '' }
  },

  computed: {
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    tomorrow() {
      const d = new Date()
      d.setDate(d.getDate() + 1)
      return d.toISOString().slice(0, 10)
    },
    // "plan|interval" for a paid membership, the plan alone for a granted one
    currentChoice() {
      return this.m ? this.choiceKey(this.m.plan_id, this.m.interval) : ''
    },
    choices() {
      if (!this.m) return []
      const out = []
      this.m.plans.forEach(plan => {
        const off = plan.status ? '' : this.$t('pages.memberships.planOff')
        if (this.m.source !== 'stripe') {
          out.push({ key: String(plan.id), label: plan.name + off })
          return
        }
        if (Number(plan.price) > 0) out.push({ key: `${plan.id}|month`, label: this.$t('pages.memberships.choiceMonth', { name: plan.name, price: this.currencyIcon + this.money(plan.price) }) + off })
        if (Number(plan.yearly_price) > 0) out.push({ key: `${plan.id}|year`, label: this.$t('pages.memberships.choiceYear', { name: plan.name, price: this.currencyIcon + this.money(plan.yearly_price) }) + off })
      })
      return out
    },
    pillClass() {
      if (!this.m) return ''
      if (['past_due', 'unpaid'].includes(this.m.status)) return 'pill-bad'
      if (!this.m.entitled) return 'pill-off'
      return this.m.cancel_at_period_end ? 'pill-wait' : 'pill-on'
    },
    ...mapGetters('setting', ['setting'])
  },

  methods: {
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    date(v) {
      if (!v) return '—'
      const d = new Date(v)
      return isNaN(d.getTime()) ? v : d.toLocaleDateString(this.$i18n?.locale || 'en', { day: 'numeric', month: 'short', year: 'numeric' })
    },
    dateTime(v) {
      if (!v) return ''
      const d = new Date(v)
      return isNaN(d.getTime()) ? v : d.toLocaleString(this.$i18n?.locale || 'en', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    },
    eventLabel(action) {
      return EVENTS.includes(action) ? this.$t('pages.memberships.events.' + action) : action
    },
    show(data) {
      this.m = data
      this.newChoice = this.choiceKey(data.plan_id, data.interval)
      this.endsAt = data.source === 'admin' && data.current_period_end ? String(data.current_period_end).slice(0, 10) : ''
    },
    async load() {
      this.loading = true
      try {
        this.show(await this.getById({ id: this.$route.params.id, params: {}, api: 'getMembership' }))
      } catch (e) {
        this.loadError = e?.message || this.$t('pages.memberships.loadFailed')
      } finally {
        this.loading = false
      }
    },
    async run(action, extra = {}) {
      this.busy = true
      this.actionError = ''
      try {
        const data = await this.setById({ id: this.m.id, params: { action, ...extra }, api: 'setMembershipAction' })
        if (data) {
          this.show(data)
          return true
        }
        // refused: setById leaves the message in ui/errors and resolves with nothing
        const errors = this.$store.getters['ui/errors']
        this.actionError = (errors?.form || [])[0] || this.$t('pages.memberships.actionFailed')
      } catch (e) {
        this.actionError = e?.message || this.$t('pages.memberships.actionFailed')
      } finally {
        this.busy = false
      }
      return false
    },
    choiceKey(planId, interval) {
      return this.m && this.m.source !== 'stripe' ? String(planId) : `${planId}|${interval || 'month'}`
    },
    changeChoice() {
      const [planId, interval] = this.newChoice.split('|')
      return this.run('change_plan', interval ? { plan_id: planId, interval } : { plan_id: planId })
    },
    async endNow() {
      await this.run('cancel_now')
      this.confirmEnd = false
    },
    ...mapActions('common', ['getById', 'setById'])
  },

  mounted() {
    this.load()
  }
}
</script>

<style scoped>
.md-back { display: inline-block; margin-bottom: 12px; font-size: 13px; color: #4f6ef7; }
.md-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 12px; }
.md-card h4 { font-size: 15px; font-weight: 600; margin: 0 0 12px; }
.md-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.md-title { margin: 0; font-size: 20px; }
.md-sub { margin: 4px 0 0; color: #6b7280; font-size: 13px; }
.md-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.md-grid .md-card { margin-bottom: 0; }
.md-grid + .md-card, .md-grid + .md-card + .md-card { margin-top: 12px; }
.md-dl { display: grid; grid-template-columns: 120px 1fr; gap: 8px 12px; font-size: 13px; margin: 0; }
.md-dl dt { color: #6b7280; }
.md-dl dd { margin: 0; word-break: break-word; }
.md-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.md-inline { display: flex; gap: 8px; align-items: center; }
.md-inline input, .md-inline select { flex: 1; min-width: 0; }
.md-inline select { height: 40px; padding: 0 10px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; font-size: 14px; }
.md-label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
.md-hint { font-size: 12px; color: #6b7280; margin: 6px 0 0; }
.md-danger { color: #b42318 !important; border-color: #fecdca !important; }
.md-error, .md-error-line { color: #b42318; }
.md-error-line { font-size: 13px; background: #fef3f2; border-radius: 6px; padding: 8px 10px; margin: 0 0 10px; }
.md-table { width: 100%; font-size: 13px; border-collapse: collapse; }
.md-table th, .md-table td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #f2f4f7; }
.md-history { list-style: none; margin: 0; padding: 0; font-size: 13px; }
.md-history li { padding: 6px 0; border-bottom: 1px solid #f2f4f7; }
.md-when { color: #6b7280; margin-right: 8px; }
.pill { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.pill-on { background: #ecfdf3; color: #027a48; }
.pill-wait { background: #fffaeb; color: #b54708; }
.pill-off { background: #f2f4f7; color: #475467; }
.pill-bad { background: #fef3f2; color: #b42318; }
@media (max-width: 900px) { .md-grid { grid-template-columns: 1fr; } }
</style>
