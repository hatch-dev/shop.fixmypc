import Service from '@/services/service.js'

const state = () => ({

})
const getters = {

}
const mutations = {
}

const actions = {
  /*
   * A guest's wishlist is owned by its server-issued token, exactly like its
   * cart, so send the token whenever nobody is signed in. Without it the API
   * has no owner to scope the row to and refuses the write.
   */
  async userWishlistAction ({ commit, dispatch }, params) {
    const userToken = params?.user_token
      || (this.$auth.loggedIn ? null : await dispatch('user/getUserToken', null, {root: true}))

    const {data} = await Service.userWishlistAction(
      {...params, user_token: userToken},
      this.$auth.strategy.token.get()
    )

    commit('detail/UPDATE_WISHLIST', data, {root: true})
    return data
  },
}

export {
  state,
  getters,
  mutations,
  actions
}
