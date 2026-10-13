/*
 * The store-wide Flash Discount: the basket banner and the rule behind it.
 *
 * Run with: npm run check:flash-discount
 *
 * Drives the REAL store/cart.js getters and the REAL components/FlashDiscount.vue
 * against the shape GET /api/v1/flash-discount returns, and holds them to two
 * rules:
 *
 *   1. Nothing comes off the basket until the shopper asks. The discount is
 *      opt-in, and `apply_flash_discount` is what the order is told; a summary
 *      that showed a saving the order was not asked for would be a promise the
 *      charge did not keep.
 *   2. What is offered is what the checkout will give. The rule here is
 *      Pricing::flashDiscount restated -- a floor the basket has to clear, a
 *      cap, and never more than the goods are worth.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

// a DOM, before Vue is loaded: Vue 2 decides at import time whether it is in
// a browser, and the non-browser build renders nothing at all
const { JSDOM } = require(path.join(NM, 'jsdom'))
const dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>',
  { url: 'http://localhost/' })

global.window = dom.window
global.document = dom.window.document
global.navigator = dom.window.navigator
global.Element = dom.window.Element
global.HTMLElement = dom.window.HTMLElement
global.SVGElement = dom.window.SVGElement
global.getComputedStyle = dom.window.getComputedStyle

const Vue = require(path.join(NM, 'vue/dist/vue.common.dev.js'))
const Vuex = require(path.join(NM, 'vuex'))
const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

Vue.use(Vuex)
Vue.config.productionTip = false
Vue.config.devtools = false

let renderError = null
Vue.config.errorHandler = (err) => { renderError = err }

/*
 * PriceFormat is auto-imported by Nuxt and reads the store's currency
 * settings. Standing in for it keeps the currency out of the component, which
 * is the point: the old markup printed a bare "€" and bare numbers.
 */
Vue.component('price-format', {
  props: { price: { default: 0 }, decimals: { default: 0 } },
  template: '<span>€{{ Number(price).toFixed(Number(decimals)) }}</span>',
})

function transform (code, filename) {
  return babel.transformSync(code, {
    filename, configFile: false, babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code
}

/* ------------------------------------------------ the server's own rule */

/** app/Models/Helper/Pricing.php::flashDiscount, restated. */
function serverDiscount (flash, subtotal) {
  if (!flash) return 0
  if (subtotal <= 0 || subtotal < Number(flash.min_cart_value || 0)) return 0

  let discount = flash.type === 'percentage'
    ? (subtotal * Number(flash.value)) / 100
    : Number(flash.value)

  const cap = Number(flash.max_discount || 0)

  if (cap > 0) discount = Math.min(discount, cap)

  return Math.round(Math.max(0, Math.min(discount, subtotal)) * 100) / 100
}

/* ------------------------------------------------------- the real store */

const service = { getRequest: null } // installed per mount

function loadCartModule () {
  const code = transform(fs.readFileSync(path.join(ROOT, 'store/cart.js'), 'utf8'), 'cart.js')
  const m = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(m, m.exports, (request) => {
    if (request === 'vue') return { default: Vue, __esModule: true }
    if (request.indexOf('service') !== -1) return { default: service, __esModule: true }
    return {}
  })

  return m.exports
}

const cartModule = loadCartModule()

/* ---------------------------------------------------------- the banner */

const file = path.join(ROOT, 'components/FlashDiscount.vue')
const parsed = compiler.parseComponent(fs.readFileSync(file, 'utf8'))
const cm = { exports: {} }

// eslint-disable-next-line no-new-func
new Function('module', 'exports', 'require', transform(parsed.script.content, 'FlashDiscount.vue'))(
  cm, cm.exports, (request) => {
    if (request === 'vuex') return Vuex
    return {}
  })

const options = cm.exports.default || cm.exports
options.template = parsed.template.content

/**
 * A basket line as store/cart.js holds it, already repriced by the server.
 *
 * `offered` is deliberately higher than the charged price: that is the shape a
 * line has under a flash sale, and reading the wrong one of the two is what
 * overstated the discount on a real basket.
 */
function line (unitPrice, quantity, selected = 1) {
  return {
    id: 1, product_id: 1, quantity, selected,
    unit_price: unitPrice,
    upsell_price: unitPrice,
    line_total: Number((unitPrice * quantity).toFixed(2)),
    original_price: unitPrice + 100,
    flash_product: { selling: unitPrice + 300, offered: unitPrice + 100 },
  }
}

/** A live window around now, unless overridden. */
function promotion (overrides) {
  return Object.assign({
    active: true,
    type: 'percentage',
    value: 15,
    min_cart_value: 100,
    max_discount: 200,
    start_time: new Date(Date.now() - 3600e3).toISOString(),
    end_time: new Date(Date.now() + 3600e3).toISOString(),
  }, overrides)
}

function mount (flashResponse, rows, optIn = false) {
  renderError = null

  service.getRequest = () => Promise.resolve({ data: flashResponse })

  const store = new Vuex.Store({
    modules: {
      cart: Object.assign({ namespaced: true }, cartModule),
    },
  })

  store.commit('cart/SET_CART_PRODUCTS', rows)
  store.commit('cart/SET_FLASH_DISCOUNT_OPT_IN', optIn)

  const Ctor = Vue.extend(Object.assign({}, options))
  const vm = new Ctor({ store })

  vm.$mount(document.createElement('div'))

  return {
    vm,
    store,
    /** What every summary on the site would show. */
    amount: () => store.getters['cart/flashDiscountAmount'],
    offer: () => store.getters['cart/flashDiscountOffer'],
    /** What the order payload would carry. */
    sentToOrder: () => (store.state.cart.flashDiscountOptIn ? 1 : 0),
    visible: () => vm.$el.nodeType !== 8,
    text: () => (vm.$el.outerHTML || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim(),
  }
}

async function settle () {
  await Vue.nextTick()
  await new Promise(r => setTimeout(r, 0))
  await Vue.nextTick()
}

const failures = []

function check (name, fn) {
  try {
    fn()
    console.log('  ok   ' + name)
  } catch (e) {
    failures.push(name + '\n       ' + String(e.message).split('\n')[0])
    console.log('  FAIL ' + name)
  }
}

/* ------------------------------------------------------------- the cases */

async function run () {
  console.log('\nFlash Discount\n')

  /* ---- opt-in: nothing happens until the shopper asks ---------------- */
  {
    const flash = promotion()
    const b = mount(flash, [line(2000, 1)])
    await settle()

    check('the banner renders', () => {
      assert.strictEqual(renderError, null, String(renderError))
    })

    check('nothing comes off the basket by default', () => {
      assert.strictEqual(b.amount(), 0,
        'the summary would show a saving of ' + b.amount() + ' nobody asked for')
    })

    check('and the order is told not to apply it', () => {
      assert.strictEqual(b.sentToOrder(), 0)
    })

    check('the banner offers it rather than claiming it is applied', () => {
      assert.ok(/Flash Discount Available/.test(b.text()), b.text().slice(0, 140))
      assert.ok(/Apply and save/.test(b.text()), b.text().slice(0, 200))
    })

    b.vm.apply()
    await settle()

    check('pressing Apply puts it on the basket', () => {
      assert.strictEqual(b.amount(), serverDiscount(flash, 2000))
    })

    check('and the order is told to apply it', () => {
      assert.strictEqual(b.sentToOrder(), 1)
    })

    check('the banner now says it is applied', () => {
      assert.ok(/Flash Discount Applied/.test(b.text()), b.text().slice(0, 140))
    })

    b.vm.remove()
    await settle()

    check('and it can be taken off again', () => {
      assert.strictEqual(b.amount(), 0)
      assert.strictEqual(b.sentToOrder(), 0)
    })
  }

  /* ---- the offer equals what the checkout will give ------------------ */
  {
    const flash = promotion()
    const b = mount(flash, [line(2000, 1)], true)
    await settle()

    check('the applied amount is the capped one', () => {
      assert.strictEqual(b.amount(), serverDiscount(flash, 2000))
    })

    check('the cap is named in the copy', () => {
      assert.ok(/up to €200\.00/.test(b.text()), b.text().slice(0, 160))
    })

    check('the banner figure matches what the summary holds', () => {
      assert.ok(b.text().indexOf('€' + b.amount().toFixed(2)) !== -1, b.text().slice(0, 200))
    })
  }

  /*
   * The basket from the reported screenshot: one 128GB iPhone 15 Pro listed at
   * 699 and charged at 599 under a 100-off flash sale, against a 15% store-wide
   * promotion capped at 200. The banner read EUR 104.85 -- 15% of the LIST
   * price -- where the checkout gives 15% of the 599 charged.
   */
  {
    const flash = promotion()
    const row = line(599, 1)
    row.original_price = 699
    row.flash_product = { selling: 899, offered: 699 }

    const b = mount(flash, [row], true)
    await settle()

    check('the discount is taken on the price charged, not the list price', () => {
      assert.strictEqual(b.store.getters['cart/selectedGoodsTotal'], 599)
    })

    check('the reported basket is offered what the checkout will give', () => {
      assert.strictEqual(b.amount(), serverDiscount(flash, 599))
    })

    check('and that figure is 89.85, not 104.85', () => {
      assert.strictEqual(b.amount(), 89.85)
    })
  }

  /* ---- a fixed-amount promotion -------------------------------------- */
  {
    const flash = promotion({ type: 'fixed', value: 20, max_discount: 20 })
    const b = mount(flash, [line(500, 1)], true)
    await settle()

    check('a fixed-amount discount is not described as a percentage', () => {
      assert.ok(b.text().indexOf('20% ') === -1, b.text().slice(0, 160))
    })

    check('the amounts in the banner carry a currency symbol', () => {
      assert.ok(/€20\.00 off your order/.test(b.text()) && /Minimum €100\.00/.test(b.text()),
        b.text().slice(0, 200))
    })
  }

  /* ---- a cap of 0, the admin form's old default ----------------------- */
  {
    const flash = promotion({ value: 10, min_cart_value: 0, max_discount: 0 })
    const b = mount(flash, [line(100, 1)], true)
    await settle()

    check('a cap of 0 is read the same way by the basket and by the server', () => {
      assert.strictEqual(b.amount(), serverDiscount(flash, 100))
    })

    check('a cap of 0 does not cancel the discount', () => {
      assert.strictEqual(b.amount(), 10)
    })

    check('no ceiling means the copy does not advertise one', () => {
      assert.ok(b.text().indexOf('up to') === -1, b.text().slice(0, 160))
    })
  }

  {
    const flash = promotion({ value: 10, min_cart_value: 0, max_discount: null })
    const b = mount(flash, [line(100, 1)], true)
    await settle()

    check('a null cap is no cap', () => {
      assert.strictEqual(b.amount(), serverDiscount(flash, 100))
    })
  }

  /* ---- below the minimum ---------------------------------------------- */
  {
    const flash = promotion()
    const b = mount(flash, [line(50, 1)])
    await settle()

    check('a basket under the minimum is offered nothing', () => {
      assert.strictEqual(b.offer(), 0)
      assert.strictEqual(b.amount(), 0)
    })

    check('there is no Apply button to press', () => {
      assert.ok(b.text().indexOf('Apply and save') === -1, b.text().slice(0, 200))
    })

    check('it says how much more is needed', () => {
      assert.ok(/Add €50\.00 more/.test(b.text()), b.text().slice(0, 200))
    })

    check('the progress bar measures progress towards the minimum', () => {
      assert.strictEqual(Math.round(b.vm.progressPercent), 50)
    })
  }

  /* ---- a basket that stops qualifying gives the discount back --------- */
  {
    const flash = promotion()
    const b = mount(flash, [line(2000, 1)], true)
    await settle()

    check('the discount is on to begin with', () => {
      assert.ok(b.amount() > 0)
    })

    // The shopper unticks the only line.
    b.store.commit('cart/UPDATE_SELECTED', { key: 0, value: 0 })
    await settle()

    check('unticking the basket takes the discount back off', () => {
      assert.strictEqual(b.amount(), 0)
    })

    check('and the order is no longer told to apply it', () => {
      assert.strictEqual(b.sentToOrder(), 0,
        'the order would still have claimed a discount this basket cannot have')
    })
  }

  /* ---- windows --------------------------------------------------------- */
  {
    const flash = promotion({
      start_time: new Date(Date.now() - 7200e3).toISOString(),
      end_time: new Date(Date.now() - 3600e3).toISOString(),
      min_cart_value: 0,
    })

    const b = mount(flash, [line(500, 1)], true)
    await settle()

    check('a closed window is worth nothing even to a shopper who opted in', () => {
      assert.strictEqual(b.amount(), 0)
    })

    check('a closed window hides the banner', () => {
      assert.strictEqual(b.visible(), false)
    })
  }

  {
    const flash = promotion({
      start_time: new Date(Date.now() + 3600e3).toISOString(),
      end_time: new Date(Date.now() + 7200e3).toISOString(),
      min_cart_value: 0,
    })

    const b = mount(flash, [line(500, 1)], true)
    await settle()

    check('a window that has not opened yet is not advertised or applied', () => {
      assert.strictEqual(b.visible(), false)
      assert.strictEqual(b.amount(), 0)
    })
  }

  {
    const b = mount({ active: false }, [line(500, 1)], true)
    await settle()

    check('no live promotion means no banner and no discount', () => {
      assert.strictEqual(b.visible(), false)
      assert.strictEqual(b.amount(), 0)
    })
  }

  /* ---- only ticked lines count ---------------------------------------- */
  {
    const flash = promotion({ value: 10, min_cart_value: 0, max_discount: null })
    const b = mount(flash, [line(500, 1, 1), line(500, 1, 0)], true)
    await settle()

    check('only selected lines count towards the discount', () => {
      assert.strictEqual(b.amount(), serverDiscount(flash, 500))
    })
  }

  /* ---- the API being down ---------------------------------------------- */
  {
    renderError = null
    service.getRequest = () => Promise.reject(new Error('502'))

    const store = new Vuex.Store({ modules: { cart: Object.assign({ namespaced: true }, cartModule) } })
    store.commit('cart/SET_CART_PRODUCTS', [line(500, 1)])

    const vm = new (Vue.extend(Object.assign({}, options)))({ store })
    vm.$mount(document.createElement('div'))
    await settle()

    check('a failed lookup leaves the basket alone rather than throwing', () => {
      assert.strictEqual(renderError, null, String(renderError))
      assert.strictEqual(vm.$el.nodeType, 8)
      assert.strictEqual(store.getters['cart/flashDiscountAmount'], 0)
    })
  }

  console.log('')

  if (failures.length) {
    console.log(failures.length + ' failing:\n')
    failures.forEach(f => console.log('  - ' + f))
    process.exitCode = 1
  } else {
    console.log('all green')
  }
}

run().then(() => process.exit(process.exitCode || 0))
  .catch((e) => { console.error(e); process.exit(1) })
