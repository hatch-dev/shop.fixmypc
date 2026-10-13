/*
 * What the clearance page's Buy Now button does.
 *
 * Run with: npm run check:clearance-buy
 *
 * It navigates to the product page, and does nothing else. This page is the
 * shop window -- it names the deal, the saving and the time left; the buying
 * happens on the product page, which applies the same deal price and carries
 * the variation picker.
 *
 * Two things it must never do again:
 *
 *   TAKE THE BUY-NOW PATH. It called addToCart(true), which posts to
 *   cart/buy-now and pushes straight into the checkout, so one click on a
 *   deal page carried the shopper into a payment flow with no basket step in
 *   between and no way back to the deal.
 *
 *   CHOOSE A VARIATION FOR ANYBODY. Adding from here meant picking a variation
 *   by stock level whenever the product had more than one. On ex-display stock
 *   that is the difference between "Excellent, Grey" and "Acceptable, Black".
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

const parsed = compiler.parseComponent(
  fs.readFileSync(path.join(ROOT, 'pages/clearance.vue'), 'utf8')
)

const code = babel.transformSync(parsed.script.content, {
  filename: 'clearance.vue',
  configFile: false,
  babelrc: false,
  presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
}).code

const module_ = { exports: {} }
// eslint-disable-next-line no-new-func
new Function('module', 'exports', 'require', code)(module_, module_.exports, () => ({
  mapGetters: () => ({}), mapActions: () => ({}), default: {},
}))

const page = module_.exports.default || module_.exports

/* The component, with the two things heroBuyNow reaches for recorded. */
function vm(state) {
  const calls = { addToCart: [], push: [] }

  const self = Object.assign({
    productInventory: { id: 104, quantity: 91 },
    productUrl: '/product/dell-latitude-3520',
    hero: { requires_option: false },
    addToCart(isBuyNow) {
      calls.addToCart.push(isBuyNow)
      // addToCart answers false when it refuses the line.
      return Promise.resolve(self.addRefused ? false : undefined)
    },
    $router: { push(to) { calls.push.push(to) } },
  }, state)

  Object.defineProperty(self, 'requiresOption', {
    get: page.computed.requiresOption.bind(self), configurable: true,
  })

  return { self, calls }
}

let failures = 0

const cases = []

/*
 * Collected and awaited below, not run here. heroBuyNow is async, so an
 * assertion inside one of these rejects a promise rather than throwing -- a
 * synchronous try/catch around fn() prints "ok" for a case that failed.
 */
function check(name, fn) {
  cases.push({ name: name, fn: fn })
}

console.log('\nthe clearance Buy Now button')

check('goes to the product page', async () => {
  const { self, calls } = vm({})

  await page.methods.heroBuyNow.call(self)

  assert.deepStrictEqual(calls.push, ['/product/dell-latitude-3520'])
})

check('adds nothing to the basket from this page', async () => {
  const { self, calls } = vm({})

  await page.methods.heroBuyNow.call(self)

  assert.deepStrictEqual(calls.addToCart, [],
    'the deal page put a line in the basket; buying happens on the product page')
})

check('a product needing a choice goes there too, and adds nothing', async () => {
  const { self, calls } = vm({ hero: { requires_option: true } })

  await page.methods.heroBuyNow.call(self)

  assert.deepStrictEqual(calls.push, ['/product/dell-latitude-3520'])
  assert.deepStrictEqual(calls.addToCart, [],
    'a variation was bought without the shopper choosing it')
})

check('nothing in stock goes there too, and adds nothing', async () => {
  const { self, calls } = vm({ productInventory: null })

  await page.methods.heroBuyNow.call(self)

  assert.deepStrictEqual(calls.push, ['/product/dell-latitude-3520'])
  assert.deepStrictEqual(calls.addToCart, [])
})

check('the button never promises a purchase it will not complete', () => {
  const template = parsed.template.content

  assert.ok(/requiresOption/.test(template),
    'the button does not distinguish a product that needs a choice')
  assert.ok(/clearance\.chooseOptions/.test(template),
    'there is no "choose options" label for a product that needs one')
})

;(async function () {
  for (const c of cases) {
    try {
      await c.fn()
      console.log('  ok   ' + c.name)
    } catch (err) {
      failures++
      console.log('  FAIL ' + c.name)
      console.log('       ' + err.message)
    }
  }

  console.log('')

  if (failures > 0) {
    console.log(failures + ' check(s) failed\n')
    process.exit(1)
  }

  console.log('all clearance buy checks passed\n')
})()
