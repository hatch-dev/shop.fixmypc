<template>
  <setting-layout
    active-route="address"
    class="mb-5"
  >
    <template v-slot:rightArea>

      <address-setting />

    </template>
  </setting-layout>
</template>

<script>

  import AddressSetting from "~/components/partials/AddressSetting";
  import SettingLayout from "~/components/partials/SettingLayout";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      SettingLayout,
      AddressSetting
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


