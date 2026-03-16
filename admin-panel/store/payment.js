import Service from '@/services/service.js'
const state = () => ({
  payment: null
})
const getters = {
  payment: ({payment}) => payment
}
const mutations = {
  SET_PAYMENT(state, payment) {
    state.payment = payment
  }
}

const actions = {
  async getPayment ({ commit }) {
    const {data} = await Service.getRequest({}, this.$auth.strategy.token.get(), 'getPayment')
    if(data.status === 200){
      commit('SET_PAYMENT', data.data)
    }else {
      return Promise.reject({statusCode: data.status, message: data.message })
    }
  },
  async setPayment ({ commit, dispatch }, params) {
    dispatch('ui/setErrors', null, { root: true })
    const {data} = await Service.setRequest(params, this.$auth.strategy.token.get(), 'setPayment')
    if (data.status === 200) {
      commit('SET_PAYMENT', data.data)
      dispatch('ui/setToastMessage', data.message, {root: true})
      return data
    } else if (data.status === 201) {
      dispatch('ui/setErrors', data.data, { root: true })
    } else {
      return Promise.reject({statusCode: data.status, message: data.message})
    }
  }
}

export {
  state,
  getters,
  mutations,
  actions
}
