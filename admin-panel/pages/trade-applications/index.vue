<template>

  <div class="ta-page">

    <div class="ta-head">

      <div>
        <h2 class="ta-title">Trade applications</h2>
        <p class="ta-lede">
          Trade accounts order at retail prices until they are approved here.
          Approving switches trade pricing on for that customer.
        </p>
      </div>

    </div>

    <!--
      Two queues. Sign-ups registered as trade and are trade accounts already,
      waiting for pricing. Upgrades are existing personal or business customers
      applying from their account page: their type does not change until one
      of these is approved, so a business customer keeps business pricing
      while it waits.
    -->
    <div class="ta-modes" role="tablist">
      <button
        v-for="m in modes"
        :key="m.key"
        role="tab"
        class="ta-mode"
        :class="{ on: mode === m.key }"
        :aria-selected="mode === m.key ? 'true' : 'false'"
        @click="setMode(m.key)"
      >
        {{ m.label }}
      </button>
    </div>

    <!-- FILTERS -->
    <div class="ta-filters">

      <button
        v-for="tab in currentTabs"
        :key="tab.key"
        class="ta-tab"
        :class="{ on: currentStatus === tab.key }"
        @click="setStatus(tab.key)"
      >
        {{ tab.label }}
      </button>

      <input
        v-model.trim="search"
        class="ta-search"
        placeholder="Search name or email"
        @keyup.enter="reload"
      >

    </div>

    <spinner v-if="loading" />

    <template v-else-if="mode === 'signups'">

    <div
      v-if="!applications.length"
      class="ta-empty"
    >
      <b>Nothing here</b>
      <span>No trade applications with this status.</span>
    </div>

    <div
      v-else
      class="ta-list"
    >

      <article
        v-for="item in applications"
        :key="item.id"
        class="ta-card"
      >

        <div class="ta-card-head">

          <div>
            <h3>{{ profileOf(item).company_name || item.name }}</h3>
            <p class="ta-contact">
              {{ item.name }} · {{ item.email }}
              <template v-if="item.phone"> · {{ item.phone }}</template>
            </p>
          </div>

          <span
            class="ta-tag"
            :class="item.trade_status"
          >
            {{ statusLabel(item.trade_status) }}
          </span>

        </div>

        <dl class="ta-facts">

          <div>
            <dt>Trade</dt>
            <dd>{{ profileOf(item).trade || '—' }}</dd>
          </div>

          <div>
            <dt>VAT number</dt>
            <dd>{{ profileOf(item).vat_number || '—' }}</dd>
          </div>

          <div>
            <dt>CRO number</dt>
            <dd>{{ profileOf(item).cro_number || '—' }}</dd>
          </div>

          <div>
            <dt>Expected spend</dt>
            <dd>{{ profileOf(item).expected_spend || '—' }}</dd>
          </div>

          <div>
            <dt>Website</dt>
            <dd>{{ profileOf(item).website || '—' }}</dd>
          </div>

          <div>
            <dt>Applied</dt>
            <dd>{{ item.created || '—' }}</dd>
          </div>

          <div class="ta-wide">
            <dt>Business address</dt>
            <dd>{{ profileOf(item).billing_address || '—' }}</dd>
          </div>

          <div class="ta-wide">
            <dt>Resale confirmed</dt>
            <dd>{{ profileOf(item).resale_confirmed ? 'Yes' : 'No' }}</dd>
          </div>

          <!--
            Account status and the company-details check are separate facts
            from the trade decision, and both bear on it: approving a
            suspended account grants pricing it cannot use.
          -->
          <div>
            <dt>Account status</dt>
            <dd>{{ accountStatusOf(item) }}</dd>
          </div>

          <div>
            <dt>Details checked</dt>
            <dd>{{ verificationOf(item) }}</dd>
          </div>

        </dl>

        <!--
          This card carries what the decision turns on. The rest of the
          registration - preferences, contact confirmation, address history -
          is on the customer record, one click away rather than three.
        -->
        <nuxt-link
          :to="'/registered-users/' + item.id"
          class="ta-full"
        >
          View full customer record &rarr;
        </nuxt-link>

        <div
          v-if="item.trade_review_note"
          class="ta-note"
        >
          <b>Note to the customer:</b> {{ item.trade_review_note }}
        </div>

        <div class="ta-acts">

          <!--
            This note is shown to the customer: it already appears on their
            dashboard, and it is now included in the decision email. The old
            placeholder read "saved with your decision", which does not tell a
            reviewer that, and an ambiguous label on a customer-facing field is
            how internal remarks end up in somebody's inbox.
          -->
          <input
            v-model.trim="notes[item.id]"
            class="ta-note-input"
            maxlength="600"
            placeholder="Note to the customer (optional) — shown on their account and in the decision email"
          >

          <button
            v-if="$can('user', 'edit') && item.trade_status !== 'approved'"
            class="lite-btn ta-approve"
            :disabled="saving === item.id"
            @click="review(item, 'approved')"
          >
            Approve
          </button>

          <button
            v-if="$can('user', 'edit') && item.trade_status !== 'rejected'"
            class="lite-btn ta-reject"
            :disabled="saving === item.id"
            @click="review(item, 'rejected')"
          >
            Reject
          </button>

          <!--
            Withdrawing trade pricing from an account that has it. Distinct
            from rejecting: the application stood, and suspending says so, so
            the note the reviewer leaves reads correctly later.
          -->
          <button
            v-if="$can('user', 'edit') && item.trade_status === 'approved'"
            class="lite-btn ta-suspend"
            :disabled="saving === item.id"
            @click="review(item, 'suspended')"
          >
            Suspend trade pricing
          </button>

          <!--
            Only offered where the backend will accept it. Every other move
            from here is refused by AccountStatus::TRADE_TRANSITIONS, and a
            button that always errors teaches the reviewer to distrust the
            screen.
          -->
          <button
            v-if="$can('user', 'edit') && item.trade_status === 'rejected'"
            class="lite-btn"
            :disabled="saving === item.id"
            @click="review(item, 'pending')"
          >
            Back to pending
          </button>

        </div>

      </article>

    </div>

    </template>

    <!-- ── upgrades from existing accounts ── -->
    <template v-else>

    <div
      v-if="!upgrades.length"
      class="ta-empty"
    >
      <b>Nothing here</b>
      <span>
        {{ upgradeStatus === 'pending'
          ? 'No existing customers are waiting for a trade decision.'
          : 'No upgrade applications with this status.' }}
      </span>
    </div>

    <div
      v-else
      class="ta-list"
    >

      <article
        v-for="item in upgrades"
        :key="'u' + item.id"
        class="ta-card"
      >

        <div class="ta-card-head">

          <div>
            <h3>{{ (item.details && item.details.company_name) || (item.user && item.user.name) || 'Customer removed' }}</h3>
            <p v-if="item.user" class="ta-contact">
              {{ item.user.name }} · {{ item.user.email }}
              <template v-if="item.user.phone"> · {{ item.user.phone }}</template>
            </p>
          </div>

          <div class="ta-tags">
            <span class="ta-move">{{ typeLabel(item.from_type) }} &rarr; {{ typeLabel(item.to_type) }}</span>
            <span class="ta-tag" :class="item.status">{{ upgradeStatusLabel(item.status) }}</span>
          </div>

        </div>

        <dl class="ta-facts">

          <!-- Browser time, like Decided below: the server formats in its own zone. -->
          <div>
            <dt>Applied</dt>
            <dd>{{ formatWhen(item.created_at) }}</dd>
          </div>

          <!--
            What the customer is charged today. For a business applicant it
            stays business pricing whatever happens here; for a personal one,
            approval is what switches it on.
          -->
          <div>
            <dt>Pricing now</dt>
            <dd>{{ pricingNow(item) }}</dd>
          </div>

          <div>
            <dt>Account status</dt>
            <dd>{{ accountStatusOf(item.user) }}</dd>
          </div>

          <div>
            <dt>Details checked</dt>
            <dd>{{ verificationOf(item.user) }}</dd>
          </div>

          <div v-if="item.reviewed_at">
            <dt>Decided</dt>
            <dd>
              {{ formatWhen(item.reviewed_at) }}
              <template v-if="item.reviewed_by"> by {{ item.reviewed_by.name }}</template>
            </dd>
          </div>

        </dl>

        <!-- What the account holds against what the customer submitted. -->
        <div class="ta-changes" role="table" :aria-label="'Submitted details for application ' + item.id">
          <div class="ta-changes-row head" role="row">
            <span role="columnheader">Field</span>
            <span role="columnheader">{{ item.status === 'pending' ? 'On the account now' : 'On the account' }}</span>
            <span role="columnheader">Submitted</span>
          </div>
          <div
            v-for="row in visibleChanges(item)"
            :key="row.field"
            class="ta-changes-row"
            :class="{ changed: changeBadge(item, row) === 'Changed' }"
            role="row"
          >
            <span class="ta-changes-label" role="rowheader">{{ row.label }}</span>
            <span role="cell">{{ showValue(row.current) }}</span>
            <span role="cell">
              {{ showValue(row.submitted) }}
              <span
                v-if="changeBadge(item, row)"
                class="ta-changed"
                :class="{ 'is-new': changeBadge(item, row) === 'New' }"
              >{{ changeBadge(item, row) }}</span>
            </span>
          </div>
        </div>

        <p v-if="identityChanged(item)" class="ta-warn">
          The company name, VAT or CRO number differs from the details on this account<template
            v-if="item.user && item.user.account && item.user.account.verification_status === 'verified'"
          >, which we have verified</template>. Approving replaces them with the submitted ones.
        </p>

        <nuxt-link
          v-if="item.user"
          :to="'/registered-users/' + item.user.id"
          class="ta-full"
        >
          View full customer record &rarr;
        </nuxt-link>

        <div
          v-if="item.review_note"
          class="ta-note"
        >
          <b>Note to the customer:</b> {{ item.review_note }}
        </div>

        <div
          v-if="item.status === 'pending' && $can('user', 'edit')"
          class="ta-acts"
        >

          <input
            v-model.trim="upgradeNotes[item.id]"
            class="ta-note-input"
            maxlength="600"
            placeholder="Note to the customer (optional) — shown on their account and in the decision email"
          >

          <button
            class="lite-btn ta-approve"
            :disabled="saving === 'u' + item.id"
            @click="decideUpgrade(item, 'approved')"
          >
            Approve
          </button>

          <button
            class="lite-btn ta-reject"
            :disabled="saving === 'u' + item.id"
            @click="decideUpgrade(item, 'rejected')"
          >
            Reject
          </button>

        </div>

      </article>

    </div>

    </template>

  </div>

</template>

<script>

  import {mapActions} from 'vuex'
  import util from '~/mixin/util'
  import Spinner from "~/components/Spinner"

  export default {

    name: "trade-applications",

    middleware: ['common-middleware', 'auth'],

    components: {Spinner},

    mixins: [util],

    data() {
      return {
        applications: [],
        notes: {},
        search: '',
        status: 'pending',
        loading: true,
        saving: null,
        tabs: [
          {key: 'pending', label: 'Pending'},
          {key: 'approved', label: 'Approved'},
          {key: 'rejected', label: 'Rejected'},
          // Approved once, withdrawn since. Not the same as never approved,
          // and a reviewer needs to be able to find them again.
          {key: 'suspended', label: 'Suspended'},
          {key: 'all', label: 'All'}
        ],
        mode: 'signups',
        modes: [
          {key: 'signups', label: 'New trade sign-ups'},
          {key: 'upgrades', label: 'Upgrades from existing accounts'}
        ],
        upgrades: [],
        upgradeNotes: {},
        upgradeStatus: 'pending',
        upgradeTabs: [
          {key: 'pending', label: 'Pending'},
          {key: 'approved', label: 'Approved'},
          {key: 'rejected', label: 'Rejected'},
          {key: 'withdrawn', label: 'Withdrawn'},
          // Personal -> business: immediate, nothing to decide, kept for the record.
          {key: 'completed', label: 'Business upgrades'},
          {key: 'all', label: 'All'}
        ]
      }
    },

    computed: {
      currentTabs() {
        return this.mode === 'upgrades' ? this.upgradeTabs : this.tabs
      },

      currentStatus() {
        return this.mode === 'upgrades' ? this.upgradeStatus : this.status
      }
    },

    methods: {

      profileOf(item) {
        return item.business_profile || {}
      },

      accountStatusOf(item) {
        return {
          active: 'Active',
          suspended: 'Suspended',
          pending: 'Pending',
          rejected: 'Rejected'
        }[item?.account?.status] || 'Active'
      },

      verificationOf(item) {
        return {
          pending: 'Not checked',
          verified: 'Verified',
          rejected: 'Rejected',
          not_required: 'Not required'
        }[item?.account?.verification_status] || 'Not checked'
      },

      statusLabel(status) {
        return {
          pending: 'Pending review',
          approved: 'Approved',
          rejected: 'Rejected',
          suspended: 'Suspended'
        }[status] || status || '—'
      },

      setStatus(status) {
        if (this.mode === 'upgrades') {
          this.upgradeStatus = status
        } else {
          this.status = status
        }

        this.reload()
      },

      setMode(mode) {
        if (this.mode === mode) {
          return
        }

        this.mode = mode
        // Kept in the URL so the customer record can link straight here.
        this.$router.replace({query: {...this.$route.query, mode: mode === 'upgrades' ? 'upgrades' : undefined}})
        this.reload()
      },

      reload() {
        return this.mode === 'upgrades' ? this.getUpgrades() : this.getApplications()
      },

      /* ── upgrade applications ── */

      async getUpgrades() {

        try {
          this.loading = true

          const result = await this.getRequest({
            params: {status: this.upgradeStatus, q: this.search},
            api: 'getTradeUpgrades'
          })

          this.upgrades = result?.data || []

        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.loading = false
        }
      },

      async decideUpgrade(item, decision) {

        try {
          this.saving = 'u' + item.id

          const updated = await this.setById({
            id: item.id,
            api: 'setTradeUpgrade',
            params: {
              decision,
              review_note: this.upgradeNotes[item.id] || ''
            }
          })

          /*
           * A refusal resolves with nothing (the store keeps the reason in
           * ui/errors) - e.g. the customer withdrew it a moment ago. Say so
           * and show where it really stands.
           */
          if (!updated) {
            const errors = this.$store.getters['ui/errors'] || {}
            const first = Object.values(errors).flat().find(Boolean)
            this.$store.dispatch('ui/setToastError', first || this.$t('error.err'))
            await this.getUpgrades()
            return
          }

          if (this.upgradeStatus !== 'all' && this.upgradeStatus !== updated.status) {
            this.upgrades = this.upgrades.filter(i => i.id !== item.id)
          } else {
            this.$set(this.upgrades, this.upgrades.indexOf(item), updated)
          }

          this.$set(this.upgradeNotes, item.id, '')

          this.reportDecision(decision, updated.notification)

        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.saving = null
        }
      },

      typeLabel(type) {
        return {personal: 'Personal', business: 'Business', trade: 'Trade'}[type] || type
      },

      upgradeStatusLabel(status) {
        return {
          pending: 'Pending review',
          approved: 'Approved',
          rejected: 'Rejected',
          withdrawn: 'Withdrawn',
          completed: 'Done'
        }[status] || status || '—'
      },

      pricingNow(item) {
        return item?.user?.account?.has_business_pricing ? 'Business pricing' : 'Retail'
      },

      hasValue(value) {
        return value !== null && value !== undefined && value !== '' && value !== false
      },

      // Rows where neither side says anything are noise.
      visibleChanges(item) {
        return (item.changes || []).filter(row => this.hasValue(row.current) || this.hasValue(row.submitted))
      },

      showValue(value) {
        if (value === true) return 'Yes'
        if (value === false) return 'No'

        return this.hasValue(value) ? value : '—'
      },

      /*
       * "Changed" only where the account held something else; a trade answer a
       * business profile never had is "New". Nothing is badged for a personal
       * applicant (every field is new) or once the application is decided.
       */
      changeBadge(item, row) {
        if (item.status !== 'pending' || item.from_type === 'personal' || !row.changed) {
          return null
        }

        return this.hasValue(row.current) ? 'Changed' : 'New'
      },

      /*
       * A business applying for trade may change who the company is. Approving
       * replaces the verified details, so that is spelled out rather than left
       * to a highlighted row.
       */
      identityChanged(item) {
        return item.status === 'pending'
          && item.from_type === 'business'
          && (item.changes || []).some(row => ['company_name', 'vat_number', 'cro_number'].includes(row.field) && row.changed)
      },

      formatWhen(iso) {
        const date = iso ? new Date(iso) : null

        return date && !isNaN(date)
          ? date.toLocaleString('en-IE', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})
          : '—'
      },

      async review(item, status) {

        try {
          this.saving = item.id

          const updated = await this.setById({
            id: item.id,
            api: 'setTradeApplication',
            params: {
              trade_status: status,
              trade_review_note: this.notes[item.id] || ''
            }
          })

          if (updated) {
            // Drop it from the list when it no longer matches the filter.
            if (this.status !== 'all' && this.status !== status) {
              this.applications = this.applications.filter(i => i.id !== item.id)
            } else {
              this.$set(this.applications, this.applications.indexOf(item), updated)
            }

            this.$set(this.notes, item.id, '')

            this.reportDecision(status, updated.notification)
          }

        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.saving = null
        }
      },

      /*
       * Say what actually happened to the customer's email.
       *
       * The server reports three separate facts and this reads all of them,
       * because "approved" and "the customer has been told" are not the same
       * event and an admin who assumes they are will not chase the one that
       * failed. Sent and queued are also different promises: the API answers
       * from the queue driver the install is configured with, so this never
       * claims a message is waiting in a queue that does not exist.
       */
      reportDecision(status, notification) {
        const decided = status === 'approved'
          ? 'Trade application approved successfully.'
          : status === 'rejected'
            ? 'Trade application rejected successfully.'
            : 'Trade application updated.'

        // A decision that changed nothing - the same status submitted twice.
        if (notification && !notification.triggered) {
          this.$store.dispatch('ui/setToastMessage',
            `${decided} This was already the status, so no new notification was sent.`)
          return
        }

        // Approval and rejection are the only decisions that email anybody.
        if (status !== 'approved' && status !== 'rejected') {
          this.$store.dispatch('ui/setToastMessage', decided)
          return
        }

        if (notification && notification.delivered) {
          this.$store.dispatch('ui/setToastMessage', notification.queued
            ? `${decided} Customer notification has been queued.`
            : `${decided} Customer notification has been sent.`)
          return
        }

        /*
         * The decision stands and the customer's pricing has already changed;
         * only the email failed. Said plainly, as a warning rather than an
         * error, so nobody re-approves an account trying to fix it.
         */
        this.$store.dispatch('ui/setToastError',
          `${decided} The customer could not be emailed — check their address and the mail log.`)
      },

      async getApplications() {

        try {
          this.loading = true

          const result = await this.getRequest({
            params: {status: this.status, q: this.search},
            api: 'getTradeApplications'
          })

          this.applications = result?.data || []

        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.loading = false
        }
      },

      ...mapActions('common', ['getRequest', 'setById'])
    },

    mounted() {
      if (this.$route.query.mode === 'upgrades') {
        this.mode = 'upgrades'
      }

      if (this.$can('user', 'view')) {
        this.reload()
      } else {
        this.loading = false
      }
    }
  }
</script>

<style lang="stylus">

$ink = #16174A
$brand = #2D2DA0
$brand-50 = #F1F1FC
$go = #00A24A
$line = #E4E5F3
$line-strong = #CFD1E8
$muted = #6B7191
$danger = #C8324B
$amber = #B26A00

.ta-page
  padding 4px 0 40px

.ta-head
  margin-bottom 20px

.ta-title
  font-size 24px
  margin 0 0 4px
  letter-spacing -0.02em
  color $ink

.ta-lede
  color $muted
  margin 0
  max-width 70ch
  font-size 14px

.ta-modes
  display inline-flex
  gap 4px
  padding 4px
  margin-bottom 16px
  background #F1F2F8
  border-radius 12px

.ta-mode
  height 36px
  line-height 1
  border 0
  border-radius 9px
  padding 0 16px
  font-size 13.5px
  font-weight 700
  color $muted
  background transparent
  cursor pointer

  &:hover
    color $brand

  // base.styl's button hover outranks any class rule on background.
  &.on
    background #fff !important
    color $ink
    box-shadow 0 1px 2px rgba(22, 23, 74, .12)

.ta-filters
  display flex
  align-items center
  gap 8px
  flex-wrap wrap
  margin-bottom 20px

.ta-tab
  height 36px
  line-height 1
  border 1px solid $line-strong
  background #fff
  border-radius 999px
  padding 8px 16px
  font-size 13px
  font-weight 700
  color $muted
  cursor pointer

  &:hover
    border-color $brand
    color $brand

  &.on
    background $brand
    border-color $brand
    color #fff

.ta-search
  margin-left auto
  min-width 240px
  border 1px solid $line-strong
  border-radius 999px
  padding 9px 16px
  font-size 13.5px

.ta-list
  display grid
  gap 16px

.ta-card
  background #fff
  border 1px solid $line
  border-radius 16px
  padding 18px 20px

.ta-card-head
  display flex
  align-items flex-start
  justify-content space-between
  gap 16px
  flex-wrap wrap
  margin-bottom 14px

  h3
    margin 0 0 4px
    font-size 16.5px
    color $ink

.ta-contact
  margin 0
  font-size 13px
  color $muted

.ta-tag
  font-size 11px
  font-weight 700
  padding 5px 11px
  border-radius 999px
  white-space nowrap
  background #FFF6E6
  color $amber

  &.approved
    background #EAF9F0
    color #0B6B3A

  &.rejected
    background #FDF2F4
    color $danger

  // Grey, not red: the account still holds an approved application.
  &.suspended,
  &.withdrawn
    background #F1F2F4
    color #55606E

  &.completed
    background #EFF6FF
    color #1D4ED8

.ta-tags
  display flex
  align-items center
  gap 6px
  flex-wrap wrap

.ta-move
  font-size 11px
  font-weight 700
  padding 5px 11px
  border-radius 999px
  background $brand-50
  color $brand
  white-space nowrap

// Current vs submitted. A grid, not a <table>: unscoped admin table rules
// restyle every table on the page.
.ta-changes
  border 1px solid $line
  border-radius 12px
  overflow hidden
  margin 0 0 14px
  font-size 13px

.ta-changes-row
  display grid
  grid-template-columns minmax(140px, 1fr) 2fr 2fr
  gap 12px
  padding 8px 14px
  border-top 1px solid $line
  color $ink
  word-break break-word

  &:first-child
    border-top 0

  &.head
    background #F7F7FD
    font-size 11px
    font-weight 800
    letter-spacing 0.08em
    text-transform uppercase
    color $muted

  &.changed
    background #FFFBEB

.ta-changes-label
  font-weight 600
  color $muted

.ta-changed
  display inline-block
  margin-left 6px
  font-size 10.5px
  font-weight 800
  padding 1px 7px
  border-radius 999px
  background #FDE68A
  color #7C4A03

.ta-changed.is-new
  background #E0E7FF
  color #3730A3

.ta-warn
  background #FFFBEB
  border 1px solid #F5D78E
  border-radius 10px
  padding 10px 12px
  font-size 13px
  color #7C4A03
  margin 0 0 14px

.ta-facts
  display grid
  grid-template-columns repeat(auto-fill, minmax(180px, 1fr))
  gap 12px 18px
  margin 0 0 14px
  padding 14px 0
  border-top 1px solid $line
  border-bottom 1px solid $line

  dt
    font-size 11px
    font-weight 800
    letter-spacing 0.08em
    text-transform uppercase
    color $muted
    margin-bottom 3px

  dd
    margin 0
    font-size 13.5px
    color $ink
    word-break break-word

.ta-wide
  grid-column 1 / -1

.ta-full
  display inline-block
  margin-bottom 14px
  font-size 13px
  font-weight 600
  color #1D4ED8

.ta-note
  background #F7F7FD
  border 1px solid $line
  border-radius 10px
  padding 10px 12px
  font-size 13px
  color $ink
  margin-bottom 14px

.ta-acts
  display flex
  align-items center
  gap 8px
  flex-wrap wrap

.ta-note-input
  flex 1
  min-width 200px
  border 1px solid $line-strong
  border-radius 999px
  padding 9px 16px
  font-size 13px

.ta-approve
  color #0B6B3A
  border-color #C6EBD4

.ta-reject
  color $danger
  border-color #EFC3CC

// Amber, not red: withdrawing pricing is reversible, rejecting is a verdict.
.ta-suspend
  color #A35B00
  border-color #F2D8AE

.ta-empty
  background #fff
  border 1px dashed $line-strong
  border-radius 16px
  padding 40px
  text-align center
  color $muted

  b
    display block
    color $ink
    font-size 15px
    margin-bottom 6px

</style>
