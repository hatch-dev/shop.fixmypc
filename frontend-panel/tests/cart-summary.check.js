/*
 * The cart sidebar, on a basket that holds both VAT conventions.
 *
 * Run with: npm run check:cart-summary
 *
 * The summary works in prices AS DISPLAYED, and adds VAT once at the end to
 * the lines that do not already carry it:
 *
 *     3,097.00   original, as displayed  (2,097 net + 1,000 gross)
 *     - 176.00   saving, as displayed    (75 net + 101 gross)
 *     = 2,921.00 products total
 *     + 465.06   VAT, added for the ex-VAT lines ONLY
 *     = 3,386.06 total
 *
 * That works because the two conventions are reconciled by WHERE the VAT is
 * added, not by converting the prices. The Samsung's tax is already inside its
 * 899.00 and nothing is added for it; the iPhone's is not, so its 465.06 is
 * the VAT row. Applying the rate to the mixed 2,921.00 instead gives 671.83 —
 * it taxes the Samsung twice, and overcharges by 38.66 on two items.
 *
 * Two figures therefore differ from what a single-convention shop would show,
 * and both are correct:
 *
 *   THE SAVING. The row says 176.00, the saving at the displayed prices. What
 *   actually comes off the total is 193.25, because the iPhone's 75 is net and
 *   reducing it also reduces the VAT added below by 17.25.
 *
 *   THE VAT. The row says 465.06, the VAT ADDED. The order contains 633.17
 *   once the Samsung's own tax is counted, which is what an invoice needs; the
 *   popover carries it.
 *
 * All of it is pinned below against the worked example in the brief.
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

console.log('\nsavings, per line, on the basis the line is priced on')

check('an ex-VAT line grosses its saving up by the VAT on it', () => {
  const row = savings([IPHONE])[0]

  assert.strictEqual(row.inclusive, false)
  close(row.unitSaving, 25, 'unit saving')
  close(row.baseSaving, 75, 'net saving (25 x 3)')
  close(row.vatOnSaving, 17.25, 'VAT on the saving')
  close(row.incSaving, 92.25, 'gross saving')

  // The parts close.
  close(row.baseSaving + row.vatOnSaving, row.incSaving, 'net + VAT = gross saving')
})

check('an inc-VAT line saves exactly what it says', () => {
  const row = savings([SAMSUNG])[0]

  assert.strictEqual(row.inclusive, true)
  close(row.incSaving, 101, 'gross saving')
  close(row.vatOnSaving, 0, 'nothing is added to a saving already gross')
})

check('a line with no saving is left out entirely', () => {
  const full = Object.assign({}, SAMSUNG, {
    original_price: 899, line_list_inc_vat: 899,
  })

  assert.strictEqual(savings([full]).length, 0)
})

console.log('\nthe worked example from the brief')

check('the saving is 176.00 as displayed and 193.25 off the final total', () => {
  /*
   * Both are right, and they are different numbers for a good reason.
   *
   * The summary shows 176.00 — the saving at the prices on the cards, 75 off
   * the iPhone and 101 off the Samsung. That is the figure that takes the
   * Products Total from 3,097.00 to 2,921.00.
   *
   * What actually comes off the amount charged is 193.25, because the iPhone's
   * 75 is net: reducing it also reduces the VAT added further down, by 17.25.
   * The Samsung's 101 is already gross and carries no such effect.
   */
  const asDisplayed = savings(BASKET).reduce((sum, row) => sum + row.baseSaving, 0)
  const offTheTotal = savings(BASKET).reduce((sum, row) => sum + row.incSaving, 0)

  close(asDisplayed, 176, 'the You Save row')
  close(offTheTotal, 193.25, 'the effect on the total')
  close(offTheTotal - asDisplayed, 17.25, 'the VAT on the ex-VAT saving')

  // And the row really is the first of those.
  close(summaryVm(BASKET).productsDiscount, 176, 'productsDiscount')
})

check('the gross ladder still closes, for the popovers that use it', () => {
  const listIncVat = BASKET.reduce((s, r) => s + r.line_list_inc_vat, 0)
  const incVat = BASKET.reduce((s, r) => s + r.line_inc_vat, 0)
  const youSave = savings(BASKET).reduce((s, r) => s + r.incSaving, 0)

  close(listIncVat, 3579.31, 'Products (Inc-VAT)')
  close(incVat, 3386.06, 'Total Products (Inc-VAT)')
  close(listIncVat - youSave, incVat, 'Products - You Save = Total Products')
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

  close(vm.originalTotal, 3097, 'Original Products total')
  close(vm.productsDiscount, 176, 'You Save')
  close(vm.cartPrice.totalPriceWithOffer, 2921, 'Products Total')
  close(vm.cartPrice.exclusiveTax, 465.06, 'VAT')
  close(vm.cartPrice.shippingPrice, 0, 'Shipping')
  close(vm.totalPrice, 3386.06, 'Total (Inc-VAT)')
})

check('the summary is one sum the customer can follow top to bottom', () => {
  const vm = summaryVm(BASKET)

  // 3,097.00 - 176.00 = 2,921.00
  close(vm.originalTotal - vm.productsDiscount, vm.cartPrice.totalPriceWithOffer,
    'Original - You Save = Products Total')

  // 2,921.00 + 465.06 + 0 = 3,386.06
  close(
    vm.cartPrice.totalPriceWithOffer + vm.cartPrice.exclusiveTax + vm.cartPrice.shippingPrice,
    vm.totalPrice,
    'Products Total + VAT + Shipping = Total'
  )
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
console.log('\noriginal prices, as the saving is measured against')

check('every line is counted once, at its original total', () => {
  // 699 x 3 as quoted, plus 1,000 x 1 as quoted.
  close(summaryVm(BASKET).originalTotal, 3097, 'original total')
})

check('the base is the line list, not the unit price', () => {
  const vm = summaryVm([IPHONE])

  // The trap: counting 699.00, which is not what three of them cost.
  close(vm.originalTotal, 2097, 'three at the original price')
  assert.ok(vm.originalTotal !== 699, 'the unit price is not the line')
})

check('a payload with no line_list falls back to unit x quantity', () => {
  const older = Object.assign({}, IPHONE)
  delete older.line_list

  close(summaryVm([older]).originalTotal, 2097, 'unit x quantity')
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

  assert.deepStrictEqual(rows, [
    { label: 'Current price', value: '€674.00' },
    { label: 'VAT rate', value: '23%' },
    { label: 'Inc-VAT equivalent', value: '€829.02' },
  ])
})

check('an inc-VAT card explains its own price', () => {
  const rows = vmFor(tile, { cart: SAMSUNG }).vatExplainer.rows

  assert.deepStrictEqual(rows, [
    { label: 'Current price', value: '€899.00' },
    { label: 'VAT included', value: '€168.11' },
    { label: 'Ex-VAT value', value: '€730.89' },
  ])
})

check('a zero-rated card opens nothing, because it has nothing to explain', () => {
  const zeroRated = Object.assign({}, SAMSUNG, { vat_rate: 0, unit_vat: 0 })

  assert.strictEqual(vmFor(tile, { cart: zeroRated }).vatExplainer, null)
})

check('the VAT icon opens both figures, added and contained', () => {
  const vm = summaryVm(BASKET)

  assert.deepStrictEqual(vm.vatExplainerRows, [
    { label: 'VAT added (23%)', value: '€465.06' },
    { label: 'Inside Inc-VAT prices', value: '€168.11' },
    { label: 'Total VAT in this order', value: '€633.17', strong: true },
    { label: 'Net Ex-VAT', value: '€2752.89' },
    { label: 'Total Inc-VAT', value: '€3386.06' },
  ])

  // The row it hangs off shows the total of the two - one VAT row, all of it.
  close(vm.vatTotal, 633.17, 'the VAT row')
})

check('an all ex-VAT basket does not claim any VAT was already included', () => {
  // Nothing is contained, so the two figures coincide and the extra rows would
  // just restate the first one.
  const labels = summaryVm([IPHONE]).vatExplainerRows.map(r => r.label)

  assert.ok(!labels.includes('Inside Inc-VAT prices'))
  assert.ok(!labels.includes('Total VAT in this order'))
})

check('a second rate stops a single percentage being printed on the row', () => {
  const reduced = Object.assign({}, SAMSUNG, {
    id: 3, vat_rate: 13.5,
    line_ex_vat: 100, line_vat: 13.5, line_inc_vat: 113.5, line_list_inc_vat: 113.5,
  })

  const vm = summaryVm([IPHONE, reduced])

  // 23% and 13.5% in one basket: no one percentage describes it.
  assert.strictEqual(vm.vatRateLabel, null)
  assert.strictEqual(vm.vatExplainerRows[0].label, 'VAT added')
  assert.ok(vm.vatExplainerNote.length > 0, 'and the popover says why')
})

console.log('\nVAT details')

check('one row per rate, highest first', () => {
  const byRate = {
    '23.00': { rate: 23, ex: 2752.89, vat: 633.17, inc: 3386.06 },
    '13.50': { rate: 13.5, ex: 100, vat: 13.5, inc: 113.5 },
    '0.00': { rate: 0, ex: 50, vat: 0, inc: 50 },
  }

  const rows = summary.computed.vatBreakdown.call(withI18n({ cartPrice: { byRate } }))

  assert.deepStrictEqual(rows.map(r => r.rate), [23, 13.5, 0])
})

check('a rate carrying no goods is not listed', () => {
  const byRate = {
    '23.00': { rate: 23, ex: 100, vat: 23, inc: 123 },
    '9.00': { rate: 9, ex: 0, vat: 0, inc: 0 },
  }

  const rows = summary.computed.vatBreakdown.call(withI18n({ cartPrice: { byRate } }))

  assert.strictEqual(rows.length, 1)
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

  close(vm.cartPrice.totalPriceWithOffer, 699, 'the price still reads correctly')
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
  const two = line(24, 'Two of them', 1000, 23, true, [1626.02, 373.98, 2000], {
    quantity: 2, line_list: 2000, line_list_inc_vat: 2000,
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

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all cart summary checks passed\n')
