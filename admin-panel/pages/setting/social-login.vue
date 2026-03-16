<template>
  <setting-layout
    active-route="social-login"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <social-login-setting />
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import SocialLoginSetting from "~/components/partials/SocialLoginSetting";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      SocialLoginSetting,
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


