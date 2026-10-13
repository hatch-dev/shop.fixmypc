/*
 * The buy box, priced the way the page prices it.
 *
 * Run with: npm run check:business-pricing
 *
 * These exercise the band-reading mixin and the two computeds that decide what
 * the customer sees, against the shapes FrontendController::businessProduct
 * actually returns. The regressions they pin down are the ones that produced
 * the reported bug: an open-ended band that never matched, a discount
 * re-derived in the browser from the wrong base, and the bundle total quietly
 * replacing the unit price.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

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

const businessPricing = loadEsm(path.join(ROOT, 'mixin/businessPricing.js'), 'businessPricing.js')

/* The component options out of the shipped SFC, so the assertions run against
 * the source that ships rather than a copy of it. */
function loadComponent(sfcPath) {
  const sfc = compiler.parseComponent(fs.readFileSync(sfcPath, 'utf8'))

  const code = babel.transformSync(sfc.script.content, {
    filename: path.basename(sfcPath) + '.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
    plugins: [],
  }).code

  const stub = { default: {}, __esModule: true }
  const fakeRequire = (request) => {
    if (request === 'vuex') {
      return { mapGetters: () => ({}), mapActions: () => ({}), mapState: () => ({}) }
    }
    if (request === 'moment') return require(path.join(NM, 'moment'))
    if (request.indexOf('~/mixin/businessPricing') === 0) {
      return { default: businessPricing, __esModule: true }
    }
    if (request.indexOf('~/') === 0 || request.indexOf('@/') === 0) return stub
    try {
      return require(path.join(NM, request))
    } catch (e) {
      return stub
    }
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return module.exports.default || module.exports
}

const DetailRight = loadComponent(path.join(ROOT, 'components/DetailRight.vue'))

/* A stand-in `this` for a computed: the component's own computeds and methods,
 * resolved lazily against whatever state the test supplies. */
function context(component, state) {
  const vm = Object.create(null)

  Object.assign(vm, state)

  const mixins = component.mixins || []

  for (const source of [...mixins, component]) {
    for (const [name, fn] of Object.entries(source.methods || {})) {
      if (!(name in state)) vm[name] = fn.bind(vm)
    }
  }

  for (const source of [...mixins, component]) {
    for (const [name, fn] of Object.entries(source.computed || {})) {
      if (name in state || typeof fn !== 'function') continue
      Object.defineProperty(vm, name, { get: fn.bind(vm), configurable: true })
    }
  }

  return vm
}

let failures = 0
function test(name, fn) {
  try {
    fn()
    console.log('  ok   ' + name)
  } catch (e) {
    failures++
    console.log('  FAIL ' + name + '\n         ' + e.message)
  }
}

// The bands from the reported configuration, as the server sends them: a
// final_price per band, decided server-side, and max 0 for the open-ended top.
const BANDS = [
  { min: 5, max: 10, discount_type: 'fixed', discount_value: 40, final_price: 659 },
  { min: 11, max: 20, discount_type: 'fixed', discount_value: 70, final_price: 629 },
  { min: 21, max: 30, discount_type: 'fixed', discount_value: 100, final_price: 599 },
  { min: 31, max: 0, discount_type: 'fixed', discount_value: 150, final_price: 549 },
]

const mixin = context({ mixins: [businessPricing] }, {})

console.log('\nband matching')

test('a quantity below every band has no band', () => {
  assert.strictEqual(mixin.bandForQuantity(BANDS, 1), null)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 4), null)
})

test('each band covers its own range and no more', () => {
  assert.strictEqual(mixin.bandForQuantity(BANDS, 5).final_price, 659)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 10).final_price, 659)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 11).final_price, 629)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 20).final_price, 629)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 21).final_price, 599)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 25).final_price, 599)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 30).final_price, 599)
})

test('the open-ended top band covers everything above its minimum', () => {
  // `qty <= row.max` against a stored max of 0 skipped this band entirely,
  // so the largest orders silently fell back to retail.
  assert.strictEqual(mixin.bandForQuantity(BANDS, 31).final_price, 549)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 50).final_price, 549)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 100).final_price, 549)
  assert.strictEqual(mixin.bandForQuantity(BANDS, 100000).final_price, 549)
})

test('overlapping bands resolve to the cheapest, as Pricing::businessTier does', () => {
  const overlapping = [
    { min: 10, max: 40, final_price: 620 },
    { min: 20, max: 40, final_price: 580 },
  ]
  assert.strictEqual(mixin.bandForQuantity(overlapping, 25).final_price, 580)
})

test('a band never raises the price', () => {
  // A flash sale can put retail below an agreed wholesale rate.
  assert.strictEqual(mixin.bandedUnitPrice(BANDS, 30, 450), 450)
  assert.strictEqual(mixin.bandedUnitPrice(BANDS, 30, 699), 599)
})

test('no bands at all leaves the retail price untouched', () => {
  assert.strictEqual(mixin.bandedUnitPrice([], 30, 699), 699)
  assert.strictEqual(mixin.bandedUnitPrice(null, 30, 699), 699)
})

console.log('\nthe buy box')

function buyBox(state) {
  return context(DetailRight, Object.assign({
    bulkPricing: [],
    quotedUnitPrice: null,
    quantity: 1,
    extrasTotalPrice: null,
    extrasOldPrice: null,
    productPrice: 699,
    prevPrice: 899,
    shippingPrice: 0,
    selectedVoucher: null,
    product: {},
    productInventory: {},
  }, state))
}

test('the server quote wins over anything derived locally', () => {
  const vm = buyBox({ bulkPricing: BANDS, quantity: 30, quotedUnitPrice: 599 })
  assert.strictEqual(vm.finalUnitPrice, 599)
})

test('before the quote lands the band the server sent is used', () => {
  const vm = buyBox({ bulkPricing: BANDS, quantity: 30 })
  assert.strictEqual(vm.finalUnitPrice, 599)
})

test('a business account buying 30 is not shown the retail price', () => {
  // The reported bug, exactly: €699 above a quantity box reading 30.
  const vm = buyBox({ bulkPricing: BANDS, quantity: 30, quotedUnitPrice: 599 })
  assert.strictEqual(vm.displayUnitPrice, 599)
  assert.notStrictEqual(vm.displayUnitPrice, 699)
})

test('changing the quantity changes the price', () => {
  const seen = [1, 5, 11, 21, 30, 31, 50, 100].map(
    (qty) => buyBox({ bulkPricing: BANDS, quantity: qty }).finalUnitPrice
  )
  assert.deepStrictEqual(seen, [699, 659, 629, 599, 599, 549, 549, 549])
})

test('a personal account with no bands keeps the retail price', () => {
  const vm = buyBox({ bulkPricing: [], quantity: 30, quotedUnitPrice: 699 })
  assert.strictEqual(vm.displayUnitPrice, 699)
})

test('ticked add-ons are added to the unit price, not swapped for it', () => {
  // extras used to *replace* the headline, which is what wiped out the band.
  const vm = buyBox({
    bulkPricing: BANDS, quantity: 30, quotedUnitPrice: 599, extrasTotalPrice: 50,
  })
  assert.strictEqual(vm.displayUnitPrice, 649)
})

test('with no add-ons the headline is exactly the unit price', () => {
  const vm = buyBox({
    bulkPricing: BANDS, quantity: 30, quotedUnitPrice: 599, extrasTotalPrice: null,
  })
  assert.strictEqual(vm.displayUnitPrice, 599)
})

test('the struck-through price covers the same items as the price', () => {
  const vm = buyBox({
    bulkPricing: BANDS, quantity: 30, quotedUnitPrice: 599,
    extrasTotalPrice: 50, extrasOldPrice: 80, prevPrice: 899,
  })
  assert.strictEqual(vm.displayOldPrice, 979)
})

test('nothing is struck through when there is no genuine discount', () => {
  const vm = buyBox({ quotedUnitPrice: 699, prevPrice: 0 })
  assert.strictEqual(vm.displayOldPrice, 0)
})

test('the total in the details popover follows the banded unit price', () => {
  const vm = buyBox({
    bulkPricing: BANDS, quantity: 30, quotedUnitPrice: 599, shippingPrice: 16,
  })
  assert.strictEqual(vm.displayTotalPrice, 615)
})

test('the applied band is reported for the rate card', () => {
  const vm = buyBox({ bulkPricing: BANDS, quantity: 30 })
  assert.strictEqual(vm.appliedBand.min, 21)
  assert.strictEqual(vm.appliedBand.max, 30)

  assert.strictEqual(buyBox({ bulkPricing: BANDS, quantity: 2 }).appliedBand, null)
})

console.log('')

if (failures) {
  console.log(failures + ' failing\n')
  process.exit(1)
}

console.log('all passing\n')
