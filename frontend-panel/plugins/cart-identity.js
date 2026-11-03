/*
 * Keep the basket on screen belonging to whoever is signed in.
 *
 * The cart module is persisted to localStorage (see vuex-persisted.js), which
 * is what makes a guest basket survive a reload — and, without this, what made
 * it survive signing in as well. The lines stayed on screen after the server
 * had already merged them into the account, so the quantities on screen were
 * the guest's, the ids were the guest's, and the first thing the shopper did
 * to one of them was refused. Signing out left the same stale copy for the
 * next person to use the browser.
 *
 * Both transitions are handled here rather than in the login page, because
 * there are several ways into an account (password, a sign-in code, two-factor,
 * a social provider) and several ways out (the header menu, an expired token,
 * another tab), and every one of them moves this flag.
 */
export default function ({app, store}) {

  /*
   * Keep the basket while the shopper shops.
   *
   * A basket's units are reserved for a set time (Settings > Basket
   * reservation, 20 minutes by default). That time used to count from the
   * last thing added, so a shopper who added four items and went on browsing
   * came back to an emptied basket. Each page they visit now restarts it
   * (cart/markBasketActivity -> CartHold::touch): the basket is released
   * only once nobody has touched the shop for the whole period.
   *
   * At most once a minute - the timer is measured in minutes - and only
   * while the basket holds something, so an empty basket costs nothing.
   *
   * Registered before the $auth check below: this plugin runs before the
   * auth module's own plugin (see .nuxt/index.js), so app.$auth is not set
   * yet here. The action reads $auth when a page changes, by which time it is.
   */
  const ACTIVITY_EVERY_MS = 60 * 1000
  let lastActivity = 0

  if (app.router) {
    app.router.afterEach(() => {
      if (!(store.getters['cart/cartCount'] > 0)) {
        return
      }

      const now = Date.now()

      if (now - lastActivity < ACTIVITY_EVERY_MS) {
        return
      }

      lastActivity = now
      store.dispatch('cart/markBasketActivity')
    })
  }

  const auth = app.$auth

  if (!auth || !auth.$storage) {
    return
  }

  const readGuestToken = () => {
    try {
      return localStorage.getItem('user_token')
    } catch (e) {
      return null
    }
  }

  // false when the shopper is on the default language, which is what the cart
  // endpoints expect to mean "send no Language header".
  const langCode = () => store.getters['language/langCode'] || null

  let previous = auth.loggedIn

  auth.$storage.watchState('loggedIn', async (loggedIn) => {

    // watchState fires on every write to the flag, not only on changes.
    if (loggedIn === previous) {
      return
    }

    previous = loggedIn

    if (loggedIn) {

      /*
       * The guest token is read before the basket is refreshed and dropped
       * after: sign-in has already merged it server-side, but a social
       * provider comes back on a redirect with no chance to send it, so the
       * merge is asked for again. It is idempotent, so the account that did
       * merge at sign-in is unaffected.
       */
      const guestToken = readGuestToken()

      await store.dispatch('cart/adoptIdentity', {
        lang: langCode(),
        mergeToken: guestToken
      })

      if (guestToken) {
        store.dispatch('user/clearUserToken')
      }

      return
    }

    // Signed out. Nothing of the account's basket may be left behind, and the
    // shopper starts again as an anonymous visitor with no basket of their own.
    store.dispatch('cart/resetCartState')
    store.dispatch('user/clearUserToken')
  })
}
