/*
 * The Essentials Bundle saving on the admin screens, without a build.
 *
 * Run from admin-panel/: npm run check:bundle-order-rows
 *
 * Three places had to learn about it, and each reads a different payload:
 *
 *   - the order detail     result.calculated.bundle_discount
 *   - the printed invoice  order.calculated.bundle_discount
 *   - the Bundle Deals list  the per-row figures, now priced per VARIANT by
 *     BundleService rather than from products.selling, plus the `sellable`
 *     flag that says whether the storefront will show the deal at all
 *
 * These pages pull in the whole admin shell, so rather than mount them their
 * computeds are called against a stub and their markup is read directly --
 * enough to catch a row wired to a key nothing sends, which is the failure
 * this change could actually introduce.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const compiler = require(path.join(NM, 'vue-template-compiler'))
const babel = require(path.join(NM, '@babel/core'))

function parse (relative) {
  const src = fs.readFileSync(path.join(ROOT, relative), 'utf8')
  const parsed = compiler.parseComponent(src)

  const errors = compiler.compile(parsed.template.content).errors

  assert.deepStrictEqual(errors, [], relative + ' template: ' + errors.join(', '))

  return parsed
}

function loadPage (relative) {
  const parsed = parse(relative)

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

    return { default: {}, __esModule: true }
  }

  const module = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return module.exports.default || module.exports
}

function markup (relative) {
  return parse(relative).template.content
}

function computed (options, name, ctx) {
  assert.ok(options.computed[name], 'no computed "' + name + '"')

  return options.computed[name].call(ctx)
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

const orderDetail = loadPage('pages/orders/_id.vue')
const invoice = loadPage('components/partials/Invoice.vue')

console.log('\nthe admin order detail')

test('the saving is read off calculated.bundle_discount', () => {
  const ctx = { result: { calculated: { bundle_discount: '10.50' } } }

  assert.strictEqual(computed(orderDetail, 'bundleDiscount', ctx), 10.5)
})

test('an order that carried none shows nothing', () => {
  const ctx = { result: { calculated: { bundle_discount: '0.00' } } }

  assert.strictEqual(computed(orderDetail, 'bundleDiscount', ctx), 0)
})

test('an older order with no such key does not break the screen', () => {
  const ctx = { result: { calculated: {} } }

  assert.strictEqual(computed(orderDetail, 'bundleDiscount', ctx), 0)
})

test('the row is drawn from that computed, under its own label', () => {
  const template = markup('pages/orders/_id.vue')

  assert.ok(/v-if="bundleDiscount"/.test(template), 'no row guarded on bundleDiscount')
  assert.ok(/fSale\.bundleDiscount/.test(template), 'the row has no label')
})

console.log('\nthe printed invoice')

test('the saving is read off calculated.bundle_discount', () => {
  const ctx = { order: { calculated: { bundle_discount: '10.50' } } }

  assert.strictEqual(computed(invoice, 'bundleDiscount', ctx), 10.5)
})

test('an order that carried none shows nothing', () => {
  const ctx = { order: { calculated: {} } }

  assert.strictEqual(computed(invoice, 'bundleDiscount', ctx), 0)
})

test('the row is drawn from that computed', () => {
  const template = markup('components/partials/Invoice.vue')

  assert.ok(/v-if="bundleDiscount"/.test(template), 'no row guarded on bundleDiscount')
  assert.ok(/fSale\.bundleDiscount/.test(template), 'the row has no label')
})

test('the label exists in en.json, so the row is not blank', () => {
  const messages = JSON.parse(fs.readFileSync(path.join(ROOT, 'lang/en.json'), 'utf8'))

  assert.ok(messages.fSale && messages.fSale.bundleDiscount,
    'fSale.bundleDiscount is missing, so both rows would render an empty label')
})

test('it is not confused with the buy-X-get-Y-free row', () => {
  const messages = JSON.parse(fs.readFileSync(path.join(ROOT, 'lang/en.json'), 'utf8'))

  assert.notStrictEqual(messages.fSale.bundleDiscount, messages.fSale.bundleOffer,
    'the two bundle features would read identically on the invoice')
})

console.log('\nthe Bundle Deals list')

test('a deal the storefront will not show is marked', () => {
  const template = markup('pages/bundle-deals/index.vue')

  assert.ok(/value\.sellable === false/.test(template),
    'the list never tests the sellable flag the API now sends')

  assert.ok(/bundle-not-live/.test(template), 'no marker element')
})

test('the marker is scoped to this list, not every admin table', () => {
  const src = fs.readFileSync(path.join(ROOT, 'pages/bundle-deals/index.vue'), 'utf8')
  const styles = src.slice(src.indexOf('<style'))

  assert.ok(/\.bundle-deals-table \.bundle-not-live/.test(styles),
    'the rule is unscoped and would reach other admin lists')
})

test('the list prints the server figures rather than recomputing them', () => {
  const template = markup('pages/bundle-deals/index.vue')

  for (const field of ['total_price', 'discount_amount', 'final_price']) {
    assert.ok(new RegExp('value\.' + field).test(template),
      'the list does not print value.' + field)
  }

  assert.ok(!/discount_value\s*\)\s*\/\s*100/.test(template),
    'the list is working the discount out for itself again')
})

console.log('')
console.log(passed + ' passed, ' + failed + ' failed')

process.exit(failed ? 1 : 0)
