/*
 * What the basket is allowed to remember across a page load.
 *
 * Run with: npm run check:cart-persistence
 *
 * The cart module is written to localStorage so a basket survives a reload.
 * The trap is that a price is not a property of the basket — it is an answer
 * to "what does this line cost THIS customer, right now" — and it goes stale
 * for reasons the browser cannot see: a trade application approved, an account
 * suspended, a flash sale started or ended, a band or a tax rule edited, or
 * the payload itself growing a field the snapshot predates.
 *
 * That last one is what reached a customer: rows saved by an older build,
 * restored into a newer one, carrying a price but no VAT split. The payment
 * page rendered them as a correct Products Total beside "VAT €0.00".
 *
 * So the reducer keeps the basket's identity and strips its money, and the
 * plugin asks the server for the real figures the moment it has rehydrated.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))

/*
 * The plugin, with vuex-persistedstate stubbed so the reducer can be called
 * directly. The stub captures the options it was built with.
 */
function loadPlugin () {
  const src = fs.readFileSync(path.join(ROOT, 'plugins/vuex-persisted.js'), 'utf8')

  const code = babel.transformSync(src, {
    filename: 'vuex-persisted.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const captured = {}

  const stub = (request) => {
    if (request === 'vuex-persistedstate') {
      return {
        default: (options) => {
          captured.options = options
          return () => {}
        },
        __esModule: true,
      }
    }
    return {}
  }

  const module = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return { plugin: module.exports.default || module.exports, captured }
}

const MONEY = [
  'price', 'unit_price', 'upsell_price', 'original_price', 'line_total',
  'vat_rate', 'vat_inclusive',
  'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
  'line_ex_vat', 'line_vat', 'line_inc_vat',
  'line_list', 'line_list_ex_vat', 'line_list_inc_vat',
]

/* A line exactly as /cart/by-user answers with it. */
const LINE = {
  id: 10268,
  product_id: 99492258,
  inventory_id: 147,
  quantity: 3,
  selected: 1,
  shipping_type: 1,
  shipping_place_id: 4,
  flash_product: { id: 99492258, title: 'Apple iPhone 15 Pro' },
  updated_inventory: { id: 147 },
  price: 674, unit_price: 674, upsell_price: 674, original_price: 699,
  line_total: 2022,
  vat_rate: 23, vat_inclusive: false,
  unit_ex_vat: 674, unit_vat: 155.02, unit_inc_vat: 829.02,
  line_ex_vat: 2022, line_vat: 465.06, line_inc_vat: 2487.06,
  line_list: 2097, line_list_ex_vat: 2097, line_list_inc_vat: 2579.31,
}

let failures = 0

function check (name, fn) {
  try {
    fn()
    console.log(`  ok   ${name}`)
  } catch (err) {
    failures++
    console.log(`  FAIL ${name}`)
    console.log(`       ${err.message}`)
  }
}

function saved (rows) {
  const { plugin, captured } = loadPlugin()

  // Enough of a store for the plugin to run against.
  plugin({
    store: {
      state: { cart: { cartProducts: rows, cartCount: 0 } },
      getters: { 'language/langCode': null },
      dispatch: () => Promise.resolve(),
    },
    app: { $auth: { loggedIn: true } },
  })

  return captured.options.reducer({ cart: { cartProducts: rows, cartCount: 0 } })
}

console.log('\nwhat gets written to localStorage')

check('not one server-decided figure is saved', () => {
  const row = saved([LINE]).cart.cartProducts[0]

  for (const field of MONEY) {
    assert.ok(!(field in row), `${field} was persisted and can go stale`)
  }
})

check('the basket itself still survives a reload', () => {
  const row = saved([LINE]).cart.cartProducts[0]

  // Identity: which line, which product, which variant, how many, ticked.
  assert.strictEqual(row.id, 10268)
  assert.strictEqual(row.product_id, 99492258)
  assert.strictEqual(row.inventory_id, 147)
  assert.strictEqual(row.quantity, 3)
  assert.strictEqual(row.selected, 1)
  assert.strictEqual(row.flash_product.title, 'Apple iPhone 15 Pro')
})

check('an empty basket does not throw', () => {
  assert.deepStrictEqual(saved([]).cart.cartProducts, [])
})

console.log('\nwhat happens once it is restored')

check('a restored basket asks the server for its prices', () => {
  const { plugin, captured } = loadPlugin()
  const dispatched = []

  plugin({
    store: {
      state: { cart: { cartProducts: [LINE] } },
      getters: { 'language/langCode': 'fr' },
      dispatch: (type, payload) => { dispatched.push({ type, payload }); return Promise.resolve() },
    },
    app: { $auth: { loggedIn: true } },
  })

  assert.strictEqual(dispatched.length, 1, 'exactly one refresh')
  assert.strictEqual(dispatched[0].type, 'cart/getCartByUser')
  assert.strictEqual(dispatched[0].payload.lang, 'fr', 'in the shopper language')
  assert.ok(captured.options, 'and persistence is still installed')
})

check('an empty basket is not worth a request', () => {
  const { plugin } = loadPlugin()
  const dispatched = []

  plugin({
    store: {
      state: { cart: { cartProducts: [] } },
      getters: { 'language/langCode': null },
      dispatch: (type) => { dispatched.push(type); return Promise.resolve() },
    },
    app: { $auth: { loggedIn: true } },
  })

  assert.strictEqual(dispatched.length, 0)
})

check('a failed refresh does not take the page down with it', () => {
  const { plugin } = loadPlugin()

  // The summary already refuses to state a total for a priceless basket; an
  // unhandled rejection here would replace the page with the error screen.
  assert.doesNotThrow(() => {
    plugin({
      store: {
        state: { cart: { cartProducts: [LINE] } },
        getters: { 'language/langCode': null },
        dispatch: () => Promise.reject(new Error('offline')),
      },
      app: { $auth: { loggedIn: false } },
    })
  })
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all cart persistence checks passed\n')
