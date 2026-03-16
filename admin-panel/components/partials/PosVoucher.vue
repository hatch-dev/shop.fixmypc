<template>
  <div class="b-t mt-15 pt-15">


    <div class="dply-felx start">
      <label for="pos-voucher" class="mb-0">
        <input
          type="checkbox"
          id="pos-voucher"
          @change="cbChanged"
        />
        {{ $t('fSale.voucher') }}
      </label>
      <button
        type="button"
        class="toggle-arrow"
        @click="showForm = !showForm"
      ><i class="icon black arrow-down"></i></button>
    </div>

    <div
      class="p-15"
      v-if="showForm"
    >
      <h4 v-if="error" class="error mtb-15">{{error}}</h4>

      <div class="pb-0" :class="{invalid: !!error}">
        <form
          class="btn-input mt-15"
        >
          <input
            class="pr-80"
            :placeholder="$t('prod.code')"
            type="text"
            v-model="voucher">

          <ajax-button
            class="primary-btn plr-15"
            type="button"
            :fetching-data="submitting"
            loading-text=""
            :disabled="!voucher || !!error || !!voucherResult"
            :text="$t('ship.apply')"
            @clicked="checkVoucher"
          />
        </form>
      </div>

    </div>
  </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton";

  export default {
    name: "PosVoucher",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        voucher: '',
        error: null,
        voucherResult: null,
        submitting: false,
        showForm: false
      }
    },
    components: {
      AjaxButton
    },
    props: {
      calculatedPrice: {
        type: Object
      },
      selectedUser: {
        type: Object
      },
    },
    watch: {
      voucher() {
        this.voucherResult = null
        this.error = null
      }
    },
    mixins: [],
    computed: {
      ...mapGetters('language', ['langCode']),
    },
    methods: {

      async checkVoucher() {
        if (!this.selectedUser || this.selectedUser?.id === -1) {
          this.error = this.$t('ship.sa')
          return
        }

        this.submitting = true
        const res = await this.setRequest({
          params: {
            voucher: this.voucher,
            user_id: this.selectedUser?.id,
            price: this.calculatedPrice?.totalPriceWithOffer
          },
          api: 'voucherValidity',
          lang: this.langCode
        })

        this.submitting = false
        if (res?.status === 201) {
          this.error = res.data.form[0]
        } else {
          this.voucherResult = res
          this.$emit('voucher-result', res)
        }
      },
      emptyError(){
        this.error = null
      },
      cbChanged(evt){
        this.showForm = evt.target.checked
      },
      ...mapActions('common', ['getRequest', 'setRequest']),
    },
    async mounted() {

    }
  }
</script>

<style scoped>

</style>
