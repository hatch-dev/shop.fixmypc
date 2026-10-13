import Service from '@/services/service.js'

/*
 * Cross-sell: the recommendations shown after something is added to the
 * basket.
 *
 * Its own module rather than part of cart.js, because it holds a different
 * kind of state. The basket is the customer's; this is a suggestion about it,
 * and it can fail, be empty, or be dismissed without any of that meaning
 * anything about the basket. Keeping them apart is what makes section 27 --
 * "the primary product stays added when the recommendations fail" -- true by
 * construction rather than by remembering to catch in the right place.
 *
 * Nothing here works out a price. Every figure comes from the server, which is
 * the only side that knows what the basket will actually charge this
 * customer.
 */
/*
 * Analytics.
 *
 * The panel already has a GA4 dataLayer and a Meta Pixel on the page -- see
 * layouts/default.vue -- but neither has ever been sent a custom event, so
 * there is no existing convention to follow. These five are section 31's list,
 * pushed to the dataLayer under their own names.
 *
 * Three rules, all of them about not letting a measurement break a purchase:
 *
 *  - it never throws. A missing dataLayer, a blocked script or an ad blocker
 *    is the normal case, not an error, and a customer's basket must not depend
 *    on a tag having loaded.
 *  - it sends no personal information. The product ids and an opaque
 *    server-issued cart token are enough to answer "did this recommendation
 *    work"; a name or an email answers nothing extra and is not ours to send.
 *  - it is separate from the relationship logic. Nothing above this line reads
 *    it, and removing it would change no behaviour.
 */
function track (event, payload = {}) {
  try {
    if (typeof window === 'undefined' || !window.dataLayer) {
      return
    }

    window.dataLayer.push({
      event,
      ...payload,
      timestamp: new Date().toISOString(),
    })
  } catch (e) {
    /* Measurement is never worth an exception in a shopping flow. */
  }
}

export const state = () => ({
  items: [],
  limit: 4,

  // Which recommendations the customer has ticked, as
  // {product_id: {inventory_id, quantity}}.
  selected: {},

  loading: false,
  adding: false,

  /*
   * A failure to LOAD, which is never a failure of the basket. Held so the
   * block can say "we could not load these" rather than vanishing, and so
   * nothing upstream is tempted to treat it as a cart error.
   */
  error: null,

  // Set once the customer closes the block, so it is not reopened at them.
  dismissed: false,
})

export const getters = {
  crossSellItems: ({items}) => items,
  crossSellLimit: ({limit}) => limit,
  crossSellSelected: ({selected}) => selected,
  crossSellLoading: ({loading}) => loading,
  crossSellAdding: ({adding}) => adding,
  crossSellError: ({error}) => error,
  crossSellDismissed: ({dismissed}) => dismissed,

  /* How many are ticked. */
  crossSellSelectedCount: ({selected}) => Object.keys(selected).length,

  /**
   * What the ticked recommendations add to the basket.
   *
   * Summed from the SERVER's price for the exact variant chosen -- the price
   * of the option when one was picked, the card's own otherwise. Working it
   * out from a product-level figure is how a block quotes one total and the
   * basket charges another.
   */
  crossSellAdditionalTotal: ({items, selected}) => {
    return Object.keys(selected).reduce((total, productId) => {
      const item = items.find(row => Number(row.id) === Number(productId))

      if (!item) return total

      const choice = selected[productId]

      const option = (item.options || [])
        .find(row => Number(row.inventory_id) === Number(choice.inventory_id))

      const price = Number(option ? option.price : item.price) || 0

      return total + price * (Number(choice.quantity) || 1)
    }, 0)
  },
}

export const mutations = {
  SET_ITEMS(state, {items, limit}) {
    state.items = items || []

    if (limit) state.limit = Number(limit)
  },

  SET_LOADING(state, loading) {
    state.loading = loading
  },

  SET_ADDING(state, adding) {
    state.adding = adding
  },

  SET_ERROR(state, error) {
    state.error = error
  },

  SET_DISMISSED(state, dismissed) {
    state.dismissed = dismissed
  },

  /*
   * Vue 2 cannot see a key added to a plain object, so the map is replaced
   * rather than mutated. Without this the tick count and the total both stay
   * where they were and the block looks broken.
   */
  SELECT(state, {productId, inventoryId, quantity}) {
    state.selected = {
      ...state.selected,
      [productId]: {
        inventory_id: inventoryId || null,
        quantity: quantity || 1,
      },
    }
  },

  DESELECT(state, productId) {
    const next = {...state.selected}

    delete next[productId]

    state.selected = next
  },

  RESET(state) {
    state.items = []
    state.selected = {}
    state.error = null
    state.loading = false
    state.adding = false
    state.dismissed = false
  },
}

export const actions = {
  /**
   * Load the recommendations for a product that has just been added.
   *
   * Section 27, and the reason this never rejects: the primary product is
   * already in the basket by the time this runs. A shopper whose add has just
   * worked must not be shown a failure because the suggestion underneath it
   * could not be built, and nothing upstream should have to remember to catch
   * for that to be true.
   */
  async loadForProduct({commit, dispatch, rootGetters}, {productId, lang} = {}) {
    commit('RESET')

    if (!productId) return

    commit('SET_LOADING', true)

    try {
      const userToken = await dispatch('user/getUserToken', null, {root: true})

      const {data} = await Service.crossSellsForProduct(
        productId,
        {user_token: userToken},
        this.$auth.strategy.token.get()
      )

      if (data?.status === 200) {
        const items = data.data?.items || []

        commit('SET_ITEMS', {items, limit: data.data?.limit})

        /* Only when something was actually shown. */
        if (items.length) {
          track('cross_sell_viewed', {
            primary_product_id: productId,
            cross_sell_product_ids: items.map(item => item.id),
            // Which promotions the block was built from -- the id an admin
            // can find in Promotions -> Cross-sell.
            cross_sell_ids: [...new Set(items.map(item => item.cross_sell_id).filter(Boolean))],
            cart_id: userToken || null,
            account_type: rootGetters['user/profile']?.account_type || 'guest',
          })
        }
      } else {
        commit('SET_ERROR', data?.message || null)
      }

    } catch (e) {
      /*
       * Swallowed on purpose, and recorded rather than thrown. A timeout here
       * is a recommendation that did not arrive, not a basket that did not
       * work.
       */
      commit('SET_ERROR', e?.message || null)
    } finally {
      commit('SET_LOADING', false)
    }
  },

  /** The same, for every product already in the basket. */
  async loadForCart({commit, dispatch}, {lang} = {}) {
    commit('RESET')
    commit('SET_LOADING', true)

    try {
      const userToken = await dispatch('user/getUserToken', null, {root: true})

      const {data} = await Service.crossSellsForCart(
        {user_token: userToken},
        this.$auth.strategy.token.get()
      )

      if (data?.status === 200) {
        commit('SET_ITEMS', {
          items: data.data?.items || [],
          limit: data.data?.limit,
        })
      } else {
        commit('SET_ERROR', data?.message || null)
      }

    } catch (e) {
      commit('SET_ERROR', e?.message || null)
    } finally {
      commit('SET_LOADING', false)
    }
  },

  /**
   * Add what the customer ticked.
   *
   * Sends the product and the chosen variant and NOTHING about price -- the
   * server prices every line from the catalogue and would ignore a figure
   * sent from here anyway. The basket is then re-read rather than patched,
   * because the server has just decided quantities, prices and VAT splits for
   * several lines at once and this is not the place to guess at any of them.
   *
   * Partial success is a real outcome: the response says what went in and what
   * did not, and the caller reports both.
   */
  async addSelected({state, commit, dispatch, rootGetters}, {primaryProductId, lang} = {}) {
    const productIds = Object.keys(state.selected)

    if (!productIds.length) {
      return {added: [], skipped: [], refused: true}
    }

    commit('SET_ADDING', true)

    try {
      const userToken = await dispatch('user/getUserToken', null, {root: true})

      const {data} = await Service.addCrossSell({
        user_token: userToken,
        primary_product_id: primaryProductId || undefined,
        products: productIds.map(productId => ({
          product_id: Number(productId),
          inventory_id: state.selected[productId].inventory_id || undefined,
          quantity: state.selected[productId].quantity || 1,
        })),
      }, this.$auth.strategy.token.get())

      if (data?.status === 200) {
        commit('common/SET_TOAST_MESSAGE', this.$i18n.t('cart.productAdded'), {root: true})

        /*
         * What actually went in, which is not what was ticked: a
         * recommendation can sell out between the tick and the click, and
         * counting the ticks would overstate the conversion this event exists
         * to measure.
         */
        track('cross_sell_added_to_cart', {
          primary_product_id: primaryProductId || null,
          cross_sell_product_ids: (data.data?.added || []).map(row => row.product_id),
          cross_sell_ids: [...new Set(
            (data.data?.added || [])
              .map(row => state.items.find(item => Number(item.id) === Number(row.product_id))?.cross_sell_id)
              .filter(Boolean)
          )],
          skipped: (data.data?.skipped || []).length,
          cart_id: userToken || null,
          account_type: rootGetters['user/profile']?.account_type || 'guest',
        })

        /*
         * Re-read rather than patched. Several lines changed at once, each
         * with its own server-decided price and VAT split, and the basket
         * summary reads relations this response does not carry.
         */
        await dispatch('cart/getCartByUser', {lang, params: {user_token: userToken}}, {root: true})

        return {
          added: data.data?.added || [],
          skipped: data.data?.skipped || [],
        }
      }

      /*
       * Nothing went in. Reported as a message rather than thrown: the basket
       * is untouched and intact, which is not an application error.
       */
      commit('common/SET_TOAST_ERROR', data?.message || this.$i18n.t('crossSell.addFailed'), {root: true})

      return {added: [], skipped: data?.data?.skipped || [], refused: true}

    } catch (e) {
      commit('common/SET_TOAST_ERROR', e?.message || this.$i18n.t('crossSell.addFailed'), {root: true})

      return {added: [], skipped: [], refused: true}
    } finally {
      commit('SET_ADDING', false)
    }
  },

  /**
   * Tick or untick one recommendation.
   *
   * A product needing a variant cannot be ticked without one, which is the
   * client half of section 14 -- the server refuses it too, and that is the
   * half that counts.
   */
  toggle({state, commit, rootGetters}, {item, inventoryId} = {}) {
    const productId = Number(item.id)

    const accountType = rootGetters['user/profile']?.account_type || 'guest'

    if (state.selected[productId]) {
      commit('DESELECT', productId)

      track('cross_sell_product_deselected', {
        cross_sell_id: item.cross_sell_id || null,
        cross_sell_product_id: productId,
        account_type: accountType,
      })

      return
    }

    commit('SELECT', {
      productId,
      inventoryId: inventoryId || item.inventory_id || null,
      quantity: 1,
    })

    track('cross_sell_product_selected', {
      cross_sell_id: item.cross_sell_id || null,
      cross_sell_product_id: productId,
      account_type: accountType,
    })
  },

  setQuantity({state, commit}, {productId, quantity} = {}) {
    const current = state.selected[Number(productId)]

    if (!current) return

    commit('SELECT', {
      productId: Number(productId),
      inventoryId: current.inventory_id,
      quantity: Math.max(1, Number(quantity) || 1),
    })
  },

  /* Changing the variant edits the tick rather than adding a second one. */
  setOption({state, commit}, {productId, inventoryId} = {}) {
    const current = state.selected[Number(productId)]

    commit('SELECT', {
      productId: Number(productId),
      inventoryId: inventoryId || null,
      quantity: current?.quantity || 1,
    })
  },

  dismiss({state, commit}) {
    commit('SET_DISMISSED', true)

    /*
     * Carries what was on offer and what was ticked when they closed it --
     * "shown four, ticked none" is the answer to whether the block works, and
     * it cannot be read from the added events alone.
     */
    track('cross_sell_dismissed', {
      shown: state.items.length,
      selected: Object.keys(state.selected).length,
    })
  },

  reset({commit}) {
    commit('RESET')
  },
}
