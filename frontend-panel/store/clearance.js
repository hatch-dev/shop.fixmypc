import Service from '@/services/service.js'

const state = () => ({
  hero: null,
  pastDeals: [],
  // People who asked to be told about these deals, counted server-side.
  subscriberTotal: 0,
})

const getters = {
  hero: ({ hero }) => hero,
  pastDeals: ({ pastDeals }) => pastDeals,
  subscriberTotal: ({ subscriberTotal }) => subscriberTotal,
}

const mutations = {
  SET_CLEARANCE(state, data) {
    state.hero = data?.hero || null
    state.pastDeals = data?.past_deals || []
    state.subscriberTotal = parseInt(data?.subscriber_count || 0)
  },
  EMPTY_CLEARANCE(state) {
    state.hero = null
    state.pastDeals = []
    state.subscriberTotal = 0
  },
}

const actions = {
  emptyClearance({ commit }) {
    commit('EMPTY_CLEARANCE')
  },
  async fetchClearance({ commit }, { lang } = {}) {
    try {
      const { data } = await Service.clearance(lang)

      if (data?.status === 200) {
        commit('SET_CLEARANCE', data.data)
      } else if (data?.status === 201) {
        return Promise.reject({ statusCode: data?.status, message: data?.message })
      } else {
        return Promise.reject({ message: 'API is down.' })
      }
    } catch (e) {
      return Promise.reject({ message: e.message })
    }
  },
}

export { state, getters, mutations, actions }
