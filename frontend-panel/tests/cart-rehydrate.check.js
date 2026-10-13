/*
 * The reported failure, reproduced: an old snapshot in localStorage, the real
 * cart store, the real persistence plugin, a stubbed API.
 *
 * Run with: npm run check:cart-rehydrate
 *
 * The symptom was a payment page stuck on "Updating prices…" — rows restored
 * from a previous build, carrying a price but no VAT split, and a refresh that
 * never replaced them. Reading the code was not enough to say why, so this
 * drives the whole path instead and asserts the one thing that matters: after
 * rehydration settles, every line holds the server's figures.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const Vue = require(path.join(NM, 'vue'))
const Vuex = require(path.join(NM, 'vuex'))
const babel = require(path.join(NM, '@babel/core'))

Vue.use(Vuex)
Vue.config.productionTip = false
Vue.config.devtools = false

/* ------------------------------------------------------------- fixtures */

/* A line as an OLDER build saved it: priced, but with no VAT split at all. */
const STALE_SNAPSHOT = {
  id: 10268,
  product_id: 99492258,
  inventory_id: 147,
  quantity: 1,
  selected: 1,
  shipping_type: 1,
  price: 699,
  upsell_price: 699,
  flash_product: { id: 99492258, title: 'Apple iPhone 15 Pro' },
  updated_inventory: { id: 147 },
}

/* The same line as /cart/by-user answers with it today. */
const FRESH_ROW = {
  ...STALE_SNAPSHOT,
  unit_price: 699,
  original_price: 699,
  line_total: 699,
  vat_rate: 23,
  vat_inclusive: false,
  unit_ex_vat: 699, unit_vat: 160.77, unit_inc_vat: 859.77,
  line_ex_vat: 699, line_vat: 160.77, line_inc_vat: 859.77,
  line_list: 699, line_list_ex_vat: 699, line_list_inc_vat: 859.77,
}

/* ------------------------------------------------------------ the world */

function transform (file, filename) {
  return babel.transformSync(fs.readFileSync(file, 'utf8'), {
    filename,
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code
}

/* A localStorage good enough for both the plugin and the store. */
function fakeStorage (seed) {
  const data = { ...seed }

  return {
    getItem: (k) => (k in data ? data[k] : null),
    setItem: (k, v) => { data[k] = String(v) },
    removeItem: (k) => { delete data[k] },
    _dump: () => data,
  }
}

function loadCartModule (service) {
  const code = transform(path.join(ROOT, 'store/cart.js'), 'cart.js')

  const fakeRequire = (request) => {
    if (request === 'vue') return { default: Vue, __esModule: true }
    if (request.indexOf('service') !== -1) return { default: service, __esModule: true }
    return {}
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return module.exports
}

function loadPersistPlugin () {
  const code = transform(path.join(ROOT, 'plugins/vuex-persisted.js'), 'vuex-persisted.js')

  const fakeRequire = (request) => {
    if (request === 'vuex-persistedstate') {
      return { default: require(path.join(NM, 'vuex-persistedstate')).default
        || require(path.join(NM, 'vuex-persistedstate')), __esModule: true }
    }
    return {}
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return module.exports.default || module.exports
}

/*
 * Boot the app the way the browser does: a snapshot already in localStorage,
 * the cart module registered, then the persistence plugin.
 */
function boot ({
  snapshot, apiRows, loggedIn = true, apiFails = false, hold = null,
  deferReady = false, noAuth = false,
}) {
  const calls = []
  let failing = apiFails

  const service = {
    cartByUser: (...args) => {
      calls.push(args)

      if (failing) return Promise.reject(new Error('offline'))

      const answer = { data: { status: 200, data: apiRows } }

      // Lets a check inspect the in-flight state before the server replies.
      return hold ? hold.then(() => answer) : Promise.resolve(answer)
    },
    // Stops the stub refusing, so a retry can be shown to recover.
    recover: () => { failing = false },
  }

  const cart = loadCartModule(service)

  const store = new Vuex.Store({
    modules: {
      cart: {
        namespaced: true,
        state: cart.state,
        getters: cart.getters,
        mutations: cart.mutations,
        actions: cart.actions,
      },
      language: { namespaced: true, getters: { langCode: () => null } },
      user: { namespaced: true, actions: { getUserToken: () => null } },
      common: { namespaced: true, mutations: { SET_TOAST_MESSAGE () {}, SET_TOAST_ERROR () {} } },
    },
  })

  /*
   * `noAuth` is the state a plugin actually sees at boot: the auth module has
   * not installed itself yet, so the store has no $auth at all.
   */
  if (!noAuth) {
    store.$auth = { loggedIn, strategy: { token: { get: () => 'token' } } }
  }

  const storage = fakeStorage(
    snapshot ? { shop: JSON.stringify({ cart: { cartProducts: snapshot, cartCount: 1 } }) } : {}
  )

  global.window = global.window || {}
  global.localStorage = storage
  global.window.localStorage = storage

  /*
   * Nuxt's own hook. `deferReady` holds it so a check can look at the world
   * between the plugin running and the app being ready — which is the window
   * the bug lived in.
   */
  let fire = null
  global.window.onNuxtReady = (cb) => {
    if (deferReady) fire = cb
    else cb()
  }

  loadPersistPlugin()({ store, app: { $auth: store.$auth } })

  const ready = () => {
    // Auth arrives with the mounted app, exactly as it does in the browser.
    store.$auth = { loggedIn, strategy: { token: { get: () => 'token' } } }
    if (fire) fire()
  }

  return { store, calls, storage, service, ready }
}

/* --------------------------------------------------------------- checks */

let failures = 0

function check (name, fn) {
  return Promise.resolve()
    .then(fn)
    .then(() => console.log(`  ok   ${name}`))
    .catch((err) => {
      failures++
      console.log(`  FAIL ${name}`)
      console.log(`       ${err.message}`)
    })
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

async function main () {
  console.log('\nbooting with a snapshot from an older build')

  await check('the stale rows are restored, then replaced by priced ones', async () => {
    const { store, calls } = boot({ snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW] })

    assert.strictEqual(calls.length, 1, 'the basket is refreshed exactly once')

    await settle()

    const row = store.state.cart.cartProducts[0]

    assert.ok(row, 'the line survived')
    assert.strictEqual(Number(row.vat_rate), 23, 'and now carries its VAT rate')
    assert.strictEqual(Number(row.line_vat), 160.77, 'and the VAT to add')
    assert.strictEqual(Number(row.line_list), 699, 'and its original price')
  })

  await check('the snapshot written back holds no money at all', async () => {
    const { storage } = boot({ snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW] })

    await settle()

    const written = JSON.parse(storage._dump().shop)
    const row = written.cart.cartProducts[0]

    for (const field of ['price', 'unit_price', 'line_vat', 'vat_rate', 'line_list']) {
      assert.ok(!(field in row), `${field} was written back and can go stale again`)
    }

    assert.strictEqual(row.quantity, 1, 'but the basket itself is still there')
  })

  await check('a basket with nothing in it asks for nothing', async () => {
    const { calls } = boot({ snapshot: [], apiRows: [] })

    assert.strictEqual(calls.length, 0)
  })

  await check('a guest basket is refreshed too', async () => {
    const { calls } = boot({ snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW], loggedIn: false })

    // A guest resolves its token first, so the request is one tick later than
    // a signed-in shopper's. Asserting synchronously here reported "never
    // refreshed" for a path that refreshes perfectly well.
    await settle()

    assert.strictEqual(calls.length, 1)
  })

  await check('an API that refuses leaves the page standing', async () => {
    const { store } = boot({ snapshot: [STALE_SNAPSHOT], apiRows: [], apiFails: true })

    await settle()

    // The line is still listed; it simply has no prices on it, which the
    // summary renders as dashes rather than as zeroes.
    assert.strictEqual(store.state.cart.cartProducts.length, 1)
  })

  console.log('\nbefore auth has finished installing')

  await check('nothing is requested until the app is ready', async () => {
    /*
     * The reported error. Plugins run before the auth module installs itself,
     * so store.$auth is undefined; getCartByUser reads .strategy off it and
     * threw "Cannot read properties of undefined (reading 'strategy')", which
     * the summary then printed where the VAT should have been.
     *
     * Deferring to onNuxtReady is not cosmetic: a request sent without a token
     * would fetch the GUEST basket and overwrite a signed-in shopper's.
     */
    const { store, calls, ready } = boot({
      snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW], deferReady: true,
    })

    await settle()

    assert.strictEqual(calls.length, 0, 'not asked for while auth is missing')

    // Auth installs, then Nuxt says it is ready.
    ready()
    await settle()
    await settle()

    assert.strictEqual(calls.length, 1, 'asked for once the app is ready')
    assert.strictEqual(Number(store.state.cart.cartProducts[0].vat_rate), 23)
  })

  await check('a dispatch with no auth refuses instead of crashing', async () => {
    const { store, calls } = boot({
      snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW], noAuth: true,
    })

    await settle()

    assert.strictEqual(calls.length, 0, 'it must not fetch a guest basket')
    assert.strictEqual(store.state.cart.pricesPending, false, 'the spinner stops')
    assert.ok(store.state.cart.pricesError, 'and it is reported')
  })

  await check('a raw exception never becomes customer-facing copy', async () => {
    const { store } = boot({
      snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW], noAuth: true,
    })

    await settle()

    const shown = store.state.cart.pricesError

    assert.ok(!/undefined|strategy|TypeError|Cannot read/i.test(shown),
      `a JavaScript error reached the customer: "${shown}"`)
    assert.ok(/basket|price/i.test(shown), 'and it still says what went wrong')
  })

  console.log('\nwhen the refresh fails')

  await check('the failure is recorded, not swallowed', async () => {
    const { store } = boot({ snapshot: [STALE_SNAPSHOT], apiRows: [], apiFails: true })

    await settle()

    /*
     * This is what a stuck "Updating prices…" was: the catch was empty, so
     * nothing ever turned the spinner off or said why.
     */
    assert.strictEqual(store.state.cart.pricesPending, false, 'the spinner stops')
    assert.ok(store.state.cart.pricesError, 'and the reason is kept')
  })

  await check('the pending flag is on while the request is in flight', async () => {
    let release
    const held = new Promise((resolve) => { release = resolve })

    const { store } = boot({
      snapshot: [STALE_SNAPSHOT],
      apiRows: [FRESH_ROW],
      hold: held,
    })

    assert.strictEqual(store.state.cart.pricesPending, true, 'while waiting')

    release()
    await settle()
    await settle()

    assert.strictEqual(store.state.cart.pricesPending, false, 'and off once answered')
  })

  await check('a retry after a failure recovers the prices', async () => {
    // Fail first, exactly as the customer saw, then let the retry through.
    const { store, service } = boot({
      snapshot: [STALE_SNAPSHOT], apiRows: [FRESH_ROW], apiFails: true,
    })

    await settle()

    assert.ok(store.state.cart.pricesError, 'it failed')
    assert.strictEqual(store.state.cart.cartProducts[0].vat_rate, undefined, 'and has no VAT')

    service.recover()

    await store.dispatch('cart/getCartByUser', { lang: null }).catch(() => {})
    await settle()

    const row = store.state.cart.cartProducts[0]

    assert.strictEqual(store.state.cart.pricesError, null, 'the error clears')
    assert.strictEqual(store.state.cart.pricesPending, false, 'and so does the spinner')
    assert.strictEqual(Number(row.vat_rate), 23, 'and the VAT arrives')
    assert.strictEqual(Number(row.line_vat), 160.77)
  })

  console.log('')

  if (failures) {
    console.log(`${failures} failing\n`)
    process.exit(1)
  }

  console.log('all cart rehydrate checks passed\n')
}

main()
