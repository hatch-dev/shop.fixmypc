<template>
  <form
    @submit.prevent="checkForm()"
    :class="{'has-error': hasError}"
    class="login-form"
  >
    <h4 class="mb-30 mb-sm-15">
      {{ $t('profile.sendCode') }}
    </h4>

    <error-formatter/>

    <div class="input-wrapper">
      <div class="icon-input">
        <i class="icon email-icon">&nbsp;</i>
        <input
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
      :loading-text="$t('profile.se')"
      :text="$t('profile.svc')"
      :activate-btn="true"
    />

  </form>
</template>

<script>
  import {mapActions} from 'vuex'
  import validation from '~/mixin/validation'
  import AjaxButton from '~/components/AjaxButton'
  import ErrorFormatter from '~/components/ErrorFormatter'

  export default {
    name: "login",
    layout: "login-layout",
    middleware: ['common-middleware', 'non-logged-in'],
    data() {
      return {
        email: '',
        hasError: false,
        formSubmitting: false
      }
    },
    mixins: [validation],
    components: {
      AjaxButton,
      ErrorFormatter
    },
    watch: {},
    computed: {
      isInvalidEmail() {
        return (this.email && !this.isValidEmail(this.email))
      },
      isLengthInvalid() {
        return (this.password && !this.isValidLength(this.password))
      },
    },
    methods: {
      async checkForm() {
        this.hasError = false
        if (!this.email || this.isInvalidEmail) {
          this.hasError = true
          return false
        }

        this.formSubmitting = true
        try {
          const data = await this.setRequest({params: {email: this.email}, api: 'forgotPassword'})
          if (data) {
            this.$router.push(`/verify-code?email=${this.email}`)
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

