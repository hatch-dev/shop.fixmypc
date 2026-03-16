<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="paypal" class="mb-0">
        <input
          type="checkbox"
          id="paypal"
          v-model="paymentData.paypal"
          @change="cbChanged"
        />
        {{ $t('fSale.paypal') }}
      </label>
      <button
        type="button"
        class="toggle-arrow"
        @click="showForm = !showForm"
      >
        <i class="icon black arrow-down"/>
      </button>
    </div>

    <div
      v-if="showForm"
      class="payment-form-wrap"
    >
      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.pk') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.pk')"
          v-model="paymentData.paypal_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.ps') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.ps')"
          v-model="paymentData.paypal_secret"
          @input="emitData"
        >
      </div>
    </div>
  </div>

</template>

<script>

  export default {
    name: 'Paypal',
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
    }
  }
</script>
