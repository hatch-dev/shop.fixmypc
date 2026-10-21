/*
 * The basket's phone-only fixed bottom summary.
 *
 * Run with: npm run check:cart-mobile-summary
 *
 * What this is here to hold:
 *
 *   ONE SOURCE OF MONEY. The bar renders the card's own computed properties -
 *   totalPrice, cartPrice, the discount getters. It must never be possible for
 *   the figure a shopper taps "Proceed to checkout" under to differ from the
 *   one the summary states, so the assertions below read both out of the same
 *   rendered DOM and compare them rather than comparing either to a constant.
 *
 *   ONE BASKET, NOT TWO. The card and the bar are both in the DOM at once (CSS
 *   decides which is seen), so anything that made the bar fetch or recompute
 *   would double the work on every cart change. Nothing here may call the API.
 *
 *   OPT-IN. Checkout, shipping and payment render the same component and must
 *   not grow a second fixed CTA, so the bar exists only where `mobile-bar` is
 *   passed - which, in the app, is pages/cart.vue and nowhere else.
 *
 *   THE PAGE HAS AN END. The bar is fixed above the tab bar, so the page is
 *   given the bar's MEASURED height to clear. Losing that leaves the last cart
 *   line, or the payment accordion, permanently underneath it.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

// a DOM, before Vue is loaded: Vue 2 decides once, at import time, whether it
// is running in a browser, and the non-browser build renders nothing at all
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
const { withI18n, installI18n } = require('./i18n-en')
const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

Vue.config.productionTip = false
Vue.config.devtools = false

let renderError = null
Vue.config.errorHandler = (err) => { renderError = err }

function transform(code, filename) {
  return babel.transformSync(code, {
    filename, configFile: false, babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code
}

function loadMixin(name) {
  const code = transform(fs.readFileSync(path.join(ROOT, 'mixin', name + '.js'), 'utf8'), name + '.js')
  const m = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(m, m.exports, () => ({}))
  return m.exports.default || m.exports
}

const file = path.join(ROOT, 'components/CheckoutRight.vue')
const parsed = compiler.parseComponent(fs.readFileSync(file, 'utf8'))
const m = { exports: {} }

// eslint-disable-next-line no-new-func
new Function('module', 'exports', 'require', transform(parsed.script.content, 'CheckoutRight.vue'))(
  m, m.exports, (request) => {
    if (request === 'vuex') {
      const spread = () => ({})
      return { mapGetters: spread, mapActions: spread, mapState: spread, mapMutations: spread }
    }
    return {}
  })

const options = m.exports.default || m.exports
options.template = parsed.template.content

/*
 * The mixins and child components the card borrows, stubbed down to what the
 * markup needs. productPriceHelper is loaded for real: formatVatRate is what
 * prints "(23%)" beside VAT, on the bar as well as the card.
 */
options.mixins = [loadMixin('productPriceHelper'), {
  computed: {
    setting: () => ({ currency: 'EUR' }),
    currencyIcon: () => '€',
    currencyPosition: () => 'left',
  },
  methods: {
    require: () => '',
    formatPrice: (v) => Number(Number(v || 0).toFixed(2)),
    priceFormat: (pos, icon, v) => icon + Number(v || 0).toFixed(2),
    sellPriceCalc: (p) => parseFloat((p && p.selling) || 0),
    currentInventoryPriceCalc: (i, p) => parseFloat((p && (p.offered || p.selling)) || 0),
  },
}]

options.components = {
  PriceFormat: {
    props: ['price', 'decimals'],
    template: '<span class="pf">€{{ Number(price || 0).toFixed(2) }}</span>',
  },
  InfoPopover: { template: '<span class="ip"></span>' },
  AjaxButton: {
    props: ['text', 'disabled', 'fetchingData', 'type', 'loadingText', 'color'],
    template: '<button class="ajax-btn" type="button" :disabled="disabled || fetchingData"'
      + ' @click="$emit(\'clicked\')">{{ text }}</button>',
  },
}

installI18n(Vue)

/*
 * The basket from the summary brief: one line priced Ex-VAT, one Inc-VAT, so
 * the bar is exercised on the arrangement that makes the two conventions add
 * up rather than on a single-convention basket where any arithmetic passes.
 */
const IPHONE = {
  id: 1, quantity: 3, vat_rate: 23, vat_inclusive: false,
  original_price: 699, unit_price: 674, shipping_type: 1,
  line_ex_vat: 2022, line_vat: 465.06, line_inc_vat: 2487.06,
  line_list: 2097, line_list_ex_vat: 2097, line_list_inc_vat: 2579.31,
  flash_product: { title: 'Apple iPhone 15 Pro' },
}

const SAMSUNG = {
  id: 2, quantity: 1, vat_rate: 23, vat_inclusive: true,
  original_price: 1000, unit_price: 899, shipping_type: 1,
  line_ex_vat: 730.89, line_vat: 168.11, line_inc_vat: 899,
  line_list: 1000, line_list_ex_vat: 813.01, line_list_inc_vat: 1000,
  flash_product: { title: 'Samsung Galaxy A05s' },
}

let apiCalls = 0

function mount(props, cart) {
  renderError = null

  const state = {
    cart: Object.assign({
      flashDiscount: { amount: 0 },
      loyaltyDiscount: { amount: 0 },
      bundleDiscount: { bundles: [], total: 0 },
      pricesPending: false,
      pricesError: null,
    }, cart || {}),
  }

  const store = new Vue({ data: { state: state } })

  Vue.prototype.$store = {
    state: store.state,
    /*
     * The promotion discounts are getters on the cart module now, so a stub
     * without them is not a store this card can be mounted against -- it reads
     * cart/flashDiscountAmount for the summary row.
     */
    getters: Object.assign({
      'cart/flashDiscountAmount': 0,
      'cart/flashDiscountOffer': 0,
      'cart/flashDiscountEligible': false,
    }, (cart || {}).getters || {}),
    dispatch: () => { apiCalls++; return Promise.resolve() },
    commit: () => {},
  }

  /*
   * The slot is filled on purpose: /shipping and /checkout both put something
   * in it, and where that something ends up is the thing most likely to break
   * - it is the one piece of the card the bar has to MOVE rather than hide.
   */
  const host = new Vue({
    components: { cr: options },
    data: {
      props: Object.assign(
        { checkedProduct: [IPHONE, SAMSUNG], hasShipping: true }, props || {}),
    },
    template: '<div><cr v-bind="props">'
      + '<template #checkout><p class="page-slot">page content</p></template>'
      + '</cr></div>',
  }).$mount()

  if (renderError) throw renderError

  return { host: host, vm: host.$children[0] }
}

function text(el) {
  return el ? el.textContent.replace(/\s+/g, ' ').trim() : null
}

/* Every euro figure in a subtree, in order, as plain numbers. */
function amounts(el) {
  return Array.prototype.slice.call(el.querySelectorAll('.pf'))
    .map((n) => Number(n.textContent.replace(/[^\d.]/g, '')))
}

let failures = 0

/*
 * Queued rather than run on the spot, because two of these have to wait a tick:
 * the bar measures itself in $nextTick, and the fold's open state is a render
 * away. Running them as they are declared would assert against the frame
 * before the one being tested and pass for the wrong reason.
 */
const queue = []

function section(title) {
  queue.push({ title: title })
}

function check(name, fn) {
  queue.push({ name: name, fn: fn })
}

async function run() {
  for (const item of queue) {
    if (item.title) {
      console.log('\n' + item.title)
      continue
    }

    try {
      await item.fn()
      console.log('  ok   ' + item.name)
    } catch (err) {
      failures++
      console.log('  FAIL ' + item.name)
      console.log('       ' + err.message)
    }
  }

  console.log(failures ? '\n' + failures + ' check(s) failed\n' : '\nall checks passed\n')
  process.exit(failures ? 1 : 0)
}

section('\nwhere the bar exists at all')

check('no mobile-bar prop, no bar: the other pages are untouched', () => {
  const r = mount()
  assert.strictEqual(r.host.$el.querySelector('.mfs'), null)
  assert.ok(r.host.$el.querySelector('.summary'), 'the card itself must still render')
})

check('the three basket-flow pages ask for one, and /payment does not', () => {
  const pages = fs.readdirSync(path.join(ROOT, 'pages')).filter((f) => f.endsWith('.vue'))

  const asking = pages.filter((f) => /mobile-bar|:mobileBar/.test(
    fs.readFileSync(path.join(ROOT, 'pages', f), 'utf8')))

  /*
   * cart -> shipping -> checkout is the flow a shopper walks, and the total
   * has to stay in reach the whole way. /payment is the page a gateway sends
   * them BACK to with its own form and its own submit; a fixed bar there
   * would sit over that form offering a second way out of it.
   */
  assert.deepStrictEqual(asking.sort(), ['cart.vue', 'checkout.vue', 'shipping.vue'])
})

check('the card is rendered alongside the bar, for CSS to choose between', () => {
  const r = mount({ mobileBar: true })
  assert.ok(r.host.$el.querySelector('.summary'), 'card missing')
  assert.ok(r.host.$el.querySelector('.mfs'), 'bar missing')
  assert.ok(r.host.$el.querySelector('.has-mobile-bar'), 'the column is not flagged for the CSS')
})

section('\nthe bar states the card figures, never its own')

check('the total under the button is the card total, to the cent', () => {
  const r = mount({ mobileBar: true })

  const card = amounts(r.host.$el.querySelector('.sum-total'))
  const bar = amounts(r.host.$el.querySelector('.mfs-head-value'))

  assert.deepStrictEqual(bar, card)
  assert.strictEqual(bar[0], 3386.06)
})

check('the breakdown restates the card row for row', () => {
  const r = mount({ mobileBar: true })
  const panel = r.host.$el.querySelector('.mfs-panel')

  // You Save, Products Total (Ex-VAT), VAT (added + inside Inc-VAT prices).
  // Shipping is free, so it states no figure.
  assert.deepStrictEqual(amounts(panel), [176, 2752.89, 633.17])
  assert.ok(/You Save/.test(text(panel)))
  assert.ok(/VAT \(23%\)/.test(text(panel)), 'the rate label is missing')
  assert.ok(/Free/.test(text(panel)), 'shipping is not stated')
})

check('an order-wide discount reaches the bar as its own row', () => {
  /*
   * The loyalty discount is still a server figure held in state; the flash
   * discount is derived, so it arrives as a getter. Seeding the old
   * `flashDiscount: { amount }` key would be seeding something nothing reads.
   */
  const r = mount({ mobileBar: true }, {
    loyaltyDiscount: { amount: 250, label: 'Trade Discount' },
    getters: { 'cart/flashDiscountAmount': 30 },
  })

  const panel = text(r.host.$el.querySelector('.mfs-panel'))
  assert.ok(/Trade Discount/.test(panel), 'the loyalty label is missing')
  assert.ok(/Flash Discount/.test(panel), 'the flash row is missing')

  // and the total moved by exactly those two
  const total = amounts(r.host.$el.querySelector('.mfs-head-value'))[0]
  assert.strictEqual(total, Number((3386.06 - 250 - 30).toFixed(2)))
})

check('a basket whose prices have not arrived declines to state a total', () => {
  const stale = Object.assign({}, IPHONE)
  delete stale.vat_rate

  const r = mount({ mobileBar: true, checkedProduct: [stale] })

  assert.strictEqual(text(r.host.$el.querySelector('.mfs-head-value')), '—')
  assert.ok(/Updating prices/.test(text(r.host.$el.querySelector('.mfs-panel'))))
})

check('the bar asks the server for nothing', () => {
  apiCalls = 0
  mount({ mobileBar: true })
  assert.strictEqual(apiCalls, 0)
})

section('\nthe checkout action')

check('tapping it emits go-next, the same event the card emits', () => {
  const r = mount({ mobileBar: true })
  let fired = 0
  r.vm.$on('go-next', () => { fired++ })

  r.host.$el.querySelector('.mfs-cta').click()
  assert.strictEqual(fired, 1)
})

check('disabled when the page says checkout is unavailable', () => {
  const r = mount({ mobileBar: true, disabled: true })
  assert.ok(r.host.$el.querySelector('.mfs-cta').disabled)
})

check('busy is disabled, and told apart from unavailable', () => {
  const r = mount({ mobileBar: true, submitting: true })
  const cta = r.host.$el.querySelector('.mfs-cta')

  assert.ok(cta.disabled, 'a second tap could place a second order')
  assert.ok(/is-busy/.test(cta.className),
    'a greyed-out button mid-submit reads as one that failed')
})

section('\nwhat the page put beside the button')

/*
 * /shipping fills this slot with the "what is still missing" note and
 * /checkout with the voucher form. The card is hidden on a phone, so the slot
 * has to move into the fold - and it has to move, not copy: two voucher
 * inputs bound to one model, or a live status message announced twice, is
 * worse than either place on its own.
 */
check('on a desktop it stays in the card, and the fold has none of it', () => {
  const r = mount({ mobileBar: true })

  const card = r.host.$el.querySelector('.summary')
  assert.ok(card.querySelector('.page-slot'), 'the card lost the slot')
  assert.strictEqual(r.host.$el.querySelectorAll('.page-slot').length, 1)
  assert.strictEqual(r.host.$el.querySelector('.mfs-slot'), null)
})

check('on a phone it moves into the fold, leaving the card with none', async () => {
  const r = mount({ mobileBar: true })
  r.vm.onPhone = true
  await Vue.nextTick()

  const holder = r.host.$el.querySelector('.mfs-slot')
  assert.ok(holder, 'the fold has nowhere for it')
  assert.ok(holder.querySelector('.page-slot'), 'the slot did not move')
  assert.strictEqual(r.host.$el.querySelectorAll('.page-slot').length, 1,
    'it was copied rather than moved')
  assert.strictEqual(r.host.$el.querySelector('.summary').querySelector('.page-slot'), null)
})

check('the moved content keeps the PAGE scope, so its CSS follows it', async () => {
  /*
   * /shipping styles its note in a `scoped` block, so the rule is really
   * .checkout-blocked[data-v-page]. Slot content is compiled by the parent and
   * must therefore carry the PAGE's scope id even once it is rendering inside
   * this component - if it picked up the summary's instead, the note would
   * arrive in the fold as unstyled text and nothing would say so.
   */
  const scoped = new Vue({
    _scopeId: 'data-v-page',
    components: { cr: options },
    data: { props: { checkedProduct: [IPHONE], hasShipping: true, mobileBar: true } },
    template: '<div><cr v-bind="props">'
      + '<template #checkout><p class="page-slot">note</p></template>'
      + '</cr></div>',
  }).$mount()

  scoped.$children[0].onPhone = true
  await Vue.nextTick()

  const moved = scoped.$el.querySelector('.mfs-slot .page-slot')
  assert.ok(moved, 'the slot did not reach the fold')
  assert.ok(moved.hasAttribute('data-v-page'),
    'the page scope was lost on the way into the fold')

  scoped.$destroy()
})

check('a page with nothing to say gets no empty holder', async () => {
  // the basket passes no slot at all
  const bare = new Vue({
    components: { cr: options },
    data: { props: { checkedProduct: [IPHONE], hasShipping: true, mobileBar: true } },
    template: '<div><cr v-bind="props" /></div>',
  }).$mount()

  bare.$children[0].onPhone = true
  await Vue.nextTick()

  assert.strictEqual(bare.$el.querySelector('.mfs-slot'), null)
  bare.$destroy()
})

check('the card is never the summary and the bar at once', async () => {
  const r = mount({ mobileBar: true })
  r.vm.onPhone = true
  await Vue.nextTick()

  // both are in the DOM - CSS decides - but only one holds the page's content
  assert.ok(r.host.$el.querySelector('.summary'))
  assert.ok(r.host.$el.querySelector('.mfs'))
  assert.strictEqual(r.host.$el.querySelectorAll('.page-slot').length, 1)
})

section('\nthe bar on a card that has no button')

/*
 * /checkout is that page. Its summary is `hide-btn` because the control that
 * places the order belongs to the payment gateway - nine of them, two
 * rendering their own SDK buttons - so the bar there is a way back to that
 * control rather than a second one.
 */
check('hide-btn alone leaves the bar with no CTA', () => {
  const r = mount({ mobileBar: true, hideBtn: true })

  assert.strictEqual(r.host.$el.querySelector('.mfs-cta'), null)
  assert.ok(r.host.$el.querySelector('.mfs-head'), 'the total must still be there')
})

check('a page that names its own action gets one, despite hide-btn', () => {
  const r = mount({ mobileBar: true, hideBtn: true, mobileBtnText: 'Go to payment' })
  const cta = r.host.$el.querySelector('.mfs-cta')

  assert.ok(cta, 'no CTA rendered')
  assert.strictEqual(text(cta), 'Go to payment')
  // and the card still has none: the gateway keeps the only submit
  assert.strictEqual(r.host.$el.querySelector('.summary .primary-btn'), null)
})

check('without one it falls back to the card button label', () => {
  const r = mount({ mobileBar: true, btnText: 'Proceed to Payment' })

  assert.strictEqual(text(r.host.$el.querySelector('.mfs-cta')), 'Proceed to Payment')
})

check('/checkout asks for a label and /shipping does not need to', () => {
  const checkout = fs.readFileSync(path.join(ROOT, 'pages/checkout.vue'), 'utf8')
  const shipping = fs.readFileSync(path.join(ROOT, 'pages/shipping.vue'), 'utf8')

  assert.ok(checkout.includes("mobile-btn-text") && checkout.includes("checkout.goToPayment"),
    '/checkout must name its own action - its card has no button to borrow one from')
  assert.ok(/@go-next="goToPayment"/.test(checkout), 'nothing is wired to the bar')
  assert.ok(!/mobile-btn-text/.test(shipping),
    '/shipping already has a button; a second label would be a second name for it')

  // the label must exist in English or vue-i18n prints the key
  const en = JSON.parse(fs.readFileSync(path.join(ROOT, 'lang/en.json'), 'utf8'))
  assert.ok(en.checkout && en.checkout.goToPayment, 'lang/en.json has no checkout.goToPayment')
})

check('the bar scrolls to payment, it does not place the order', () => {
  const checkout = fs.readFileSync(path.join(ROOT, 'pages/checkout.vue'), 'utf8')

  assert.ok(checkout.includes('goToPayment() {'), 'no goToPayment method')

  const body = checkout.slice(checkout.indexOf('goToPayment() {'))
    .slice(0, 900)

  assert.ok(/scrollIntoView/.test(body), 'it does not scroll anywhere')
  assert.ok(!/confirmOrder|placeOrder|initStripe|initSumup|payWith/.test(body),
    'the bar must never be a second way to place an order')
})

section('\nthe fold')

check('closed on arrival, and the control says so', () => {
  const r = mount({ mobileBar: true })
  const toggle = r.host.$el.querySelector('.mfs-head')

  assert.strictEqual(toggle.getAttribute('aria-expanded'), 'false')
  assert.strictEqual(toggle.getAttribute('aria-controls'), 'mobile-summary-panel')
  assert.ok(r.host.$el.querySelector('#mobile-summary-panel'), 'nothing carries that id')
  assert.strictEqual(r.host.$el.querySelector('.mfs-panel').style.display, 'none')
})

check('it names the action without replacing the amount it is attached to', () => {
  const r = mount({ mobileBar: true })
  const toggle = r.host.$el.querySelector('.mfs-head')

  assert.strictEqual(toggle.getAttribute('aria-label'), null,
    'an aria-label here would hide the total from a screen reader')
  assert.ok(/Total \(Inc-VAT\)/.test(text(toggle)))
  assert.ok(/Show price breakdown/.test(text(toggle)))
})

check('opening it flips the control, the label and the panel', async () => {
  const r = mount({ mobileBar: true })
  r.vm.mobileOpen = true
  await Vue.nextTick()

  const toggle = r.host.$el.querySelector('.mfs-head')
  assert.strictEqual(toggle.getAttribute('aria-expanded'), 'true')
  assert.ok(/Hide price breakdown/.test(text(toggle)))
  assert.notStrictEqual(r.host.$el.querySelector('.mfs-panel').style.display, 'none')
})

section('\nthe page still has an end')

check('mounting claims the clearance and publishes a MEASURED height', async () => {
  mount({ mobileBar: true })
  await Vue.nextTick()

  assert.ok(document.body.classList.contains('has-mobile-summary'),
    'nothing tells the page to clear the bar')
  assert.notStrictEqual(
    document.documentElement.style.getPropertyValue('--mobile-summary-h'), '',
    'the height was never published')
})

check('leaving the basket gives it back', () => {
  const r = mount({ mobileBar: true })
  r.host.$destroy()

  assert.ok(!document.body.classList.contains('has-mobile-summary'),
    'every page after the basket would keep a band of dead space')
  assert.strictEqual(
    document.documentElement.style.getPropertyValue('--mobile-summary-h'), '')
})

check('a card with no bar never touches the body', () => {
  document.body.classList.remove('has-mobile-summary')
  mount()
  assert.ok(!document.body.classList.contains('has-mobile-summary'))
})

check('the stylesheet spends that height, above the tab bar', () => {
  const styl = fs.readFileSync(path.join(ROOT, 'assets/styles/mobile-chrome.styl'), 'utf8')

  assert.ok(/body\.has-mobile-summary/.test(styl), 'nothing clears the bar')
  assert.ok(/--mobile-summary-h/.test(styl), 'the measured height is not read back')

  const css = parsed.styles.map((s) => s.content).join('\n')

  // the tab bar is z-index 45; anything lower and the summary is behind it
  assert.ok(/z-index:\s*46/.test(css), 'the bar does not sit above the tab bar')
  assert.ok(/bottom:\s*calc\(64px \+ env\(safe-area-inset-bottom/.test(css),
    'the bar is not lifted clear of the tab bar and the safe area')
  assert.ok(/\.has-mobile-bar \.summary\s*\{\s*display:\s*none/.test(css),
    'the card is not hidden on a phone, so the total is stated twice')
})

run()
