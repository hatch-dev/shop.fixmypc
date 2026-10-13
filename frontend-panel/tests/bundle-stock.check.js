/*
 * Stock on the browser side, and whether it says the same thing the server
 * does. Run with: npm run check:bundle-stock
 *
 * The companion to tests/Feature/BundleStockTest.php, which proves the server
 * refuses what it should. This proves the UI does not OFFER what the server
 * would refuse -- because a stepper that lets a customer ask for twelve of a
 * two-unit line turns a clean refusal into a dead end.
 *
 * The rule both sides must share, from Pricing::isPurchasable and
 * CartsController::stockError:
 *
 *   is_active === 1  ->  backorder allowed, any quantity
 *   otherwise        ->  at most `quantity`
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

    return { default: {}, __esModule: true }
  }

  const module = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, stub)

  return module.exports.default || module.exports
}

const tile = loadPage('components/CartProductTile.vue')
const product = loadPage('pages/_main/product/_id.vue')

function computed (options, name, ctx) {
  assert.ok(options.computed[name], 'no computed "' + name + '"')

  return options.computed[name].call(ctx)
}

/** What the server would allow for this variant, per stockError. */
function serverAllows (inventory, wanted) {
  if (parseInt(inventory.is_active) === 1) {
    return true
  }

  return wanted <= parseInt(inventory.quantity || 0)
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

console.log('\nthe basket stepper and the server agree')

test('a stocked line is capped at the shelf', () => {
  const ctx = { productInventory: { quantity: 2, is_active: 0 } }

  assert.strictEqual(computed(tile, 'maxQuantity', ctx), 2)
})

test('a backorder line is not capped', () => {
  const ctx = { productInventory: { quantity: 0, is_active: 1 } }

  assert.strictEqual(computed(tile, 'maxQuantity', ctx), 9999)
})

test('a sold-out line cannot be stepped up at all', () => {
  const ctx = { productInventory: { quantity: 0, is_active: 0 } }

  assert.strictEqual(computed(tile, 'maxQuantity', ctx), 0)
})

test('a missing inventory is treated as nothing, not as unlimited', () => {
  assert.strictEqual(computed(tile, 'maxQuantity', { productInventory: null }), 0)
})

test('the cap never offers what the server would refuse', () => {
  const shelves = [
    { quantity: 0, is_active: 0 },
    { quantity: 1, is_active: 0 },
    { quantity: 2, is_active: 0 },
    { quantity: 50, is_active: 0 },
    { quantity: 0, is_active: 1 },
    { quantity: 5, is_active: 1 },
  ]

  shelves.forEach((inventory) => {
    const cap = computed(tile, 'maxQuantity', { productInventory: inventory })

    assert.ok(serverAllows(inventory, cap),
      'the stepper allows ' + cap + ' where the server refuses it, for '
      + JSON.stringify(inventory))
  })
})

console.log('\nthe product page: a bundle that stops being buyable')

test('a bundle the server no longer offers loses its tick', () => {
  const ctx = {
    productData: { cross_sell: [], bundle_deal: [{ id: 2, items: [] }] },
    selectedItems: [],
    selectedBundleIds: [1, 2],
    // The component keeps this in data(); pruneCrossSellSelection reads it
    // to decide whether a row's chosen variant is still buyable.
    crossSellChoice: {},
  }

  product.methods.pruneCrossSellSelection.call(ctx)

  assert.deepStrictEqual(ctx.selectedBundleIds, [2],
    'a sold-out bundle stayed ticked, so its price stayed in the strip')
})

test('nothing offered means no Essentials Bundle section', () => {
  assert.strictEqual(
    product.computed.hasBundleDeals.call({ productData: { bundle_deal: [] } }),
    false
  )
})


console.log('\nthe bundle card names the variant it is selling')

test('a variation product is named with its variant', () => {
  const bundle = {
    items: [
      { id: 1, name: 'Apple iPhone 15 Pro', variant: '128GB / Midnight' },
      { id: 2, name: 'HOCO CA74 Magnetic Car Phone Holder', variant: null },
    ],
  }

  assert.strictEqual(
    product.methods.bundleItemsText.call(null, bundle),
    'Apple iPhone 15 Pro (128GB / Midnight), HOCO CA74 Magnetic Car Phone Holder'
  )
})

test('an all-simple bundle reads exactly as it did before', () => {
  const bundle = {
    items: [
      { id: 1, name: 'USB-C Cable', variant: null },
      { id: 2, name: 'Car Holder', variant: null },
    ],
  }

  assert.strictEqual(
    product.methods.bundleItemsText.call(null, bundle),
    'USB-C Cable, Car Holder'
  )
})

test('an older payload with no variant key does not print "undefined"', () => {
  const bundle = { items: [{ id: 1, name: 'USB-C Cable' }] }

  assert.strictEqual(product.methods.bundleItemsText.call(null, bundle), 'USB-C Cable')
})

console.log('')
console.log(passed + ' passed, ' + failed + ' failed')

process.exit(failed ? 1 : 0)
