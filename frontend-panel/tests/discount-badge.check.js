/*
 * What the "-N%" badge is allowed to say.
 *
 * Run with: npm run check:discount-badge
 *
 * The badge used to be worked out as
 *
 *     100 - parseInt(((now / previous) * 100).toString())
 *
 * in five separate places. parseInt truncates the RATIO, and truncating the
 * ratio rounds the DISCOUNT up, so the badge showed ceil() of the real figure
 * every time the division was not whole: a 15% clearance on a EUR 589.99
 * product advertised "-16% off". It never erred in the shop's favour -- it
 * always overstated the saving, which is the direction that matters when the
 * number is an advertisement.
 *
 * There is one rule now, productPriceHelper.discountPercent, and the two
 * components that had their own copy call it. These cases pin the rounding,
 * the guards, and the exact figure the sample clearance produces.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
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

const badge = (previous, now) => helper.methods.discountPercent.call(helper.methods, previous, now)

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

console.log('\nthe discount badge')

check('the sample clearance reads -15%, not -16%', () => {
  // 15% off EUR 589.99 is EUR 501.49 once the price is rounded to the cent.
  assert.strictEqual(badge(589.99, 501.49), 15)
})

check('a discount is rounded, not rounded up', () => {
  // 38.93% -> 39, 25.003% -> 25. The old formula gave 39 and 26.
  assert.strictEqual(badge(899, 549), 39)
  assert.strictEqual(badge(79.99, 59.99), 25)
})

check('an exact discount is itself', () => {
  assert.strictEqual(badge(100, 90), 10)
  assert.strictEqual(badge(1000, 900), 10)
  assert.strictEqual(badge(200, 100), 50)
})

check('a discount rounds to the nearest whole point in both directions', () => {
  assert.strictEqual(badge(100, 89.4), 11)   // 10.6 up
  assert.strictEqual(badge(100, 89.6), 10)   // 10.4 down
})

check('no badge when the price did not move', () => {
  assert.strictEqual(badge(100, 100), 0)
})

check('no badge when the price went up', () => {
  assert.strictEqual(badge(100, 120), 0)
})

check('no badge, and no NaN, when a figure is missing', () => {
  for (const [previous, now] of [
    [0, 50], [null, 50], [undefined, 50], ['', 50],
    [100, 0], [100, null], [100, undefined], [100, ''],
    [null, null],
  ]) {
    const value = badge(previous, now)
    assert.strictEqual(value, 0, `badge(${previous}, ${now}) was ${value}`)
    assert.ok(!Number.isNaN(value), `badge(${previous}, ${now}) was NaN`)
  }
})

check('a free item is 100% off, never more', () => {
  assert.strictEqual(badge(100, 0.01), 100)
})

check('strings are accepted, as the API sends them', () => {
  assert.strictEqual(badge('589.99', '501.49'), 15)
})

check('the tile computed uses the same rule', () => {
  const vm = {
    prevPrice: 589.99,
    currentPricing: 501.49,
    discountPercent: helper.methods.discountPercent,
  }
  assert.strictEqual(helper.computed.reducedPercent.call(vm), 15)
})

check('nothing still truncates the ratio', () => {
  const sources = [
    'mixin/productPriceHelper.js',
    'components/PastDealCard.vue',
    'pages/clearance.vue',
  ]

  /*
   * Comments stripped first: the helper's own docblock quotes the old formula
   * to explain what was wrong with it, and that is worth keeping.
   */
  const codeOnly = (text) => text
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')

  for (const file of sources) {
    const text = codeOnly(fs.readFileSync(path.join(ROOT, file), 'utf8'))
    assert.ok(
      !/100\s*-\s*parseInt/.test(text),
      `${file} has gone back to truncating the ratio`
    )
  }
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all discount badge checks passed\n')
