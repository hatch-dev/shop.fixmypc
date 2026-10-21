/*
 * What a product tile prices at, once it has a quantity.
 *
 * Run with: npm run check:tile-quantity-pricing
 *
 * A listing price is decided at quantity 1 — ListingPrices::decorate asks
 * Pricing::displayRange for the cost of buying one, because a tile has no
 * quantity to speak of. The tile then grew a quantity stepper, and nothing
 * reconnected the two: a business account stepping 10 -> 11 crossed a band,
 * the basket and checkout both moved, and the tile went on showing the
 * quantity-1 price. That is the regression these pin down.
 *
 * The bands are not modelled here and must never be. The server decides which
 * tier applies (CartsController::action -> Pricing::unitPrice) and answers with
 * the figure it settled on; the tile's only job is to show that answer rather
 * than the one it was rendered with. So every case below feeds in a *server
 * response* and asserts what gets displayed.
 *
 * The figures are the ones configured for Apple iPhone 15 Pro (99492258) on
 * 2026-09-16 — 3-10 @ 674, 11-20 @ 649, 21+ @ 624, retail 699 — used as
 * realistic fixtures, not as a rule the frontend applies.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const { withI18n, installI18n } = require('./i18n-en')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))

function loadEsm(file, name) {
  const code = babel.transformSync(fs.readFileSync(file, 'utf8'), {
    filename: name,
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, require)

  return module.exports.default || module.exports
}

const helper = loadEsm(path.join(ROOT, 'mixin/productPriceHelper.js'), 'productPriceHelper.js')

/* The listing payload for the product, as ListingPrices::decorate emits it. */
const LISTING = {
  display_price: 699,
  display_compare_price: 899,
  display_price_ex_vat: 699,
  display_price_vat: 160.77,
  display_price_inc_vat: 859.77,
  vat_rate: 23,
  vat_inclusive: false,
}

/*
 * Resolve the mixin's computeds against a product and (optionally) a basket
 * line, wiring the dependencies between them the way Vue would.
 */
function view(product, cartEntry) {
  const ctx = withI18n({
    product,
    cartEntry: cartEntry || null,
    get cartLine() { return helper.computed.cartLine.call(ctx) },
    get cartLineUnitPrice() { return helper.computed.cartLineUnitPrice.call(ctx) },
    get hasServerPrice() { return helper.computed.hasServerPrice.call(ctx) },
    get hasVatSplit() { return helper.computed.hasVatSplit.call(ctx) },
    get flashPrice() { return helper.computed.flashPrice.call(ctx) },
    get sellPrice() { return helper.computed.sellPrice.call(ctx) },
    get offeredPrice() { return helper.computed.offeredPrice.call(ctx) },
    get currentPricing() { return helper.computed.currentPricing.call(ctx) },
    get prevPrice() { return helper.computed.prevPrice.call(ctx) },
    get vatCounterpart() { return helper.computed.vatCounterpart.call(ctx) },
    get livePrice() { return helper.computed.livePrice.call(ctx) },
    get livePrevPrice() { return helper.computed.livePrevPrice.call(ctx) },
  })

  return {
    price: ctx.livePrice,
    prev: ctx.livePrevPrice,
    discount: helper.computed.liveReducedPercent.call(ctx),
    counterpart: helper.computed.liveVatCounterpart.call(ctx),
  }
}

/*
 * A basket line as the server answers with it: the unit price it settled on
 * for this quantity, the retail price that undercuts, and the split.
 */
function line(quantity, unitPrice) {
  const inc = Math.round(unitPrice * 1.23 * 100) / 100

  return {
    quantity,
    unit_price: unitPrice,
    original_price: 699,
    line_total: Math.round(unitPrice * quantity * 100) / 100,
    vat_rate: 23,
    vat_inclusive: false,
    unit_ex_vat: unitPrice,
    unit_vat: Math.round((inc - unitPrice) * 100) / 100,
    unit_inc_vat: inc,
  }
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

console.log('\nno basket line — listing behaviour is untouched')

check('a tile with no line shows the listing price', () => {
  const out = view(LISTING, null)

  assert.strictEqual(out.price, 699)
  assert.strictEqual(out.prev, 899)
})

check('a tile with no line keeps the listing VAT counterpart', () => {
  assert.strictEqual(view(LISTING, null).counterpart.amount, 859.77)
})

console.log('\nquantity boundaries — the tile shows what the server returned')

/* [quantity, the unit price the server answers with] */
const BOUNDARIES = [
  [1, 699], [2, 699],
  [3, 674], [4, 674], [5, 674], [10, 674],
  [11, 649], [20, 649],
  [21, 624], [30, 624], [31, 624], [50, 624], [100, 624],
]

BOUNDARIES.forEach(([quantity, unitPrice]) => {
  check(`quantity ${quantity} displays ${unitPrice}`, () => {
    assert.strictEqual(view(LISTING, line(quantity, unitPrice)).price, unitPrice)
  })
})

check('crossing 10 -> 11 changes the displayed price', () => {
  const before = view(LISTING, line(10, 674)).price
  const after = view(LISTING, line(11, 649)).price

  assert.notStrictEqual(before, after)
  assert.strictEqual(after, 649)
})

check('crossing 20 -> 21 changes the displayed price', () => {
  assert.strictEqual(view(LISTING, line(20, 649)).price, 649)
  assert.strictEqual(view(LISTING, line(21, 624)).price, 624)
})

check('30 -> 31 stays on the open-ended tier', () => {
  assert.strictEqual(view(LISTING, line(30, 624)).price, 624)
  assert.strictEqual(view(LISTING, line(31, 624)).price, 624)
})

console.log('\ndiscount and VAT follow the tier')

check('the discount badge is recomputed from the tier price', () => {
  // 674 off 699 is 3%; 624 off 699 is 10%. The badge must not keep the first.
  assert.strictEqual(view(LISTING, line(10, 674)).discount, 4)
  assert.strictEqual(view(LISTING, line(21, 624)).discount, 11)
})

check('a tier that saves nothing shows no discount', () => {
  const out = view(LISTING, line(1, 699))

  assert.strictEqual(out.prev, 0, 'nothing to strike through')
  assert.strictEqual(out.discount, 0)
})

check('the VAT counterpart follows the tier price', () => {
  // Not 859.77, which is the quantity-1 figure off the product payload.
  assert.strictEqual(view(LISTING, line(21, 624)).counterpart.amount, 767.52)
})

check('the VAT amount and rate follow the tier too', () => {
  // "Inc-VAT €767.52 (VAT 23% €143.52)" - every part of that line moves with
  // the band, not just the gross figure.
  const out = view(LISTING, line(21, 624)).counterpart

  assert.strictEqual(out.vat, 143.52)
  assert.strictEqual(out.rate, 23)

  // And the three figures agree with each other.
  assert.ok(
    Math.abs((624 + out.vat) - out.amount) < 0.005,
    'net + VAT must equal the gross figure shown beside it'
  )
})

check('crossing a band moves the VAT with the price', () => {
  const below = view(LISTING, line(10, 674)).counterpart
  const above = view(LISTING, line(11, 649)).counterpart

  assert.notStrictEqual(below.vat, above.vat)
  assert.notStrictEqual(below.amount, above.amount)
})

check('a line with no split yet shows no counterpart', () => {
  // Better to show nothing than to pair this quantity's net price with the
  // previous quantity's gross one.
  const stale = line(21, 624)
  delete stale.unit_inc_vat
  delete stale.vat_rate

  assert.strictEqual(view(LISTING, stale).counterpart, null)
})

check('an inc-VAT line shows no counterpart', () => {
  const gross = Object.assign(line(21, 624), { vat_inclusive: true })

  assert.strictEqual(view(LISTING, gross).counterpart, null)
})

console.log('\nthe tile never prices for itself')

check('an unpriced line falls back rather than guessing', () => {
  // A row in the store with no server price yet must not render as 0.
  const out = view(LISTING, { quantity: 11 })

  assert.strictEqual(out.price, 699)
})

check('the displayed price is whatever the server said, even if odd', () => {
  // Feed a figure no band would produce: the tile must still show it rather
  // than substituting a tier of its own.
  assert.strictEqual(view(LISTING, line(21, 111.11)).price, 111.11)
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all tile quantity pricing checks passed\n')
