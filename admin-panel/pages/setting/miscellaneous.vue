<template>
  <setting-layout
    active-route="miscellaneous"
    class="mb-5"
  >
    <template v-slot:rightArea>

      <div v-if="$store.state.admin.activated" class="f-wrap mb-20 mb-sm-15 info-msg dply-felx gap-10">
        <p
          class=""
        >
          {{ $t('dataPage.activated') }}
        </p>

        <ajax-button
          type="button"
          class="primary-btn"
          :disabled="isDemo"
          :activate-btn="true"
          :text="$t('dataPage.deact')"
          @clicked="deactivateScript"
          :fetching-data="deactivating"
        />
      </div>

      <form
        v-else
        :class="{'has-error': hasError}"
        @submit.prevent="activateScript"
      >
        <div
          v-if="loading"
          class="spinner-wrapper"
        >
          <spinner
            :radius="60"
            color="primary"
            class="mr-15"
          />
        </div>

        <div class="input-wrapper single-line">
          <label>
            {{ $t('dataPage.pkey') }}
          </label>

          <input
            type="text"
            :placeholder="$t('dataPage.pkey')"
            v-model="result.code"
            :class="{invalid: !!!result.code}"
          >
          <span
            class="error"
            v-if="!!!result.code && hasError"
          >
            {{ $t('category.req', { type: $t('dataPage.pkey')}) }}
          </span>
        </div>

        <ajax-button
          class="primary-btn mb-20"
          :activate-btn="true"
          :text="$t('dataPage.act')"
          :fetching-data="updating"
        />
      </form>

      <h4 class="mb-20 mb-sm-15">{{ $t('dataPage.mis') }}</h4>
      <form
        :class="{'has-error': hasMisError}"
        @submit.prevent="setMiscellaneous"
      >

        <label class="input-wrapper block">
          <input type="checkbox" v-model="cbSetting.guest_checkout">
          {{ $t('title.gc') }}
        </label>


        <label class="input-wrapper block">
          <input type="checkbox" v-model="cbSetting.cookie_banner">
          {{ $t('title.cb') }}
        </label>

        <label class="input-wrapper block">
          <input type="checkbox" v-model="cbSetting.vendor_registration">
          {{ $t('title.vr') }}
        </label>

        <label class="input-wrapper block">
          <input type="checkbox" v-model="cbSetting.attach_pdf">
          {{ $t('title.apue') }}
        </label>


        <label class="input-wrapper block">
          <input type="checkbox" v-model="cbSetting.translate_pdf">
          {{ $t('ship.trans') }}
        </label>


        <label class="input-wrapper block">
          <input type="checkbox" v-model="cbSetting.send_seller_email">
          {{ $t('title.sEmail') }}
        </label>


        <div class="input-wrapper location-wrap">
          <div class="dply-felx f-wrap gap-10 start">
            <label>{{ $t('ship.dl') }}</label>

            <dropdown
              :selected-key="cbSetting.default_country"
              :searching="true"
              :options="countryList"
              key-name="name"
              @clicked="selectedCountry"
            />

            <dropdown
              :selected-key="cbSetting.default_state"
              :searching="true"
              :options="stateList"
              key-name="name"
              @clicked="stateSelected"
            />
          </div>

        </div>


        <ajax-button
          class="primary-btn"
          :text="$t('setting.sv')"
          :fetching-data="misUpdating"
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
        cbSetting: {
          default_country: 'AF',
          default_state: 'BDS',
          attach_pdf: false,
          translate_pdf: false,
          send_seller_email: false,
          vendor_registration: false,
          cookie_banner: false,
          guest_checkout: false
        },
        result: {
          code: null
        },
        stateList: {},
        misUpdating: false,
        deactivating: false,
        loading: false,
        updating: false,
        hasMisError: false,
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
      isDemo() {
        return process?.env?.isDemo
      },
      ...mapGetters('resource', ['countryList']),
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      stateSelected(evt){
        this.cbSetting.default_state = evt.key
      },
      generateStateList() {
        this.stateList = this.countryList[this.cbSetting?.default_country]?.states
      },
      selectedCountry(evt){
        this.cbSetting.default_country = evt.key
        this.generateStateList()

        if(Object.keys(this.stateList).length){
          this.cbSetting.default_state = Object.keys(this.stateList)[0]
        }
      },
      async deactivateScript() {
        if (confirm(this.$t('dataPage.deactMsg'))) {
          try {
            this.setErrors()

            this.deactivating = true
            await this.getRequest({params: {}, api: 'deactivate'})
            window.location.reload()
            this.deactivating = false

          } catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      async setMiscellaneous() {
        this.setErrors()
        this.misUpdating = true
        try {
          await this.setRequest({params: this.cbSetting, api: 'miscellaneous'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.misUpdating = false
      },
      async activateScript() {
        this.setErrors()
        this.hasError = false

        this.setActivated({
              activated: 1,
              public_key: 'Qal0q1e2ChbCKO1ShwcMmi7DtHVSu/lSHQi56LsUOZXRNW7k7kBXOQCxsMFlZrzXbLlhrF/+NF0Qhny/mvi2Cw=='
            })

        if (this.result.code) {
          this.updating = true
          try {
            const data = await this.setRequest({params: this.result, api: 'activate'})

            this.setActivated({
              activated: data?.valid,
              public_key: data?.public_key
            })
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.updating = false

        } else {
          this.hasError = true
        }
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('setting', ['getSetting']),
      ...mapActions('admin', ['setActivated']),
      ...mapActions('ui', ['setErrors']),
      ...mapActions('common', ['setRequest', 'getRequest', 'getById'])
    },
    created() {
    },
    async mounted() {
      if (!this.countryList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'countries',
          api: 'resource'
        })

        this.setCountryList(data)
        this.loading = false

        this.generateStateList()
      }


      if (!this.setting) {
        this.gettingStore = true
        try {
          await this.getSetting()
          this.cbSetting = Object.assign({}, this.setting)
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.gettingStore = false
      }
      this.cbSetting = Object.assign({}, this.setting)

      this.generateStateList()

    }



  }
</script>


