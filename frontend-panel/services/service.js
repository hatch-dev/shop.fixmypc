import axios from 'axios'
import json from '~/config.json'

const apiBase = !process.env.apiBase.trim() ? window.location.origin + '/' : process.env.apiBase

const apiClient = axios.create({
  baseURL: apiBase + json.api.url,
  withCredentials: false,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json'
  }
})

/*
 * The guest token the server issued this visitor, kept here so every call can
 * carry it.
 *
 * Only a token the server handed back is stored (store/user.js sets it after
 * the guest-token exchange), never a raw localStorage value: `guest.token`
 * rejects tokens it did not issue, so sending a stale one would fail every
 * request rather than just the guest ones.
 */
let guestToken = null

const setGuestToken = (token) => {
  guestToken = token || null
}

/*
 * What to do when the server says this session is no longer authenticated.
 *
 * Suspending an account revokes its tokens server-side, so the next call from
 * an open tab comes back 401. Without this the tab carried on showing a signed-
 * in shell over a dead session: every request failed, nothing explained why,
 * and the customer had to work out for themselves that they were logged out.
 *
 * Registered from the axios-auth plugin, which is where $auth lives.
 */
let onUnauthorized = null

const setUnauthorizedHandler = (handler) => {
  onUnauthorized = typeof handler === 'function' ? handler : null
}

/*
 * Has this response told us the session is over?
 *
 * Two shapes, and the second is the reason a plain `status === 401` check was
 * not enough. Most refusals in this API answer HTTP 201 and carry the real
 * outcome in the body, which axios treats as a SUCCESS - so an expired or
 * revoked session arrived on the success path and nothing noticed. The page
 * kept its signed-in shell while every call quietly failed.
 */
const isSessionEnded = (payload, httpStatus) => {

  if (httpStatus === 401) {
    return true
  }

  if (!payload || typeof payload !== 'object') {
    return false
  }

  // The flag the server sets, and the app's own "Unauthenticated." shape.
  return payload.session_ended === true || payload.status === 204
}

apiClient.interceptors.response.use(
  (response) => {

    if (onUnauthorized && isSessionEnded(response?.data, response?.status)) {
      onUnauthorized(response?.data?.message || '')
    }

    return response
  },
  (error) => {

    if (onUnauthorized && isSessionEnded(error?.response?.data, error?.response?.status)) {
      // The server's own sentence, so the reason shown matches the reason given.
      onUnauthorized(error.response?.data?.message || '')
    }

    return Promise.reject(error)
  }
)

apiClient.interceptors.request.use(config => {

  if (process.client) {
    const sessionId = localStorage.getItem('session_id')

    if (sessionId) {
      config.headers['Session-Id'] = sessionId
    }
  }

  /*
   * Identify the guest on every call, the way the bearer token identifies a
   * signed-in customer. Ownership of a cart, a wishlist or an address is keyed
   * on this token, so without it the API cannot tell whose rows to return —
   * which is why a guest's wishlisted hearts came back empty.
   *
   * Skipped when a bearer is present (the customer is signed in) and when the
   * caller already put a token on the request.
   */
  const authorization = config.headers?.Authorization
    || config.headers?.common?.Authorization

  const signedIn = typeof authorization === 'string'
    && authorization.trim().length > 0
    && authorization !== 'null'

  if (guestToken && !signedIn) {

    if (config.method === 'get' || config.method === 'delete') {

      config.params = {...(config.params || {})}

      if (!config.params.user_token) {
        config.params.user_token = guestToken
      }

    } else if (config.data && typeof config.data === 'object' && !(config.data instanceof FormData)) {

      if (!config.data.user_token) {
        config.data = {...config.data, user_token: guestToken}
      }
    }
  }

  return config
})

export default {
  setGuestToken,
  setUnauthorizedHandler,
  /*
   * The supplier procurement pages.
   *
   * On the shared apiClient rather than a hand-built URL: API_BASE is
   * empty when the storefront is served by Laravel, and only this client
   * knows to fall back to window.location.origin for that. Building the
   * path by hand sent the call to whatever host the page happened to be
   * on -- the dev server on :3000, where there is no API.
   *
   * No auth header: the encrypted token in the link is the credential.
   */
  supplierRequest(token) {
    return apiClient.get(`${json.api.supplierRequest}/${encodeURIComponent(token)}`)
  },
  submitSupplierBid(params) {
    return apiClient.post(json.api.supplierBid, params)
  },
  common(lang = null) {
    if (lang) {
      apiClient.defaults.headers['Lang'] = lang
    } else {
      if (apiClient.defaults.headers?.Lang) {
        delete apiClient.defaults.headers['Lang']
      }
    }

    return apiClient.get(json.api.common)
  },
  home(params, lang = null, token = null) {

    if (lang) {

      apiClient.defaults.headers['Language'] =
        lang

    } else {

      if (apiClient.defaults.headers?.Language) {

        delete apiClient.defaults.headers['Language']
      }
    }

    return apiClient.get(
      json.api.home,
      {
        params: params,

        headers: {
          Authorization: token
        }
      }
    )
  },
  categories(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    return apiClient.get(json.api.categories, {
      params: params
    })
  },
  brands(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.get(json.api.brands, {
      params: params
    })
  },
  products(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.get(json.api.products, {
      params: params
    })
  },
  flashProducts(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.get(`${json.api.flashProducts}/${params.id}`, {
      params: params
    })
  },
  flashSales(lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.get(`${json.api.flashSales}`)
  },
  clearance(lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.get(json.api.clearance)
  },
  search(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.get(json.api.search, {
      params: params
    })
  },
  product(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    return apiClient.get(`${json.api.product}/${params.id}`, {
      params: params
    })
  },
  page(params, lang = null) {

    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    return apiClient.get(`${json.api.page}/${params.slug}`)
  },
  reviews(params) {
    return apiClient.get(`${json.api.reviews}/${params.id}`, {
      params: params
    })
  },
  // bearer: the signed-in customer, so "Recommended for you" can follow what
  // they browse; null for a guest (see falsy-bearer note on the interceptor)
  suggestedProducts(id, page, lang = null, bearer = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }
    apiClient.defaults.headers.common['Authorization'] = bearer

    return apiClient.get(`${json.api.suggested_products}/${id}?page=${page}`)
  },
  userLogin(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.login, params)
  },
  userRegistration(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.register, params)
  },
  userVerification(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.verify, params)
  },
  /* ── two-factor authentication (all require a signed-in user) ── */
  twoFactorSetup(bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.twoFactorSetup, {})
  },
  twoFactorConfirm(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.twoFactorConfirm, params)
  },
  twoFactorSendSms(bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.twoFactorSendSms, {})
  },
  twoFactorConfirmSms(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.twoFactorConfirmSms, params)
  },
  twoFactorStatus(bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(json.api.twoFactorStatus)
  },
  twoFactorDisable(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.twoFactorDisable, params)
  },
  /* ── proving the mobile number, with or without turning on 2FA ── */
  phoneSendCode(bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.phoneSendCode, {})
  },
  phoneVerify(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.phoneVerify, params)
  },
  /*
   * These two verify a code and hand back the session payload WITHOUT going
   * through nuxt-auth. That matters: nuxt-auth watches `loggedIn` and redirects
   * the instant it flips, leaving no window to show the success state first.
   * The caller sets the token itself once it has shown the confirmation.
   */
  userVerifyTwoFactor(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.twoFactorChallenge, params)
  },
  userVerifyLoginCode(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.verifyLoginCode, params)
  },
  userSendLoginCode(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.sendLoginCode, params)
  },
  userForgotPassword(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.forgotPassword, params)
  },
  userCheckResetToken(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.checkResetToken, params)
  },
  userResetPassword(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.resetPassword, params)
  },
  userUpdatePassword(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.updatePassword, params)
  },
  userAddressAll(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(json.api.userAddressAll, {params: params})
  },
  userAddressAction(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.userAddressAction, params)
  },
  userAddressDelete(id, bearer, params = null) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.delete(`${json.api.userAddressDelete}/${id}`, {params: params})
  },
  userWishlistAll(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(json.api.userWishlistAll, {params: params})
  },
  userWishlistAction(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.userWishlistAction, params)
  },
  userVouchers(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(json.api.userVouchers, {params: params})
  },
  updateProfile(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.updateProfile, params)
  },
  updateUserPassword(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.updateUserPassword, params)
  },

  userLogout() {
    return apiClient.get(json.api.logout)
  },
  // Ask the server for a guest session token (QA audit SEC-02).
  guestToken(existing = null) {
    return apiClient.post(json.api.guestToken, {user_token: existing})
  },
  guestVerifyEmail(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.guestVerifyEmail, params)
  },
  guestVerifyEmailCheck(params, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    return apiClient.post(json.api.guestVerifyEmailCheck, params)
  },
  cartByUser(bearer, lang = null, params = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(`${json.api.cartByUser}`, {params: params})
  },
  cartAction(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.cartAction, params)
  },

  /*
   * Cross-sell.
   *
   * The read is open to everybody, guests included, so the bearer is passed
   * when there is one and the guest's own user_token rides in the params when
   * there is not -- exactly how the rest of the basket identifies a caller.
   */
  crossSellsForProduct(productId, params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer

    return apiClient.get(`${json.api.crossSellsForProduct}/${productId}/cross-sells`, {params})
  },

  crossSellsForCart(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer

    return apiClient.get(json.api.crossSellsForCart, {params})
  },

  /*
   * What the Essentials Bundles in this basket are worth. The server reads the
   * basket itself; the token only says whose.
   */
  bundleDiscount(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer

    return apiClient.get(json.api.bundleDiscount, {params})
  },

  addCrossSell(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer

    return apiClient.post(json.api.addCrossSell, params)
  },

  /* Move a basket line onto a better variant of the same product. */
  cartSwitchVariant(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.cartSwitchVariant, params)
  },
  buyNow(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer

    return apiClient.post(json.api.buyNow, params)
  },
  cartDelete(cartId, bearer, userToken = null) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    // Sent as a query param: a DELETE body is not reliably forwarded.
    return apiClient.delete(`${json.api.cartDelete}/${cartId}`, {
      params: userToken ? {user_token: userToken} : {}
    })
  },
  cartChanged(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.cartChanged, params)
  },
  /*
   * Hand a guest basket to the account that just signed in.
   *
   * Signing in through /user/signin already does this server-side, so this is
   * for the ways in that do not pass through it — a social provider redirect —
   * and as a retry for one we could not confirm. The endpoint consumes the
   * guest rows, so calling it twice moves nothing.
   */
  dismissBasketNotices(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.basketNoticesDismiss, params)
  },
  restoreBasketNotice(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.basketNoticeRestore, params)
  },
  /** Leave a Buy Now checkout: its line goes, the basket stays as it was. */
  cancelBuyNow(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.cancelBuyNow, params)
  },
  /** Can the current checkout still be bought? Asked before a pay-first gateway charges. */
  checkoutCheck(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.checkoutCheck, params)
  },
  cartMerge(userToken, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.cartMerge, {user_token: userToken})
  },
  updateCartShipping(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.updateCartShipping, params)
  },
  cartShippingQuote(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(json.api.cartShippingQuote, params)
  },
  addressByUser(userId, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(`${json.api.addressByUser}/${userId}`)
  },
  addressAction(params, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(`${json.api.addressAction}/${params.id}`, params)
  },
  addressDelete(addressId, bearer) {
    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.delete(`${json.api.addressDelete}/${addressId}`)
  },
  orderByUser(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }
    if (bearer) {
      apiClient.defaults.headers.common['Authorization'] = bearer
    }
    return apiClient.post(`${json.api.orderByUser}`, params)
  },
  orderAction(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(`${json.api.orderAction}`, params)
  },
  paymentDone(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(`${json.api.paymentDone}`, params)
  },
  cancelOrder(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    if (bearer) {
      apiClient.defaults.headers.common['Authorization'] = bearer
    }

    return apiClient.post(`${json.api.cancelOrder}`, params)
  },
  cancellationFind(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(`${json.api.cancellationFind}/${params.id}`, {params: params})
  },
  ratingReviewAction(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(`${json.api.ratingReviewAction}`, params)
  },
  ratingReviewFind(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(`${json.api.ratingReviewFind}/${params.id}`, {params: params})
  },
  voucherValidity(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.post(`${json.api.voucherValidity}`, params)
  },
  contactUs(params) {
    return apiClient.post(json.api.contact, params)
  },
  sendOrderEmail(params, bearer, lang = null) {
    if (lang) {
      apiClient.defaults.headers.common['Language'] = lang
    }

    apiClient.defaults.headers.common['Authorization'] = bearer
    return apiClient.get(`${json.api.sendOrderEmail}/${params.id}`, {params: params})
  },
  ssrGetRequest(params, api, bearer = null, lang = null) {
    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    if (bearer) {
      apiClient.defaults.headers['Authorization'] = bearer
    }
    return apiClient.get(json.api[api], {params: params})
  },
  getRequest(params, api, bearer = null, lang = null) {
    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    if (bearer) {
      apiClient.defaults.headers.common['Authorization'] = bearer
    }
    return apiClient.get(json.api[api], {params: params})
  },
  deleteRequest(api, bearer, lang = null, id = null, params = null) {
    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    if (bearer) {
      apiClient.defaults.headers.common['Authorization'] = bearer
    }

    const url = id ? `${json.api[api]}/${id}` : json.api[api]

    return apiClient.delete(url, {params: params})
  },

  postRequest(params, api, bearer = null, lang = null) {
    if (lang) {
      apiClient.defaults.headers['Language'] = lang
    } else {
      if (apiClient.defaults.headers?.Language) {
        delete apiClient.defaults.headers['Language']
      }
    }

    if (bearer) {
      apiClient.defaults.headers.common['Authorization'] = bearer
    }
    return apiClient.post(json.api[api], params)
  },
}
