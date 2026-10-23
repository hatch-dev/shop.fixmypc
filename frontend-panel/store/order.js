import Service from '@/services/service.js'

const state = () => ({
  orderedList: [],
  ordered: {}
})
const getters = {
  orderedList: ({ orderedList }) => orderedList,
  ordered: ({ ordered }) => ordered
}
const mutations = {
  SET_ORDERED_LIST (state, order) {
    state.orderedList = order
  },
  SET_ORDERED (state, order) {
    state.ordered = order
  },
  UPDATE_ORDERED (state, order) {
    state.ordered = {... state.ordered, ...order}
  },
  EMPTY_ORDERED (state) {
    state.ordered = {}
  },
  ORDER_ACTION (state, order) {
    state.orderedList.push(order)
  }
}

const actions = {
  updateOrderData({ commit }, payload) {
    commit('UPDATE_ORDERED', payload)
  },
  /*
   * The voucher check prices the code against the caller's OWN basket, so it
   * has to say who is calling. It was the one cart-reading action that passed
   * null here instead of the bearer.
   *
   * axios sent that null as the literal string "null", which the request
   * interceptor in services/service.js reads as "not signed in" - so it put
   * the guest token in the body, and the server priced a signed-in
   * customer's voucher against a guest basket holding nothing. Every code
   * came back "You have to spend at least <min_spend>", whatever was in the
   * real basket.
   *
   * null is still what a guest sends: that is the value the interceptor keys
   * on to attach their guest token. token.get() returns false rather than
   * null when signed out, and the interceptor does not recognise "false".
   */
  async voucherValidity ({ commit }, {payload, lang}) {
    const bearer = this.$auth?.loggedIn
      ? this.$auth.strategy.token.get()
      : null

    const {data} = await Service.voucherValidity(payload, bearer || null, lang)
    return data
  },
  // isCurrent: given, an answer is kept only while it is still the latest ask,
  // so a slow older answer never replaces a newer one (My Orders tabs, search, pages).
  async getOrderByUser ({ commit }, {payload, lang, isCurrent}) {
    commit('EMPTY_ORDERED')
    const {data} = await Service.orderByUser(payload, this.$auth.strategy.token.get(), lang)
    if (isCurrent && !isCurrent()) {
      return data
    }
    if(data?.status === 200){
      if(!payload.order_id){
        commit('SET_ORDERED_LIST', data.data)
      }else {
        commit('SET_ORDERED', data.data)
      }
    }
    return data
  },
  async orderAction ({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.orderAction(payload, this.$auth.strategy.token.get(), lang)
    return data
  },
  async paymentDone ({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.paymentDone(payload, this.$auth.strategy.token.get(), lang)
    return data
  },
  async cancelOrder ({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.cancelOrder(payload, this.$auth.strategy.token.get(), lang)
    return data
  },
  async cancellationFind({ commit, dispatch }, {payload, lang}) {

    const {data} = await Service.cancellationFind(payload, this.$auth.strategy.token.get(), lang)
    return data
  },
  async ratingReviewAction ({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.ratingReviewAction(payload, this.$auth.strategy.token.get(), lang)
    return data
  },
  async ratingReviewFind({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.ratingReviewFind(payload, this.$auth.strategy.token.get(), lang)
    return data
  },
  async sendOrderEmail({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.sendOrderEmail(payload, this.$auth.strategy.token.get(), lang)
    return data
  }

}

export {
  state,
  getters,
  mutations,
  actions
}
