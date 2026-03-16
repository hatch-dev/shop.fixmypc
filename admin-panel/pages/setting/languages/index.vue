<template>
  <setting-layout
    active-route="languages"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <language-setting/>
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import LanguageSetting from "~/components/partials/LanguageSetting";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      LanguageSetting,
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


