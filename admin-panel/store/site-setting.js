
const state = () => ({
  siteSetting: null
})
const getters = {
  siteSetting: ({siteSetting}) => siteSetting
}
const mutations = {
  SET_SITE_SETTING(state, setting) {
    state.siteSetting = {...state.siteSetting, ...setting}
  }
}

const actions = {
}

export {
  state,
  getters,
  mutations,
  actions
}
