<template>
  <setting-layout
    active-route="smtp"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <smtp-setting/>
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import SmtpSetting from "~/components/partials/SmtpSetting";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      SmtpSetting,
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


