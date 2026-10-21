<template>

  <div class="twofa-panel">

    <div class="twofa-head">
      <h6 class="mb-1">{{ $t('ui.twoFactorPanel.title') }}</h6>
      <p class="small text-muted mb-0">
        {{ $t('ui.twoFactorPanel.lede') }}
      </p>
    </div>

    <div v-if="errors.length" class="twofa-errors">
      <div v-for="(message, index) in errors" :key="index">{{ message }}</div>
    </div>

    <!-- ── loading ── -->
    <div v-if="loading" class="twofa-loading">
      {{ $t('ui.twoFactorPanel.loading') }}
    </div>

    <!-- ══ idle: current state and what can be done about it ══ -->
    <template v-else-if="screen === 'idle'">

      <div class="twofa-status" :class="enabled ? 'is-on' : 'is-off'">

        <span class="twofa-status-ico" aria-hidden="true">
          <i class="fa-solid" :class="enabled ? 'fa-shield-halved' : 'fa-shield'" />
        </span>

        <span class="twofa-status-text">
          <strong>{{ enabled ? $t('ui.twoFactorPanel.isOn') : $t('ui.twoFactorPanel.isOff') }}</strong>
          <small>{{ statusLine }}</small>
        </span>

      </div>

      <!--
        Non-blocking on purpose. Someone who chose "Skip for now" at
        registration should be reminded, not nagged into a dead end.
      -->
      <p v-if="!enabled" class="twofa-hint">
        {{ $t('ui.twoFactorPanel.hint') }}
      </p>

      <!--
        The mobile number is a contact detail until someone proves they can
        receive a message at it. Nothing is gated on this; it is shown so the
        customer can see which of the two theirs is.
      -->
      <div v-if="hasPhone" class="twofa-phone">

        <span class="twofa-phone-text">
          <strong>{{ $t('ui.twoFactorPanel.mobileNumber') }}</strong>
          <small>
            {{ phoneVerified
              ? $t('ui.twoFactorPanel.phoneConfirmed', { phone: phoneHint })
              : $t('ui.twoFactorPanel.phoneNotConfirmed', { phone: phoneHint }) }}
          </small>
        </span>

        <button
          v-if="!phoneVerified && smsAvailable"
          type="button"
          class="twofa-link"
          :disabled="submitting"
          @click="startPhoneVerify"
        >
          {{ $t('ui.twoFactorPanel.confirmIt') }}
        </button>

      </div>

      <div class="twofa-actions">

        <template v-if="!enabled">

          <button
            type="button"
            class="tf-btn tf-btn-primary"
            @click="startAuthenticator"
          >
            {{ $t('ui.twoFactorPanel.setUpApp') }}
          </button>

          <!--
            Offered only when the provider can actually deliver. Otherwise
            the button leads to a text that never arrives.
          -->
          <button
            v-if="smsAvailable"
            type="button"
            class="tf-btn tf-btn-ghost"
            @click="startSms"
          >
            {{ $t('ui.twoFactorPanel.useSms') }}
          </button>

        </template>

        <template v-else>

          <button
            type="button"
            class="tf-btn tf-btn-ghost"
            @click="startAuthenticator"
          >
            {{ method === 'app' ? $t('ui.twoFactorPanel.newDevice') : $t('ui.twoFactorPanel.switchToApp') }}
          </button>

          <button
            v-if="smsAvailable && method !== 'sms'"
            type="button"
            class="tf-btn tf-btn-ghost"
            @click="startSms"
          >
            {{ $t('ui.twoFactorPanel.switchToSms') }}
          </button>

          <button
            type="button"
            class="tf-btn tf-btn-danger"
            @click="screen = 'disable'"
          >
            {{ $t('ui.twoFactorPanel.turnOff') }}
          </button>

        </template>

      </div>

    </template>

    <!-- ══ authenticator app setup ══ -->
    <template v-else-if="screen === 'app'">

      <button type="button" class="twofa-back" @click="cancel">
        <span aria-hidden="true">&larr;</span> {{ $t('ui.twoFactorPanel.back') }}
      </button>

      <p class="small mb-3">
        {{ $t('ui.twoFactorPanel.scanInstructions') }}
      </p>

      <div class="twofa-qr-row">

        <img
          v-if="qrDataUrl"
          class="twofa-qr"
          :src="qrDataUrl"
          :alt="$t('ui.twoFactorPanel.qrAlt')"
        >
        <div v-else class="twofa-qr twofa-qr-loading" />

        <div class="twofa-secret">
          <span class="small text-muted">{{ $t('ui.twoFactorPanel.cantScan') }}</span>
          <code>{{ secretDisplay }}</code>
          <button type="button" class="twofa-copy" @click="copySecret">
            {{ secretCopied ? $t('ui.twoFactorPanel.copied') : $t('ui.twoFactorPanel.copyKey') }}
          </button>
        </div>

      </div>

      <otp-input
        :value="code"
        :is-invalid="codeInvalid"
        @change="onCodeChange"
        @complete="confirmAuthenticator"
      />

      <ajax-button
        class="tf-btn tf-btn-primary mt-2"
        type="button"
        :fetching-data="submitting"
        :text="$t('ui.twoFactorPanel.turnOn')"
        @clicked="confirmAuthenticator"
      />

    </template>

    <!-- ══ SMS setup ══ -->
    <template v-else-if="screen === 'sms'">

      <button type="button" class="twofa-back" @click="cancel">
        <span aria-hidden="true">&larr;</span> {{ $t('ui.twoFactorPanel.back') }}
      </button>

      <i18n path="ui.twoFactorPanel.smsSent" tag="p" class="small mb-3">
        <template #phone><strong>{{ phoneHint }}</strong></template>
      </i18n>

      <otp-input
        :value="code"
        :is-invalid="codeInvalid"
        @change="onCodeChange"
        @complete="confirmSms"
      />

      <div class="twofa-actions mt-2">

        <ajax-button
          class="tf-btn tf-btn-primary"
          type="button"
          :fetching-data="submitting"
          :text="$t('ui.twoFactorPanel.turnOn')"
          @clicked="confirmSms"
        />

        <button
          type="button"
          class="twofa-link"
          :disabled="submitting || resendWait > 0"
          @click="startSms"
        >
          {{ resendWait > 0 ? $t('ui.twoFactorPanel.resendIn', { seconds: resendWait }) : $t('ui.twoFactorPanel.sendNewCode') }}
        </button>

      </div>

    </template>

    <!-- ══ confirming the mobile number ══ -->
    <template v-else-if="screen === 'phone'">

      <button type="button" class="twofa-back" @click="cancel">
        <span aria-hidden="true">&larr;</span> {{ $t('ui.twoFactorPanel.back') }}
      </button>

      <i18n path="ui.twoFactorPanel.phoneSent" tag="p" class="small mb-3">
        <template #phone><strong>{{ phoneHint }}</strong></template>
      </i18n>

      <otp-input
        :value="code"
        :is-invalid="codeInvalid"
        @change="onCodeChange"
        @complete="confirmPhone"
      />

      <div class="twofa-actions mt-2">

        <ajax-button
          class="tf-btn tf-btn-primary"
          type="button"
          :fetching-data="submitting"
          :text="$t('ui.twoFactorPanel.confirmNumber')"
          @clicked="confirmPhone"
        />

        <button
          type="button"
          class="twofa-link"
          :disabled="submitting || resendWait > 0"
          @click="startPhoneVerify"
        >
          {{ resendWait > 0 ? $t('ui.twoFactorPanel.resendIn', { seconds: resendWait }) : $t('ui.twoFactorPanel.sendNewCode') }}
        </button>

      </div>

    </template>

    <!-- ══ recovery codes — shown once ══ -->
    <template v-else-if="screen === 'recovery'">

      <div class="twofa-status is-on">
        <span class="twofa-status-ico" aria-hidden="true">
          <i class="fa-solid fa-shield-halved" />
        </span>
        <span class="twofa-status-text">
          <strong>{{ $t('ui.twoFactorPanel.isOn') }}</strong>
          <small>{{ $t('ui.twoFactorPanel.saveCodes') }}</small>
        </span>
      </div>

      <p class="small text-muted">
        {{ $t('ui.twoFactorPanel.codesHelp') }}
      </p>

      <ul class="twofa-recovery">
        <li v-for="item in recoveryCodes" :key="item">{{ item }}</li>
      </ul>

      <div class="twofa-actions">

        <button type="button" class="tf-btn tf-btn-ghost" @click="copyRecovery">
          {{ recoveryCopied ? $t('ui.twoFactorPanel.copied') : $t('ui.twoFactorPanel.copyCodes') }}
        </button>

        <button type="button" class="tf-btn tf-btn-primary" @click="doneWithRecovery">
          {{ $t('ui.twoFactorPanel.savedThem') }}
        </button>

      </div>

    </template>

    <!-- ══ turning it off ══ -->
    <template v-else-if="screen === 'disable'">

      <button type="button" class="twofa-back" @click="cancel">
        <span aria-hidden="true">&larr;</span> {{ $t('ui.twoFactorPanel.back') }}
      </button>

      <p class="small mb-3">
        {{ $t('ui.twoFactorPanel.disableHelp') }}
      </p>

      <password-field :value="password" @change="password = $event" />

      <ajax-button
        class="tf-btn tf-btn-danger mt-2"
        type="button"
        :fetching-data="submitting"
        :text="$t('ui.twoFactorPanel.turnOffTwoFactor')"
        @clicked="disable"
      />

    </template>

  </div>

</template>

<script>

  import {mapActions} from 'vuex'
  import OtpInput from '~/components/OtpInput'
  import AjaxButton from '~/components/AjaxButton'
  import PasswordField from '~/components/PasswordField'

  /**
   * Account -> Security. The same endpoints the registration wizard uses,
   * so an account that chose "Skip for now" can turn 2FA on here later
   * without going back through registration.
   */
  export default {

    name: 'TwoFactorPanel',

    components: {OtpInput, AjaxButton, PasswordField},

    data() {
      return {
        loading: true,
        screen: 'idle',
        enabled: false,
        method: null,
        phoneHint: null,
        smsAvailable: false,
        hasPhone: false,
        phoneVerified: false,
        secret: '',
        secretDisplay: '',
        qrDataUrl: '',
        code: '',
        codeInvalid: false,
        password: '',
        recoveryCodes: [],
        secretCopied: false,
        recoveryCopied: false,
        submitting: false,
        errors: [],
        // Stops a queue of texts building up behind an impatient click.
        resendWait: 0,
        resendTimer: null
      }
    },

    computed: {

      statusLine() {

        if (!this.enabled) {
          return this.$t('ui.twoFactorPanel.statusOff')
        }

        return this.method === 'sms'
          ? this.$t('ui.twoFactorPanel.statusSms', { phone: this.phoneHint || this.$t('ui.twoFactorPanel.yourMobile') })
          : this.$t('ui.twoFactorPanel.statusApp')
      }
    },

    methods: {

      async load() {

        this.loading = true

        try {

          const data = await this.twoFactorStatus()

          if (data?.status === 200) {
            this.enabled = !!data.data.enabled
            this.method = data.data.method
            this.phoneHint = data.data.phone_hint
            this.smsAvailable = data.data.sms_available === true
            this.hasPhone = data.data.has_phone === true
            this.phoneVerified = data.data.phone_verified === true
          }

        } catch (e) {
          // Leave the panel in its "off" default; the actions still work.
        } finally {
          this.loading = false

          /*
           * Announced on every load, not only on a change. The page header
           * carries its own "two-factor is on/off" badge, and without this
           * it would sit at its default — telling an account that has 2FA
           * enabled that it does not — until the customer touched something.
           */
          this.$emit('changed', {enabled: this.enabled, method: this.method})
        }
      },

      onCodeChange(value) {
        this.code = value
        this.codeInvalid = false
      },

      cancel() {
        this.screen = 'idle'
        this.reset()
      },

      reset() {
        this.code = ''
        this.codeInvalid = false
        this.password = ''
        this.errors = []
        this.qrDataUrl = ''
        this.secret = ''
        this.secretDisplay = ''
      },

      rejectCode() {
        this.codeInvalid = true
        this.code = ''
      },

      async startAuthenticator() {

        this.reset()
        this.screen = 'app'

        try {

          const data = await this.twoFactorSetup()

          if (data?.status === 200) {
            this.secret = data.data.secret
            this.secretDisplay = data.data.secret_display
            await this.renderQr(data.data.uri)
          } else {
            this.errors = [data?.message]
            this.screen = 'idle'
          }

        } catch (e) {
          this.errors = [this.$t('ui.twoFactorPanel.errors.startFailed')]
          this.screen = 'idle'
        }
      },

      async renderQr(uri) {

        try {
          // Imported on demand so it never runs during server-side render.
          const QRCode = await import('qrcode')

          this.qrDataUrl = await QRCode.toDataURL(uri, {
            width: 220,
            margin: 1,
            color: {dark: '#16174A', light: '#FFFFFF'}
          })

        } catch (e) {
          // The key is on screen for manual entry, so this is not fatal.
          this.qrDataUrl = ''
        }
      },

      async confirmAuthenticator() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.twoFactorConfirm({code: this.code})

          if (data?.status === 200) {
            this.afterEnable(data.data)
          } else {
            this.rejectCode()
            this.errors = [data?.message]
          }

        } catch (e) {
          this.rejectCode()
        } finally {
          this.submitting = false
        }
      },

      async startSms() {

        if (this.submitting || this.resendWait > 0) {
          return
        }

        this.reset()
        this.screen = 'sms'
        this.submitting = true

        try {

          const data = await this.twoFactorSendSms()

          if (data?.status === 200) {
            this.phoneHint = data.data.phone_hint
            this.startResendCountdown()
          } else {
            this.errors = [data?.message]
            this.screen = 'idle'
          }

        } catch (e) {
          this.errors = [this.$t('ui.twoFactorPanel.errors.smsUseApp')]
          this.screen = 'idle'
        } finally {
          this.submitting = false
        }
      },

      startResendCountdown() {

        this.resendWait = 30
        clearInterval(this.resendTimer)

        this.resendTimer = setInterval(() => {

          this.resendWait -= 1

          if (this.resendWait <= 0) {
            clearInterval(this.resendTimer)
          }
        }, 1000)
      },

      async confirmSms() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.twoFactorConfirmSms({code: this.code})

          if (data?.status === 200) {
            this.afterEnable(data.data)
          } else {
            this.rejectCode()
            this.errors = [data?.message]
          }

        } catch (e) {
          this.rejectCode()
        } finally {
          this.submitting = false
        }
      },

      async startPhoneVerify() {

        if (this.submitting || this.resendWait > 0) {
          return
        }

        this.reset()
        this.screen = 'phone'
        this.submitting = true

        try {

          const data = await this.phoneSendCode()

          if (data?.status === 200) {

            // Already proved on another device; nothing to type.
            if (data.data?.already_verified) {
              this.phoneVerified = true
              this.screen = 'idle'
              return
            }

            this.phoneHint = data.data.phone_hint
            this.startResendCountdown()

          } else {
            this.errors = [data?.message]
            this.screen = 'idle'
          }

        } catch (e) {
          this.errors = [this.$t('ui.twoFactorPanel.errors.smsLater')]
          this.screen = 'idle'
        } finally {
          this.submitting = false
        }
      },

      async confirmPhone() {

        if (this.code.length !== 6 || this.submitting) {
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.phoneVerify({code: this.code})

          if (data?.status === 200) {
            this.phoneVerified = true
            this.code = ''
            this.screen = 'idle'
            this.setToastMessage(this.$t('ui.twoFactorPanel.toast.phoneConfirmed'))
          } else {
            this.rejectCode()
            this.errors = [data?.message]
          }

        } catch (e) {
          this.rejectCode()
        } finally {
          this.submitting = false
        }
      },

      afterEnable(payload) {
        this.enabled = true
        this.method = payload.method
        this.recoveryCodes = payload.recovery_codes || []
        this.code = ''
        this.screen = 'recovery'
        this.$emit('changed', {enabled: true, method: payload.method})
      },

      doneWithRecovery() {
        this.recoveryCodes = []
        this.screen = 'idle'
        this.load()
      },

      async disable() {

        if (!this.password || this.submitting) {
          this.errors = [this.$t('ui.twoFactorPanel.errors.enterPassword')]
          return
        }

        this.errors = []
        this.submitting = true

        try {

          const data = await this.twoFactorDisable({password: this.password})

          if (data?.status === 200) {
            this.enabled = false
            this.method = null
            this.password = ''
            this.screen = 'idle'
            this.setToastMessage(this.$t('ui.twoFactorPanel.toast.off'))
            this.$emit('changed', {enabled: false, method: null})
          } else {
            this.errors = [data?.message || this.$t('ui.twoFactorPanel.errors.wrongPassword')]
          }

        } catch (e) {
          this.errors = [this.$t('ui.twoFactorPanel.errors.disableFailed')]
        } finally {
          this.submitting = false
        }
      },

      async copySecret() {
        await this.copyText(this.secret)
        this.secretCopied = true
        setTimeout(() => { this.secretCopied = false }, 2000)
      },

      async copyRecovery() {
        await this.copyText(this.recoveryCodes.join('\n'))
        this.recoveryCopied = true
        setTimeout(() => { this.recoveryCopied = false }, 2000)
      },

      async copyText(text) {
        try {
          await navigator.clipboard.writeText(text)
        } catch (e) {
          // Clipboard blocked; the value is on screen to copy by hand.
        }
      },

      ...mapActions('common', ['setToastMessage']),
      ...mapActions('user', [
        'twoFactorStatus',
        'twoFactorSetup',
        'twoFactorConfirm',
        'twoFactorSendSms',
        'twoFactorConfirmSms',
        'twoFactorDisable',
        'phoneSendCode',
        'phoneVerify'
      ])
    },

    mounted() {
      this.load()
    },

    beforeDestroy() {
      clearInterval(this.resendTimer)
    }
  }

</script>

<style scoped>

/*
 * Sits inside the Profile page's Security card, so it borrows that page's
 * scale and palette rather than Bootstrap's: #33319A primary going green on
 * hover, 8px controls, #E3E3EF lines.
 */
.twofa-panel {
    --tf-brand: #33319A;
    --tf-brand-hover: #05B942;
    --tf-ink: #130E2B;
    --tf-muted: #6B7280;
    --tf-line: #E3E3EF;
    --tf-soft: #F4F4FA;
    --tf-danger: #E5484D;

    font-family: inherit;
    border: 1px solid var(--tf-line);
    border-radius: 10px;
    padding: 16px;
    height: 100%;
}

.twofa-head {
    margin-bottom: 14px;
}

.twofa-head h6 {
    font-size: 16px;
    line-height: 1.4;
    font-weight: 600;
    color: var(--tf-ink);
    margin: 0 0 4px;
}

.twofa-head p {
    font-size: 13px;
    line-height: 1.5;
    color: var(--tf-muted);
    margin: 0;
}

/* ── buttons: one size, matching the account pages ── */

.tf-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 0 14px;
    border-radius: 8px;
    border: 1px solid transparent;
    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.2;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s ease, border-color .15s ease, color .15s ease;
}

.tf-btn-primary {
    background: var(--tf-brand);
    border-color: var(--tf-brand);
    color: #fff;
}

.tf-btn-primary:hover {
    background: var(--tf-brand-hover);
    border-color: var(--tf-brand-hover);
    color: #fff;
}

.tf-btn-ghost {
    background: #fff;
    border-color: var(--tf-line);
    color: var(--tf-ink);
}

.tf-btn-ghost:hover {
    background: #F5F5FF;
    border-color: var(--tf-brand);
    color: var(--tf-brand);
}

.tf-btn-danger {
    background: #fff;
    border-color: #F3C9CA;
    color: var(--tf-danger);
}

.tf-btn-danger:hover {
    background: #FDECEC;
    color: var(--tf-danger);
}

.tf-btn:focus-visible,
.twofa-link:focus-visible,
.twofa-copy:focus-visible,
.twofa-back:focus-visible {
    outline: 2px solid var(--tf-brand);
    outline-offset: 2px;
}

.tf-btn:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.mt-2 {
    margin-top: 8px;
}

.twofa-errors {
    background: #FEF2F2;
    border: 1px solid #FECACA;
    color: #B91C1C;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 13px;
    margin-bottom: 12px;
}

.twofa-loading {
    color: #6B7280;
    font-size: 14px;
    padding: 10px 0;
}

.twofa-status {
    display: flex;
    align-items: center;
    gap: 12px;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
}

.twofa-status.is-on {
    background: #ECFDF5;
    border: 1px solid #A7F3D0;
}

.twofa-status.is-off {
    background: #F9FAFB;
    border: 1px solid var(--tf-line);
}

.twofa-status-ico {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #FFFFFF;
    border: 1px solid var(--tf-line);
}

.twofa-status.is-on .twofa-status-ico {
    color: #047857;
    border-color: #A7F3D0;
}

.twofa-status.is-off .twofa-status-ico {
    color: #6B7280;
}

.twofa-status-text {
    display: flex;
    flex-direction: column;
    line-height: 1.35;
}

.twofa-status-text small {
    color: #6B7280;
}

.twofa-hint {
    font-size: 13px;
    color: #6B7280;
    margin-bottom: 12px;
}

.twofa-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.twofa-phone {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid var(--tf-line);
    padding-top: 12px;
    margin-bottom: 12px;
}

.twofa-phone-text {
    display: flex;
    flex-direction: column;
    line-height: 1.35;
}

.twofa-phone-text small {
    color: #6B7280;
}

.twofa-back {
    background: none;
    border: 0;
    padding: 0;
    color: #6B7280;
    font-size: 13px;
    margin-bottom: 10px;
}

/*
 * "Confirm it" and "Send a new code" are real actions, not prose links, so
 * they get a real target. At 13px with no padding the hit area was 15px
 * tall — measurably below a usable tap target on a phone.
 */
.twofa-link {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    background: none;
    border: 0;
    padding: 0 6px;
    margin: 0 -6px;
    border-radius: 6px;
    color: var(--tf-brand);
    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
}

.twofa-link:hover:not(:disabled) {
    text-decoration: underline;
}

.twofa-link:disabled {
    color: #9CA3AF;
    cursor: not-allowed;
}

/* Same reasoning for the two other bare-button links in this panel. */
.twofa-copy,
.twofa-back {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    border-radius: 6px;
    cursor: pointer;
}

.twofa-qr-row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.twofa-qr {
    width: 140px;
    height: 140px;
    border-radius: 8px;
    border: 1px solid var(--tf-line);
    background: #FFFFFF;
}

.twofa-qr-loading {
    background: var(--tf-soft);
}

.twofa-secret {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-start;
    min-width: 160px;
}

.twofa-secret code {
    font-size: 14px;
    letter-spacing: 1px;
    color: var(--tf-ink);
    word-break: break-all;
}

.twofa-copy {
    background: none;
    border: 0;
    padding: 0;
    color: var(--tf-brand);
    font-size: 13px;
}

.twofa-recovery {
    list-style: none;
    padding: 12px;
    margin: 0 0 12px;
    background: #F9FAFB;
    border: 1px solid var(--tf-line);
    border-radius: 8px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px 14px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 13px;
}

@media (max-width: 480px) {

    .twofa-recovery {
        grid-template-columns: 1fr;
    }

    .twofa-qr-row {
        justify-content: center;
    }
}

</style>
