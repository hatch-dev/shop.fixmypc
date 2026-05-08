<template>
  <div class="body-bg">
    <div class="container my-5">
      <div class="center-text mb-30 mb-sm-15">
        <nuxt-link to="/">
          <img
            class="mx-h-55x"
            :src="imageURL({'image': site_setting.header_logo})"
            :alt="$t('footer.siteLogo')"
            height="40"
            width="139"
          >
        </nuxt-link>
      </div>
      <div class="card-welcome-box text-center">
        <h5 class="fw-bold">
          {{ pageTitle }}
        </h5>
        <p class="text-muted small mt-2">
          {{ pageDescription }}
        </p>
        <button
          v-if="googleLogin"
          class="btn w-100 mb-2 social-btn mt-3"
          @click.prevent="loginWith('google')"
        >
          <div class="social-icon-text">
             <i 
              class="icon google-icon">
            </i>
            <span class="ms-2 pt-1">
              Continue with Google
            </span>
          </div>
        </button>
        <button
          v-if="facebookLogin"
          class="btn w-100 social-btn mb-3 mt-2"
          @click.prevent="loginWith('facebook')"
        >
          <div class="social-icon-text">
             <i 
              class="icon facebook-icon">
            </i>
            <span class="ms-2 pt-1">
              Continue with Facebook
            </span>
          </div>
        </button>
        <div class="divider-login">Or continue with email</div>
        <Nuxt />
        <p class="mt-20 small">
          {{ $t('empty.agreement') }}
          <nuxt-link :to="pageLink({slug: 'privacy-policy'})" class="link">
            {{ $t('empty.privacyPolicy') }}
          </nuxt-link>
        </p>
      </div>
      <div class="info-box mt-4">
        <h6 class="fw-bold mb-3">
          <i class="fa-solid fa-shield-halved"></i>
          Why Create an Account?
        </h6>
        <div class="row">
          <div class="col-md-6">
            <div class="d-flex align-items-start mb-3">
              <span class="check-icon me-2">✔</span>
              <div>
                <div class="text-track-order">Track Orders</div>
                <small class="list-subheading">Monitor your purchases</small>
              </div>
            </div>
            <div class="d-flex align-items-start">
              <span class="check-icon me-2">✔</span>
              <div>
                <div class="text-track-order">Wishlist</div>
                <small class="list-subheading">Save items for later</small>
              </div>
            </div>
          </div>
           <div class="col-md-6">
            <div class="d-flex align-items-start mb-3">
              <span class="check-icon me-2">✔</span>
              <div>
                <div class="text-track-order">Save Addresses</div>
                <small class="list-subheading">Quick checkout next time</small>
              </div>
            </div>
            <div class="d-flex align-items-start">
              <span class="check-icon me-2">✔</span>
              <div>
                <div class="text-track-order">Exclusive Offers</div>
                <small class="list-subheading">Members-only deals</small>
              </div>
            </div>
           </div>
        </div>
      </div>
      <div class="feature-box text-center">
        <div class="row">
          <div class="col-md-3">
            <div class="icon-circle"><i class="fa-solid fa-shield-halved"></i></div>
            <p class="mt-2 mb-1 feature-box-heading">Secure Payment</p>
            <small class="feature-box-subheading">Your payment info is protected</small>
          </div>

          <div class="col-md-3">
            <div class="icon-circle"><i class="fa-solid fa-truck"></i></div>
            <p class="mt-2 mb-1 feature-box-heading">Fast Delivery</p>
            <small class="feature-box-subheading">Quick and reliable shipping</small>
          </div>

          <div class="col-md-3">
            <div class="icon-circle"><i class="fa-solid fa-arrow-rotate-left"></i></div>
            <p class="mt-2 mb-1 feature-box-heading">Easy Returns</p>
            <small class="feature-box-subheading">30-day hassle-free returns</small>
          </div>

          <div class="col-md-3">
            <div class="icon-circle"><i class="fa-solid fa-headset"></i></div>
            <p class="mt-2 mb-1 feature-box-heading">24/7 Support</p>
            <small class="feature-box-subheading">Always here to help</small>
          </div>
        </div>
      </div>
    </div>
    <!-- <section class="section">
      <div class="container">

        <div class="center-text mb-30 mb-sm-15">
          <nuxt-link to="/">
            <img
              class="mx-h-55x"
              :src="imageURL({'image': site_setting.header_logo})"
              :alt="$t('footer.siteLogo')"
              height="40"
              width="139"
            >
          </nuxt-link>
        </div>

        <div class="user-form">
          <h4 class="mb-15 bold">
            {{ $t('empty.welcome', {name: site_setting.site_name }) }}
          </h4>
          <Nuxt/>
          <h5
            v-if="facebookLogin || googleLogin"
            class="bold mtb-15 center-text"
          >
            {{ $t('empty.or') }}
          </h5>
          <div class="flex flex-xs gap-10">

            <button
              v-if="facebookLogin"
              aria-label="submit"
              class="flex flex-1 primary-btn facebook-btn"
              @click.prevent="loginWith('facebook')"
            >
              <i
                class="icon facebook-icon"
              />
              {{ $t('empty.loginFacebook') }}
            </button>

            <button
              v-if="googleLogin"
              aria-label="submit"
              class="flex flex-1 primary-btn google-btn"
              @click.prevent="loginWith('google')"
            >
              <i
                class="icon google-icon"
              />
              {{ $t('empty.loginGoogle') }}
            </button>
          </div>
          <p class="mt-20 mt-sm-15 f-9 plr-40">
            {{ $t('empty.agreement') }}
            <nuxt-link
              :to="pageLink({slug: 'privacy-policy'})"
              class="link"
            >
              {{ $t('empty.privacyPolicy') }}
            </nuxt-link>
            .
          </p>
        </div>

        <p class="ptb-15 mt-30 mt-sm-15 b-t center-text">
          © {{ getYear }} - {{ site_setting.copyright_text }}
        </p>
      </div>
    </section> -->
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

  export default {
    head() {
      return this.commonMeta({...this.site_setting, ...this.setting})
    },
    components: {ToastMessage},
    mixins: [util, metaHelper],
    computed: {
      pageTitle() {
        const path = this.$route.path
        if (path.includes('login')) return 'Welcome Back'
        if (path.includes('register')) return 'Create Your Account'
        if (path.includes('forgot-password')) return 'Forgot Your Password'
      },
      pageDescription() {
        const path = this.$route.path
        if (path.includes('login'))
          return 'Sign in to continue to checkout or proceed as guest'

        if (path.includes('register'))
          return 'Join us to enjoy exclusive benefits and faster checkout'
        
        if (path.includes('forgot-password')) 
          return 'Enter your email address and we’ll send you a code to reset your password.'

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
      ...mapGetters('language', ['currentLanguage']),
      ...mapState('common', ['site_setting', 'setting']),
      ...mapGetters('common', ['toastMessage', 'toastError', 'toastMessageStatus']),
    },
    methods: {
      async loginWith(service) {
        window.location.href = this.socialRedirect(service)
      },
      ...mapActions('common', ['hideToast']),
    },
    mounted() {
      document.body.classList.add(this.currentLanguage?.direction || 'ltr')


      if (this.site_setting?.primary_color) {
        document.documentElement.style.setProperty('--primary-color', this.site_setting.primary_color)
        document.documentElement.style.setProperty('--primary-hover-color', this.site_setting.primary_hover_color)
      }
    }
  }
</script>
<style scoped>
.card-welcome-box {
    max-width: 1080px;
    margin: auto;
    background: #fff;
    padding: 30px 30px;
    border-radius: 20px;
    border: 1px solid #E3E3EF;
}

 .social-btn {
    background: #F3F5FC;
    border-radius: 8px;
    border: 1px solid #F3F5FC;
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
}
.social-btn:hover {
    border: 1px solid #33319A;
    
}

 .divider-login {
    text-align: center;
    position: relative;
    margin: 20px 0;
    font-size: 14px;
    color: #6B7280;
    font-weight: 400;
}

  .divider-login::before,
  .divider-login::after {
    content: "";
    position: absolute;
    width: 40%;
    height: 1px;
    background: #ddd;
    top: 50%;
  }

  .divider-login::before { left: 0; }
  .divider-login::after { right: 0; }

.info-box {
    background: #fff;
    border-radius: 20px;
    padding: 25px;
    width: 1080px;
    margin: 0 auto;
    border: 1px solid #E3E3EF;
}
 .feature-box {
    background: #fff;
    border-radius: 20px;
    padding: 40px 19px;
    margin-top: 25px;
    border: 1px solid #E3E3EF;
}
  .icon-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #eef0ff;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: auto;
    font-size: 20px;
  }

.feature-box-heading {
    color: #130E2B;
    font-size: 14px;
    font-weight: 500;
}
.feature-box-subheading {
    font-size: 15px;
    color: #6B7280;
    font-weight: 400;
}
.check-icon {
  color: #4b4bff;
  font-size: 16px;
  margin-top: 2px;
}
.text-track-order {
    font-size: 14px;
    font-weight: 400;
    color: #262626;
}
.list-subheading {
    font-size: 12px;
    font-weight: 400;
    color: #525252;
}

.social-icon-text {
    display: flex;
    justify-content: center;
}
</style>
