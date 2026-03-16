import Service from '@/services/service.js'
const state = () => ({
  messageCount: 0,
  userMessages: null,
})
const getters = {
  messageCount: ({messageCount}) => messageCount,
  userMessages: ({userMessages}) => userMessages,
}
const mutations = {
  SET_MESSAGES_COUNT(state, messageCount) {
    state.messageCount = messageCount
  },
  SET_USER_MESSAGES(state, userMessages) {
    state.userMessages = userMessages
  }
}

const actions = {
  async getUserMessages ({ commit }, params) {
    const {data} = await Service.getRequest(params, this.$auth.strategy.token.get(), 'getUserMessages')
    if(data.status === 200){
      commit('SET_USER_MESSAGES', data.data.data)
      commit('SET_MESSAGES_COUNT', 0)
    }else {
      return Promise.reject({statusCode: data.status, message: data.message })
    }
  }
}

export {
  state,
  getters,
  mutations,
  actions
}
