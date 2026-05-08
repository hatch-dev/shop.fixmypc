<template>
  <form
    ref="loginForm"
    @submit.prevent="checkForm()"
    :class="{'has-error': hasError}"
    class="login-form test"
  >
    <h4 class="mb-30 mb-sm-15">
      {{ $t('profile.wb') }}
    </h4>

    <error-formatter/>

    <div class="input-wrapper">
      <div class="icon-input">
        <i
          class="icon email-icon"
        />
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

    <div class="input-wrapper">
      <password-field
        :value="password"
        :is-invalid="isLengthInvalid"
        @change="password = $event"
      />
      <span
        class="error"
        v-if="!!!password && hasError"
      >
        {{ $t('category.req', { type: $t('user.pass')}) }}
      </span>
      <span
        class="error"
        v-else-if="isLengthInvalid && hasError"
      >
        {{ $t('user.inPass') }}
      </span>
    </div>
    <div class="sided mt-15">
      <label class="checkbox">
        <input
          type="checkbox"
          v-model="rememberToken"
        >
        {{ $t('profile.rm') }}
      </label>

      <nuxt-link
        to="/forgot-password"
        class="link"
      >
        {{ $t('profile.fp') }}
      </nuxt-link>
    </div>

    <ajax-button
      :activate-btn="true"
      class="mt-20 primary-btn"
      :fetching-data="formSubmitting"
      :loading-text="$t('dataPage.logging')"
      :text="$t('dataPage.sign')"
    />
    <client-only>
      <div
        v-if="isDemo"
      >
        <button
          @click.prevent="setCredentials(-1)"
          class="outline-btn block mtb-15 w-100"
        >
          {{ $t('profile.laa') }}
        </button>

        <button
          @click.prevent="setCredentials(1)"
          class="outline-btn block w-100"
        >
          {{ $t('profile.lav') }}
        </button>

      </div>
    </client-only>


  </form>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import validation from '~/mixin/validation'
  import AjaxButton from '~/components/AjaxButton'
  import PasswordField from '~/components/PasswordField'
  import ErrorFormatter from '~/components/ErrorFormatter'

  export default {
    name: "login",
    layout: "login-layout",
    middleware: ['common-middleware', 'non-logged-in'],
    data() {
      return {
        email: '',
        password: '',
        rememberToken: '',
        hasError: false,
        formSubmitting: false
      }
    },
    mixins: [util, validation],
    components: {
      AjaxButton,
      ErrorFormatter,
      PasswordField
    },
    watch: {},
    computed: {
      isDemo() {
        return process.env.isDemo
      },
      isInvalidEmail() {
        return (this.email && !this.isValidEmail(this.email))
      },
      isLengthInvalid() {
        return (this.password && !this.isValidLength(this.password))
      },
      ...mapGetters('language', ['defaultLanguage']),
      ...mapGetters('ui', ['rememberMe'])
    },
    methods: {
      setCredentials(data) {
        if (data < 0) {
          this.email = 'admin@mail.com'
          this.password = '123456'
        } else {
          this.email = 'vendor@mail.com'
          this.password = '123456'
        }
        this.checkForm()
      },

      async checkForm() {
        this.hasError = false
        if (!this.email || !this.password || this.isInvalidEmail || this.isLengthInvalid) {
          this.hasError = true
          return false
        }
        this.settingRemember(this.rememberToken)

        this.formSubmitting = true
        try {
          if (!(process.env.apiBase || '').trim()) {
            this.$axios.defaults.baseURL = window.location.origin + '/'
          }

          this.$auth.loginWith('local',
            {
              data: {
                remember_token: this.rememberToken,
                password: this.password,
                email: this.email
              }
            })
            .then(async res => {

              if(this.rememberToken) {
                this.$auth.$storage.setCookie('remember_expires', 7, { expires: 7 });
              }else {
                this.$auth.$storage.removeCookie('remember_expires');
              }

              const {data} = res

              if (data.status === 200) {
                const data = await this.getRequest({
                  params: {},
                  api: 'profile'
                });
                this.formSubmitting = false

                await this.settingSiteData(data)
                this.setProfile(data)

                if (data.languages.length) {
                  await this.getLangData({
                    i18n: this.$i18n,
                    token: null
                  })

                  if (this.defaultLanguage?.code) {
                    this.$i18n.locale = this.defaultLanguage?.code
                  }
                }

                this.hasError = false
                this.setErrors()

                if (this.$auth.$state.redirect) {
                  // If rediect to login page from page that is required authentication (auth midleware), go that page
                  this.$router.push(this.$auth.$state.redirect)
                } else {
                  // Otherwise, go to home page
                  this.$router.push('/')
                }

              } else {
                this.formSubmitting = false
                this.setErrors(data?.data)
              }
            })
            .catch(e => {
              this.formSubmitting = false

              this.setErrors({
                'form': [e.message]
              })
              console.error(e)
            })

        } catch (e) {
          this.formSubmitting = false
          return this.$nuxt.error(e)
        }
      },
      ...mapActions(['settingSiteData']),
      ...mapActions('admin', ['setProfile']),
      ...mapActions('common', ['getRequest']),
      ...mapActions('language', ['getLangData']),
      ...mapActions('ui', ['setErrors', 'settingRemember'])
    },
    mounted() {
      this.rememberToken = this.rememberMe === 'true' ? true : ''
      this.setErrors()
    }
  }
</script>

<style scoped>

</style>

