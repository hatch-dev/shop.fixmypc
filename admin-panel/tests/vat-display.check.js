/*
 * Which VAT figures an order line is allowed to print.
 *
 * Run from admin-panel/: npm run check:vat-display
 *
 * The admin order table is read by whoever is answering the phone about an
 * order, and its "Line total" header names no VAT side. It used to lead every
 * row with the net figure and hang "Inc-VAT" underneath, so on the products
 * whose prices already contain their VAT the column showed an amount the
 * customer was never charged.
 *
 * The row now closes on the side it was actually charged on, and states the
 * gross figure only where the charged price is net. Every figure comes from
 * what the order froze (OrderSummary::decorate); nothing here re-derives a
 * rate, and an order placed before the treatment was recorded must not be
 * given one.
 */
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

/* The component's options object, without mounting it. */
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
    return {}
  }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return module.exports.default || module.exports
}

const row = loadSfc(path.join(ROOT, 'components/partials/OrderedProduct.vue'))

/*
 * Evaluate the VAT computeds against one ordered_products row, resolving the
 * dependencies between them the way Vue would.
 */
function view(orderedProduct) {
  const ctx = {
    orderedProduct,
    $t: (key) => key,
    get lineTotal() {
      return Number(orderedProduct.selling || 0) * Number(orderedProduct.quantity || 0)
    },
    get lineExVat() {
      return row.computed.lineExVat.call(ctx)
    },
    get lineIncVat() {
      return row.computed.lineIncVat.call(ctx)
    },
    get isVatInclusiveLine() {
      return row.computed.isVatInclusiveLine.call(ctx)
    },
  }

  return {
    primary: row.computed.lineTotalPrimary.call(ctx),
    counterpart: row.computed.lineVatCounterpart.call(ctx),
    side: row.computed.vatSideLabel.call(ctx),
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

const assert = require('assert')

/* An ex-VAT line: 674.00 x 3, VAT added on top at 23%. */
const EXCLUSIVE = {
  selling: 674, quantity: 3,
  ex_vat: 2022, inc_vat: 2487.06,
  vat_rate: 23, vat_inclusive: false, vat_known: true,
}

/* An inc-VAT line: the 1000 already contains its VAT. */
const INCLUSIVE = {
  selling: 1000, quantity: 1,
  ex_vat: 813.01, inc_vat: 1000,
  vat_rate: 23, vat_inclusive: true, vat_known: true,
}

/* Placed before tax_rate / tax_inclusive existed: treatment not recorded. */
const UNKNOWN = {
  selling: 500, quantity: 2,
  ex_vat: 1000, inc_vat: 1000,
  vat_rate: 0, vat_inclusive: null, vat_known: false,
}

console.log('\nadmin order line')

check('an ex-VAT line closes on its net total', () => {
  assert.strictEqual(view(EXCLUSIVE).primary, 2022)
})

check('an ex-VAT line states the gross total too', () => {
  assert.strictEqual(view(EXCLUSIVE).counterpart, 2487.06)
})

check('an inc-VAT line closes on the amount actually charged', () => {
  // The regression: this used to read 813.01 under a "Line total" header.
  assert.strictEqual(view(INCLUSIVE).primary, 1000)
})

check('an inc-VAT line shows no second figure', () => {
  assert.strictEqual(view(INCLUSIVE).counterpart, null)
})

check('an unrecorded treatment is never given a side', () => {
  assert.strictEqual(view(UNKNOWN).side, '')
})

check('an unrecorded treatment shows one figure, not a split', () => {
  const out = view(UNKNOWN)

  assert.strictEqual(out.primary, 1000)
  assert.strictEqual(out.counterpart, null, 'must not imply a split the order never stored')
})

check('vat_inclusive is ignored unless the order recorded the treatment', () => {
  // A stray inclusive flag on an order that never froze its VAT treatment
  // must not flip the row onto the gross figure.
  const stray = Object.assign({}, UNKNOWN, { vat_inclusive: true })

  assert.strictEqual(view(stray).counterpart, null)
  assert.strictEqual(view(stray).side, '')
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all admin VAT display checks passed\n')
