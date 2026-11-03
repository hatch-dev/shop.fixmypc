/*
 * The bundle saving where a customer actually reads it, without a browser.
 *
 * Run with: node tests/bundle-order-rows.check.js
 *
 * The Essentials Bundle discount is re-derived server-side and only ever
 * arrives as a number on a payload. Three screens have to print it, and each
 * of them prints a DIFFERENT payload:
 *
 *   - guest checkout      cart.bundleDiscount   (the live basket)
 *   - order confirmation  calculations          (the placed order)
 *   - order history       calculations          (the placed order)
 *
 * Guest checkout is the one that matters most: it subtracts the discount from
 * the figure on the pay button, so a page that cannot see it quotes a total
 * HIGHER than the amount the server takes.
 *
 * As in the sibling checks, the pages are far too large to mount, so their
 * computeds and methods are called against a stub: the real code, none of the
 * scaffolding.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const compiler = require(path.join(NM, 'vue-template-compiler'))
const babel = require(path.join(NM, '@babel/core'))

function loadPage (relative) {
  const src = fs.readFileSync(path.join(ROOT, relative), 'utf8')
  const parsed = compiler.parseComponent(src)

  const errors = compiler.compile(parsed.template.content).errors

  assert.deepStrictEqual(errors, [], relative + ' template: ' + errors.join(', '))

  const code = babel.transformSync(parsed.script.content, {
    filename: path.basename(relative),
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const stub = (request) => {
    if (request === 'vuex') {
      const spread = () => ({})
      return { mapGetters: spread, mapActions: spread, mapState: spread }
    }

    // The shared checkout arithmetic is loaded for real: it IS the total.
    if (request.indexOf('mixin/checkoutTotals') !== -1) {
      const totals = { exports: {} }
      const source = babel.transformSync(fs.readFileSync(path.join(ROOT, 'mixin/checkoutTotals.js'), 'utf8'), {
        filename: 'checkoutTotals.js', configFile: false, babelrc: false,
        presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
      }).code

      // eslint-disable-next-line no-new-func
      new Function('module', 'exports', 'require', source)(totals, totals.exports, () => ({}))

      return totals.exports
    }

    return { default: {}, __esModule: true }
  }

  const module = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return module.exports.default || module.exports
}

/** The raw <template> of a page, for asserting what the markup reads. */
function markup (relative) {
  const src = fs.readFileSync(path.join(ROOT, relative), 'utf8')

  return compiler.parseComponent(src).template.content
}

function computed (options, name, ctx) {
  assert.ok(options.computed[name], 'no computed "' + name + '"')

  return options.computed[name].call(ctx)
}

function method (options, name, ctx, ...args) {
  assert.ok(options.methods[name], 'no method "' + name + '"')

  return options.methods[name].apply(ctx, args)
}

const guest = loadPage('pages/guest-checkout.vue')
const complete = loadPage('pages/user/order/complete/_id.vue')
const history = loadPage('pages/user/order/_id.vue')

/* A basket as the bundle-discount endpoint answers it. */
function bundleState (total, bundles) {
  return {
    cart: {
      bundleDiscount: total === null
        ? null
        : { total: total, bundles: bundles || [{ id: 7, title: 'Car Essentials Bundle', discount: total }] },
    },
  }
}

let passed = 0
let failed = 0

function test (name, fn) {
  try {
    fn()
    console.log('  ok   ' + name)
    passed++
  } catch (e) {
    console.log('  FAIL ' + name + '\n         ' + e.message)
    failed++
  }
}

console.log('\nguest checkout: the bundle saving')

/*
 * The guest's pay button, from the same chain the registered checkout uses
 * (checkoutTotals.payable): goods incl. VAT + delivery, less voucher, flash
 * discount and bundle, each capped by what is still owed.
 */
function guestTotal ({ goods = 94.97, delivery = 0, bundle = null, voucher = null, flash = 0 } = {}) {
  const ctx = {
    $store: { state: bundleState(bundle), getters: { 'cart/flashDiscountAmount': flash } },
    goods: { incVat: goods },
    deliveryPrice: delivery,
    voucherResult: voucher,
  }

  ctx.voucherDiscount = computed(guest, 'voucherDiscount', ctx)
  ctx.bundleDiscount = computed(guest, 'bundleDiscount', ctx)
  ctx.flashDiscount = computed(guest, 'flashDiscount', ctx)
  ctx.totals = computed(guest, 'totals', ctx)

  return { ctx, payable: computed(guest, 'payableTotal', ctx) }
}

test('the saving comes off the pay button', () => {
  const { ctx, payable } = guestTotal({ bundle: 10.5 })

  assert.strictEqual(ctx.bundleDiscount, 10.5)
  assert.strictEqual(payable, 84.47)
})

test('a basket with no bundle pays the same as before', () => {
  const { ctx, payable } = guestTotal()

  assert.strictEqual(ctx.bundleDiscount, 0)
  assert.strictEqual(payable, 94.97)
})

test('a voucher and a bundle both come off', () => {
  assert.strictEqual(guestTotal({ bundle: 10.5, voucher: { offered: '5.00' } }).payable, 79.47)
})

test('the total never goes below zero', () => {
  assert.strictEqual(guestTotal({ goods: 20, bundle: 500 }).payable, 0)
})

test('the flash discount the order takes comes off the guest pay button too', () => {
  // 94.97 + 5.00 delivery - 9.50 flash - 10.50 bundle = 79.97
  assert.strictEqual(guestTotal({ delivery: 5, flash: 9.5, bundle: 10.5 }).payable, 79.97)
})

test('one row per bundle, each named', () => {
  const ctx = {
    $store: {
      state: bundleState(14, [
        { id: 7, title: 'Car Essentials Bundle', discount: 10.5 },
        { id: 8, title: 'Screen Care Bundle', discount: 3.5 },
      ]),
    },
  }

  const rows = computed(guest, 'bundleDiscounts', ctx)

  assert.strictEqual(rows.length, 2)
  assert.strictEqual(rows[0].title, 'Car Essentials Bundle')
})

test('no bundle means no rows rather than a crash', () => {
  const ctx = { $store: { state: bundleState(null) } }

  assert.deepStrictEqual(computed(guest, 'bundleDiscounts', ctx), [])
})

test('the markup draws those rows and asks the server for them', () => {
  const template = markup('pages/guest-checkout.vue')
  const script = fs.readFileSync(path.join(ROOT, 'pages/guest-checkout.vue'), 'utf8')

  assert.ok(/v-for="bundle in bundleDiscounts"/.test(template),
    'the summary does not loop over bundleDiscounts')

  assert.ok(/bundle\.discount/.test(template),
    'the row does not print the bundle amount')

  assert.ok(/cart\/loadBundleDiscount/.test(script),
    'the page never asks the server what the bundles are worth')
})

console.log('\nthe order pages: the bundle saving')

for (const [label, page, rel, state] of [
  ['confirmation', complete, 'pages/user/order/complete/_id.vue', 'order'],
  ['history', history, 'pages/user/order/_id.vue', 'ordered'],
]) {
  test(label + ': a bundle saving is shown when the order carried one', () => {
    const ctx = { [state]: { calculations: { bundle_discount: '10.50' } } }

    assert.strictEqual(method(page, 'showRow', ctx, 'Bundle Saving'), true)
  })

  test(label + ': no row when the order carried none', () => {
    const ctx = { [state]: { calculations: { bundle_discount: '0.00' } } }

    assert.strictEqual(method(page, 'showRow', ctx, 'Bundle Saving'), false)
  })

  test(label + ': the markup prints the figure it decided to show', () => {
    const template = markup(rel)

    assert.ok(/showRow\('Bundle Saving'\)/.test(template),
      'the page never asks whether to draw the bundle row')

    assert.ok(template.indexOf(state + '?.calculations?.bundle_discount') !== -1,
      'the row does not read calculations.bundle_discount')
  })
}

console.log('')
console.log(passed + ' passed, ' + failed + ' failed')

process.exit(failed ? 1 : 0)
