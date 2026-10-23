<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setLoyaltyGroup"
    get-api="getLoyaltyGroup"
    route-name="loyalty-groups"
    :name="$t('pages.loyaltyGroups.loyaltyGroup')"
    :validation-keys="validationKeys"
    :result="result"
    @result="settingResult"
  >

    <template v-slot:form="{hasError}">

      <!--
        Tabs, not two stacked cards. The member picker is long, so the
        redemption history sat far below the fold and an admin had to scroll
        past the whole form to reach it.

        Only rendered when editing: a group being created has no history, so a
        single-tab bar would be decoration.
      -->
      <div v-if="isEditing" class="lg-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="String(detailsVisible(hasError))"
          :class="['lg-tab', {active: detailsVisible(hasError)}]"
          @click="activeTab = 'details'"
        >
          {{ $t('pages.loyaltyGroups.groupDetails') }}
          <!-- A validation failure must be findable from the other tab. -->
          <span v-if="hasError" class="lg-tab-dot" :aria-label="$t('pages.loyaltyGroups.hasErrors')"></span>
        </button>

        <button
          type="button"
          role="tab"
          :aria-selected="String(!detailsVisible(hasError))"
          :class="['lg-tab', {active: !detailsVisible(hasError)}]"
          @click="activeTab = 'redemptions'"
        >
          {{ $t('pages.loyaltyGroups.redemptions') }}
          <span v-if="redemptionCount" class="lg-tab-count">{{ redemptionCount }}</span>
        </button>
      </div>

      <!--
        v-show rather than v-if: the form inputs stay mounted, so switching
        tabs cannot discard half-typed values or the member selection.
      -->
      <div v-show="detailsVisible(hasError)">

      <div class="card">

        <!-- TITLE + STATUS -->
        <div class="row-fields">

          <div class="input-wrapper half">
            <label>{{ $t('pages.loyaltyGroups.titleRequiredLabel') }}</label>
            <input
              type="text"
              :placeholder="$t('pages.loyaltyGroups.groupTitle')"
              v-model="result.title"
              :class="{invalid: !result.title && hasError}"
            >
            <span class="error" v-if="!result.title && hasError">
              {{ $t('pages.loyaltyGroups.titleIsRequired') }}
            </span>
            <span class="field-hint">
              {{ $t('pages.loyaltyGroups.internalOnly') }}
            </span>
          </div>

          <div class="input-wrapper half">
            <label>{{ $t('category.status') }}</label>
            <dropdown
              :selectedKey="result.status ? 'active' : 'inactive'"
              :options="statusOptions"
              @clicked="statusSelected"
            />
            <span class="field-hint">
              {{ $t('pages.loyaltyGroups.inactiveHint') }}
            </span>
          </div>

        </div>

        <!--
          Separate from the title on purpose: the title is filing, this is copy.
          Without it the internal name was printed on the shopper's order
          summary, which is how a customer's basket read "Group 1  - €250.00".
        -->
        <div class="input-wrapper">
          <label>{{ $t('pages.loyaltyGroups.labelShownToCustomers') }}</label>
          <input
            type="text"
            maxlength="100"
            :placeholder="$t('pages.loyaltyGroups.loyaltyDiscount')"
            v-model="result.customer_label"
          >
          <span class="field-hint">
            {{ $t('pages.loyaltyGroups.customerLabelHint') }}
          </span>
        </div>

        <!-- DISCOUNT -->
        <div class="row-fields">
          <div class="input-wrapper half">
            <label>{{ $t('pages.loyaltyGroups.discountValueRequiredLabel') }}</label>
            <input
              type="number"
              step="0.01"
              min="0"
              :placeholder="$t('pages.loyaltyGroups.enterDiscountValue')"
              v-model="result.discount_value"
              @blur="formatDiscount"
              :class="{invalid: (!result.discount_value && hasError) || !!discountError}"
            >
            <span class="error" v-if="!result.discount_value && hasError">
              {{ $t('pages.loyaltyGroups.discountValueRequired') }}
            </span>
            <span class="error" v-else-if="discountError">
              {{ discountError }}
            </span>
          </div>

          <div class="input-wrapper half">
            <label>{{ $t('pages.loyaltyGroups.discountType') }}</label>
            <dropdown
              :selectedKey="result.discount_type"
              :options="discountTypes"
              @clicked="discountTypeSelected"
            />
          </div>
        </div>

        <!--
          A percentage group with no cap and no floor can discount an order to
          nothing. Both are optional, but the form has to offer them.
        -->
        <div class="row-fields">
          <div class="input-wrapper half">
            <label>{{ $t('pages.loyaltyGroups.minimumCartValue') }}</label>
            <input
              type="number"
              step="0.01"
              min="0"
              :placeholder="$t('pages.loyaltyGroups.noMinimum')"
              v-model="result.min_cart_value"
            >
            <span class="field-hint">
              {{ $t('pages.loyaltyGroups.ordersBelowNoDiscount') }}
            </span>
          </div>

          <div class="input-wrapper half">
            <label>{{ $t('pages.loyaltyGroups.maximumDiscount') }}</label>
            <input
              type="number"
              step="0.01"
              min="0"
              :placeholder="$t('pages.loyaltyGroups.noCap')"
              v-model="result.max_discount"
              :class="{invalid: !!capError}"
            >
            <span class="error" v-if="capError">
              {{ capError }}
            </span>
            <span class="field-hint" v-else>
              {{ $t('pages.loyaltyGroups.capHint') }}
            </span>
          </div>
        </div>

        <!-- VALIDITY PERIOD -->
        <div class="input-wrapper">
          <label>{{ $t('pages.loyaltyGroups.validityPeriod') }}</label>

          <div class="validity-options">

            <!-- One Time -->
            <div
              class="validity-card"
              :class="{active: result.validity === 'one_time'}"
              @click="selectValidity('one_time')"
            >
              <input
                type="radio"
                value="one_time"
                :checked="result.validity === 'one_time'"
                @change="selectValidity('one_time')"
              >
              <div>
                <strong>{{ $t('pages.loyaltyGroups.oneTimePurchase') }}</strong>
                <p>{{ $t('pages.loyaltyGroups.nextOrderOnly') }}</p>
              </div>
            </div>

            <!-- Set Period -->
            <div
              class="validity-card"
              :class="{active: result.validity === 'period', invalid: periodInvalid && hasError}"
              @click="selectValidity('period')"
            >
              <input
                type="radio"
                value="period"
                :checked="result.validity === 'period'"
                @change="selectValidity('period')"
              >
              <div>
                <strong>{{ $t('pages.loyaltyGroups.setPeriod') }}</strong>
                <p v-if="result.validity === 'period' && result.period_days">
                  {{ $t('pages.loyaltyGroups.daysCount', { days: result.period_days }) }}
                </p>
                <p v-else>{{ $t('pages.loyaltyGroups.eg30Days') }}</p>
              </div>

              <div
                v-if="showPeriodPopup"
                class="mini-popup"
                @click.stop
              >
                <div class="mini-popup-title">{{ $t('pages.loyaltyGroups.setValidityPeriod') }}</div>
                <input
                  type="number"
                  min="1"
                  max="3650"
                  v-model="periodDays"
                  :placeholder="$t('pages.loyaltyGroups.enterDays')"
                  @keyup.enter.stop="confirmPeriod"
                />
                <span class="error" v-if="periodPopupError">
                  {{ periodPopupError }}
                </span>
                <div class="mini-popup-actions">
                  <button type="button" class="cancel-btn" @click.stop="cancelPeriodPopup">{{ $t('index.cancelBtn') }}</button>
                  <button type="button" class="apply-btn primary-btn" @click.stop="confirmPeriod">{{ $t('pages.loyaltyGroups.apply') }}</button>
                </div>
              </div>
            </div>

            <!-- Date Range -->
            <div
              class="validity-card"
              :class="{active: result.validity === 'date_range', invalid: dateRangeInvalid && hasError}"
              @click="selectValidity('date_range')"
            >
              <input
                type="radio"
                value="date_range"
                :checked="result.validity === 'date_range'"
                @change="selectValidity('date_range')"
              >
              <div>
                <strong>{{ $t('pages.loyaltyGroups.dateRangeTitle') }}</strong>
                <p v-if="result.validity === 'date_range' && result.start_date && result.end_date">
                  {{ $t('pages.loyaltyGroups.dateToDate', { start: result.start_date, end: result.end_date }) }}
                </p>
                <p v-else>{{ $t('pages.loyaltyGroups.specificDates') }}</p>
              </div>

              <div
                v-if="showDatePopup"
                class="mini-popup"
                @click.stop
              >
                <div class="mini-popup-title">{{ $t('pages.loyaltyGroups.selectDateRange') }}</div>
                <input type="date" v-model="startDate">
                <input type="date" v-model="endDate" :min="startDate || null">
                <span class="error" v-if="datePopupError">
                  {{ datePopupError }}
                </span>
                <div class="mini-popup-actions">
                  <button type="button" class="cancel-btn" @click.stop="cancelDatePopup">{{ $t('index.cancelBtn') }}</button>
                  <button type="button" class="apply-btn primary-btn" @click.stop="confirmDateRange">{{ $t('pages.loyaltyGroups.apply') }}</button>
                </div>
              </div>
            </div>

          </div>

          <span class="error" v-if="periodInvalid && hasError">
            {{ $t('pages.loyaltyGroups.periodRequired') }}
          </span>
          <span class="error" v-else-if="dateRangeInvalid && hasError">
            {{ $t('pages.loyaltyGroups.dateRangeRequired') }}
          </span>
        </div>

        <!-- SELECT USERS -->
        <div class="input-wrapper">
          <div class="users-header">

            <div class="users-header-left">
              <div class="users-title">{{ $t('pages.loyaltyGroups.selectUsers') }}</div>
              <div class="users-subtitle">
                {{ $t('pages.loyaltyGroups.filterAddUsers') }}
                <strong>{{ result.users.length }}</strong> {{ $t('pages.loyaltyGroups.selectedSuffix') }}
              </div>
            </div>

            <div class="users-header-actions">
              <button type="button" @click="selectAllShown">
                {{ $t('pages.loyaltyGroups.selectAllShown') }}
              </button>

              <button type="button" @click="clearUsers">
                {{ $t('pages.loyaltyGroups.clear') }}
              </button>
            </div>

          </div>

          <div class="filter-box">

            <div class="filter-header">
              <span class="arrow"><i class="fa fa-filter"></i></span>
              <span class="title">{{ $t('pages.loyaltyGroups.advancedFilters') }}</span>
            </div>

            <div class="filter-grid">

              <div class="filter-field">
                <label>{{ $t('pages.loyaltyGroups.totalSpendCurrency', { currency: currencyIcon || '$' }) }}</label>
                <div class="min-max">
                  <input type="number" min="0" :placeholder="$t('pages.loyaltyGroups.min')" v-model="filters.spend_min">
                  <span class="seperate">-</span>
                  <input type="number" min="0" :placeholder="$t('pages.loyaltyGroups.max')" v-model="filters.spend_max">
                </div>
              </div>

              <div class="filter-field">
                <label>{{ $t('pages.loyaltyGroups.registeredOnOrAfter') }}</label>
                <input type="date" v-model="filters.registration">
              </div>

              <div class="filter-field">
                <label>{{ $t('pages.loyaltyGroups.ordersLast30d') }}</label>
                <input type="number" min="0" :placeholder="$t('pages.loyaltyGroups.anyAmount')" v-model="filters.orders">
              </div>

              <div class="filter-field">
                <label>{{ $t('pages.loyaltyGroups.loyaltyPoints') }}</label>
                <input type="number" min="0" :placeholder="$t('pages.loyaltyGroups.minPts')" v-model="filters.points">
              </div>

              <div class="filter-action">
                <button
                  type="button"
                  class="apply-filter primary-btn"
                  :disabled="loadingUsers"
                  @click="applyFilters"
                >
                  {{ loadingUsers ? $t('pages.loyaltyGroups.loading') : $t('pages.loyaltyGroups.apply') }}
                </button>
                <button
                  type="button"
                  class="reset-filter btn"
                  :disabled="loadingUsers"
                  @click="resetFilters"
                >
                  {{ $t('pages.loyaltyGroups.reset') }}
                </button>
              </div>

            </div>

          </div>

          <!--
            Filtering narrows what is shown, never what is selected. The old
            page re-pointed the list at the filtered set and then let "select
            all" overwrite result.users with just those rows, so filtering
            silently dropped every member who did not match the new filter.
          -->
          <div v-if="hiddenSelectedCount > 0" class="selection-note">
            {{ hiddenSelectedCount === 1 ? $t('pages.loyaltyGroups.hiddenSelectedOne', { count: hiddenSelectedCount }) : $t('pages.loyaltyGroups.hiddenSelectedMany', { count: hiddenSelectedCount }) }}
          </div>

          <div class="users-table">

            <div class="user-row header">
              <div class="checkbox-col">
                <input
                  type="checkbox"
                  :checked="allShownSelected"
                  @change="toggleAllShown"
                >
              </div>
              <div class="user-col">{{ $t('pages.loyaltyGroups.user') }}</div>
              <div class="spend-col">{{ $t('pages.loyaltyGroups.totalSpend') }}</div>
              <div class="orders-col">{{ $t('pages.loyaltyGroups.orders30d') }}</div>
              <div class="points-col">{{ $t('pages.loyaltyGroups.points') }}</div>
            </div>

            <!--
              The rows live in their own box under the sticky header above, so
              a long customer list scrolls here instead of stretching the form
              and pushing Save off the bottom of the page.
            -->
            <div class="users-table-body">

              <div v-if="loadingUsers" class="user-row empty">
                {{ $t('pages.loyaltyGroups.loadingCustomers') }}
              </div>

              <div v-else-if="!users.length" class="user-row empty">
                {{ $t('pages.loyaltyGroups.noCustomersMatch') }}
              </div>

              <div
                v-else
                v-for="user in users"
                :key="user.id"
                class="user-row"
              >
                <div class="checkbox-col">
                  <input
                    type="checkbox"
                    :value="user.id"
                    v-model="result.users"
                  >
                </div>

                <div class="user-col user-info">
                  <div class="avatar">
                    {{ (user.name || '?').charAt(0) }}
                  </div>

                  <div class="user-name">
                    {{ user.name }}
                    <span class="user-email">{{ user.email }}</span>
                  </div>
                </div>

                <div class="spend-col">
                  {{ currencyIcon || '$' }}{{ user.total_spend || 0 }}
                </div>

                <div class="orders-col">
                  {{ user.orders_last_30d || 0 }}
                </div>

                <div class="points-col">
                  {{ user.total_points || 0 }}
                </div>

              </div>

            </div>

          </div>
        </div>
      </div>

      </div>

      <!--
        Reads ordered_loyalty_discount, which is the record of money taken off
        an order. The member list on the other tab says who is *eligible*; this
        says who actually used it, for how much, and on which order.
      -->
      <div
        v-show="isEditing && !detailsVisible(hasError)"
        class="card redemptions-card"
      >

        <div class="redemptions-head">
          <div>
            <div class="users-title">{{ $t('pages.loyaltyGroups.redemptions') }}</div>
            <div class="users-subtitle">
              {{ $t('pages.loyaltyGroups.redemptionsSubtitle') }}
            </div>
          </div>

          <div class="redemption-search">
            <input
              type="text"
              :placeholder="$t('pages.loyaltyGroups.searchRedemptions')"
              v-model="redemptionSearch"
              @keyup.enter="loadRedemptions(1)"
            >
            <button type="button" class="apply-filter primary-btn" @click="loadRedemptions(1)">
              {{ $t('pages.loyaltyGroups.search') }}
            </button>
          </div>
        </div>

        <div class="redemption-stats">
          <div class="redemption-stat">
            <span class="redemption-stat-label">{{ $t('pages.loyaltyGroups.timesRedeemed') }}</span>
            <strong>{{ redemptionCount }}</strong>
          </div>
          <div class="redemption-stat">
            <span class="redemption-stat-label">{{ $t('pages.loyaltyGroups.customers') }}</span>
            <strong>{{ redemptionCustomers }}</strong>
          </div>
          <div class="redemption-stat">
            <span class="redemption-stat-label">{{ $t('pages.loyaltyGroups.totalDiscounted') }}</span>
            <strong>{{ currencyIcon }}{{ Number(redemptionTotal || 0).toFixed(2) }}</strong>
          </div>
        </div>

        <div v-if="loadingRedemptions" class="redemption-empty">
          {{ $t('pages.loyaltyGroups.loadingRedemptions') }}
        </div>

        <div v-else-if="!redemptions.length" class="redemption-empty">
          <template v-if="redemptionSearch">
            {{ $t('pages.loyaltyGroups.noRedemptionsMatch') }}
          </template>
          <template v-else>
            {{ $t('pages.loyaltyGroups.noRedemptionsYet') }}
          </template>
        </div>

        <template v-else>
          <div class="redemption-table">

            <div class="redemption-row header">
              <div>{{ $t('pages.loyaltyGroups.customer') }}</div>
              <div>{{ $t('pages.loyaltyGroups.order') }}</div>
              <div>{{ $t('category.date') }}</div>
              <div class="ta-right">{{ $t('pages.loyaltyGroups.discount') }}</div>
              <div>{{ $t('pages.loyaltyGroups.orderStatus') }}</div>
            </div>

            <div
              v-for="row in redemptions"
              :key="row.id"
              class="redemption-row"
            >
              <div :data-label="$t('pages.loyaltyGroups.customer')" class="redemption-user">
                <strong>{{ row.user_name || $t('pages.loyaltyGroups.deletedCustomer') }}</strong>
                <span v-if="row.user_email">{{ row.user_email }}</span>
              </div>

              <div :data-label="$t('pages.loyaltyGroups.order')">
                <nuxt-link
                  v-if="row.order_id"
                  class="link"
                  :to="`/orders/${row.order_id}`"
                >
                  #{{ row.order_number }}
                </nuxt-link>
              </div>

              <div :data-label="$t('category.date')">{{ redemptionDate(row.created_at) }}</div>

              <div :data-label="$t('pages.loyaltyGroups.discount')" class="ta-right redemption-amount">
                - {{ currencyIcon }}{{ Number(row.discount || 0).toFixed(2) }}
              </div>

              <div :data-label="$t('pages.loyaltyGroups.orderStatus')">
                <span class="pill" :class="orderStatusPill(row.order_status)">
                  {{ orderStatusLabel(row.order_status) }}
                </span>
              </div>
            </div>

          </div>

          <div v-if="redemptionLastPage > 1" class="redemption-pager">
            <button
              type="button"
              :disabled="redemptionPage <= 1 || loadingRedemptions"
              @click="loadRedemptions(redemptionPage - 1)"
            >
              {{ $t('pages.loyaltyGroups.previous') }}
            </button>

            <span>{{ $t('pages.loyaltyGroups.pageOf', { page: redemptionPage, last: redemptionLastPage }) }}</span>

            <button
              type="button"
              :disabled="redemptionPage >= redemptionLastPage || loadingRedemptions"
              @click="loadRedemptions(redemptionPage + 1)"
            >
              {{ $t('pages.loyaltyGroups.next') }}
            </button>
          </div>
        </template>

      </div>

    </template>
  </data-page>
</template>
<script>

import DataPage from '~/components/partials/DataPage'
import Dropdown from '~/components/Dropdown'
import util from '~/mixin/util'
import { mapActions, mapGetters } from 'vuex'

export default {

  name: "loyalty-group",
  middleware: ['common-middleware', 'auth'],
  mixins: [util],
  components: {
    DataPage,
    Dropdown
  },
  data() {
    return {
      showPeriodPopup: false,
      showDatePopup: false,
      loadingUsers: false,

      // What the popups are editing, before Apply commits it to `result`.
      periodDays: '',
      startDate: '',
      endDate: '',
      periodPopupError: '',
      datePopupError: '',

      /*
       * Where to go back to if a popup is cancelled. Cancel used to just hide
       * the popup, leaving the group set to "period" with no number of days or
       * to "date_range" with no dates -- an invalid group the old backend
       * happily saved.
       */
      validityBeforePopup: 'one_time',

      users: [],

      // 'details' | 'redemptions'
      activeTab: 'details',

      // Who has actually spent this discount. Loaded separately from the form
      // so a long-running group's history cannot slow the form down.
      redemptions: [],
      redemptionCount: 0,
      redemptionCustomers: 0,
      redemptionTotal: 0,
      redemptionPage: 1,
      redemptionLastPage: 1,
      redemptionSearch: '',
      loadingRedemptions: false,

      filters: {
        spend_min: '',
        spend_max: '',
        registration: '',
        orders: '',
        points: ''
      },
      /*
       * Keyed by name, not by 0/1. Dropdown.clicked() refuses to emit for the
       * key '0' unless defaultNull is set, so an option keyed '0' would look
       * selectable and silently never apply.
       */
      statusOptions: {
        active: { title: this.$t('fSale.active') },
        inactive: { title: this.$t('fSale.inactive') }
      },
      discountTypes: {
        fixed: { title: this.$t('pages.loyaltyGroups.fixedAmount') },
        percentage: { title: this.$t('pages.loyaltyGroups.percentagePct') }
      },
      result: {
        id: '',
        title: '',
        customer_label: '',
        status: 1,
        discount_type: 'fixed',
        discount_value: '',
        min_cart_value: '',
        max_discount: '',
        validity: 'one_time',
        period_days: '',
        start_date: '',
        end_date: '',
        users: []
      }
    }
  },
  computed: {

    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },

    /*
     * The redemption panel is meaningless on a group that does not exist yet.
     * DataPage treats a missing route param as "adding".
     */
    isEditing() {
      return !!this.$route?.params?.id
    },

    ...mapGetters('setting', ['setting']),

    /*
     * DataPage blocks submit on any key in this list that is falsy, so the
     * validity fields only become required once the validity that needs them
     * is chosen. The old page listed title and discount_value only, which let
     * a "set period" group save with no period.
     */
    validationKeys() {
      const keys = ['title', 'discount_value']

      if (this.result.validity === 'period') {
        keys.push('period_days')
      }

      if (this.result.validity === 'date_range') {
        keys.push('start_date', 'end_date')
      }

      return keys
    },

    discountError() {
      const value = Number(this.result.discount_value)

      if (this.result.discount_value === '' || isNaN(value)) {
        return ''
      }

      if (value < 0) {
        return this.$t('pages.loyaltyGroups.discountNegative')
      }

      if (this.result.discount_type === 'percentage' && value > 100) {
        return this.$t('pages.loyaltyGroups.percentOver100')
      }

      return ''
    },

    capError() {
      const cap = Number(this.result.max_discount)
      const value = Number(this.result.discount_value)

      if (!this.result.max_discount || isNaN(cap) || cap <= 0) {
        return ''
      }

      if (this.result.discount_type === 'fixed' && value > 0 && cap < value) {
        return this.$t('pages.loyaltyGroups.capBelowFixed')
      }

      return ''
    },

    periodInvalid() {
      return this.result.validity === 'period' && !(Number(this.result.period_days) > 0)
    },

    dateRangeInvalid() {
      if (this.result.validity !== 'date_range') {
        return false
      }

      if (!this.result.start_date || !this.result.end_date) {
        return true
      }

      return new Date(this.result.end_date) < new Date(this.result.start_date)
    },

    shownIds() {
      return this.users.map(user => user.id)
    },

    allShownSelected() {
      return this.users.length > 0 &&
        this.shownIds.every(id => this.result.users.includes(id))
    },

    hiddenSelectedCount() {
      return this.result.users.filter(id => !this.shownIds.includes(id)).length
    }
  },
  methods: {

    selectValidity(type) {
      if (this.showPeriodPopup || this.showDatePopup) {
        // A popup is already open on another card; cancel it cleanly first.
        this.showPeriodPopup = false
        this.showDatePopup = false
      } else {
        this.validityBeforePopup = this.result.validity
      }

      this.periodPopupError = ''
      this.datePopupError = ''
      this.result.validity = type

      if (type === 'period') {
        this.periodDays = this.result.period_days || ''
        this.showPeriodPopup = true
      }

      if (type === 'date_range') {
        this.startDate = this.result.start_date || ''
        this.endDate = this.result.end_date || ''
        this.showDatePopup = true
      }
    },

    cancelPeriodPopup() {
      this.showPeriodPopup = false
      this.periodPopupError = ''
      this.result.validity = this.validityBeforePopup
    },

    confirmPeriod() {
      const days = Number(this.periodDays)

      if (!(days > 0)) {
        this.periodPopupError = this.$t('pages.loyaltyGroups.daysGreaterThanZero')
        return
      }

      if (days > 3650) {
        this.periodPopupError = this.$t('pages.loyaltyGroups.daysTooMany')
        return
      }

      this.periodPopupError = ''
      this.result.validity = 'period'
      this.result.period_days = days
      this.result.start_date = ''
      this.result.end_date = ''
      this.showPeriodPopup = false
    },

    cancelDatePopup() {
      this.showDatePopup = false
      this.datePopupError = ''
      this.result.validity = this.validityBeforePopup
    },

    confirmDateRange() {
      if (!this.startDate || !this.endDate) {
        this.datePopupError = this.$t('pages.loyaltyGroups.bothDatesRequired')
        return
      }

      if (new Date(this.endDate) < new Date(this.startDate)) {
        this.datePopupError = this.$t('pages.loyaltyGroups.endBeforeStart')
        return
      }

      this.datePopupError = ''
      this.result.validity = 'date_range'
      this.result.start_date = this.startDate
      this.result.end_date = this.endDate
      this.result.period_days = ''
      this.showDatePopup = false
    },

    /*
     * Only the rows currently on screen are affected. Unticking the header box
     * leaves members the filter is hiding exactly where they were.
     */
    toggleAllShown(e) {
      if (e.target.checked) {
        this.selectAllShown()
      } else {
        this.result.users = this.result.users.filter(
          id => !this.shownIds.includes(id)
        )
      }
    },

    selectAllShown() {
      const merged = new Set(this.result.users)
      this.shownIds.forEach(id => merged.add(id))
      this.result.users = Array.from(merged)
    },

    clearUsers() {
      this.result.users = []
    },

    discountTypeSelected(data) {
      this.result.discount_type = data?.key
    },

    statusSelected(data) {
      this.result.status = data?.key === 'inactive' ? 0 : 1
    },

    formatDiscount() {
      if (this.result.discount_value === '' || this.result.discount_value === null) {
        return
      }

      const value = Number(this.result.discount_value)

      if (isNaN(value)) {
        this.result.discount_value = ''
        return
      }

      this.result.discount_value = (Math.round(value * 100) / 100).toFixed(2)
    },

    /**
     * Is the Group Details pane showing?
     *
     * Takes hasError because Save lives in the page header and stays clickable
     * from either tab. Without this, submitting an invalid form while looking
     * at Redemptions would mark fields red on a pane nobody could see, and the
     * save would appear to do nothing at all. An error always brings the form
     * back into view.
     *
     * On the create form there is no second tab, so details always win.
     */
    detailsVisible(hasError) {
      if (!this.isEditing) {
        return true
      }

      return this.activeTab === 'details' || !!hasError
    },

    /*
     * The full 1-8 range. The shared util mixin's orderStatus map stops at 5,
     * so a redemption against a cancelled or refunded order would have shown a
     * blank status -- which is exactly the case an admin looking at this panel
     * most wants to spot.
     */
    orderStatusLabel(status) {
      const key = {
        1: 'statusPending', 2: 'statusConfirmed', 3: 'statusPickedUp', 4: 'statusOnTheWay',
        5: 'statusDelivered', 6: 'statusCancelled', 7: 'statusReturned', 8: 'statusRefunded'
      }[parseInt(status)] || 'statusUnknown'

      return this.$t('pages.loyaltyGroups.' + key)
    },

    orderStatusPill(status) {
      const code = parseInt(status)

      if (code === 5) {
        return 'pill-on'
      }

      // Cancelled, returned, refunded: the discount was handed back.
      if ([6, 7, 8].includes(code)) {
        return 'pill-off'
      }

      return 'pill-wait'
    },

    redemptionDate(value) {
      if (!value) {
        return ''
      }

      const d = new Date(value)

      return isNaN(d.getTime())
        ? String(value).substring(0, 10)
        : d.toLocaleDateString(this.$i18n?.locale || 'en', { day: '2-digit', month: 'short', year: 'numeric' })
    },

    async loadRedemptions(page = 1) {
      if (!this.isEditing) {
        return
      }

      this.loadingRedemptions = true

      try {
        const data = await this.getById({
          id: this.$route.params.id,
          api: 'getLoyaltyGroupRedemptions',
          params: {
            page,
            q: this.redemptionSearch || null
          }
        })

        const list = data?.redemptions

        this.redemptions = list?.data || []
        this.redemptionPage = list?.current_page || 1
        this.redemptionLastPage = list?.last_page || 1
        this.redemptionCount = data?.redemption_count || 0
        this.redemptionCustomers = data?.customer_count || 0
        this.redemptionTotal = data?.total_discount || 0

      } catch (e) {
        this.redemptions = []
        this.setToastError(e?.message || this.$t('pages.loyaltyGroups.couldNotLoadRedemptions'))
      } finally {
        this.loadingRedemptions = false
      }
    },

    applyFilters() {
      return this.fetchUsers(this.filters)
    },

    resetFilters() {
      this.filters = {
        spend_min: '',
        spend_max: '',
        registration: '',
        orders: '',
        points: ''
      }

      return this.fetchUsers()
    },

    /*
     * Goes through the shared service layer rather than hand-rolling an axios
     * call against a hard-coded https://shop.fixmypc.ie/ fallback, so it picks
     * up the configured API base, the language header and the bearer token the
     * way every other admin screen does.
     */
    async fetchUsers(filters = {}) {
      this.loadingUsers = true

      try {
        const data = await this.getRequest({
          api: 'getUsers',
          params: {
            limit: 'all',
            type: 'desc',
            orderby: 'users.created_at',
            page: 1,
            time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            ...filters
          }
        })

        this.users = data?.data || []

      } catch (e) {
        this.users = []
        this.setToastError(e?.message || this.$t('pages.loyaltyGroups.couldNotLoadCustomers'))
      } finally {
        this.loadingUsers = false
      }
    },

    settingResult(evt) {
      this.result = {
        id: evt.id,
        title: evt.title,
        customer_label: evt.customer_label || '',
        status: evt.status === false || Number(evt.status) === 0 ? 0 : 1,
        discount_type: evt.discount_type,
        discount_value: evt.discount_value,
        min_cart_value: evt.min_cart_value ?? '',
        max_discount: evt.max_discount ?? '',
        validity: evt.validity || 'one_time',
        period_days: evt.period_days || '',
        start_date: evt.start_date ? String(evt.start_date).substring(0, 10) : '',
        end_date: evt.end_date ? String(evt.end_date).substring(0, 10) : '',
        users: evt.users ? evt.users.map(u => u.id) : []
      }

      this.periodDays = this.result.period_days
      this.startDate = this.result.start_date
      this.endDate = this.result.end_date
      this.validityBeforePopup = this.result.validity
    },

    ...mapActions('common', ['getRequest', 'getById']),
    ...mapActions('ui', ['setToastError'])
  },
  mounted() {
    this.fetchUsers()
    this.loadRedemptions(1)
  }
}
</script>
<style scoped>
.row-fields{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:20px;
  position:relative;
}

.input-wrapper{
  margin-bottom:22px;
}

.input-wrapper label{
  display:block;
  margin-bottom:6px;
  font-weight:500;
  font-size:14px;
}

.field-hint{
  display:block;
  font-size:12px;
  color:#6b7280;
  margin-top:4px;
}

.card{
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:24px;
  background:#fff;
  margin-bottom: 10px;
}

/* VALIDITY CARDS */

.validity-options{
  display:flex;
  gap:16px;
  margin-top:10px;
}

.validity-card{
  flex:1;
  border:1px solid #d0d5dd;
  border-radius:8px;
  padding:14px;
  display:flex;
  gap:10px;
  cursor:pointer;
  transition:all .2s;
  position: relative;
}

.validity-card input{
  margin-top:3px;
}

.validity-card strong{
  font-size:14px;
}

.validity-card p{
  font-size:12px;
  color:#667085;
  margin:2px 0 0 0;
}

.validity-card.active{
  border-color:#4f6ef7;
  background:#f5f7ff;
}

.validity-card.invalid{
  border-color:#d92d20;
}

/* ADVANCED FILTER */
.filter-box{
  background:#f9fafb;
  border:1px solid #e5e7eb;
  border-radius:8px;
  padding:16px 18px;
  margin-top:12px;
}

.filter-header{
  display:flex;
  align-items:center;
  gap:6px;
  margin-bottom:12px;
  font-weight:600;
  font-size:14px;
  color:#374151;
}

.arrow{
  font-size:12px;
}

/*
 * Wrapping flex, not fixed grid tracks. The old 220px + 180px * 3 + buttons
 * needed more width than the card has at common admin widths, so Apply/Reset
 * hung outside the filter box's right border instead of wrapping.
 *
 * Each field shrinks to a 170px basis first; only when even that will not fit
 * does the button pair drop to its own line, where margin-left:auto keeps it
 * on the right rather than stranding it under the first input.
 */
.filter-grid{
  display:flex;
  flex-wrap:wrap;
  gap:14px;
  align-items:flex-end;
}

.filter-field{
  display:flex;
  flex-direction:column;
  flex:1 1 170px;
  min-width:0;
}

/* Two inputs and a dash need more room than the single-input fields. */
.filter-grid .filter-field:first-child{
  flex:1.4 1 210px;
}

.filter-field label{
  font-size:12px;
  color:#6b7280;
  margin-bottom:4px;
}

/*
 * 36px to line up with the Apply/Reset buttons at the end of the row.
 *
 * min-width:0 matters on the date field in particular: date inputs carry a
 * wide intrinsic minimum that would otherwise refuse to shrink and push the
 * row past the card edge again.
 */
.filter-field input{
  height:36px;
  min-width:0;
  padding:0 10px;
  border:1px solid #d1d5db;
  border-radius:6px;
  font-size:13px;
  background:#fff;
}

.min-max{
  display:flex;
  gap:8px;
}

.min-max input{
  width:100%;
  min-width:0;
}

.filter-action{
  display:flex;
  align-items:center;
  gap:8px;
  flex:0 0 auto;
  margin-left:auto;
}

/* Colours for these live with the other buttons, further down. */

/* USERS TABLE */

.selection-note{
  margin-top:12px;
  padding:8px 12px;
  border-radius:6px;
  background:#fffbeb;
  border:1px solid #fde68a;
  color:#92400e;
  font-size:12px;
}

/*
 * The list scrolls inside a fixed box instead of growing the page. A shop with
 * a few hundred customers used to stretch this form far past the viewport and
 * push the Save bar out of reach.
 *
 * The scroll container is the table itself, not the body below it, so the
 * header row sticks to the top and stays column-aligned with the rows: both
 * are laid out inside the same scrolled box, so the scrollbar narrows both by
 * the same amount. Scrolling the body alone would shift the rows out from
 * under a header that never moved.
 */
.users-table{
  margin-top:16px;
  border:1px solid #e5e7eb;
  border-radius:8px;
  max-height:360px;
  overflow-y:auto;
  overscroll-behavior:contain;
}

.users-table::-webkit-scrollbar{
  width:8px;
}

.users-table::-webkit-scrollbar-thumb{
  background:#d1d5db;
  border-radius:4px;
}

.users-table::-webkit-scrollbar-thumb:hover{
  background:#9ca3af;
}

.users-table::-webkit-scrollbar-track{
  background:transparent;
}

.users-table-body{
  min-height:60px;
}

.user-row{
  display:grid;
  grid-template-columns:50px 1fr 140px 110px 100px;
  align-items:center;
  padding:12px 16px;
  border-bottom:1px solid #f1f1f1;
  font-size:13px;
}

.user-row:last-child{
  border-bottom:none;
}

/*
 * Rows scroll underneath this, so it needs an opaque background of its own,
 * a hard bottom edge, and an exemption from the row hover tint.
 */
.user-row.header{
  position:sticky;
  top:0;
  z-index:2;
  background:#f9fafb;
  border-bottom:1px solid #e5e7eb;
  font-weight:600;
  font-size:12px;
  color:#6b7280;
  text-transform:uppercase;
  text-align:center;
}

.user-row.header:hover{
  background:#f9fafb;
}

.user-row.empty{
  display:block;
  text-align:center;
  color:#6b7280;
  padding:20px;
}

.user-row:hover{
  background:#fafafa;
}

.checkbox-col{
  display:flex;
  justify-content:center;
  align-items:center;
}

.user-col{
  display:flex;
  align-items:center;
  gap:10px;
}

.spend-col,
.orders-col,
.points-col{
  display:flex;
  justify-content:center;
  align-items:center;
}

.avatar{
  width:28px;
  height:28px;
  border-radius:50%;
  background:#e5edff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:12px;
  font-weight:600;
  color:#4f6ef7;
  flex-shrink:0;
  text-transform:uppercase;
}

.user-name{
  font-weight:500;
  display:flex;
  flex-direction:column;
}

.user-email{
  font-weight:400;
  font-size:11px;
  color:#6b7280;
}

.custom-dropdown .dropdown-inner {
  width: 100% !important;
}

form .input-wrapper .custom-dropdown{
  min-width: 100% !important;
}

.users-header{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  margin-bottom:14px;
}

.users-title{
  font-size:16px;
  font-weight:600;
  color:#111827;
}

.users-subtitle{
  font-size:13px;
  color:#6b7280;
  margin-top:2px;
}

.users-header-actions{
  display:flex;
  gap:8px;
}

/*
 * Buttons here sit under two global rules:
 *
 *   base.styl   button { height: 40px; padding: 0 30px }
 *   base.styl   button:hover:not(.mfp-arrow, .mfp-close, .primary-btn,
 *                                .lite-btn, .nav-l, .nav-r, .delete-btn)
 *                 { background: hover-color }
 *
 * The second is why a hand-rolled coloured button goes pale on hover while
 * keeping its white text. The answer is the exemption list, not a specificity
 * fight: anything meant to hold its colour wears .primary-btn (see below).
 *
 * A light grey hover IS the right behaviour for the white secondary buttons,
 * so those simply let it happen. This rule only normalises size, since the 40px
 * height and 30px padding above are too big for a popup and a filter row.
 */
.users-header-actions button,
.filter-action button,
.mini-popup-actions button{
  height:36px;
  padding:0 14px;
  border-radius:6px;
  font-size:13px;
  font-weight:500;
  line-height:1;
  cursor:pointer;
  white-space:nowrap;
  transition:background-color .15s ease, border-color .15s ease;
}

/* Secondary: Clear, Select All Shown, Reset, Cancel. */
.users-header-actions button,
.filter-action .reset-filter,
.mini-popup-actions .cancel-btn{
  border:1px solid #d1d5db;
  background:#fff;
  color:#374151;
}

.users-header-actions button:hover:enabled,
.filter-action .reset-filter:hover:enabled,
.mini-popup-actions .cancel-btn:hover:enabled{
  background:#f9fafb;
  border-color:#9ca3af;
}

/*
 * The Apply buttons carry the panel's own .primary-btn class rather than a
 * hand-rolled blue.
 *
 * That is the convention base.styl is built around: .primary-btn is one of the
 * classes its generic `button:hover:not(...)` rule exempts, so it keeps its
 * colour on hover instead of being washed to hover-color while its white text
 * stays put -- which is what made Apply read as a disabled, near-invisible
 * button. It also takes primary-color / primary-hover from the theme, so these
 * two buttons track the rest of the admin panel instead of pinning a hex.
 *
 * Only the size is normalised here, to match the inputs beside them.
 */
.filter-action .apply-filter,
.mini-popup-actions .apply-btn{
  border:0;
  padding:0 16px;
}

.users-header-actions button:disabled,
.filter-action button:disabled,
.mini-popup-actions button:disabled{
  opacity:.55;
  cursor:not-allowed;
}

/*
 * White, not #f5f6f7. This panel floats over the Select Users section below
 * it, and a grey card on a grey page reads as part of the page rather than as
 * something sitting on top of it. The heavier shadow does the same job.
 */
.mini-popup{
  position:absolute;
  top:100%;
  left:0;
  margin-top:10px;
  width:260px;
  background:#fff;
  border-radius:10px;
  padding:16px;
  border:1px solid #d0d5dd;
  box-shadow:0 12px 28px rgba(16,24,40,0.18);
  z-index:30;
}

.mini-popup-title{
  font-weight:600;
  font-size:14px;
  margin-bottom:10px;
}

.mini-popup input{
  width:100%;
  height:36px;
  border:1px solid #d1d5db;
  border-radius:6px;
  padding:0 10px;
  margin-bottom:10px;
}

/*
 * Right-aligned and paired, rather than space-between pushing two buttons to
 * opposite edges of a 260px panel with a gulf between them.
 */
.mini-popup-actions{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  margin-top:4px;
}

/* .cancel-btn / .apply-btn are styled with the other buttons, above. */

.error{
  display:block;
  font-size:12px;
  color:#d92d20;
  margin-top:4px;
}

/* ---- Tabs ---- */

.lg-tabs{
  display:flex;
  gap:4px;
  border-bottom:1px solid #e5e7eb;
  margin-bottom:16px;
  overflow-x:auto;
}

/*
 * Explicit height, padding and hover: base.styl sets button { height:40px;
 * padding:0 30px } and repaints any button it does not recognise on hover.
 */
.lg-tab{
  position:relative;
  display:inline-flex;
  align-items:center;
  gap:8px;
  height:40px;
  padding:0 16px;
  border:0;
  border-bottom:2px solid transparent;
  border-radius:0;
  background:transparent;
  color:#6b7280;
  font-size:14px;
  font-weight:500;
  white-space:nowrap;
  cursor:pointer;
  transition:color .15s ease, border-color .15s ease;
}

.lg-tab:hover:enabled{
  background:transparent;
  color:#111827;
}

.lg-tab.active{
  color:#4f6ef7;
  border-bottom-color:#4f6ef7;
}

.lg-tab-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:20px;
  height:20px;
  padding:0 6px;
  border-radius:999px;
  background:#eef1ff;
  color:#4f6ef7;
  font-size:11px;
  font-weight:600;
}

.lg-tab.active .lg-tab-count{
  background:#4f6ef7;
  color:#fff;
}

/* Says "there is a problem on this tab" without stealing focus. */
.lg-tab-dot{
  width:8px;
  height:8px;
  border-radius:50%;
  background:#d92d20;
}

/* ---- Redemptions panel ---- */

.redemptions-card{
  margin-top:0;
}

.redemptions-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:16px;
}

.redemption-search{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

.redemption-search input{
  height:36px;
  min-width:230px;
  padding:0 10px;
  border:1px solid #d1d5db;
  border-radius:6px;
  font-size:13px;
  background:#fff;
}

/* Three figures for the whole group, not the page being viewed. */
.redemption-stats{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));
  gap:12px;
  margin-bottom:16px;
}

.redemption-stat{
  border:1px solid #e5e7eb;
  border-radius:8px;
  padding:12px 14px;
  background:#f9fafb;
}

.redemption-stat-label{
  display:block;
  font-size:12px;
  color:#6b7280;
  margin-bottom:4px;
}

.redemption-stat strong{
  font-size:18px;
  color:#111827;
}

.redemption-table{
  border:1px solid #e5e7eb;
  border-radius:8px;
  overflow:hidden;
}

.redemption-row{
  display:grid;
  grid-template-columns:1.6fr 1fr 1fr 110px 130px;
  align-items:center;
  gap:12px;
  padding:12px 16px;
  border-bottom:1px solid #f1f1f1;
  font-size:13px;
}

.redemption-row:last-child{
  border-bottom:none;
}

.redemption-row.header{
  background:#f9fafb;
  font-weight:600;
  font-size:12px;
  color:#6b7280;
  text-transform:uppercase;
}

.redemption-row:not(.header):hover{
  background:#fafaff;
}

.redemption-user{
  display:flex;
  flex-direction:column;
  min-width:0;
}

.redemption-user strong{
  font-weight:600;
  color:#111827;
}

.redemption-user span{
  font-size:11px;
  color:#6b7280;
  overflow-wrap:anywhere;
}

.redemption-amount{
  font-weight:600;
  color:#027a48;
  white-space:nowrap;
}

.ta-right{
  text-align:right;
}

.redemption-empty{
  border:1px dashed #e5e7eb;
  border-radius:8px;
  padding:28px 16px;
  text-align:center;
  color:#6b7280;
  font-size:13px;
}

.redemption-pager{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
  margin-top:14px;
  font-size:13px;
  color:#6b7280;
}

/*
 * Explicit hover states again: base.styl's generic
 * button:hover:not(.primary-btn, ...) would otherwise repaint these.
 */
.redemption-pager button{
  height:34px;
  padding:0 14px;
  border:1px solid #d1d5db;
  border-radius:6px;
  background:#fff;
  color:#374151;
  font-size:13px;
  cursor:pointer;
}

.redemption-pager button:hover:enabled{
  background:#f9fafb;
  border-color:#9ca3af;
}

.redemption-pager button:disabled{
  opacity:.55;
  cursor:not-allowed;
}

.redemption-search .primary-btn{
  height:36px;
  padding:0 16px;
  border:0;
}

@media (max-width: 900px){

  /*
   * Five columns will not fit once the sidebar is up. Each redemption becomes
   * its own block, with the column heading taken from data-label.
   */
  .redemption-row.header{
    display:none;
  }

  .redemption-row{
    grid-template-columns:1fr;
    gap:6px;
    padding:14px 16px;
  }

  .redemption-row > div{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    text-align:right;
  }

  .redemption-row > div::before{
    content:attr(data-label);
    font-size:12px;
    font-weight:600;
    color:#6b7280;
    text-align:left;
    flex-shrink:0;
  }

  .redemption-user{
    flex-direction:column;
    align-items:flex-end;
  }

  .ta-right{
    text-align:right;
  }

  .redemption-search input{
    min-width:0;
    flex:1 1 auto;
  }
}
</style>
