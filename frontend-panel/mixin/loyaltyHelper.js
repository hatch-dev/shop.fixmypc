/*
 * The customer's loyalty group discount, shared by the basket and the
 * checkout so the two cannot show different figures for the same basket.
 *
 * The amount is never worked out in the browser. Which group applies depends
 * on the customer's memberships, whether a one-time benefit has already been
 * spent and whether the validity window has closed - none of which the client
 * is entitled to decide. The order endpoint recalculates it from scratch
 * server-side, so what happens here only affects what is displayed.
 */
export default {

  computed: {
    loyaltyDiscount() {
      return this.$store.state.cart.loyaltyDiscount?.amount || 0
    },
    loyaltyTitle() {
      return this.$store.state.cart.loyaltyDiscount?.title || ''
    }
  },

  methods: {

    /**
     * @param {number} subtotal goods subtotal, before shipping and tax
     */
    async loadLoyaltyDiscount(subtotal) {

      if (!this.$auth?.loggedIn) {
        this.$store.commit('cart/SET_LOYALTY_DISCOUNT', null)
        return
      }

      const amount = Number(subtotal || 0)

      if (!(amount > 0)) {
        this.$store.commit('cart/SET_LOYALTY_DISCOUNT', null)
        return
      }

      /*
       * Baskets change faster than the network answers. Without this, an
       * earlier reply landing after a later one would leave the shopper
       * looking at a discount for a basket they no longer have.
       */
      const request = (this._loyaltyRequest || 0) + 1
      this._loyaltyRequest = request

      try {
        /*
         * Same resolution as mixin/util.js. API_BASE is deliberately empty
         * when the storefront is served by Laravel itself, and an empty
         * string is falsy -- so `process.env.apiBase || '<prod host>'` sent
         * every local and staging basket's loyalty lookup to the live site,
         * where the local token is not valid. The discount was computed
         * correctly and then thrown away by the catch.
         */
        const configured = (process.env.apiBase || '').trim()

        const baseUrl = configured
          || (typeof window !== 'undefined' ? window.location.origin + '/' : '/')

        // Registered inside the auth:user group, which carries a /user prefix.
        const res = await this.$axios.get(`${baseUrl}api/v1/user/loyalty-discount`, {
          params: { subtotal: amount },
          headers: { Authorization: this.$auth.strategy.token.get() }
        })

        if (this._loyaltyRequest !== request) {
          return
        }

        /*
         * Kept when there is no discount yet but there IS one within reach --
         * that is the whole point of the "add X more" nudge. Storing only on
         * `active` would throw the nudge away before it could be shown.
         */
        const payload = res?.data

        this.$store.commit(
          'cart/SET_LOYALTY_DISCOUNT',
          payload && (payload.active || payload.next) ? payload : null
        )

      } catch (e) {
        /*
         * A failure here must never block checkout. Nothing is shown, and if a
         * discount is genuinely due the server still applies it to the order.
         */
        if (this._loyaltyRequest === request) {
          this.$store.commit('cart/SET_LOYALTY_DISCOUNT', null)
        }
      }
    },

    clearLoyaltyDiscount() {
      this.$store.commit('cart/SET_LOYALTY_DISCOUNT', null)
    }
  }
}
