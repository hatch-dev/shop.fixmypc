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
