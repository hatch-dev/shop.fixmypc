<template>

  <div>

    <!-- checking the link -->
    <div
      v-if="checking"
      class="auth-note"
    >
      {{ $t('ui.resetPasswordPage.checking') }}
    </div>

    <!-- link no good -->
    <template v-else-if="!tokenValid">

      <div class="auth-note auth-note-warn">
        {{ tokenError }}
      </div>

      <div class="auth-btn-stack">
        <nuxt-link
          to="/forgot-password"
          class="auth-btn-ghost auth-btn-link"
        >
          {{ $t('ui.resetPasswordPage.sendNewLink') }}
        </nuxt-link>
      </div>

    </template>

    <!-- done -->
    <template v-else-if="done">

      <div class="thank-you-content">
        <svg width="184" height="144" viewBox="0 0 184 144" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M58.2503 84.9997C52.4463 82.8113 39.6503 74.9468 34.8979 60.9962C33.1226 52.995 24.4627 34.9601 9.29225 38.0186C4.46096 38.9926 -2.82421 46.4956 3.35161 55.8674C7.85822 62.706 21.0093 71.3363 37.5609 51.1487L54.563 25.2988" stroke="#33319A" stroke-width="2" stroke-linecap="round" stroke-dasharray="5 5"/>
          <path d="M43.3818 18.9353L71.5384 1L64.4424 33.6079L57.6686 20.8631L43.3818 18.9353Z" stroke="#33319A" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M59.9221 17.6177L59.3586 18.4291" stroke="#33319A" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M71.1903 1.39331L61.6113 15.1847" stroke="#33319A" stroke-width="2" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="112.979" cy="89" r="55" fill="#33319A"/>
          <path d="M92.9795 92.4615L104.744 104L132.979 74" stroke="white" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="175.56" cy="128.58" r="6.0803" stroke="#33319A" stroke-width="3"/>
        </svg>
        <h2>{{ $t('ui.resetPasswordPage.doneTitle') }}</h2>
        <p>
          {{ $t('ui.resetPasswordPage.doneText') }}
        </p>
      </div>

      <nuxt-link
        to="/login"
        class="auth-btn-primary auth-btn-link"
      >
        {{ $t('ui.resetPasswordPage.signIn') }}
      </nuxt-link>

    </template>

    <!-- set a new password -->
    <form
      v-else
      @submit.prevent="submit"
    >

      <div
        v-if="errors.length"
        class="auth-error-box"
      >
        <div
          v-for="(value, index) in errors"
          :key="index"
        >
          {{ value }}
        </div>
      </div>

      <div class="auth-note auth-note-account">
        <span
          class="auth-note-ico"
          aria-hidden="true"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
        </span>
        <i18n
          path="ui.resetPasswordPage.settingFor"
          tag="span"
        >
          <template #email><b>{{ email }}</b></template>
        </i18n>
      </div>

      <div class="auth-form-group">

        <label class="auth-label">
          {{ $t('ui.resetPasswordPage.newPassword') }}
          <span class="auth-label-required">*</span>
        </label>

        <auth-password-field
          :value="password"
          :is-invalid="fieldBad('password')"
          @change="password = $event"
        />

        <div
          class="auth-meter"
          :class="`s${passwordScore}`"
        >
          <i /><i /><i /><i />
        </div>

        <p class="auth-hint">{{ passwordHint }}</p>

        <small
          v-if="fieldBad('password')"
          class="auth-error"
        >
          {{ $t('ui.resetPasswordPage.passwordRule') }}
        </small>

      </div>

      <div class="auth-form-group">

        <label class="auth-label">
          {{ $t('ui.resetPasswordPage.confirmPassword') }}
          <span class="auth-label-required">*</span>
        </label>

        <auth-password-field
          :value="confirmPassword"
          :is-invalid="fieldBad('confirm')"
          @change="confirmPassword = $event"
        />

        <small
          v-if="fieldBad('confirm')"
          class="auth-error"
        >
          {{ $t('ui.resetPasswordPage.passwordsMismatch') }}
        </small>

      </div>

      <ajax-button
        class="auth-btn-primary"
        :fetching-data="submitting"
        :loading-text="$t('ui.resetPasswordPage.saving')"
        :text="$t('ui.resetPasswordPage.save')"
      >
        {{ $t('ui.resetPasswordPage.save') }}
      </ajax-button>

    </form>

  </div>

</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import AuthPasswordField from '~/components/AuthPasswordField'
  import global from '~/mixin/global'

  export default {

    middleware: ['common-middleware', 'non-logged-in'],
    layout: "empty",

    components: {AjaxButton, AuthPasswordField},

    mixins: [global],

    data() {
      return {
        password: '',
        confirmPassword: '',
        errors: [],
        showErrors: false,
        submitting: false,
        checking: true,
        tokenValid: false,
        tokenError: this.$t('ui.resetPasswordPage.linkExpired'),
        done: false
      }
    },

    computed: {

      token() {
        return this.$route.query?.token || ''
      },

      email() {
        return this.$route.query?.email || ''
      },

      /* Same bands as the signup form, so the rules read consistently. */
      passwordScore() {

        const p = this.password || ''
        let score = 0

        if (p.length >= 10) score++
        if (/[A-Z]/.test(p) && /[a-z]/.test(p)) score++
        if (/\d/.test(p)) score++
        if (/[^A-Za-z0-9]/.test(p) && p.length >= 12) score++

        return score
      },

      passwordHint() {
        return [
          this.$t('ui.resetPasswordPage.strength.s0'),
          this.$t('ui.resetPasswordPage.strength.s1'),
          this.$t('ui.resetPasswordPage.strength.s2'),
          this.$t('ui.resetPasswordPage.strength.s3'),
          this.$t('ui.resetPasswordPage.strength.s4')
        ][this.passwordScore]
      },

      passwordStrongEnough() {
        const p = this.password || ''
        return p.length >= 10 && /[A-Z]/.test(p) && /\d/.test(p)
      },

      badFields() {

        const bad = {}

        if (!this.passwordStrongEnough) bad.password = true
        if (!this.confirmPassword || this.confirmPassword !== this.password) bad.confirm = true

        return bad
      },

      ...mapGetters('language', ['langCode'])
    },

    methods: {

      fieldBad(name) {
        return this.showErrors && !!this.badFields[name]
      },

      async checkToken() {

        if (!this.token || !this.email) {
          this.checking = false
          this.tokenValid = false
          this.tokenError = this.$t('ui.resetPasswordPage.linkIncomplete')
          return
        }

        try {

          const data = await this.checkResetToken({
            params: {token: this.token, email: this.email},
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.tokenValid = true
          } else {
            this.tokenValid = false
            this.tokenError = data?.message || this.tokenError
          }

        } catch (e) {
          this.tokenValid = false
        } finally {
          this.checking = false
        }
      },

      async submit() {

        this.showErrors = true

        if (Object.keys(this.badFields).length || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.resetPassword({
            params: {
              token: this.token,
              email: this.email,
              password: this.password
            },
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.done = true
          } else {
            this.errors = data?.data?.form || [data?.message]

            // An expired link mid-way should send them back for a new one.
            if (/expired|already been used/i.test(data?.message || '')) {
              this.tokenValid = false
              this.tokenError = data.message
            }
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },

      ...mapActions('user', ['checkResetToken', 'resetPassword'])
    },

    mounted() {
      this.checkToken()
    }
  }
</script>

<style>

/* Notes, hints and the strength meter come from the shared auth styles in
 * layouts/empty.vue. Only this page's own pieces live here. */

.auth-note-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 28px;
}

.auth-note-ico {
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--auth-brand-50, #F1F1FC);
    color: var(--auth-brand, #2D2DA0);
}

/* Matches the register form's field rhythm. */
.auth-form-group {
    margin-bottom: 20px;
}

.auth-btn-link {
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

</style>
