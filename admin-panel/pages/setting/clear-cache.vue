<template>
  <setting-layout
    active-route="clear-cache"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <div>
        <p
          class="dashboard-notice info-msg mb-20 mb-sm-15"
        >
          <b>{{ $t('setting.imp') }}</b>: {{ $t('setting.cClearMsg') }}
        </p>

        <clear-cache-btn
          class="primary-btn"
        />
      </div>
    </template>
  </setting-layout>
</template>

<script>

  import SettingLayout from "~/components/partials/SettingLayout";
  import ClearCacheBtn from "~/components/partials/ClearCacheBtn";

  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

      }
    },
    props: {},
    mixins: [],
    components: {
      ClearCacheBtn,
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


