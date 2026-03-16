
const state = () => ({
  userAddresses: {},
})
const getters = {
  userAddresses: ({userAddresses}) => userAddresses,
}
const mutations = {
  SET_USER_ADDRESSES(state, {user, addresses}) {
    state.userAddresses = {...state.userAddresses, ...{[user]: addresses}}
  },
  DELETE_USER_ADDRESS(state, {user, addressId }) {
    const index = state.userAddresses[user].findIndex(i => {
      return i.id === addressId
    })
    if(index > -1){
      state.userAddresses[user]?.splice(index, 1)
    }
  },
  UPDATE_USER_ADDRESSES(state, {user, address}) {

    const i = state.userAddresses[user]?.findIndex(i => {
      return i?.id === address?.id
    })

    if(i > -1){

      state.userAddresses[user][i] = address

    } else {
      if(!state.userAddresses[user]){
        state.userAddresses[user] = []
      }
      state.userAddresses[user].push(address)
    }
  },
}

const actions = {
  async setUserAddresses ({ commit }, params) {
    commit('SET_USER_ADDRESSES', params)
  },
  async deleteUserAddress ({ commit }, params) {
    commit('DELETE_USER_ADDRESS', params)
  },
  async updateUserAddress ({ commit }, params) {
    commit('UPDATE_USER_ADDRESSES', params)
  }
}

export {
  state,
  getters,
  mutations,
  actions
}
