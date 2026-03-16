<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="iyzico-payment" class="mb-0">
        <input
          type="checkbox"
          id="iyzico-payment"
          v-model="paymentData.iyzico_payment"
          @change="cbChanged"
        />
        {{ $t('dataPage.ip') }}
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
      class="payment-form-wrap"
      v-if="showForm">
      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.iak') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.iak')"
          v-model="paymentData.ip_api_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.isk') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.isk')"
          v-model="paymentData.ip_secret_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.ibu') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.ibu')"
          v-model="paymentData.ip_base_url"
          @input="emitData"
        >
      </div>


    </div>
  </div>

</template>

<script>

  import util from "~/mixin/util";

  export default {
    name: 'IyzicoPayment',
    components: {},
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
    mixins: [util],
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
