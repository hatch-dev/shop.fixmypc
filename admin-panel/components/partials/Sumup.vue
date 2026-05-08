<template>
  <div>
    <!-- Header -->
    <div class="input-wrapper dply-felx start">
      <label for="sumup-payment" class="mb-0">
        <input
          type="checkbox"
          id="sumup-payment"
          v-model="paymentData.sumup"
          @change="cbChanged"
        />
        SumUp
      </label>

      <button
        type="button"
        class="toggle-arrow"
        @click="showForm = !showForm"
      >
        <i class="icon black arrow-down"/>
      </button>
    </div>

    <!-- Form -->
    <div class="payment-form-wrap" v-if="showForm">

      <div class="input-wrapper">
        <label>API Key</label>
        <input
          type="text"
          v-model="paymentData.sumup_api_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>Merchant Email</label>
        <input
          type="text"
          v-model="paymentData.sumup_merchant_email"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>Merchant Code</label>
        <input
          type="text"
          v-model="paymentData.sumup_merchant_code"
          @input="emitData"
        >
      </div>

    </div>
  </div>
</template>
<script>
import util from "~/mixin/util";

export default {
  name: "Sumup",

  props: {
    paymentData: {
      type: Object,
      required: true
    }
  },

  mixins: [util],

  data() {
    return {
      showForm: false
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

  mounted() {
    // auto open if already enabled
    this.showForm = !!this.paymentData.sumup
  }
}
</script>