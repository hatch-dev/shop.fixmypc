<template>
  <div :class="routeName">
    <transition
      name="fade"
      mode="out-in"
    >
      <div
        v-if="popupWrapperVisible"
        class="popup-banner-wrapper"
      >
        <banner
          class="popup-banner"
          :banner="popupBanner"
          v-outside-click="bannerClosed"
          @close="closedPermanently"
          @clicked="closedPermanently"
        />
      </div>
    </transition>
    <Header
      ref="siteHeader"
      @going-next="goingNext"
    />
    <main>
      <!--
        undefined, not null, until goingNext() sets a key: Nuxt treats any
        defined key as given and, finding it empty, builds one from
        $route.matched[0].path - which does not exist for an address no page
        matches, so a mistyped URL crashed with "reading 'path'" instead of
        showing the not-found page.
      -->
      <Nuxt
        :nuxt-child-key="componentId || undefined"
      />
    </main>
    <Footer/>

    <mobile-tab-bar @open-search="openMobileSearch" />

    <!-- What changed the basket without the shopper (BasketNotice). -->
    <basket-notices />

    <transition name="fade" mode="out-in">
      <toast-message
        v-if="toastMessageStatus"
        :is-error="toastError"
        @hide="hideToast"
        :message="toastMessage"
      />
    </transition>


    <pop-over
      v-if="!isCookieBannerClosed"
      class="cookie-banner"
      elem-id="cookie-pop-over"
      :layer-on-responsive="false"
      :layer="false"
    >
      <template v-slot:content>
        <div class="flex gap-15">
          <p class="f-9">{{ $t('date.cb') }}</p>
          <button @click.prevent="closeCookieBanner" class="outline-btn plr-15">{{ $t('date.gi') }}</button>
        </div>
      </template>
    </pop-over>



  </div>
</template>

<script>
  import Header from '~/layouts/partials/Header.vue'
  import Footer from '~/layouts/partials/Footer.vue'
  import ToastMessage from '~/components/ToastMessage.vue'
  import { mapGetters, mapActions} from 'vuex'
  import outsideClick from '~/directives/outside-click'
  import Banner from "~/components/Banner";
  import metaHelper from "~/mixin/metaHelper";
  import util from "~/mixin/util";
  import PopOver from "~/components/PopOver";
  import MobileTabBar from "~/components/MobileTabBar";

  export default {
    head() {
      return this.commonMeta({...this.site_setting, ...this.setting})
    },
    mixins: [util, metaHelper],
    data() {
      return {
        isCookieBannerClosed: true,
        componentId: null,
        loading: false,
        scrollPosition: 0,
        isPopupWrapperVisible: false
      }
    },
    components: {
      MobileTabBar,
      PopOver,
      Banner,
      Header,
      Footer,
      ToastMessage
    },
    directives: {
      outsideClick
    },
    watch: {},
    computed: {

      enablePixel() {
        return this.setting?.enable_pixel
      },
      pixelId() {
        return this.setting?.pixel_id
      },
      popupWrapperVisible() {
        return this.isPopupWrapperVisible && parseInt(this.popupBanner?.status) === 1
      },
      routeName() {
        return this.$route?.name?.split('___')[0] || 'error'
      },
      ...mapGetters('user', ['profile']),
      ...mapGetters('error', ['error']),
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('common', ['setting', 'site_setting', 'popupBanner',
        'toastMessage', 'toastError', 'toastMessageStatus']),
    },
    methods: {
      // the tab bar has no field of its own - it opens the search overlay
      openMobileSearch() {
        this.$refs.siteHeader?.openMobileSearch?.()
      },
      closeCookieBanner(){
        localStorage.setItem('cookieBannerClosed', true)
        this.isCookieBannerClosed = true
      },
      initializeGoogleAnalytics(gaId) {
        // Initialize Google Analytics
        window.dataLayer = window.dataLayer || [];
        function gtag() {
          window.dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', gaId);
      },
      goingNext({id, url}) {
        this.componentId = id + '-' + Date.now()
        this.$router.push({path: url})
      },
      closedPermanently() {
        localStorage.setItem('popupBannerClosed', true)
        this.bannerClosed()
      },
      bannerClosed() {
        this.isPopupWrapperVisible = false
        document.body.classList.remove('no-scroll')
      },
      initializeFacebookPixel(pixelID) {
        !function (f, b, e, v, n, t, s) {
          if (f.fbq) return;
          n = f.fbq = function () {
            n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
          };
          if (!f._fbq) f._fbq = n;
          n.push = n;
          n.loaded = !0;
          n.version = '2.0';
          n.queue = [];
          t = b.createElement(e);
          t.async = !0;
          t.src = v;
          s = b.getElementsByTagName(e)[0];
          s.parentNode.insertBefore(t, s);
        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

        fbq('init', pixelID);
        fbq('track', 'PageView');
      },
      ...mapActions('cart', ['setCartCount']),
      ...mapActions('common', ['hideToast', 'getRequest']),
      ...mapActions('error', ['emptyError']),
      ...mapActions('user', ['setProfile', 'getUserToken', 'logout']),
    },
    async mounted() {
      document.body.classList.add(this.currentLanguage?.direction || 'ltr')

      if (this.site_setting?.primary_color) {
        document.documentElement.style.setProperty('--primary-color', this.site_setting.primary_color)
        document.documentElement.style.setProperty('--primary-hover-color', this.site_setting.primary_hover_color)
      }
      if (!this.profile) {
        if(!process.env.apiBase.trim()){
          this.$axios.defaults.baseURL = window.location.origin + '/'
        }

        try {
          this.loading = true
          const data = await this.getRequest({
            params: {
              user_token: await this.getUserToken()
            },
            api: 'profile',
            requiredToken: true
          })

          if (data?.status === 200) {

            if(data?.data?.is_logged_in){
              await this.$auth.setUser(data.data)
            }

            this.setCartCount(data.data?.cart_count)
            this.setProfile(data)

          } else if (this.$auth.loggedIn) {
            /*
             * The profile endpoint refuses anyone it cannot identify, so a
             * non-200 here while $auth still reports a signed-in shopper
             * means the token in the browser is one the API no longer
             * accepts: expired, revoked, or issued before the signing keys
             * were replaced.
             *
             * Leaving it in place is worse than having no token at all.
             * Every call that carries it is refused, and getUserToken()
             * declines to mint a guest token because it believes someone is
             * signed in, so the basket and the address list go out with no
             * identity whatsoever - which is how pressing the basket ended
             * on the error screen.
             *
             * reset() rather than logout(): the session is already gone, and
             * logout() redirects to /login, which would throw the shopper off
             * whatever page they had open. Dropping it here lets them carry
             * on as a guest and sign in again when they choose to - but it
             * is said out loud, because a header that quietly stops saying
             * their name is not an explanation.
             *
             * A refusal that comes back as a real 401 is handled earlier, by
             * the interceptor in plugins/axios-auth.js. This branch is for
             * the endpoints that answer 200 with a refusal in the envelope,
             * which that interceptor never sees.
             */
            this.$auth.reset()
            this.$store.dispatch('common/setToastError',
              'Your session has ended. Please sign in again.')
          }

          this.loading = false
        } catch (e) {
          await this.logout()
          this.loading = false
          return this.$nuxt.error(e)
        }
      }

      this.isCookieBannerClosed = !this.setting?.cookie_banner || localStorage.getItem('cookieBannerClosed', false)

      if (!localStorage.getItem('popupBannerClosed', false) && parseInt(this.popupBanner?.status) === this.status.PUBLIC) {
        document.body.classList.add('no-scroll')
        this.isPopupWrapperVisible = true
      }

      if(this.setting?.enable_ga){
        const script = document.createElement('script');
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + this.setting?.ga_id;
        script.async = true;
        document.head.appendChild(script);
        script.onload = () => {
          this.initializeGoogleAnalytics(this.setting?.ga_id);
        };
      }

      if (this.setting?.enable_pixel) {
        this.initializeFacebookPixel(this.setting?.pixel_id);
      }

    }
  }
</script>
<style lang="stylus">
  main
    min-height 650px

  .error
    main
      min-height 300px

  @media only screen and (max-width: 768px)
    main
      min-height 400px
</style>
