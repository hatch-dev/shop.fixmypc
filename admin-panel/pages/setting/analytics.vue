<template>
  <setting-layout
    active-route="analytics"
    class="mb-5"
  >
    <template v-slot:rightArea>

      <h4 class="mb-20 mb-sm-15">{{ $t('title.ana') }}</h4>
      <form
        :class="{'has-error': hasError}"
        @submit.prevent="setAnalytics"
      >

        <label class="input-wrapper block">
          <input type="checkbox" v-model="result.enable_ga">
          {{ $t('title.ega') }}
        </label>


        <div class="input-wrapper single-line">
          <label>
            {{ $t('title.gaId') }}
          </label>

          <input
            type="text"
            :placeholder="$t('title.eGa')"
            v-model="result.ga_id"
          >
        </div>

        <label class="input-wrapper block">
          <input type="checkbox" v-model="result.enable_pixel">
          {{ $t('title.pixel') }}
        </label>


        <div class="input-wrapper single-line">
          <label>
            {{ $t('title.pixelId') }}
          </label>

          <input
            type="text"
            :placeholder="$t('title.ePixel')"
            v-model="result.pixel_id"
          >
        </div>

        <ajax-button
          class="primary-btn"
          :text="$t('setting.sv')"
          :fetching-data="loading"
        />
      </form>

    </template>
  </setting-layout>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import SettingLayout from "~/components/partials/SettingLayout";
  import AjaxButton from "~/components/AjaxButton";
  import Dropdown from "~/components/Dropdown";
  import util from "~/mixin/util";
  import Spinner from "~/components/Spinner";

  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          enable_ga: false,
          enable_pixel: false,
          ga_id: "",
          pixel_id: ""
        },
        loading: false,
        hasError: false,
      }
    },
    props: {},
    mixins: [util],
    components: {
      Spinner,
      Dropdown,
      AjaxButton,
      SettingLayout
    },
    computed: {
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      async setAnalytics() {
        this.setErrors()
        this.loading = true
        try {
          await this.setRequest({ params: this.result, api: 'analytics' })
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      ...mapActions('setting', ['getSetting']),
      ...mapActions('ui', ['setErrors']),
      ...mapActions('common', ['setRequest', 'getRequest'])
    },
    created() {
    },
    async mounted() {
      if (!this.setting) {
        this.gettingStore = true
        try {
          await this.getSetting()
          this.result = Object.assign({}, this.setting)
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.gettingStore = false
      }
      this.result = Object.assign({}, this.setting)
    }
  }
</script>


