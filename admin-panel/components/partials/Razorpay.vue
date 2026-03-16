<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="razorpay" class="mb-0">
        <input
          type="checkbox"
          id="razorpay"
          v-model="paymentData.razorpay"
          @change="cbChanged"
        />
        {{ $t('dataPage.razorpay') }}
      </label>
      <button
        type="button"
        class="toggle-arrow"
        @click="showForm = !showForm"
      ><i class="icon black arrow-down"></i></button>
    </div>

    <div
      class="payment-form-wrap"
      v-if="showForm">
      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.rk') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.rk')"
          v-model="paymentData.razorpay_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.rs') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.rs')"
          v-model="paymentData.razorpay_secret"
          @input="emitData"
        >
      </div>
    </div>
  </div>

</template>

<script>

  export default {
    name: 'Razorpay',
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
    mixins: [],
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
