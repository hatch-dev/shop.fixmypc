<template>
  <form
    :class="{'has-error': hasError}"
    @submit.prevent="updateData"
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

    <div class="input-wrapper">

      <div class="input-wrapper dply-felx start gap-10">
        <label
          for="google-login"
          class="mb-0 dply-felx start gap-10">
          <span class="label">{{ $t('title.googleLogin') }}</span>
          <input
            type="checkbox"
            id="google-login"
            v-model="result.google_login"
          />

        </label>
      </div>



      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.gci') }}
        </label>
        <input
          type="password"
          :placeholder="$t('dataPage.gci')"
          v-model="result.googleClientId"
          :class="{invalid: !result.googleClientId && hasError}"
        >
        <span
          class="error"
          v-if="!result.googleClientId && hasError"
        >
         {{ $t('category.req', { type: $t('dataPage.gci')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.gcs') }}
        </label>
        <input
          type="password"
          :placeholder="$t('dataPage.gcs')"
          v-model="result.googleClientSecret"
          :class="{invalid: !result.googleClientSecret && hasError}"
        >
        <span
          class="error"
          v-if="!result.googleClientSecret && hasError"
        >
          {{ $t('category.req', { type: $t('dataPage.gcs')}) }}
        </span>
      </div>


      <div class="input-wrapper dply-felx start gap-10">
        <label
          for="facebook-login"
          class="mb-0 dply-felx start gap-10"
        >
          <span class="label">
            {{ $t('title.facebookLogin') }}
          </span>
          <input
            type="checkbox"
            id="facebook-login"
            v-model="result.facebook_login"
          />

        </label>
      </div>


      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.fci') }}
        </label>
        <input
          type="password"
          :placeholder="$t('dataPage.fci')"
          v-model="result.facebookClientId"
          :class="{invalid: !result.facebookClientId && hasError}"
        >
        <span
          class="error"
          v-if="!result.facebookClientId && hasError"
        >
         {{ $t('category.req', { type: $t('dataPage.fci')}) }}
        </span>
      </div>


      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.fcs') }}
        </label>
        <input
          type="password"
          :placeholder="$t('dataPage.fcs')"
          v-model="result.facebookClientSecret"
          :class="{invalid: !result.facebookClientSecret && hasError}"
        >
        <span
          class="error"
          v-if="!result.facebookClientSecret && hasError"
        >
          {{ $t('category.req', { type: $t('dataPage.fcs')}) }}
        </span>
      </div>


    </div>

    <div class="oflow-hidden">
      <ajax-button
        v-if="$can('setting', 'edit')"
        class="primary-btn"
        :fetching-data="updating"
      />
    </div>

  </form>

</template>

<script>

  import AjaxButton from '~/components/AjaxButton'
  import validation from "~/mixin/validation"
  import Spinner from '~/components/Spinner'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import util from "~/mixin/util"
  import {mapActions} from 'vuex'

  export default {
    name: 'SocialLoginSetting',
    data() {
      return {
        gettingStore: false,
        updating: false,
        loading: false,

        hasError: false,
        result: {
          facebook_login: true,
          google_login: true,
          googleClientId: null,
          googleClientSecret: null,
          facebookClientId: null,
          facebookClientSecret: null,
        }
      }
    },
    props: {},
    mixins: [util, validation],
    components: {
      AjaxButton,
      Spinner,
      ErrorFormatter
    },
    computed: {},
    methods: {

      async fetchingData() {
        try {
          this.loading = true

          const data = await this.getRequest({api: 'socialLoginFind'})

          if(data){
            this.result = data
          }

          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },

      async updateData() {
        if ((this.result.googleClientId && this.result.googleClientSecret) ||
          (this.result.facebookClientId && this.result.facebookClientSecret)
        ) {
          this.updating = true
          try {
            const data = await this.setRequest({params: this.result, api: 'socialLoginAction'})

            if(data){
              this.result = data
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.updating = false

        } else {
          this.hasError = true
        }
      },

      ...mapActions('common', ['setRequest', 'getRequest'])
    },
    created() {
    },
    async mounted() {
      await this.fetchingData()
    }
  }
</script>
<style lang="stylus">

</style>
