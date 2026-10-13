import Service from '~/services/service'

export default function ({
  $axios,
  $auth,
  store,
  redirect
}) {

  $axios.onRequest((config) => {

    const token =
      $auth.strategy.token.get()

    if (token) {

      config.headers.common.Authorization =
        token
    }

    return config
  })

  /*
   * The server revokes a suspended account's tokens, so an open tab finds out
   * at its next request rather than at its next sign-in. Everything after that
   * is the browser catching up: drop the dead session, say why, and put them
   * on the sign-in page.
   *
   * Guarded so a burst of failing requests from one page does not sign out
   * four times over and stack four toasts.
   */
  let signingOut = false

  Service.setUnauthorizedHandler(async (message) => {

    if (signingOut || !$auth.loggedIn) {
      return
    }

    signingOut = true

    try {
      // Through the store action, so the stored session id goes too.
      await store.dispatch('user/logout')
    } catch (e) {
      // Already signed out, or the logout call itself was refused.
    }

    if (message) {
      store.dispatch('common/setToastError', message)
    }

    redirect('/login')
  })

  // A fresh sign-in re-arms it.
  $auth.$storage.watchState('loggedIn', (loggedIn) => {
    if (loggedIn) {
      signingOut = false
    }
  })
}
