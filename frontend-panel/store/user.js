import Service from '@/services/service.js'

/*
 * The guest-token request in flight, if any. Module-level rather than in
 * state: it is a promise, not data, and must not be serialised or watched.
 */
let pendingUserToken = null

// The wallet-balance request in flight, shared by everyone who asks meanwhile.
let pendingWalletBalance = null

const state = () => ({
  userToken: null,
  profile: null,
  vouchers: null,
  allAddress: [],
  /*
   * The signed-in shopper's wallet balance; null until first loaded. Kept
   * here, not in the payment panel, so a balance fetched on /shipping is
   * already on screen when /checkout opens - it used to be the last request
   * the panel fired and arrived after everything else on the page.
   */
  walletBalance: null
})
const getters = {
  userToken: ({userToken}) => userToken,
  profile: ({profile}) => profile,
  vouchers: ({vouchers}) => vouchers,
  allAddress: ({allAddress}) => allAddress,
  walletBalance: ({walletBalance}) => walletBalance
}
const mutations = {
  SET_WALLET_BALANCE(state, balance) {
    state.walletBalance = balance === null ? null : Number(balance || 0)
  },
  SET_USER_TOKEN(state, userToken) {
    localStorage.setItem('user_token', userToken)
    state.userToken = userToken
  },
  /*
   * Forget the guest identity.
   *
   * Once a guest token has been handed to an account its basket, addresses and
   * orders have all moved across, so the token names nothing. Keeping it would
   * hand the same spent identity to whoever uses the browser next; dropping it
   * means their first guest action mints a fresh one.
   */
  CLEAR_USER_TOKEN(state) {
    try {
      localStorage.removeItem('user_token')
    } catch (e) {
      // Storage unavailable (private mode); the in-memory token still goes.
    }
    state.userToken = null
  },
  EMPTY_VOUCHER(state) {
    state.vouchers = null
  },
  SET_PROFILE(state, profile) {
    state.profile = profile
  },
  UPDATE_VOUCHERS(state, vouchers) {
    state.vouchers = vouchers
  },
  ALL_ADDRESS(state, allAddress) {
    state.allAddress = allAddress
  },
  UPDATE_ADDRESS(state, address) {
    const index = state.allAddress.data.findIndex(obj => {
      return parseInt(obj.id) === parseInt(address.id)
    })
    if (index > -1) {
      state.allAddress.data.splice(index, 1, address)
    } else {
      state.allAddress.data.unshift(address)
    }
  }
}

const actions = {
  /*
   * Fetch the wallet balance. Concurrent callers share one request; a guest
   * has no wallet. The panel re-asks on every mount, so the figure is fresh
   * while the one already held shows in the meantime.
   */
  loadWalletBalance({ commit }) {
    if (!this.$auth?.loggedIn) {
      commit('SET_WALLET_BALANCE', 0)
      return Promise.resolve(0)
    }

    if (pendingWalletBalance) return pendingWalletBalance

    const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

    pendingWalletBalance = this.$axios.get(`${baseUrl}api/v1/user/wallet`, {
      headers: { Authorization: this.$auth.strategy.token.get() }
    })
      .then(({ data }) => {
        commit('SET_WALLET_BALANCE', data?.balance || 0)
        return data?.balance || 0
      })
      .catch((e) => {
        console.error('Wallet fetch error:', e)
        return 0
      })
      .finally(() => { pendingWalletBalance = null })

    return pendingWalletBalance
  },
  /*
   * QA audit SEC-02.
   *
   * This used to build the guest token here, out of Math.random() and the
   * clock, and the server accepted whatever it was handed. A guest token is a
   * credential — it opens that guest's cart, addresses and orders — so the
   * server issues it now, and rejects any it did not issue.
   *
   * An existing token is passed up so a returning guest keeps their basket;
   * the server hands the same one back if it is still valid.
   */
  async getUserToken({state, commit}) {

    /*
     * A signed-in customer has no use for one, and minting one anyway put a
     * fresh guest identity in localStorage on every page load — which the next
     * sign-out would then hand to whoever used the browser next. The layout
     * asks for a token before it knows who it is talking to, so the guard
     * belongs here rather than at each call site.
     */
    if (this.$auth?.loggedIn) {
      return null
    }

    if (state.userToken) {
      return state.userToken
    }

    /*
     * One mint per page, however many callers ask at once. The layout, the
     * basket and the product page all ask in the same tick, before the first
     * answer is back, and each used to mint its own token: the basket was
     * written under one identity and read back under another, and the
     * recommendations could not see what was in it.
     */
    if (pendingUserToken) {
      return pendingUserToken
    }

    pendingUserToken = (async () => {
      const stored = localStorage.getItem('user_token')

      try {
        const {data} = await Service.guestToken(stored)
        const issued = data?.data?.user_token || data?.user_token

        if (issued) {
          commit('SET_USER_TOKEN', issued)

          // Let every subsequent call carry it, so the API knows whose cart and
          // wishlist to answer with. Only the server-issued token is shared this
          // way — a stale stored one would be rejected on every request.
          Service.setGuestToken(issued)

          return issued
        }
      } catch (e) {
        // Fall through: a guest with no token simply cannot use guest features
        // until the request succeeds, which is the safe direction to fail.
      }

      if (stored) {
        commit('SET_USER_TOKEN', stored)
      }

      return state.userToken
    })()

    try {
      return await pendingUserToken
    } finally {
      pendingUserToken = null
    }
  },
  /**
   * Drop the guest identity, in memory, in storage and on the API client.
   */
  clearUserToken({commit}) {
    commit('CLEAR_USER_TOKEN')
    Service.setGuestToken(null)
  },
  async login({commit}, {params, lang}) {
    const {data} = await Service.userLogin(params, lang)
    return data
  },
  async registration({commit}, {params, lang}) {
    const {data} = await Service.userRegistration(params, lang)
    return data
  },
  async verify({commit}, {params, lang}) {
    const {data} = await Service.userVerification(params, lang)
    return data
  },
  async twoFactorSetup() {
    const {data} = await Service.twoFactorSetup(this.$auth.strategy.token.get())
    return data
  },
  async twoFactorConfirm({commit}, params) {
    const {data} = await Service.twoFactorConfirm(params, this.$auth.strategy.token.get())
    return data
  },
  async twoFactorSendSms() {
    const {data} = await Service.twoFactorSendSms(this.$auth.strategy.token.get())
    return data
  },
  async twoFactorConfirmSms({commit}, params) {
    const {data} = await Service.twoFactorConfirmSms(params, this.$auth.strategy.token.get())
    return data
  },
  async twoFactorStatus() {
    const {data} = await Service.twoFactorStatus(this.$auth.strategy.token.get())
    return data
  },
  async twoFactorDisable({commit}, params) {
    const {data} = await Service.twoFactorDisable(params, this.$auth.strategy.token.get())
    return data
  },
  async phoneSendCode() {
    const {data} = await Service.phoneSendCode(this.$auth.strategy.token.get())
    return data
  },
  async phoneVerify({commit}, params) {
    const {data} = await Service.phoneVerify(params, this.$auth.strategy.token.get())
    return data
  },
  async verifyTwoFactor({commit}, {params, lang}) {
    const {data} = await Service.userVerifyTwoFactor(params, lang)
    return data
  },
  async verifyLoginCode({commit}, {params, lang}) {
    const {data} = await Service.userVerifyLoginCode(params, lang)
    return data
  },
  async sendLoginCode({commit}, {params, lang}) {
    const {data} = await Service.userSendLoginCode(params, lang)
    return data
  },
  async forgotPassword({commit}, {params, lang}) {
    const {data} = await Service.userForgotPassword(params, lang)
    return data
  },
  /*
   * Sign out through here rather than calling $auth.logout() directly, so the
   * stored session id goes with it — otherwise the next anonymous request
   * still carries the Session-Id header of the session we just ended.
   */
  async logout() {

    try {
      localStorage.removeItem('session_id')
    } catch (e) {
      // Storage unavailable (private mode); signing out still proceeds.
    }

    await this.$auth.logout()
  },
  async checkResetToken({commit}, {params, lang}) {
    const {data} = await Service.userCheckResetToken(params, lang)
    return data
  },
  async resetPassword({commit}, {params, lang}) {
    const {data} = await Service.userResetPassword(params, lang)
    return data
  },
  async updatePassword({commit}, {params, lang}) {
    const {data} = await Service.userUpdatePassword(params, lang)
    return data
  },
  setProfile({commit}, params) {
    commit('SET_PROFILE', params?.data)
  },
  async userVouchers({commit}, {params, lang}) {
    const {data} = await Service.userVouchers(params, this.$auth.strategy.token.get(), lang)
    if (data?.status === 200) {
      commit('UPDATE_VOUCHERS', data.data)
    }
    return data
  },
  /*
   * Guest checkout confirms the email before the order can be paid for; the
   * address it proves is where the receipt and the new account's credentials
   * are sent.
   */
  async guestVerifyEmail({commit}, {params, lang}) {
    const {data} = await Service.guestVerifyEmail(params, lang)
    return data
  },
  async guestVerifyEmailCheck({commit}, {params, lang}) {
    const {data} = await Service.guestVerifyEmailCheck(params, lang)
    return data
  },
  async userAddressAll({commit}, params) {
    const {data} = await Service.userAddressAll(params, this.$auth.strategy.token.get())
    if (data?.status === 200) {
      commit('ALL_ADDRESS', data.data)
    }
    return data
  },
  async userAddressAction({commit}, params) {
    const {data} = await Service.userAddressAction(params, this.$auth.strategy.token.get())
    if (data?.status === 200) {
      commit('UPDATE_ADDRESS', data.data)
    }
    return data
  },
  async userAddressDelete({commit}, {id, params}) {
    const {data} = await Service.userAddressDelete(id, this.$auth.strategy.token.get(), params)
    return data
  },
  async updateUserPassword({commit}, params) {
    const {data} = await Service.updateUserPassword(params, this.$auth.strategy.token.get())
    return data
  },
  async updateProfile({commit}, params) {
    const {data} = await Service.updateProfile(params, this.$auth.strategy.token.get())
    return data
  },
  emptyVoucher({commit}) {
    commit('EMPTY_VOUCHER')
  },
}

export {
  state,
  getters,
  mutations,
  actions
}
