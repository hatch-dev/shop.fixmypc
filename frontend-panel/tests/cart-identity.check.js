/*
 * Exercises the storefront half of the guest-cart hand-over without a browser:
 * the real cart and user store modules and the real cart-identity plugin, with
 * the API service and nuxt-auth stubbed.
 *
 * Run from frontend-panel/: npm run check:cart-identity
 */
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const Vue = require('vue')
const Vuex = require('vuex')

Vue.use(Vuex)

// ---- a localStorage good enough for the code under test --------------------

const storage = {}

global.localStorage = {
  getItem: k => (k in storage ? storage[k] : null),
  setItem: (k, v) => { storage[k] = String(v) },
  removeItem: k => { delete storage[k] },
  clear: () => { Object.keys(storage).forEach(k => delete storage[k]) },
}

// ---- the API, recorded rather than called ----------------------------------

const calls = []

let serverCart = []

const Service = {
  setGuestToken(token) { calls.push(['setGuestToken', token]) },
  guestToken(existing) {
    calls.push(['guestToken', existing])
    return Promise.resolve({data: {status: 200, data: {user_token: 'issued-token'}}})
  },
  cartMerge(token) {
    calls.push(['cartMerge', token])
    return Promise.resolve({data: {status: 200, data: {merged: 1, cart_count: 8}}})
  },
  cartByUser(bearer, lang, params) {
    calls.push(['cartByUser', params])
    return Promise.resolve({data: {status: 200, data: serverCart}})
  },
}

// ---- load an ES module out of the project into this context ----------------

function load(file, injected) {
  const src = fs.readFileSync(file, 'utf8')

  const stripped = src
    .replace(/^[ \t]*(?:\/\/.*)?import[\s\S]*?from\s+['"][^'"]+['"];?[ \t]*\n/gm, '')
    .replace(/^[ \t]*export\s+default\s+/m, 'module.exports.default = ')
    .replace(/^[ \t]*export\s*\{[\s\S]*?\}[ \t]*;?[ \t]*$/m, '')

  const module = {exports: {}}

  const context = vm.createContext(Object.assign({
    module, exports: module.exports, console, localStorage, Promise,
  }, injected))

  new vm.Script(stripped + '\n;module.exports.__names = {' +
    'state: typeof state !== "undefined" ? state : undefined,' +
    'getters: typeof getters !== "undefined" ? getters : undefined,' +
    'mutations: typeof mutations !== "undefined" ? mutations : undefined,' +
    'actions: typeof actions !== "undefined" ? actions : undefined};',
    {filename: file}).runInContext(context)

  return module.exports
}

const cartModule = load(path.join('store', 'cart.js'), {Service}).__names
const userModule = load(path.join('store', 'user.js'), {Service}).__names
const plugin = load(path.join('plugins', 'cart-identity.js'), {}).default

// ---- a nuxt-auth stand-in with the one behaviour the plugin uses -----------

function makeAuth() {
  const watchers = []

  return {
    loggedIn: false,
    strategy: {token: {get: () => 'Bearer stub'}},
    $storage: {
      watchState(key, fn) { watchers.push(fn) },
    },
    set(value) {
      this.loggedIn = value
      watchers.forEach(fn => fn(value))
    },
  }
}

// ---- assertions ------------------------------------------------------------

let failures = 0

function check(label, actual, expected) {
  const a = JSON.stringify(actual)
  const e = JSON.stringify(expected)

  if (a === e) {
    console.log('  ok   ' + label)
  } else {
    failures++
    console.log('  FAIL ' + label + '\n         expected ' + e + '\n         got      ' + a)
  }
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0))

// ---- the cases -------------------------------------------------------------

async function run() {

  // A guest basket, persisted to localStorage, exactly as it is on screen when
  // the shopper reaches the sign-in form.
  const guestLines = [
    {id: 11, product_id: 1, quantity: 5, selected: 1},
    {id: 12, product_id: 2, quantity: 1, selected: 1},
  ]

  const store = new Vuex.Store({
    modules: {
      cart: {namespaced: true, ...cartModule},
      user: {namespaced: true, ...userModule},
      language: {namespaced: true, state: {langCode: false}, getters: {langCode: s => s.langCode}},
    },
  })

  const auth = makeAuth()
  store.$auth = auth

  localStorage.setItem('user_token', 'guest-token-abc')

  // The guest basket is in the store, rehydrated by vuex-persistedstate.
  store.commit('cart/SET_CART_PRODUCTS', guestLines)
  store.commit('cart/SET_SELECTED_ADDRESS', {id: 99})

  plugin({app: {$auth: auth}, store})

  console.log('\nBefore signing in')
  check('basket holds the guest lines', store.state.cart.cartProducts.length, 2)
  check('count is the guest count', store.state.cart.cartCount, 6)

  // --- sign in
  serverCart = [
    {id: 21, product_id: 1, quantity: 7, selected: 1},
    {id: 22, product_id: 2, quantity: 1, selected: 1},
  ]

  auth.set(true)
  await settle()
  await settle()

  console.log('\nAfter signing in')
  check('the merge was asked for with the guest token',
    calls.filter(c => c[0] === 'cartMerge').map(c => c[1]), ['guest-token-abc'])
  check('the basket is the one the server returned',
    store.state.cart.cartProducts.map(l => l.id), [21, 22])
  check('the count is the merged count', store.state.cart.cartCount, 8)
  check('the guest address did not follow', store.state.cart.selectedAddress, null)
  check('the guest token was retired', localStorage.getItem('user_token'), null)

  // --- a reload while signed in must not re-merge
  const mergesBefore = calls.filter(c => c[0] === 'cartMerge').length

  auth.set(true)
  await settle()

  check('setting the flag again re-merges nothing',
    calls.filter(c => c[0] === 'cartMerge').length, mergesBefore)

  // --- sign out
  auth.set(false)
  await settle()

  console.log('\nAfter signing out')
  check('the basket is empty', store.state.cart.cartProducts.length, 0)
  check('the count is zero', store.state.cart.cartCount, 0)
  check('the payment method went with it', store.state.cart.paymentMethod, null)
  check('no guest token was left behind', localStorage.getItem('user_token'), null)

  // --- a signed-in shopper is never given a guest token
  console.log('\nGuest identity while signed in')

  auth.loggedIn = true
  const issuedBefore = calls.filter(c => c[0] === 'guestToken').length

  const token = await store.dispatch('user/getUserToken')

  check('getUserToken returns nothing for a signed-in customer', token, null)
  check('and mints nothing', calls.filter(c => c[0] === 'guestToken').length, issuedBefore)

  // --- and a signed-out one still gets one
  auth.loggedIn = false
  store.state.user.userToken = null

  const guestToken = await store.dispatch('user/getUserToken')

  check('a guest is still issued a token', guestToken, 'issued-token')

  console.log('\n' + (failures ? failures + ' FAILED' : 'all checks passed'))
  process.exit(failures ? 1 : 0)
}

run().catch(e => { console.error(e); process.exit(1) })
