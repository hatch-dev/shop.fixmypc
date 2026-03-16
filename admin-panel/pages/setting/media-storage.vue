<template>
  <setting-layout
    active-route="media-storage"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <media-storage-setting/>
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import MediaStorageSetting from "~/components/partials/MediaStorageSetting";

  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      MediaStorageSetting,
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


