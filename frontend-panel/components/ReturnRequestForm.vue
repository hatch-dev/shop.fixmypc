<template>
  <div class="rr-form">
    <div class="rr-head">
      <h3 class="rr-title">{{ $t('returnForm.title') }}</h3>
      <button type="button" class="rr-close" :aria-label="$t('returnForm.close')" @click="$emit('close')">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>

    <p v-if="loading" class="rr-note">{{ $t('returnForm.loading') }}</p>
    <p v-else-if="loadError" class="rr-error" role="alert">{{ loadError }}</p>

    <template v-else-if="options">
      <p v-if="!options.can_request" class="rr-error" role="alert">{{ options.reason }}</p>

      <template v-else>
        <p v-if="options.days_left !== null" class="rr-note">
          {{ $t('returnForm.window', { days: options.days_left }) }}
        </p>

        <div class="rr-type" role="radiogroup" :aria-label="$t('returnForm.whatToDo')">
          <label class="rr-type-option" :class="{ active: type === 'return' }">
            <input type="radio" value="return" v-model="type">
            <span>
              <strong>{{ $t('returnForm.refund') }}</strong>
              <small>{{ $t('returnForm.refundHint') }}</small>
            </span>
          </label>
          <label v-if="canExchange" class="rr-type-option" :class="{ active: type === 'exchange' }">
            <input type="radio" value="exchange" v-model="type">
            <span>
              <strong>{{ $t('returnForm.exchange') }}</strong>
              <small>{{ $t('returnForm.exchangeHint') }}</small>
            </span>
          </label>
        </div>

        <ul class="rr-lines">
          <li v-for="line in options.lines" :key="line.ordered_product_id" class="rr-line">
            <div class="rr-line-main">
              <span class="rr-line-title">{{ line.title }}</span>
              <span v-if="line.variant" class="rr-line-variant">{{ line.variant }}</span>
              <span v-if="line.returnable_quantity === 0" class="rr-line-variant">{{ $t('returnForm.noneLeft') }}</span>
            </div>
            <div v-if="line.returnable_quantity > 0" class="rr-qty" role="group" :aria-label="$t('returnForm.howMany', { item: line.title })">
              <button type="button" :disabled="qty(line) === 0" :aria-label="$t('returnForm.less')" @click="setQty(line, qty(line) - 1)">&minus;</button>
              <span aria-live="polite">{{ qty(line) }}</span>
              <button type="button" :disabled="qty(line) >= line.returnable_quantity" :aria-label="$t('returnForm.more')" @click="setQty(line, qty(line) + 1)">+</button>
            </div>

            <div v-if="type === 'exchange' && qty(line) > 0" class="rr-swap">
              <label :for="`rr-swap-${line.ordered_product_id}`">{{ $t('returnForm.swapFor') }}</label>
              <select :id="`rr-swap-${line.ordered_product_id}`" v-model="exchangeFor[line.ordered_product_id]">
                <option value="">{{ $t('returnForm.chooseOption') }}</option>
                <option v-for="v in line.variants" :key="v.inventory_id" :value="v.inventory_id">
                  {{ v.label }} · {{ priceNote(line, v) }}
                </option>
              </select>
              <p v-if="!line.variants.length" class="rr-note">{{ $t('returnForm.noOptions') }}</p>
            </div>
          </li>
        </ul>

        <p v-if="type === 'exchange' && exchangeDifference > 0" class="rr-note rr-callout">
          {{ $t('returnForm.payMore', { amount: money(exchangeDifference) }) }}
        </p>
        <p v-if="type === 'exchange' && exchangeDifference < 0" class="rr-note rr-callout">
          {{ $t('returnForm.getBack', { amount: money(-exchangeDifference) }) }}
        </p>

        <label class="rr-label" for="rr-reason">{{ $t('returnForm.reason') }}</label>
        <select id="rr-reason" v-model="reason">
          <option value="">{{ $t('returnForm.chooseReason') }}</option>
          <option v-for="(label, key) in options.reasons" :key="key" :value="key">{{ label }}</option>
        </select>

        <label class="rr-label" for="rr-note">{{ $t('returnForm.note') }}</label>
        <textarea id="rr-note" v-model="note" rows="3" maxlength="2000" :placeholder="$t('returnForm.notePlaceholder')"></textarea>

        <p v-if="error" class="rr-error" role="alert">{{ error }}</p>

        <button type="button" class="rr-submit" :disabled="!canSubmit || busy" @click="submit">
          {{ busy ? $t('returnForm.sending') : (type === 'exchange' ? $t('returnForm.sendExchange') : $t('returnForm.sendReturn')) }}
        </button>
        <p class="rr-note rr-small">{{ $t('returnForm.afterSend') }}</p>
      </template>
    </template>
  </div>
</template>

<script>
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

/**
 * The customer's return / exchange request, on their order page.
 *
 * Everything that matters is decided by the server (CustomerReturnsController):
 * which units are left, the window, which variants can be had and their price.
 * The figures shown here are a preview from those same numbers.
 */
export default {
  name: 'ReturnRequestForm',
  mixins: [util],
  props: {
    orderId: { type: [Number, String], required: true }
  },

  data() {
    return {
      options: null,
      loading: true,
      loadError: '',
      type: 'return',
      quantities: {},
      exchangeFor: {},
      reason: '',
      note: '',
      busy: false,
      error: ''
    }
  },

  computed: {
    canExchange() {
      return (this.options?.lines || []).some(l => l.returnable_quantity > 0 && l.variants.length)
    },
    chosen() {
      return (this.options?.lines || []).filter(l => this.qty(l) > 0)
    },
    exchangeDifference() {
      let cents = 0
      this.chosen.forEach(line => {
        const v = line.variants.find(x => String(x.inventory_id) === String(this.exchangeFor[line.ordered_product_id]))
        if (v) cents += Math.round((Number(v.unit_price) - Number(line.unit_price)) * 100) * this.qty(line)
      })
      return cents / 100
    },
    canSubmit() {
      if (!this.chosen.length || !this.reason) return false
      if (this.type === 'exchange') return this.chosen.every(l => !!this.exchangeFor[l.ordered_product_id])
      return true
    },
    ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
    ...mapGetters('language', ['langCode'])
  },

  methods: {
    money(value) {
      return this.priceFormat(this.currencyPosition, this.currencyIcon, Number(value || 0).toFixed(2), this.setting, 2)
    },
    qty(line) {
      return Number(this.quantities[line.ordered_product_id] || 0)
    },
    setQty(line, value) {
      this.$set(this.quantities, line.ordered_product_id, Math.max(0, Math.min(value, line.returnable_quantity)))
    },
    priceNote(line, variant) {
      const diff = Math.round((Number(variant.unit_price) - Number(line.unit_price)) * 100) / 100
      if (diff > 0) return this.$t('returnForm.plus', { amount: this.money(diff) })
      if (diff < 0) return this.$t('returnForm.minus', { amount: this.money(-diff) })
      return this.$t('returnForm.samePrice')
    },
    async ownership() {
      // The signed-in customer is known from the bearer; a guest proves it with the order's token.
      return this.$auth?.loggedIn ? {} : { user_token: await this.getUserToken() }
    },
    async load() {
      this.loading = true
      try {
        const data = await this.postRequest({
          api: 'returnOptions',
          params: { order_id: this.orderId, ...(await this.ownership()) },
          requiredToken: !!this.$auth?.loggedIn,
          lang: this.langCode
        })
        if (data?.status !== 200) {
          this.loadError = data?.message || this.$t('returnForm.loadFailed')
          return
        }
        this.options = data.data
        const exchangeFor = {}
        this.options.lines.forEach(l => { exchangeFor[l.ordered_product_id] = '' })
        this.exchangeFor = exchangeFor
        // Only one unit to choose from: choose it.
        const open = this.options.lines.filter(l => l.returnable_quantity > 0)
        if (open.length === 1 && open[0].returnable_quantity === 1) this.setQty(open[0], 1)
      } catch (e) {
        this.loadError = this.$t('returnForm.loadFailed')
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
        this.chosen.forEach(line => {
          items[line.ordered_product_id] = this.qty(line)
          if (this.type === 'exchange') exchange[line.ordered_product_id] = this.exchangeFor[line.ordered_product_id]
        })

        const data = await this.postRequest({
          api: 'returnRequest',
          params: {
            order_id: this.orderId, type: this.type, items, exchange,
            reason: this.reason, note: this.note, ...(await this.ownership())
          },
          requiredToken: !!this.$auth?.loggedIn,
          lang: this.langCode
        })

        if (data?.status !== 200) {
          this.error = data?.message || (data?.data?.form || [])[0] || this.$t('returnForm.sendFailed')
          return
        }

        this.setToastMessage(data.message || this.$t('returnForm.sent'))
        this.$emit('submitted', data.data)
      } catch (e) {
        this.error = this.$t('returnForm.sendFailed')
      } finally {
        this.busy = false
      }
    },
    ...mapActions('common', ['postRequest', 'setToastMessage']),
    ...mapActions('user', ['getUserToken'])
  },

  mounted() {
    this.load()
  }
}
</script>

<style scoped>
.rr-form { color: #130E2B; }
.rr-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.rr-title { margin: 0; font-size: 18px; font-weight: 600; color: #232159; }
.rr-close { background: transparent !important; border: 0; width: 32px; height: 32px; line-height: 32px; padding: 0; color: #6B6B80; cursor: pointer; }
.rr-note { margin: 0 0 12px; font-size: 14px; color: #4A4A5E; line-height: 1.5; }
.rr-small { font-size: 12px; margin-top: 10px; }
.rr-callout { background: #F5F5FF; border-radius: 8px; padding: 10px 12px; }
.rr-error { margin: 0 0 12px; font-size: 14px; color: #B91C1C; background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 10px 12px; }
.rr-type { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 14px; }
.rr-type-option { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border: 1px solid #D7DAE9; border-radius: 10px; cursor: pointer; }
.rr-type-option.active { border-color: #33319A; background: #F5F5FF; }
.rr-type-option input { width: 16px; height: 16px; margin-top: 3px; flex: 0 0 16px; }
.rr-type-option strong { display: block; font-size: 15px; }
.rr-type-option small { display: block; font-size: 12px; color: #6B6B80; margin-top: 2px; }
.rr-lines { list-style: none; margin: 0 0 14px; padding: 0; border-top: 1px solid #EEEEF5; }
.rr-line { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 12px 0; border-bottom: 1px solid #EEEEF5; }
.rr-line-main { flex: 1 1 220px; min-width: 0; }
.rr-line-title { display: block; font-weight: 600; font-size: 15px; overflow-wrap: anywhere; }
.rr-line-variant { display: block; font-size: 13px; color: #6B6B80; }
.rr-qty { display: inline-flex; align-items: center; border: 1px solid #D7DAE9; border-radius: 8px; overflow: hidden; }
.rr-qty button { width: 38px; height: 38px; line-height: 36px; padding: 0; border: 0; background: #fff !important; color: #130E2B !important; font-size: 18px; cursor: pointer; }
.rr-qty button:disabled { color: #C4C4D4 !important; cursor: not-allowed; }
.rr-qty span { min-width: 34px; text-align: center; font-weight: 600; }
.rr-swap { flex: 1 1 100%; }
.rr-swap label { display: block; font-size: 13px; color: #4A4A5E; margin-bottom: 4px; }
.rr-label { display: block; font-size: 14px; font-weight: 600; margin: 12px 0 6px; }
.rr-form select, .rr-form textarea {
  width: 100%; border: 1px solid #D7DAE9; border-radius: 8px; padding: 10px 12px; font-size: 14px; font-family: inherit; background: #fff; color: #130E2B;
}
.rr-form select { height: 44px; padding: 0 12px; }
.rr-submit {
  display: block; width: 100%; height: 48px; line-height: 46px; margin-top: 16px; border-radius: 10px;
  border: 1px solid #33319A; background: #33319A; color: #fff; font-size: 16px; font-weight: 600; cursor: pointer;
}
.rr-submit:hover:not(:disabled) { background: #28267d; }
.rr-submit:disabled { opacity: .55; cursor: not-allowed; }
</style>
