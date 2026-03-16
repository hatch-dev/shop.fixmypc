<template>
  <setting-layout
    active-route="plugins"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <plugins-setting />
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import PluginsSetting from "../../components/partials/PluginsSetting";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      PluginsSetting,
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


