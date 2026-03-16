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

    <div class="input-wrapper ">

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.smtpHost') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.smtpHost')"
          v-model="result.smtpHost"
          :class="{invalid: !result.smtpHost && hasError}"
        >
        <span
          class="error"
          v-if="!result.smtpHost && hasError"
        >
         {{ $t('category.req', { type: $t('dataPage.smtpHost')}) }}
        </span>
      </div>


      <div class="dply-felx inputs">
        <div
          class="input-wrapper w-50 pr-7-5"
        >
          <label>
            {{ $t('dataPage.smtpPort') }}
          </label>
          <input
            type="text"
            :placeholder="$t('dataPage.smtpPort')"
            v-model="result.smtpPort"
            :class="{invalid: !result.smtpPort && hasError}"
          >
          <span
            class="error"
            v-if="!result.smtpPort && hasError"
          >
            {{ $t('category.req', { type: $t('dataPage.smtpPort')}) }}
          </span>
        </div>

        <div class="input-wrapper w-50 pl-7-5">
          <label>
            {{ $t('dataPage.smtpEnc') }}
          </label>
          <input
            type="text"
            :placeholder="$t('dataPage.smtpEnc')"
            v-model="result.smtpEncryption"
            :class="{invalid: !result.smtpEncryption && hasError}"
          >
          <span
            class="error"
            v-if="!result.smtpEncryption && hasError"
          >
            {{ $t('category.req', { type: $t('dataPage.smtpEnc')}) }}
          </span>
        </div>
      </div>


      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.smtpUser') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.smtpUser')"
          v-model="result.smtpUsername"
          :class="{invalid: !result.smtpUsername && hasError}"
        >
        <span
          class="error"
          v-if="!result.smtpUsername && hasError"
        >
         {{ $t('category.req', { type: $t('dataPage.smtpUser')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.smtpPass') }}
        </label>
        <input
          type="password"
          :placeholder="$t('dataPage.smtpPass')"
          v-model="result.smtpPassword"
          :class="{invalid: !result.smtpPassword && hasError}"
        >
        <span
          class="error"
          v-if="!result.smtpPassword && hasError"
        >
         {{ $t('category.req', { type: $t('dataPage.smtpPass')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('dataPage.smtpForm') }}
        </label>
        <input
          type="text"
          :placeholder="$t('dataPage.smtpForm')"
          v-model="result.mailFrom"
          :class="{invalid: !result.mailFrom && hasError}"
        >
        <span
          class="error"
          v-if="!result.mailFrom && hasError"
        >
          {{ $t('category.req', { type: $t('dataPage.smtpForm')}) }}
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
    name: 'SmtpSetting',
    data() {
      return {
        gettingStore: false,
        updating: false,
        loading: false,

        hasError: false,
        result: {

          smtpHost: null,
          smtpPort: null,
          smtpUsername: null,
          smtpPassword: null,
          smtpEncryption: null,
          mailFrom: null,
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

          const data = await this.getRequest({api: 'smtpFind'})

          if(data){
            this.result = data
          }

          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async updateData() {
        if (this.result.smtpHost && this.result.smtpPort && this.result.smtpUsername &&
          this.result.smtpPassword && this.result.smtpEncryption && this.result.mailFrom
        ) {
          this.updating = true

          try {
            const data = await this.setRequest({params: this.result, api: 'smtpAction'})

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
