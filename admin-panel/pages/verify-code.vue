<template>
  <form
    @submit.prevent="checkForm()"
    :class="{'has-error': hasError}"
    class="login-form"
  >
    <h4
      class="mb-30 mb-sm-15"
    >
      {{ $t('profile.putCode') }}
    </h4>

    <error-formatter/>

    <div class="input-wrapper">
      <div class="icon-input">
        <i class="icon email-icon">&nbsp;</i>
        <input
          disabled
          type="text"
          :placeholder="$t('fSale.email')"
          v-model.trim="email"
          :class="{invalid: !!!email || isInvalidEmail}"
        >
      </div>

      <span
        class="error"
        v-if="!!!email && hasError"
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
      <div class="icon-input">
        <i class="icon code-icon">&nbsp;</i>
        <input
          type="text"
          :placeholder="$t('profile.cfe')"
          v-model.trim="code"
          :class="{invalid: !!!code }"
        >
      </div>

      <span
        class="error"
        v-if="!!!code && hasError"
      >
        {{ $t('category.req', { type: $t('setting.code')}) }}
      </span>
    </div>

    <div
      class="input-wrapper"
    >
      <password-field
        :value="password"
        :is-invalid="isLengthInvalid"
        @change="password = $event"
      />
      <span
        class="error"
        v-if="!password && hasError"
      >
        {{ $t('category.req', { type: $t('user.pass')}) }}
      </span>
      <span
        class="error"
        v-else-if="invalidPassword && hasError"
      >
       {{ $t('user.inPass') }}
      </span>
    </div>

    <div
      class="input-wrapper"
    >
      <password-field
        :value="confirmPassword"
        @change="confirmPassword = $event"
      />
      <span
        class="error"
        v-if="!confirmPassword && hasError"
      >
        {{ $t('category.req', { type: $t('user.pass')}) }}
      </span>
      <span
        class="error"
        v-else-if="confirmPassword !== password && hasError"
      >
        {{ $t('user.nMatch') }}
      </span>
    </div>

    <div class="dply-felx j-right mt-15">
      <nuxt-link
        to="/login"
        class="link"
      >
        {{ $t('profile.ltya') }}
      </nuxt-link>
    </div>

    <ajax-button
      class="mt-20 primary-btn"
      :fetching-data="formSubmitting"
      :loading-text="$t('dataPage.verify')"
      :text="$t('dataPage.up')"
      :activate-btn="true"
    />

  </form>
</template>

<script>
  import {mapActions} from 'vuex'
  import validation from '~/mixin/validation'
  import AjaxButton from '~/components/AjaxButton'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import PasswordField from '~/components/PasswordField'

  export default {
    name: "login",
    layout: "login-layout",
    middleware: ['common-middleware', 'non-logged-in'],
    data() {
      return {
        password: '',
        code: '',
        confirmPassword: '',
        hasError: false,
        formSubmitting: false
      }
    },
    mixins: [validation],
    components: {
      AjaxButton,
      ErrorFormatter,
      PasswordField
    },
    watch: {},
    computed: {
      email(){
        return this.$route?.query?.email
      },
      isInvalidEmail() {
        return (this.email && !this.isValidEmail(this.email))
      },
      isLengthInvalid() {
        return (this.password && !this.isValidLength(this.password))
      },
      invalidPassword() {
        return !this.isValidLength(this.password)
      },
      passwordValid() {
        return this.password && !this.invalidPassword
      }
    },
    methods: {
      async checkForm() {
        this.hasError = false

        if (!this.email || this.isInvalidEmail || !this.code || !this.password || (this.password !== this.confirmPassword)) {
          this.hasError = true
          return false
        }

        this.formSubmitting = true
        try {
          const data = await this.setRequest({params:
              {
                password : this.password,
                email : this.email,
                code : this.code
            }, api: 'verifyCode'})
          if(data){
            this.$router.push('/login')
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },
      ...mapActions('ui', ['setErrors']),
      ...mapActions('common', ['setRequest'])
    },
    mounted() {
      this.setErrors()
    }
  }
</script>

<style scoped>

</style>

