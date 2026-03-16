<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="bank-payment" class="mb-0">
        <input
          type="checkbox"
          id="bank-payment"
          v-model="paymentData.bank"
          @change="cbChanged"
        />
        {{ $t('title.bp') }}
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
      v-if="showForm"
    >
      <div class="input-wrapper">
        <label>
          {{ $t('title.bank') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.bank')"
          v-model="paymentData.bank_name"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('title.ahn') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.ahn')"
          v-model="paymentData.account_name"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('title.an') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.an')"
          v-model="paymentData.account_number"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('title.bn') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.bn')"
          v-model="paymentData.branch_name"
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
