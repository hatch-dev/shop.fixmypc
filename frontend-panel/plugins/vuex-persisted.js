import createPersistedState from 'vuex-persistedstate'

/*
 * The figures the SERVER decides for a basket line, which must never be
 * restored from localStorage.
 *
 * This list is the same one store/cart.js copies off a write response, and for
 * the same reason: every one of them is an answer to "what does this line cost
 * THIS customer, right now". None of them is a property of the basket.
 *
 * They go stale for reasons the browser cannot see — a trade application is
 * approved, an account is suspended, a flash sale starts or ends, staff edit a
 * quantity band or a tax rule, or the payload itself grows a field it did not
 * have when the snapshot was written. A rehydrated row keeps whatever it was
 * saved with and looks completely normal, because the title, the image and the
 * quantity are all still right.
 *
 * That is what put "VAT €0.00" under a correct Products Total on the payment
 * page: rows written by an older build, restored into a newer one, carrying a
 * price but no VAT split. Stripping the money here means the worst a stale
 * snapshot can do is show a basket with no prices for the moment it takes the
 * server to answer — never a wrong price, and never a wrong tax.
 */
const SERVER_DECIDED = [
  'price', 'unit_price', 'upsell_price', 'original_price', 'line_total',
  'vat_rate', 'vat_inclusive',
  'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
  'line_ex_vat', 'line_vat', 'line_inc_vat',
  'line_list', 'line_list_ex_vat', 'line_list_inc_vat',
]

/*
 * Shipping rates are money too. The line's shipping place and the product's
 * shipping rule both carry prices, and a saved copy outlives an admin edit:
 * after the Ireland rule went from €16 to €24 a reloaded /shipping still
 * priced "Send by Post" at €16 a line from localStorage.
 */
const SERVER_DECIDED_SHIPPING = ['shipping_place', 'available_shipping']

function withoutServerPricing (row) {
  if (!row || typeof row !== 'object') return row

  const kept = { ...row }

  SERVER_DECIDED.forEach((field) => { delete kept[field] })
  SERVER_DECIDED_SHIPPING.forEach((field) => { delete kept[field] })

  if (kept.flash_product && typeof kept.flash_product === 'object') {
    const product = { ...kept.flash_product }
    delete product.shipping_rule
    kept.flash_product = product
  }

  return kept
}

export default ({ store, app }) => {
  createPersistedState({
    key: 'shop',
    paths: ['cart'],

    /*
     * What gets written to localStorage. The basket's identity — which lines,
     * which variants, which quantities, which are ticked — and none of its
     * money.
     */
    reducer (state) {
      const cart = state.cart || {}

      const kept = {
        ...cart,
        cartProducts: (cart.cartProducts || []).map(withoutServerPricing),
      }

      /*
       * The promotion discounts are server figures too, and the spread of
       * the whole cart module was carrying them across.
       *
       * Only the basket page recalculates the flash discount -- the component
       * that does it lives inside CartList and nowhere else -- so a restored
       * amount was never checked again. A shopper who reloaded on /checkout
       * was still shown, and still had the voucher call priced against, a
       * discount whose window had closed or whose basket had changed. Left
       * out here, they are rebuilt from the live promotion instead.
       */
      // flashDiscountOptIn is deliberately NOT deleted: it is the shopper's
      // own answer, and it has to survive the trip to the checkout. The
      // promotion it applies to is server data and is refetched.
      delete kept.flashPromotion
      delete kept.loyaltyDiscount
      delete kept.bundleDiscount
      // Server state, re-read with the basket: a dismissed notice must not
      // come back from a saved copy.
      delete kept.basketNotices

      return { cart: kept }
    },
  })(store)

  /*
   * Refresh the basket once it is restored — but not before the app is ready.
   *
   * createPersistedState rehydrates synchronously above, so by here the store
   * holds the saved lines without their prices, and they need filling in for
   * the whole app rather than page by page. The timing is the hard part.
   *
   * Plugins run before the auth module has finished installing itself, so
   * `store.$auth` is still undefined at this point. getCartByUser reads
   * `this.$auth.strategy.token.get()`, so dispatching straight from here threw
   * "Cannot read properties of undefined (reading 'strategy')" — which is
   * exactly what a customer was shown where the VAT should have been.
   *
   * onNuxtReady fires after the root instance is mounted, by which time auth
   * is installed and a request carries the right identity. Firing immediately
   * instead does not merely fail: without a token it would fetch the GUEST
   * basket and overwrite a signed-in shopper's with it.
   */
  const lines = (store.state.cart && store.state.cart.cartProducts) || []

  if (!lines.length) {
    return
  }

  const refresh = () => {
    /*
     * Failures are handled rather than swallowed: getCartByUser records the
     * reason in the store, and the summary offers a retry from it. The catch
     * here only stops an unhandled rejection replacing the page with Nuxt's
     * error screen over something a retry may well fix.
     */
    store.dispatch('cart/getCartByUser', {
      lang: store.getters['language/langCode'] || null,
    }).catch(() => {})
  }

  if (typeof window !== 'undefined' && typeof window.onNuxtReady === 'function') {
    window.onNuxtReady(refresh)
  } else {
    // No Nuxt to wait for (tests, or a build that does not expose the hook).
    refresh()
  }
}
