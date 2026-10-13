<template>

  <div>

    <!-- ─────────── ASK FOR THE ADDRESS ─────────── -->
    <form
      v-if="!sent"
      @submit.prevent="sendLink"
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

      <div class="auth-form-group">

        <label
          class="auth-label"
          for="fp-email"
        >
          Email address
          <span class="auth-label-required">*</span>
        </label>

        <input
          id="fp-email"
          v-model.trim="email"
          type="email"
          class="auth-input"
          :class="{ invalid: emailInvalid }"
          autocomplete="email"
          placeholder="you@example.ie"
        >

        <small
          v-if="emailInvalid"
          class="auth-error"
        >
          Enter a valid email address.
        </small>

      </div>

      <ajax-button
        class="auth-btn-primary"
        :fetching-data="submitting"
        :loading-text="'Sending...'"
        :text="'Send reset link'"
      >
        Send reset link
      </ajax-button>

    </form>

    <!-- ─────────── LINK ON ITS WAY ─────────── -->
    <div v-else>

      <div class="auth-note auth-note-go">
        We sent a reset link to <b>{{ email }}</b>. It’s valid for
        {{ expiryMinutes }} minutes and can only be used once.
      </div>

      <p class="auth-hint auth-hint-spaced">
        Nothing in your inbox after a minute? Check spam, or send it again.
      </p>

      <div class="auth-btn-stack">

        <button
          type="button"
          class="auth-btn-ghost"
          :disabled="submitting"
          @click="sendLink"
        >
          {{ submitting ? 'Sending...' : 'Send it again' }}
        </button>

        <button
          type="button"
          class="auth-btn-ghost"
          @click="useAnotherEmail"
        >
          Use a different email
        </button>

      </div>

    </div>

  </div>

</template>

<script>

  import validation from '~/mixin/validation'
  import AjaxButton from '~/components/AjaxButton'
  import {mapGetters, mapActions} from 'vuex'
  import global from '~/mixin/global'

  export default {

    middleware: ['common-middleware', 'non-logged-in'],
    layout: "empty",

    components: {AjaxButton},

    mixins: [validation, global],

    data() {
      return {
        email: '',
        errors: [],
        showErrors: false,
        submitting: false,
        sent: false,
        expiryMinutes: 60
      }
    },

    computed: {

      emailValid() {
        return !!this.email && this.isValidEmail(this.email)
      },

      emailInvalid() {
        return this.showErrors && !this.emailValid
      },

      ...mapGetters('language', ['langCode'])
    },

    methods: {

      useAnotherEmail() {
        this.sent = false
        this.errors = []
        this.showErrors = false
      },

      async sendLink() {

        this.showErrors = true

        if (!this.emailValid || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.forgotPassword({
            params: {email: this.email},
            lang: this.langCode
          })

          if (data?.status === 200) {
            this.showErrors = false
            this.expiryMinutes = data.data?.expires_in_minutes || 60
            this.sent = true
            this.setToastMessage(`Reset link sent to ${this.email}`)
          } else {
            this.errors = data?.data?.form || [data?.message]
          }

        } catch (e) {
          return this.$nuxt.error(e)
        } finally {
          this.submitting = false
        }
      },

      ...mapActions('user', ['forgotPassword']),
      ...mapActions('common', ['setToastMessage'])
    }
  }
</script>

<style>

.auth-hint-spaced {
    margin: 14px 0 20px;
}

</style>
