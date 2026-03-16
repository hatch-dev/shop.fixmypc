import Service from '@/services/service.js'

const state = () => ({
  defaultImage: '',
  mediaStorage: '',
  imgSrcUrl: '',
  thumbPrefix: '',
  media: '',

})
const getters = {
  defaultImage: ({defaultImage}) => defaultImage,
  mediaStorage: ({mediaStorage}) => mediaStorage,
  imgSrcUrl: ({imgSrcUrl}) => imgSrcUrl,
  thumbPrefix: ({thumbPrefix}) => thumbPrefix,
  media: ({media}) => media,
}
const mutations = {
  SET_DEFAULT_IMAGE(state, defaultImage) {
    state.defaultImage = defaultImage
  },
  SET_MEDIA(state, media) {
    state.media = media
  },
  SET_MEDIA_STORAGE(state, mediaStorage) {
    state.mediaStorage = mediaStorage
  },
  SET_IMG_SRC_URL(state, imgSrcUrl) {
    state.imgSrcUrl = imgSrcUrl
  },
  SET_THUMB_PREFIX(state, thumbPrefix) {
    state.thumbPrefix = thumbPrefix
  },
}

const actions = {
  async settingSiteData({state, dispatch}, data) {
    const cookies = document.cookie
    const currentLanguage = cookies && cookies.includes('currentLanguage=')
      ? cookies.split('currentLanguage=')[1].split(';')[0] : '';

    dispatch('admin/setActivated', data)

    dispatch('language/setLanguages', data.languages)

    if (data?.default_language) {
      dispatch('language/setDefaultLanguage', data.default_language)
    }

    if (currentLanguage && state.language.languages[currentLanguage]) {
      dispatch('language/setCurrentLanguage', state.language.languages[currentLanguage])

    } else if (state.language?.defaultLanguage) {

      dispatch('language/setCurrentLanguage', state.language.defaultLanguage)
    }

    if (state.language.currentLanguage?.code !== state.language.defaultLanguage?.code) {
      dispatch('language/setLangCode', state.language.currentLanguage.code)
    }
  },
  async nuxtClientInit({commit, state, dispatch}, context) {
    const cookies = document.cookie

    const token = cookies && cookies.includes('admin-panel__token.local=Bearer%20')
      ? cookies.split('admin-panel__token.local=Bearer%20')[1].split(';')[0] : null

    const currentLanguage = cookies && cookies.includes('currentLanguage=')
      ? cookies.split('currentLanguage=')[1].split(';')[0] : '';

    if (token) {
      try {
        const {data} = await Service.getRequest({
          lang: currentLanguage
        }, 'Bearer ' + token, 'profile', null)

        dispatch('admin/setProfile', data.data)
        dispatch('settingSiteData', data.data)

        if (data.data.languages.length) {
          await dispatch('language/getLangData', {
            i18n: context.app.i18n,
            token: 'Bearer ' + token
          })

          if (state.language?.currentLanguage?.code) {
            context.app.i18n.locale = state.language?.currentLanguage?.code
          }
        }

      } catch (e) {
        return context.error({
          message: e.message
        })
      }
    } else {

      try {
        const {data} = await Service.getRequest({}, null, 'languages', null)
        dispatch('settingSiteData', data.data)

        if (data.data.languages.length) {
          await dispatch('language/getLangData', {
            i18n: context.app.i18n,
            token: null
          })

          if (state.language?.currentLanguage?.code) {
            context.app.i18n.locale = state.language?.currentLanguage?.code
            context.$axios.setHeader('Language', state.language?.currentLanguage?.code)
          }
        }

      } catch (e) {
        return context.error({
          message: e.message
        })
      }
    }
  }
}

export {
  state,
  getters,
  mutations,
  actions
}
