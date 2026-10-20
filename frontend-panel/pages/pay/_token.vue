<template>
  <div class="pay-page container">
    <div class="pay-card">

      <div v-if="loading" class="pay-state">
        <span class="pay-spinner" aria-hidden="true"></span>
        <p>{{ $t('payLink.loading') }}</p>
      </div>

      <div v-else-if="loadError" class="pay-state pay-state-bad" role="alert">
        <h1 class="pay-title">{{ $t('payLink.unavailable') }}</h1>
        <p>{{ loadError }}</p>
      </div>

      <template v-else-if="link">
        <p class="pay-eyebrow">{{ $t('payLink.eyebrow') }}</p>
        <h1 class="pay-title">
          {{ link.status === 'paid' ? $t('payLink.paidTitle') : $t('payLink.title') }}
        </h1>

        <dl class="pay-facts">
          <div>
            <dt>{{ $t('payLink.returnNumber') }}</dt>
            <dd class="pay-mono">{{ link.rma_number }}</dd>
          </div>
          <div>
            <dt>{{ $t('payLink.order') }}</dt>
            <dd class="pay-mono">#{{ link.order_number }}</dd>
          </div>
          <div class="pay-items" v-if="link.items && link.items.length">
            <dt>{{ $t('payLink.swap') }}</dt>
            <dd>
              <span v-for="item in link.items" :key="item.ordered_product_id" class="pay-item">
                {{ item.quantity }} &times; {{ item.title }}<template v-if="item.exchange_label"> &rarr; {{ item.exchange_label }}</template>
              </span>
            </dd>
          </div>
        </dl>

        <div class="pay-amount">
          <span>{{ link.status === 'paid' ? $t('payLink.paidAmount') : $t('payLink.amountDue') }}</span>
          <strong>{{ money(link.amount) }}</strong>
        </div>

        <!-- Paid -->
        <div v-if="link.status === 'paid'" class="pay-done" role="status">
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          <div>
            <p>{{ $t('payLink.thanks') }}</p>
            <p v-if="link.replacement_order" class="pay-sub">{{ $t('payLink.replacement', { order: link.replacement_order }) }}</p>
          </div>
        </div>

        <!-- Not payable any more -->
        <p v-else-if="link.status !== 'pending'" class="pay-message pay-message-bad" role="alert">
          {{ link.status === 'expired' ? $t('payLink.expired') : $t('payLink.withdrawn') }}
        </p>

        <!-- Pay -->
        <template v-else>
          <p class="pay-sub">{{ $t('payLink.explain') }}</p>

          <button v-if="!started" type="button" class="pay-btn" :disabled="starting" @click="start">
            {{ starting ? $t('payLink.starting') : $t('payLink.payButton', { amount: money(link.amount) }) }}
          </button>

          <!-- SumUp mounts its own form, button included, into this node by id. -->
          <div v-show="started && link.gateway === 'sumup'" id="pay-sumup-card" class="pay-widget"></div>

          <template v-if="started && link.gateway === 'stripe'">
            <div ref="stripeElement" class="pay-widget pay-stripe"></div>
            <button type="button" class="pay-btn" :disabled="paying || !stripeReady" @click="payStripe">
              {{ paying ? $t('payLink.processing') : $t('payLink.payButton', { amount: money(link.amount) }) }}
            </button>
          </template>

          <p v-if="confirming" class="pay-message" role="status">{{ $t('payLink.confirming') }}</p>
          <p v-if="payError" class="pay-message pay-message-bad" role="alert">{{ payError }}</p>

          <p class="pay-secure">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            {{ link.gateway === 'stripe' ? $t('payLink.secureStripe') : $t('payLink.secureSumup') }}
          </p>
        </template>
      </template>
    </div>
  </div>
</template>

<script>
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'

const STRIPE_JS = 'https://js.stripe.com/v3/'

function loadStripeJs() {
  if (typeof window === 'undefined') return Promise.reject(new Error('no window'))
  if (window.Stripe) return Promise.resolve()
  if (!loadStripeJs.promise) {
    loadStripeJs.promise = new Promise((resolve, reject) => {
      const s = document.createElement('script')
      s.src = STRIPE_JS
      s.onload = resolve
      s.onerror = () => { loadStripeJs.promise = null; reject(new Error('Stripe.js failed to load')) }
      document.head.appendChild(s)
    })
  }
  return loadStripeJs.promise
}

/**
 * Pay the difference on an exchange, from the link in the email
 * (App\Models\Helper\PayLinks). No sign-in: the link is the credential.
 *
 * The amount comes from the server and the checkout is created there for it;
 * this page only hosts the gateway's own card form and then asks the server
 * to check with the gateway. Nothing here is trusted as proof of payment.
 */
export default {
  name: 'pay-link',
  middleware: ['common-middleware'],
  mixins: [util],

  data() {
    return {
      link: null,
      loading: true,
      loadError: '',
      started: false,
      starting: false,
      paying: false,
      confirming: false,
      payError: '',
      stripe: null,
      elements: null,
      stripeReady: false,
      sdkTimer: null
    }
  },

  head() {
    return {
      title: this.$t('payLink.title'),
      meta: [{ hid: 'robots', name: 'robots', content: 'noindex, nofollow' }]
    }
  },

  computed: {
    token() {
      return this.$route.params.token
    },
    ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
    ...mapGetters('language', ['langCode'])
  },

  methods: {
    money(value) {
      return this.priceFormat(this.currencyPosition, this.currencyIcon, Number(value || 0).toFixed(2), this.setting, 2)
    },
    // The API answers a refusal in the body with status 201 and a message.
    messageOf(data, fallback) {
      return data?.message || (data?.data?.form || [])[0] || fallback
    },
    async load() {
      this.loading = true
      try {
        const data = await this.getRequest({ api: 'payLinkView', params: { link: this.token }, lang: this.langCode })
        if (data?.status !== 200) {
          this.loadError = this.messageOf(data, this.$t('payLink.notFound'))
          return
        }
        this.link = data.data
      } catch (e) {
        this.loadError = this.$t('payLink.notFound')
      } finally {
        this.loading = false
      }
    },
    async start() {
      this.starting = true
      this.payError = ''
      try {
        const data = await this.postRequest({ api: 'payLinkStart', params: { link: this.token }, lang: this.langCode })
        if (data?.status !== 200) {
          this.payError = this.messageOf(data, this.$t('payLink.startFailed'))
          return
        }
        this.started = true
        await this.$nextTick()
        if (this.link.gateway === 'stripe') {
          await this.mountStripe(data.data.client_secret)
        } else {
          await this.mountSumup(data.data.checkout_id)
        }
      } catch (e) {
        this.payError = this.$t('payLink.startFailed')
        this.started = false
      } finally {
        this.starting = false
      }
    },
    waitForSumup(timeout = 15000) {
      return new Promise((resolve, reject) => {
        const startedAt = Date.now()
        const tick = () => {
          if (window.SumUpCard) return resolve()
          if (Date.now() - startedAt > timeout) return reject(new Error('SumUp SDK not available'))
          this.sdkTimer = setTimeout(tick, 150)
        }
        tick()
      })
    },
    async mountSumup(checkoutId) {
      await this.waitForSumup()
      window.SumUpCard.mount({
        id: 'pay-sumup-card',
        checkoutId,
        amount: String(this.link.amount),
        currency: this.link.currency,
        onResponse: (type, body) => {
          if (type === 'invalid') {
            this.payError = this.$t('payLink.checkCard')
            return false
          }
          if (type === 'error') {
            this.payError = body?.message || this.$t('payLink.declined')
            return false
          }
          if (type === 'success' && !this.confirming) {
            this.payError = ''
            this.confirm()
          }
        }
      })
    },
    async mountStripe(clientSecret) {
      await loadStripeJs()
      this.stripe = window.Stripe(this.link.stripe_key)
      this.elements = this.stripe.elements({ clientSecret })
      const element = this.elements.create('payment', { layout: 'tabs' })
      element.on('ready', () => { this.stripeReady = true })
      element.mount(this.$refs.stripeElement)
    },
    async payStripe() {
      if (this.paying) return
      this.paying = true
      this.payError = ''
      try {
        const { error } = await this.stripe.confirmPayment({
          elements: this.elements,
          redirect: 'if_required',
          confirmParams: { return_url: window.location.href }
        })
        if (error) {
          this.payError = error.message || this.$t('payLink.declined')
          return
        }
        await this.confirm()
      } finally {
        this.paying = false
      }
    },
    /*
     * Ask the server, which asks the gateway. A card can clear a moment before
     * the gateway reports it, so a "not yet" is retried a few times.
     */
    async confirm() {
      this.confirming = true
      try {
        for (let attempt = 0; attempt < 4; attempt++) {
          const data = await this.postRequest({ api: 'payLinkConfirm', params: { link: this.token }, lang: this.langCode })
          if (data?.status === 200) {
            this.link = data.data
            return
          }
          if (attempt === 3) {
            this.payError = this.messageOf(data, this.$t('payLink.notConfirmed'))
            return
          }
          await new Promise(resolve => setTimeout(resolve, 2500))
        }
      } catch (e) {
        this.payError = this.$t('payLink.notConfirmed')
      } finally {
        this.confirming = false
      }
    },
    ...mapActions('common', ['getRequest', 'postRequest'])
  },

  mounted() {
    this.load()
  },

  beforeDestroy() {
    clearTimeout(this.sdkTimer)
  }
}
</script>

<style scoped>
.pay-page { display: flex; justify-content: center; padding: 40px 16px 64px; }
.pay-card {
  width: 100%; max-width: 560px; background: #fff; border: 1px solid #E3E3EF; border-radius: 20px;
  padding: 28px 28px 24px; color: #130E2B;
}
.pay-eyebrow { margin: 0 0 4px; font-size: 13px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #33319A; }
.pay-title { margin: 0 0 18px; font-size: 24px; line-height: 1.3; font-weight: 700; color: #232159; }
.pay-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; margin: 0 0 18px; }
.pay-facts dt { font-size: 12px; color: #6B6B80; margin-bottom: 2px; }
.pay-facts dd { margin: 0; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
.pay-items { grid-column: 1 / -1; }
.pay-item { display: block; font-weight: 500; }
.pay-mono { font-variant-numeric: tabular-nums; letter-spacing: .02em; }
.pay-amount {
  display: flex; justify-content: space-between; align-items: baseline; gap: 12px;
  padding: 14px 16px; border-radius: 12px; background: #F5F5FF; margin-bottom: 18px;
}
.pay-amount span { font-size: 14px; color: #4A4A5E; }
.pay-amount strong { font-size: 24px; color: #232159; }
.pay-sub { margin: 0 0 16px; font-size: 14px; color: #4A4A5E; line-height: 1.5; }
.pay-btn {
  display: block; width: 100%; height: 48px; line-height: 46px; border-radius: 10px; border: 1px solid #33319A;
  background: #33319A; color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 12px;
}
.pay-btn:hover:not(:disabled) { background: #28267d; }
.pay-btn:disabled { opacity: .6; cursor: not-allowed; }
.pay-widget { margin-top: 8px; }
.pay-stripe { padding: 4px 0; }
.pay-message { margin: 14px 0 0; font-size: 14px; color: #4A4A5E; }
.pay-message-bad { color: #B91C1C; background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 10px 12px; }
.pay-secure { margin: 16px 0 0; font-size: 12px; color: #6B6B80; display: flex; gap: 6px; align-items: center; }
.pay-done { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border-radius: 12px; background: #ECFDF5; color: #047857; }
.pay-done i { font-size: 22px; margin-top: 2px; }
.pay-done p { margin: 0; font-weight: 600; }
.pay-done .pay-sub { margin-top: 4px; font-weight: 400; color: #065F46; }
.pay-state { text-align: center; padding: 24px 0; }
.pay-state-bad p { color: #B91C1C; }
.pay-spinner {
  display: inline-block; width: 28px; height: 28px; border-radius: 50%;
  border: 3px solid #E3E3EF; border-top-color: #33319A; animation: pay-spin .8s linear infinite;
}
@keyframes pay-spin { to { transform: rotate(360deg); } }
@media (max-width: 575px) {
  .pay-card { padding: 20px 16px; border-radius: 16px; }
  .pay-facts { grid-template-columns: 1fr; }
}
</style>
