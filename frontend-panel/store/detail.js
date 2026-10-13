import Service from '@/services/service.js'

const state = () => ({
  product: null,
  suggested: null,
})
const getters = {
  product: ({ product }) => product,
  suggested: ({ suggested }) => suggested,
}
const mutations = {
  SET_PRODUCT(state, data){
    state.product = data?.data
  },
  UPDATE_WISHLIST(state, data){
    state.product = {...state.product, ...{wishlisted: data?.data ? 1 : null}}
  },
  SET_SUGGESTED_PRODUCTS(state, data){
    state.suggested = data?.data
  },
  EMPTY_SUGGESTED_PRODUCTS(state){
    state.suggested = null
  },
  /*
   * Fresh stock for the variants already on screen, updated in place: the
   * page's selected variant is one of these same objects, so the "In Stock
   * · N units" pill and the quantity limit follow without re-selecting.
   */
  SET_INVENTORY_STOCK(state, rows) {
    const inventory = state.product?.inventory

    if (!inventory || !Array.isArray(rows)) return

    rows.forEach((row) => {
      const target = inventory.find(item => Number(item.id) === Number(row.id))

      if (target) {
        target.quantity = Number(row.quantity)
        target.is_active = row.is_active
      }
    })
  }
}

const actions = {
  async refreshStock ({ commit, state }) {
    const id = state.product?.id

    if (!id) return

    try {
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
      const { data } = await this.$axios.get(`${baseUrl}api/v1/product/${id}/stock`)

      if (data?.status === 200) {
        commit('SET_INVENTORY_STOCK', data.data)
      }
    } catch (e) {
      // A stale pill is not worth an error on a product page.
    }
  },
  async fetchProduct ({ commit }, {params, lang}) {

    try {
      const {data} = await Service.product(params, lang)

      if(data?.status ){

        if(data?.status === 200){
          commit('SET_PRODUCT', data)

        }else if(data?.status === 201){

          return Promise.reject({
            statusCode: data?.status, message: data?.message
          })
        }


      } else {

        return Promise.reject({
          message: "API is down."
        })
      }
    }catch (e) {

      return Promise.reject({
        message: e.message
      })
    }

  },
  async fetchSuggestedProducts ({ commit }, {id, page, lang, bearer = null}) {
    const {data} = await Service.suggestedProducts(id, page, lang, bearer)

    if(data?.status === 200){
      commit('SET_SUGGESTED_PRODUCTS', data)
    }else {
      return Promise.reject({statusCode: data?.status, message: data?.message })
    }

  },
  emptySuggestedProducts ({ commit }) {
    commit('SET_SUGGESTED_PRODUCTS')
  }
}

export {
  state,
  getters,
  mutations,
  actions
}
