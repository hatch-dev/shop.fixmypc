import Vue from 'vue'
import Service from '@/services/service.js'

// Customer-facing text in the selected language; the English stands in before the app is up.
const translate = (key, english) => {
  const i18n = typeof window !== 'undefined' && window.$nuxt && window.$nuxt.$i18n

  return i18n && i18n.te(key) ? i18n.t(key) : english
}
// import util from '@/mixin/util.js'

/*
 * The figures the server decides for a basket line.
 *
 * Every one of these is the server's answer, never arithmetic done here: the
 * unit price for the customer's entitlement at the line's current quantity,
 * the retail price it undercuts, and the line total. The basket copies them
 * across rather than working any of them out for itself.
 */
const SERVER_PRICE_FIELDS = [
  'price', 'unit_price', 'upsell_price', 'original_price', 'line_total',
  /*
   * The VAT split for that unit price, at that quantity.
   *
   * These were missing, so a line kept the split it was added with while its
   * unit price moved to a new band underneath it — a gross figure from the old
   * quantity sitting beside a net one from the new. They are the server's
   * answer too; nothing here works VAT out from a rate.
   */
  'vat_rate', 'vat_inclusive',
  'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
  'line_ex_vat', 'line_vat', 'line_inc_vat',
  /*
   * The list price of the line, all three ways. Each is measured against the
   * line's quantity, so each has to follow the quantity like everything else.
   *
   * `line_list` is what the summary's "Original Products" rows show. Leaving
   * it out of this list is not a missing detail — the row keeps the figure it
   * was added with, so a line stepped from 1 to 2 goes on reporting one unit's
   * original price (699.00) beside a Products Total that has moved to 1,398.00,
   * and the saving between them is silently wrong by a whole unit.
   *
   * Anything added to the payload in CartsController::attachVatSplit belongs
   * here too.
   */
  'line_list', 'line_list_ex_vat', 'line_list_inc_vat',
]

/*
 * The message out of a refused basket write, whatever field it is keyed by.
 *
 * Validation::error keys the body by the field it is about, so a stock or
 * quantity refusal arrives under its own name rather than under 'form'.
 */
function cartRefusalMessage (data) {
  const body = data?.data || {}

  for (const value of Object.values(body)) {
    if (Array.isArray(value) && typeof value[0] === 'string') {
      return value[0]
    }

    if (typeof value === 'string' && value) {
      return value
    }
  }

  return data?.message || translate('ui.priceHelper.basketUpdateFailed', 'We could not update your basket.')
}

const state = () => ({
  cartCount: 0,
  cartProducts: [],
  /*
   * What changed the basket without the shopper - emptied after 20 idle
   * minutes, a line cut back, guest items added at sign-in. The server sends
   * the pending ones with every basket read; BasketNotices shows them.
   */
  basketNotices: [],
  /*
   * Whether the basket's prices are currently being fetched, and why the last
   * attempt failed.
   *
   * The lines are restored from localStorage without any money on them
   * (plugins/vuex-persisted.js), so between a page load and the server
   * answering there is genuinely nothing to show. That is a normal moment and
   * the summary says "Updating prices…" through it.
   *
   * A failure is not a normal moment, and it used to be swallowed: the refresh
   * was dispatched fire-and-forget with an empty catch, so one refused request
   * left the basket priceless with no way back except a reload the customer had
   * to think of themselves. Recording it is what lets the summary offer a retry
   * instead of spinning forever.
   */
  pricesPending: false,
  pricesError: null,
  /*
   * The live store-wide promotion, as GET /flash-discount describes it, and
   * whether this shopper has asked for it.
   *
   * Two separate things on purpose. The promotion is the server's, refetched
   * wherever it is needed and never persisted. The opt-in is the customer's
   * answer, so it survives the trip from the basket to the checkout and is
   * what the order endpoint is told -- it decides the amount itself.
   *
   * The amount used to be held here as `flashDiscount`, written only by the
   * banner on the basket page. Nothing else could recalculate it, so the
   * checkout showed whatever the basket had last put there, or nothing at all
   * on a direct landing. It is a getter now, derived wherever it is read.
   */
  flashPromotion: null,
  flashDiscountOptIn: false,
  /*
   * The signed-in customer's loyalty group discount for the current basket.
   *
   * Always a server figure, never computed here: unlike the flash discount,
   * whose rules are public, a loyalty discount depends on which group the
   * customer is in and whether they have already spent it, and the browser is
   * not entitled to decide either. The order endpoint recalculates it anyway,
   * so this is a display value only.
   */
  loyaltyDiscount: null,
  /*
   * What the Essentials Bundles in this basket are worth.
   *
   * A server figure, like the two above, and for a stronger reason: the
   * discount is re-derived from carts.bundle_deal_id on every pricing pass and
   * is only granted while the basket still holds a whole bundle. The browser
   * cannot know whether it still qualifies, and the order endpoint works it
   * out again regardless -- so this is a display value only.
   *
   * Shape: {total, bundles: [{id, title, subtotal, discount}]}.
   */
  bundleDiscount: null,
  selectedAddress: null,
  // A collection order has no address; these stand in for it.
  collectionContact: null,
  // Chosen on /shipping, so /checkout can open on it rather than asking again.
  paymentMethod: null,
  /*
   * The discounts the SumUp checkout was priced with. /payment creates the
   * order after the card is charged, on a different route from the page that
   * did the arithmetic - without these it billed the discounted amount and
   * then recorded an undiscounted order, which is a mismatch the server
   * refuses to confirm.
   */
  pendingPayment: null
})
/*
 * The basket count, never negative and never NaN.
 *
 * cartCount is persisted to localStorage (plugins/vuex-persisted.js), so a
 * value that once went negative is read back on every later visit. Clamping
 * on the way IN (each mutation below) stops a new one being written; clamping
 * on the way OUT here is what heals a browser that already has -3 on disk,
 * instead of leaving it to count back up from there.
 */
const safeCount = value => Math.max(0, parseInt(value) || 0)

/*
 * The goods a line will actually be charged for.
 *
 * The server reprices every line on each read and sends what it settled on,
 * and that is the base OrdersController hands Pricing::flashDiscount. Reading
 * flash_product.offered instead -- the product-level offer, blind to the sale
 * on the line, the customer's band and the variant's own price -- is what once
 * advertised EUR 104.85 on a basket the checkout would discount by EUR 89.85.
 */
const lineGoods = (item) => {
  const lineTotal = Number(item.line_total)

  if (lineTotal > 0) return lineTotal

  const unit = Number(item.unit_price ?? item.upsell_price)

  if (unit > 0) return item.quantity * unit

  const p = item.flash_product || {}

  return item.quantity * (Number(p.offered) > 0 ? Number(p.offered) : Number(p.selling || 0))
}

/*
 * A Buy Now line (carts.buy_now) is a checkout of one item, not part of the
 * basket (since 2026-10-09). The basket views never show or count
 * it; the checkout pages see it instead of the basket for as long as it
 * exists - the same rule the server applies (Cart::scopeForCheckout).
 */
const isBuyNow = line => !!line && (line.buy_now === true || parseInt(line.buy_now) === 1)
const basketLines = lines => (lines || []).filter(line => !isBuyNow(line))
const checkoutLines = (lines) => {
  const buyNow = (lines || []).filter(isBuyNow)

  return buyNow.length ? buyNow : basketLines(lines)
}
const basketQuantity = lines =>
  basketLines(lines).reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0)

/*
 * A line that cannot be bought as it stands: sold out, unpublished, or asking
 * for more than is left. The server says which (availability on each basket
 * line); checkout is not started while a ticked line is one of these.
 */
const BLOCKING = ['out_of_stock', 'short', 'unavailable']
const cannotBuy = line => !!line && BLOCKING.includes(line.availability)

const getters = {
  cartCount: ({ cartCount }) => safeCount(cartCount),
  basketNotices: ({ basketNotices }) => basketNotices || [],

  /** The basket proper - what the basket page, the drawer and the badge show. */
  cartProducts: ({ cartProducts }) => basketLines(cartProducts),

  /** What a checkout is for: the Buy Now line while there is one, else the basket. */
  checkoutProducts: ({ cartProducts }) => checkoutLines(cartProducts),

  /** Is a one-item Buy Now checkout in progress? */
  buyNowInProgress: ({ cartProducts }) => (cartProducts || []).some(isBuyNow),

  /** Ticked basket lines that cannot be bought as they stand. */
  blockedBasketLines: ({ cartProducts }) =>
    basketLines(cartProducts).filter(p => parseInt(p.selected) === 1 && cannotBuy(p)),

  /** The ticked lines, which are the only ones being bought. */
  selectedCartProducts: ({ cartProducts }) =>
    checkoutLines(cartProducts).filter(p => parseInt(p.selected) === 1),

  /** The goods those lines come to. */
  selectedGoodsTotal: (state, get) =>
    get.selectedCartProducts.reduce((sum, item) => sum + lineGoods(item), 0),

  /** Is a promotion running right now? Checked against the clock, not trusted. */
  flashPromotionLive: ({ flashPromotion }) => {
    if (!flashPromotion || !flashPromotion.active) return false

    const now = Date.now()

    return now >= new Date(flashPromotion.start_time).getTime()
      && now <= new Date(flashPromotion.end_time).getTime()
  },

  /** The floor the basket has to clear before the promotion pays anything. */
  flashMinCartValue: ({ flashPromotion }) =>
    Number((flashPromotion && flashPromotion.min_cart_value) || 0),

  /*
   * A ceiling of 0 is no ceiling. The server sends null for "uncapped", but a
   * stored 0 still reaches here from an older row and Pricing::flashDiscount
   * ignores that too -- the two have to read the field the same way.
   */
  flashCap: ({ flashPromotion }) => {
    const given = Number((flashPromotion && flashPromotion.max_discount) || 0)

    return given > 0 ? given : null
  },

  /** Would this basket earn the discount, if the shopper asked for it? */
  flashDiscountEligible: (state, get) =>
    get.flashPromotionLive
      && get.selectedCartProducts.length > 0
      && get.selectedGoodsTotal > 0
      && get.selectedGoodsTotal >= get.flashMinCartValue,

  /** What it is worth. Pricing::flashDiscount, restated. */
  flashDiscountOffer: (state, get) => {
    if (!get.flashDiscountEligible) return 0

    const goods = get.selectedGoodsTotal

    let amount = state.flashPromotion.type === 'percentage'
      ? (goods * Number(state.flashPromotion.value)) / 100
      : Number(state.flashPromotion.value)

    if (get.flashCap !== null) amount = Math.min(amount, get.flashCap)

    return Math.round(Math.max(0, Math.min(amount, goods)) * 100) / 100
  },

  /*
   * What comes off this basket: the offer, but only once it has been asked
   * for. Every summary reads this, so nothing can show a discount the order
   * will not be told to apply.
   */
  flashDiscountAmount: (state, get) =>
    state.flashDiscountOptIn ? get.flashDiscountOffer : 0,

  /** How much more is needed to qualify. */
  flashDiscountShortfall: (state, get) =>
    Math.round(Math.max(0, get.flashMinCartValue - get.selectedGoodsTotal) * 100) / 100
}
const mutations = {
  SET_PRODUCT_SHIPPING_TYPE(state, payload) {

    const product = state.cartProducts.find(
      p => p.id === payload.id
    )

    if (product) {
      product.shipping_type = payload.shipping_type
    }
  },
  SET_SELECTED_ADDRESS(state, payload) {
    state.selectedAddress = payload
  },
  SET_COLLECTION_CONTACT(state, payload) {
    state.collectionContact = payload
  },
  SET_PAYMENT_METHOD(state, payload) {
    state.paymentMethod = payload
  },
  SET_PENDING_PAYMENT(state, payload) {
    state.pendingPayment = payload
  },
  SET_FLASH_PROMOTION(state, payload) {
    state.flashPromotion = payload
  },
  SET_FLASH_DISCOUNT_OPT_IN(state, payload) {
    state.flashDiscountOptIn = !!payload
  },
  SET_LOYALTY_DISCOUNT(state, payload) {
    state.loyaltyDiscount = payload
  },
  SET_BUNDLE_DISCOUNT(state, payload) {
    state.bundleDiscount = payload
  },
  UPDATE_SELECTED (state, obj) {
    state.cartProducts[obj.key].selected = obj.value
  },
  SET_CART_COUNT (state, cartCount) {
    state.cartCount = safeCount(cartCount)
  },
  DELETE_CART (state, cartProduct) {
    const index = state.cartProducts.findIndex((obj) => {
      return obj.id === cartProduct.id
    })
    state.cartProducts.splice(index, 1)
    // same invariant as SUBTRACT_CART_COUNT below
    state.cartCount = safeCount(safeCount(state.cartCount) - parseInt(cartProduct.quantity))
  },
  INCREASE_CART_COUNT (state, cartCount) {
    state.cartCount = safeCount(safeCount(state.cartCount) + parseInt(cartCount))
  },
  /*
   * Floored at zero: a basket cannot contain fewer than no items.
   *
   * This subtracted blind, and cartCount is persisted to localStorage
   * (plugins/vuex-persisted.js). So any path that took more off than was
   * there - an order placed twice, a payment retried, a stale count seeded
   * over a real one - wrote a negative number to disk and the header then
   * showed a badge reading "-3" on every page, for ever. Nothing could clear
   * it either: the only recovery, in Header.vue, is guarded by
   * `if (!this.cartCount ...)`, and -3 is truthy.
   */
  SUBTRACT_CART_COUNT (state, cartCount) {
    state.cartCount = safeCount(safeCount(state.cartCount) - parseInt(cartCount))
  },
  SET_BASKET_NOTICES (state, notices) {
    state.basketNotices = Array.isArray(notices) ? notices : []
  },
  DROP_BASKET_NOTICES (state, ids) {
    const gone = new Set((ids || []).map(Number))
    state.basketNotices = (state.basketNotices || []).filter(n => !gone.has(Number(n.id)))
  },
  SET_CART_PRODUCTS (state, cartProducts) {
    state.cartProducts = cartProducts
    // The badge counts the basket, never a Buy Now checkout.
    state.cartCount = safeCount(basketQuantity(cartProducts))
  },
  CART_PRODUCT_ACTION (state, cartProduct) {
    const index = state.cartProducts.findIndex((obj) => {
      return obj.id === cartProduct.id
    })

    if(index === -1){
      state.cartProducts.push(cartProduct)
      return
    }

    const row = state.cartProducts[index]

    row.quantity = cartProduct.quantity

    /*
     * The prices the server settled on for the new quantity.
     *
     * Only `quantity` used to be copied across. The unit price stayed at
     * whatever the line was priced at before, so the basket showed
     * old-unit-price x new-quantity — which is how a business account that
     * stepped from 10 to 11 kept the 5-10 band's rate, and every figure
     * derived from it (line total, subtotal, discount, VAT, grand total) was
     * wrong with it.
     */
    SERVER_PRICE_FIELDS.forEach((field) => {
      const value = cartProduct[field]

      if (value !== undefined && value !== null) {
        Vue.set(row, field, value)
      }
    })
  },
  EMPTY_CART_PRODUCT(state) {
    state.cartProducts = []
    state.cartCount = 0
  },
  /*
   * The order went through: drop exactly the lines it bought - the Buy Now
   * line, or the ticked basket lines. A Buy Now order used to drop every
   * ticked basket line too, though the server had left them where they were.
   */
  EMPTY_CART_PRODUCT_ONLY(state, status) {
    const ordered = new Set(checkoutLines(state.cartProducts)
      .filter(i => parseInt(i.selected) === status.PUBLIC)
      .map(i => i.id))

    state.cartProducts = state.cartProducts.filter(i => !ordered.has(i.id))
  },
  INSERT_CART_SHIPPING(state, cartProducts) {
    state.cartProducts = cartProducts
  },
  SET_PRICES_PENDING (state, pending) {
    state.pricesPending = !!pending

    // A new attempt clears the last failure, so a retry that works does not
    // leave its own error message on screen underneath the right figures.
    if (pending) state.pricesError = null
  },
  SET_PRICES_ERROR (state, message) {
    state.pricesPending = false
    state.pricesError = message || null
  }
}

const actions = {
  async getCartByUser ({ commit, dispatch }, {lang, params} = {}) {

    /*
     * Every exit from here has to settle the pending flag, including the ones
     * that throw. The basket has no prices of its own any more — they are not
     * persisted — so a refresh that ends without saying so leaves the summary
     * showing "Updating prices…" for as long as the tab is open.
     */
    commit('SET_PRICES_PENDING', true)

    try {
      /*
       * Auth has to be installed before this can ask for anything.
       *
       * Dispatched from a plugin at boot, `this.$auth` is still undefined and
       * reading .strategy off it threw a TypeError that reached the customer as
       * "Cannot read properties of undefined (reading 'strategy')" in place of
       * their VAT. Refusing cleanly is not only about the message: without a
       * token this would fetch the GUEST basket and overwrite a signed-in
       * shopper's with it.
       */
      if (!this.$auth?.strategy) {
        /*
         * Thrown, not `return Promise.reject(...)`. Returning a rejected
         * promise from inside a try block does not enter the local catch, so
         * the pending flag was never cleared and the summary span forever —
         * the very failure this whole path exists to prevent.
         */
        throw {
          statusCode: null,
          message: translate('ui.priceHelper.basketLoadFailed', 'Your basket could not be loaded. Please try again.')
        }
      }

      /*
       * `params` used to be dereferenced directly, so any caller that dispatched
       * without it threw a TypeError and Nuxt replaced the whole page with the
       * error screen rather than just failing the refresh.
       *
       * Fall back to the guest token so a signed-out shopper still gets their
       * own basket back.
       */
      const userToken = params?.user_token
        || (this.$auth.loggedIn ? null : await dispatch('user/getUserToken', null, {root: true}))

      const {data} = await Service.cartByUser(this.$auth.strategy.token.get(), lang, {user_token: userToken})

      if (data?.status === 200) {
        commit('SET_CART_PRODUCTS', data.data)
        commit('SET_BASKET_NOTICES', data.notices)
        commit('SET_PRICES_PENDING', false)

        return data
      }

      /*
       * data.message threw its own TypeError whenever the API answered with
       * something that was not a JSON envelope at all - a proxy page, a reset
       * connection - and an envelope without a message rejected with
       * {statusCode: undefined, message: undefined}, which is how a refused
       * basket ended up on screen as a bare "Something went wrong".
       */
      const message = data?.message
        || (Array.isArray(data?.data?.form) ? data.data.form.join(', ') : null)
        || translate('ui.priceHelper.basketLoadFailed', 'Your basket could not be loaded. Please try again.')

      // Thrown for the same reason as the guard above: a returned rejection
      // would leave the catch, and the pending flag, untouched.
      throw { statusCode: data?.status, message }

    } catch (e) {
      /*
       * A network failure, an expired token, a proxy page instead of JSON.
       * Recorded rather than swallowed, then re-thrown so the callers that
       * already handle a rejection keep doing so.
       *
       * Only OUR OWN rejections carry a message fit to show a customer — they
       * are the ones with a statusCode, raised above from the API envelope. A
       * thrown Error is a programming fault and its text is for the console:
       * "Cannot read properties of undefined (reading 'strategy')" was printed
       * in the summary where the VAT belonged.
       */
      const isOurs = e && typeof e === 'object' && 'statusCode' in e

      commit('SET_PRICES_ERROR',
        isOurs && e.message
          ? e.message
          : translate('ui.priceHelper.basketLoadFailed', 'Your basket could not be loaded. Please try again.'))

      throw e
    }
  },
  async buyNow ({ commit, dispatch }, {payload, lang}) {
    const {data} = await Service.buyNow(payload, this.$auth.strategy.token.get())

    if(data?.status === 200){
      // Awaited: checkout opens straight after, and has to find the Buy Now line.
      try {
        await dispatch('getCartByUser', {
          lang: lang,
          params: payload
        })
      } catch (e) {
        // Checkout fetches the basket for itself as well.
      }
    }

    return data
  },
  async cartAction ({ state, commit, dispatch }, {payload, lang}) {
    const apiVal = payload.apiVal
    const storeVal = payload.storeVal

    const {data} = await Service.cartAction(apiVal, this.$auth.strategy.token.get())
    if(data?.status === 200){
      commit('common/SET_TOAST_MESSAGE', this.$i18n.t('cart.productAdded'), {root: true})

      const row = data.data || {}

      // Whether the basket already holds this line decides how much of it the
      // response can be trusted to complete; see below.
      const known = state.cartProducts.some((obj) => obj.id === row.id)

      /*
       * The server priced this line against the customer's entitlement and the
       * line's NEW quantity, and answered with what it settled on. Carrying
       * those figures across is the point of the round trip.
       */
      const priced = {...storeVal, id: row.id, quantity: row.quantity}

      SERVER_PRICE_FIELDS.forEach((field) => {
        if (row[field] !== undefined && row[field] !== null) {
          priced[field] = row[field]
        }
      })

      commit('CART_PRODUCT_ACTION', priced)

      commit('INCREASE_CART_COUNT', storeVal.quantity)

      /*
       * A line the basket has not seen before is pushed as the caller's own
       * shape, which is not a basket row — it carries no product, inventory,
       * tax rule or shipping relation, all of which the summary reads. A
       * bundle writes several lines at once for the same reason. Either way,
       * ask the server for the real thing.
       */
      if(!known || payload.isBundle){
        dispatch('getCartByUser', {
          lang: lang,
          params: payload
        })
      }

    } else if(data?.status === 201) {
      /*
       * A refusal is keyed by the field it is about, and it is not always
       * 'form' — a stock or quantity refusal names its own. Reading
       * data.form[0] blindly threw on those, which surfaced as the Nuxt error
       * page instead of a toast saying what was wrong.
       */
      commit('common/SET_TOAST_ERROR', cartRefusalMessage(data), {root: true})
    } else {
      return Promise.reject({statusCode: data?.status, message: data.message })
    }
  },
  async cartDelete ({ commit, dispatch }, {payload, lang}) {
    /*
     * A guest has to identify itself here. The cart endpoints scope every read
     * and write to the owner, so removing a line without a user_token reads as
     * an anonymous caller and is refused — which showed up as the error page
     * instead of the item disappearing.
     */
    const userToken = payload?.user_token || await dispatch('user/getUserToken', null, {root: true})

    const {data} = await Service.cartDelete(payload.id, this.$auth.strategy.token.get(), userToken)
    if(data?.status === 200){
      dispatch('getCartByUser', {params: {...payload, user_token: userToken}, lang: lang})
      commit('DELETE_CART', data.data)
    }else {
      return Promise.reject({statusCode: data?.status, message: data.message })
    }
  },
  async cartChanged ({ state, commit, dispatch }, {payload, lang}) {
    // Same as cartDelete: selecting and deselecting lines is scoped to the
    // owner, so a guest must send its token.
    const userToken = payload?.user_token || await dispatch('user/getUserToken', null, {root: true})

    const req = {
      checked: payload.checked,
      unchecked: [],
      isBundle: false,
      user_token: userToken
    }

    state.cartProducts.forEach((obj,key) => {
      if(req.checked.indexOf(parseInt(obj.id)) === -1){
        req.unchecked.push(obj.id)

        commit('UPDATE_SELECTED', {
          key: key,
          value: 2,
        })
      }else{
        commit('UPDATE_SELECTED', {
          key: key,
          value: 1,
        })
      }
      if(!req.isBundle && obj.bundle_deal){
        req.isBundle = true
      }
    })

    const {data} = await Service.cartChanged(req, this.$auth.strategy.token.get())
    if(data?.status !== 200){
      commit('common/SET_TOAST_ERROR', data.data.form[0], {root: true})

    }else {
      if(req.isBundle){
        dispatch('getCartByUser', {lang: lang, params: {user_token: userToken}})
      }
    }
  },
  /*
   * Take an upgrade on a line that is already in the basket.
   *
   * The basket is refetched rather than patched. A switch may merge two lines
   * into one, and the row the server writes carries the product, inventory,
   * tax rule and shipping relations the summary reads -- none of which this
   * action could reconstruct. Same reason cartAction refetches for a line the
   * basket has not seen before.
   */
  async cartSwitchVariant ({ commit, dispatch }, { payload, lang }) {
    const userToken = payload?.user_token
      || await dispatch('user/getUserToken', null, { root: true })

    const { data } = await Service.cartSwitchVariant(
      { ...payload, user_token: userToken },
      this.$auth.strategy.token.get()
    )

    if (data?.status === 200) {
      await dispatch('getCartByUser', {
        lang,
        params: { user_token: userToken }
      })

      return data.data
    }

    /*
     * A refused upgrade is a real outcome, not an error page: the variant sold
     * out, or stopped being offered, while the page was open. It is named in
     * the body, under whichever field it is about.
     */
    if (data?.status === 201) {
      commit('common/SET_TOAST_ERROR', cartRefusalMessage(data), { root: true })

      return null
    }

    return Promise.reject({ statusCode: data?.status, message: data?.message })
  },
  /*
   * What delivery costs for this basket, before anything is saved: the price
   * and row the server would charge for the address (or collection), and the
   * lines that cannot be sent there. Nothing is committed - the page shows it
   * and update-shipping decides it again when the shopper continues.
   *
   * The bearer is null for a guest, never false: the service only recognises
   * "null" as signed out, and a guest's basket is found by their user_token.
   */
  async shippingQuote (ctx, payload) {
    const bearer = this.$auth.loggedIn ? this.$auth.strategy.token.get() : null
    const {data} = await Service.cartShippingQuote(payload, bearer)

    return data
  },
  async updateCartShipping ({ commit }, payload) {
    const {data} = await Service.updateCartShipping(payload, this.$auth.strategy.token.get())
    if(data?.status === 200){
      commit('INSERT_CART_SHIPPING', data.data)
    }
    return data
  },
  /*
   * The live store-wide promotion.
   *
   * Dispatched by every page that shows what the basket costs, not just the
   * one carrying the banner: the amount is derived from this, so a checkout
   * reached directly has to be able to fetch it for itself.
   *
   * Never rejects. It decorates a basket that is already correct, and the
   * order endpoint decides the discount again regardless of what this says,
   * so a failure costs a banner rather than an order.
   */
  async loadFlashPromotion ({ commit }) {
    try {
      const { data } = await Service.getRequest(null, 'flashDiscount')

      commit('SET_FLASH_PROMOTION', data && data.active ? data : null)

    } catch (e) {
      commit('SET_FLASH_PROMOTION', null)
    }
  },
  emptyCartProduct ({ commit }) {
    commit('EMPTY_CART_PRODUCT')
  },
  /*
   * Everything in this module that belongs to one shopper's basket.
   *
   * The lines are the obvious part, but a delivery address, a collection
   * contact, a chosen payment method and the discounts a pending card payment
   * was priced with all belong to the person who made those choices — and all
   * of them are persisted to localStorage, so none of them may survive a
   * change of identity.
   */
  resetCartState ({ commit }) {
    commit('EMPTY_CART_PRODUCT')
    commit('SET_SELECTED_ADDRESS', null)
    commit('SET_COLLECTION_CONTACT', null)
    commit('SET_PAYMENT_METHOD', null)
    commit('SET_PENDING_PAYMENT', null)
    commit('SET_FLASH_PROMOTION', null)
    commit('SET_FLASH_DISCOUNT_OPT_IN', false)
    commit('SET_LOYALTY_DISCOUNT', null)
    commit('SET_BUNDLE_DISCOUNT', null)
  },

  /**
   * Ask the server what the Essentials Bundles in this basket are worth.
   *
   * Nothing about the basket is sent: the server reads the caller's own lines,
   * because a discount the page could describe is a discount the page could
   * invent. Re-read after anything that changes the basket, since removing one
   * item of a bundle ends the discount for the rest of it.
   *
   * Never rejects. This is a line in the summary on top of a basket that is
   * already correct, and checkout applies the discount itself regardless of
   * what this says -- so a failure costs a row, never an order.
   */
  async loadBundleDiscount({commit, dispatch}) {
    try {
      const userToken = await dispatch('user/getUserToken', null, {root: true})

      const {data} = await Service.bundleDiscount(
        {user_token: userToken},
        this.$auth.strategy.token.get()
      )

      commit(
        'SET_BUNDLE_DISCOUNT',
        data?.status === 200 && Number(data.data?.total) > 0 ? data.data : null
      )

    } catch (e) {
      commit('SET_BUNDLE_DISCOUNT', null)
    }
  },
  /*
   * Bring the basket into line with whoever is signed in now.
   *
   * The cart module is persisted to localStorage, so without this the basket
   * a shopper built as a guest is still on screen after they sign in — and,
   * worse, still on screen for whoever uses the browser next. Emptying first
   * means a refresh that arrives late never shows the previous identity's
   * lines, and a refresh that fails leaves an empty basket rather than
   * somebody else's.
   *
   * `mergeToken` is the guest token to hand over on the way in. Sign-in
   * through /user/signin has already merged it server-side; asking again is
   * how the social-provider redirect gets its basket, and the endpoint is
   * idempotent, so the duplicate ask costs a request and changes nothing.
   */
  async adoptIdentity ({ dispatch }, {lang, mergeToken} = {}) {

    dispatch('resetCartState')

    if (mergeToken && this.$auth.loggedIn) {
      try {
        await Service.cartMerge(mergeToken, this.$auth.strategy.token.get())
      } catch (e) {
        // The merge that matters ran at sign-in; this one is a safety net, and
        // a failed safety net must not stop the basket from loading.
      }
    }

    try {
      await dispatch('getCartByUser', {lang})
    } catch (e) {
      // An empty basket on screen is the honest answer until the next attempt.
    }
  },
  /*
   * After an order. The count is recomputed from what is left rather than
   * reduced by what was bought: a Buy Now order bought something the badge
   * never counted, and subtracting it took the basket's own count down.
   */
  /** The shopper has read these notices: drop them here and on the server. */
  async dismissBasketNotices ({ commit, dispatch }, ids) {
    commit('DROP_BASKET_NOTICES', ids)

    try {
      const userToken = this.$auth.loggedIn ? null : await dispatch('user/getUserToken', null, {root: true})
      await Service.dismissBasketNotices({ ids, user_token: userToken }, this.$auth.loggedIn ? this.$auth.strategy.token.get() : null)
    } catch (e) {
      // Shown again on the next basket read at worst.
    }
  },
  /*
   * "Add them back" on an emptied basket. Each line is added the normal way,
   * at today's price and only while in stock; the answer says what came back
   * and what could not. The basket is re-read either way.
   */
  async restoreBasketNotice ({ commit, dispatch }, id) {
    const userToken = this.$auth.loggedIn ? null : await dispatch('user/getUserToken', null, {root: true})
    const { data } = await Service.restoreBasketNotice({ id, user_token: userToken }, this.$auth.loggedIn ? this.$auth.strategy.token.get() : null)

    commit('DROP_BASKET_NOTICES', [id])

    try {
      await dispatch('getCartByUser', { params: { user_token: userToken } })
    } catch (e) {
      // The basket page offers its own retry.
    }

    return data
  },
  subtractCartProductCount ({ commit, state }, payload) {
    commit('EMPTY_CART_PRODUCT_ONLY', payload?.status)
    commit('SET_CART_COUNT', basketQuantity(state.cartProducts))
  },
  /*
   * Leave a Buy Now checkout. The basket page asks for this when it opens: a
   * shopper back at the basket has left that one-item checkout, and a
   * forgotten one must not take over the next checkout started from here.
   * The basket itself is not touched.
   */
  async cancelBuyNow ({ state, commit, dispatch }) {
    try {
      const userToken = this.$auth.loggedIn ? null : await dispatch('user/getUserToken', null, {root: true})
      const bearer = this.$auth.loggedIn ? this.$auth.strategy.token.get() : null

      if (this.$auth.loggedIn || userToken) {
        await Service.cancelBuyNow({user_token: userToken}, bearer)
      }
    } catch (e) {
      // The server clears an abandoned Buy Now after 30 minutes regardless.
    }

    if ((state.cartProducts || []).some(isBuyNow)) {
      commit('SET_CART_PRODUCTS', basketLines(state.cartProducts))
    }
  },
  setCartCount ({ commit }, count) {
    commit('SET_CART_COUNT', count)
  },
}

export {
  state,
  getters,
  mutations,
  actions
}
