<template>
  <form
    @submit.prevent="updatePayment"
    class="payment-form"
    :class="{'has-error': hasError}"
  >
    <div
      v-if="gettingData"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>


    <div class="input-wrapper">
      <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{ $t('admin.default') }}
          </span>

        <dropdown
          :selectedKey="paymentData.default"
          :options="defaultMethodDrop"
          @clicked="paymentTypesSelect"
        />
      </div>

      <!--
        Checkout shows one "Credit / Debit Card" option; this picks who is
        behind it. Only the gateways ticked below are offered; the other card
        gateway is refused at checkout.
      -->
      <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{ $t('cmp.PaymentSetting.cardHandledBy') }}
          </span>

        <dropdown
          v-if="cardGatewayKey"
          :key="Object.keys(cardGatewayDrop).join()"
          :selectedKey="cardGatewayKey"
          :options="cardGatewayDrop"
          @clicked="cardGatewaySelect"
        />
        <span
          v-else
          class="card-gateway-none"
        >
          {{ $t('cmp.PaymentSetting.noCardPayments') }}
        </span>
      </div>
    </div>

    <div class="input-wrapper dply-felx start">
      <label
        for="cod"
        class="mb-0">
        <input
          type="checkbox"
          id="cod"
          v-model="paymentData.cash_on_delivery"
        />

        {{ $t('fSale.cod') }}
      </label>
    </div>

    <sumup
      :payment-data="paymentData"
    />

    <stripe
      :payment-data="paymentData"
    />

    <bank-payment
      :payment-data="paymentData"
    />

    <!--
      Gateways this store does not use. Checkout has no row for any of them,
      so a ticked box here did nothing; a disabled fieldset turns off every
      checkbox and toggle inside it. The server forces them off on save too
      (PaymentsController::save).
    -->
    <fieldset
      disabled
      class="gateway-off"
      aria-describedby="gateway-off-note"
    >
      <p id="gateway-off-note" class="gateway-off-note">
        {{ $t('cmp.PaymentSetting.notAvailable') }}
      </p>

      <paypal
        :payment-data="paymentData"
      />

      <razorpay
        :payment-data="paymentData"
      />

      <flutterwave
        :payment-data="paymentData"
      />

      <iyzico-payment
        :payment-data="paymentData"
      />

      <pay-fast
        :payment-data="paymentData"
      />
    </fieldset>

    <div class="oflow-hidden">
      <ajax-button
        v-if="$can('setting', 'edit')"
        class="primary-btn"
        :fetching-data="updatingPayment"
      />
    </div>
  </form>
</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import util from "~/mixin/util"
  import Spinner from '~/components/Spinner'
  import Paypal from "~/components/partials/Paypal";
  import Stripe from "~/components/partials/Stripe";
  import Razorpay from "~/components/partials/Razorpay";
  import Flutterwave from "./Flutterwave";
  import IyzicoPayment from "./IyzicoPayment";
  import BankPayment from "./BankPayment";
  import Dropdown from "../Dropdown";
  import PayFast from "./PayFast";
  import Sumup from "./Sumup";

  export default {
    name: 'PaymentSetting',
    data() {
      return {
        gettingData: false,
        updatingPayment: false,
        hasError: false,
        paymentData:{
          id: null,
          default: 1,
          paypal: true,
          paypal_key: null,
          paypal_secret: null,
          cash_on_delivery: true,
          stripe: true,
          razorpay: true,
          razorpay_key: null,
          razorpay_secret: null,
          stripe_key: null,
          stripe_secret: null,
          stripe_webhook_secret: null,

          flutterwave: true,
          fw_public_key: null,
          fw_secret_key: null,
          fw_encryption_key: null,
          fw_environment: null,

          iyzico_payment: true,
          ip_base_url: null,
          ip_api_key: null,
          ip_secret_key: null,

          bank: true,
          bank_name: null,
          branch_name: null,
          account_name: null,
          account_number: null,
          iban: null,
          bic_swift: null,

          payfast_payment: true,
          payfast_base_url: '',
          payfast_merchant_id: '',
          payfast_merchant_key: '',
          payfast_passphrase: '',

          sumup: true,
          sumup_api_key: null,
          sumup_merchant_email: null,
          sumup_merchant_code: null,

          card_gateway: null,
        },
        // Keyed as strings: Dropdown compares keys loosely and never emits
        // for a falsy one.
        // What checkout can preselect. Card is one row whichever gateway is
        // behind it, so it is offered once.
        defaultMethodDrop: {
          '2': {title: this.$t('fSale.cod')},
          '3': {title: this.$t('cmp.PaymentSetting.creditDebitCard')},
          '7': {title: this.$t('title.bt')},
        }
      }
    },
    props: {},
    mixins: [util],
    components: {
      PayFast,
      Dropdown,
      IyzicoPayment,
      Flutterwave,
      Razorpay,
      Stripe,
      Paypal,
      AjaxButton,
      Spinner,
      BankPayment,
      Sumup
    },
    computed: {
      /*
       * Only the card gateways whose box is ticked. Keyed as strings: Dropdown
       * compares keys loosely and never emits for a falsy one.
       */
      cardGatewayDrop() {
        const on = v => [1, true, '1'].includes(v)
        const options = {}
        if (on(this.paymentData.sumup)) options['9'] = {title: 'SumUp'}
        if (on(this.paymentData.stripe)) options['3'] = {title: 'Stripe'}
        return options
      },
      /*
       * The chosen gateway while its box is ticked, else the first ticked one -
       * the same rule the server applies (Payment::cardGateway). Null when
       * neither is ticked.
       */
      cardGatewayKey() {
        const chosen = String(parseInt(this.paymentData.card_gateway))
        if (this.cardGatewayDrop[chosen]) return chosen
        if (this.cardGatewayDrop['9']) return '9'
        if (this.cardGatewayDrop['3']) return '3'
        return null
      },
      ...mapGetters('payment', ['payment'])
    },
    methods: {
      paymentTypesSelect(data){
        this.paymentData.default = data.key
      },
      cardGatewaySelect(data){
        this.paymentData.card_gateway = parseInt(data.key)
      },
      async updatePayment() {
        this.updatingPayment = true
        for (const key of ['paypal', 'razorpay', 'flutterwave', 'iyzico_payment', 'payfast_payment']) {
          this.paymentData[key] = 0
        }
        // Save what the dropdown shows: unticking the chosen gateway moves
        // cards to the one still ticked.
        this.paymentData.card_gateway = this.cardGatewayKey ? parseInt(this.cardGatewayKey) : null
        try {
          await this.setPayment(this.paymentData)
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.updatingPayment = false
      },
      ...mapActions('payment', ['setPayment', 'getPayment'])
    },
    created() {
    },
    async mounted() {
      if (!this.payment) {
        this.gettingData = true
        try {
          await this.getPayment()
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.gettingData = false
      }
      this.paymentData = Object.assign({}, this.payment)
    }
  }
</script>

<style lang="stylus" scoped>
  .gateway-off
    border 0
    margin 20px 0 0
    padding 12px 0 0
    min-width 0
    border-top 1px solid #e5e7eb
    opacity .5
    cursor not-allowed

    ::v-deep label, ::v-deep button
      cursor not-allowed

  .card-gateway-none
    font-size 13px
    color #6b7280

  .gateway-off-note
    font-size 12px
    margin 0 0 12px
    color #6b7280
</style>
