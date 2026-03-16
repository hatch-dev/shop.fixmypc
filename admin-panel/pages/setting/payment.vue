<template>
  <setting-layout
    active-route="payment"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <payment-setting/>
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import PaymentSetting from "~/components/partials/PaymentSetting";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      PaymentSetting,
      SettingLayout
    },
    computed: {

    },
    methods: {

    },
    created() {
    },
    async mounted() {

    }
  }
</script>


