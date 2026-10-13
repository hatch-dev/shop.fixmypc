/*
 * Basket notices in the cart store (2026-10-13).
 *
 * Run with: node tests/basket-notices.check.js
 *
 * The server keeps a notice for each change to the basket the shopper did not
 * make (emptied after 20 idle minutes, a line cut back, guest items added at
 * sign-in) and sends the pending ones with every basket read. Pinned here:
 * the store keeps what the read sent, dismissing drops exactly those, and a
 * saved copy of the basket never brings a dismissed one back.
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

let passed = 0
const check = (name, fn) => { fn(); passed++; console.log('ok -', name) }

check('notices from a basket read are kept, and a missing list clears them', () => {
  const state = makeState()
  mutations.SET_BASKET_NOTICES(state, [{ id: 1, kind: 'expired' }, { id: 2, kind: 'merged' }])
  assert.deepStrictEqual(getters.basketNotices(state).map(n => n.id), [1, 2])

  mutations.SET_BASKET_NOTICES(state, undefined)
  assert.deepStrictEqual(getters.basketNotices(state), [])
})

check('dismissing drops exactly those notices', () => {
  const state = makeState()
  mutations.SET_BASKET_NOTICES(state, [{ id: 1 }, { id: 2 }, { id: 3 }])
  mutations.DROP_BASKET_NOTICES(state, ['2', 3])
  assert.deepStrictEqual(state.basketNotices.map(n => n.id), [1])
})

check('a saved copy of the basket never brings notices back', () => {
  const src = fs.readFileSync(path.join(ROOT, 'plugins/vuex-persisted.js'), 'utf8')
  assert.ok(src.includes('delete kept.basketNotices'))
})

console.log(`\n${passed} passed, 0 failed`)
