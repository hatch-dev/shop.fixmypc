<template>
  <div class="b-t mt-15 pt-15">

    <div class="dply-felx start gap-15">
      <label>{{ $t('list.payment') }}</label>
      <dropdown
        class="payment-drop"
        :selected-key="posPaymentIn.CASH"
        :options="posPaymentDrop"
        @clicked="paymentChanged"
      />
    </div>

    <form
      v-if="parseInt(posPayment.payment_method) === posPaymentIn.OFFLINE"
      class="pl-50 pt-15"
    >
      <div class="input-wrapper">
        <label>
          {{ $t('fSale.pMethod') }}
        </label>
        <input
          type="text"
          :placeholder="$t('fSale.pMethod')"
          v-model="posPayment.offline_payment_method"
          @change="emitPosPayment"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('title.ti') }}
        </label>
        <input
          type="text"
          :placeholder="$t('title.ti')"
          v-model="posPayment.offline_trans_id"
          @change="emitPosPayment"
        >
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('ship.pp') }}
        </label>

        <file-upload
          :image-url="proofImageUrl"
          class="mb-20 mb-sm-15"
          only-icon="upload-icon"
          @file-upload="uploadFile"
        />
      </div>
    </form>

  </div>
</template>

<script>
  import AjaxButton from "~/components/AjaxButton";
  import Dropdown from "~/components/Dropdown";
  import util from "~/mixin/util";
  import FileUpload from "../FileUpload";

  export default {
    name: "PosPayment",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        posPayment: {
          payment_method: '',
          offline_payment_method: '',
          offline_trans_id: '',
          offline_payment_proof: ''
        },
        proofImageUrl: null,
        showForm: false
      }
    },
    components: {
      FileUpload,
      Dropdown,
      AjaxButton
    },
    props: {
    },
    mixins: [util],
    computed: {

    },
    methods: {
      emitPosPayment(){
        this.$emit('pos-payment', this.posPayment)
      },
      uploadFile(evt){
        if (evt) {
          this.posPayment.offline_payment_proof = evt.files[0]
          this.proofImageUrl = URL.createObjectURL(evt)
          this.emitPosPayment()
        }
      },
      paymentChanged(evt){
        this.posPayment.payment_method = evt.key
        this.emitPosPayment()
      }
    },
    mounted() {
      this.posPayment.payment_method = this.posPaymentIn.CASH
    }
  }
</script>

<style scoped>

</style>
