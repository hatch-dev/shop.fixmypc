<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="payfast" class="mb-0">
        <input
          type="checkbox"
          id="payfast"
          v-model="paymentData.payfast_payment"
          @change="cbChanged"
        />
        {{ $t('ship.payfast') }}
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
          {{ $t('ship.pmi') }}
        </label>
        <input
          type="text"
          :placeholder="$t('ship.pmi')"
          v-model="paymentData.payfast_merchant_id"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('ship.pms') }}
        </label>
        <input
          type="text"
          :placeholder="$t('ship.pms')"
          v-model="paymentData.payfast_merchant_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('ship.pps') }}
        </label>
        <input
          type="text"
          :placeholder="$t('ship.pps')"
          v-model="paymentData.payfast_passphrase"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('ship.pbu') }}
        </label>
        <input
          type="text"
          :placeholder="$t('ship.pbu')"
          v-model="paymentData.payfast_base_url"
          @input="emitData"
        >
      </div>
    </div>
  </div>

</template>

<script>

  export default {
    name: 'PayFast',
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
