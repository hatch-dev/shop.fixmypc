<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="stripe" class=" mb-0">
        <input
          type="checkbox"
          id="stripe"
          v-model="paymentData.stripe"
          @change="cbChanged"
        />
        {{ $t('dataPage.stripe') }}
      </label>
      <button
        type="button"
        class="toggle-arrow"
        @click="showForm = !showForm"
      ><i class="icon black arrow-down"></i></button>
    </div>

    <div
      v-if="showForm"
      class="payment-form-wrap"
    >
      <div class="input-wrapper">
        <label>{{ $t('dataPage.sk') }}</label>
        <input
          type="text"
          :placeholder="$t('dataPage.sk')"
          v-model="paymentData.stripe_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.ss') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.ss')"
          v-model="paymentData.stripe_secret"
          @input="emitData"
        >
      </div>

      <!--
        Memberships are billed as Stripe subscriptions, and Stripe reports
        renewals, failed payments and cancellations to the webhook. Add an
        endpoint in Stripe (Developers > Webhooks) for the URL below and paste
        its signing secret here.
      -->
      <div class="input-wrapper">
        <label>{{ $t('cmp.Stripe.webhookSecret') }}</label>
        <input
          type="text"
          placeholder="whsec_..."
          v-model="paymentData.stripe_webhook_secret"
          @input="emitData"
        >
        <span class="field-hint">
          {{ $t('cmp.Stripe.forMemberships') }} <code>{{ webhookUrl }}</code><br>
          {{ $t('cmp.Stripe.events') }} customer.subscription.created,
          customer.subscription.updated, customer.subscription.deleted,
          invoice.paid, invoice.payment_failed
        </span>
      </div>
    </div>
  </div>

</template>

<script>

  export default {
    name: 'Stripe',
    data() {
      return {
        showForm: false
      }
    },
    computed: {
      // the API host, which serves the webhook (not necessarily this panel's)
      webhookUrl() {
        const base = (process.env.apiBase || (typeof window !== 'undefined' ? window.location.origin + '/' : '/'))
        return base.replace(/\/?$/, '/') + 'api/v1/stripe/webhook'
      }
    },
    props: {
      paymentData: {
        type: Object,
        required: true
      }
    },
    methods: {
      cbChanged(evt){
        this.showForm = evt.target.checked
        this.emitData()
      },
      emitData(){
        this.$emit('change', this.paymentData)
      }
    },
  }
</script>
