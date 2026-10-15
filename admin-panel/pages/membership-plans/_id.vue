<template>
  <data-page
    ref="dataPage"
    set-api="setMembershipPlan"
    get-api="getMembershipPlan"
    route-name="membership-plans"
    name="Membership Plan"
    gate="membership"
    :validation-keys="['name']"
    :validation-labels="{name: 'Plan name'}"
    :result="result"
    @result="settingResult"
  >

    <!-- ══════════ main column ══════════ -->
    <template v-slot:form="{hasError, fieldErrors}">

      <div v-if="stripeWarning" class="mp-alert" role="alert">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span>{{ stripeWarning }}</span>
      </div>

      <!-- Details -->
      <section class="mp-section">
        <!-- a div: the admin styles every <header> as the fixed top bar -->
        <div class="mp-section-head">
          <span class="mp-step">1</span>
          <div>
            <h3 class="mp-section-title">Plan details</h3>
            <p class="mp-section-sub">The name and line customers see on the Savers Club page.</p>
          </div>
        </div>

        <div class="input-wrapper" data-field="name">
          <label for="mp-name">Plan name</label>
          <input id="mp-name" v-model="result.name" type="text" maxlength="100" placeholder="e.g. Premium"
                 :class="{invalid: !result.name && hasError}">
          <div class="mp-field-foot">
            <span v-if="!result.name && hasError" class="error">Give the plan a name.</span>
            <field-error v-else :messages="fieldErrors && fieldErrors.name" />
            <span class="mp-count">{{ (result.name || '').length }}/100</span>
          </div>
        </div>

        <div class="input-wrapper" data-field="tagline">
          <label for="mp-tagline">Tagline <span class="mp-optional">optional</span></label>
          <input id="mp-tagline" v-model="result.tagline" type="text" maxlength="255"
                 placeholder="e.g. Unlock exclusive benefits and special discounts">
          <div class="mp-field-foot">
            <field-error :messages="fieldErrors && fieldErrors.tagline" />
            <span class="mp-count">{{ (result.tagline || '').length }}/255</span>
          </div>
        </div>
      </section>

      <!-- Pricing -->
      <section class="mp-section">
        <div class="mp-section-head">
          <span class="mp-step">2</span>
          <div>
            <h3 class="mp-section-title">Pricing</h3>
            <p class="mp-section-sub">Offer monthly, yearly or both; customers choose when they join. Billed by Stripe, cancel any time.</p>
          </div>
        </div>

        <div class="mp-grid">
          <div class="mp-field" data-field="price">
            <label for="mp-price">Monthly price <span class="mp-optional">leave blank to not offer</span></label>
            <div class="mp-affix" :class="{invalid: noPrice && hasError}">
              <span class="mp-affix-text">{{ currencyIcon }}</span>
              <input id="mp-price" v-model="result.price" type="number" min="0.5" step="0.01" inputmode="decimal" placeholder="9.99">
              <span class="mp-affix-text mp-affix-after">/ month</span>
            </div>
            <field-error :messages="fieldErrors && fieldErrors.price" />
          </div>

          <div class="mp-field" data-field="yearly_price">
            <label for="mp-yearly">Yearly price <span class="mp-optional">leave blank to not offer</span></label>
            <div class="mp-affix" :class="{invalid: noPrice && hasError}">
              <span class="mp-affix-text">{{ currencyIcon }}</span>
              <input id="mp-yearly" v-model="result.yearly_price" type="number" min="0.5" step="0.01" inputmode="decimal" placeholder="99.00">
              <span class="mp-affix-text mp-affix-after">/ year</span>
            </div>
            <span v-if="yearlySaving" class="mp-saving">
              <i class="fa-solid fa-tag" aria-hidden="true"></i> Saves {{ yearlySaving }}% against paying monthly
            </span>
            <span v-else-if="yearlyCostsMore" class="mp-error">Costs more than twelve monthly payments.</span>
            <field-error :messages="fieldErrors && fieldErrors.yearly_price" />
          </div>
        </div>

        <p v-if="noPrice && hasError" class="mp-error mp-error-block">Enter a monthly price, a yearly price, or both.</p>

        <p v-if="!noPrice" class="mp-summary">
          <i class="fa-regular fa-circle-check" aria-hidden="true"></i>
          {{ priceSummary }}
        </p>
        <p v-if="isEditing && priceChanged" class="mp-hint mp-hint-warn">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          Saving creates new Stripe prices for new members. Existing members keep paying what they signed up at.
        </p>
      </section>

      <!-- Benefits -->
      <section class="mp-section" data-field="benefits">
        <div class="mp-section-head">
          <span class="mp-step">3</span>
          <div>
            <h3 class="mp-section-title">Benefits</h3>
            <p class="mp-section-sub">
              Free delivery and the discount are applied at checkout; early access emails members about new products. Display lines are wording only.
            </p>
          </div>
        </div>

        <field-error :messages="fieldErrors && fieldErrors.benefits" />

        <ul v-if="result.benefits.length" class="mp-benefits">
          <li v-for="(b, index) in result.benefits" :key="b.uid" class="mp-benefit"
              :class="{open: openUid === b.uid, invalid: hasError && !b.title}">
            <div class="mp-benefit-row">
              <span class="mp-benefit-icon" aria-hidden="true">
                <i v-if="isFaIcon(b.icon)" :class="faClass(b.icon)"></i>
                <span v-else>{{ b.icon || '•' }}</span>
              </span>

              <button type="button" class="mp-benefit-main" :aria-expanded="openUid === b.uid ? 'true' : 'false'" @click="toggle(b)">
                <span class="mp-benefit-title">{{ b.title || 'Untitled benefit' }}</span>
                <span class="mp-benefit-sub">{{ b.subtitle || typeInfo[b.type].hint }}</span>
              </button>

              <span class="mp-badge" :class="'mp-badge-' + b.type">{{ badge(b) }}</span>
              <span v-if="b.highlight" class="mp-tile-flag" title="Shown as a tile">
                <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
              </span>

              <div class="mp-tools">
                <button type="button" class="mp-icon-btn" :disabled="index === 0" aria-label="Move up" @click="move(index, -1)">
                  <i class="fa-solid fa-arrow-up" aria-hidden="true"></i>
                </button>
                <button type="button" class="mp-icon-btn" :disabled="index === result.benefits.length - 1" aria-label="Move down" @click="move(index, 1)">
                  <i class="fa-solid fa-arrow-down" aria-hidden="true"></i>
                </button>
                <button type="button" class="mp-icon-btn mp-icon-danger" aria-label="Remove benefit" @click="remove(index)">
                  <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
                </button>
                <button type="button" class="mp-icon-btn" :aria-label="openUid === b.uid ? 'Close' : 'Edit'" @click="toggle(b)">
                  <i class="fa-solid" :class="openUid === b.uid ? 'fa-chevron-up' : 'fa-pen'" aria-hidden="true"></i>
                </button>
              </div>
            </div>

            <div v-if="openUid === b.uid" class="mp-benefit-edit">
              <div class="mp-grid mp-grid-2">
                <div class="mp-field">
                  <label>Title</label>
                  <input v-model="b.title" type="text" maxlength="100" placeholder="e.g. Free Shipping"
                         :class="{invalid: !b.title && hasError}">
                  <span v-if="!b.title && hasError" class="mp-error">Every benefit needs a title.</span>
                </div>
                <div class="mp-field">
                  <label>Subtitle <span class="mp-optional">optional</span></label>
                  <input v-model="b.subtitle" type="text" maxlength="255" placeholder="e.g. On all orders, no minimum">
                </div>
              </div>

              <div class="mp-field">
                <label>Icon</label>
                <div class="mp-icons">
                  <button v-for="icon in iconChoices" :key="icon" type="button" class="mp-icon-choice"
                          :class="{active: b.icon === icon}" :aria-label="icon" @click="b.icon = icon">
                    <i v-if="isFaIcon(icon)" :class="faClass(icon)" aria-hidden="true"></i>
                    <span v-else>{{ icon }}</span>
                  </button>
                  <input v-model="b.icon" type="text" maxlength="60" class="mp-icon-input" placeholder="fa-… or symbol" aria-label="Custom icon">
                </div>
              </div>

              <div v-if="b.type === 'percent_discount'" class="mp-grid">
                <div class="mp-field">
                  <label>Discount</label>
                  <div class="mp-affix mp-affix-end" :class="{invalid: !(Number(b.percent) > 0) && hasError}">
                    <input v-model="b.percent" type="number" min="0.01" max="100" step="0.01" placeholder="10">
                    <span class="mp-affix-text">%</span>
                  </div>
                </div>
                <div class="mp-field">
                  <label>Most off one order <span class="mp-optional">optional</span></label>
                  <div class="mp-affix">
                    <span class="mp-affix-text">{{ currencyIcon }}</span>
                    <input v-model="b.max_discount" type="number" min="0" step="0.01" placeholder="No limit">
                  </div>
                </div>
                <div class="mp-field">
                  <label>Minimum order <span class="mp-optional">optional</span></label>
                  <div class="mp-affix">
                    <span class="mp-affix-text">{{ currencyIcon }}</span>
                    <input v-model="b.min_order" type="number" min="0" step="0.01" placeholder="None">
                  </div>
                </div>
              </div>

              <div v-if="b.type === 'free_shipping'" class="mp-grid">
                <div class="mp-field">
                  <label>Minimum order <span class="mp-optional">optional</span></label>
                  <div class="mp-affix">
                    <span class="mp-affix-text">{{ currencyIcon }}</span>
                    <input v-model="b.min_order" type="number" min="0" step="0.01" placeholder="None">
                  </div>
                </div>
              </div>

              <div class="mp-switches">
                <template v-if="b.type === 'percent_discount'">
                  <div class="mp-switch-row">
                    <div>
                      <strong>Include sale items</strong>
                      <span>Also discount items already reduced by a flash sale or clearance.</span>
                    </div>
                    <button type="button" class="mp-switch" role="switch" :aria-checked="b.include_sale_items ? 'true' : 'false'"
                            aria-label="Include sale items" @click="b.include_sale_items = !b.include_sale_items">
                      <span class="mp-switch-knob"></span>
                    </button>
                  </div>
                  <div class="mp-switch-row">
                    <div>
                      <strong>Include business prices</strong>
                      <span>Also discount lines charged at a business or trade band price.</span>
                    </div>
                    <button type="button" class="mp-switch" role="switch" :aria-checked="b.include_business_prices ? 'true' : 'false'"
                            aria-label="Include business prices" @click="b.include_business_prices = !b.include_business_prices">
                      <span class="mp-switch-knob"></span>
                    </button>
                  </div>
                </template>
                <div class="mp-switch-row">
                  <div>
                    <strong>Show as a tile</strong>
                    <span>An icon across the top of the membership box. Every benefit is also listed under the plan's benefits.</span>
                  </div>
                  <button type="button" class="mp-switch" role="switch" :aria-checked="b.highlight ? 'true' : 'false'"
                          aria-label="Show as a tile" @click="b.highlight = !b.highlight">
                    <span class="mp-switch-knob"></span>
                  </button>
                </div>
              </div>

              <p class="mp-hint">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                {{ typeInfo[b.type].note }}
              </p>
            </div>
          </li>
        </ul>
        <p v-else class="mp-empty">No benefits yet. Add at least one so customers know what they are paying for.</p>

        <div class="mp-add">
          <span class="mp-add-label">Add</span>
          <button v-for="(info, type) in typeInfo" :key="type" type="button" class="mp-preset"
                  :disabled="type !== 'display' && hasType(type)"
                  :title="type !== 'display' && hasType(type) ? 'A plan can have only one of these' : ''"
                  @click="addBenefit(type)">
            <i :class="'fa-solid ' + info.icon" aria-hidden="true"></i> {{ info.title }}
          </button>
        </div>
      </section>
    </template>

    <!-- ══════════ sidebar ══════════ -->
    <template v-slot:sidebar>
      <div class="mp-side">

        <div class="mp-side-card">
          <div class="mp-side-row">
            <div>
              <h4 class="mp-side-title">On sale</h4>
              <p class="mp-side-sub">
                {{ result.status ? 'Offered on the Savers Club page.' : 'Hidden from customers. Existing members keep their plan.' }}
              </p>
            </div>
            <button type="button" class="mp-switch" role="switch" :aria-checked="result.status ? 'true' : 'false'"
                    aria-label="On sale" @click="result.status = result.status ? 0 : 1">
              <span class="mp-switch-knob"></span>
            </button>
          </div>
        </div>

        <div class="mp-side-card">
          <h4 class="mp-side-title">Customer preview</h4>
          <div class="mp-preview" :class="{off: !result.status}">
            <div class="mp-preview-head">
              <strong>{{ (result.name || 'Plan') + ' Membership' }}</strong>
              <span>{{ result.tagline || 'Unlock exclusive benefits and special discounts' }}</span>
            </div>
            <div v-if="previewTiles.length" class="mp-preview-tiles">
              <div v-for="b in previewTiles" :key="b.uid" class="mp-preview-tile">
                <span class="mp-preview-icon">
                  <i v-if="isFaIcon(b.icon)" :class="faClass(b.icon)" aria-hidden="true"></i>
                  <span v-else>{{ b.icon || '•' }}</span>
                </span>
                <b>{{ b.title || 'Untitled' }}</b>
                <small>{{ b.subtitle }}</small>
              </div>
            </div>
            <div v-if="offered.length > 1" class="mp-preview-toggle" role="radiogroup" aria-label="Preview billing">
              <button v-for="i in offered" :key="i" type="button" role="radio"
                      :aria-checked="shownInterval === i ? 'true' : 'false'" @click="previewInterval = i">
                {{ i === 'year' ? 'Yearly' : 'Monthly' }}
                <em v-if="i === 'year' && yearlySaving">-{{ yearlySaving }}%</em>
              </button>
            </div>
            <div class="mp-preview-plan">
              <div class="mp-preview-plan-top">
                <div>
                  <b>{{ result.name || 'Plan' }}</b>
                  <small>{{ previewBilled }}</small>
                </div>
                <div class="mp-preview-price">
                  <b>{{ currencyIcon }}{{ money(shownInterval === 'year' ? result.yearly_price : result.price) }}</b>
                  <small>{{ perLabel }}</small>
                </div>
              </div>
              <span class="mp-preview-join">{{ 'Join ' + (result.name || 'plan') }}</span>
            </div>
          </div>
          <p v-if="!result.status" class="mp-side-sub mt-8">Customers will not see this until the plan is on sale.</p>
        </div>

        <div v-if="isEditing" class="mp-side-card">
          <div class="mp-side-row">
            <h4 class="mp-side-title">Stripe</h4>
            <span class="mp-chip" :class="synced ? 'ok' : 'wait'">
              {{ synced ? 'Synced' : 'Not synced' }}
            </span>
          </div>
          <dl class="mp-dl">
            <dt>Product</dt><dd>{{ result.stripe_product_id || '—' }}</dd>
            <dt>Monthly</dt><dd>{{ result.stripe_price_id || (Number(result.price) > 0 ? 'Not created yet' : 'Not offered') }}</dd>
            <dt>Yearly</dt><dd>{{ result.stripe_yearly_price_id || (Number(result.yearly_price) > 0 ? 'Not created yet' : 'Not offered') }}</dd>
            <dt>Members</dt>
            <dd><nuxt-link :to="`/memberships?plan_id=${result.id}`" class="link">{{ result.members_count || 0 }} with benefits</nuxt-link></dd>
          </dl>
          <button type="button" class="mp-btn mp-btn-ghost mp-btn-block" :disabled="syncing" @click="syncStripe">
            <i class="fa-solid fa-rotate" :class="{'fa-spin': syncing}" aria-hidden="true"></i>
            {{ syncing ? 'Syncing…' : 'Sync with Stripe' }}
          </button>
          <p class="mp-side-sub mt-8">Happens on every save while the plan is on sale.</p>
        </div>

      </div>
    </template>
  </data-page>
</template>

<script>
import DataPage from '~/components/partials/DataPage'
import FieldError from '~/components/FieldError'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

let uid = 0

function benefit(fields = {}) {
  return {
    uid: ++uid,
    id: null,
    type: 'display',
    title: '',
    subtitle: '',
    icon: '',
    highlight: false,
    percent: '',
    min_order: '',
    max_discount: '',
    include_sale_items: false,
    include_business_prices: false,
    ...fields
  }
}

// What a new benefit of each type starts as.
const STARTERS = {
  free_shipping: { title: 'Free Shipping', subtitle: 'On all orders, no minimum', icon: 'fa-truck', highlight: true },
  percent_discount: { title: 'Exclusive Discounts', subtitle: '10% off full-price items', icon: '%', percent: 10, highlight: true },
  early_access: { title: 'Early Access', subtitle: 'Hear about new products first', icon: 'fa-star', highlight: true },
  display: { title: '', subtitle: '', icon: 'fa-circle-check', highlight: false }
}

export default {
  name: 'membership-plan',
  middleware: ['common-middleware', 'auth'],
  components: { DataPage, FieldError },
  mixins: [util],

  data() {
    return {
      stripeWarning: '',
      syncing: false,
      originalPriceKey: '',
      openUid: null,
      // which price the customer preview shows
      previewInterval: 'month',
      typeInfo: {
        free_shipping: {
          title: 'Free delivery',
          icon: 'fa-truck',
          hint: 'Delivery is free at checkout',
          note: 'Delivery costs nothing at checkout. Collection keeps its own price.'
        },
        percent_discount: {
          title: 'Percentage discount',
          icon: 'fa-percent',
          hint: 'Taken off the order at checkout',
          note: 'Never combined with a loyalty group discount: the customer gets whichever is bigger. Vouchers and points still apply.'
        },
        early_access: {
          title: 'Early access',
          icon: 'fa-star',
          hint: 'Emailed when new products arrive',
          note: 'Members are emailed whenever new products go on sale (products published together come in one email). Anyone can buy them as normal. Members can turn the emails off.'
        },
        display: {
          title: 'Display line',
          icon: 'fa-align-left',
          hint: 'Wording only',
          note: 'Wording only. Nothing is applied at checkout.'
        }
      },
      iconChoices: ['fa-truck', '%', 'fa-star', 'fa-bolt', 'fa-gift', 'fa-headset', 'fa-crown', 'fa-tag', 'fa-shield-halved', 'fa-rotate-left'],
      // A new plan starts with the three tiles the Savers Club box has always shown.
      result: {
        id: '',
        name: '',
        tagline: 'Unlock exclusive benefits and special discounts',
        price: '',
        yearly_price: '',
        interval: 'month',
        interval_count: 1,
        trial_days: 0,
        status: 0,
        sort_order: 0,
        stripe_product_id: null,
        stripe_price_id: null,
        stripe_yearly_price_id: null,
        members_count: 0,
        benefits: [
          benefit({ type: 'free_shipping', ...STARTERS.free_shipping }),
          benefit({ type: 'percent_discount', ...STARTERS.percent_discount }),
          benefit({ type: 'early_access', ...STARTERS.early_access })
        ]
      }
    }
  },

  computed: {
    isEditing() {
      return !isNaN(this.$route?.params?.id)
    },
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    // the billing periods offered, monthly first
    offered() {
      return ['month', 'year'].filter(i => Number(i === 'year' ? this.result.yearly_price : this.result.price) > 0)
    },
    noPrice() {
      return this.offered.length === 0
    },
    shownInterval() {
      return this.offered.includes(this.previewInterval) ? this.previewInterval : (this.offered[0] || 'month')
    },
    yearlySaving() {
      const m = Number(this.result.price), y = Number(this.result.yearly_price)
      if (!(m > 0) || !(y > 0)) return null
      const saving = Math.floor((1 - y / (m * 12)) * 100)
      return saving > 0 ? saving : null
    },
    yearlyCostsMore() {
      const m = Number(this.result.price), y = Number(this.result.yearly_price)
      return m > 0 && y > 0 && y > m * 12
    },
    perLabel() {
      return this.shownInterval === 'year' ? 'per year' : 'per month'
    },
    previewBilled() {
      const every = this.shownInterval === 'year' ? 'yearly' : 'monthly'
      return `Billed ${every}. Cancel anytime.`
    },
    priceSummary() {
      const m = Number(this.result.price) > 0 ? `${this.currencyIcon}${this.money(this.result.price)} per month` : null
      const y = Number(this.result.yearly_price) > 0 ? `${this.currencyIcon}${this.money(this.result.yearly_price)} per year` : null
      if (m && y) {
        return `Customers choose ${m} or ${y}${this.yearlySaving ? ` (saves ${this.yearlySaving}%)` : ''}.`
      }
      return `Members pay ${m || y}, until they cancel.`
    },
    previewTiles() {
      return this.result.benefits.filter(b => b.highlight).slice(0, 3)
    },
    priceKey() {
      return [Number(this.result.price || 0).toFixed(2), Number(this.result.yearly_price || 0).toFixed(2)].join('|')
    },
    priceChanged() {
      return !!(this.result.stripe_price_id || this.result.stripe_yearly_price_id)
        && this.originalPriceKey !== '' && this.priceKey !== this.originalPriceKey
    },
    // every period on offer has its Stripe price
    synced() {
      return this.offered.length > 0
        && this.offered.every(i => (i === 'year' ? this.result.stripe_yearly_price_id : this.result.stripe_price_id))
    },
    ...mapGetters('setting', ['setting'])
  },

  methods: {
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    settingResult(evt) {
      this.stripeWarning = evt.stripe_warning || ''
      if (this.stripeWarning) this.setToastError(this.stripeWarning)

      this.result = {
        id: evt.id,
        name: evt.name || '',
        tagline: evt.tagline || '',
        price: evt.price ?? '',
        yearly_price: evt.yearly_price ?? '',
        interval: evt.interval || 'month',
        interval_count: evt.interval_count || 1,
        trial_days: evt.trial_days || 0,
        status: evt.status ? 1 : 0,
        sort_order: evt.sort_order || 0,
        stripe_product_id: evt.stripe_product_id,
        stripe_price_id: evt.stripe_price_id,
        stripe_yearly_price_id: evt.stripe_yearly_price_id,
        members_count: evt.members_count ?? this.result.members_count,
        benefits: (evt.benefits || []).map(b => benefit({
          id: b.id,
          type: b.type,
          title: b.title || '',
          subtitle: b.subtitle || '',
          icon: b.icon || '',
          highlight: !!b.highlight,
          percent: b.percent ?? '',
          min_order: b.min_order ?? '',
          max_discount: b.max_discount ?? '',
          include_sale_items: !!b.include_sale_items,
          include_business_prices: !!b.include_business_prices
        }))
      }

      this.openUid = null
      this.originalPriceKey = this.priceKey
    },
    hasType(type) {
      return this.result.benefits.some(b => b.type === type)
    },
    badge(b) {
      if (b.type === 'percent_discount') return Number(b.percent) > 0 ? `${Number(b.percent)}% off` : 'Discount'
      if (b.type === 'free_shipping') return 'Free delivery'
      if (b.type === 'early_access') return 'New product emails'
      return 'Display'
    },
    toggle(b) {
      this.openUid = this.openUid === b.uid ? null : b.uid
    },
    addBenefit(type) {
      const item = benefit({ type, ...STARTERS[type] })
      this.result.benefits.push(item)
      this.openUid = item.uid
    },
    remove(index) {
      const [item] = this.result.benefits.splice(index, 1)
      if (item && item.uid === this.openUid) this.openUid = null
    },
    move(index, step) {
      const list = this.result.benefits
      const [item] = list.splice(index, 1)
      list.splice(index + step, 0, item)
    },
    isFaIcon(icon) {
      return /^fa[-\s]/.test(String(icon || '').trim())
    },
    faClass(icon) {
      const value = String(icon).trim()
      return /\bfa-(solid|regular|brands)\b/.test(value) ? value : `fa-solid ${value}`
    },
    async syncStripe() {
      this.syncing = true
      try {
        const data = await this.setById({ id: this.result.id, params: {}, api: 'syncMembershipPlan' })
        if (data) {
          this.settingResult(data)
          this.setToastMessage('Synced with Stripe')
        } else {
          const errors = this.$store.getters['ui/errors']
          this.stripeWarning = (errors?.form || [])[0] || 'Stripe did not accept this plan.'
        }
      } catch (e) {
        this.setToastError(e?.message || 'Stripe did not accept this plan')
      } finally {
        this.syncing = false
      }
    },
    // One plan for now: the add form sends you to the plan there is.
    async redirectIfPlanExists() {
      try {
        const data = await this.getRequest({ api: 'getMembershipPlans', params: { page: 1 } })
        const existing = (data?.data || [])[0]
        if (existing && Number(data.plan_count) >= Number(data.max_plans || 1)) {
          this.setToastMessage('There is already a membership plan. Edit it here.')
          this.$router.replace(`/membership-plans/${existing.id}`)
        }
      } catch (e) {
        // the server refuses a second plan on save anyway
      }
    },
    ...mapActions('common', ['setById', 'getRequest']),
    ...mapActions('ui', ['setToastError', 'setToastMessage'])
  },

  mounted() {
    if (!this.isEditing) this.redirectIfPlanExists()
  }
}
</script>

<style scoped>
/*
 * Same design language as gift-vouchers/_id.vue. Buttons set height,
 * line-height and every hover state with !important: base.styl's global
 * button rules (50px height, a hover that outranks scoped CSS, a purple
 * border on :hover:enabled) otherwise win.
 */

/* ---------- page ---------- */

::v-deep .edit-aside { width: 340px; }

@media only screen and (min-width: 993px) {
  ::v-deep .edit-aside { position: sticky; top: 76px; }
}

.mp-alert {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin-bottom: 18px;
  padding: 12px 14px;
  border: 1px solid #FECDCA;
  border-radius: 10px;
  background: #FEF3F2;
  color: #B42318;
  font-size: 13px;
}

.mp-alert i { margin-top: 2px; }

/* ---------- sections ---------- */

.mp-section {
  padding: 4px 0 24px;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--border-color, #E5E7EB);
}

.mp-section:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }

.mp-section-head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 18px;
}

.mp-step {
  flex: 0 0 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--primary-soft, #EFEFFF);
  color: var(--primary-color, #33319A);
  font-weight: 700;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mp-section-title { margin: 0; font-size: 15.5px; font-weight: 700; color: var(--text-strong, #111827); }
.mp-section-sub { margin: 2px 0 0; font-size: 13px; color: var(--text-muted, #6B7280); }

.mp-optional { font-weight: 500; color: var(--text-soft, #9CA3AF); margin-left: 4px; text-transform: none; }

.mp-field-foot { display: flex; justify-content: space-between; gap: 12px; min-height: 18px; margin-top: 4px; }
.mp-count { margin-left: auto; font-size: 11.5px; color: var(--text-soft, #9CA3AF); font-variant-numeric: tabular-nums; }
.mp-error { display: block; margin-top: 6px; font-size: 12px; color: var(--danger-text, #B42318); }

/* ---------- fields ---------- */

.mp-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 16px;
}

.mp-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

.mp-field { min-width: 0; margin-bottom: 14px; }

.mp-field > label {
  display: block;
  margin-bottom: 7px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-muted, #6B7280);
  letter-spacing: .01em;
}

.tab-sidebar .form-wrapper .mp-field input:not([type='checkbox']),
.tab-sidebar .form-wrapper .input-wrapper input:not([type='checkbox']) {
  width: 100%;
  height: 42px;
  padding: 0 12px;
  font-size: 14px;
}

/* an input with a fixed word or symbol before or after it */
.mp-affix {
  display: flex;
  align-items: stretch;
  height: 42px;
  border: 1px solid var(--border-strong, #D1D5DB);
  border-radius: var(--radius-md, 8px);
  background: #fff;
  overflow: hidden;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.mp-affix:focus-within {
  border-color: var(--primary-color, #33319A);
  box-shadow: 0 0 0 3px var(--primary-soft, #EFEFFF);
}

.mp-affix.invalid { border-color: var(--danger-text, #B42318); }

.mp-affix-text {
  display: flex;
  align-items: center;
  padding: 0 12px;
  background: #F9FAFB;
  color: var(--text-muted, #6B7280);
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  border-right: 1px solid var(--border-color, #E5E7EB);
}

.mp-affix-end .mp-affix-text,
.mp-affix .mp-affix-after { border-right: 0; border-left: 1px solid var(--border-color, #E5E7EB); }

.mp-saving {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #027A48;
}

.mp-error-block { margin: 0 0 10px; }

/* the storefront's Monthly / Yearly switch, small */
.mp-preview-toggle {
  display: flex;
  margin: 10px 10px 0;
  padding: 3px;
  border-radius: 8px;
  background: #EEF0F6;
}

.mp-preview-toggle button {
  flex: 1 1 0;
  height: 26px !important;
  line-height: 1 !important;
  padding: 0;
  border: 0 !important;
  border-radius: 6px;
  background: transparent !important;
  color: #6B7280 !important;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}

.mp-preview-toggle button[aria-checked='true'] {
  background: #fff !important;
  color: #130E2B !important;
  box-shadow: 0 1px 2px rgba(16, 24, 40, .1);
}

.mp-preview-toggle em { font-style: normal; color: #027A48; margin-left: 2px; }

.tab-sidebar .form-wrapper .mp-affix input:not([type='checkbox']) {
  flex: 1 1 auto;
  width: 0;
  min-width: 0;
  height: 100%;
  border: 0;
  border-radius: 0;
  box-shadow: none;
  -moz-appearance: textfield;
}

.mp-affix input::-webkit-outer-spin-button,
.mp-affix input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

/* segmented control (billing period) */
.mp-segment {
  display: flex;
  height: 42px;
  padding: 3px;
  border-radius: var(--radius-md, 8px);
  background: #F3F4F6;
}

.mp-segment button {
  flex: 1 1 0;
  height: 100% !important;
  line-height: 1 !important;
  padding: 0 12px;
  border: 0 !important;
  border-radius: 6px;
  background: transparent !important;
  color: var(--text-muted, #6B7280) !important;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}

.mp-segment button[aria-checked='true'] {
  background: #fff !important;
  color: var(--text-strong, #111827) !important;
  box-shadow: 0 1px 2px rgba(16, 24, 40, .1);
}

.mp-segment button:hover:not([aria-checked='true']) { color: var(--text-strong, #111827) !important; }

.mp-summary {
  display: flex;
  gap: 8px;
  align-items: center;
  margin: 2px 0 0;
  padding: 10px 12px;
  border-radius: var(--radius-md, 8px);
  background: #F6FEF9;
  color: #05603A;
  font-size: 13px;
}

.mp-hint {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin: 10px 0 0;
  font-size: 12.5px;
  color: var(--text-muted, #6B7280);
}

.mp-hint i { margin-top: 2px; }
.mp-hint-warn { padding: 10px 12px; border-radius: var(--radius-md, 8px); background: #FFFAEB; color: #93370D; }

/* ---------- benefits ---------- */

.mp-benefits { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }

.mp-benefit {
  border: 1px solid var(--border-color, #E5E7EB);
  border-radius: 12px;
  background: #fff;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.mp-benefit:hover { border-color: var(--border-strong, #D1D5DB); }
.mp-benefit.open { border-color: var(--primary-color, #33319A); box-shadow: 0 0 0 3px var(--primary-soft, #EFEFFF); }
.mp-benefit.invalid { border-color: var(--danger-text, #B42318); }

.mp-benefit-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 12px 12px 14px;
}

.mp-benefit-icon,
.mp-preview-icon {
  flex: 0 0 38px;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #F1F2F6;
  color: #130E2B;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  font-weight: 600;
}

.mp-benefit-main {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  height: auto !important;
  line-height: 1.3 !important;
  padding: 0;
  border: 0 !important;
  background: transparent !important;
  text-align: left;
  cursor: pointer;
}

.mp-benefit-title { font-size: 14px; font-weight: 600; color: var(--text-strong, #111827); }

.mp-benefit-sub {
  max-width: 100%;
  font-size: 12.5px;
  color: var(--text-muted, #6B7280);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mp-badge {
  flex-shrink: 0;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 700;
  white-space: nowrap;
}

.mp-badge-free_shipping { background: #ECFDF3; color: #027A48; }
.mp-badge-percent_discount { background: var(--primary-soft, #EFEFFF); color: var(--primary-color, #33319A); }
.mp-badge-early_access { background: #FFFAEB; color: #B54708; }
.mp-badge-display { background: #F2F4F7; color: #475467; }

.mp-tile-flag { flex-shrink: 0; color: var(--text-soft, #9CA3AF); font-size: 13px; }

.mp-tools { display: flex; gap: 4px; flex-shrink: 0; }

.mp-icon-btn {
  width: 32px;
  height: 32px !important;
  line-height: 1 !important;
  padding: 0;
  border: 0 !important;
  border-radius: 8px;
  background: transparent !important;
  color: var(--text-muted, #6B7280) !important;
  font-size: 13px;
  cursor: pointer;
}

.mp-icon-btn:hover:enabled { background: #F3F4F6 !important; color: var(--text-strong, #111827) !important; }
.mp-icon-danger:hover:enabled { background: #FEF3F2 !important; color: #B42318 !important; }
.mp-icon-btn:disabled { opacity: .3; cursor: not-allowed; }

.mp-benefit-edit { padding: 4px 16px 16px; border-top: 1px solid #F2F4F7; margin-top: 2px; padding-top: 16px; }

/* icon picker */
.mp-icons { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }

.mp-icon-choice {
  width: 38px;
  height: 38px !important;
  line-height: 1 !important;
  padding: 0;
  border: 1px solid var(--border-color, #E5E7EB) !important;
  border-radius: 8px;
  background: #fff !important;
  color: #130E2B !important;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.mp-icon-choice:hover { border-color: var(--border-strong, #D1D5DB) !important; background: #F9FAFB !important; }

.mp-icon-choice.active {
  border-color: var(--primary-color, #33319A) !important;
  background: var(--primary-soft, #EFEFFF) !important;
  color: var(--primary-color, #33319A) !important;
}

.tab-sidebar .form-wrapper .mp-icons input.mp-icon-input { width: 150px; height: 38px; }

/* switch rows */
.mp-switches {
  border: 1px solid var(--border-color, #E5E7EB);
  border-radius: 10px;
  overflow: hidden;
}

.mp-switch-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 12px 14px;
}

.mp-switch-row + .mp-switch-row { border-top: 1px solid #F2F4F7; }
.mp-switch-row strong { display: block; font-size: 13.5px; font-weight: 600; color: var(--text-strong, #111827); }
.mp-switch-row span { display: block; font-size: 12.5px; color: var(--text-muted, #6B7280); }

.mp-switch {
  position: relative;
  flex: 0 0 44px;
  width: 44px;
  height: 24px !important;
  line-height: 1 !important;
  padding: 0;
  border: 0 !important;
  border-radius: 999px;
  background: var(--border-strong, #D1D5DB) !important;
  cursor: pointer;
  transition: background-color .2s ease;
}

.mp-switch:hover { background: #BFC3CC !important; }
.mp-switch[aria-checked='true'] { background: #12B76A !important; }
.mp-switch[aria-checked='true']:hover { background: #0E9F5B !important; }
.mp-switch:focus-visible { outline: 2px solid var(--primary-color, #33319A); outline-offset: 2px; }

.mp-switch-knob {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
  transition: transform .2s ease;
}

.mp-switch[aria-checked='true'] .mp-switch-knob { transform: translateX(20px); }

/* add bar */
.mp-empty {
  margin: 0;
  padding: 12px 14px;
  border-radius: var(--radius-md, 8px);
  background: #FFF7D6;
  color: #7A5A00;
  font-size: 13px;
}

.mp-add { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 14px; }

.mp-add-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-soft, #9CA3AF);
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-right: 2px;
}

.mp-preset {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px !important;
  line-height: 1 !important;
  padding: 0 14px;
  border: 1px dashed var(--border-strong, #D1D5DB) !important;
  border-radius: 999px;
  background: #fff !important;
  color: var(--text-color, #1F2937) !important;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.mp-preset:hover:enabled {
  border-style: solid !important;
  border-color: var(--primary-color, #33319A) !important;
  color: var(--primary-color, #33319A) !important;
  background: var(--primary-soft, #EFEFFF) !important;
}

.mp-preset:disabled { opacity: .45; cursor: not-allowed; }

/* ---------- sidebar ---------- */

.mp-side { display: flex; flex-direction: column; gap: 16px; }

.mp-side-card {
  background: #fff;
  border: 1px solid var(--border-color, #E5E7EB);
  border-radius: 12px;
  padding: 16px;
}

.mp-side-title { margin: 0 0 10px; font-size: 14px; font-weight: 700; color: var(--text-strong, #111827); }
.mp-side-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mp-side-row .mp-side-title { margin-bottom: 2px; }
.mp-side-sub { margin: 0; font-size: 12.5px; color: var(--text-muted, #6B7280); line-height: 1.45; }
.mp-side-inline { align-items: center; margin-bottom: 6px; }
.mp-side-label { margin: 0; font-size: 13px; font-weight: 600; color: var(--text-strong, #111827); }
.mt-8 { margin-top: 8px; }

/* customer preview: the Savers Club box, small */
.mp-preview {
  border: 1px solid #E3E3EF;
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 6px 18px rgba(17, 24, 39, .06);
  transition: opacity .2s ease;
}

.mp-preview.off { opacity: .6; }

.mp-preview-head { padding: 12px 14px; background: #F3F3FA; }
.mp-preview-head strong { display: block; font-size: 13.5px; color: #130E2B; }
.mp-preview-head span { display: block; font-size: 11.5px; color: #6B7280; margin-top: 2px; }

.mp-preview-tiles {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
  padding: 14px 10px 4px;
  text-align: center;
}

.mp-preview-tile { display: flex; flex-direction: column; align-items: center; gap: 3px; min-width: 0; }
.mp-preview-tile .mp-preview-icon { flex: 0 0 32px; width: 32px; height: 32px; font-size: 13px; }
.mp-preview-tile b { font-size: 11px; color: #130E2B; line-height: 1.25; overflow-wrap: anywhere; }
.mp-preview-tile small { font-size: 10px; color: #6B7280; line-height: 1.3; overflow-wrap: anywhere; }

.mp-preview-plan { margin: 10px; padding: 12px; border-radius: 10px; background: #F7F8FC; }
.mp-preview-plan-top { display: flex; justify-content: space-between; gap: 8px; }
.mp-preview-plan-top b { display: block; font-size: 12.5px; color: #130E2B; }
.mp-preview-plan-top small { display: block; font-size: 10.5px; color: #6B7280; line-height: 1.35; }
.mp-preview-price { text-align: right; flex-shrink: 0; }
.mp-preview-price b { font-size: 14px; }

.mp-preview-join {
  display: block;
  margin-top: 10px;
  padding: 8px;
  border-radius: 7px;
  background: #05B942;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  text-align: center;
}

/* Stripe card */
.mp-chip { flex-shrink: 0; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
.mp-chip.ok { background: #ECFDF3; color: #027A48; }
.mp-chip.wait { background: #FFFAEB; color: #B54708; }

.mp-dl { display: grid; grid-template-columns: 70px 1fr; gap: 6px 10px; margin: 10px 0 14px; font-size: 12.5px; }
.mp-dl dt { color: var(--text-muted, #6B7280); }
.mp-dl dd { margin: 0; color: var(--text-strong, #111827); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; word-break: break-all; }
.mp-dl dd .link { font-family: inherit; font-size: 12.5px; }
.mp-dl dd:last-child { font-family: inherit; }

.mp-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 38px !important;
  line-height: 1 !important;
  padding: 0 14px;
  border-radius: var(--radius-md, 8px);
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
}

.mp-btn-block { width: 100%; }

.mp-btn-ghost,
.mp-btn-ghost:disabled:hover {
  background: #fff !important;
  border: 1px solid var(--border-strong, #D1D5DB) !important;
  color: var(--text-strong, #111827) !important;
}

.mp-btn-ghost:hover:enabled { background: #F3F4F6 !important; border-color: var(--border-strong, #D1D5DB) !important; }
.mp-btn:disabled { opacity: .6; cursor: not-allowed; }

@media only screen and (max-width: 992px) {
  ::v-deep .edit-aside { width: 100%; }
}

@media only screen and (max-width: 640px) {
  .mp-grid-2 { grid-template-columns: 1fr; }
  .mp-benefit-row { flex-wrap: wrap; }
  .mp-badge, .mp-tile-flag { order: 3; }
}
</style>
