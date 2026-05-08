<template>
  <div class="">
    <button
      class="btn manage-btn"
      aria-label="submit"
      @click.prevent="payNow = true"
    >
      {{ $t('checkout.payNow') }}
    </button>
    <payment-popup
      v-if="payNow"
      :order="order"
      @close="payNow = false"
    />
  </div>

</template>
<script>

  import PaymentPopup from "./PaymentPopup";
  export default {
    name: 'PayButton',
    data() {
      return {
        payNow: false
      }
    },
    watch: {},
    props: {
      order: {
        type: Object,
        default(){
          return null
        }
      }
    },
    components: {
      PaymentPopup
    },
    computed: {

    },
    mixins: [],
    methods: {
    },
    created() {
    },
    async mounted() {
    }
  }
</script>
<style scoped>
.manage-btn {
    background: #33319A;
    color: #fff;
    border-radius: 20px;
    padding: 6px 18px;
    font-size: 14px;
    font-weight: 600;
}
.manage-btn:hover {
    background: #05B942;
    color: #Fff;
}
</style>

