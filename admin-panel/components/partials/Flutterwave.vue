<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="flutterwave" class="mb-0">
        <input
          type="checkbox"
          id="flutterwave"
          v-model="paymentData.flutterwave"
          @change="cbChanged"
        />
        {{ $t('dataPage.fw') }}
      </label>
      <button
        type="button"
        class="toggle-arrow"
        @click="showForm = !showForm"
      >
        <i class="icon black arrow-down" />
      </button>
    </div>

    <div
      class="payment-form-wrap"
      v-if="showForm">
      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.fwpk') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.fwpk')"
          v-model="paymentData.fw_public_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.fwsk') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.fwsk')"
          v-model="paymentData.fw_secret_key"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.fwek') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.fwek')"
          v-model="paymentData.fw_encryption_key"
          @input="emitData"
        >
      </div>


      <div class="input-wrapper">
        <label class="block">
          {{ $t('dataPage.env') }}
        </label>
        <dropdown
          :selectedKey="`${paymentData.fw_environment}`"
          :options="environmentObj"
          @clicked="dropdownSelected"
        />
      </div>
    </div>
  </div>

</template>

<script>

  import Dropdown from "../Dropdown";
  import util from "../../mixin/util";
  export default {
    name: 'Flutterwave',
    components: {Dropdown},
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
      dropdownSelected(data){
        this.paymentData.fw_environment = data.key
        this.emitData()
      },
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
