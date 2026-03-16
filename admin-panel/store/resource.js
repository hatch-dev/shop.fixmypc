

const state = () => ({
  countryList: null,
  phoneList: null,
  decimalFormatList: null,
  currencyList: null,
  languageList: null
})
const getters = {
  decimalFormatList: ({ decimalFormatList }) => decimalFormatList,
  phoneList: ({ phoneList }) => phoneList,
  countryList: ({ countryList }) => countryList,
  currencyList: ({ currencyList }) => currencyList,
  languageList: ({ languageList }) => languageList,
}
const mutations = {
  SET_COUNTRY_LIST(state, data){
    state.countryList = data
  },
  SET_PHONE_LIST(state, data){
    state.phoneList = data
  },
  SET_CURRENCY_LIST(state, data){
    state.currencyList = data
  },
  SET_LANGUAGE_LIST(state, data){
    state.languageList = data
  },
  SET_DECIMAL_FORMAT_LIST(state, data){
    state.decimalFormatList = data
  },
}

const actions = {
  setDecimalFormatList ({ commit }, list) {
    commit('SET_DECIMAL_FORMAT_LIST', list)
  },
  setCountryList ({ commit }, list) {
    commit('SET_COUNTRY_LIST', list)
  },
  setPhoneList ({ commit }, list) {
    commit('SET_PHONE_LIST', list)
  },
  setCurrencyList ({ commit }, list) {
    commit('SET_CURRENCY_LIST', list)
  },
  setLanguageList ({ commit }, list) {
    commit('SET_LANGUAGE_LIST', list)
  }
}

export {
  state,
  getters,
  mutations,
  actions
}
