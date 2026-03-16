const configJson = require('./jsconfig.json')

const isDemo = process.env.IS_DEMO === 'true'
let apiBase = !process.env.API_BASE.trim() ? '/' : process.env.API_BASE
apiBase += configJson.api.url

export default {
  // Disable server-side rendering: https://go.nuxtjs.dev/ssr-mode
  ssr: false,
/*  target: 'static',*/
  // Global page headers: https://go.nuxtjs.dev/config-head
  head: {
    title: 'Admin panel',
    htmlAttrs: {
      lang: 'en'
    },
    meta: [
      { charset: 'utf-8' },
      { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      { hid: 'description', name: 'description', content: '' },
      { name: 'format-detection', content: 'telephone=no' }
    ],
    link: [
      { rel: 'icon', type: 'image/x-icon', href: '/favicon.png' },
      {
        rel: 'stylesheet',
        href: 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css'
      }
    ]
  },
  transition: {
    css: false
  },
  // Global CSS: https://go.nuxtjs.dev/config-css
  css: [
    '~/assets/styles/styles.styl'
  ],
  env: {
    apiBase: process.env.API_BASE,
    isDemo: isDemo
  },
  // Plugins to run before rendering page: https://go.nuxtjs.dev/config-plugins
  plugins: [

    { src: '@/plugins/vue-html2pdf', mode: 'client' },
    { src: '@/plugins/ability.js' },
    {src: '~/plugins/cryptojs.js', mode: 'client'},
    { src: '@/plugins/casl.js' },
    {src: '~/plugins/dompurify.js'},
    {src: '~/plugins/nuxt-client-init.js', ssr: false},
    {src: '~/plugins/back-handler.js', mode: 'client'},
    { src: '~/plugins/toast.js', mode: 'client' }
  ],

  // Auto import components: https://go.nuxtjs.dev/config-components
  components: true,

  // Modules for dev and build (recommended): https://go.nuxtjs.dev/config-modules
  buildModules: [
  ],
  // Modules: https://go.nuxtjs.dev/config-modules
  modules: [
    // https://go.nuxtjs.dev/axios
    '@nuxtjs/axios',
    '@nuxtjs/i18n',
    '@nuxtjs/auth-next'
  ],
  router: {
    base: process.env.ADMIN_FOLDER
  },
  auth: {
    namespace: 'admin_panel',
    redirect: {
      logout: '/login',
      callback: false,
      home: false,
    },
    cookie:{
      //options: {
        //maxAge: 31622400
     // },
      prefix: 'admin-panel_'
    },
    localStorage: false,
    strategies: {
      local: {
        token: {
          property: 'data.token',
          //maxAge: 60 * 60 * 4, // 4 hours
          // required: true,
          // type: 'Bearer'
        },
        user: {
          // property: 'data',
          autoFetch: true
        },
        endpoints: {
          login: {url: apiBase + configJson.api.login, method: 'post'},
          logout: {url: apiBase + configJson.api.logout, method: 'get'},
          user: false
          // user: {url: apiBase + configJson.api.profile, method: 'get'}
        }
      }
    }
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
    defaultLocale: 'en'
  },
  build: {
    transpile: ['vue-multiselect']
  }

  // Build Configuration: https://go.nuxtjs.dev/config-build
/*  build: {
    extractCSS: false,
    splitChunks: {
      layouts: false,
      pages: true,
      commons: true
    },
    optimization: {
      minimize: true,
      splitChunks: {
        chunks: 'all',
        automaticNameDelimiter: '.',
        name: true,
        cacheGroups: {}
      }
    }
  }*/
}
