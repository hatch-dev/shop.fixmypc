<template>
  <div class="return-start p-20">
    <nuxt-link v-if="orderId" :to="`/orders/${orderId}`" class="rd-back">← {{ $t('pages.returns.order') }}</nuxt-link>
    <nuxt-link v-else to="/returns" class="rd-back">← {{ $t('pages.returns.returns') }}</nuxt-link>

    <div v-if="loading" class="rd-card">{{ $t('pages.returns.loading') }}</div>
    <div v-else-if="loadError" class="rd-card rd-error">{{ loadError }}</div>

    <template v-else-if="info">
      <div class="rd-card rd-head">
        <div>
          <h3 class="rd-title">{{ isExchange ? $t('pages.returns.startExchange') : $t('pages.returns.startReturn') }}</h3>
          <p class="rd-sub">
            {{ $t('pages.returns.orderNumber', { number: info.order.order }) }}
            <template v-if="info.order.contact && info.order.contact.name"> · {{ info.order.contact.name }}</template>
            <template v-if="info.order.contact && info.order.contact.email"> · {{ info.order.contact.email }}</template>
          </p>
        </div>
      </div>

      <div v-if="!info.eligibility.ok" class="rd-card rd-error" role="alert">{{ info.eligibility.reason }}</div>

      <template v-else>
        <p v-if="info.eligibility.within_window === false" class="rd-warning" role="alert">
          {{ $t('pages.returns.pastWindow', { days: info.eligibility.days_since_delivery, window: info.eligibility.window_days }) }}
        </p>

        <div class="rd-card">
          <div class="rd-type" role="radiogroup" :aria-label="$t('pages.returns.returnOrExchange')">
            <label class="rd-type-option" :class="{active: type === 'return'}">
              <input type="radio" value="return" v-model="type"> {{ $t('pages.returns.returnForRefund') }}
            </label>
            <label class="rd-type-option" :class="{active: type === 'exchange'}" :title="canExchange ? '' : $t('pages.returns.noVariantsToSwap')">
              <input type="radio" value="exchange" v-model="type" :disabled="!canExchange"> {{ $t('pages.returns.exchangeForVariant') }}
            </label>
          </div>
          <p v-if="isExchange" class="rd-hint rd-gap-bottom">
            {{ $t('pages.returns.exchangeExplainer') }}
          </p>

          <h4>{{ $t('pages.returns.items') }}</h4>
          <table class="rd-table">
            <tr><th>{{ $t('pages.returns.item') }}</th><th>{{ $t('pages.returns.bought') }}</th><th>{{ $t('pages.returns.alreadyReturning') }}</th><th>{{ isExchange ? $t('pages.returns.exchange') : $t('pages.returns.returnHeading') }}</th><th v-if="isExchange">{{ $t('pages.returns.swapTo') }}</th></tr>
            <tr v-for="line in info.lines" :key="line.ordered_product_id">
              <td>
                <strong>{{ line.title }}</strong>
                <span v-if="line.variant" class="rd-hint-inline"> · {{ line.variant }}</span>
                <span v-if="!line.refundable" class="pill pill-wait">{{ $t('pages.returns.markedNonRefundable') }}</span>
              </td>
              <td>{{ line.quantity }}</td>
              <td>{{ line.returned_quantity || '—' }}</td>
              <td>
                <input
                  v-if="line.returnable_quantity > 0"
                  v-model.number="quantities[line.ordered_product_id]"
                  type="number" min="0" :max="line.returnable_quantity" step="1"
                  class="rd-qty"
                  :aria-label="$t('pages.returns.unitsToReturnAria', { title: line.title })"
                  @input="clamp(line)"
                >
                <span v-else class="rd-hint-inline">{{ $t('pages.returns.noneLeft') }}</span>
              </td>
              <td v-if="isExchange">
                <template v-if="Number(quantities[line.ordered_product_id]) > 0">
                  <select
                    v-if="line.variants && line.variants.length"
                    v-model="exchangeFor[line.ordered_product_id]"
                    class="rd-select rd-select-sm"
                    :aria-label="$t('pages.returns.variantToSwapAria', { title: line.title })"
                  >
                    <option value="">{{ $t('pages.returns.chooseVariant') }}</option>
                    <option v-for="v in line.variants" :key="v.inventory_id" :value="v.inventory_id" :disabled="!v.available">
                      {{ v.label }} · {{ currencyIcon }}{{ money(v.unit_price) }}{{ v.available ? '' : ' · ' + $t('pages.returns.outOfStock') }}
                    </option>
                  </select>
                  <span v-else class="rd-hint-inline">{{ $t('pages.returns.noOtherVariants') }}</span>
                </template>
                <span v-else class="rd-hint-inline">—</span>
              </td>
            </tr>
          </table>
        </div>

        <div class="rd-grid">
          <div class="rd-card">
            <h4>{{ $t('pages.returns.details') }}</h4>
            <label class="rd-label" for="rt-reason">{{ $t('pages.returns.reason') }}</label>
            <select id="rt-reason" v-model="reason" class="rd-select">
              <option value="">{{ $t('pages.returns.chooseReason') }}</option>
              <option v-for="(label, key) in info.reasons" :key="key" :value="key">{{ label }}</option>
            </select>

            <label class="rd-label" for="rt-cnote">{{ $t('pages.returns.customerSaidOptional') }}</label>
            <textarea id="rt-cnote" v-model="customerNote" rows="3" maxlength="2000"></textarea>

            <label class="rd-label" for="rt-snote">{{ $t('pages.returns.staffNoteOptional') }}</label>
            <textarea id="rt-snote" v-model="staffNote" rows="2" maxlength="2000"></textarea>

            <label class="rd-check">
              <input type="checkbox" v-model="notify">
              {{ $t('pages.returns.emailReturnNumber') }}
            </label>

            <template v-if="notify && info.languages && info.languages.length > 1">
              <label class="rd-label" for="rt-lang">{{ $t('pages.returns.emailLanguage') }}</label>
              <select id="rt-lang" v-model="emailLang" class="rd-select">
                <option v-for="l in info.languages" :key="l.code" :value="l.code">{{ l.name }}</option>
              </select>
            </template>
          </div>

          <div class="rd-card">
            <h4>{{ isExchange ? $t('pages.returns.difference') : $t('pages.returns.refundIfAccepted') }}</h4>
            <p v-if="!units" class="rd-hint">{{ isExchange ? $t('pages.returns.chooseUnitsExchange') : $t('pages.returns.chooseUnitsReturn') }}</p>
            <template v-else-if="isExchange">
              <p v-if="!exchangeComplete" class="rd-hint">{{ $t('pages.returns.chooseVariantEveryUnit') }}</p>
              <template v-else>
                <dl class="rd-dl">
                  <dt>{{ exchangeDifference > 0 ? $t('pages.returns.customerPaysLabel') : exchangeDifference < 0 ? $t('pages.returns.customerGetsBack') : $t('pages.returns.difference') }}</dt>
                  <dd>{{ currencyIcon }}{{ money(Math.abs(exchangeDifference)) }}</dd>
                </dl>
                <p class="rd-hint">{{ $t('pages.returns.todaysPricesRecalc') }}</p>
              </template>
            </template>
            <template v-else-if="quote">
              <dl class="rd-dl">
                <dt>{{ $t('pages.returns.items') }}</dt><dd>{{ currencyIcon }}{{ money(quote.items_refund) }}</dd>
                <template v-if="quote.gift_card > 0"><dt>{{ $t('pages.returns.toGiftCard') }}</dt><dd>{{ currencyIcon }}{{ money(quote.gift_card) }}</dd></template>
                <template v-if="quote.points_returned > 0"><dt>{{ $t('pages.returns.pointsBack') }}</dt><dd>{{ quote.points_returned }}</dd></template>
                <template v-if="quote.points_taken > 0"><dt>{{ $t('pages.returns.pointsTakenBack') }}</dt><dd>{{ quote.points_taken }}</dd></template>
              </dl>
              <p class="rd-hint">
                {{ $t('pages.returns.discountsShared') }}
              </p>
            </template>
            <p v-else class="rd-hint">{{ $t('pages.returns.workingItOut') }}</p>
          </div>
        </div>

        <p v-if="error" class="rd-error-line" role="alert">{{ error }}</p>

        <div class="rd-actions">
          <button type="button" class="primary-btn" :disabled="busy || !units || !reason || (isExchange && !exchangeComplete)" @click="submit">
            {{ busy ? $t('pages.returns.opening') : isExchange ? $tc('pages.returns.openExchangeUnits', units, { count: units }) : $tc('pages.returns.openReturnUnits', units, { count: units }) }}
          </button>
        </div>
      </template>
    </template>
  </div>
</template>

<script>
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

export default {
  name: 'return-start',
  middleware: ['common-middleware', 'auth'],
  mixins: [util],

  data() {
    return {
      info: null,
      loading: true,
      loadError: '',
      quantities: {},
      type: 'return',
      // {ordered_product_id: inventory_id}, for an exchange
      exchangeFor: {},
      reason: '',
      customerNote: '',
      staffNote: '',
      notify: true,
      // Nothing records the customer's language, so staff pick it; English by default.
      emailLang: 'en',
      quote: null,
      quoteTimer: null,
      busy: false,
      error: ''
    }
  },

  computed: {
    orderId() {
      return Number(this.$route.query.order) || null
    },
    currencyIcon() {
      return this.setting?.currency_icon || '€'
    },
    chosen() {
      return Object.keys(this.quantities)
        .map(id => [id, Number(this.quantities[id]) || 0])
        .filter(([, qty]) => qty > 0)
    },
    units() {
      return this.chosen.reduce((sum, [, qty]) => sum + qty, 0)
    },
    isExchange() {
      return this.type === 'exchange'
    },
    canExchange() {
      return (this.info?.lines || []).some(line => line.returnable_quantity > 0 && (line.variants || []).some(v => v.available))
    },
    exchangeComplete() {
      return this.chosen.length > 0 && this.chosen.every(([id]) => !!this.exchangeFor[id])
    },
    /** What the swap costs, from the prices the server sent: new variant minus what was paid. */
    exchangeDifference() {
      let cents = 0
      this.chosen.forEach(([id, qty]) => {
        const line = this.info.lines.find(l => String(l.ordered_product_id) === String(id))
        const variant = (line?.variants || []).find(v => String(v.inventory_id) === String(this.exchangeFor[id]))
        if (line && variant) cents += Math.round((Number(variant.unit_price) - Number(line.unit_price_gross)) * 100) * qty
      })
      return cents / 100
    },
    ...mapGetters('setting', ['setting'])
  },

  watch: {
    chosen() {
      this.refreshQuote()
    }
  },

  methods: {
    money(v) {
      return Number(v || 0).toFixed(2)
    },
    clamp(line) {
      const id = line.ordered_product_id
      let qty = Math.floor(Number(this.quantities[id]) || 0)
      qty = Math.max(0, Math.min(qty, line.returnable_quantity))
      if (qty !== this.quantities[id]) this.$set(this.quantities, id, qty)
    },
    refreshQuote() {
      clearTimeout(this.quoteTimer)
      this.quote = null
      if (!this.units) return
      this.quoteTimer = setTimeout(async () => {
        try {
          this.quote = await this.getRequest({
            api: 'getReturnQuote',
            params: { order_id: this.orderId, items: this.chosen.map(([id, qty]) => `${id}:${qty}`).join(',') }
          })
        } catch (e) {
          this.quote = null
        }
      }, 250)
    },
    async load() {
      if (!this.orderId) {
        this.loadError = this.$t('pages.returns.noOrderGiven')
        this.loading = false
        return
      }
      try {
        this.info = await this.getById({ id: this.orderId, params: {}, api: 'getReturnsForOrder' })
        const quantities = {}
        const exchangeFor = {}
        this.info.lines.forEach(line => { quantities[line.ordered_product_id] = 0; exchangeFor[line.ordered_product_id] = '' })
        this.quantities = quantities
        this.exchangeFor = exchangeFor
        if (this.$route.query.type === 'exchange' && this.canExchange) this.type = 'exchange'
      } catch (e) {
        this.loadError = e?.message || this.$t('pages.returns.orderLoadFailed')
      } finally {
        this.loading = false
      }
    },
    async submit() {
      this.busy = true
      this.error = ''
      try {
        const items = {}
        const exchange = {}
        this.chosen.forEach(([id, qty]) => {
          items[id] = qty
          if (this.isExchange) exchange[id] = this.exchangeFor[id]
        })
        const data = await this.setRequest({
          api: 'createOrderReturn',
          params: {
            order_id: this.orderId,
            items,
            exchange: this.isExchange ? exchange : undefined,
            reason: this.reason,
            customer_note: this.customerNote,
            staff_note: this.staffNote,
            notify: this.notify,
            email_lang: this.emailLang
          }
        })
        // setRequest hands a refusal back as the response body (status 201)
        if (!data || data.status === 201) {
          this.error = data?.message || (data?.data?.form || [])[0] || (this.type === 'exchange' ? this.$t('pages.returns.exchangeNotOpened') : this.$t('pages.returns.returnNotOpened'))
          return
        }
        this.$router.push(`/returns/${data.id}`)
      } catch (e) {
        this.error = e?.message || this.$t('pages.returns.returnNotOpened')
      } finally {
        this.busy = false
      }
    },
    ...mapActions('common', ['getById', 'getRequest', 'setRequest'])
  },

  mounted() {
    this.load()
  },

  beforeDestroy() {
    clearTimeout(this.quoteTimer)
  }
}
</script>

<style scoped>
.rd-back { display: inline-block; margin-bottom: 12px; font-size: 13px; color: #4f6ef7; }
.rd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 12px; }
.rd-card h4 { font-size: 15px; font-weight: 600; margin: 0 0 12px; }
.rd-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.rd-title { margin: 0; font-size: 20px; }
.rd-sub { margin: 4px 0 0; color: #6b7280; font-size: 13px; }
.rd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.rd-grid .rd-card { margin-bottom: 0; }
.rd-table { width: 100%; font-size: 13px; border-collapse: collapse; }
.rd-table th, .rd-table td { text-align: left; padding: 10px 6px; border-bottom: 1px solid #f2f4f7; vertical-align: middle; }
.rd-type { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.rd-type-option { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid #d0d5dd; border-radius: 8px; font-size: 14px; cursor: pointer; }
.rd-type-option.active { border-color: #33319a; background: #f5f5ff; }
.rd-type-option input { width: 16px !important; height: 16px !important; margin: 0; }
.rd-gap-bottom { margin: 0 0 18px; }
.rd-gap-bottom + h4 { margin-top: 0; }
.rd-select-sm { height: 36px; font-size: 13px; min-width: 220px; }
.rd-qty { width: 80px; height: 36px; padding: 0 8px; border: 1px solid #d0d5dd; border-radius: 8px; }
.rd-label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; }
.rd-label:first-of-type { margin-top: 0; }
.rd-select { width: 100%; height: 40px; padding: 0 10px; border: 1px solid #d0d5dd; border-radius: 8px; background: #fff; font-size: 14px; }
.rd-card textarea { width: 100%; padding: 8px 10px; border: 1px solid #d0d5dd; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical; }
.rd-check { display: flex; gap: 8px; align-items: center; font-size: 13px; margin-top: 14px; cursor: pointer; }
.rd-check input { flex: 0 0 16px; width: 16px !important; height: 16px !important; margin: 0; }
.rd-dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; font-size: 14px; margin: 0; }
.rd-dl dt { color: #6b7280; }
.rd-dl dd { margin: 0; font-weight: 600; }
.rd-hint { font-size: 12px; color: #6b7280; margin: 10px 0 0; }
.rd-hint-inline { font-size: 12px; color: #6b7280; }
.rd-warning { margin: 0 0 12px; font-size: 13px; color: #b54708; background: #fffaeb; border: 1px solid #fedf89; border-radius: 8px; padding: 10px 14px; }
.rd-error { color: #b42318; }
.rd-error-line { font-size: 13px; color: #b42318; background: #fef3f2; border-radius: 6px; padding: 8px 10px; margin: 0 0 12px; }
.rd-actions { display: flex; justify-content: flex-end; }
.rd-actions .primary-btn { height: 44px; line-height: 42px; padding: 0 22px; width: auto; }
.pill { display: inline-block; margin-left: 6px; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.pill-wait { background: #fffaeb; color: #b54708; }
@media (max-width: 900px) { .rd-grid { grid-template-columns: 1fr; } }
</style>
