import { scripts } from 'laravel-mix'
import missingHandler from './lang/missing-handler'

const configJson = require('./config.json')

let apiBase = !process.env.API_BASE.trim() ? '/' : process.env.API_BASE
apiBase += configJson.api.url

export default {
  target: 'static', // Add this line
  ssr: process.env.SSR === 'false',
  serverMiddleware: [
    { path: "/sitemap.xml", handler: "~/api/sitemap/index.js" },
    { path: "/robots.txt", handler: "~/api/robots/index.js" },
  ],
  env: {
    apiBase: process.env.API_BASE
  },
  head: {
    meta: [
      {charset: 'utf-8'},
      {
        name: 'viewport',
        content: 'width=device-width,initial-scale=1,width=device-width, initial-scale=1'
      },
    ],
    link: [
      { rel: 'icon', type: 'image/x-icon', href: '/favicon.png' },
      {
        rel: 'preconnect',
        href: 'https://fonts.googleapis.com'
      },
      {
        rel: 'preconnect',
        href: 'https://fonts.gstatic.com'
      },
      {
        rel: 'stylesheet',
        href: 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css'
      },
      {
        rel: 'stylesheet',
        href: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'
      },
      {
        rel: 'stylesheet',
        href: 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap'
      }
    ],
    script : [
      {
        src: "https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js",
        defer: true
      },
      {
        src: "https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js",
        defer: true
      }
    ]
  },
  css: [
    '~/assets/styles/styles.styl',
    '@glidejs/glide/dist/css/glide.core.min.css',
    '~/assets/css/website-style.css'
  ],
  //Progress bar color
  loading: {color: '#39b982'},

  // Plugins to run before rendering page: https://go.nuxtjs.dev/config-plugins
  plugins: [
    {src: '~/plugins/vue-product-zoomer.js', mode: 'client'},
    {src: '~/plugins/vue-social-sharing.js', mode: 'client'},
    {src: '~/plugins/v-drag.js', mode: 'client'},
    {src: '~/plugins/cryptojs.js', mode: 'client'},
    {src: '~/plugins/back-handler.js', mode: 'client'},
    {src: '~/plugins/nuxt-client-init.js', ssr: false},
    {src: '~/plugins/dompurify.js'},
    {src: '~/plugins/no-leading-space.js', mode: 'client'},
    { src: '~/plugins/vuex-persisted.js', ssr: false },
    // After vuex-persisted, so the rehydrated basket is already in the store
    // when this decides whose it is.
    { src: '~/plugins/cart-identity.js', ssr: false },
    // A placeholder wherever an image fails to load.
    { src: '~/plugins/image-placeholder.js', mode: 'client' }
    
  ],

  // Auto import components: https://go.nuxtjs.dev/config-components
  components: true,

  hooks: {
    /*
     * Keep the skeletons in the main bundle.
     *
     * @nuxt/components emits every auto-imported component as its own dynamic
     * import in a production build, which is the right default - but not for
     * these. A skeleton exists to be on screen the instant a page starts
     * waiting for data, and an async one cannot be: it needs its own round
     * trip first, so the reader gets the blank screen the skeleton was added
     * to remove, and then a placeholder, and then the content.
     *
     * Setting isAsync to false makes the generated index export them
     * statically. They are a few hundred bytes each, and this is the one part
     * of the panel that must never be late.
     *
     * (The dirs option cannot express this: the scanner coerces a false
     * isAsync back to null, which production reads as async.)
     */
    'components:extend'(components) {
      for (const component of components) {
        // Skeleton* and the account pages' AccountSkeleton alike.
        if (component.pascalName.includes('Skeleton')) {
          component.isAsync = false
        }
      }
    }
  },

  // Modules for dev and build (recommended): https://go.nuxtjs.dev/config-modules
  buildModules: [
    '@nuxtjs/moment'
  ],
  // Modules: https://go.nuxtjs.dev/config-modules
  modules: [
    // https://go.nuxtjs.dev/axios
    '@nuxtjs/axios',
    '@nuxtjs/auth-next',
    '@nuxtjs/i18n',
    '@nuxtjs/pwa'
  ],
  auth: {
    redirect: {
      login: '/login',
      // Signing out lands on sign-in rather than the shop home, so it is
      // obvious the session ended. Covers every $auth.logout() call site.
      logout: '/login',
      callback: '/login',
      home: '/'
    },
    cookie:{
      prefix: 'frontend_',
      /*
       * No longer than the longest sign-in the API issues (30 days, "Keep me
       * signed in"). An unticked sign-in's token stops working after a day
       * and the session-ended handling signs the browser out; this only stops
       * the cookie outliving any token it could hold.
       */
      options: {
        maxAge: 30 * 24 * 60 * 60
      },
    },
    localStorage: false,
    strategies: {
      local: {
        token: {
          property: 'data.token',
          // required: true,
          // type: 'Bearer'
        },
        user: {
          property: 'data',
          autoFetch: true
        },
        endpoints: {
          login: {url: apiBase + configJson.api.login, method: 'post'},
          logout: {url: apiBase + configJson.api.logout, method: 'get'},
          user: false
          /*user: {url: apiBase + configJson.api.profile, method: 'get'}*/
        }
      }
    }
  },
  axios: {},

  build: {
    transpile: [
      'defu',
    ]
  },

  i18n: {
    locales: [
      {
        code: 'en',
        file: 'en.json'
      },
      {
        code: 'fr',
        file: 'fr.json'
      },
      {
        code: 'ar',
        file: 'ar.json'
      },
      {
        code: 'tr',
        file: 'tr.json'
      },
      {
        code: 'hi',
        file: 'hi.json'
      }
    ],
    detectBrowserLanguage: {
      useCookie: true,
      cookieKey: 'i18n_redirected'
    },
    lazy: false,
    langDir: 'lang/',
    defaultLocale: 'en',
    /*
     * Untranslated text has to read as text.
     *
     * Without a fallbackLocale, vue-i18n renders the key itself for anything a
     * language file does not define — which is how "detailRight.freeCollection"
     * ended up printed in the buy box next to the price. French, Arabic, Turkish
     * and Hindi are all partially translated, so any one of them could do the
     * same on any page.
     *
     * English answers for them; missingHandler answers when English cannot.
     */
    vueI18n: {
      fallbackLocale: 'en',
      // The fallback is the design, not a fault: no warning for using it.
      silentFallbackWarn: true,
      missing: missingHandler
    }
  },
  pwa: {
    themeColor: "#081d4d",
    backgroundColor: "#FFFFFF",
    icon: {
      fileName: 'pwa-icon.png',
      purpose: "any"
    },
  },
/*  server: {
    port: 3001,
    host: '0.0.0.0',
  },*/
}
