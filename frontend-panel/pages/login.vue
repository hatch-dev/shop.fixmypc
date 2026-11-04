<template>

  <div>

    <!-- ─────────── PASSWORD SIGN IN ─────────── -->
    <template v-if="screen === 'signin'">

      <form
        id="loginForm"
        @submit.prevent="formSubmit"
      >

        <!-- ERRORS -->
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

        <!-- EMAIL -->
        <div class="auth-form-group">

          <label
            class="auth-label"
            for="login-email"
          >

            {{ $t('ui.loginPage.emailLabel') }}

            <span class="auth-label-required">
              *
            </span>

          </label>

          <div class="auth-input-container">

            <input
              id="login-email"
              v-model.trim="email"
              class="auth-input"
              :class="{ invalid: emailInvalid }"
              type="email"
              autocomplete="username"
              placeholder="you@example.ie"
            >

          </div>

          <small
            v-if="emailInvalid"
            class="auth-error"
          >
            {{ $t('ui.loginPage.emailOnAccount') }}
          </small>

        </div>

        <!-- PASSWORD -->
        <div class="auth-form-group">

          <label class="auth-label">

            {{ $t('ui.loginPage.passwordLabel') }}

            <span class="auth-label-required">
              *
            </span>

          </label>

          <auth-password-field
            :value="password"
            :is-invalid="passwordInvalid"
            @change="password = $event"
            class="auth-password-field"
          />

          <small
            v-if="passwordInvalid"
            class="auth-error"
          >
            {{ $t('ui.loginPage.passwordRequired') }}
          </small>

        </div>

        <!-- OPTIONS -->
        <div class="auth-options-row">

          <label class="auth-checkbox-label">

            <input
              v-model="rememberMe"
              type="checkbox"
            >

            <span class="auth-custom-checkbox" />

            {{ $t('ui.loginPage.keepSignedIn') }}

          </label>

          <nuxt-link
            to="/forgot-password"
            class="auth-forgot-link"
          >
            {{ $t('ui.loginPage.forgotPassword') }}
          </nuxt-link>

        </div>

        <!-- LOGIN BUTTON -->
        <ajax-button
          class="auth-btn-primary"
          :fetching-data="formSubmitting"
          :loading-text="$t('login.loggingIn')"
          :text="$t('ui.loginPage.signIn')"
        >
          {{ $t('ui.loginPage.signIn') }}
        </ajax-button>

      </form>

      <div class="auth-divider">
        {{ $t('ui.loginPage.or') }}
      </div>

      <div class="auth-btn-stack">

        <button
          type="button"
          class="auth-btn-ghost"
          @click="goToScreen('code')"
        >
          {{ $t('ui.loginPage.emailMeCode') }}
        </button>

        <button
          type="button"
          class="auth-btn-ghost"
          @click="continueAsGuest"
        >
          {{ $t('ui.loginPage.guestCheckout') }}
        </button>

      </div>

    </template>

    <!-- ─────────── TWO-FACTOR CHALLENGE ─────────── -->
    <template v-else-if="screen === '2fa'">

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

      <div
        v-if="codeAccepted"
        class="auth-note auth-note-go"
      >
        {{ successMessage }}
      </div>

      <p
        v-else
        class="auth-lede"
      >
        {{ challengeMethod === 'sms'
          ? $t('ui.loginPage.smsCodeSent')
          : $t('ui.loginPage.appCodePrompt') }}
      </p>

      <form @submit.prevent="verifyTwoFactor">

        <div class="auth-form-group">

          <label class="auth-label">
            {{ $t('ui.loginPage.yourCode') }}
            <span class="auth-label-required">*</span>
          </label>

          <otp-input
            ref="otp"
            :value="code"
            :is-invalid="hasCodeError && !codeAccepted && code.length === 6"
            :is-valid="codeAccepted"
            @change="onCodeChange"
            @complete="verifyTwoFactor"
          />

        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="verifyingCode"
          :loading-text="$t('login.loggingIn')"
          :text="$t('ui.loginPage.signIn')"
        >
          {{ $t('ui.loginPage.signIn') }}
        </ajax-button>

      </form>

      <div class="auth-btn-stack auth-btn-stack-spaced">

        <button
          type="button"
          class="auth-btn-ghost"
          @click="useRecoveryCode = !useRecoveryCode"
        >
          {{ useRecoveryCode ? $t('ui.loginPage.useSixDigitCode') : $t('ui.loginPage.useRecoveryCode') }}
        </button>

        <button
          type="button"
          class="auth-btn-ghost"
          @click="goToScreen('signin')"
        >
          {{ $t('ui.loginPage.cancel') }}
        </button>

      </div>

      <div
        v-if="useRecoveryCode"
        class="auth-form-group auth-recovery"
      >
        <label class="auth-label" for="recovery-code">
          {{ $t('ui.loginPage.recoveryCode') }}
        </label>
        <input
          id="recovery-code"
          v-model.trim="code"
          class="auth-input"
          placeholder="ABCD-1234"
          autocomplete="one-time-code"
        >
        <p class="auth-hint">
          {{ $t('ui.loginPage.recoveryHint') }}
        </p>
      </div>

    </template>

    <!-- ─────────── EMAIL NOT CONFIRMED YET ─────────── -->
    <!-- Reached from the password step when the sign-up was never confirmed. -->
    <template v-else-if="screen === 'verify'">

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

      <div
        v-if="codeAccepted"
        class="auth-note auth-note-go"
      >
        {{ successMessage }}
      </div>

      <i18n
        v-else-if="!verifyCodeSent"
        path="ui.loginPage.verifyPendingLede"
        tag="p"
        class="auth-lede"
      >
        <template #email><b>{{ email }}</b></template>
      </i18n>

      <div
        v-else
        class="auth-note auth-note-go"
      >
        <i18n
          :path="verifyCodeSentNow ? 'ui.loginPage.verifyCodeSentNote' : 'ui.loginPage.verifyEnterCodeNote'"
          tag="span"
        >
          <template #email><b>{{ email }}</b></template>
          <template #minutes>{{ verifyExpiryMinutes }}</template>
        </i18n>
      </div>

      <template v-if="!verifyCodeSent">
        <ajax-button
          class="auth-btn-primary"
          type="button"
          :fetching-data="sendingCode"
          :loading-text="$t('ui.loginPage.sending')"
          :text="$t('ui.loginPage.emailMeNewCode')"
          @clicked="sendVerificationCode"
        />

        <!-- The code from the sign-up email may well still be good. -->
        <div class="auth-btn-stack auth-btn-stack-spaced">
          <button
            type="button"
            class="auth-btn-ghost"
            @click="enterExistingCode"
          >
            {{ $t('ui.loginPage.haveCodeAlready') }}
          </button>
        </div>
      </template>


      <form
        v-else
        @submit.prevent="confirmEmail"
      >

        <div class="auth-form-group">

          <label class="auth-label">
            {{ $t('ui.loginPage.enterYourCode') }}
            <span class="auth-label-required">*</span>
          </label>

          <otp-input
            ref="otp"
            :value="code"
            :is-invalid="hasCodeError && !codeAccepted && code.length === 6"
            :is-valid="codeAccepted"
            @change="onCodeChange"
            @complete="confirmEmail"
          />

        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="verifyingCode"
          :loading-text="$t('login.loggingIn')"
          :text="$t('ui.loginPage.confirmAndSignIn')"
        >
          {{ $t('ui.loginPage.confirmAndSignIn') }}
        </ajax-button>

        <div class="auth-btn-stack auth-btn-stack-spaced">
          <button
            type="button"
            class="auth-btn-ghost"
            :disabled="sendingCode"
            @click="sendVerificationCode"
          >
            {{ sendingCode ? $t('ui.loginPage.sending') : $t('ui.loginPage.sendNewCode') }}
          </button>
        </div>

      </form>

    </template>

    <!-- ─────────── EMAIL SIGN-IN CODE ─────────── -->
    <template v-else>

      <!-- "← Back to sign in" is rendered by the layout, above the heading. -->

      <!-- ERRORS -->
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

      <!-- STEP 1 · WHERE TO SEND IT -->
      <form
        v-if="!codeSent"
        @submit.prevent="sendCode"
      >

        <div class="auth-form-group">

          <label
            class="auth-label"
            for="code-email"
          >

            {{ $t('ui.loginPage.emailLabel') }}

            <span class="auth-label-required">
              *
            </span>

          </label>

          <div class="auth-input-container">

            <input
              id="code-email"
              v-model.trim="email"
              class="auth-input"
              :class="{ invalid: !emailValid && hasCodeError }"
              type="email"
              autocomplete="email"
              placeholder="you@example.ie"
            >

          </div>

          <small
            v-if="!emailValid && hasCodeError"
            class="auth-error"
          >
            {{ $t('ui.loginPage.emailInvalid') }}
          </small>

        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="sendingCode"
          :loading-text="$t('ui.loginPage.sending')"
          :text="$t('ui.loginPage.sendMyCode')"
        >
          {{ $t('ui.loginPage.sendMyCode') }}
        </ajax-button>

      </form>

      <!-- STEP 2 · ENTER IT -->
      <form
        v-else
        @submit.prevent="verifyCode"
      >

        <div class="auth-note auth-note-go">
          <template v-if="codeAccepted">{{ successMessage }}</template>
          <i18n
            v-else
            path="ui.loginPage.codeSentNote"
            tag="span"
          >
            <template #email><b>{{ email }}</b></template>
            <template #minutes>{{ codeExpiryMinutes }}</template>
          </i18n>
        </div>

        <div class="auth-form-group">

          <label class="auth-label">

            {{ $t('ui.loginPage.enterYourCode') }}

            <span class="auth-label-required">
              *
            </span>

          </label>

          <otp-input
            ref="otp"
            :value="code"
            :is-invalid="hasCodeError && !codeAccepted && code.length === 6"
            :is-valid="codeAccepted"
            @change="onCodeChange"
            @complete="verifyCode"
          />

        </div>

        <ajax-button
          class="auth-btn-primary"
          :fetching-data="verifyingCode"
          :loading-text="$t('login.loggingIn')"
          :text="$t('ui.loginPage.signIn')"
        >
          {{ $t('ui.loginPage.signIn') }}
        </ajax-button>

        <div class="auth-btn-stack auth-btn-stack-spaced">

          <button
            type="button"
            class="auth-btn-ghost"
            :disabled="sendingCode"
            @click="sendCode"
          >
            {{ sendingCode ? $t('ui.loginPage.sending') : $t('ui.loginPage.sendNewCode') }}
          </button>

          <button
            type="button"
            class="auth-btn-ghost"
            @click="useAnotherEmail"
          >
            {{ $t('ui.loginPage.useDifferentEmail') }}
          </button>

        </div>

      </form>

    </template>

  </div>

</template>
<script>

  import {mapActions, mapGetters} from 'vuex'
  import validation from '~/mixin/validation'
  import util from '~/mixin/util'
  import AjaxButton from '~/components/AjaxButton'
  import AuthPasswordField from '~/components/AuthPasswordField'
  import OtpInput from '~/components/OtpInput'
  import global from '~/mixin/global'
  import configJson from '~/config.json'

  export default {
    middleware: ['common-middleware', 'non-logged-in'],
    layout: "empty",
    data() {
      return {
        email: '',
        password: '',
        code: '',
        hasFormError: false,
        hasCodeError: false,
        errors: [],
        formSubmitting: false,
        sendingCode: false,
        verifyingCode: false,
        codeSent: false,
        codeExpiryMinutes: 10,
        challenge: '',
        challengeMethod: 'app',
        useRecoveryCode: false,
        codeAccepted: false,
        successMessage: '',
        /* Long enough to register the green state, short enough not to nag. */
        successPause: 1600,
        rememberMe: false,
        // Set by the password step when the sign-up was never confirmed.
        pendingVerification: false,
        // the code boxes are showing, and whether a code was sent just now
        verifyCodeSent: false,
        verifyCodeSentNow: false,
        verifyExpiryMinutes: 30
      }
    },
    components: {
      AjaxButton,
      AuthPasswordField,
      OtpInput
    },
    mixins: [validation, util, global],
    computed: {
      screen() {

        const step = this.$route.query?.step

        if (step === 'code') return 'code'

        /*
         * The 2FA screen is meaningless without a challenge from the password
         * step. A stale ?step=2fa — a bookmark, the back button, or the logout
         * redirect carrying the query over — would otherwise show a code form
         * that can never succeed.
         */
        if (step === '2fa' && this.challenge) return '2fa'

        // Same reasoning: only meaningful straight after the password step said so.
        if (step === 'verify' && this.pendingVerification) return 'verify'

        return 'signin'
      },
      emailValid() {
        return !!this.email && this.isValidEmail(this.email)
      },
      /*
       * Sign-in only checks the password is present. Length and complexity are
       * registration rules — enforcing them here would lock out an existing
       * account whose password predates them, before the server ever sees it.
       */
      passwordFilled() {
        return !!this.password
      },
      emailInvalid() {
        return this.hasFormError && !this.emailValid
      },
      passwordInvalid() {
        return this.hasFormError && !this.passwordFilled
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['setting']),
    },
    watch: {
      /*
       * The screen can change from the buttons here or from the layout's
       * "Back to sign in" link, so reset centrally on the change itself.
       */
      screen(now, before) {
        this.errors = []
        this.hasFormError = false
        this.hasCodeError = false
        this.codeSent = false
        this.code = ''
        this.useRecoveryCode = false
        this.codeAccepted = false
        this.successMessage = ''

        // Leaving the challenge abandons it; a new sign-in issues a fresh one.
        if (before === '2fa') {
          this.challenge = ''
        }

        if (before === 'verify') {
          this.pendingVerification = false
          this.verifyCodeSent = false
          this.verifyCodeSentNow = false
        }
      }
    },
    methods: {
      /*
       * Guest checkout is a setting the shop can turn off. With it off there is
       * no guest screen to send anyone to, so the button falls back to the shop
       * rather than to a page that would only bounce them back here.
       */
      continueAsGuest() {
        this.$router.push(
          this.setting?.guest_checkout ? '/guest-checkout' : '/'
        )
      },
      goToScreen(screen) {

        const query = screen === 'signin' ? {} : {step: screen}

        this.$router.replace({path: this.$route.path, query})
      },
      useAnotherEmail() {
        this.codeSent = false
        this.code = ''
        this.errors = []
        this.hasCodeError = false
      },
      /*
       * A rejected code is cleared and the caret sent back to the first box,
       * so retyping needs no clicking.
       */
      resetCode() {

        this.code = ''

        this.$nextTick(() => {
          if (this.$refs.otp && this.$refs.otp.focusFirst) {
            this.$refs.otp.focusFirst()
          }
        })
      },

      onCodeChange(code) {

        // Locked once accepted — the redirect is already on its way.
        if (this.codeAccepted) {
          return
        }

        this.code = code

        if (code.length < 6) {
          this.hasCodeError = false
          this.errors = []
        }
      },
      async sendCode() {

        this.hasCodeError = true

        if (!this.emailValid) {
          return
        }

        this.errors = []
        this.sendingCode = true

        try {

          const data = await this.sendLoginCode({
            params: {email: this.email},
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.hasCodeError = false
            this.codeSent = true
            this.code = ''
            this.codeExpiryMinutes = data.data?.expires_in_minutes || 10
            this.setToastMessage(this.$t('ui.loginPage.toastCodeSent', { email: this.email }))
          } else {
            this.errors = data?.data?.form || [data?.message]
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.sendingCode = false
        }
      },
      async verifyCode() {

        this.hasCodeError = true

        if (this.code.length !== 6 || this.verifyingCode || this.codeAccepted) {
          return
        }

        this.errors = []
        this.verifyingCode = true

        try {

          const data = await this.verifySignInCode({
            params: {
              user_token: await this.getUserToken(),
              email: this.email,
              code: this.code,
              // The choice made on the sign-in screen, if they ticked it
              // before asking for a code: it sets how long this sign-in lasts.
              remember_me: this.rememberMe
            },
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.hasCodeError = false
            this.errors = []
            await this.completeSignIn(data.data, this.$t('ui.loginPage.codeAccepted'))
          } else {
            this.errors = data?.data?.form || [data?.message]
            this.resetCode()
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.verifyingCode = false
        }
      },
      async formSubmit() {

        // Mark every bad field at once, the way the prototype does, rather
        // than stopping at the first one.
        this.hasFormError = true

        /*
         * formSubmitting also gates a second submit while the first is still
         * in flight. The button shows a spinner but stays clickable, and each
         * submit carries the guest token — the merge behind it is idempotent,
         * so the basket was never at risk, but two sessions were being created
         * for one sign-in.
         */
        if (!this.emailValid || !this.passwordFilled || this.formSubmitting) {
          return
        }

        this.errors = []
        this.formSubmitting = true

        try {

          if(!process.env.apiBase.trim()){
            this.$axios.defaults.baseURL = window.location.origin + '/'
          }

          const {data} = await this.$auth.loginWith('local',
            {
              data: {
                user_token: await this.getUserToken(),
                password: this.password,
                email: this.email,
                remember_me: this.rememberMe
              }
            })

          if (data?.status === 200) {

            /*
             * Password was right but the account has 2FA: no token yet, just a
             * challenge handle. Hand over to the code screen.
             */
            if (data.data?.two_factor_required) {
              this.hasFormError = false
              this.errors = []
              this.challenge = data.data.challenge
              this.challengeMethod = data.data.method
              this.code = ''
              return this.$router.replace({
                path: this.$route.path,
                query: {step: '2fa'}
              })
            }

            this.hasFormError = false
            this.setProfile(data.data?.user)
            localStorage.setItem('session_id', data.data.session_id);
            this.errors = []
          } else if (data?.data?.verification_pending) {
            /*
             * Right password, but the email was never confirmed. Only the
             * person who signed up gets this answer; offer them a new code.
             */
            this.hasFormError = false
            this.errors = []
            this.pendingVerification = true
            this.verifyExpiryMinutes = data.data.expires_in_minutes || 30
            return this.$router.replace({
              path: this.$route.path,
              query: {step: 'verify'}
            })
          } else {
            this.errors = data?.data?.form || [data?.message]
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.formSubmitting = false
        }
      },

      async verifyTwoFactor() {

        this.hasCodeError = true

        if (this.code.length < 6 || this.verifyingCode || this.codeAccepted) {
          return
        }

        this.errors = []
        this.verifyingCode = true

        try {

          const data = await this.verifyTwoFactorCode({
            params: {
              user_token: await this.getUserToken(),
              challenge: this.challenge,
              code: this.code,
              // Carried from the password step: the session is issued here.
              remember_me: this.rememberMe
            },
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.hasCodeError = false
            this.errors = []
            await this.completeSignIn(data.data, this.$t('ui.loginPage.codeAccepted'))
          } else {
            this.errors = data?.data?.form || [data?.message]
            this.resetCode()
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.verifyingCode = false
        }
      },

      async sendVerificationCode() {

        if (this.sendingCode) {
          return
        }

        this.errors = []
        this.sendingCode = true

        try {

          const data = await this.resendVerification({
            params: {email: this.email, password: this.password},
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.verifyCodeSent = true
            this.verifyCodeSentNow = true
            this.code = ''
            this.hasCodeError = false
            this.verifyExpiryMinutes = data.data?.expires_in_minutes || this.verifyExpiryMinutes
            this.setToastMessage(this.$t('ui.loginPage.toastNewCode', { email: this.email }))
          } else {
            this.errors = data?.data?.form || [data?.message]
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.sendingCode = false
        }
      },

      enterExistingCode() {
        this.errors = []
        this.code = ''
        this.hasCodeError = false
        this.verifyCodeSent = true
        this.verifyCodeSentNow = false
      },

      /*
       * Confirming the email signs them in, as it does at the end of
       * registration: through nuxt-auth, so the token lands where sign-in
       * puts it. The password goes with the code (UsersController::verify).
       */
      async confirmEmail() {

        this.hasCodeError = true

        if (this.code.length !== 6 || this.verifyingCode || this.codeAccepted) {
          return
        }

        this.errors = []
        this.verifyingCode = true

        try {

          const base = process.env.apiBase?.trim() ? process.env.apiBase : window.location.origin + '/'

          const {data} = await this.$auth.loginWith('local', {
            url: base + configJson.api.url + configJson.api.verify,
            data: {
              email: this.email,
              code: this.code,
              password: this.password,
              user_token: await this.getUserToken(),
              remember_me: this.rememberMe
            }
          })

          if (data?.status === 200) {
            this.hasCodeError = false
            this.codeAccepted = true
            this.successMessage = this.$t('ui.loginPage.emailConfirmed')
            this.setProfile(data.data?.user)
            try {
              localStorage.setItem('session_id', data.data.session_id)
            } catch (e) {
              // Storage unavailable; the session still works for this tab.
            }
          } else {
            this.errors = data?.data?.form || [data?.message]
            this.resetCode()
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.verifyingCode = false
        }
      },

      /*
       * Shared tail for both code screens: show the accepted state, hold it
       * long enough to read, then hand the token to nuxt-auth — which is what
       * triggers the redirect, so it has to come last.
       */
      async completeSignIn(payload, message) {

        this.codeAccepted = true
        this.successMessage = message

        this.setProfile(payload?.user)

        try {
          localStorage.setItem('session_id', payload.session_id)
        } catch (e) {
          // Storage unavailable; the session still works for this tab.
        }

        await new Promise(resolve => setTimeout(resolve, this.successPause))

        await this.$auth.setUserToken(payload.token)
      },
      ...mapActions('user', {
        setProfile: 'setProfile',
        getUserToken: 'getUserToken',
        sendLoginCode: 'sendLoginCode',
        resendVerification: 'resendVerification',
        verifyTwoFactorCode: 'verifyTwoFactor',
        verifySignInCode: 'verifyLoginCode'
      }),
      ...mapActions('common', ['setToastMessage']),
    },
    mounted() {

      /*
       * Arriving with a step we can't honour (no challenge in memory) — tidy
       * the address bar so it matches the form actually on screen.
       */
      if (this.$route.query?.step === '2fa' && !this.challenge) {
        this.$router.replace({path: this.$route.path, query: {}}).catch(() => {})
      }
    },
  }
</script>

<style scoped>

.auth-btn-stack {
    display: grid;
    gap: 12px;
}

.auth-btn-stack-spaced {
    margin-top: 12px;
}

.auth-note {
    border: 1px solid var(--auth-line, #E4E5F3);
    background: var(--auth-wash, #F7F7FD);
    border-radius: var(--auth-radius, 14px);
    padding: 14px 16px;
    font-size: 13.5px;
    color: var(--auth-ink-soft, #3A3C6E);
    margin-bottom: 20px;
}

.auth-note-go {
    background: #F0FBF4;
    border-color: #C6EBD4;
    color: #12603A;
}

.auth-recovery {
    margin-top: 18px;
}

.auth-hint {
    font-size: 12.5px;
    color: var(--auth-muted, #6B7191);
    margin: 6px 0 0;
    display: block;
}

</style>
