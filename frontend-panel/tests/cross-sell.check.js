/*
 * Cross-sell on the storefront, without a browser or a build.
 *
 * Run with: npm run check:cross-sell
 *
 * Exercises the real store module against the shapes the cross-sell API
 * actually returns, with the API stubbed.
 *
 * The cases are the ones that matter to a customer and cannot be seen from a
 * compile check: that a failure to load recommendations never reaches the
 * basket (section 27), that the additional total is summed from the SERVER's
 * price for the exact variant chosen rather than from a product-level figure,
 * that a variable product cannot be taken without a choice, and that nothing
 * about a price is ever sent to the server.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

/*
 * A DOM, BEFORE Vue is loaded.
 *
 * Vue decides once, at import time, whether it is running in a browser. Load
 * it first and $mount becomes a no-op that reports no error at all -- every
 * component test then passes against an instance that rendered nothing.
 */
const { JSDOM } = require(path.join(NM, 'jsdom'))

const dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>', {
  url: 'http://localhost/',
})

global.window = dom.window
global.document = dom.window.document
global.navigator = dom.window.navigator

/*
 * The full build, not the runtime-only one: the panel is mounted from its
 * real template, and the runtime build cannot compile one.
 */
const Vue = require(path.join(NM, 'vue/dist/vue.common.dev.js'))
const compiler = require(path.join(NM, 'vue-template-compiler'))
const Vuex = require(path.join(NM, 'vuex'))
const babel = require(path.join(NM, '@babel/core'))

Vue.use(Vuex)
Vue.config.productionTip = false
Vue.config.devtools = false

/* ------------------------------------------------------- the store module */

function loadModule (relative, service) {
  const src = fs.readFileSync(path.join(ROOT, relative), 'utf8')

  const code = babel.transformSync(src, {
    filename: path.basename(relative),
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

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

const toasts = []
const cartReads = []

function buildStore (service) {
  const loaded = loadModule('store/crossSell.js', service)

  const store = new Vuex.Store({
    modules: {
      crossSell: {
        namespaced: true,
        state: loaded.state,
        getters: loaded.getters,
        mutations: loaded.mutations,
        actions: loaded.actions,
      },
      common: {
        namespaced: true,
        state: () => ({}),
        mutations: {
          SET_TOAST_MESSAGE (s, m) { toasts.push({ type: 'ok', m }) },
          SET_TOAST_ERROR (s, m) { toasts.push({ type: 'error', m }) },
        },
      },
      user: {
        namespaced: true,
        actions: { getUserToken: () => 'guest-token' },
      },
      cart: {
        namespaced: true,
        actions: { getCartByUser (ctx, payload) { cartReads.push(payload) } },
      },
    },
  })

  // What Nuxt injects onto the store.
  store.$auth = { strategy: { token: { get: () => null } } }
  store.$i18n = { t: key => key }

  return store
}

/* A recommendation as the server sends one. */
function card (id, overrides = {}) {
  return Object.assign({
    id,
    name: 'Accessory ' + id,
    slug: 'accessory-' + id,
    image: 'a.png',
    sku: 'SKU-' + id,
    inventory_id: 500 + id,
    cross_sell_id: 9,
    price: 25,
    old_price: 30,
    requires_option: false,
    options: [],
    in_stock: true,
    backorder: false,
    from: false,
  }, overrides)
}

/* A variable one: two buyable options at different prices, no default. */
function variableCard (id) {
  return card(id, {
    inventory_id: null,
    requires_option: true,
    from: true,
    price: 49,
    old_price: 59,
    options: [
      { inventory_id: 901, label: 'Black', sku: 'BLK', price: 49, old_price: 59, quantity: 4, in_stock: true, backorder: false },
      { inventory_id: 902, label: 'Blue', sku: 'BLU', price: 55, old_price: 65, quantity: 2, in_stock: true, backorder: false },
    ],
  })
}

/* ------------------------------------------------------------ the runner */

let passed = 0
let failed = 0

async function test (name, fn) {
  try {
    await fn()
    console.log('  ok   ' + name)
    passed++
  } catch (e) {
    console.log('  FAIL ' + name + '\n         ' + e.message)
    failed++
  }
}

async function run () {
  console.log('\nloading recommendations')

  await test('the server list and limit are taken as given', async () => {
    const service = {
      crossSellsForProduct: async () => ({
        data: { status: 200, data: { items: [card(1), card(2)], limit: 4 } },
      }),
    }

    const store = buildStore(service)

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })

    assert.deepStrictEqual(
      store.getters['crossSell/crossSellItems'].map(i => i.id), [1, 2]
    )
    assert.strictEqual(store.getters['crossSell/crossSellLimit'], 4)
  })

  /*
   * Section 27, and the reason the action never rejects. The primary product
   * is already in the basket by the time this runs; a shopper whose add has
   * just worked must not be shown a failure because the block underneath it
   * could not be built.
   */
  await test('a failed load never throws at the caller', async () => {
    const service = {
      crossSellsForProduct: async () => { throw new Error('gateway timeout') },
    }

    const store = buildStore(service)

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })

    assert.strictEqual(store.getters['crossSell/crossSellItems'].length, 0)
    assert.ok(store.getters['crossSell/crossSellError'], 'the failure was not recorded')
    assert.strictEqual(store.getters['crossSell/crossSellLoading'], false)
  })

  await test('a refusal is recorded rather than thrown', async () => {
    const service = {
      crossSellsForProduct: async () => ({
        data: { status: 201, message: 'nope' },
      }),
    }

    const store = buildStore(service)

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })

    assert.strictEqual(store.getters['crossSell/crossSellError'], 'nope')
  })

  await test('a new load clears what the last one left behind', async () => {
    const service = {
      crossSellsForProduct: async () => ({
        data: { status: 200, data: { items: [card(1)], limit: 4 } },
      }),
    }

    const store = buildStore(service)

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })
    store.dispatch('crossSell/toggle', { item: card(1) })

    assert.strictEqual(store.getters['crossSell/crossSellSelectedCount'], 1)

    await store.dispatch('crossSell/loadForProduct', { productId: 11 })

    assert.strictEqual(
      store.getters['crossSell/crossSellSelectedCount'], 0,
      'a tick from the previous product survived into the next one'
    )
  })

  console.log('\nselection and the total')

  await test('ticking and unticking moves the count', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [card(1), card(2)], limit: 4 })

    store.dispatch('crossSell/toggle', { item: card(1) })
    assert.strictEqual(store.getters['crossSell/crossSellSelectedCount'], 1)

    store.dispatch('crossSell/toggle', { item: card(1) })
    assert.strictEqual(store.getters['crossSell/crossSellSelectedCount'], 0)
  })

  /*
   * Section 10. The total is the SERVER's price for each ticked line, times
   * the quantity -- never arithmetic on a product-level figure.
   */
  await test('the additional total sums the ticked lines', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', {
      items: [card(1, { price: 49 }), card(2, { price: 25 }), card(3, { price: 15 })],
      limit: 4,
    })

    store.dispatch('crossSell/toggle', { item: card(1, { price: 49 }) })
    store.dispatch('crossSell/toggle', { item: card(2, { price: 25 }) })

    assert.strictEqual(store.getters['crossSell/crossSellAdditionalTotal'], 74)
  })

  await test('quantity multiplies the line, not the card', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [card(1, { price: 25 })], limit: 4 })

    store.dispatch('crossSell/toggle', { item: card(1, { price: 25 }) })
    store.dispatch('crossSell/setQuantity', { productId: 1, quantity: 3 })

    assert.strictEqual(store.getters['crossSell/crossSellAdditionalTotal'], 75)
  })

  /*
   * Section 14. The chosen option's own price is what counts: the card's
   * headline is the cheapest buyable one, and charging Blue while quoting
   * Black is exactly the defect this guards.
   */
  await test('choosing a dearer option moves the total to that option', async () => {
    const store = buildStore({})

    const item = variableCard(1)

    store.commit('crossSell/SET_ITEMS', { items: [item], limit: 4 })

    store.dispatch('crossSell/setOption', { productId: 1, inventoryId: 902 })

    assert.strictEqual(
      store.getters['crossSell/crossSellAdditionalTotal'], 55,
      'the total used the headline price rather than the chosen option'
    )
  })

  await test('changing the option edits the tick rather than adding a second', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [variableCard(1)], limit: 4 })

    store.dispatch('crossSell/setOption', { productId: 1, inventoryId: 901 })
    store.dispatch('crossSell/setOption', { productId: 1, inventoryId: 902 })

    assert.strictEqual(store.getters['crossSell/crossSellSelectedCount'], 1)
    assert.strictEqual(store.getters['crossSell/crossSellSelected'][1].inventory_id, 902)
  })

  console.log('\nadding what was ticked')

  /*
   * Section 34. The page sends the product and the chosen variant and NOTHING
   * about money. The server prices every line from the catalogue and would
   * ignore a figure sent from here, but a payload that carries one invites
   * somebody to start trusting it.
   */
  await test('no price is ever sent to the server', async () => {
    let sent = null

    const service = {
      addCrossSell: async (params) => {
        sent = params

        return { data: { status: 200, data: { added: [{ id: 1 }], skipped: [] } } }
      },
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [card(1, { price: 25 })], limit: 4 })
    store.dispatch('crossSell/toggle', { item: card(1, { price: 25 }) })

    await store.dispatch('crossSell/addSelected', { primaryProductId: 10 })

    assert.ok(sent, 'nothing was sent')

    const payload = JSON.stringify(sent)

    assert.ok(!payload.includes('price'), 'a price reached the payload: ' + payload)
    assert.strictEqual(sent.products[0].product_id, 1)
    assert.strictEqual(sent.products[0].inventory_id, 501)
    assert.strictEqual(sent.primary_product_id, 10)
  })

  await test('the chosen variant is what is sent', async () => {
    let sent = null

    const service = {
      addCrossSell: async (params) => {
        sent = params

        return { data: { status: 200, data: { added: [{ id: 1 }], skipped: [] } } }
      },
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [variableCard(1)], limit: 4 })
    store.dispatch('crossSell/setOption', { productId: 1, inventoryId: 902 })

    await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(sent.products[0].inventory_id, 902)
  })

  /*
   * The server has just decided quantities, prices and VAT splits for several
   * lines at once, and the basket summary reads relations the add response
   * does not carry. Re-read, never patched.
   */
  await test('a successful add re-reads the basket from the server', async () => {
    cartReads.length = 0

    const service = {
      addCrossSell: async () => ({
        data: { status: 200, data: { added: [{ id: 1 }], skipped: [] } },
      }),
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [card(1)], limit: 4 })
    store.dispatch('crossSell/toggle', { item: card(1) })

    await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(cartReads.length, 1, 'the basket was not re-read')
  })

  await test('partial success is reported as such', async () => {
    const service = {
      addCrossSell: async () => ({
        data: {
          status: 200,
          data: {
            added: [{ id: 1 }],
            skipped: [{ product_id: 2, message: 'Accessory 2 has just gone out of stock.' }],
          },
        },
      }),
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [card(1), card(2)], limit: 4 })
    store.dispatch('crossSell/toggle', { item: card(1) })
    store.dispatch('crossSell/toggle', { item: card(2) })

    const result = await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(result.added.length, 1)
    assert.strictEqual(result.skipped.length, 1)
    assert.ok(result.skipped[0].message.includes('Accessory 2'))
  })

  /*
   * A refusal leaves the basket untouched and intact, which is not an
   * application error -- so it is a message, not a rejection.
   */
  await test('a refused add is a message rather than a throw', async () => {
    toasts.length = 0
    cartReads.length = 0

    const service = {
      addCrossSell: async () => ({
        data: { status: 201, message: 'That product is no longer available.', data: { skipped: [] } },
      }),
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [card(1)], limit: 4 })
    store.dispatch('crossSell/toggle', { item: card(1) })

    const result = await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(result.refused, true)
    assert.strictEqual(cartReads.length, 0, 'the basket was re-read after nothing changed')
    assert.ok(toasts.some(t => t.type === 'error'), 'the refusal was never shown')
  })

  await test('a thrown request does not take the caller down with it', async () => {
    const service = {
      addCrossSell: async () => { throw new Error('network') },
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [card(1)], limit: 4 })
    store.dispatch('crossSell/toggle', { item: card(1) })

    const result = await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(result.refused, true)
    assert.strictEqual(store.getters['crossSell/crossSellAdding'], false)
  })

  await test('adding nothing asks the server nothing', async () => {
    let called = false

    const service = { addCrossSell: async () => { called = true } }

    const store = buildStore(service)

    const result = await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(called, false)
    assert.strictEqual(result.refused, true)
  })


  console.log('\nthe panel')

  /*
   * The component's own rules, mounted for real.
   *
   * Everything below is something a customer can see and a store test cannot:
   * which tick is reachable, which price is printed beside it, and what the
   * panel does when there is nothing to show.
   */
  function loadSfc (file) {
    const parsed = compiler.parseComponent(fs.readFileSync(path.join(ROOT, file), 'utf8'))

    const code = babel.transformSync(parsed.script.content, {
      filename: path.basename(file),
      configFile: false,
      babelrc: false,
      presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
    }).code

    const module = { exports: {} }

    const stub = (request) => {
      if (request === 'vuex') {
        const spread = () => ({})

        return { mapGetters: spread, mapActions: spread, mapState: spread }
      }

      /*
       * util supplies getImageURL and priceFormat. Stubbed, so the assertions
       * below are about this component rather than about currency formatting,
       * which has its own checks.
       */
      return {
        default: {
          methods: {
            getImageURL: image => '/img/' + (image || 'default'),
            priceFormat: (pos, icon, price) => icon + Number(price).toFixed(2),
          },
        },
        __esModule: true,
      }
    }

    // eslint-disable-next-line no-new-func
    new Function('module', 'exports', 'require', code)(module, module.exports, stub)

    const options = module.exports.default || module.exports

    options.template = parsed.template.content

    return options
  }

  const panelOptions = loadSfc('components/CrossSellModal.vue')

  /* Mount it against a real store, so the getters under test are the real ones. */
  function mountPanel (store, propsData = {}) {
    const options = Object.assign({}, panelOptions, {
      computed: Object.assign({}, panelOptions.computed, {
        crossSellItems () { return store.getters['crossSell/crossSellItems'] },
        crossSellSelected () { return store.getters['crossSell/crossSellSelected'] },
        crossSellLoading () { return store.getters['crossSell/crossSellLoading'] },
        crossSellAdding () { return store.getters['crossSell/crossSellAdding'] },
        crossSellError () { return store.getters['crossSell/crossSellError'] },
        crossSellSelectedCount () { return store.getters['crossSell/crossSellSelectedCount'] },
        crossSellAdditionalTotal () { return store.getters['crossSell/crossSellAdditionalTotal'] },
        currencyIcon: () => '€',
        currencyPosition: () => 1,
        setting: () => ({ decimal_format: 2 }),
        langCode: () => 'en',
      }),
      methods: Object.assign({}, panelOptions.methods, {
        crossSellToggle (payload) { return store.dispatch('crossSell/toggle', payload) },
        crossSellSetOption (payload) { return store.dispatch('crossSell/setOption', payload) },
        crossSellSetQuantity (payload) { return store.dispatch('crossSell/setQuantity', payload) },
        crossSellAddSelected (payload) { return store.dispatch('crossSell/addSelected', payload) },
        crossSellDismiss () { return store.dispatch('crossSell/dismiss') },
      }),
    })

    /*
     * Nuxt auto-imports the skeletons, so the panel never registers one. The
     * stub keeps its class name, which is what the loading-state check reads.
     */
    options.components = Object.assign({}, options.components, {
      SkeletonBase: {
        name: 'SkeletonBase',
        props: ['width', 'height', 'radius', 'circle', 'mb', 'tag', 'animated'],
        template: '<span class="sk-base" />',
      },
    })

    const Ctor = Vue.extend(options)

    Ctor.prototype.$t = (key, params) => (params ? key + ':' + JSON.stringify(params) : key)

    /*
     * Mounted inside a host rather than on its own.
     *
     * The panel's root is a <transition> wrapping a dynamic <component :is>,
     * and Vue renders nothing at all for that shape when the component is
     * mounted standalone -- no error, just an empty instance, which is the
     * kind of green-but-testing-nothing this suite exists to avoid. A parent
     * renders it the way a page does.
     */
    const props = Object.assign({ open: true, primaryProductId: 10 }, propsData)

    const host = new Vue({
      store,
      components: { panel: Ctor },
      data: () => ({ props }),
      template: "<div><panel v-bind='props' ref='panel' /></div>",
    })

    host.$mount()

    assert.ok(host.$refs.panel, 'the host did not render the panel')

    return host.$refs.panel
  }

  /** The panel's rendered root, which is the host's only child. */
  function rootOf (vm) {
    const root = vm.$parent.$el.firstChild

    assert.ok(root, 'the panel did not render at all')

    return root
  }

  /*
   * Section 14. The tick for a product with a real choice is unreachable until
   * the choice is made -- the server refuses it too, and this is what stops
   * the customer finding out only on clicking.
   */
  await test('a variable product cannot be ticked until an option is chosen', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [variableCard(1)], limit: 4 })

    const vm = mountPanel(store)
    await vm.$nextTick()

    assert.strictEqual(vm.needsChoice(vm.items[0]), true)

    const box = rootOf(vm).querySelector('input[type="checkbox"]')

    assert.ok(box.disabled, 'the tick was reachable without a choice')

    vm.chooseOption(vm.items[0], '901')
    await vm.$nextTick()

    assert.strictEqual(vm.needsChoice(vm.items[0]), false)
    assert.strictEqual(vm.isSelected(vm.items[0]), true, 'choosing an option did not tick it')
  })

  await test('the price shown follows the chosen option', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [variableCard(1)], limit: 4 })

    const vm = mountPanel(store)
    await vm.$nextTick()

    assert.strictEqual(vm.priceOf(vm.items[0]).now, 49, 'the headline is not the cheapest option')

    vm.chooseOption(vm.items[0], '902')
    await vm.$nextTick()

    assert.strictEqual(vm.priceOf(vm.items[0]).now, 55)
    assert.strictEqual(vm.priceOf(vm.items[0]).old, 65)
  })

  await test('a struck-through price only appears when there is a saving', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', {
      items: [card(1, { price: 25, old_price: 30 }), card(2, { price: 25, old_price: 25 })],
      limit: 4,
    })

    const vm = mountPanel(store)
    await vm.$nextTick()

    assert.strictEqual(vm.showsWas(vm.items[0]), true)
    assert.strictEqual(vm.showsWas(vm.items[1]), false)
  })

  /* Section 26: an empty block says so rather than rendering an empty list. */
  await test('no recommendations shows the empty state', async () => {
    const store = buildStore({})

    const vm = mountPanel(store)
    await vm.$nextTick()

    assert.ok(rootOf(vm).innerHTML.includes('crossSell.empty'))
  })

  /*
   * Section 27. The error state talks about the RECOMMENDATIONS. The primary
   * product is already in the basket, and saying anything else here would
   * alarm a customer whose add worked perfectly.
   */
  await test('a load failure says the recommendations failed, not the basket', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ERROR', 'timeout')

    const vm = mountPanel(store)
    await vm.$nextTick()

    assert.ok(rootOf(vm).innerHTML.includes('crossSell.loadFailed'))
    assert.ok(rootOf(vm).innerHTML.includes('crossSell.tryAgain'))
  })

  await test('the loading state paints skeletons rather than nothing', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_LOADING', true)

    const vm = mountPanel(store)
    await vm.$nextTick()

    assert.ok(rootOf(vm).innerHTML.includes('is-skeleton'), 'no placeholder while loading')
  })

  /* The inline form has no backdrop to click away from. */
  await test('the inline block does not close on a stray click', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [card(1)], limit: 4 })

    const vm = mountPanel(store, { inline: true })
    await vm.$nextTick()

    let closed = false
    vm.$on('close', () => { closed = true })

    vm.onBackdrop()

    assert.strictEqual(closed, false)
    assert.strictEqual(rootOf(vm).tagName.toLowerCase(), 'section')
  })

  await test('the overlay closes on a click away', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', { items: [card(1)], limit: 4 })

    const vm = mountPanel(store)
    await vm.$nextTick()

    let closed = false
    vm.$on('close', () => { closed = true })

    vm.onBackdrop()

    assert.strictEqual(closed, true)
  })

  await test('the footer reports the count and the additional total', async () => {
    const store = buildStore({})

    store.commit('crossSell/SET_ITEMS', {
      items: [card(1, { price: 49 }), card(2, { price: 25 })],
      limit: 4,
    })

    const vm = mountPanel(store)
    await vm.$nextTick()

    vm.toggle(vm.items[0])
    vm.toggle(vm.items[1])
    await vm.$nextTick()

    const html = rootOf(vm).innerHTML

    assert.ok(html.includes('"count":2'), 'the panel does not say how many are ticked')
    assert.ok(html.includes('74.00'), 'the additional total is not shown')
  })


  console.log('\nanalytics')

  /*
   * Section 31. Measurement rides along; it never gets in the way.
   *
   * A missing dataLayer is the NORMAL case -- an ad blocker, a tag that has
   * not loaded yet, a server render -- so the one thing worth pinning is that
   * none of it can take a basket down with it.
   */
  await test('events are pushed to the dataLayer', async () => {
    const pushed = []
    global.window.dataLayer = { push: row => pushed.push(row) }

    const store = buildStore({
      crossSellsForProduct: async () => ({
        data: { status: 200, data: { items: [card(1), card(2)], limit: 4 } },
      }),
    })

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })

    const viewed = pushed.find(row => row.event === 'cross_sell_viewed')

    assert.ok(viewed, 'nothing was measured')
    assert.strictEqual(viewed.primary_product_id, 10)
    assert.deepStrictEqual(viewed.cross_sell_product_ids, [1, 2])
    // Which PROMOTION was shown -- the id an admin can find under Promotions.
    assert.deepStrictEqual(viewed.cross_sell_ids, [9])
    assert.ok(viewed.timestamp, 'no timestamp')

    store.dispatch('crossSell/toggle', { item: card(1) })
    const selected = pushed.find(row => row.event === 'cross_sell_product_selected')
    assert.ok(selected)
    assert.strictEqual(selected.cross_sell_id, 9)

    store.dispatch('crossSell/toggle', { item: card(1) })
    assert.ok(pushed.find(row => row.event === 'cross_sell_product_deselected'))

    store.dispatch('crossSell/dismiss')
    assert.ok(pushed.find(row => row.event === 'cross_sell_dismissed'))

    delete global.window.dataLayer
  })

  /* No name, no email, nothing that identifies a person. */
  await test('no personal information is measured', async () => {
    const pushed = []
    global.window.dataLayer = { push: row => pushed.push(row) }

    const store = buildStore({
      crossSellsForProduct: async () => ({
        data: { status: 200, data: { items: [card(1)], limit: 4 } },
      }),
    })

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })

    const payload = JSON.stringify(pushed)

    for (const forbidden of ['email', 'name', 'phone', 'address']) {
      assert.ok(!payload.includes(forbidden), 'the event carries ' + forbidden)
    }

    delete global.window.dataLayer
  })

  await test('a broken tag cannot stop a product being added', async () => {
    global.window.dataLayer = { push () { throw new Error('tag blew up') } }

    const service = {
      addCrossSell: async () => ({
        data: { status: 200, data: { added: [{ id: 1, product_id: 1 }], skipped: [] } },
      }),
    }

    const store = buildStore(service)

    store.commit('crossSell/SET_ITEMS', { items: [card(1)], limit: 4 })
    store.dispatch('crossSell/toggle', { item: card(1) })

    const result = await store.dispatch('crossSell/addSelected', {})

    assert.strictEqual(result.added.length, 1, 'a throwing tag lost the add')

    delete global.window.dataLayer
  })

  await test('no dataLayer at all is not an error', async () => {
    delete global.window.dataLayer

    const store = buildStore({
      crossSellsForProduct: async () => ({
        data: { status: 200, data: { items: [card(1)], limit: 4 } },
      }),
    })

    await store.dispatch('crossSell/loadForProduct', { productId: 10 })

    assert.strictEqual(store.getters['crossSell/crossSellItems'].length, 1)
  })

  console.log('\n' + passed + ' passed, ' + failed + ' failed\n')
  process.exit(failed ? 1 : 0)
}

run()
