<template>
  <form
    :class="{'has-error': hasError}"
    @submit.prevent="updateStore"
  >
    <div
      v-if="gettingStore"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <div class="input-wrapper">
      <label>
        {{ $t('fSale.email') }}
      </label>
      <div class="icon-input">
        <i
          class="icon email-icon"
        />
        <input
          type="text"
          :placeholder="$t('fSale.email')"
          v-model.trim="result.email"
          :class="{invalid: !!!result.email || isInvalidEmail}"
        >
      </div>

      <span
        class="error"
        v-if="!!!result.email && hasError"
      >
        {{ $t('category.req', { type: $t('fSale.email')}) }}
      </span>

      <span
        class="error"
        v-else-if="isInvalidEmail && hasError"
      >
        {{ $t('user.isValid', { type: $t('fSale.email') }) }}
      </span>
    </div>

    <div class="input-wrapper">
      <label>
        {{ $t('fSale.phone') }}
      </label>
      <input
        type="text"
        :placeholder="$t('fSale.phone')"
        v-model="result.phone"
      >
    </div>

    <div class="input-wrapper">
      <label>
        {{ $t('setting.adr') }} 1
      </label>
      <input
        type="text"
        :placeholder="`${$t('setting.adr')} 1`"
        v-model="result.address_1"
        :class="{invalid: !result.address_1 && hasError}"
      >
      <span
        class="error"
        v-if="!result.address_1 && hasError"
      >
        {{ $t('category.req', { type: $t('setting.adr')}) }}
      </span>
    </div>

    <div class="input-wrapper">
      <label>
        {{ $t('setting.adr') }} 2
      </label>
      <input
        type="text"
        :placeholder="`${$t('setting.adr')} 2`"
        v-model="result.address_2"
      >
    </div>

    <div class="dply-felx inputs">
      <div
        class="input-wrapper w-50 pr-7-5"
      >
        <label>
          {{ $t('setting.city') }}
        </label>
        <input
          type="text"
          :placeholder="$t('setting.city')"
          v-model="result.city"
          :class="{invalid: !result.city && hasError}"
        >
        <span
          class="error"
          v-if="!result.city && hasError"
        >
          {{ $t('category.req', { type: $t('setting.city')}) }}
        </span>
      </div>

      <div class="input-wrapper w-50 pl-7-5">
        <label>
          {{ $t('setting.state') }}
        </label>
        <input
          type="text"
          :placeholder="$t('setting.state')"
          v-model="result.state"
          :class="{invalid: !result.state && hasError}"
        >
        <span
          class="error"
          v-if="!result.state && hasError"
        >
          {{ $t('category.req', { type: $t('setting.state')}) }}
        </span>
      </div>
    </div>

    <div class="dply-felx inputs">
      <div class="input-wrapper w-50 pr-7-5">
        <label>
          {{ $t('setting.zip') }}
        </label>
        <input
          type="text"
          :placeholder="$t('setting.zip')"
          v-model="result.zip"
          :class="{invalid: !result.zip && hasError}"
        >
        <span
          class="error"
          v-if="!result.zip && hasError"
        >
          {{ $t('category.req', { type: $t('setting.zip')}) }}
        </span>
      </div>

      <div class="input-wrapper w-50 pl-7-5">
        <label>
          {{ $t('brand.country') }}
        </label>
        <input
          type="text"
          :placeholder="$t('brand.country')"
          v-model="result.country"
          :class="{invalid: !result.country && hasError}"
        >
        <span
          class="error"
          v-if="!result.country && hasError"
        >
          {{ $t('category.req', { type: $t('brand.country')}) }}
        </span>
      </div>
    </div>

    <div class="oflow-hidden">
      <ajax-button
        v-if="$can('setting', 'edit')"
        class="primary-btn"
        :fetching-data="updatingStore"
      />
    </div>

  </form>

</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import validation from "~/mixin/validation"
  import FileUpload from "~/components/FileUpload"
  import Spinner from '~/components/Spinner'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import util from "~/mixin/util"

  export default {
    name: 'AddressSetting',
    data() {
      return {
        gettingStore: false,
        updatingStore: false,
        fileUploading: false,
        hasError: false,
        result: {
          id: null,
          phone: null,
          email: null,
          address_1: null,
          address_2: null,
          city: null,
          state: null,
          zip: null
        }
      }
    },
    props: {},
    mixins: [util, validation],
    components: {
      AjaxButton,
      FileUpload,
      Spinner,
      ErrorFormatter
    },
    computed: {
      isInvalidEmail() {
        return (this.result.email && !this.isValidEmail(this.result.email))
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      async updateStore() {
        if (this.result.email && this.result.address_1 && this.result.city
          && this.result.state && this.result.zip && this.result.country && !this.isInvalidEmail) {
          this.updatingStore = true
          try {


            await this.setAddress(this.result)


          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.result = Object.assign({}, this.setting)
          this.updatingStore = false

        } else{
          this.hasError = true
        }
      },
      ...mapActions('setting', ['getSetting', 'setAddress'])
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
<style lang="stylus">
  .logo-upload
    width 200px
    padding 0 !important

    input[type="file"]
      display none

    img
      max-height 80px
      width auto

</style>
