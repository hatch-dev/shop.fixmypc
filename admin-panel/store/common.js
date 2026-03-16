import Service from '@/services/service.js'

const state = () => ({
  allUpdatedUpsells: null,
  allCategories: null,
  allTaxRules: null,
  allAttributes: null,
  allAttributeValues: null,
  allBrands: null,
  allProductCollections: null,
  allBundleDeals: null,
  allShippingRules: null,
  allPages: null,
  allPermissions: null,
  allSubscriptionEmailFormats: null,
  allRoles: null
})
const getters = {
  allUpdatedUpsells: ({allUpdatedUpsells}) => allUpdatedUpsells,
  allTaxRules: ({allTaxRules}) => allTaxRules,
  allAttributeValues: ({allAttributeValues}) => allAttributeValues,
  allAttributes: ({allAttributes}) => allAttributes,
  allBrands: ({allBrands}) => allBrands,
  allCategories: ({allCategories}) => allCategories,
  allProductCollections: ({allProductCollections}) => allProductCollections,
  allBundleDeals: ({allBundleDeals}) => allBundleDeals,
  allShippingRules: ({allShippingRules}) => allShippingRules,
  allPermissions: ({allPermissions}) => allPermissions,
  allRoles: ({allRoles}) => allRoles,
  allSubscriptionEmailFormats: ({allSubscriptionEmailFormats}) => allSubscriptionEmailFormats,
  allPages: ({allPages}) => allPages
}
const mutations = {
  SET_ALL_UPDATED_UPSELLS(state, upsells) {
    state.allUpdatedUpsells = {}

    upsells.forEach((item) => {
      state.allUpdatedUpsells = {
        ...state.allUpdatedUpsells,
        ...{ [item.id]: { title: item.title } }
      }
    })
  },
  SET_ALL_PERMISSIONS(state, allPermissions) {
    state.allPermissions = allPermissions
  },
  SET_ALL_ROLES(state, allRoles) {
    state.allRoles = {}
    allRoles.forEach((item) => {
      state.allRoles = {...state.allRoles, ...{[item.name]: {title: item.name}}}
    })
  },
  SET_ALL_PAGES(state, allPages) {
    state.allPages = {}
    allPages.forEach((item) => {
      state.allPages = {...state.allPages, ...{[item.id]: {title: item.title}}}
    })
  },
  SET_ALL_CATEGORIES(state, allCategories) {
    state.allCategories = {}
    allCategories.forEach((item) => {
      state.allCategories = {...state.allCategories, ...{[item.id]: {title: item.title}}}
    })
  },
  SET_ALL_SUBSCRIPTION_EMAIL_FORMATS(state, allSubscriptionEmailFormats) {
    state.allSubscriptionEmailFormats = allSubscriptionEmailFormats
  },
  SET_ALL_SHIPPING_RULES(state, allShippingRules) {
    state.allShippingRules = {}
    allShippingRules.forEach((item) => {
      state.allShippingRules = {...state.allShippingRules, ...{[item.id]: {title: item.title}}}
    })
  },
  SET_ALL_PRODUCT_COLLECTIONS(state, allProductCollections) {
    state.allProductCollections = allProductCollections
  },
  SET_ALL_BUNDLE_DEALS(state, allBundleDeals) {
    state.allBundleDeals = {}
    allBundleDeals.forEach((item) => {
      state.allBundleDeals = {...state.allBundleDeals, ...{[item.id]: {title: item.title}}}
    })
  },
  SET_ALL_ATTRIBUTES(state, allAttributes) {
    state.allAttributes = allAttributes

    let val = []
    allAttributes.forEach(i=>{
      val = [...val, ...i.values]
    })

    state.allAttributeValues = val
  },
  SET_ALL_BRANDS(state, allBrands) {
    state.allBrands = {}
    allBrands.forEach((item) => {
      state.allBrands = {...state.allBrands, ...{[item.id]: {title: item.title}}}
    })
  },
  SET_ALL_TAX_RULES(state, allTaxRules) {
    state.allTaxRules = {}
    allTaxRules.forEach((item) => {
      state.allTaxRules = {...state.allTaxRules, ...{[item.id]: {title: item.title}}}
    })
  },
  EMPTY_ALL_LIST(state, storeAllVariable) {
    state[storeAllVariable] = null
  },
}

const actions = {
  setAllSubscriptionEmailFormats({commit}, allSubscriptionEmailFormats) {
    commit('SET_ALL_SUBSCRIPTION_EMAIL_FORMATS', allSubscriptionEmailFormats)
  },
  emptyAllList({commit}, storeAllVariable) {
    if(storeAllVariable){
      commit('EMPTY_ALL_LIST', storeAllVariable)
    }
  },
  async getAllList({rootState, commit}, {api, mutation}) {
    const {data} = await Service.getRequest({}, this.$auth.strategy.token.get(), api, rootState.language.langCode)
    if (data.status === 200) {
      commit(mutation, data.data)
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async setWysiwygImage({commit, dispatch}, params) {
    dispatch('ui/setErrors', null, {root: true})
    const {data} = await Service.setRequest(params, this.$auth.strategy.token.get(), 'setWysiwygImage')
    if (data.status === 200) {
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data.data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, {root: true})
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async getDropdownList({ rootState, commit}) {
    const {data} = await Service.getRequest(null, this.$auth.strategy.token.get(), 'getDropdownList', rootState.language.langCode)
    if (data.status === 200) {
      const result = data.data
      commit('SET_ALL_CATEGORIES', result.categories)
      commit('SET_ALL_SHIPPING_RULES', result.shipping_rules)
      commit('SET_ALL_PRODUCT_COLLECTIONS', result.product_collections)
      commit('SET_ALL_BUNDLE_DEALS', result.bundle_deals)
      commit('SET_ALL_ATTRIBUTES', result.attributes)
      commit('SET_ALL_BRANDS', result.brands)
      commit('SET_ALL_TAX_RULES', result.tax_rules)
      commit('SET_ALL_UPDATED_UPSELLS', result.updated_upsells)
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async getRequest({rootState, commit, dispatch}, {params, api}) {

    const {data} = await Service.getRequest(params, this.$auth.strategy.token.get(), api, rootState.language.langCode)

    if (data.status === 200) {
      return data.data
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async downloadRequest({rootState, commit, dispatch}, {params, api}) {
    const response = await Service.downloadRequest(params, this.$auth.strategy.token.get(), api, rootState.language.langCode)
    if (response?.status === 200) {

      const isJsonBlob = (data) => data instanceof Blob && data.type === "application/json";
      const responseData = isJsonBlob(response?.data) ? await (response?.data)?.text() : response?.data || {};
      const responseJson = (typeof responseData === "string") ? JSON.parse(responseData) : responseData;

      if(responseJson?.status === 201) {
        return Promise.reject({statusCode: responseJson?.status, message: responseJson?.message})

      } else {
        return responseJson
      }
    } else {
      return Promise.reject({statusCode: response?.status, message: response?.message})
    }
  },
  async setRequest({rootState, commit, dispatch}, {params, api}) {
    const {data} = await Service.setRequest(params, this.$auth.strategy.token.get(), api, rootState.language.langCode)
    if (data.status === 200) {
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data.data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, {root: true})
      return data
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async deleteParam({rootState, commit, dispatch}, {params, api}) {
    const {data} = await Service.deleteParam(params, this.$auth.strategy.token.get(), api, rootState.language.langCode)
    if (data.status === 200) {
      dispatch('ui/setToastMessage', data?.message?.trim() === '' ? this.$i18n.t('util.del') : data?.message , {root: true})
      return data.data
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },

  async deleteData({rootState, commit, dispatch}, {params, api}) {
    const {data} = await Service.deleteData(params, this.$auth.strategy.token.get(), api, rootState.language.langCode)
    if (data.status === 200) {
      dispatch('ui/setToastMessage', data?.message?.trim() === '' ? this.$i18n.t('util.del') : data?.message , {root: true})
      return data.data
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async getById({rootState, commit, dispatch}, {id, params, api}) {
    const {data} = await Service.getById(id, params, this.$auth.strategy.token.get(), api, rootState.language.langCode)
    if (data.status === 200) {
      return data.data
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async setById({rootState, commit, dispatch}, {id, params, api}) {
    dispatch('ui/setErrors', null, {root: true})
    const {data} = await Service.setById(id, params, this.$auth.strategy.token.get(), api, rootState.language.langCode)

    if (data.status === 200) {
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data.data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, {root: true})
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  },
  async setImageById({commit, dispatch}, {id, params, api}) {
    dispatch('ui/setErrors', null, {root: true})

    const {data} = await Service.setImageById(id, params, this.$auth.strategy.token.get(), api)
    if (data.status === 200) {
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data.data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, {root: true})
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
