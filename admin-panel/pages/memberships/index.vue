<template>
  <div class="members-page">

    <div v-if="stats" class="ms-stats">
      <div class="ms-stat"><span>Members</span><strong>{{ stats.members }}</strong><small>{{ stats.paying }} paying · {{ stats.granted }} granted</small></div>
      <div class="ms-stat"><span>Monthly revenue</span><strong>{{ currencyIcon }}{{ money(stats.monthly_revenue) }}</strong><small>at what each member pays</small></div>
      <div class="ms-stat"><span>Ending</span><strong>{{ stats.ending }}</strong><small>cancelled, active to period end</small></div>
      <div class="ms-stat" :class="{alert: stats.past_due > 0}"><span>Payment due</span><strong>{{ stats.past_due }}</strong><small>Stripe is retrying</small></div>
      <div class="ms-stat"><span>Discount given</span><strong>{{ currencyIcon }}{{ money(stats.discount_given) }}</strong><small>on member orders</small></div>
    </div>

    <p v-if="stats && !stats.stripe_ready" class="ms-warning">
      Stripe keys are not set in Payment Setting, so customers cannot join or be billed.
    </p>

    <list-page
      ref="listPage"
      list-api="getMemberships"
      route-name="memberships"
      name="Members"
      gate="membership"
      :add-button="false"
      :order-options="orderOptions"
    >
      <template v-slot:extra-filters>
        <div class="ups-filters">
          <select v-model="filter" aria-label="Status" @change="applyFilters">
            <option value="">All memberships</option>
            <option value="entitled">With benefits now</option>
            <option value="ending">Ending</option>
            <option value="past_due">Payment due</option>
            <option value="ended">Ended</option>
            <option value="admin">Granted by an admin</option>
          </select>
          <select v-model="planId" aria-label="Plan" @change="applyFilters">
            <option value="">All plans</option>
            <option v-for="plan in plans" :key="plan.id" :value="String(plan.id)">{{ plan.name }}</option>
          </select>
        </div>
      </template>

      <template v-slot:add-button>
        <button v-if="$can('membership', 'create')" type="button" class="button primary-btn ms-grant-btn" @click="openGrant">
          <i class="fa-solid fa-gift" aria-hidden="true"></i> Grant membership
        </button>
      </template>

      <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">#</th>
          <th>Customer</th>
          <th>Plan</th>
          <th>Status</th>
          <th>Pays</th>
          <th>Renews / ends</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="value.id">
          <td class="w-50x mx-w-50x">{{ from + index }}</td>
          <td>
            <nuxt-link class="link" :to="`/memberships/${value.id}`"><h5>{{ value.user_name || 'Customer #' + value.user_id }}</h5></nuxt-link>
            <span class="sub-note">{{ value.user_email }}</span>
          </td>
          <td>{{ value.plan_name }}</td>
          <td>
            <span class="pill" :class="pillClass(value)">{{ value.status_label }}</span>
            <span v-if="value.source === 'admin'" class="sub-note">granted</span>
          </td>
          <td>
            <template v-if="value.source === 'stripe'">{{ currencyIcon }}{{ money(value.price) }} <span class="sub-note">{{ intervalLabel(value) }}</span></template>
            <span v-else class="sub-note">free</span>
          </td>
          <td>
            {{ value.current_period_end ? formatDate(value.current_period_end) : 'No end date' }}
            <span class="sub-note">{{ endNote(value) }}</span>
          </td>
          <td><button class="lite-btn" @click.prevent="$router.push(`/memberships/${value.id}`)">Open</button></td>
        </tr>
      </template>
    </list-page>

    <confirm-dialog
      :open="grant.open"
      title="Grant a membership"
      tone="primary"
      icon="fa-solid fa-gift"
      confirm-label="Grant"
      busy-label="Granting…"
      :busy="grant.busy"
      :confirm-disabled="!grant.user || !grant.planId"
      @cancel="grant.open = false"
      @confirm="submitGrant"
    >
      <template v-slot:subject>No payment is taken. The customer gets the plan's benefits until the end date.</template>

      <div class="ms-form">
        <label>Customer</label>
        <div v-if="grant.user" class="ms-picked">
          <span>{{ grant.user.name }} <small>{{ grant.user.email }}</small></span>
          <button type="button" class="lite-btn" @click="grant.user = null">Change</button>
        </div>
        <template v-else>
          <input type="text" v-model="grant.q" placeholder="Search by name or email" @input="searchCustomers">
          <ul v-if="grant.results.length" class="ms-results">
            <li v-for="u in grant.results" :key="u.id">
              <button type="button" @click="grant.user = u">{{ u.name }} <small>{{ u.email }}</small></button>
            </li>
          </ul>
        </template>

        <label>Plan</label>
        <select v-model="grant.planId">
          <option value="">Choose a plan</option>
          <option v-for="plan in plans" :key="plan.id" :value="String(plan.id)">{{ plan.name }}</option>
        </select>

        <label>Ends on</label>
        <input type="date" v-model="grant.endsAt" :min="tomorrow">
        <small class="sub-note">Leave empty for no end date.</small>

        <label>Note (staff only)</label>
        <input type="text" maxlength="255" v-model="grant.note" placeholder="e.g. Apology for a late delivery">

        <p v-if="grant.error" class="ms-error">{{ grant.error }}</p>
      </div>
    </confirm-dialog>
  </div>
</template>

<script>
import ListPage from '~/components/partials/ListPage'
import ConfirmDialog from '~/components/ConfirmDialog'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

export default {
  name: 'memberships',
  middleware: ['common-middleware', 'auth'],
  components: { ListPage, ConfirmDialog },
  mixins: [util],

  data() {
    return {
      stats: null,
      plans: [],
      filter: this.$route.query.filter || '',
      planId: this.$route.query.plan_id || '',
      orderOptions: {
        created_at: { title: 'Date' }
      },
      grant: { open: false, busy: false, q: '', results: [], user: null, planId: '', endsAt: '', note: '', error: '' },
      searchTimer: null
    }
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
    ...mapGetters('setting', ['setting'])
  },

  methods: {
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    intervalLabel(m) {
      const count = Number(m.interval_count || 1)
      return count === 1 ? `per ${m.interval || 'month'}` : `every ${count} ${m.interval}s`
    },
    formatDate(value) {
      const d = new Date(String(value).replace(' ', 'T'))
      return isNaN(d.getTime()) ? value : d.toLocaleDateString('en-IE', { day: 'numeric', month: 'short', year: 'numeric' })
    },
    endNote(m) {
      if (m.status === 'canceled') return 'ended'
      if (m.cancel_at_period_end) return 'ends then'
      if (m.source === 'admin') return 'ends then'
      return m.entitled ? 'renews then' : ''
    },
    pillClass(m) {
      if (['past_due', 'unpaid'].includes(m.status)) return 'pill-bad'
      if (!m.entitled) return 'pill-off'
      return m.cancel_at_period_end ? 'pill-wait' : 'pill-on'
    },
    applyFilters() {
      const query = { ...this.$route.query }
      if (this.filter) query.filter = this.filter; else delete query.filter
      if (this.planId) query.plan_id = this.planId; else delete query.plan_id
      delete query.page
      this.$router.push({ query }).catch(() => {}).then(() => this.$refs.listPage?.fetchingData())
    },
    async loadStats() {
      try {
        this.stats = await this.getRequest({ api: 'getMembershipStats', params: {} })
      } catch (e) {
        this.stats = null
      }
    },
    async loadPlans() {
      try {
        const data = await this.getRequest({ api: 'getMembershipPlans', params: { orderby: 'sort_order', type: 'asc' } })
        this.plans = data?.data || []
      } catch (e) {
        this.plans = []
      }
    },
    openGrant() {
      this.grant = { open: true, busy: false, q: '', results: [], user: null, planId: this.planId || '', endsAt: '', note: '', error: '' }
    },
    searchCustomers() {
      clearTimeout(this.searchTimer)
      const q = this.grant.q.trim()
      if (q.length < 2) {
        this.grant.results = []
        return
      }
      this.searchTimer = setTimeout(async () => {
        try {
          this.grant.results = await this.getRequest({ api: 'getMembershipCustomers', params: { q } }) || []
        } catch (e) {
          this.grant.results = []
        }
      }, 250)
    },
    async submitGrant() {
      this.grant.busy = true
      this.grant.error = ''
      try {
        const data = await this.setRequest({
          api: 'grantMembership',
          params: { user_id: this.grant.user.id, plan_id: this.grant.planId, ends_at: this.grant.endsAt || null, note: this.grant.note }
        })
        // setRequest hands a refusal back as the response body (status 201)
        if (data && data.status === 201) {
          this.grant.error = data.message || 'The membership was not granted.'
          return
        }
        this.grant.open = false
        this.$router.push(`/memberships/${data.id}`)
      } catch (e) {
        this.grant.error = e?.message || 'The membership was not granted.'
      } finally {
        this.grant.busy = false
      }
    },
    ...mapActions('common', ['getRequest', 'setRequest'])
  },

  mounted() {
    this.loadStats()
    this.loadPlans()
  }
}
</script>

<style scoped>
.ms-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; padding: 20px 20px 0; }
.ms-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; gap: 2px; }
.ms-stat span { font-size: 12px; color: #6b7280; }
.ms-stat strong { font-size: 22px; font-weight: 700; color: #111827; }
.ms-stat small { font-size: 11px; color: #9ca3af; }
.ms-stat.alert strong { color: #b42318; }
.ms-warning { margin: 12px 20px 0; font-size: 13px; color: #b42318; background: #fef3f2; border: 1px solid #fecdca; border-radius: 8px; padding: 10px 14px; }
.sub-note { display: block; font-size: 11px; color: #6b7280; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.pill-on { background: #ecfdf3; color: #027a48; }
.pill-wait { background: #fffaeb; color: #b54708; }
.pill-off { background: #f2f4f7; color: #475467; }
.pill-bad { background: #fef3f2; color: #b42318; }
.ms-form { display: flex; flex-direction: column; gap: 6px; text-align: left; }
.ms-form label { font-size: 13px; font-weight: 600; margin-top: 8px; }
.ms-form input, .ms-form select { width: 100%; }
.ms-results { list-style: none; margin: 4px 0 0; padding: 0; border: 1px solid #e5e7eb; border-radius: 6px; max-height: 180px; overflow-y: auto; }
.ms-results li button { display: block; width: 100%; height: auto; line-height: 1.4; text-align: left; padding: 8px 10px; background: #fff; border: 0; border-bottom: 1px solid #f2f4f7; color: #111827; }
.ms-results li button:hover { background: #f5f7ff !important; color: #111827 !important; }
.ms-results small, .ms-picked small { color: #6b7280; margin-left: 6px; }
.ms-picked { display: flex; justify-content: space-between; align-items: center; background: #f9fafb; border-radius: 6px; padding: 8px 10px; }
.ms-error { color: #b42318; font-size: 13px; margin: 8px 0 0; }
</style>
