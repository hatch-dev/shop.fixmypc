<template>
  <div class="member-page p-20">
    <nuxt-link to="/memberships" class="md-back">← Members</nuxt-link>

    <div v-if="loading" class="md-card">Loading…</div>
    <div v-else-if="loadError" class="md-card md-error">{{ loadError }}</div>

    <template v-else-if="m">
      <div class="md-card md-head">
        <div>
          <h3 class="md-title">{{ m.user ? m.user.name : 'Customer' }}</h3>
          <p class="md-sub">
            {{ m.user ? m.user.email : '' }}
            <span v-if="m.user && m.user.status === 'suspended'" class="pill pill-bad">account suspended</span>
          </p>
        </div>
        <span class="pill" :class="pillClass">{{ m.status_label }}</span>
      </div>

      <div class="md-grid">
        <div class="md-card">
          <h4>Membership</h4>
          <dl class="md-dl">
            <dt>Plan</dt><dd>{{ m.plan ? m.plan.name : '—' }}</dd>
            <dt>Billing</dt>
            <dd v-if="m.source === 'stripe'">{{ currencyIcon }}{{ money(m.price) }} {{ m.interval_label }} (Stripe)</dd>
            <dd v-else>Granted, no payment</dd>
            <dt>Benefits now</dt><dd>{{ m.entitled ? 'Yes' : 'No' }}</dd>
            <dt>Started</dt><dd>{{ date(m.created_at) }}</dd>
            <dt>{{ m.source === 'stripe' && !m.cancel_at_period_end ? 'Renews' : 'Ends' }}</dt>
            <dd>{{ m.current_period_end ? date(m.current_period_end) : 'No end date' }}</dd>
            <template v-if="m.trial_ends_at"><dt>Trial ends</dt><dd>{{ date(m.trial_ends_at) }}</dd></template>
            <template v-if="m.ended_at"><dt>Ended</dt><dd>{{ date(m.ended_at) }}</dd></template>
            <template v-if="m.note"><dt>Note</dt><dd>{{ m.note }}</dd></template>
            <template v-if="m.stripe_subscription_id">
              <dt>Stripe</dt>
              <dd><a :href="m.stripe_url" target="_blank" rel="noopener" class="link">{{ m.stripe_subscription_id }}</a></dd>
              <dt>Last synced</dt><dd>{{ m.last_synced_at ? dateTime(m.last_synced_at) : 'never' }}</dd>
            </template>
          </dl>
        </div>

        <div v-if="$can('membership', 'edit')" class="md-card">
          <h4>Actions</h4>
          <p v-if="actionError" class="md-error-line" role="alert">{{ actionError }}</p>

          <template v-if="m.source === 'stripe'">
            <div class="md-actions">
              <button v-if="m.can_cancel" type="button" class="outline-btn xs" :disabled="busy" @click="run('cancel_at_period_end')">Cancel at period end</button>
              <button v-if="m.can_resume" type="button" class="outline-btn xs" :disabled="busy" @click="run('resume')">Keep membership (undo cancel)</button>
              <button v-if="m.status !== 'canceled'" type="button" class="outline-btn xs md-danger" :disabled="busy" @click="confirmEnd = true">End now</button>
              <button type="button" class="outline-btn xs" :disabled="busy" @click="run('sync')">Re-read from Stripe</button>
            </div>
            <p class="md-hint">Refunds are made in the Stripe dashboard. Ending now stops billing immediately; nothing is refunded automatically.</p>
          </template>

          <template v-else>
            <label class="md-label">End date</label>
            <div class="md-inline">
              <input type="date" v-model="endsAt" :min="tomorrow">
              <button type="button" class="outline-btn xs" :disabled="busy" @click="run('extend', {ends_at: endsAt || null})">
                {{ m.status === 'canceled' ? 'Restart' : 'Save end date' }}
              </button>
            </div>
            <p class="md-hint">Empty means no end date.</p>
            <button v-if="m.status !== 'canceled'" type="button" class="outline-btn xs md-danger" :disabled="busy" @click="confirmEnd = true">End now</button>
          </template>

          <template v-if="m.source === 'admin' || m.can_change_plan">
            <label class="md-label">{{ m.source === 'stripe' ? 'Plan and billing' : 'Plan' }}</label>
            <div class="md-inline">
              <select v-model="newChoice">
                <option v-for="c in choices" :key="c.key" :value="c.key" :disabled="c.key === currentChoice">{{ c.label }}</option>
              </select>
              <button type="button" class="outline-btn xs" :disabled="busy || newChoice === currentChoice" @click="changeChoice">Change</button>
            </div>
            <p v-if="m.source === 'stripe'" class="md-hint">
              Stripe prorates: unused time is credited against the new price. Moving between monthly and yearly starts the new period today and is invoiced straight away.
            </p>
          </template>
        </div>
      </div>

      <div v-if="m.source === 'stripe'" class="md-card">
        <h4>Invoices</h4>
        <p v-if="!m.invoices.length" class="md-hint">None yet. Stripe reports each invoice to the webhook.</p>
        <table v-else class="md-table">
          <tr><th>Period</th><th>Number</th><th>Status</th><th>Paid</th><th>&nbsp;</th></tr>
          <tr v-for="inv in m.invoices" :key="inv.id">
            <td>{{ date(inv.period_start) }} – {{ date(inv.period_end) }}</td>
            <td>{{ inv.number || '—' }}</td>
            <td>{{ inv.status }}</td>
            <td>{{ currencyIcon }}{{ money(inv.amount_paid) }} <span v-if="inv.amount_paid < inv.amount_due" class="md-hint">of {{ money(inv.amount_due) }}</span></td>
            <td><a v-if="inv.url" :href="inv.url" target="_blank" rel="noopener" class="link">View</a></td>
          </tr>
        </table>
      </div>

      <div class="md-card">
        <h4>History</h4>
        <ul class="md-history">
          <li v-for="(e, i) in m.events" :key="i">
            <span class="md-when">{{ dateTime(e.at) }}</span>
            <strong>{{ eventLabel(e.action) }}</strong>
            <span v-if="e.detail"> — {{ e.detail }}</span>
            <span v-if="e.admin" class="md-hint"> by {{ e.admin }}</span>
          </li>
        </ul>
      </div>
    </template>

    <confirm-dialog
      :open="confirmEnd"
      title="End this membership now?"
      confirm-label="End now"
      busy-label="Ending…"
      :busy="busy"
      :warning="m && m.source === 'stripe' ? 'Billing stops and the benefits end immediately. No refund is made.' : 'The benefits end immediately.'"
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

const EVENTS = {
  subscribed: 'Joined',
  granted: 'Granted',
  status: 'Status changed',
  cancel_scheduled: 'Set to end at period end',
  cancel_withdrawn: 'Cancellation withdrawn',
  plan_changed: 'Plan changed',
  billing_changed: 'Billing changed',
  payment_failed: 'Payment failed',
  expired: 'Expired',
  admin_cancel_at_period_end: 'Admin: cancel at period end',
  admin_resume: 'Admin: kept membership',
  admin_cancel_now: 'Admin: ended now',
  admin_change_plan: 'Admin: changed plan',
  admin_sync: 'Admin: re-read from Stripe',
  admin_extend: 'Admin: set end date'
}

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
        const off = plan.status ? '' : ' (off)'
        if (this.m.source !== 'stripe') {
          out.push({ key: String(plan.id), label: plan.name + off })
          return
        }
        if (Number(plan.price) > 0) out.push({ key: `${plan.id}|month`, label: `${plan.name} · ${this.currencyIcon}${this.money(plan.price)} / month${off}` })
        if (Number(plan.yearly_price) > 0) out.push({ key: `${plan.id}|year`, label: `${plan.name} · ${this.currencyIcon}${this.money(plan.yearly_price)} / year${off}` })
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
      return isNaN(d.getTime()) ? v : d.toLocaleDateString('en-IE', { day: 'numeric', month: 'short', year: 'numeric' })
    },
    dateTime(v) {
      if (!v) return ''
      const d = new Date(v)
      return isNaN(d.getTime()) ? v : d.toLocaleString('en-IE', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    },
    eventLabel(action) {
      return EVENTS[action] || action
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
        this.loadError = e?.message || 'This membership could not be loaded.'
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
        this.actionError = (errors?.form || [])[0] || 'That could not be done.'
      } catch (e) {
        this.actionError = e?.message || 'That could not be done.'
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
