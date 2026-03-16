<template>
  <div>
    <div class="input-wrapper dply-felx start">
      <label for="paypal" class="mb-0">
        <input
          type="checkbox"
          id="paypal"
          v-model="storeData.whatsapp_btn"
          @change="cbChanged"
        />
        {{ $t('title.wb') }}
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
          {{ $t('title.wn') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.wn')"
          v-model="storeData.whatsapp_number"
          @input="emitData"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('title.wdm') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.wdm')"
          v-model="storeData.whatsapp_default_msg"
          @input="emitData"
        >
      </div>

    </div>
  </div>

</template>

<script>

  export default {
    name: 'WhatsAppSetting',
    data() {
      return {
        showForm: false
      }
    },
    props: {
      storeData: {
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
        this.$emit('change', this.storeData)
      }
    }
  }
</script>
