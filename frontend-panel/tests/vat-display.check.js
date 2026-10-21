/*
 * Which VAT labels a price is allowed to carry.
 *
 * Run with: npm run check:vat-display
 *
 * This catalogue quotes prices under two conventions at once, and the rule is
 * deliberately asymmetric:
 *
 *   ex-VAT quote   the headline is not what the customer pays, so the gross
 *                  figure has to appear beside it
 *   inc-VAT quote   the headline already is what the customer pays, so its net
 *                  equivalent must NOT appear - it reads as a cheaper offer
 *   no rate         say nothing; a zero-rated or margin-scheme line has no
 *                  side to name and no split to show
 *
 * The regression these pin down is the second case. Every tile used to print a
 * counterpart, so the 111 products whose prices already contain their VAT
 * advertised a net figure nobody could pay. The split itself is server-side;
 * nothing here re-derives a rate.
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
    plugins: [],
  }).code

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, require)

  return module.exports.default || module.exports
}

const helper = loadEsm(path.join(ROOT, 'mixin/productPriceHelper.js'), 'productPriceHelper.js')

/* Call a computed with nothing but the product on `this`. */
function withProduct(name, product) {
  return helper.computed[name].call(withI18n({
    product,
    computed: helper.computed,
    get hasVatSplit() {
      return helper.computed.hasVatSplit.call(this)
    },
  }))
}

/* The three shapes ListingPrices::decorate actually emits. */
const EXCLUSIVE = {
  // Apple iPhone 15 Pro, excludeVAT = 1: quoted net, VAT added at checkout.
  display_price: 699,
  display_price_ex_vat: 699,
  display_price_vat: 160.77,
  display_price_inc_vat: 859.77,
  vat_rate: 23,
  vat_inclusive: false,
}

const INCLUSIVE = {
  // Samsung Galaxy A54 5G, excludeVAT = 0: the 1000 is what gets charged.
  display_price: 1000,
  display_price_ex_vat: 813.01,
  display_price_vat: 186.99,
  display_price_inc_vat: 1000,
  vat_rate: 23,
  vat_inclusive: true,
}

const ZERO_RATED = {
  // A margin-scheme line: vatBreakdown reports rate 0 and no split.
  display_price: 699,
  display_price_ex_vat: 699,
  display_price_vat: 0,
  display_price_inc_vat: 699,
  vat_rate: 0,
  vat_inclusive: true,
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

console.log('\nproduct tile / listing + search')

check('an ex-VAT price is labelled Ex-VAT', () => {
  assert.strictEqual(withProduct('vatSide', EXCLUSIVE), 'Ex-VAT')
})

check('an ex-VAT price states the gross figure beside it', () => {
  const counterpart = withProduct('vatCounterpart', EXCLUSIVE)

  assert.ok(counterpart, 'expected a counterpart on an ex-VAT quote')
  assert.strictEqual(counterpart.label, 'Inc-VAT')
  assert.strictEqual(counterpart.amount, 859.77)
})

check('an inc-VAT price is labelled Inc-VAT', () => {
  assert.strictEqual(withProduct('vatSide', INCLUSIVE), 'Inc-VAT')
})

check('an inc-VAT price shows NO net counterpart', () => {
  // The regression: 813.01 advertised under a product that costs 1000.
  assert.strictEqual(
    withProduct('vatCounterpart', INCLUSIVE), null,
    'an inc-VAT price must not advertise its net equivalent'
  )
})

check('a zero-rated price names no side and shows no split', () => {
  assert.strictEqual(withProduct('vatSide', ZERO_RATED), '')
  assert.strictEqual(withProduct('vatCounterpart', ZERO_RATED), null)
})

check('the counterpart is never re-derived in the browser', () => {
  // Feed a deliberately inconsistent split: the UI must quote the server's
  // number verbatim rather than recomputing 699 x 1.23.
  const odd = Object.assign({}, EXCLUSIVE, { display_price_inc_vat: 800 })

  assert.strictEqual(withProduct('vatCounterpart', odd).amount, 800)
})

console.log('\nbasket + checkout line')

/*
 * The basket and the checkout list share this computed, so both screens say the
 * same thing about a line. It reads the cart row's own treatment, not the
 * product's - a basket can hold one of each convention.
 */
function cartDetail(cart) {
  return helper.computed.cartVatDetail.call(withI18n({ cart }))
}

check('an ex-VAT cart line states its gross figure and the VAT added', () => {
  // €674 net: "Inc-VAT €829.02 (VAT 23% €155.02)"
  const out = cartDetail({
    vat_rate: 23, vat_inclusive: false,
    unit_ex_vat: 674, unit_vat: 155.02, unit_inc_vat: 829.02,
  })

  assert.ok(out, 'expected a VAT line on an ex-VAT basket line')
  assert.strictEqual(out.amount, 829.02)
  assert.strictEqual(out.vat, 155.02)
  assert.strictEqual(out.rate, 23)
  assert.strictEqual(out.inclusive, false, 'must read as VAT added at a rate')

  // The wording is the whole point: added, not contained.
  assert.ok(
    Math.abs(674 * 23 / 100 - out.vat) < 0.011,
    'an ex-VAT line adds VAT on top of the price'
  )
})

check('an inc-VAT cart line states the VAT contained in it', () => {
  // €899 gross: "Inc-VAT €899.00 (VAT included €168.11)"
  const out = cartDetail({
    vat_rate: 23, vat_inclusive: true,
    unit_ex_vat: 730.89, unit_vat: 168.11, unit_inc_vat: 899,
  })

  assert.ok(out, 'expected a VAT line on an inc-VAT basket line')
  assert.strictEqual(out.amount, 899)
  assert.strictEqual(out.vat, 168.11)
  assert.strictEqual(out.inclusive, true, 'must read as VAT already included')

  // Taken out of the price, not added to it - 899 x 23% would be 206.77.
  assert.ok(
    Math.abs(899 * 23 / 123 - out.vat) < 0.011,
    'an inc-VAT line takes the VAT out of the price'
  )
  assert.ok(
    Math.abs(899 * 23 / 100 - out.vat) > 1,
    'an inc-VAT line must not be taxed as though it were net'
  )
})

check('a zero-rated cart line says nothing', () => {
  assert.strictEqual(cartDetail({ vat_rate: 0, vat_inclusive: false }), null)
})

check('a line with no VAT fields at all says nothing', () => {
  // An endpoint that never ran the split must not fall back to a guess.
  assert.strictEqual(cartDetail({}), null)
})

console.log('\nthe rate label is whatever the server sent')

check('23 renders as 23%', () => {
  assert.strictEqual(helper.methods.formatVatRate(23), '23%')
})

check('a fractional rate keeps its decimal', () => {
  assert.strictEqual(helper.methods.formatVatRate(13.5), '13.5%')
})

check('a whole rate ending in zero is not truncated', () => {
  assert.strictEqual(helper.methods.formatVatRate(20), '20%')
  assert.strictEqual(helper.methods.formatVatRate(100), '100%')
})

check('a zero rate has no label', () => {
  assert.strictEqual(helper.methods.formatVatRate(0), '')
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all VAT display checks passed\n')
