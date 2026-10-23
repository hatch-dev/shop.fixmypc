<template>

  <div v-if="$can('user', 'view')" class="cust-page">

    <div v-if="loading" class="spinner-wrapper">
      <spinner :radius="60" color="primary" />
    </div>

    <div v-else-if="!result.id" class="cust-empty">
      <b>{{ $t('pages.registeredUsers.notFound') }}</b>
      <span>{{ $t('pages.registeredUsers.notFoundSub') }}</span>
      <nuxt-link to="/registered-users" class="lite-btn">{{ $t('pages.registeredUsers.backToCustomers') }}</nuxt-link>
    </div>

    <template v-else>

      <!-- ============ OVERVIEW ============ -->
      <div class="cust-top">

        <nuxt-link to="/registered-users" class="cust-back">&larr; {{ $t('pages.registeredUsers.allCustomers') }}</nuxt-link>

        <div class="cust-ident">

          <div class="cust-ident-main">
            <h2 class="cust-name">{{ result.name || '—' }}</h2>
            <p class="cust-sub">
              {{ result.email }}
              <template v-if="result.phone"> · {{ result.phone }}</template>
            </p>
            <p v-if="companyName" class="cust-company">{{ companyName }}</p>
          </div>

          <!--
            Account type first and largest. The list screen could already show
            it, but the person deciding a trade application was reading the
            company name and guessing at the rest.
          -->
          <div class="cust-badges">

            <span class="badge badge-type" :class="'type-' + accountType">
              {{ typeLabel }}
            </span>

            <span class="badge" :class="'tone-' + statusTone">
              {{ $t('pages.registeredUsers.badgeAccount', { label: statusLabel }) }}
            </span>

            <span class="badge" :class="'tone-' + verificationTone">
              {{ $t('pages.registeredUsers.badgeVerification', { label: verificationLabel }) }}
            </span>

            <!-- Trade status is a trade-only fact and is shown only there. -->
            <span
              v-if="accountType === 'trade'"
              class="badge"
              :class="'tone-' + tradeTone"
            >
              {{ $t('pages.registeredUsers.badgeTrade', { label: tradeLabel }) }}
            </span>

          </div>

        </div>

        <dl class="cust-meta">
          <div>
            <dt>{{ $t('pages.registeredUsers.registered') }}</dt>
            <dd>{{ result.created || '—' }}</dd>
          </div>
          <div>
            <dt>{{ $t('pages.registeredUsers.lastUpdated') }}</dt>
            <dd>{{ lastUpdated }}</dd>
          </div>
          <div>
            <dt>{{ $t('pages.registeredUsers.customerId') }}</dt>
            <dd>#{{ result.id }}</dd>
          </div>
          <div>
            <dt>{{ $t('pages.registeredUsers.businessPricing') }}</dt>
            <dd>{{ account.has_business_pricing ? $t('pages.registeredUsers.entitled') : $t('pages.registeredUsers.notEntitled') }}</dd>
          </div>
        </dl>

        <p v-if="account.headline" class="cust-headline">{{ account.headline }}</p>

        <!--
          The staff-facing reason for a suspension. Kept out of the submitted
          data below on purpose: it is an internal remark and must never be
          read back as something the customer told us.
        -->
        <p v-if="account.status_note" class="cust-internal">
          <b>{{ $t('pages.registeredUsers.internalStatusNote') }}</b> {{ account.status_note }}
        </p>

      </div>

      <!-- ============ VIEW DETAILS ============ -->
      <div class="cust-band cust-band-view">
        <h3>{{ $t('pages.registeredUsers.viewDetails') }}</h3>
        <span>{{ $t('pages.registeredUsers.viewDetailsSub', { type: typeLabel.toLowerCase() }) }}</span>
      </div>

      <section
        v-for="section in sections"
        :key="section.key"
        class="cust-card"
      >

        <button class="cust-card-head" @click="toggle(section.key)">
          <h3>{{ section.title }}</h3>
          <span class="cust-chev">{{ isOpen(section.key) ? '−' : '+' }}</span>
        </button>

        <dl v-show="isOpen(section.key)" class="cust-fields">
          <div
            v-for="(field, i) in section.fields"
            :key="i"
            :class="{ 'is-missing': !field.provided }"
          >
            <dt>
              {{ field.label }}
              <small v-if="field.note" class="cust-hint">{{ field.note }}</small>
            </dt>
            <dd>
              <template v-if="field.kind === 'bool'">
                <span class="pill" :class="field.value ? 'pill-yes' : 'pill-no'">
                  {{ field.value ? $t('pages.registeredUsers.yes') : $t('pages.registeredUsers.no') }}
                </span>
              </template>
              <template v-else-if="field.provided">{{ field.value }}</template>
              <template v-else><i class="cust-none">{{ $t('pages.registeredUsers.notProvided') }}</i></template>
            </dd>
          </div>
        </dl>

      </section>

      <!-- Delivery addresses: added by the customer later, not at registration. -->
      <section v-if="deliveryAddresses.length" class="cust-card">

        <button class="cust-card-head" @click="toggle('delivery')">
          <h3>{{ $t('pages.registeredUsers.deliveryAddresses') }} <small>({{ deliveryAddresses.length }})</small></h3>
          <span class="cust-chev">{{ isOpen('delivery') ? '−' : '+' }}</span>
        </button>

        <div v-show="isOpen('delivery')" class="cust-addr-wrap">
          <p class="cust-hint cust-hint-block">
            {{ $t('pages.registeredUsers.deliveryAddressesHint') }}
          </p>
          <div v-for="addr in deliveryAddresses" :key="addr.id" class="cust-addr">
            <b>
              {{ addr.name || result.name }}
              <span v-if="parseInt(addr.default)" class="pill pill-yes">{{ $t('pages.registeredUsers.default') }}</span>
            </b>
            <span>{{ addressLine(addr) }}</span>
            <small v-if="addr.phone">{{ addr.phone }}</small>
          </div>
        </div>

      </section>

      <!--
        Self-serve changes of account type (App\Models\Helper\AccountUpgrade),
        newest first. The details above are what the account holds now; this
        is how it came to be this type.
      -->
      <section v-if="upgradeRequests.length" class="cust-card">

        <button class="cust-card-head" @click="toggle('upgrades')">
          <h3>{{ $t('pages.registeredUsers.accountTypeChanges') }} <small>({{ upgradeRequests.length }})</small></h3>
          <span class="cust-chev">{{ isOpen('upgrades') ? '−' : '+' }}</span>
        </button>

        <div v-show="isOpen('upgrades')" class="cust-addr-wrap">
          <div v-for="u in upgradeRequests" :key="u.id" class="cust-addr">
            <b>
              {{ upgradeTypeName(u.from_type) }} &rarr; {{ upgradeTypeName(u.to_type) }}
              <span class="pill" :class="upgradePill(u.status)">{{ upgradeStatusName(u.status) }}</span>
            </b>
            <span>
              {{ $t('pages.registeredUsers.requestedAt', { date: upgradeWhen(u.created_at) }) }}
              <template v-if="u.reviewed_at">
                · {{ $t('pages.registeredUsers.decidedAt', { date: upgradeWhen(u.reviewed_at) }) }}<template v-if="u.reviewer"> {{ $t('pages.registeredUsers.byName', { name: u.reviewer.name }) }}</template>
              </template>
            </span>
            <small v-if="u.review_note">{{ $t('pages.registeredUsers.noteToCustomer', { note: u.review_note }) }}</small>
            <nuxt-link
              v-if="u.status === 'pending'"
              to="/trade-applications?mode=upgrades"
              class="cust-upgrade-link"
            >
              {{ $t('pages.registeredUsers.reviewInTradeApps') }} &rarr;
            </nuxt-link>
          </div>
        </div>

      </section>

      <!-- What registration never asked for, said plainly. -->
      <section v-if="notCollected.length" class="cust-card cust-card-quiet">
        <button class="cust-card-head" @click="toggle('gaps')">
          <h3>{{ $t('pages.registeredUsers.notCollected') }}</h3>
          <span class="cust-chev">{{ isOpen('gaps') ? '−' : '+' }}</span>
        </button>
        <ul v-show="isOpen('gaps')" class="cust-gaps">
          <li v-for="(note, i) in notCollected" :key="i">{{ note }}</li>
        </ul>
      </section>

      <!-- ============ ADMIN ACTIONS ============ -->
      <div v-if="$can('user', 'edit')" class="cust-band cust-band-act">
        <h3>{{ $t('pages.registeredUsers.adminActions') }}</h3>
        <span>{{ $t('pages.registeredUsers.adminActionsSub') }}</span>
      </div>

      <section v-if="$can('user', 'edit')" class="cust-card cust-actions">

        <p v-if="error" class="cust-error">{{ error }}</p>

        <!-- Trade decision -->
        <div v-if="accountType === 'trade'" class="cust-act-row">
          <div class="cust-act-label">
            <b>{{ $t('pages.registeredUsers.tradeApplication') }}</b>
            <small>{{ $t('pages.registeredUsers.tradeApplicationSub') }}</small>
          </div>
          <div class="cust-act-controls">
            <input
              v-model.trim="tradeNote"
              class="cust-input"
              maxlength="600"
              :placeholder="$t('pages.registeredUsers.tradeNotePlaceholder')"
            >
            <div class="cust-act-btns">
              <button
                v-if="tradeStatus !== 'approved'"
                class="lite-btn act-approve"
                :disabled="!!saving"
                @click="reviewTrade('approved')"
              >{{ $t('pages.registeredUsers.approve') }}</button>
              <button
                v-if="tradeStatus !== 'rejected'"
                class="lite-btn act-reject"
                :disabled="!!saving"
                @click="reviewTrade('rejected')"
              >{{ $t('pages.registeredUsers.reject') }}</button>
              <button
                v-if="tradeStatus === 'approved'"
                class="lite-btn act-suspend"
                :disabled="!!saving"
                @click="reviewTrade('suspended')"
              >{{ $t('pages.registeredUsers.suspendTradePricing') }}</button>
              <button
                v-if="tradeStatus === 'rejected'"
                class="lite-btn"
                :disabled="!!saving"
                @click="reviewTrade('pending')"
              >{{ $t('pages.registeredUsers.backToPending') }}</button>
            </div>
          </div>
        </div>

        <!--
          Orders placed by this customer.

          The order screen links here to identify who placed an order; without
          the return trip an admin holding a customer record had no way to see
          what they had bought other than searching the order list by hand.
        -->
        <div class="cust-act-row">
          <div class="cust-act-label">
            <b>{{ $t('pages.registeredUsers.orders') }}</b>
            <small>{{ $t('pages.registeredUsers.ordersSub') }}</small>
          </div>
          <div class="cust-act-btns">
            <router-link
              class="lite-btn"
              :to="`/orders?user_id=${id}&orderBy=created_at&orderByType=desc`"
            >{{ $t('pages.registeredUsers.viewOrders') }}</router-link>
          </div>
        </div>

        <!-- Company-details verification -->
        <div v-if="accountType !== 'personal'" class="cust-act-row">
          <div class="cust-act-label">
            <b>{{ $t('pages.registeredUsers.companyCheck') }}</b>
            <small>{{ $t('pages.registeredUsers.companyCheckSub') }}</small>
          </div>
          <div class="cust-act-btns">
            <button
              v-if="verificationStatus !== 'verified'"
              class="lite-btn act-approve"
              :disabled="!!saving"
              @click="setVerification('verified')"
            >{{ $t('pages.registeredUsers.markVerified') }}</button>
            <button
              v-if="verificationStatus !== 'rejected'"
              class="lite-btn act-reject"
              :disabled="!!saving"
              @click="setVerification('rejected')"
            >{{ $t('pages.registeredUsers.markRejected') }}</button>
            <button
              v-if="verificationStatus !== 'pending'"
              class="lite-btn"
              :disabled="!!saving"
              @click="setVerification('pending')"
            >{{ $t('pages.registeredUsers.backToUnchecked') }}</button>
          </div>
        </div>

        <!-- Account status -->
        <div class="cust-act-row">
          <div class="cust-act-label">
            <b>{{ $t('pages.registeredUsers.accountStatus') }}</b>
            <small>{{ $t('pages.registeredUsers.accountStatusSub') }}</small>
          </div>
          <div class="cust-act-btns">
            <button
              v-if="isActive"
              class="lite-btn act-suspend"
              :disabled="!!saving"
              @click="setStatus('suspended')"
            >{{ $t('pages.registeredUsers.suspendAccount') }}</button>
            <button
              v-if="accountStatus === 'suspended'"
              class="lite-btn act-approve"
              :disabled="!!saving"
              @click="setStatus('active')"
            >{{ $t('pages.registeredUsers.reactivateAccount') }}</button>
            <span v-if="accountStatus === 'rejected'" class="cust-none">
              {{ $t('pages.registeredUsers.rejectedCannotReactivate') }}
            </span>
          </div>
        </div>

      </section>

    </template>

  </div>

</template>

<script>

  import {mapActions} from 'vuex'
  import Spinner from "~/components/Spinner"
  import util from "~/mixin/util"

  export default {
    name: "customer-detail",
    middleware: ['common-middleware', 'auth'],
    components: {Spinner},
    mixins: [util],

    data() {
      return {
        loading: false,
        saving: null,
        error: '',
        tradeNote: '',
        result: {},
        // Everything open on arrival: this screen exists to be read before a
        // decision, and a reviewer should not have to click six times first.
        closed: {}
      }
    },

    computed: {
      id() {
        return this.$route?.params?.id
      },

      /*
       * Every status read goes through result.account, the object the backend
       * builds in User::accountSummary - the same one the customer's own
       * dashboard reads. Reading raw columns here is how two screens end up
       * describing one account differently.
       */
      account() {
        return this.result?.account || {}
      },

      registration() {
        return this.result?.registration || {}
      },

      sections() {
        return this.registration.sections || []
      },

      notCollected() {
        return this.registration.not_collected || []
      },

      deliveryAddresses() {
        return this.result?.delivery_addresses || []
      },

      upgradeRequests() {
        return this.result?.upgrade_requests || []
      },

      companyName() {
        return this.result?.business_profile?.company_name || ''
      },

      accountType() {
        return this.account.account_type || 'personal'
      },

      accountStatus() {
        return this.account.status || 'active'
      },

      verificationStatus() {
        return this.account.verification_status || 'not_required'
      },

      tradeStatus() {
        return this.account.trade_status || 'pending'
      },

      isActive() {
        return !this.account.status || this.account.status === 'active'
      },

      lastUpdated() {
        const field = this.sections
          .find(s => s.key === 'verification')?.fields
          ?.find(f => f.label === 'Last updated')

        return field?.value || '—'
      },

      typeLabel() {
        return {
        personal: this.$t('pages.registeredUsers.typePersonal'),
        business: this.$t('pages.registeredUsers.typeBusiness'),
        trade: this.$t('pages.registeredUsers.typeTrade')
      }[this.accountType] || this.$t('pages.registeredUsers.typePersonal')
      },

      statusLabel() {
        return {
          active: this.$t('pages.registeredUsers.statusActive'), suspended: this.$t('pages.registeredUsers.statusSuspended'),
          pending: this.$t('pages.registeredUsers.statusPending'), rejected: this.$t('pages.registeredUsers.statusRejected')
        }[this.accountStatus] || this.$t('pages.registeredUsers.statusActive')
      },

      statusTone() {
        if (this.accountStatus === 'suspended' || this.accountStatus === 'rejected') return 'bad'
        if (this.accountStatus === 'pending') return 'wait'
        return 'good'
      },

      verificationLabel() {
        return {
          not_required: this.$t('pages.registeredUsers.verNotRequired'),
          pending: this.$t('pages.registeredUsers.verNotChecked'),
          verified: this.$t('pages.registeredUsers.verVerified'),
          rejected: this.$t('pages.registeredUsers.statusRejected')
        }[this.verificationStatus] || this.$t('pages.registeredUsers.verNotChecked')
      },

      verificationTone() {
        if (this.verificationStatus === 'verified') return 'good'
        if (this.verificationStatus === 'rejected') return 'bad'
        if (this.verificationStatus === 'not_required') return 'mute'
        return 'wait'
      },

      tradeLabel() {
        return {
          pending: this.$t('pages.registeredUsers.statusPending'), approved: this.$t('pages.registeredUsers.statusApproved'),
          rejected: this.$t('pages.registeredUsers.statusRejected'), suspended: this.$t('pages.registeredUsers.statusSuspended')
        }[this.tradeStatus] || this.$t('pages.registeredUsers.statusPending')
      },

      tradeTone() {
        if (this.tradeStatus === 'approved') return 'good'
        if (this.tradeStatus === 'rejected' || this.tradeStatus === 'suspended') return 'bad'
        return 'wait'
      }
    },

    methods: {

      upgradeTypeName(type) {
        return {
        personal: this.$t('pages.registeredUsers.typePersonal'),
        business: this.$t('pages.registeredUsers.typeBusiness'),
        trade: this.$t('pages.registeredUsers.typeTrade')
      }[type] || type
      },

      upgradeStatusName(status) {
        return {
          pending: this.$t('pages.registeredUsers.upgradeWaiting'),
          completed: this.$t('pages.registeredUsers.upgradeDone'),
          approved: this.$t('pages.registeredUsers.statusApproved'),
          rejected: this.$t('pages.registeredUsers.statusRejected'),
          withdrawn: this.$t('pages.registeredUsers.upgradeWithdrawn')
        }[status] || status
      },

      upgradePill(status) {
        return {
          pending: 'pill-wait',
          completed: 'pill-yes',
          approved: 'pill-yes',
          rejected: 'pill-bad'
        }[status] || 'pill-no'
      },

      upgradeWhen(iso) {
        const date = iso ? new Date(iso) : null

        return date && !isNaN(date)
          ? date.toLocaleString(this.$i18n?.locale || 'en', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})
          : '—'
      },

      isOpen(key) {
        return !this.closed[key]
      },

      toggle(key) {
        this.$set(this.closed, key, !this.closed[key])
      },

      addressLine(addr) {
        return [addr.address_1, addr.address_2, addr.city, addr.state, addr.zip, addr.country]
          .filter(Boolean).join(', ')
      },

      async fetchCustomer() {
        try {
          this.loading = true
          this.result = Object.assign({}, await this.getById({
            id: this.id, params: {}, api: 'getUser'
          }) || {})
        } catch (e) {
          this.error = e?.message || this.$t('pages.registeredUsers.loadFailed')
        } finally {
          this.loading = false
        }
      },

      /*
       * Repaint from the record the server sends back rather than assuming the
       * change landed. Every one of these transitions can be refused - a
       * pending application cannot be suspended, a rejected account cannot be
       * reactivated - and the reviewer has to see the state the account is
       * actually in, not the one they asked for.
       */
      applyResponse(updated) {
        if (!updated) {
          return
        }

        this.result = Object.assign({}, this.result, updated)
      },

      async setStatus(status) {
        const wording = status === 'suspended'
          ? this.$t('pages.registeredUsers.suspendConfirm')
          : this.$t('pages.registeredUsers.reactivateConfirm')

        if (!confirm(wording)) {
          return
        }

        await this.run('status', () => this.setById({
          id: this.id, api: 'setUserStatus', params: {status, status_note: null}
        }))
      },

      async setVerification(status) {
        await this.run('verification', () => this.setById({
          id: this.id, api: 'setUserVerification', params: {verification_status: status}
        }))
      },

      async reviewTrade(status) {
        const wording = {
          approved: this.$t('pages.registeredUsers.confirmApproveTrade'),
          rejected: this.$t('pages.registeredUsers.confirmRejectTrade'),
          suspended: this.$t('pages.registeredUsers.confirmSuspendTrade'),
          pending: this.$t('pages.registeredUsers.confirmPendingTrade')
        }[status]

        if (!confirm(wording)) {
          return
        }

        await this.run('trade', () => this.setById({
          id: this.id,
          api: 'setTradeApplication',
          params: {trade_status: status, trade_review_note: this.tradeNote || null}
        }))
      },

      async run(key, action) {
        this.error = ''
        this.saving = key

        try {
          this.applyResponse(await action())
          // The registration block and the badges both come from the record,
          // so re-read rather than patching fields one at a time.
          await this.fetchCustomer()
        } catch (e) {
          this.error = e?.message || this.$t('error.err') || this.$t('pages.registeredUsers.changeRejected')
        } finally {
          this.saving = null
        }
      },

      ...mapActions('common', ['getById', 'setById'])
    },

    mounted() {
      this.fetchCustomer()
    }
  }

</script>

<style scoped>

.cust-page { max-width: 1100px; }

.cust-back {
  display: inline-block;
  margin-bottom: 12px;
  font-size: 13px;
  color: #4B5563;
}

/* ---- overview ---- */
.cust-top {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: 18px 20px;
  margin-bottom: 18px;
}

.cust-ident {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  justify-content: space-between;
  align-items: flex-start;
}

.cust-name { margin: 0; font-size: 20px; }

.cust-sub,
.cust-company {
  margin: 4px 0 0;
  font-size: 13px;
  color: #6B7280;
}

.cust-company { font-weight: 600; color: #374151; }

.cust-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}

.badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  background: #F3F4F6;
  color: #374151;
}

.badge-type {
  font-size: 12px;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.type-business { background: #EFF6FF; color: #1D4ED8; }
.type-trade    { background: #F5F3FF; color: #6D28D9; }
.type-personal { background: #F3F4F6; color: #374151; }

.tone-good { background: #ECFDF5; color: #047857; }
.tone-wait { background: #FFFBEB; color: #B45309; }
.tone-bad  { background: #FEF2F2; color: #B91C1C; }
.tone-mute { background: #F3F4F6; color: #6B7280; }

.cust-meta {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  margin: 16px 0 0;
  padding-top: 14px;
  border-top: 1px solid #F3F4F6;
}

.cust-meta dt {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #9CA3AF;
}

.cust-meta dd { margin: 2px 0 0; font-size: 13px; color: #111827; }

.cust-headline {
  margin: 14px 0 0;
  padding: 9px 12px;
  background: #F9FAFB;
  border-radius: 6px;
  font-size: 13px;
  color: #374151;
}

/* Internal remarks are tinted differently from anything the customer wrote. */
.cust-internal {
  margin: 8px 0 0;
  padding: 9px 12px;
  background: #FFF7ED;
  border-left: 3px solid #FB923C;
  border-radius: 4px;
  font-size: 13px;
  color: #7C2D12;
}

/* ---- band headings separating reading from doing ---- */
.cust-band {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 10px;
  margin: 22px 0 10px;
  padding-bottom: 6px;
  border-bottom: 2px solid #E5E7EB;
}

.cust-band h3 { margin: 0; font-size: 14px; text-transform: uppercase; letter-spacing: .05em; }
.cust-band span { font-size: 12px; color: #6B7280; }
.cust-band-view { border-bottom-color: #93C5FD; }
.cust-band-act  { border-bottom-color: #FCA5A5; }
.cust-band-act h3 { color: #B91C1C; }

/* ---- cards ---- */
.cust-card {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  margin-bottom: 12px;
  overflow: hidden;
}

.cust-card-quiet { background: #FAFAFA; }

.cust-card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  padding: 12px 18px;
  border: 0;
  background: #F9FAFB;
  cursor: pointer;
  text-align: left;
}

.cust-card-head h3 { margin: 0; font-size: 14px; font-weight: 600; }
.cust-card-head small { font-weight: 400; color: #9CA3AF; }
.cust-chev { font-size: 18px; color: #6B7280; line-height: 1; }

.cust-fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 14px 24px;
  margin: 0;
  padding: 16px 18px;
}

.cust-fields dt {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #9CA3AF;
}

.cust-fields dd {
  margin: 3px 0 0;
  font-size: 14px;
  color: #111827;
  word-break: break-word;
}

.cust-hint {
  display: block;
  margin-top: 2px;
  font-size: 10px;
  text-transform: none;
  letter-spacing: 0;
  color: #C0C4CC;
}

.cust-hint-block {
  margin: 0 0 10px;
  font-size: 12px;
  color: #9CA3AF;
}

.cust-none { color: #9CA3AF; font-style: italic; font-size: 13px; }
.is-missing dd { color: #9CA3AF; }

.pill {
  display: inline-block;
  padding: 2px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
}

.pill-yes { background: #ECFDF5; color: #047857; }
.pill-no  { background: #F3F4F6; color: #6B7280; }
.pill-wait { background: #FFFBEB; color: #B45309; }
.pill-bad  { background: #FEF2F2; color: #B91C1C; }

.cust-upgrade-link {
  display: table;
  margin-top: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #1D4ED8;
}

/* ---- addresses ---- */
.cust-addr-wrap { padding: 16px 18px; }

.cust-addr {
  display: block;
  padding: 10px 0;
  border-bottom: 1px solid #F3F4F6;
  font-size: 13px;
}

.cust-addr:last-child { border-bottom: 0; }
.cust-addr b { display: block; margin-bottom: 2px; }
.cust-addr small { display: block; color: #9CA3AF; margin-top: 2px; }

.cust-gaps { margin: 0; padding: 14px 18px 14px 34px; }
.cust-gaps li { font-size: 12px; color: #6B7280; margin-bottom: 5px; }

/* ---- actions ---- */
.cust-actions { padding: 4px 18px 16px; }

.cust-act-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  align-items: flex-start;
  padding: 14px 0;
  border-bottom: 1px solid #F3F4F6;
}

.cust-act-row:last-child { border-bottom: 0; }
.cust-act-label { flex: 1 1 240px; }
.cust-act-label b { display: block; font-size: 13px; }
.cust-act-label small { display: block; margin-top: 2px; font-size: 12px; color: #9CA3AF; }

.cust-act-controls { flex: 1 1 320px; }

.cust-act-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.cust-input {
  width: 100%;
  margin-bottom: 8px;
  padding: 8px 10px;
  border: 1px solid #D1D5DB;
  border-radius: 6px;
  font-size: 13px;
}

.act-approve { background: #ECFDF5; color: #047857; }
.act-reject  { background: #FEF2F2; color: #B91C1C; }
.act-suspend { background: #FFFBEB; color: #B45309; }

.cust-error {
  margin: 12px 0 0;
  padding: 9px 12px;
  background: #FEF2F2;
  border-radius: 6px;
  color: #B91C1C;
  font-size: 13px;
}

.cust-empty {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
  padding: 40px;
}

@media (max-width: 700px) {
  .cust-fields { grid-template-columns: 1fr; }
  .cust-badges { width: 100%; }
  .cust-act-row { flex-direction: column; }
}

</style>
