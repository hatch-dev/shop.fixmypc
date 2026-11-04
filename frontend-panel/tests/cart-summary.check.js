/*
 * The cart sidebar, on a basket that holds both VAT conventions.
 *
 * Run with: npm run check:cart-summary
 *
 * Since 2026-11-03 the summary states the goods on both sides of VAT, with
 * the VAT already inside prices and the VAT added on separate rows:
 *
 *     2,752.89   Subtotal (excl. VAT)     (2,022.00 net + 730.89 net of 899)
 *       168.11   VAT already included     (the Samsung's, inside its 899.00)
 *     + 465.06   VAT added                (the iPhone's: 155.02 x 3)
 *       633.17   Total VAT                (the two, counted once)
 *     3,386.06   Subtotal (incl. VAT)     = excl. VAT + Total VAT
 *     = 3,386.06 Final amount payable     (no shipping, no discounts)
 *
 * The conventions are reconciled by WHERE the VAT is added, never by applying
 * a rate to a mixed total: the Samsung's tax is inside its 899.00 and nothing
 * is added for it. Applying 23% to the mixed 2,921.00 instead gives 671.83 -
 * it taxes the Samsung twice.
 *
 * The saving is 193.25, incl. VAT on both lines (92.25 + 101.00). It used to
 * be shown as 176.00 - the iPhone's NET 75.00 added to the Samsung's gross
 * 101.00 - while the amount charged fell by 193.25.
 *
 * Every line figure is per unit, rounded to the cent, times the units paid
 * for (Pricing::lineVat) - the rule the order charges by.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const { withI18n, installI18n } = require('./i18n-en')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

function loadMixin(name) {
  const file = path.join(ROOT, 'mixin', `${name}.js`)

  const code = babel.transformSync(fs.readFileSync(file, 'utf8'), {
    filename: `${name}.js`,
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const module = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, () => ({}))

  return module.exports.default || module.exports
}

function loadSfc(file) {
  const parsed = compiler.parseComponent(fs.readFileSync(file, 'utf8'))
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
      return { mapGetters: spread, mapActions: spread, mapState: spread, mapMutations: spread }
    }
    /*
     * productPriceHelper is loaded for real rather than stubbed: it holds
     * cartVatBasis, cartVatDetail, vatExplainer and formatVatRate, which
     * is most of what the card and the summary's popovers are made of. Stub it
     * and the checks below pass against a component that has no VAT logic at
     * all. It imports nothing itself, so nothing else has to be resolved.
     */
    if (request.indexOf('mixin/productPriceHelper') !== -1) {
      return { default: loadMixin('productPriceHelper'), __esModule: true }
    }
    /*
     * The summary's arithmetic lives in checkoutTotals, so it is loaded for
     * real too: stubbed, every figure below would be undefined.
     */
    if (request.indexOf('mixin/checkoutTotals') !== -1) {
      return loadMixin('checkoutTotals')
    }
    return {}
  }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return module.exports.default || module.exports
}

const summary = loadSfc(path.join(ROOT, 'components/CheckoutRight.vue'))
const tile = loadSfc(path.join(ROOT, 'components/CartProductTile.vue'))

/*
 * A component with its real computeds wired up, so the assertions below run
 * the arithmetic the page runs rather than a copy of it kept in step by hand.
 */
function vmFor (component, seed) {
  const vm = withI18n(Object.create(null))

  Object.assign(vm, {
    // The two util-mixin helpers these components borrow.
    priceType: { FLAT: 1, PERCENT: 2 },
    formatPrice: (value) => Number(Number(value || 0).toFixed(2)),
    priceFormat: (position, icon, value) => `${icon}${Number(value || 0).toFixed(2)}`,
    currencyIcon: '€',
    currencyPosition: 'left',
    setting: {},
    sellPriceCalc: (product) => parseFloat(product?.selling || 0),
    currentInventoryPriceCalc: (inventory, product) =>
      parseFloat(product?.offered || product?.selling || 0),
    $emit: () => {},
    // getters as well as state: the summary reads the flash discount from
    // cart/flashDiscountAmount, which is derived rather than stored.
    $store: {
      state: { cart: {} },
      getters: { 'cart/flashDiscountAmount': 0 },
    },
  }, seed)

  /*
   * Later sources win, because that is Vue's own precedence: a component's
   * computed overrides one of the same name on a mixin it uses.
   *
   * Getting this backwards is not academic here. productPriceHelper and
   * CheckoutRight both define `vatRateLabel` — the mixin's reads a product,
   * the summary's reads the rates in the basket — so a mixin-first harness
   * quietly tests the wrong one and reports "no single rate" for a basket that
   * has exactly one.
   */
  const seeded = new Set(Object.keys(vm))
  const methods = {}
  const computed = {}

  for (const source of [...(component.mixins || []), component]) {
    Object.assign(methods, source.methods || {})
    Object.assign(computed, source.computed || {})
  }

  for (const [name, fn] of Object.entries(methods)) {
    if (!seeded.has(name)) vm[name] = fn.bind(vm)
  }

  for (const [name, fn] of Object.entries(computed)) {
    if (seeded.has(name) || typeof fn !== 'function') continue
    Object.defineProperty(vm, name, { get: fn.bind(vm), configurable: true })
  }

  return vm
}

function summaryVm(rows, seed = {}) {
  return vmFor(summary, Object.assign({
    checkedProduct: rows,
    hasShipping: true,
    voucherResult: null,
    flashDiscount: 0,
    loyaltyDiscount: 0,
    pointsDiscount: 0,
  }, seed))
}

/*
 * The basket from the brief, as CartsController::repriceForCustomer answers
 * with it. Every figure here is one the server sends; nothing is derived.
 */
const IPHONE = {
  id: 1,
  quantity: 3,
  vat_rate: 23,
  vat_inclusive: false,          // quoted net; VAT is added
  original_price: 699,
  unit_price: 674,
  unit_ex_vat: 674, unit_vat: 155.02, unit_inc_vat: 829.02,
  line_ex_vat: 2022, line_vat: 465.06, line_inc_vat: 2487.06,
  line_list: 2097, line_list_ex_vat: 2097, line_list_inc_vat: 2579.31,  // 699 x 3
  // the list price on the same per-unit rule: 699 + 160.77 VAT = 859.77
  unit_list_ex_vat: 699, unit_list_vat: 160.77, unit_list_inc_vat: 859.77,
  line_goods: 2022, free_units: 0, payable_quantity: 3, vat_flat: 0,
  flash_product: { title: 'Apple iPhone 15 Pro' },
}

const SAMSUNG = {
  id: 2,
  quantity: 1,
  vat_rate: 23,
  vat_inclusive: true,           // quoted gross; VAT is inside
  original_price: 1000,
  unit_price: 899,
  unit_ex_vat: 730.89, unit_vat: 168.11, unit_inc_vat: 899,
  line_ex_vat: 730.89, line_vat: 168.11, line_inc_vat: 899,
  line_list: 1000, line_list_ex_vat: 813.01, line_list_inc_vat: 1000,  // 1,000 gross -> 813.01 net
  unit_list_ex_vat: 813.01, unit_list_vat: 186.99, unit_list_inc_vat: 1000,
  line_goods: 899, free_units: 0, payable_quantity: 1, vat_flat: 0,
  flash_product: { title: 'Samsung Galaxy A05s' },
}

const BASKET = [IPHONE, SAMSUNG]

function savings(rows) {
  return summary.computed.savingsDetails.call(withI18n({ checkedProduct: rows }))
}

let failures = 0

function check(name, fn) {
  try {
    fn()
    console.log(`  ok   ${name}`)
  } catch (err) {
    failures++
    console.log(`  FAIL ${name}`)
    console.log(`       ${err.message}`)
  }
}

function close(actual, expected, what) {
  assert.ok(
    Math.abs(actual - expected) < 0.005,
    `${what}: expected ${expected.toFixed(2)}, got ${Number(actual).toFixed(2)}`
  )
}

console.log('\nsavings, per line, stated incl. VAT on every line')

/*
 * One basis for every line since 2026-11-03. The saving used to be added up
 * "as displayed" - the iPhone's NET 75.00 beside the Samsung's gross 101.00 -
 * and the row said 176.00 while the amount charged fell by 193.25. Both ends
 * are now inc-VAT for every line (the list price split on the same per-unit
 * rule as the charge), so the row is the 193.25 the customer actually saves,
 * which is also what the order confirmation states (discount_inc_vat).
 */
check('an ex-VAT line states its saving incl. the VAT it saves', () => {
  const row = savings([IPHONE])[0]

  close(row.unitFrom, 859.77, 'list price incl. VAT (699 + 160.77)')
  close(row.unitTo, 829.02, 'charged price incl. VAT (674 + 155.02)')
  close(row.priceSaving, 92.25, 'gross saving (30.75 x 3)')
  close(row.freeValue, 0, 'no free units')
})

check('an inc-VAT line saves exactly what it says', () => {
  const row = savings([SAMSUNG])[0]

  close(row.priceSaving, 101, 'gross saving')
  close(row.freeValue, 0, 'no free units')
})

check('a line with no saving is left out entirely', () => {
  const full = Object.assign({}, SAMSUNG, {
    original_price: 899, line_list_inc_vat: 899, unit_list_inc_vat: 899,
  })

  assert.strictEqual(savings([full]).length, 0)
})

check('free bundle units are a saving of their own, counted once', () => {
  // 3 at 100.00 inc-VAT, 1 free: charged 200.00, list 300.00.
  const bundled = Object.assign({}, SAMSUNG, {
    id: 30, quantity: 3, free_units: 1, payable_quantity: 2,
    unit_price: 100, original_price: 100,
    unit_ex_vat: 81.3, unit_vat: 18.7, unit_inc_vat: 100,
    line_ex_vat: 162.6, line_vat: 37.4, line_inc_vat: 200, line_goods: 200,
    unit_list_ex_vat: 81.3, unit_list_vat: 18.7, unit_list_inc_vat: 100,
    line_list: 300, line_list_ex_vat: 243.9, line_list_inc_vat: 300,
  })
  const row = savings([bundled])[0]

  close(row.priceSaving, 0, 'no price reduction')
  close(row.freeValue, 100, 'one unit given away')
  close(summaryVm([bundled]).productsDiscount, 100, 'the saving is the free unit, once')
})

console.log('\nthe worked example from the brief')

check('the saving is 193.25 off the final total, and the row says 193.25', () => {
  const lines = savings(BASKET).reduce((sum, row) => sum + row.priceSaving + row.freeValue, 0)

  close(lines, 193.25, 'the popover lines (92.25 + 101.00)')
  close(summaryVm(BASKET).productsDiscount, 193.25, 'the You saved figure')
})

check('the gross ladder closes: list - saving = charged, incl. VAT', () => {
  const vm = summaryVm(BASKET)

  close(vm.goods.listIncVat, 3579.31, 'list incl. VAT')
  close(vm.goods.incVat, 3386.06, 'charged incl. VAT')
  close(vm.goods.listIncVat - vm.productsDiscount, vm.goods.incVat, 'list - saving = charged')
})

check('ex-VAT + VAT = inc-VAT across both conventions', () => {
  const ex = BASKET.reduce((s, r) => s + r.line_ex_vat, 0)
  const vat = BASKET.reduce((s, r) => s + r.line_vat, 0)
  const inc = BASKET.reduce((s, r) => s + r.line_inc_vat, 0)

  close(ex, 2752.89, 'Ex-VAT')
  close(vat, 633.17, 'VAT')
  close(inc, 3386.06, 'Inc-VAT')
  close(ex + vat, inc, 'ex + VAT = inc')
})

console.log('\nthe summary as it is rendered, end to end')

/*
 * The checks above measure the fixture. These run the component: every figure
 * is read off the computed the template actually binds to, so a row that stops
 * agreeing with the rest of the summary fails here rather than on a customer's
 * screen.
 */
check('every row of the summary matches the brief', () => {
  const vm = summaryVm(BASKET)
  const included = vm.productGroups.find(g => g.key === 'included')
  const added = vm.productGroups.find(g => g.key === 'added')

  // ② the Samsung: 899.00 incl. VAT, of which 168.11 VAT, 730.89 net
  close(included.inc, 899, 'VAT included group: subtotal incl. VAT')
  close(included.vat, 168.11, 'VAT included in these prices')
  close(included.ex, 730.89, 'VAT included group: subtotal excl. VAT')

  // ③ the iPhone: 2,022.00 + 465.06 VAT (155.02 x 3) = 2,487.06
  close(added.ex, 2022, 'VAT added group: subtotal excl. VAT')
  close(added.vat, 465.06, 'VAT added')
  close(added.inc, 2487.06, 'VAT added group: subtotal incl. VAT')

  close(vm.productsCharge, 3386.06, 'Total charges for all products')
  close(vm.vatTotal, 633.17, 'Includes 633.17 VAT')
  close(vm.vatIncluded, 168.11, '... 168.11 included')
  close(vm.cartPrice.exclusiveTax, 465.06, '... + 465.06 added')
  close(vm.productsDiscount, 193.25, 'You saved, incl. VAT')
  close(vm.cartPrice.totalPriceWithOffer, 2921, 'goods before VAT is added (the flash base)')
  close(vm.cartPrice.shippingPrice, 0, 'Shipping')
  close(vm.totalPrice, 3386.06, 'Final amount payable')
})

check('the summary is one sum the customer can follow top to bottom', () => {
  const vm = summaryVm(BASKET)
  const groups = vm.productGroups.reduce((s, g) => s + g.inc, 0)

  // 899.00 + 2,487.06 = 3,386.06: the groups make up Total charges
  close(groups, vm.productsCharge, 'the groups add up to Total charges')
  close(vm.goods.exVat + vm.vatTotal, vm.productsCharge, 'excl. VAT + all VAT = Total charges')
  close(vm.vatIncluded + vm.cartPrice.exclusiveTax, vm.vatTotal, 'included + added = the VAT stated')

  // 3,386.06 + 0 shipping - 0 discounts = 3,386.06
  close(vm.productsCharge + vm.cartPrice.shippingPrice - vm.totals.discounts, vm.totalPrice,
    'Total charges + Shipping - Discounts = Final amount payable')
})

check('VAT is added for the ex-VAT line and NOT for the inc-VAT one', () => {
  /*
   * The rule the whole summary rests on. The Samsung is quoted gross, so its
   * tax is already inside the 899.00 that reached Products Total; adding
   * anything for it would charge that tax twice.
   */
  close(summaryVm([IPHONE]).cartPrice.exclusiveTax, 465.06, 'ex-VAT line adds VAT')
  close(summaryVm([SAMSUNG]).cartPrice.exclusiveTax, 0, 'inc-VAT line adds nothing')

  // And the basket is the sum of those two, not of both lines' VAT.
  close(summaryVm(BASKET).cartPrice.exclusiveTax, 465.06, 'the basket')
})

check('an all inc-VAT basket adds no VAT at all, and still totals correctly', () => {
  const vm = summaryVm([SAMSUNG])

  close(vm.cartPrice.exclusiveTax, 0, 'nothing is added')
  close(vm.totalPrice, 899, 'the total is the price as displayed')
})

check('the VAT row is the tax ADDED, not the tax in the order', () => {
  const vm = summaryVm(BASKET)

  /*
   * These two must not be confused: €465.06 moves the amount charged, €633.17
   * is what the order actually carries once the Samsung's own tax is counted.
   * The row shows the first; the popover has to carry the second, because an
   * invoice needs it.
   */
  close(vm.cartPrice.exclusiveTax, 465.06, 'VAT added')
  close(vm.cartPrice.vat, 633.17, 'VAT contained in the order')

  const labels = vm.vatExplainerRows.map(r => r.label)

  assert.ok(labels.some(l => l.indexOf('VAT added') === 0), 'the popover names the added VAT')
  assert.ok(labels.includes('Total VAT in this order'), 'and the total VAT')

  const total = vm.vatExplainerRows.find(r => r.label === 'Total VAT in this order')

  assert.strictEqual(total.value, '€633.17')
})

check('each card states its line on the basis that line is priced on', () => {
  /*
   * The card is not the place the two conventions get reconciled. It shows
   * "€674.00 x 3 = €2,022.00 Ex-VAT", which the customer can check against the
   * price directly above it. Normalising is the summary's job, and the summary
   * is where VAT is stated.
   */
  const iphone = vmFor(tile, { cart: IPHONE })
  const samsung = vmFor(tile, { cart: SAMSUNG })

  close(iphone.lineTotal, 2022, 'iPhone line total, net as quoted')
  assert.strictEqual(iphone.cartVatBasis.short, 'Ex-VAT')

  close(samsung.lineTotal, 899, 'Samsung line total, gross as quoted')
  assert.strictEqual(samsung.cartVatBasis.short, 'Inc-VAT')

  // The multiplication on the card is the unit price it shows, times quantity.
  close(iphone.displayPrice * IPHONE.quantity, iphone.lineTotal, 'unit x qty = line')
  close(samsung.displayPrice * SAMSUNG.quantity, samsung.lineTotal, 'unit x qty = line')
})

check('an ex-VAT card states its VAT in full, and adds it once', () => {
  const v = vmFor(tile, { cart: IPHONE }).vatLine

  assert.strictEqual(v.kind, 'added')
  assert.strictEqual(v.badge, 'Ex-VAT')

  // Line Total: "€674.00 x 3" over "€2,022.00 Ex-VAT", then "€2,487.06 Inc-VAT"
  close(v.unitQuoted, 674, 'the price as quoted, net')
  close(v.lineQuoted, 2022, 'the line as quoted, net')
  assert.strictEqual(v.short, 'Ex-VAT')
  close(v.unitEx, 674, 'unit price excl. VAT')
  assert.strictEqual(v.rateLabel, '23%')
  close(v.unitVat, 155.02, 'VAT per unit, added')
  close(v.unitInc, 829.02, 'unit price incl. VAT')
  assert.strictEqual(v.quantity, 3)
  close(v.lineInc, 2487.06, 'total incl. VAT')

  // Everything on the card multiplies out without the customer doing it.
  close(v.unitEx + v.unitVat, v.unitInc, 'excl. + VAT = incl.')
  close(v.unitInc * v.paid, v.lineInc, 'unit incl. x quantity = total')
})

check('an inc-VAT card splits its price and never adds VAT to it', () => {
  const v = vmFor(tile, { cart: SAMSUNG }).vatLine

  assert.strictEqual(v.kind, 'included')
  assert.strictEqual(v.badge, 'Inc-VAT')
  close(v.lineQuoted, 899, 'the line as quoted, gross')
  close(v.lineEx, 730.89, 'its Ex-VAT counterpart')
  close(v.unitEx, 730.89, 'the VAT-exclusive part')
  close(v.unitVat, 168.11, 'the VAT already inside')
  close(v.unitInc, 899, 'the price as quoted - unchanged')
  close(v.lineInc, 899, 'the total is the price, not price + VAT')
})

check('a zero-rated card says No VAT', () => {
  const zero = Object.assign({}, SAMSUNG, {
    vat_rate: 0, unit_vat: 0, unit_ex_vat: 899, line_vat: 0, line_ex_vat: 899,
  })
  const v = vmFor(tile, { cart: zero }).vatLine

  assert.strictEqual(v.kind, 'none')
  assert.strictEqual(v.badge, 'No VAT')
  assert.strictEqual(v.rateLabel, '0%')
})

check('a genuine zero price stays zero on the card', () => {
  // A 100% clearance: the server says 0.00 and means it.
  const free = Object.assign({}, SAMSUNG, {
    unit_price: 0, upsell_price: 0, unit_ex_vat: 0, unit_vat: 0, unit_inc_vat: 0,
    line_total: 0, line_ex_vat: 0, line_vat: 0, line_inc_vat: 0,
    flash_product: { title: 'Free', selling: 1000, offered: 899 },
  })
  const card = vmFor(tile, { cart: free })

  assert.strictEqual(card.hasUpsellPrice, true, 'the server answered')
  close(card.displayPrice, 0, 'not replaced by the old selling price')
})

/*
 * The "Original Products" LIST is gone from the summary on purpose -- it
 * restated the Products Total five lines above it, and the header comment in
 * CheckoutRight.vue says exactly that. These three cases were written against
 * that list and went on asserting a computed that no longer exists, so all
 * three reported failures against correct behaviour.
 *
 * What they were guarding survives: the base the saving is measured against is
 * still built per line, still from the server's line_list, and still falls
 * back to unit x quantity for a payload that predates it. That is
 * originalTotal, so that is what they measure now.
 */
console.log('\nlist prices, as the saving is measured against')

/*
 * The saving's base is the list price incl. VAT, per line, from the server
 * (line_list_inc_vat). It used to be line_list - each product's own
 * convention, 2,097 net + 1,000 gross = 3,097 - which is not a figure that
 * can be compared with an inc-VAT charge.
 */
check('every line is counted once, at its list total incl. VAT', () => {
  // 859.77 x 3 + 1,000.00
  close(summaryVm(BASKET).goods.listIncVat, 3579.31, 'list total incl. VAT')
})

check('the base is the line list, not the unit price', () => {
  const vm = summaryVm([IPHONE])

  close(vm.goods.listIncVat, 2579.31, 'three at the list price incl. VAT')
  assert.ok(vm.goods.listIncVat !== 859.77, 'the unit price is not the line')
})

check('a payload with no list figure shows no saving rather than inventing one', () => {
  const older = Object.assign({}, IPHONE)
  delete older.line_list_inc_vat
  delete older.unit_list_inc_vat

  close(summaryVm([older]).productsDiscount, 0, 'no saving')
})

check('the base totals as displayed, and VAT is not applied to that total', () => {
  const vm = summaryVm(BASKET)


  /*
   * The total is addable because VAT is added afterwards, per line, and only
   * where it is missing. Applying the rate to this figure instead would tax
   * the Samsung's already-taxed 1,000.00 a second time.
   */
  const naive = vm.cartPrice.totalPriceWithOffer * 0.23

  assert.ok(Math.abs(naive - vm.cartPrice.exclusiveTax) > 1,
    'the rate must not be applied to the mixed total')

  close(vm.cartPrice.exclusiveTax, 465.06, 'VAT added, ex-VAT lines only')
})

/*
 * Since 2026-11-02 the summary opens with the three VAT groups and closes them
 * on "Total charges for all products". That figure has to be the same sum
 * totalPrice starts from, or the rows and the amount charged are two routes to
 * two numbers.
 */
check('total charges are the cards plus the VAT added, and hold all the VAT', () => {
  const vm = summaryVm(BASKET)

  close(vm.productsCharge, 3386.06, 'Total charges for all products')
  close(vm.productsCharge,
    vm.cartPrice.totalPriceWithOffer + vm.cartPrice.exclusiveTax, 'the rows no longer add up')
  close(vm.vatTotal, 633.17, 'the VAT stated under it')
})

console.log('\nwhat the info icons open')

/*
 * The detail moved out of the cards and out of the two disclosures under the
 * button, and into these. That only works if what they open is complete — so
 * these pin the figures, and pin that they are the SAME figures as the rows
 * beside them rather than a second calculation kept in step by hand.
 */
check('an ex-VAT card explains its own price', () => {
  const rows = vmFor(tile, { cart: IPHONE }).vatExplainer.rows

  // unit price excl. VAT, rate, VAT added per unit, unit price incl. VAT
  assert.deepStrictEqual(rows, [
    { label: 'Current price', value: '€674.00' },
    { label: 'VAT rate', value: '23%' },
    { label: 'VAT added per unit', value: '€155.02' },
    { label: 'Inc-VAT equivalent', value: '€829.02' },
  ])
})

check('an inc-VAT card explains its own price', () => {
  const rows = vmFor(tile, { cart: SAMSUNG }).vatExplainer.rows

  assert.deepStrictEqual(rows, [
    { label: 'Current price', value: '€899.00' },
    { label: 'VAT rate', value: '23%' },
    { label: 'VAT included', value: '€168.11' },
    { label: 'Ex-VAT value', value: '€730.89' },
  ])
})

check('a zero-rated card opens nothing, because it has nothing to explain', () => {
  const zeroRated = Object.assign({}, SAMSUNG, { vat_rate: 0, unit_vat: 0 })

  assert.strictEqual(vmFor(tile, { cart: zeroRated }).vatExplainer, null)
})

check('the VAT icon opens both figures, included and added, rate by rate', () => {
  const vm = summaryVm(BASKET)

  assert.deepStrictEqual(vm.vatExplainerRows, [
    { label: 'VAT included in prices (23%)', value: '€168.11' },
    { label: 'VAT added (23%)', value: '€465.06' },
    { label: 'Total VAT in this order', value: '€633.17', strong: true },
    { label: 'Net Ex-VAT', value: '€2752.89' },
    { label: 'Total Inc-VAT', value: '€3386.06' },
  ])

  // The row it hangs off shows the total of the two - one VAT row, all of it.
  close(vm.vatTotal, 633.17, 'the VAT row')
})

check('an all ex-VAT basket does not claim any VAT was already included', () => {
  const vm = summaryVm([IPHONE])

  assert.ok(!vm.vatExplainerRows.some(r => r.label.indexOf('VAT included') === 0))
  assert.deepStrictEqual(vm.productGroups.map(g => g.key), ['added'], 'and no VAT-included group')
  close(vm.goods.vatIncluded, 0, 'nothing is inside the price')
})

check('a second rate stops a single percentage being printed on the row', () => {
  const reduced = Object.assign({}, SAMSUNG, {
    id: 3, vat_rate: 13.5,
    line_ex_vat: 100, line_vat: 13.5, line_inc_vat: 113.5, line_list_inc_vat: 113.5,
  })

  const vm = summaryVm([IPHONE, reduced])

  // 23% and 13.5% in one basket: no one percentage describes it.
  assert.strictEqual(vm.vatRateLabel, null)
  assert.deepStrictEqual(vm.vatExplainerRows.slice(0, 2).map(r => r.label),
    ['VAT included in prices (13.5%)', 'VAT added (23%)'], 'each rate states its own VAT')
  assert.ok(vm.vatExplainerNote.length > 0, 'and the popover explains the rule')
})

console.log('\nVAT details')

check('one row per rate, highest first', () => {
  const reduced = Object.assign({}, IPHONE, {
    id: 31, vat_rate: 13.5, line_ex_vat: 100, line_vat: 13.5, line_inc_vat: 113.5,
  })

  const rows = summaryVm([reduced, IPHONE]).vatExplainerRows

  assert.deepStrictEqual(rows.slice(0, 2).map(r => r.label), ['VAT added (23%)', 'VAT added (13.5%)'])
})

check('a rate carrying no goods is not listed', () => {
  const zeroRated = Object.assign({}, SAMSUNG, {
    id: 32, vat_rate: 0, line_vat: 0, unit_vat: 0, line_ex_vat: 899,
  })

  const rows = summaryVm([zeroRated, IPHONE]).vatExplainerRows

  assert.deepStrictEqual(rows.filter(r => /VAT (added|included)/.test(r.label)).map(r => r.label),
    ['VAT added (23%)'])
})

check('a flat-amount rule is tax, not "no VAT"', () => {
  // A EUR 5.00-per-unit rule: rate 0, but the order adds it (Pricing::unitTax).
  const flat = Object.assign({}, IPHONE, {
    id: 33, quantity: 2, vat_rate: 0, vat_flat: 5,
    unit_ex_vat: 100, unit_vat: 5, unit_inc_vat: 105,
    line_ex_vat: 200, line_vat: 10, line_inc_vat: 210, line_goods: 200,
  })
  const vm = summaryVm([flat])

  assert.deepStrictEqual(vm.productGroups.map(g => g.key), ['added'])
  close(vm.goods.vatAdded, 10, 'VAT added: 5.00 x 2')
  close(vm.totalPrice, 210, 'and it is charged')
  assert.strictEqual(vm.productGroups[0].lines[0].rateLabel, 'flat €5.00')
})

console.log('\na basket that lost its VAT split')

check('a row with no VAT fields is flagged, not reported as zero VAT', () => {
  /*
   * The reported bug. A line rehydrated from localStorage by an older build:
   * price and quantity survive, the split does not. Every other figure then
   * looks right and only VAT reads zero, which is the worst possible way for
   * this to fail — on the last screen before the customer commits.
   */
  const stale = {
    id: 9, quantity: 1, unit_price: 699, original_price: 699, line_list: 699,
    flash_product: { title: 'Apple iPhone 15 Pro' },
  }

  const vm = summaryVm([stale])

  // No figure is made up for a line the server has not priced: it counts for
  // nothing until it is, and the summary shows a dash in the meantime.
  close(vm.cartPrice.totalPriceWithOffer, 0, 'no price is invented for it')
  assert.strictEqual(vm.cartPrice.staleLines, 1, 'and the missing split is counted')
  assert.strictEqual(vm.hasStaleLines, true, 'so the summary declines to state VAT')
})

check('a genuinely zero-rated line is NOT treated as stale', () => {
  /*
   * vat_rate 0 is a real answer — a margin-scheme or zero-rated product — and
   * its VAT really is zero. Only a MISSING rate means the row never got one.
   */
  const zeroRated = {
    id: 10, quantity: 1, vat_rate: 0, vat_inclusive: false,
    unit_price: 699, original_price: 699, line_list: 699,
    line_ex_vat: 699, line_vat: 0, line_inc_vat: 699, line_list_inc_vat: 699,
    flash_product: { title: 'Zero rated' },
  }

  const vm = summaryVm([zeroRated])

  assert.strictEqual(vm.cartPrice.staleLines, 0)
  assert.strictEqual(vm.hasStaleLines, false)
  close(vm.cartPrice.exclusiveTax, 0, 'and its VAT really is zero')
})

check('a complete basket is never flagged', () => {
  assert.strictEqual(summaryVm(BASKET).hasStaleLines, false)
})

/*
 * The three-group summary, on the reference basket from the 2026-11-02 brief.
 *
 * Every line figure below is what Pricing::vatBreakdown returns for that line
 * (checked by running it: 1,000 inc-VAT at 23% -> 813.01 + 186.99), so these
 * pin the component against the server's own split rather than against
 * numbers worked out by hand.
 *
 *   ① Products 1, 2   0%                      300.00
 *   ② Products 3, 4   23%, VAT inside         1,500.00  (VAT 280.49)
 *   ③ Product 5       23%, VAT added          200.00 + 46.00 = 246.00
 *   Total charges                             2,046.00  (VAT 326.49)
 *   + shipping 32.00 - voucher 50.00 - loyalty 20.00 = 2,008.00
 *   - wallet 500.00                           = 1,508.00 left to pay
 */
console.log('\nthe three VAT groups (reference basket)')

function line(id, title, price, rate, inclusive, split, extra) {
  return Object.assign({
    id,
    quantity: 1,
    vat_rate: rate,
    vat_inclusive: inclusive,
    original_price: price,
    unit_price: price,
    line_list: price,
    // one unit: the line IS one unit at quantity 1
    unit_ex_vat: split[0], unit_vat: split[1], unit_inc_vat: split[2],
    line_ex_vat: split[0], line_vat: split[1], line_inc_vat: split[2],
    line_list_inc_vat: split[2],
    flash_product: { title },
  }, extra || {})
}

const P1 = line(11, 'Product 1', 100, 0, false, [100, 0, 100])
const P2 = line(12, 'Product 2', 200, 0, false, [200, 0, 200])
const P3 = line(13, 'Product 3', 1000, 23, true, [813.01, 186.99, 1000])
const P4 = line(14, 'Product 4', 500, 23, true, [406.5, 93.5, 500])
const P5 = line(15, 'Product 5', 200, 23, false, [200, 46, 246])

// Deliberately out of order: the groups must not follow the basket's order.
const REFERENCE = [P5, P3, P1, P4, P2]

function groupOf(vm, key) {
  return vm.productGroups.find(g => g.key === key)
}

check('groups always come out without VAT, VAT included, VAT added', () => {
  assert.deepStrictEqual(summaryVm(REFERENCE).productGroups.map(g => g.key),
    ['none', 'included', 'added'])
})

check('① products without VAT: 300.00, no VAT', () => {
  const g = groupOf(summaryVm(REFERENCE), 'none')

  assert.deepStrictEqual(g.lines.map(l => l.title), ['Product 1', 'Product 2'])
  close(g.inc, 300, 'subtotal (without VAT)')
  close(g.vat, 0, 'VAT')
  assert.strictEqual(g.lines[0].rateLabel, '', 'a 0% line prints no rate')
})

check('② products with VAT included: 1,500.00 holding 280.49', () => {
  const g = groupOf(summaryVm(REFERENCE), 'included')

  assert.deepStrictEqual(g.lines.map(l => l.title), ['Product 3', 'Product 4'])
  close(g.lines[0].amount, 1000, 'Product 3 as priced')
  close(g.lines[0].vat, 186.99, 'Product 3 VAT included')
  close(g.lines[0].ex, 813.01, 'Product 3 excl. VAT')
  close(g.lines[1].vat, 93.5, 'Product 4 VAT included')
  assert.strictEqual(g.lines[0].rateLabel, '23%')
  close(g.inc, 1500, 'subtotal (including VAT)')
  close(g.vat, 280.49, 'VAT included in these prices')
})

check('③ products with VAT added: 200.00 + 46.00 = 246.00', () => {
  const g = groupOf(summaryVm(REFERENCE), 'added')

  close(g.lines[0].amount, 200, 'shown at its ex-VAT price')
  close(g.lines[0].vat, 46, 'VAT added')
  close(g.lines[0].inc, 246, 'incl. VAT')
  close(g.ex, 200, 'subtotal (excluding VAT)')
  close(g.vat, 46, 'VAT added')
  close(g.inc, 246, 'subtotal (including VAT)')
})

check('total charges 2,046.00 = the three groups = the existing sum', () => {
  const vm = summaryVm(REFERENCE)
  const groups = vm.productGroups.reduce((s, g) => s + g.inc, 0)

  close(groups, 2046, 'the groups')
  close(vm.productsCharge, 2046, 'Total charges for all products')
  close(vm.cartPrice.totalPriceWithOffer + vm.cartPrice.exclusiveTax, 2046,
    'what totalPrice has always started from')
})

check('total VAT 326.49 = 280.49 included + 46.00 added', () => {
  const vm = summaryVm(REFERENCE)

  close(vm.vatIncluded, 280.49, 'inside VAT-inclusive prices')
  close(vm.cartPrice.exclusiveTax, 46, 'added at checkout')
  close(vm.vatTotal, 326.49, 'total VAT')
})

check('VAT is never added to the VAT-included products', () => {
  // Only ③ moves the amount charged; ② on its own adds nothing at all.
  close(summaryVm([P3, P4]).cartPrice.exclusiveTax, 0, 'VAT added for ② alone')
  close(summaryVm([P3, P4]).totalPrice, 1500, '② is charged as priced')
})

check('shipping, voucher, loyalty and wallet give 2,008.00 and 1,508.00', () => {
  const vm = summaryVm(REFERENCE, {
    shippingQuote: { ok: true, price: 32 },
    voucherResult: { offered: 50 },
    loyaltyDiscount: 20,
    walletApplied: 500,
  })

  close(vm.cartPrice.shippingPrice, 32, 'shipping')
  assert.strictEqual(vm.isFreeShipping, false)
  close(vm.totalPrice, 2008, 'final amount payable')
  close(vm.productsCharge + vm.cartPrice.shippingPrice
    - vm.discountRows.reduce((s, r) => s + r.amount, 0), vm.totalPrice,
  'Total charges + Shipping - Discounts = Final amount payable')
  close(vm.amountLeftToPay, 1508, 'left to pay')
})

check('only the discounts actually applied get a row', () => {
  const vm = summaryVm(REFERENCE, {
    voucherResult: { offered: 50 },
    loyaltyDiscount: 20,
  })

  assert.deepStrictEqual(vm.discountRows.map(r => r.key), ['voucher', 'loyalty'])
  assert.deepStrictEqual(vm.discountRows.map(r => r.label), ['Voucher', 'Loyalty Discount'])
  assert.deepStrictEqual(summaryVm(REFERENCE).discountRows, [], 'no discount, no rows')
})

check('free shipping is free, and the total is the products total', () => {
  const vm = summaryVm(REFERENCE, { shippingQuote: { ok: true, price: 0 } })

  assert.strictEqual(vm.isFreeShipping, true)
  close(vm.totalPrice, 2046, 'final amount payable')
})

check('a 0% line is never "VAT added", whatever vat_inclusive says', () => {
  // vat_inclusive is false on a zero-rated line too; only the rate decides.
  const zero = line(21, 'Zero', 50, 0, false, [50, 0, 50])
  const zeroFlagged = line(22, 'Zero, inclusive flag', 50, 0, true, [50, 0, 50])

  assert.deepStrictEqual(summaryVm([zero, zeroFlagged]).productGroups.map(g => g.key), ['none'])
})

check('two rates in one group each get their own VAT row, highest first', () => {
  const reduced = line(23, 'Reduced rate', 113.5, 13.5, true, [100, 13.5, 113.5])
  const g = groupOf(summaryVm([reduced, P3]), 'included')

  assert.deepStrictEqual(g.rates.map(r => r.rateLabel), ['23%', '13.5%'])
  close(g.rates[0].vat, 186.99, 'VAT at 23%')
  close(g.rates[1].vat, 13.5, 'VAT at 13.5%')
  close(g.vat, 200.49, 'the group VAT is the sum of its rates')
  assert.deepStrictEqual(g.lines.map(l => l.rateLabel), ['13.5%', '23%'], 'each line names its own rate')
})

check('a quantity of two shows the unit price and the line total', () => {
  // per unit: 1,000 = 813.01 + 186.99, so the line is 1,626.02 + 373.98
  const two = line(24, 'Two of them', 1000, 23, true, [1626.02, 373.98, 2000], {
    quantity: 2, line_list: 2000, line_list_inc_vat: 2000,
    unit_ex_vat: 813.01, unit_vat: 186.99, unit_inc_vat: 1000,
  })
  const vm = summaryVm([two])
  const l = groupOf(vm, 'included').lines[0]

  close(l.amount, 2000, 'line total')
  close(l.unit, 1000, 'each')
  close(l.vat, 373.98, 'VAT on the whole line')
  close(vm.totalPrice, 2000, 'final amount payable')
})

check('a line with free bundle units shows no "each" that would not multiply out', () => {
  // 3 ordered, 1 free: charged for 2, so 1,000 x 3 is not the line.
  const bundled = line(25, 'Bundle', 1000, 23, true, [1626.02, 373.98, 2000], {
    quantity: 3, flash_product: { title: 'Bundle', bundle_deal: { buy: 3, free: 1 } },
  })
  const vm = summaryVm([bundled])

  assert.strictEqual(groupOf(vm, 'included').lines[0].unit, null)
  close(vm.productsCharge, 2000, 'total charges follow the units paid for')
})

check('a line still waiting for its prices is in no group', () => {
  const stale = { id: 26, quantity: 1, unit_price: 699, flash_product: { title: 'Waiting' } }
  const vm = summaryVm([stale, P1])

  assert.deepStrictEqual(vm.productGroups.map(g => g.key), ['none'])
  assert.strictEqual(vm.hasStaleLines, true, 'and the summary shows a dash')
})

check('an empty basket has no groups and totals nothing', () => {
  const vm = summaryVm([])

  assert.deepStrictEqual(vm.productGroups, [])
  close(vm.productsCharge, 0, 'total charges')
  close(vm.totalPrice, 0, 'final amount payable')
  assert.deepStrictEqual(vm.discountRows, [])
})

check('the group labels exist in all five languages', () => {
  const keys = [
    'totalCharges', 'includesVat', 'includesVatSplit', 'discounts', 'finalPayable',
    'finalPayableShort', 'savedOnList',
    'groups.none.title', 'groups.none.tag', 'groups.included.title', 'groups.included.tag',
    'groups.added.title', 'groups.added.tag', 'groups.items', 'groups.each',
    'groups.lineIncluded', 'groups.lineAdded', 'groups.peekIncluded', 'groups.peekAdded',
    'groups.subtotalWithoutVat', 'groups.subtotalIncVat', 'groups.subtotalExVat',
    'groups.vatIncludedInPrices', 'groups.vatAdded', 'groups.atRate',
    'flatRate', 'breakdown.subtotalEx', 'breakdown.vatIncluded',
    'breakdown.vatAdded', 'breakdown.totalVat',
    'breakdown.subtotalInc', 'explain.savingPriceLine', 'explain.savingFreeLine',
    'explain.savingsGrossNote', 'explain.vatInsideAt', 'explain.vatAddedAt', 'explain.vatOnceNote',
  ]

  // Built at run time (`groups.${key}.title`) or used through <i18n path>, so
  // the i18n-keys scan cannot see them: listed here instead.
  for (const code of ['en', 'fr', 'tr', 'hi', 'ar']) {
    const bundle = JSON.parse(fs.readFileSync(path.join(ROOT, `lang/${code}.json`), 'utf8'))

    for (const key of keys) {
      const value = `ui.checkoutSummary.${key}`.split('.').reduce((n, p) => (n ? n[p] : undefined), bundle)
      assert.strictEqual(typeof value, 'string', `${code}: ui.checkoutSummary.${key} is missing`)
    }
  }
})

console.log('\nthe mixed-basis notice')

function mixed(rows) {
  return summary.computed.hasMixedVatBasis.call(withI18n({ checkedProduct: rows }))
}

check('a basket holding both conventions says so', () => {
  assert.strictEqual(mixed(BASKET), true)
})

check('a basket of one convention stays quiet', () => {
  assert.strictEqual(mixed([IPHONE]), false)
  assert.strictEqual(mixed([SAMSUNG]), false)
  assert.strictEqual(mixed([SAMSUNG, SAMSUNG]), false)
})

check('a zero-rated line does not count as a second convention', () => {
  // It has no side to be on, so it cannot make a basket "mixed".
  const zeroRated = Object.assign({}, SAMSUNG, {
    id: 3, vat_rate: 0, vat_inclusive: false, line_vat: 0,
  })

  assert.strictEqual(mixed([SAMSUNG, zeroRated]), false)
})

check('an empty basket says nothing', () => {
  assert.strictEqual(mixed([]), false)
})

/*
 * The savings breakdown (2026-11-03): per selected line, list price and
 * discount before VAT, the discounted price, its VAT and the final price;
 * then the product discount, the VAT that is lower because it is charged on
 * the lower price, and their total - which is productsDiscount, the one
 * saving figure. Never a "discount on VAT": the VAT is the line's own.
 */
console.log('\nthe savings breakdown: product discount + lower VAT = total saving')

// The brief's Dell: 589.99 list, 501.49 charged, both ex-VAT, VAT 23% added.
const DELL = {
  id: 40, quantity: 1, vat_rate: 23, vat_inclusive: false, vat_flat: 0,
  original_price: 589.99, unit_price: 501.49,
  unit_ex_vat: 501.49, unit_vat: 115.34, unit_inc_vat: 616.83,
  line_ex_vat: 501.49, line_vat: 115.34, line_inc_vat: 616.83,
  unit_list_ex_vat: 589.99, unit_list_vat: 135.70, unit_list_inc_vat: 725.69,
  line_list: 589.99, line_list_ex_vat: 589.99, line_list_inc_vat: 725.69,
  line_goods: 501.49, free_units: 0, payable_quantity: 1,
  product: { title: 'DELL Latitude 3520' },
}

check('the Dell: 88.50 discount, 115.34 VAT, 616.83 final, 20.36 less VAT, 108.86 saved', () => {
  const vm = summaryVm([DELL])
  const row = vm.savingsDetails[0]
  close(row.listExVat, 589.99, 'original price (ex-VAT)')
  close(row.discountExVat, 88.50, 'product discount')
  close(row.exVat, 501.49, 'discounted price (ex-VAT)')
  close(row.vat, 115.34, 'VAT on the discounted price')
  close(row.incVat, 616.83, 'final price (inc-VAT)')
  assert.ok(/23/.test(vm.savingsVatLabel(row)), 'the VAT row names the rate: ' + vm.savingsVatLabel(row))
  close(vm.savingsSummary.discountExVat, 88.50, 'product discount')
  close(vm.savingsSummary.vatDifference, 20.36, 'VAT difference')
  close(vm.savingsSummary.savingIncVat, 108.86, 'total saving')
  close(vm.savingsSummary.savingIncVat, vm.productsDiscount, 'the total is the one saving figure')
})

check('only the selected lines count, and a line with no discount adds nothing', () => {
  const plain = Object.assign({}, SAMSUNG, {
    id: 41, original_price: 899, line_list_ex_vat: 730.89, line_list_inc_vat: 899, unit_list_inc_vat: 899,
  })
  // the page passes only the ticked lines (checkedProduct)
  assert.strictEqual(summaryVm([plain]).savingsDetails.length, 0, 'nothing saved, nothing shown')
  close(summaryVm([plain]).savingsSummary.savingIncVat, 0, 'no saving')
  const withPlain = summaryVm([DELL, plain]).savingsSummary
  close(withPlain.savingIncVat, 108.86, 'an undiscounted line beside the Dell changes nothing')
  close(withPlain.vatDifference, 20.36, 'nor its VAT difference')
})

check('mixed baskets: ex-VAT and inc-VAT lines add up exactly', () => {
  const vm = summaryVm([IPHONE, SAMSUNG, DELL])
  const s = vm.savingsSummary
  // iPhone 2097 -> 2022 ex (75.00), 92.25 incl.; Samsung 813.01 -> 730.89 ex (82.12), 101.00 incl.
  close(s.discountExVat, 75 + 82.12 + 88.50, 'product discounts before VAT')
  close(s.savingIncVat, 92.25 + 101 + 108.86, 'total saving incl. VAT')
  close(s.vatDifference, (92.25 - 75) + (101 - 82.12) + 20.36, 'VAT difference')
  close(s.discountExVat + s.vatDifference, s.savingIncVat, 'discount + lower VAT = total')
  close(s.savingIncVat, vm.productsDiscount, 'the same figure as before')
  vm.savingsDetails.forEach((row) => close(row.listExVat - row.discountExVat, row.exVat, row.title + ': list - discount = discounted'))
})

check('no VAT on a line: the saving is the discount, nothing more', () => {
  const margin = Object.assign({}, SAMSUNG, {
    id: 42, vat_rate: 0, vat_flat: 0, vat_inclusive: false,
    unit_ex_vat: 250, unit_vat: 0, unit_inc_vat: 250, line_ex_vat: 250, line_vat: 0, line_inc_vat: 250,
    unit_list_ex_vat: 300, unit_list_vat: 0, unit_list_inc_vat: 300, line_list_ex_vat: 300, line_list_inc_vat: 300,
  })
  const vm = summaryVm([margin])
  close(vm.savingsSummary.discountExVat, 50, 'discount')
  close(vm.savingsSummary.vatDifference, 0, 'no VAT difference')
  close(vm.savingsSummary.savingIncVat, 50, 'total')
  assert.ok(!/\d+%/.test(vm.savingsVatLabel(vm.savingsDetails[0])), 'no rate is claimed for it')
})

console.log('\nfinal amount payable: the group subtotals, added up')

check('the parts are the groups, in table order, and add up to Total product charges', () => {
  const margin = Object.assign({}, SAMSUNG, {
    id: 43, vat_rate: 0, vat_flat: 0, vat_inclusive: false,
    unit_ex_vat: 250, unit_vat: 0, unit_inc_vat: 250, line_ex_vat: 250, line_vat: 0, line_inc_vat: 250,
    unit_list_ex_vat: 250, unit_list_vat: 0, unit_list_inc_vat: 250, line_list_ex_vat: 250, line_list_inc_vat: 250,
  })
  const vm = summaryVm([IPHONE, SAMSUNG, DELL, margin])
  assert.deepStrictEqual(vm.finalParts.map(p => p.key), ['included', 'added', 'none'])
  const sum = vm.finalParts.reduce((s, p) => s + Math.round(p.amount * 100), 0) / 100
  close(sum, vm.productsCharge, 'the parts are Total product charges')
  assert.strictEqual(vm.finalSectionNumber, 4, 'three tables, then this section')

  const one = summaryVm([DELL])
  assert.deepStrictEqual(one.finalParts.map(p => p.key), ['added'])
  close(one.finalParts[0].amount, one.productsCharge, 'a single group is the whole charge')
  assert.strictEqual(one.finalSectionNumber, 2, 'one table, then this section')
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all cart summary checks passed\n')
