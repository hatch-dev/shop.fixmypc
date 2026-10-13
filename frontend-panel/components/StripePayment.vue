<template>
  <transition name="sp-fade" appear>
    <!--
      Two ways to show the same card form: inline, in the checkout page under
      the card row (the page's own confirm button pays); or as a dialog, for an
      order that already exists (the order page's "pay now").
    -->
    <div
      v-if="open"
      :class="inline ? 'sp-inline' : 'sp-overlay'"
      @mousedown.self="!inline && close()"
    >
      <div
        :class="inline ? 'sp-inline-body' : 'sp-sheet'"
        :role="inline ? null : 'dialog'"
        :aria-modal="inline ? null : 'true'"
        :aria-labelledby="inline ? null : 'sp-title'"
      >
        <header v-if="!inline" class="sp-head">
          <div class="sp-head-text">
            <p class="sp-eyebrow">
              <svg width="11" height="12" viewBox="0 0 11 12" aria-hidden="true">
                <path :d="lockPath" fill="currentColor"/>
              </svg>
              Secure checkout
            </p>
            <h2 id="sp-title" class="sp-title">Pay by card</h2>
            <p v-if="orderNumber" class="sp-order">Order #{{ orderNumber }}</p>
          </div>
          <button
            type="button"
            class="sp-close"
            aria-label="Close"
            :disabled="submitting"
            @click="close"
          >
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
              <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
          </button>
        </header>

        <div v-if="!inline" class="sp-amount">
          <span class="sp-amount-label">Amount to pay</span>
          <price-format class="sp-amount-value" :price="amount" :decimals="2"/>
        </div>

        <div class="sp-body">
          <div v-if="loading && !loadError" class="sp-skeleton" aria-hidden="true">
            <span class="sp-sk sp-sk-label"></span>
            <span class="sp-sk sp-sk-field"></span>
            <span class="sp-sk-row">
              <span class="sp-sk sp-sk-field"></span>
              <span class="sp-sk sp-sk-field"></span>
            </span>
          </div>

          <div v-if="loadError" class="sp-alert" role="alert">
            <span>{{ loadError }}</span>
            <button type="button" class="sp-link" @click="load">Try again</button>
          </div>

          <!--
            v-show, not v-if: Stripe mounts into this node, so it has to be in
            the DOM before the element is created.
          -->
          <div v-show="!loading && !loadError" ref="element" class="sp-element"></div>

          <div v-if="errorText" class="sp-alert" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>
              <path d="M12 7.5v5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              <circle cx="12" cy="16.4" r="1.15" fill="currentColor"/>
            </svg>
            <span>{{ errorText }}</span>
          </div>
        </div>

        <footer v-if="!inline" class="sp-foot">
          <button
            type="button"
            class="sp-pay"
            :disabled="submitting || loading || !!loadError"
            @click="pay"
          >
            <span v-if="submitting" class="sp-spinner" aria-hidden="true"></span>
            <svg v-else width="13" height="14" viewBox="0 0 11 12" aria-hidden="true">
              <path :d="lockPath" fill="currentColor"/>
            </svg>
            <span>{{ submitting ? 'Processing payment…' : payBtnText }}</span>
          </button>

          <p class="sp-note">
            Your card details are encrypted and processed by Stripe.
            We never see or store them.
          </p>

          <div class="sp-trust" aria-hidden="true">
            <span class="sp-powered">Powered by <b>stripe</b></span>
          </div>
        </footer>
        <p v-else class="sp-inline-note">
          <svg width="11" height="12" viewBox="0 0 11 12" aria-hidden="true">
            <path :d="lockPath" fill="currentColor"/>
          </svg>
          <span>Encrypted and processed by <b>Stripe</b>. We never see or store your card details.</span>
        </p>
      </div>
    </div>
  </transition>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import paymentHelper from '~/mixin/paymentHelper'
  import util from '~/mixin/util'
  import PriceFormat from './PriceFormat'

  const STRIPE_JS = 'https://js.stripe.com/v3/'

  // One script tag for the whole visit, however often the popup opens.
  function loadStripeJs() {
    if (window.Stripe) return Promise.resolve()
    if (!loadStripeJs.promise) {
      loadStripeJs.promise = new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = STRIPE_JS
        script.onload = () => resolve()
        script.onerror = () => {
          loadStripeJs.promise = null
          script.remove()
          reject(new Error('Stripe could not be loaded'))
        }
        document.head.appendChild(script)
      })
    }
    return loadStripeJs.promise
  }

  /*
   * Card payment through Stripe's Payment Element.
   *
   * The Element only collects the card: paymentMethodCreation 'manual' turns
   * it into a PaymentMethod (pm_...) and the server creates and confirms the
   * PaymentIntent for the order's own amount (OrdersController::attemptPayment).
   * If the bank wants 3-D Secure the server answers requires_action;
   * handleCardAction() runs the challenge and the intent goes back to be
   * confirmed. Apple Pay and Google Pay appear inside the Element on devices
   * that have them, once the domain is registered in Stripe.
   */
  export default {
    name: 'StripePayment',
    mixins: [util, paymentHelper],
    components: {PriceFormat},
    props: {
      orderId: {type: Number, default: null},
      orderNumber: {type: String, default: ''},
      stripeKey: {type: String, default: ''},
      currency: {type: String, default: 'EUR'},
      amount: {type: Number, default: 0},
      // PaymentGateways passes name/email; older callers userName/userEmail.
      name: {type: String, default: ''},
      email: {type: String, default: ''},
      userName: {type: String, default: ''},
      userEmail: {type: String, default: ''},
      siteName: {type: String, default: ''},
      /*
       * Checkout passes this: pay first. The card is authorised for the basket
       * and placeOrder(intentId) places the order, which takes the money.
       * Resolves {ok, orderId} or {ok: false, message}. Without it the popup
       * pays an order that already exists (orderId) straight away.
       */
      placeOrder: {type: Function, default: null},
      // In the page rather than a dialog; the parent calls pay().
      inline: {type: Boolean, default: false},
      // ISO code the card form's country list starts on.
      country: {type: String, default: 'IE'},
    },
    data() {
      return {
        open: true,
        loading: true,
        loadError: '',
        submitting: false,
        errorText: '',
        lockPath: 'M3.375 3.375V4.5H7.125V3.375C7.125 2.33906 6.28594 1.5 5.25 1.5C4.21406 1.5 3.375 2.33906 3.375 3.375V3.375M1.875 4.5V3.375C1.875 1.51172 3.38672 0 5.25 0C7.11328 0 8.625 1.51172 8.625 3.375V4.5H9C9.82734 4.5 10.5 5.17266 10.5 6V10.5C10.5 11.3273 9.82734 12 9 12H1.5C0.672656 12 0 11.3273 0 10.5V6C0 5.17266 0.672656 4.5 1.5 4.5H1.875V4.5',
      }
    },
    computed: {
      payBtnText() {
        return this.$t('stripePayment.pay', {amount: this.formattedPrice})
      },
      formattedPrice() {
        return this.priceFormat(this.currencyPosition, this.currencyIcon, this.amount, this.setting)
      },
      holderName() {
        return this.name || this.userName || this.$auth?.user?.name || ''
      },
      holderEmail() {
        return this.email || this.userEmail || this.$auth?.user?.email || ''
      },
      currencyCode() {
        const code = String(this.currency || '').trim().toLowerCase()
        return /^[a-z]{3}$/.test(code) ? code : 'eur'
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting'])
    },
    methods: {
      async load() {
        this.loading = true
        this.loadError = ''
        try {
          await loadStripeJs()
          this.mountElement()
        } catch (e) {
          this.loading = false
          this.loadError = 'The secure card form could not be loaded. Check your connection and try again.'
        }
      },
      mountElement() {
        this.stripe = window.Stripe(this.stripeKey)
        this.elements = this.stripe.elements({
          mode: 'payment',
          amount: Math.max(1, Math.round(this.amount * 100)),
          currency: this.currencyCode,
          paymentMethodCreation: 'manual',
          paymentMethodTypes: ['card'],
          locale: this.langCode || 'auto',
          fonts: [{cssSrc: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap'}],
          appearance: {
            theme: 'stripe',
            labels: 'above',
            variables: {
              colorPrimary: '#33319A',
              colorText: '#130E2B',
              colorTextSecondary: '#737987',
              colorTextPlaceholder: '#A3A8B5',
              colorDanger: '#C8321E',
              colorBackground: '#FFFFFF',
              fontFamily: 'Inter, system-ui, sans-serif',
              fontSizeBase: '15px',
              borderRadius: '10px',
              spacingUnit: '4px',
            },
            rules: {
              '.Input': {border: '1px solid #E0E0EA', boxShadow: 'none', padding: '12px 14px'},
              '.Input:focus': {borderColor: '#33319A', boxShadow: '0 0 0 3px rgba(51, 49, 154, .12)'},
              '.Label': {fontWeight: '500', fontSize: '13px', marginBottom: '6px'},
            }
          }
        })

        const element = this.elements.create('payment', {
          layout: 'tabs',
          // Link's own sign-up block (email, phone, name) doubled the form
          // for a customer who has already given us all three.
          wallets: {applePay: 'auto', googlePay: 'auto', link: 'never'},
          defaultValues: {billingDetails: {
            name: this.holderName,
            email: this.holderEmail,
            // Stripe guesses the country from the IP otherwise.
            address: {country: this.country}
          }},
        })
        this.paymentElement = element
        element.on('ready', () => {
          this.loading = false
          // In the page, focusing would scroll the shopper away from where they are.
          if (!this.inline) element.focus()
        })
        element.on('loaderror', (event) => {
          this.loading = false
          this.loadError = event?.error?.message || 'The secure card form could not be loaded.'
        })
        element.on('change', () => {
          if (this.errorText) this.errorText = ''
        })
        element.mount(this.$refs.element)
      },
      async pay() {
        if (this.submitting || !this.elements) return
        this.errorText = ''
        this.submitting = true
        // Nobody edits the card while it is being charged.
        this.paymentElement?.update({readOnly: true})
        try {
          // Validates the form and runs the wallet sheet when one was picked.
          const {error: submitError} = await this.elements.submit()
          if (submitError) {
            this.errorText = submitError.message
            return
          }

          const {error, paymentMethod} = await this.stripe.createPaymentMethod({
            elements: this.elements,
            params: {billing_details: {name: this.holderName || undefined, email: this.holderEmail || undefined}}
          })
          if (error) {
            this.errorText = error.message
            return
          }

          if (this.placeOrder) {
            return await this.authoriseAndPlace(paymentMethod.id)
          }

          const data = await this.paymentDoneFn(this.orderId, paymentMethod.id, this.orderMethods.STRIPE)
          if (!data?.data?.requires_action) return

          // paymentDoneFn cleared the flag; the bank's challenge is still to come.
          this.submitting = true
          const action = await this.stripe.handleCardAction(data.data.client_secret)
          if (action.error) {
            this.errorText = action.error.message
            return
          }

          await this.paymentDoneFn(this.orderId, action.paymentIntent.id, this.orderMethods.STRIPE)
        } catch (e) {
          this.errorText = e?.message || 'Payment could not be confirmed'
        } finally {
          this.submitting = false
          this.paymentElement?.update({readOnly: false})
        }
      },
      async authoriseAndPlace(paymentMethodId) {
        let auth = await this.authorise(paymentMethodId)

        if (auth.status === 'requires_action') {
          const action = await this.stripe.handleCardAction(auth.client_secret)
          if (action.error) {
            this.errorText = action.error.message
            this.release(auth.intent)
            return
          }
          auth = await this.authorise(action.paymentIntent.id)
        }

        if (auth.status !== 'authorised') {
          this.errorText = auth.message || 'Your card was not authorised. Please try another card.'
          if (auth.intent) this.release(auth.intent)
          return
        }

        const placed = await this.placeOrder(auth.intent)
        if (!placed?.ok) {
          // The server released the hold with its refusal; this is a backstop.
          this.errorText = placed?.message || 'Your order could not be placed. You have not been charged.'
          this.release(auth.intent)
          return
        }

        this.$emit('success', placed.orderId)
      },
      async api(path, body) {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const headers = {}
        const token = this.$auth?.loggedIn ? this.$auth.strategy.token.get() : null
        if (token) headers.Authorization = token

        const {data} = await this.$axios.post(baseUrl + 'api/v1/order/' + path, {
          ...body,
          user_token: this.$auth?.loggedIn ? null : await this.getUserToken()
        }, {headers})
        return data
      },
      // {status: authorised | requires_action | failed, intent, client_secret, message}
      async authorise(paymentToken) {
        try {
          const res = await this.api('stripe-authorise', {payment_token: paymentToken, amount: this.amount})
          if (res?.status === 200) return res.data
          return {status: 'failed', message: res?.message}
        } catch (e) {
          return {status: 'failed', message: 'The card payment could not be started. Please try again.'}
        }
      },
      release(intent) {
        this.api('stripe-cancel', {payment_intent: intent}).catch(() => {})
      },
      close() {
        // Not mid-payment: the card may already be charging.
        if (this.submitting || this.inline) return
        this.open = false
        this.$emit('closed', this.orderId)
      },
      onKeydown(e) {
        if (e.key === 'Escape') this.close()
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('order', ['paymentDone']),
    },
    watch: {
      // A voucher or the wallet tick changes the total while the form is open.
      amount(value) {
        this.elements?.update({amount: Math.max(1, Math.round(value * 100))})
      }
    },
    created() {
      // Stripe's own objects: kept off data so Vue does not try to observe them.
      this.stripe = null
      this.elements = null
      this.paymentElement = null
    },
    mounted() {
      if (!this.inline) {
        document.addEventListener('keydown', this.onKeydown)
        document.documentElement.classList.add('sp-lock-scroll')
      }
      this.load()
    },
    beforeDestroy() {
      document.removeEventListener('keydown', this.onKeydown)
      document.documentElement.classList.remove('sp-lock-scroll')
    }
  }
</script>

<style>
  html.sp-lock-scroll, html.sp-lock-scroll body {
    overflow: hidden;
  }
</style>

<style scoped>
  .sp-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    background: rgba(19, 14, 43, .55);
    backdrop-filter: blur(2px);
  }

  .sp-sheet {
    width: 100%;
    max-width: 460px;
    max-height: calc(100vh - 48px);
    overflow-y: auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(19, 14, 43, .28);
    font-family: "Inter", sans-serif;
    color: #130E2B;
  }

  /* HEAD */
  .sp-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 24px 16px;
  }
  .sp-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 6px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .02em;
    color: #05924A;
  }
  .sp-title {
    margin: 0;
    font-size: 20px;
    line-height: 1.25em;
    font-weight: 600;
    color: #130E2B;
  }
  .sp-order {
    margin: 4px 0 0;
    font-size: 13px;
    color: #737987;
  }
  .sp-head .sp-close {
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    line-height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 50%;
    background: #F2F2F8;
    color: #4B5060;
    box-shadow: none;
    transition: background .15s ease;
  }
  .sp-head .sp-close:hover { background: #E6E6F0; }
  .sp-head .sp-close:focus-visible { box-shadow: 0 0 0 3px rgba(51, 49, 154, .25); }
  .sp-head .sp-close:disabled { opacity: .5; cursor: not-allowed; }

  /* AMOUNT */
  .sp-amount {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin: 0 24px;
    padding: 14px 16px;
    border-radius: 12px;
    background: #F5F6FB;
    border: 1px solid #EDEDF3;
  }
  .sp-amount-label {
    font-size: 13px;
    color: #737987;
  }
  .sp-amount-value {
    font-size: 22px;
    font-weight: 600;
    color: #130E2B;
  }

  /* BODY */
  .sp-body {
    padding: 20px 24px 4px;
  }
  .sp-element {
    min-height: 120px;
  }
  .sp-skeleton {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .sp-sk-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .sp-sk {
    display: block;
    border-radius: 10px;
    background: linear-gradient(90deg, #F1F1F6 25%, #E7E7EF 50%, #F1F1F6 75%);
    background-size: 200% 100%;
    animation: sp-shimmer 1.2s ease-in-out infinite;
  }
  .sp-sk-label { width: 34%; height: 12px; }
  .sp-sk-field { height: 46px; }
  @keyframes sp-shimmer {
    from { background-position: 200% 0; }
    to { background-position: -200% 0; }
  }

  .sp-alert {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 14px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #FDF1EF;
    border: 1px solid #F6D3CD;
    color: #9F2A17;
    font-size: 13.5px;
    line-height: 1.45em;
  }
  .sp-alert svg { flex: 0 0 16px; margin-top: 1px; }
  .sp-alert .sp-link {
    height: auto;
    line-height: inherit;
    padding: 0;
    margin-left: auto;
    border: 0;
    background: none;
    box-shadow: none;
    color: #33319A;
    font-weight: 600;
    text-decoration: underline;
  }

  /* FOOT */
  .sp-foot {
    padding: 18px 24px 22px;
  }
  .sp-foot .sp-pay {
    width: 100%;
    height: 52px;
    line-height: 52px;
    padding: 0 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border: 0;
    border-radius: 12px;
    background: #33319A;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    box-shadow: 0 6px 16px rgba(51, 49, 154, .25);
    transition: background .15s ease, transform .1s ease, box-shadow .15s ease;
  }
  .sp-foot .sp-pay:hover:not(:disabled) { background: #28267F; }
  .sp-foot .sp-pay:active:not(:disabled) { transform: translateY(1px); box-shadow: 0 3px 10px rgba(51, 49, 154, .25); }
  .sp-foot .sp-pay:focus-visible { box-shadow: 0 0 0 3px rgba(51, 49, 154, .3); }
  .sp-foot .sp-pay:disabled { background: #9C9BCB; box-shadow: none; cursor: not-allowed; }

  .sp-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, .4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: sp-spin .7s linear infinite;
  }
  @keyframes sp-spin { to { transform: rotate(360deg); } }

  .sp-note {
    margin: 14px 0 0;
    text-align: center;
    font-size: 12.5px;
    line-height: 1.5em;
    color: #737987;
  }
  .sp-trust {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 12px;
  }
  .sp-powered {
    font-size: 11.5px;
    color: #9096A3;
  }
  .sp-powered b {
    font-weight: 700;
    color: #635BFF;
    letter-spacing: -.01em;
  }

  /* Phone: a bottom sheet the thumb can reach. */
  @media (max-width: 575px) {
    .sp-overlay {
      align-items: flex-end;
      padding: 0;
    }
    .sp-sheet {
      max-width: none;
      max-height: 92vh;
      border-radius: 18px 18px 0 0;
    }
    .sp-head { padding: 18px 16px 14px; }
    .sp-amount { margin: 0 16px; }
    .sp-body { padding: 16px 16px 4px; }
    .sp-foot { padding: 16px 16px calc(18px + env(safe-area-inset-bottom)); }
  }

  /* INLINE: under the card row in the checkout page */
  .sp-inline-body {
    font-family: "Inter", sans-serif;
    color: #130E2B;
  }
  .sp-inline .sp-body {
    padding: 0;
  }
  .sp-inline .sp-element {
    min-height: 0;
  }
  .sp-inline .sp-alert:first-child {
    margin-top: 0;
  }
  .sp-inline-note {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    margin: 14px 0 0;
    font-size: 12.5px;
    line-height: 1.45em;
    color: #737987;
  }
  .sp-inline-note svg {
    flex: 0 0 11px;
    margin-top: 3px;
    color: #05924A;
  }
  .sp-inline-note b {
    color: #635BFF;
    font-weight: 700;
  }

  .sp-fade-enter-active, .sp-fade-leave-active { transition: opacity .2s ease; }
  .sp-fade-enter-active .sp-sheet, .sp-fade-leave-active .sp-sheet { transition: transform .2s ease; }
  .sp-fade-enter, .sp-fade-leave-to { opacity: 0; }
  .sp-fade-enter .sp-sheet, .sp-fade-leave-to .sp-sheet { transform: translateY(12px); }
</style>
