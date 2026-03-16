
const state = () => ({
  sidebarOpen: localStorage.getItem('sidebar_open')!=='false' ,
  rememberMe: localStorage.getItem('remember_me') || '',
  dashboardNotice: localStorage.getItem('dashboard_notice') || false,
  toastMessage: '',
  toastMessageStatus: false,
  toastError: false,
  errors: null,
})
const getters = {
  sidebarOpen: ({sidebarOpen}) => sidebarOpen,
  rememberMe: ({rememberMe}) => rememberMe,
  dashboardNotice: ({dashboardNotice}) => dashboardNotice,
  toastMessage:({ toastMessage }) => toastMessage,
  toastError:({ toastError }) => toastError,
  errors:({ errors }) => errors,
  toastMessageStatus:({ toastMessageStatus }) => toastMessageStatus
}
const mutations = {
  TOGGLE_SIDEBAR(state) {
    state.sidebarOpen = !state.sidebarOpen
    localStorage.setItem('sidebar_open', state.sidebarOpen)
  },
  HIDE_SIDEBAR(state) {
    state.sidebarOpen = false
    localStorage.setItem('sidebar_open', state.sidebarOpen)
  },
  SET_DASHBOARD_NOTICE(state, data) {
    state.dashboardNotice = data
    localStorage.setItem('dashboard_notice', data)
  },
  SET_REMEMBER_ME(state, data) {
    state.rememberMe = data
    localStorage.setItem('remember_me', data)
  },
  SET_TOAST_MESSAGE (state, toastMessage) {
    state.toastMessageStatus = true
    if(toastMessage?.trim()){
      state.toastMessage = toastMessage
    }
  },
  SET_TOAST_ERROR (state, toastMessage) {
    state.toastError = true
    state.toastMessageStatus = true
    state.toastMessage = toastMessage
  },
  HIDE_TOAST(state) {
    state.toastMessageStatus = false
    state.toastError = false
    state.toastMessage = ''
  },
  SET_ERRORS(state, data) {
    state.errors = data
  },
}

const actions = {
  settingDashboardNotice ({ commit }, params) {
    commit('SET_DASHBOARD_NOTICE', params)
  },
  settingRemember ({ commit }, params) {
    commit('SET_REMEMBER_ME', params)
  },
  hideSidebar ({ commit }) {
    commit('HIDE_SIDEBAR')
  },
  toggleSidebar ({ commit }) {
    commit('TOGGLE_SIDEBAR')
  },
  setToastMessage ({ commit }, message) {
    message = message?.trim() ? message : this.$i18n.t('util.saved')
    commit('SET_TOAST_MESSAGE', message)
  },
  setToastError ({ commit }, message) {
    commit('SET_TOAST_ERROR', message)
  },
  hideToast ({ commit }) {
    commit('HIDE_TOAST')
  },
  setErrors ({ commit }, payload = null) {
    commit('SET_ERRORS', payload)
  },
}

export {
  state,
  getters,
  mutations,
  actions
}
