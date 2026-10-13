/*
 * Buy Now and blocked lines in the cart store (2026-10-09).
 *
 * Run with: node tests/buy-now-basket.check.js
 *
 * A Buy Now line (buy_now on the line) is a checkout of one item, not part of
 * the basket. Pinned here:
 *   - the basket views and the badge never see it;
 *   - while it exists the checkout pages see only it;
 *   - placing the order drops exactly the lines bought, and the badge is
 *     recomputed rather than reduced (a Buy Now order bought nothing the
 *     badge counted);
 *   - a ticked line the server says cannot be bought blocks the checkout.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')
const babel = require(path.join(NM, '@babel/core'))

function loadStore () {
  const src = fs.readFileSync(path.join(ROOT, 'store/cart.js'), 'utf8')

  const code = babel.transformSync(src, {
    filename: 'cart.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const stub = (request) => {
    if (request === 'vue') return { default: { set: (o, k, v) => { o[k] = v } }, __esModule: true }
    return { default: {}, __esModule: true }
  }

  const module = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return module.exports
}

const { getters, mutations, actions, state: makeState } = loadStore()
const PUBLIC = 1

function read (state) {
  const get = {}
  Object.keys(getters).forEach((name) => {
    Object.defineProperty(get, name, { get: () => getters[name](state, get) })
  })
  return get
}

const line = (id, quantity, extra = {}) => ({ id, product_id: 100 + id, quantity, selected: 1, buy_now: false, ...extra })

let passed = 0
const check = (name, fn) => { fn(); passed++; console.log('ok -', name) }

check('the basket and the badge ignore a Buy Now line', () => {
  const state = makeState()
  mutations.SET_CART_PRODUCTS(state, [line(1, 2), line(2, 1), line(3, 1, { buy_now: true })])

  assert.deepStrictEqual(read(state).cartProducts.map(l => l.id), [1, 2])
  assert.strictEqual(state.cartCount, 3)
})

check('while a Buy Now exists the checkout is only that line', () => {
  const state = makeState()
  mutations.SET_CART_PRODUCTS(state, [line(1, 2), line(3, 1, { buy_now: 1 })])
  const get = read(state)

  assert.deepStrictEqual(get.checkoutProducts.map(l => l.id), [3])
  assert.deepStrictEqual(get.selectedCartProducts.map(l => l.id), [3])
  assert.strictEqual(get.buyNowInProgress, true)
})

check('without one the checkout is the ticked basket', () => {
  const state = makeState()
  mutations.SET_CART_PRODUCTS(state, [line(1, 2), line(2, 1, { selected: 2 })])
  const get = read(state)

  assert.deepStrictEqual(get.checkoutProducts.map(l => l.id), [1, 2])
  assert.deepStrictEqual(get.selectedCartProducts.map(l => l.id), [1])
  assert.strictEqual(get.buyNowInProgress, false)
})

check('a Buy Now order leaves the basket and its count alone', () => {
  const state = makeState()
  mutations.SET_CART_PRODUCTS(state, [line(1, 2), line(3, 1, { buy_now: true })])
  const commits = []
  const commit = (type, payload) => { commits.push(type); mutations[type](state, payload) }

  actions.subtractCartProductCount({ commit, state }, { qty: 1, status: { PUBLIC } })

  assert.deepStrictEqual(state.cartProducts.map(l => l.id), [1])
  assert.strictEqual(state.cartCount, 2, 'not 2 - 1')
})

check('a basket order drops only the ticked lines', () => {
  const state = makeState()
  mutations.SET_CART_PRODUCTS(state, [line(1, 2), line(2, 1, { selected: 2 })])
  const commit = (type, payload) => mutations[type](state, payload)

  actions.subtractCartProductCount({ commit, state }, { qty: 2, status: { PUBLIC } })

  assert.deepStrictEqual(state.cartProducts.map(l => l.id), [2])
  assert.strictEqual(state.cartCount, 1)
})

check('a ticked line that cannot be bought blocks the checkout; an unticked one does not', () => {
  const state = makeState()
  mutations.SET_CART_PRODUCTS(state, [
    line(1, 2, { availability: 'low_stock' }),
    line(2, 3, { availability: 'short' }),
    line(3, 1, { availability: 'out_of_stock', selected: 2 }),
  ])

  assert.deepStrictEqual(read(state).blockedBasketLines.map(l => l.id), [2])
})

console.log(`\n${passed} passed, 0 failed`)
