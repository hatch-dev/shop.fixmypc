<template>
  <div class="auth-shell">

    <auth-sidebar-slider />

    <main class="auth-panel">

      <div
        class="auth-panel-inner"
        :class="{ 'auth-panel-form': wideForm }"
      >

        <div class="auth-brand" :class="{
          'auth-brand-centered': $route.query?.step === 'success' || $route.query?.step === 'verified'
        }">
          <!--
            The uploaded logo can go missing from uploads/ while the setting
            still names it — a zip/restore loses the file, not the row — and
            the sign-in and registration screens then lead with a broken
            image icon. Falling back to the packaged mark keeps the page
            branded until someone re-uploads it.
          -->
          <nuxt-link to="/">
            <img
              class="auth-brand-logo"
              :src="brandLogo"
              :alt="$t('footer.siteLogo')"
              @error="onLogoError"
            >
          </nuxt-link>
        </div>

        <nuxt-link
          v-if="showBackToSignIn"
          to="/login"
          class="auth-back"
        >
          <span aria-hidden="true">&larr;</span> Back to sign in
        </nuxt-link>

        <h1
          v-if="pageTitle"
          class="auth-h1"
        >
          {{ pageTitle }}
        </h1>

        <p
          v-if="pageDescription"
          class="auth-lede"
        >
          {{ pageDescription }}
        </p>

        <Nuxt />

        <!-- Forgot-password has no footer link: "← Back to sign in" above the
             heading already covers it, as in the prototype. -->
        <p
          v-if="showFoot"
          class="auth-foot"
        >
          <template v-if="routeName === 'login'">
            New to FixMyPC?
            <nuxt-link
              to="/register"
              class="auth-foot-link"
            >
              Create an account
            </nuxt-link>
          </template>
          <template v-else-if="routeName === 'register'">
            Already registered?
            <nuxt-link
              to="/login"
              class="auth-foot-link"
            >
              Sign in
            </nuxt-link>
          </template>
        </p>

        <div class="auth-powered">
          <span>Powered by</span>
          <span class="auth-powered-logo">
            <img :src="require('~/assets/images/logo-excytech.png')" alt="Excytech Logo">
          </span>
        </div>

      </div>

    </main>

    <transition name="fade" mode="out-in">
      <toast-message
        v-if="toastMessageStatus"
        :is-error="toastError"
        @hide="hideToast"
        :message="toastMessage"
      />
    </transition>

  </div>

</template>

<script>
  import {mapState, mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import ToastMessage from "~/components/ToastMessage";
  import metaHelper from "~/mixin/metaHelper";
  import AuthSidebarSlider from '../components/AuthSidebarSlider.vue';

  export default {
    head() {
      return this.commonMeta({...this.site_setting, ...this.setting})
    },
    components: {ToastMessage, AuthSidebarSlider},
    mixins: [util, metaHelper],
    data() {
      return {
        // Set when the uploaded logo fails to load; see brandLogo().
        logoFailed: false
      }
    },
    computed: {
      pageTitle() {
        const path = this.$route.path
        const step = this.$route.query?.step

        if (path.includes('login')) {
          if (step === 'code') {
            return 'Sign in with a code'
          }
          if (step === '2fa') {
            return 'Enter your security code'
          }
          return 'Sign in to your account'
        }
        /*
         * Register is a wizard whose heading depends on the step and the
         * account type chosen, so that page renders its own heading and lede.
         */
        if (path.includes('register')) {
          return ''
        }
        if (path.includes('reset-password')) {
          return 'Set a new password'
        }
        if (path.includes('forgot-password')) {
          return 'Reset your password'
        }
      },
      pageDescription() {
        const path = this.$route.path
        const step = this.$route.query?.step

        if (step === 'success' || step === 'verified') {
          return ''
        }

        if (path.includes('login')) {
          if (step === 'code') return 'We’ll email you a 6-digit code. No password needed.'
          // The 2FA screen explains its own method, so no lede here.
          if (step === '2fa') return ''
          return 'Track repairs, reorder parts and check out faster.'
        }

        if (path.includes('register'))
          return ''

        if (path.includes('reset-password'))
          return 'Choose a password you don’t use anywhere else.'

        if (path.includes('forgot-password'))
          return `Enter your email and we’ll send a reset link valid for 60 minutes.`
      },
      /*
       * The prototype puts "← Back to sign in" above the heading on every
       * screen you can reach from sign-in. Not on the success screen, where
       * the page hands over its own next step.
       */
      /*
       * The sign-in-code screen has no footer link in the prototype — it is a
       * detour off sign-in, not a place to start a new account.
       */
      showFoot() {

        if (this.routeName === 'login') {
          return !['code', '2fa'].includes(this.$route.query?.step)
        }

        // Register carries its own footer, which changes per wizard step.
        return false
      },
      /*
       * The details step pairs fields two to a row, which needs more than the
       * 430px column. Only the inner column widens — the banner keeps the same
       * split it has on sign-in.
       */
      wideForm() {

        // Guest checkout pairs town/Eircode and country/county the same way.
        if (this.routeName === 'guest-checkout') {
          return true
        }

        return this.routeName === 'register'
          && this.$route.query?.step === 'details'
      },
      showBackToSignIn() {

        const step = this.$route.query?.step

        if (['forgot-password', 'reset-password'].includes(this.routeName)) {
          return true
        }

        if (this.routeName === 'login') {
          return step === 'code'
        }

        return false
      },
      googleLogin(){
        return this.setting?.google_login
      },
      facebookLogin(){
        return this.setting?.facebook_login
      },
      routeName() {
        return this.$route?.name?.split('___')[0] || 'error'
      },

      /* Swapped for the packaged mark once the uploaded file 404s. */
      brandLogo() {
        return this.logoFailed
          ? require('~/assets/images/logo-excytech.png')
          : this.imageURL({'image': this.site_setting?.header_logo})
      },

      ...mapGetters('language', ['currentLanguage']),
      ...mapState('common', ['site_setting', 'setting']),
      ...mapGetters('common', ['toastMessage', 'toastError', 'toastMessageStatus']),
    },
    methods: {
      async loginWith(service) {
        window.location.href = this.socialRedirect(service)
      },

      onLogoError() {
        this.logoFailed = true
      },

      ...mapActions('common', ['hideToast']),
    },
    beforeDestroy() {
      // Only the auth screens are viewport-locked; the shop needs to scroll.
      document.documentElement.classList.remove('auth-locked')
    },
    mounted() {
      document.documentElement.classList.add('auth-locked')
      document.body.classList.add(this.currentLanguage?.direction || 'ltr')


      if (this.site_setting?.primary_color) {
        document.documentElement.style.setProperty('--primary-color', this.site_setting.primary_color)
        document.documentElement.style.setProperty('--primary-hover-color', this.site_setting.primary_hover_color)
      }
    }
  }
</script>
<style>

/* Shared auth tokens, used by the layout and by every auth page. */
:root {
  --auth-ink: #16174A;
  --auth-ink-soft: #3A3C6E;
  --auth-brand: #2D2DA0;
  --auth-brand-50: #F1F1FC;
  --auth-go: #00A24A;
  --auth-go-700: #00873E;
  --auth-line: #E4E5F3;
  --auth-line-strong: #CFD1E8;
  --auth-muted: #6B7191;
  --auth-danger: #C8324B;
  --auth-wash: #F7F7FD;
  --auth-radius: 14px;
}

.auth-shell {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
    min-height: 100vh;
    min-height: 100dvh;
    background: #FFFFFF;
}

.auth-panel {
    display: flex;
    flex-direction: column;
    padding: 40px 48px;
    overflow-y: auto;
}

/* Side-by-side layout: pin the shell to the viewport so the page itself never
 * scrolls, and let the form column scroll inside its own column instead.
 * The lock class is added/removed by this layout so it can't leak to the shop. */
@media (min-width: 1025px) {

  html.auth-locked,
  html.auth-locked body {
      height: 100%;
      overflow: hidden;
  }

  .auth-shell {
      height: 100vh;
      height: 100dvh;
      /* The base rule's min-height would let the grid grow again. */
      min-height: 0;
      /*
       * Without this the single row is `auto`, which sizes to its content and
       * pushes straight past the container's height — the panel then has no
       * bounded height to scroll inside, so the overflow lands on the page.
       */
      grid-template-rows: minmax(0, 1fr);
  }

  /* And the items themselves have to be allowed to shrink below their content. */
  .auth-shell > .auth-stage,
  .auth-shell > .auth-panel {
      min-height: 0;
      height: 100%;
  }

  .auth-panel {
      overscroll-behavior: contain;
      /* When a step really is too long to fit, the bar should read as part of
       * the panel rather than as a page scrollbar hugging the window edge. */
      scrollbar-width: thin;
      scrollbar-color: var(--auth-line-strong) transparent;
  }

  .auth-panel::-webkit-scrollbar {
      width: 8px;
  }

  .auth-panel::-webkit-scrollbar-track {
      background: transparent;
  }

  .auth-panel::-webkit-scrollbar-thumb {
      background: var(--auth-line-strong);
      border-radius: 999px;
  }

  .auth-panel::-webkit-scrollbar-thumb:hover {
      background: var(--auth-muted);
  }
}

/* Anything shorter than a tall desktop monitor: tighten the vertical rhythm so
 * the column fits outright instead of scrolling. */

.auth-panel-inner {
    width: 100%;
    max-width: 430px;
    margin: auto;
    padding: 24px 0;
}

.auth-panel-form {
    max-width: 560px;
}


.auth-brand {
    display: flex;
    align-items: center;
    margin-bottom: 30px;
}

.auth-brand-centered {
    justify-content: center;
}

.auth-brand-logo {
    max-width: 180px;
    /* The uploaded logo is much taller than the prototype's 46px brand mark,
     * which cost ~100px at the top of every auth screen. */
    max-height: 64px;
    height: auto;
    object-fit: contain;
}

/* Also used by the sign-in-code screen's back button, which is a <button> —
 * hence the height/line-height guards against base.styl's blanket button rule. */
.auth-back {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: auto;
    line-height: 1.5;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--auth-muted);
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
    text-decoration: none;
    margin-bottom: 18px;
}

.auth-back:hover {
    color: var(--auth-brand);
    text-decoration: none;
}

.auth-h1 {
    font-size: 27px;
    line-height: 1.2;
    letter-spacing: -.02em;
    font-weight: 800;
    color: var(--auth-ink);
    margin: 0 0 8px;
}

.auth-lede {
    color: var(--auth-muted);
    margin: 0 0 26px;
    font-size: 14.5px;
}

/* ── shared auth form controls ── */
.auth-form-group {
    margin-bottom: 16px;
    position: relative;
}

.auth-label {
    display: block;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--auth-ink);
    margin-bottom: 7px;
}

.auth-label-required {
    color: var(--auth-danger);
    margin-left: 2px;
}

.auth-input-container {
    position: relative;
    width: 100%;
}

.auth-input {
    width: 100%;
    height: auto;
    padding: 14px 16px;
    background-color: #FFFFFF;
    border: 1px solid var(--auth-line-strong);
    border-radius: var(--auth-radius);
    font-size: 15px;
    font-weight: 400;
    color: var(--auth-ink);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.auth-input::placeholder {
    color: #9AA0BC;
    font-weight: 400;
    opacity: 1;
}

.auth-input:focus {
    border-color: var(--auth-brand);
    box-shadow: 0 0 0 4px rgba(45, 45, 160, .10);
}

.auth-input.invalid,
.auth-input.readonly-input.invalid {
    border-color: var(--auth-danger);
    box-shadow: 0 0 0 4px rgba(200, 50, 75, .09);
}

.auth-input.readonly-input {
    background-color: var(--auth-wash);
    color: var(--auth-muted);
}

/* a level of the address hierarchy that its parent has not opened yet */
.auth-input:disabled {
    background-color: var(--auth-wash);
    color: var(--auth-muted);
    cursor: not-allowed;
}

textarea.auth-input,
select.auth-input {
    height: auto;
}

.auth-error {
    display: block;
    color: var(--auth-danger);
    font-size: 12.5px;
    font-weight: 600;
    margin-top: 6px;
}

.auth-error-box {
    background: #FDF2F4;
    border: 1px solid #EFC3CC;
    color: #8E2035;
    padding: 14px 16px;
    border-radius: var(--auth-radius);
    margin-bottom: 20px;
    font-size: 13.5px;
}

.auth-options-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin: 4px 0 22px;
    font-size: 13.5px;
}

.auth-checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--auth-ink-soft);
    cursor: pointer;
    user-select: none;
}

.auth-checkbox-label input {
    display: none;
}

.auth-custom-checkbox {
    width: 18px;
    height: 18px;
    flex: 0 0 auto;
    border: 1px solid var(--auth-line-strong);
    border-radius: 5px;
    display: inline-block;
    position: relative;
    background: #fff;
    transition: all .2s ease;
}

.auth-checkbox-label input:checked + .auth-custom-checkbox {
    background-color: var(--auth-brand);
    border-color: var(--auth-brand);
}

.auth-checkbox-label input:checked + .auth-custom-checkbox::after {
    content: '';
    position: absolute;
    left: 5px;
    top: 2px;
    width: 5px;
    height: 9px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.auth-forgot-link {
    color: var(--auth-brand);
    font-weight: 600;
    text-decoration: none;
}

.auth-forgot-link:hover {
    text-decoration: underline;
}

.auth-btn-primary {
    width: 100%;
    height: auto;
    /* base.styl sets line-height:48px on every button, which would stack on top
     * of the padding and make this pill ~78px tall. */
    line-height: 1.5;
    padding: 15px 20px;
    background-color: var(--auth-go);
    color: #FFFFFF;
    font-size: 15.5px;
    font-weight: 700;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    transition: transform .12s ease, background .15s;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: none;
}

.auth-btn-primary:hover {
    background-color: var(--auth-go-700);
    transform: translateY(-1px);
}

/* base.styl and website-style.css both restyle `button:active`, which beats a
 * single class and strips the fill mid-press. Pin it back. */
.auth-btn-primary:active,
.auth-btn-primary:focus {
    background: var(--auth-go-700);
    background-color: var(--auth-go-700);
    box-shadow: none;
    transform: translateY(0);
}

.auth-btn-primary[disabled] {
    opacity: .55;
    cursor: not-allowed;
    transform: none;
}

.auth-btn-ghost {
    width: 100%;
    height: auto;
    line-height: 1.5;
    padding: 15px 20px;
    background: #fff;
    color: var(--auth-ink);
    border: 1px solid var(--auth-line-strong);
    border-radius: 999px;
    font-size: 15.5px;
    font-weight: 700;
    cursor: pointer;
    transition: border-color .15s, color .15s, background .15s;
}

.auth-btn-ghost:hover {
    border-color: var(--auth-brand);
    color: var(--auth-brand);
    background: var(--auth-brand-50);
}

.auth-btn-ghost:active,
.auth-btn-ghost:focus {
    background: #fff;
    box-shadow: none;
}

.auth-btn-ghost:active {
    border-color: var(--auth-brand);
    color: var(--auth-brand);
    background: var(--auth-brand-50);
}

.auth-btn-ghost[disabled] {
    opacity: .55;
    cursor: not-allowed;
}

.auth-divider {
    display: flex;
    align-items: center;
    text-align: center;
    gap: 14px;
    margin: 22px 0;
    color: var(--auth-muted);
    font-size: 12.5px;
    font-weight: 600;
}

.auth-divider::before,
.auth-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--auth-line);
}

/*
 * Shared across the auth pages. These used to live in register.vue's style
 * block, which meant a direct load of another auth page (a reset link from an
 * email, say) rendered notes and hints completely unstyled.
 */
.auth-hint {
    font-size: 12.5px;
    color: var(--auth-muted);
    margin: 6px 0 0;
    display: block;
    font-weight: 400;
    line-height: 1.45;
}

/*
 * Something is worth querying but is not being refused - a postcode that
 * disagrees with the county picked above it, say. Sits between the plain hint
 * and the red error, and never blocks a submit.
 */
.auth-warning {
    font-size: 12.5px;
    color: #a16207;
    margin: 6px 0 0;
    display: block;
    font-weight: 400;
    line-height: 1.45;
}

.auth-note {
    border: 1px solid var(--auth-line);
    background: var(--auth-wash);
    border-radius: var(--auth-radius);
    padding: 13px 15px;
    font-size: 13.5px;
    line-height: 1.5;
    color: var(--auth-ink-soft);
    margin: 0 0 24px;
}

.auth-note b {
    color: var(--auth-ink);
    font-weight: 700;
}

.auth-note-go {
    background: #F0FBF4;
    border-color: #C6EBD4;
    color: #12603A;
}

.auth-note-go b {
    color: #0B6B3A;
}

.auth-note-warn {
    background: #FFF8EC;
    border-color: #F3DFBB;
    color: #7A4C00;
}

.auth-note-warn b {
    color: #7A4C00;
}

/* password strength meter */
.auth-meter {
    display: flex;
    gap: 5px;
    margin-top: 9px;
}

.auth-meter i {
    height: 4px;
    flex: 1;
    border-radius: 999px;
    background: var(--auth-line);
    transition: background .2s;
}

.auth-meter.s1 i:nth-child(1) { background: var(--auth-danger); }
.auth-meter.s2 i:nth-child(-n+2) { background: #B26A00; }
.auth-meter.s3 i:nth-child(-n+3) { background: #3E9E5F; }
.auth-meter.s4 i { background: var(--auth-go); }

.auth-btn-stack {
    display: grid;
    gap: 12px;
    margin-top: 12px;
}

.thank-you-content {
    padding: 20px 0 30px;
    text-align: center;
}

.thank-you-content h2 {
    font-size: 27px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--auth-ink);
    margin-top: 24px;
    margin-bottom: 14px;
}

.thank-you-content p {
    font-size: 15px;
    max-width: 44ch;
    margin: auto;
    color: var(--auth-muted);
    line-height: 1.55;
}

.auth-foot {
    text-align: center;
    font-size: 14px;
    color: var(--auth-muted);
    margin-top: 24px;
}

.auth-foot-link {
    color: var(--auth-brand);
    font-weight: 700;
    text-decoration: none;
}

.auth-foot-link:hover {
    text-decoration: underline;
}

.auth-powered {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #9AA0BC;
    font-size: 12.5px;
    margin-top: 34px;
}

.auth-powered-logo {
    display: flex;
    align-items: center;
    height: 20px;
}

/* Placed after the base rules above so these actually win: same specificity,
 * so source order decides. */
@media (min-width: 1025px) and (max-height: 1100px) {

  .auth-panel {
      padding: 24px 48px;
  }

  .auth-panel-inner {
      padding: 8px 0;
  }

  .auth-brand {
      margin-bottom: 18px;
  }

  .auth-brand-logo {
      max-height: 50px;
  }

  .auth-h1 {
      font-size: 25px;
  }

  .auth-lede {
      margin-bottom: 18px;
  }

  .auth-steps {
      margin-bottom: 16px;
  }

  .auth-back {
      margin-bottom: 22px;
  }

  .auth-foot {
      margin-top: 16px;
  }

  .auth-powered {
      margin-top: 18px;
  }

  /* Sign-in was 4px over at 1366x768; this clears it with room to spare. */
  .auth-divider {
      margin: 18px 0;
  }
}

@media (max-width: 1024px) {

  .auth-shell {
      grid-template-columns: 1fr;
  }

  /* Height is owned by AuthSidebarSlider, which knows what the banner copy
   * needs — setting it here too meant two rules fighting over the stage. */
  .auth-stage {
      order: -1;
  }

  .auth-panel {
      padding: 28px 24px 48px;
  }

  /* Keep the column readable on tablets rather than letting fields run the
   * full width of the screen. */
  .auth-panel-inner {
      max-width: 520px;
      padding: 0 0 8px;
  }

  .auth-h1 {
      font-size: 23px;
  }
}

@media (max-width: 640px) {

  .auth-panel {
      padding: 22px 18px 40px;
  }

  .auth-brand {
      margin-bottom: 22px;
  }

  .auth-h1 {
      font-size: 22px;
  }

  .auth-lede {
      font-size: 14px;
      margin-bottom: 22px;
  }

  /*
   * 16px is the threshold below which iOS zooms the page on focus, which then
   * leaves the layout scrolled sideways. Worth the extra pixel everywhere.
   */
  .auth-input {
      font-size: 16px;
      padding: 14px 16px;
  }

  .auth-btn-primary,
  .auth-btn-ghost {
      font-size: 15px;
      padding: 15px 18px;
  }

  /* Comfortable tap targets for the row of small controls. */
  .auth-options-row {
      gap: 10px;
  }

  .auth-checkbox-label,
  .auth-forgot-link {
      padding: 4px 0;
  }

  .auth-foot,
  .auth-powered {
      font-size: 13.5px;
  }
}

</style>
