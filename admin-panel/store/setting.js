import Service from '@/services/service.js'
const state = () => ({
  setting: null,
  storeData: null,
  base64SiteLogo: null,
})
const getters = {
  setting: ({setting}) => setting,
  storeData: ({storeData}) => storeData,
  base64SiteLogo: ({base64SiteLogo}) => base64SiteLogo
}
const mutations = {
  SET_STORE_DATA(state, storeData) {
    state.storeData = {... state.storeData, ...storeData}
  },
  SET_SETTING(state, setting) {
    state.setting = {... state.setting, ...setting}
  },
  EMPTY_SETTING(state) {
    state.setting = null
  },
  EMPTY_STORE(state) {
    state.store = null
  },
  BASE_64_SITE_LOGO(state, logo) {
    state.base64SiteLogo = logo
  },
  EMPTY_64_SITE_LOGO(state) {
    state.base64SiteLogo = null
  }
}

const actions = {
  updateStoreData ({ commit }, storeData) {
    commit('SET_STORE_DATA', storeData)
  },
  clearSetting ({ commit }) {
    commit('EMPTY_SETTING')
    commit('EMPTY_STORE')
    commit('EMPTY_64_SITE_LOGO')
  },
  async setConvertImage ({ commit }, params) {
    // This converted image is being used in generated PDF in order page
    const {data} = await Service.convertImage(params, this.$auth.strategy.token.get())
    if(data.status === 200){
      commit('BASE_64_SITE_LOGO', data?.data)
    }else {
      return Promise.reject({statusCode: data.status, message: data.message })
    }
  },
  async getSetting ({ commit }) {
    const {data} = await Service.getRequest({}, this.$auth.strategy.token.get(), 'getSetting')
    if(data.status === 200){
      commit('SET_SETTING', data.data)
    }else {
      return Promise.reject({statusCode: data.status, message: data.message })
    }
  },
  async setCurrency ({ commit, dispatch }, params) {
    dispatch('ui/setErrors', null, { root: true })
    const {data} = await Service.setRequest(params, this.$auth.strategy.token.get(), 'setCurrency')

    if (data.status === 200) {
      commit('SET_SETTING', data.data)
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, { root: true })
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async setAddress ({ commit, dispatch }, params) {
    dispatch('ui/setErrors', null, { root: true })
    const {data} = await Service.setRequest(params, this.$auth.strategy.token.get(), 'setAddress')

    if (data.status === 200) {
      commit('SET_SETTING', data.data)
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, { root: true })
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
}

export {
  state,
  getters,
  mutations,
  actions
}
