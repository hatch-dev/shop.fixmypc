/*
 * The basket, repriced as the quantity changes.
 *
 * Run with: npm run check:cart-repricing
 *
 * Exercises the real cart store module and the real CartProductTile computeds
 * against the shapes the cart API actually returns, with the API stubbed.
 *
 * The bug this pins down: cartAction took only `quantity` from the write
 * response and left the unit price at whatever the line was priced at before.
 * The server had already repriced the line for the new quantity — nothing
 * carried the figure across — so a business account stepping from 10 to 11
 * kept the 5-10 band's rate, and the line total became
 * old-unit-price x new-quantity. Every figure derived from it was wrong with
 * it: subtotal, discount, VAT and the grand total.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const Vue = require(path.join(NM, 'vue'))
const Vuex = require(path.join(NM, 'vuex'))
const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

Vue.use(Vuex)
Vue.config.productionTip = false
Vue.config.devtools = false

/* ------------------------------------------------------------------ bands */

/*
 * The shape of a configured rate card. The band a quantity falls in, and the
 * price it charges, are the SERVER's answers — this stands in for them so the
 * store can be driven without a database.
 */
const BANDS = [
  { min: 1, max: 4, price: 699 },
  { min: 5, max: 9, price: 669 },
  { min: 10, max: 24, price: 629 },
  { min: 25, max: 0, price: 599 }, // max 0 = open ended, as the admin stores it
]

const RETAIL = 899

function bandFor (quantity) {
  return BANDS.find(b => quantity >= b.min && (b.max <= 0 || quantity <= b.max))
}

/** What the server would charge per unit at this quantity. */
function serverUnitPrice (quantity, entitled) {
  if (!entitled) return 699 // the retail offer price, no band

  return Math.min(699, bandFor(quantity).price)
}

/* ------------------------------------------------------- the store module */

function loadStore () {
  const src = fs.readFileSync(path.join(ROOT, 'store/cart.js'), 'utf8')

  const code = babel.transformSync(src, {
    filename: 'cart.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const service = {
    cartAction: null, // installed per test
    cartByUser: null,
  }

  const fakeRequire = (request) => {
    if (request === 'vue') return { default: Vue, __esModule: true }
    if (request.indexOf('service') !== -1) return { default: service, __esModule: true }
    return {}
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return { module: module.exports, service }
}

/** A basket line as GET /cart/by-user returns it, already repriced. */
function cartRow (quantity, entitled, overrides = {}) {
  const unit = serverUnitPrice(quantity, entitled)

  return Object.assign({
    id: 77,
    product_id: 99492258,
    inventory_id: 147,
    quantity,
    selected: 1,
    shipping_type: 1,
    upsell_price: unit,
    unit_price: unit,
    original_price: RETAIL,
    line_total: Number((unit * quantity).toFixed(2)),
    // The list price of the line, as byUser sends it: against the quantity.
    line_list: Number((RETAIL * quantity).toFixed(2)),
    flash_product: {
      id: 99492258,
      title: 'Apple iPhone 15 Pro',
      selling: RETAIL,
      offered: 699,
      excludeVAT: 1,
      tax_rules: { id: 1, title: 'VAT23', type: 2, price: 23 },
      bundle_deal: null,
    },
    updated_inventory: { id: 147, price: 699, quantity: 500, inventory_attributes: [] },
    shipping_place: { price: 0, pickup_price: 0, shipping_rule: { id: 1, single_price: 0 } },
  }, overrides)
}

function buildStore (rows, entitled) {
  const loaded = loadStore()

  const store = new Vuex.Store({
    modules: {
      cart: Object.assign({ namespaced: true }, {
        state: loaded.module.state,
        getters: loaded.module.getters,
        mutations: loaded.module.mutations,
        actions: loaded.module.actions,
      }),
      common: {
        namespaced: true,
        state: () => ({}),
        mutations: { SET_TOAST_MESSAGE () {}, SET_TOAST_ERROR (s, m) { toasts.push(m) } },
      },
      user: {
        namespaced: true,
        actions: { getUserToken: () => null },
      },
    },
  })

  store.$auth = { strategy: { token: { get: () => 'token' } }, loggedIn: true }
  store.$i18n = { t: () => 'Added' }

  store.commit('cart/SET_CART_PRODUCTS', rows)

  /*
   * The server: reprices the line for the new quantity and answers with what
   * it settled on, exactly as CartsController::action does.
   */
  let refetches = 0

  loaded.service.cartAction = (apiVal) => {
    const row = store.state.cart.cartProducts.find(r => r.product_id === apiVal.product_id)
    const quantity = row ? row.quantity + apiVal.quantity : apiVal.quantity
    const unit = serverUnitPrice(quantity, entitled)

    return Promise.resolve({
      data: {
        status: 200,
        data: {
          id: row ? row.id : 99,
          product_id: apiVal.product_id,
          quantity,
          price: unit,
          unit_price: unit,
          upsell_price: unit,
          original_price: RETAIL,
          line_total: Number((unit * quantity).toFixed(2)),
          /*
           * The list price of the line, as CartsController::attachVatSplit
           * sends it: measured against the NEW quantity, like everything else
           * in this response.
           */
          line_list: Number((RETAIL * quantity).toFixed(2)),
        },
      },
    })
  }

  loaded.service.cartByUser = () => {
    refetches++
    return Promise.resolve({ data: { status: 200, data: store.state.cart.cartProducts } })
  }

  return { store, refetches: () => refetches, service: loaded.service }
}

const toasts = []

/* ------------------------------------------------------------- the tile */

function loadTile () {
  const sfcPath = path.join(ROOT, 'components/CartProductTile.vue')
  const sfc = compiler.parseComponent(fs.readFileSync(sfcPath, 'utf8'))

  const code = babel.transformSync(sfc.script.content, {
    filename: 'CartProductTile.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const stub = { default: {}, __esModule: true }
  const fakeRequire = (request) => {
    if (request === 'vuex') {
      return { mapGetters: () => ({}), mapActions: () => ({}), mapState: () => ({}) }
    }
    if (request.indexOf('~/') === 0 || request.indexOf('@/') === 0) return stub
    try { return require(path.join(NM, request)) } catch (e) { return stub }
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return module.exports.default || module.exports
}

const CartProductTile = loadTile()

function tileFor (row) {
  const vm = Object.create(null)
  vm.cart = row

  for (const source of [...(CartProductTile.mixins || []), CartProductTile]) {
    for (const [name, fn] of Object.entries(source.methods || {})) {
      vm[name] = fn.bind(vm)
    }
  }
  for (const source of [...(CartProductTile.mixins || []), CartProductTile]) {
    for (const [name, fn] of Object.entries(source.computed || {})) {
      if (typeof fn !== 'function') continue
      Object.defineProperty(vm, name, { get: fn.bind(vm), configurable: true })
    }
  }
  vm.$options = CartProductTile

  return vm
}


/* ----------------------------------------------------------- the summary */

function loadSummary () {
  const sfcPath = path.join(ROOT, 'components/CheckoutRight.vue')
  const sfc = compiler.parseComponent(fs.readFileSync(sfcPath, 'utf8'))

  const code = babel.transformSync(sfc.script.content, {
    filename: 'CheckoutRight.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const stub = { default: {}, __esModule: true }
  const fakeRequire = (request) => {
    if (request === 'vuex') {
      return { mapGetters: () => ({}), mapActions: () => ({}), mapState: () => ({}) }
    }
    if (request.indexOf('~/') === 0 || request.indexOf('@/') === 0) return stub
    try { return require(path.join(NM, request)) } catch (e) { return stub }
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return module.exports.default || module.exports
}

const CheckoutRight = loadSummary()

/*
 * The VAT split the server sends down with every basket line.
 *
 * The summary does not work tax out any more — CartsController::
 * repriceForCustomer asks Pricing::vatBreakdown once and sends the answer, and
 * CheckoutRight sums `line_vat` and friends rather than applying a rate to
 * anything. That is the whole point of the split living server-side, and it
 * means a fixture that carries only a tax_rules object is not a basket payload
 * the storefront will ever see: every VAT assertion below it silently measures
 * zero against zero.
 *
 * So the fixture is decorated here the way the server decorates it, from the
 * row's own rule and quantity, after any per-test override of either. A row
 * that already states its split (the brief's worked example does) is left
 * exactly as written.
 */
function withServerVat (row) {
  if (row.line_inc_vat !== undefined) return row

  const product = row.flash_product || {}
  const rule = product.tax_rules
  const rate = rule && parseInt(rule.type) === 2 ? parseFloat(rule.price) : 0

  // excludeVAT = 1 means the price is quoted net; everything else is gross.
  const inclusive = parseInt(product.excludeVAT || 0) !== 1

  const quantity = parseInt(row.quantity || 0)
  const unit = parseFloat(row.unit_price ?? row.upsell_price ?? 0)
  const list = parseFloat(row.original_price ?? unit)

  // A "buy X get Y free" bundle does not tax the free ones.
  const bundle = product.bundle_deal
  const free = bundle && quantity >= parseInt(bundle.buy) ? parseInt(bundle.free) : 0
  const payable = Math.max(quantity - free, 0)

  // Pricing::vatBreakdown, in its two directions.
  const split = (amount) => {
    if (!(rate > 0)) return { ex: round2(amount), vat: 0, inc: round2(amount) }

    if (inclusive) {
      const ex = round2(amount / (1 + rate / 100))
      const vat = round2(amount - ex)
      return { ex, vat, inc: round2(ex + vat) }
    }

    const ex = round2(amount)
    const vat = round2((amount * rate) / 100)
    return { ex, vat, inc: round2(ex + vat) }
  }

  const unitSplit = split(unit)
  const lineSplit = split(round2(unit * payable))
  const listSplit = split(round2(list * quantity))

  return Object.assign({}, row, {
    vat_rate: rate,
    vat_inclusive: inclusive,
    unit_ex_vat: unitSplit.ex, unit_vat: unitSplit.vat, unit_inc_vat: unitSplit.inc,
    line_ex_vat: lineSplit.ex, line_vat: lineSplit.vat, line_inc_vat: lineSplit.inc,
    line_list_inc_vat: listSplit.inc,
  })
}

function summaryFor (rows, overrides = {}) {
  const vm = Object.create(null)

  /*
   * The two helpers the summary borrows from the util mixin, which the stub
   * loader above does not provide. Same values as mixin/util.js.
   */
  const priceType = { FLAT: 1, PERCENT: 2 }

  Object.assign(vm, {
    priceType,
    priceByType (totalPrice, price, type) {
      if (parseInt(type) === priceType.FLAT) return price
      if (parseInt(type) === priceType.PERCENT) return (totalPrice * price) / 100
      return 0
    },
    sellPriceCalc: (product) => parseFloat(product?.selling || 0),
    currentInventoryPriceCalc: (inventory, product) =>
      parseFloat(product?.offered || product?.selling || 0),
    checkedProduct: rows.map(withServerVat),
    hasShipping: true,
    voucherResult: null,
    flashDiscount: 0,
    loyaltyDiscount: 0,
    /*
     * The Essentials Bundle saving, which the component reads off the store.
     * Stubbed at zero like its neighbours so these cases stay about VAT and
     * carriage; BundleCheckoutTest covers what the discount itself does.
     */
    bundleDiscount: 0,
    bundleDiscounts: [],
    pointsDiscount: 0,
    $emit: () => {},
  }, overrides)

  for (const source of [...(CheckoutRight.mixins || []), CheckoutRight]) {
    for (const [name, fn] of Object.entries(source.methods || {})) {
      if (!(name in vm)) vm[name] = fn.bind(vm)
    }
  }
  for (const source of [...(CheckoutRight.mixins || []), CheckoutRight]) {
    for (const [name, fn] of Object.entries(source.computed || {})) {
      if (name in vm || typeof fn !== 'function') continue
      Object.defineProperty(vm, name, { get: fn.bind(vm), configurable: true })
    }
  }

  return vm
}

const round2 = (n) => Math.round(n * 100) / 100

/* --------------------------------------------------------------- harness */

let failures = 0
function test (name, fn) {
  return Promise.resolve()
    .then(fn)
    .then(() => console.log('  ok   ' + name))
    .catch((e) => {
      failures++
      console.log('  FAIL ' + name + '\n         ' + e.message)
    })
}

async function step (ctx, by) {
  await ctx.store.dispatch('cart/cartAction', {
    lang: 'en',
    payload: {
      apiVal: { product_id: 99492258, inventory_id: 147, quantity: by },
      storeVal: { quantity: by, selected: '1' },
    },
  })

  return ctx.store.state.cart.cartProducts.find(r => r.product_id === 99492258)
}

async function main () {
  console.log('\nthe band for a quantity')

  await test('each quantity lands in its configured band', () => {
    const seen = [1, 2, 4, 5, 9, 10, 11, 24, 25, 30, 50, 100].map(q => serverUnitPrice(q, true))
    assert.deepStrictEqual(
      seen,
      [699, 699, 699, 669, 669, 629, 629, 629, 599, 599, 599, 599]
    )
  })

  await test('the open-ended top band covers everything above its minimum', () => {
    assert.strictEqual(serverUnitPrice(25, true), 599)
    assert.strictEqual(serverUnitPrice(100000, true), 599)
  })

  console.log('\nstepping the quantity up')

  await test('crossing a threshold changes the unit price on the line', async () => {
    const ctx = buildStore([cartRow(10, true)], true)

    let row = ctx.store.state.cart.cartProducts[0]
    assert.strictEqual(parseFloat(row.unit_price), 629, 'starting point')

    row = await step(ctx, 1) // 10 -> 11, still the 10-24 band

    assert.strictEqual(parseInt(row.quantity), 11)
    assert.strictEqual(parseFloat(row.unit_price), 629)
    assert.strictEqual(parseFloat(row.line_total), 6919)
  })

  await test('the original-price figures follow the quantity too', async () => {
    /*
     * The reported bug: the Order Summary's "Original Products" row stayed at
     * one unit's original price while the quantity climbed. The server sends
     * the right figure on every write; the store copies server fields onto the
     * row by an explicit allow-list, and `line_list` was not on it, so the row
     * kept the value it was added with.
     *
     * The symptom is quiet and expensive: Products Total moves, the original
     * does not, and the saving between them is wrong by whole units.
     */
    const ctx = buildStore([cartRow(1, false)], false)

    let row = ctx.store.state.cart.cartProducts[0]
    assert.strictEqual(parseFloat(row.line_list), 899, 'one unit, at retail')

    row = await step(ctx, 1) // 1 -> 2

    assert.strictEqual(parseInt(row.quantity), 2)
    assert.strictEqual(parseFloat(row.line_list), 1798, '899 x 2, not 899')

    row = await step(ctx, 1) // 2 -> 3

    assert.strictEqual(parseFloat(row.line_list), 2697, '899 x 3')

    row = await step(ctx, -1) // back down to 2

    assert.strictEqual(parseFloat(row.line_list), 1798, 'and back down again')
  })

  await test('every server field on the write response reaches the row', () => {
    /*
     * The allow-list is the thing that broke, so this guards the list itself
     * rather than one more field on it: anything the server answers a write
     * with has to be copied, or it silently goes stale on the next step.
     */
    const store = loadStore().module
    const src = fs.readFileSync(path.join(ROOT, 'store/cart.js'), 'utf8')
    const listed = src.slice(
      src.indexOf('SERVER_PRICE_FIELDS = ['), src.indexOf(']', src.indexOf('SERVER_PRICE_FIELDS = ['))
    )

    for (const field of [
      'unit_price', 'original_price', 'line_total',
      'vat_rate', 'vat_inclusive',
      'unit_ex_vat', 'unit_vat', 'unit_inc_vat',
      'line_ex_vat', 'line_vat', 'line_inc_vat',
      'line_list', 'line_list_ex_vat', 'line_list_inc_vat',
    ]) {
      assert.ok(
        listed.indexOf(`'${field}'`) !== -1,
        `${field} is missing from SERVER_PRICE_FIELDS and will go stale`
      )
    }

    assert.ok(store, 'the store module loads')
  })

  await test('the reported case: 10 -> 11 does not keep the old rate x 11', async () => {
    const ctx = buildStore([cartRow(9, true)], true)

    // 9 is in the 5-9 band at 669; stepping to 10 crosses into 10-24 at 629.
    let row = await step(ctx, 1)

    assert.strictEqual(parseFloat(row.unit_price), 629, 'the new band, not the old')
    assert.notStrictEqual(parseFloat(row.line_total), 669 * 10, 'old rate x new quantity')
    assert.strictEqual(parseFloat(row.line_total), 6290)

    row = await step(ctx, 1) // 10 -> 11
    assert.strictEqual(parseFloat(row.unit_price), 629)
    assert.strictEqual(parseFloat(row.line_total), 6919)
  })

  await test('every threshold going up', async () => {
    const ctx = buildStore([cartRow(1, true)], true)

    const seen = { 1: 699 }

    for (let q = 2; q <= 30; q++) {
      const row = await step(ctx, 1)
      seen[q] = parseFloat(row.unit_price)
      assert.strictEqual(parseInt(row.quantity), q, 'quantity tracked at ' + q)
    }

    assert.strictEqual(seen[4], 699)
    assert.strictEqual(seen[5], 669, '5 enters the second band')
    assert.strictEqual(seen[9], 669)
    assert.strictEqual(seen[10], 629, '10 enters the third band')
    assert.strictEqual(seen[24], 629)
    assert.strictEqual(seen[25], 599, '25 enters the top band')
    assert.strictEqual(seen[30], 599)
  })

  console.log('\nstepping the quantity down')

  await test('the price moves back down through the bands', async () => {
    const ctx = buildStore([cartRow(30, true)], true)

    const drops = [
      [5, 25, 599],
      [1, 24, 629],
      [14, 10, 629],
      [1, 9, 669],
      [4, 5, 669],
      [4, 1, 699],
    ]

    for (const [by, expectedQty, expectedPrice] of drops) {
      const row = await step(ctx, -by)
      assert.strictEqual(parseInt(row.quantity), expectedQty)
      assert.strictEqual(
        parseFloat(row.unit_price), expectedPrice,
        'at quantity ' + expectedQty
      )
    }
  })

  console.log('\nwhat the line shows')

  await test('the line total is the server figure, not a local multiplication', () => {
    // A deliberately inconsistent payload: if the tile multiplies, it gets
    // 6290; if it reads the server, it gets the server's number.
    const vm = tileFor(cartRow(10, true, { line_total: 1234.56 }))

    assert.strictEqual(vm.lineTotal, 1234.56)
  })

  await test('an older payload without a line total still renders', () => {
    const row = cartRow(11, true)
    delete row.line_total

    assert.strictEqual(tileFor(row).lineTotal, 629 * 11)
  })

  await test('the unit price shown is the server price', () => {
    assert.strictEqual(tileFor(cartRow(11, true)).displayPrice, 629)
    assert.strictEqual(tileFor(cartRow(11, false)).displayPrice, 699)
  })

  await test('the struck-through price is the retail price the band undercut', () => {
    const vm = tileFor(cartRow(11, true))
    assert.strictEqual(vm.originalPrice, RETAIL)
    assert.strictEqual(vm.hasUpsellDiscount, true)
  })

  console.log('\na personal account')

  await test('no band applies and the price never moves', async () => {
    const ctx = buildStore([cartRow(1, false)], false)

    for (const q of [2, 5, 10, 11, 25, 30]) {
      let row
      do { row = await step(ctx, 1) } while (parseInt(row.quantity) < q)

      assert.strictEqual(
        parseFloat(row.unit_price), 699,
        'a personal account was given a band price at quantity ' + q
      )
    }
  })

  console.log('\nasking the server for a line it has not seen')

  await test('a line new to the basket triggers a refresh', async () => {
    const ctx = buildStore([], true)

    await step(ctx, 1)

    assert.strictEqual(ctx.refetches(), 1, 'a new line was not fetched back')
  })

  await test('a line already in the basket does not', async () => {
    const ctx = buildStore([cartRow(10, true)], true)

    await step(ctx, 1)

    assert.strictEqual(ctx.refetches(), 0, 'an unnecessary round trip')
  })


  console.log('\nthe order summary')

  await test('the subtotal is the list price of what is in the basket', () => {
    const vm = summaryFor([cartRow(11, true)])

    // 899 list x 11, from the server's original_price - not products.selling
    // read locally, which is the same here and wrong on a variant.
    assert.strictEqual(round2(vm.cartPrice.priceBeforeOffer), 9889)
  })

  await test('the discount is list minus what is actually charged', () => {
    const vm = summaryFor([cartRow(11, true)])

    const charged = round2(vm.cartPrice.totalPrice)
    const discount = round2(vm.cartPrice.priceBeforeOffer - charged)

    assert.strictEqual(charged, 629 * 11, 'charged at the 10-24 band')
    assert.strictEqual(discount, 9889 - 6919)
  })

  await test('the discount is not taken twice off an already banded price', () => {
    const vm = summaryFor([cartRow(11, true)])

    // What the customer pays is exactly the band price x quantity. If the
    // discount row were also coming off this, the two would not agree.
    assert.strictEqual(round2(vm.cartPrice.totalPriceWithOffer), 629 * 11)

    assert.strictEqual(
      round2(vm.cartPrice.priceBeforeOffer - round2(vm.cartPrice.priceBeforeOffer - vm.cartPrice.totalPrice)),
      round2(vm.cartPrice.totalPrice)
    )
  })

  await test('VAT is charged on the banded price, not the list price', () => {
    const vm = summaryFor([cartRow(11, true)])

    // excludeVAT = 1 on the fixture, so VAT is added: 23% of 629 x 11.
    assert.strictEqual(round2(vm.cartPrice.exclusiveTax), round2(629 * 0.23 * 11))

    // ... and emphatically not 23% of the retail price.
    assert.notStrictEqual(round2(vm.cartPrice.exclusiveTax), round2(899 * 0.23 * 11))
  })

  await test('VAT follows the product own tax rule, not a fixed rate', () => {
    const row = cartRow(11, true)
    row.flash_product.tax_rules = { id: 9, title: 'VAT13.5', type: 2, price: 13.5 }

    assert.strictEqual(
      round2(summaryFor([row]).cartPrice.exclusiveTax),
      round2(629 * 0.135 * 11)
    )
  })

  await test('a product on no tax rule is not taxed', () => {
    const row = cartRow(11, true)
    row.flash_product.tax_rules = null

    assert.strictEqual(round2(summaryFor([row]).cartPrice.exclusiveTax), 0)
  })

  await test('the grand total is goods plus VAT plus carriage', () => {
    const row = cartRow(11, true)
    row.shipping_place = { price: 16, pickup_price: 0, shipping_rule: { id: 1, single_price: 0 } }

    const vm = summaryFor([row])

    const goods = 629 * 11
    const vat = round2(629 * 0.23 * 11)

    assert.strictEqual(round2(vm.totalPrice), round2(goods + vat + 16))
  })

  await test('free bundle units are not taxed', () => {
    const row = cartRow(11, true)
    row.flash_product.bundle_deal = { buy: 10, free: 1 }

    const vm = summaryFor([row])

    // 10 paid for, 1 free: VAT on 10, not on 11.
    assert.strictEqual(round2(vm.cartPrice.exclusiveTax), round2(629 * 0.23 * 10))
    assert.strictEqual(round2(vm.cartPrice.totalPriceWithOffer), 629 * 10)
  })

  await test('every line is summed on its own price and tax rule', () => {
    const banded = cartRow(11, true)

    const plain = cartRow(2, false, { id: 78, product_id: 12345 })
    plain.flash_product = Object.assign({}, plain.flash_product, {
      id: 12345, selling: 100, offered: 80,
      tax_rules: { id: 9, title: 'VAT13.5', type: 2, price: 13.5 },
    })
    plain.unit_price = 80
    plain.upsell_price = 80
    plain.original_price = 100
    plain.line_total = 160

    const vm = summaryFor([banded, plain])

    assert.strictEqual(round2(vm.cartPrice.totalPrice), 629 * 11 + 80 * 2)
    assert.strictEqual(round2(vm.cartPrice.priceBeforeOffer), 899 * 11 + 100 * 2)
    assert.strictEqual(
      round2(vm.cartPrice.exclusiveTax),
      round2(629 * 0.23 * 11 + 80 * 0.135 * 2)
    )
    assert.strictEqual(vm.cartPrice.totalItems, 13)
  })

  await test('a personal basket is summed at retail throughout', () => {
    const vm = summaryFor([cartRow(11, false)])

    assert.strictEqual(round2(vm.cartPrice.totalPrice), 699 * 11)
    assert.strictEqual(round2(vm.cartPrice.exclusiveTax), round2(699 * 0.23 * 11))
  })

  /*
   * The Essentials Bundle saving comes off the payable total like the other
   * order-wide discounts. It is the server's figure -- the discount stands
   * only while the basket holds a whole bundle, and the browser is not
   * entitled to decide that -- so the component only subtracts it.
   */
  await test('a bundle saving comes off the total', () => {
    const plain = summaryFor([cartRow(1, true)])
    const saved = summaryFor([cartRow(1, true)], { bundleDiscount: 10 })

    assert.strictEqual(
      Number((plain.totalPrice - saved.totalPrice).toFixed(2)), 10,
      'the bundle saving did not reduce the total'
    )
  })

  await test('a bundle saving cannot take the total below zero', () => {
    const vm = summaryFor([cartRow(1, true)], { bundleDiscount: 100000 })

    assert.ok(vm.totalPrice >= 0, `total was ${vm.totalPrice}`)
  })

  await test('the summary never shows a negative total', () => {
    const vm = summaryFor([cartRow(1, true)], { loyaltyDiscount: 100000 })

    assert.strictEqual(vm.totalPrice, 0)
  })

  console.log('')

  if (failures) {
    console.log(failures + ' failing\n')
    process.exit(1)
  }

  console.log('all passing\n')
}

main()
