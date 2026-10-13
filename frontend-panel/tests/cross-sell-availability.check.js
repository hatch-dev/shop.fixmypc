/*
 * Cross-sell availability and the shape of the block, without a browser.
 *
 * Run with: npm run check:cross-sell-availability
 *
 * The companion to cross-sell.check.js, which covers the store and the modal.
 * This one covers the two PAGES that render recommendations -- the product
 * page's "Frequently bought together" strip and the basket page's "You might
 * also like" block -- and the rules about what a customer may be shown:
 *
 *   - nothing unavailable is ever drawn, and the server alone decides what
 *     that means;
 *   - a section with nothing in it is not a section;
 *   - the skeleton is for the first load, not for every requote;
 *   - a tick survives a price requote but not the product selling out;
 *   - the add is proved again server-side, and a refusal is said out loud.
 *
 * The page components are far too large to mount -- between them they pull in
 * thirty components, five mixins and the router. Their computed properties and
 * methods are plain functions of `this`, so they are called against a stub
 * instead: the real code, none of the scaffolding.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const compiler = require(path.join(NM, 'vue-template-compiler'))
const babel = require(path.join(NM, '@babel/core'))

/* ------------------------------------------------------ loading a page */

/**
 * The options object out of a .vue file, with every import stubbed.
 *
 * Nothing is mounted, so the stubs only have to be importable. The template is
 * still compiled, because a computed property that no longer matches the
 * markup reading it is exactly the drift these checks exist to catch.
 */
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

const product = loadPage('pages/_main/product/_id.vue')
const cart = loadPage('pages/cart.vue')

/* Call a computed property against a stand-in component. */
function computed (options, name, ctx) {
  assert.ok(options.computed[name], 'no computed "' + name + '"')

  return options.computed[name].call(ctx)
}

/* A recommendation as the server sends one -- already proved buyable. */
function card (id, overrides) {
  return Object.assign({
    id: id,
    name: 'Accessory ' + id,
    slug: 'accessory-' + id,
    image: 'a.png',
    inventory_id: 500 + id,
    cross_sell_id: 9,
    price: 25,
    old_price: 30,
    requires_option: false,
    options: [],
    in_stock: true,
    backorder: false,
  }, overrides || {})
}

/* ------------------------------------------------------------ the runner */

let passed = 0
let failed = 0

async function test (name, fn) {
  try {
    await fn()
    console.log('  ok   ' + name)
    passed++
  } catch (e) {
    console.log('  FAIL ' + name + '\n         ' + e.message)
    failed++
  }
}

async function run () {
  console.log('\nthe product page: what is shown')

  /*
   * Rule 7. The server has already dropped everything unbuyable, so an empty
   * list is not something to apologise for -- it is the answer that there is
   * no section. The old block rendered on `main_product` alone, which put a
   * "Frequently bought together" heading over the single product the customer
   * was already looking at.
   */
  await test('no recommendations means no section at all', () => {
    const ctx = { productData: { main_product: { id: 1 }, cross_sell: [] } }

    assert.strictEqual(computed(product, 'hasCrossSells', ctx), false)
  })

  await test('one recommendation is enough for a section', () => {
    const ctx = { productData: { main_product: { id: 1 }, cross_sell: [card(2)] } }

    assert.strictEqual(computed(product, 'hasCrossSells', ctx), true)
  })

  await test('a product that never answered has no section', () => {
    const ctx = { productData: { main_product: null, cross_sell: [card(2)] } }

    assert.strictEqual(computed(product, 'hasCrossSells', ctx), false)
  })

  console.log('\nthe product page: loading')

  /*
   * Rule 6. A skeleton on the first request stops the page jumping when the
   * block lands; a skeleton on EVERY request is the "Loading... products...
   * Loading..." flicker the same rule forbids, and it would replace a block
   * the customer is reading with grey bars every time they touched the
   * quantity box.
   */
  await test('the first load paints a skeleton', () => {
    assert.strictEqual(
      computed(product, 'crossSellPending', { loading: true, crossSellLoaded: false }),
      true
    )
  })

  await test('a requote leaves the block on screen', () => {
    assert.strictEqual(
      computed(product, 'crossSellPending', { loading: true, crossSellLoaded: true }),
      false
    )
  })

  /*
   * Rule 15. A block that could not be built is absent, not shimmering: the
   * skeleton must never become the permanent state of a failed request.
   */
  await test('a failed load shimmers nowhere', () => {
    assert.strictEqual(
      computed(product, 'crossSellPending', { loading: false, crossSellLoaded: true }),
      false
    )
    assert.strictEqual(
      computed(product, 'hasCrossSells', { productData: { main_product: null, cross_sell: [] } }),
      false
    )
  })

  console.log('\nthe product page: the ticks')

  /*
   * Changing the quantity requotes, and the requote used to clear every tick:
   * the customer's chosen accessories silently vanished and the total dropped
   * back to the product on its own.
   */
  await test('ticks survive a requote', () => {
    const ctx = {
      productData: { cross_sell: [card(2), card(3)], bundle_deal: [] },
      selectedItems: [2, 3],
      selectedBundleIds: [],
      // Held in data() by the component; a row with no choice to make has
      // no entry here, which is what these two cards are.
      crossSellChoice: {},
    }

    product.methods.pruneCrossSellSelection.call(ctx)

    assert.deepStrictEqual(ctx.selectedItems, [2, 3])
  })

  /*
   * Rule 5. Something that sold out while the page was open is gone from the
   * next answer, so its tick goes with it -- the total stops including
   * something that can no longer be bought, rather than the customer finding
   * out at the till.
   */
  await test('a recommendation that sold out loses its tick', () => {
    const ctx = {
      productData: { cross_sell: [card(2)], bundle_deal: [] },
      selectedItems: [2, 3],
      selectedBundleIds: [],
      crossSellChoice: {},
    }

    product.methods.pruneCrossSellSelection.call(ctx)

    assert.deepStrictEqual(ctx.selectedItems, [2])
  })

  /*
   * Rule 14. A product with a real choice cannot be taken from this strip at
   * all -- there is no room for a picker in a one-line row, and the server
   * refuses it without one.
   */
  await test('a product needing a choice is never left ticked', () => {
    const ctx = {
      productData: {
        cross_sell: [card(2, { requires_option: true, inventory_id: null })],
        bundle_deal: [],
      },
      selectedItems: [2],
      selectedBundleIds: [],
      // Nothing chosen: the row demands a variant and has not been given one.
      crossSellChoice: {},
    }

    product.methods.pruneCrossSellSelection.call(ctx)

    assert.deepStrictEqual(ctx.selectedItems, [])
  })

  /*
   * The pictures, the total and the basket have to agree about which products
   * are ticked. They used to compare ids differently -- Number(item.id) for
   * the thumbnails, a raw `item.id` for what was sent -- so a string id from
   * the API could show a product in the bundle picture and leave it out of
   * the basket.
   */
  await test('what is sent is what the total was built from', () => {
    const ctx = {
      productData: { cross_sell: [card(2), card(3)], bundle_deal: [] },
      selectedItems: [3],
      selectedBundles: [],
      crossSellChoice: {},
    }

    // selectedCrossSell asks the component which variant each row is on.
    ctx.chosenOption = product.methods.chosenOption.bind(ctx)

    ctx.selectedCrossSell = computed(product, 'selectedCrossSell', ctx)

    const extras = product.methods.getCartExtras.call(ctx)

    assert.deepStrictEqual(extras.cross_sell.map(i => i.id), [3])
  })

  console.log('\nthe product page: taking a recommendation')

  /*
   * Rule 9. Between the block being drawn and the button being pressed the
   * product can sell out, so the add goes to the endpoint that proves it
   * again -- and it goes with no price, because the server prices every line
   * from the catalogue and would ignore one anyway.
   */
  await test('the add is proved again, and carries no price', async () => {
    const sent = []

    const ctx = {
      product: { id: 7 },
      langCode: 'en',
      $axios: {
        post: async (url, body) => {
          sent.push({ url: url, body: body })

          return { data: { status: 200, data: { added: [{ product_id: 2 }], skipped: [] } } }
        },
      },
      $store: { dispatch: async () => {} },
      $t: key => key,
    }

    const refused = await product.methods.addCrossSellLines.call(ctx, [card(2)], 'tok')

    assert.deepStrictEqual(refused, [])
    assert.ok(sent[0].url.indexOf('api/v1/cart/cross-sell') !== -1, sent[0].url)
    assert.strictEqual(sent[0].body.primary_product_id, 7)
    assert.deepStrictEqual(sent[0].body.products, [
      { product_id: 2, inventory_id: 502, quantity: 1 },
    ])
    assert.ok(!('price' in sent[0].body.products[0]), 'a price was sent')
  })

  await test('a line the server refused comes back as a reason', async () => {
    const ctx = {
      product: { id: 7 },
      langCode: 'en',
      $axios: {
        post: async () => ({
          data: {
            status: 200,
            data: {
              added: [{ product_id: 2 }],
              skipped: [{ product_id: 3, message: 'Sold out' }],
            },
          },
        }),
      },
      $store: { dispatch: async () => {} },
      $t: key => key,
    }

    const refused = await product.methods.addCrossSellLines.call(ctx, [card(2), card(3)], 'tok')

    assert.deepStrictEqual(refused.map(r => r.message), ['Sold out'])
  })

  /*
   * Nothing added at all arrives as HTTP 201 with the reason in the body --
   * axios reads that as success, so it has to be read out of `status` rather
   * than from the request having thrown.
   */
  await test('nothing added at all is a reason, not a success', async () => {
    const ctx = {
      product: { id: 7 },
      langCode: 'en',
      $axios: {
        post: async () => ({
          data: {
            status: 201,
            message: 'This product is no longer available.',
            data: { skipped: [] },
          },
        }),
      },
      $store: { dispatch: async () => {} },
      $t: key => key,
    }

    const refused = await product.methods.addCrossSellLines.call(ctx, [card(2)], 'tok')

    assert.strictEqual(refused.length, 1)
    assert.strictEqual(refused[0].message, 'This product is no longer available.')
  })

  /*
   * Rule 15 at the other end: the primary product is already in the basket by
   * the time this runs, so a thrown request is reported as the accessories
   * not going in -- never as the add having failed.
   */
  await test('a thrown add does not take the basket down with it', async () => {
    const ctx = {
      product: { id: 7 },
      langCode: 'en',
      $axios: { post: async () => { throw new Error('offline') } },
      $store: { dispatch: async () => {} },
      $t: () => 'could not add',
    }

    const refused = await product.methods.addCrossSellLines.call(ctx, [card(2)], 'tok')

    assert.strictEqual(refused.length, 1)
  })

  /*
   * Rule 15, as a shape rather than a behaviour.
   *
   * Accessories & Upgrades and the Essentials Bundle share the card with the
   * cross-sell strip but have nothing to do with it. Nesting them inside the
   * cross-sell branch would mean a recommendation request that failed, or
   * simply returned nothing, silently took two unrelated sections off the
   * page with it -- and it is exactly the kind of thing a later tidy-up does
   * without noticing.
   */
  await test('upgrades and bundles do not live inside the cross-sell branch', () => {
    const src = fs.readFileSync(
      path.join(ROOT, 'pages/_main/product/_id.vue'), 'utf8'
    ).replace(/\r\n/g, '\n')

    const open = src.indexOf('<template v-else-if="hasCrossSells">')

    assert.ok(open !== -1, 'the cross-sell branch is not where it was')

    /*
     * Balanced, not "the next </template>": the thumbnail row nests a
     * <template v-for> of its own, and stopping at that one would leave most
     * of the branch unexamined and the check quietly passing.
     */
    const tags = /<template\b|<\/template>/g

    tags.lastIndex = open

    let depth = 0
    let close = -1
    let match

    while ((match = tags.exec(src)) !== null) {
      depth += match[0] === '</template>' ? -1 : 1

      if (depth === 0) {
        close = match.index
        break
      }
    }

    assert.ok(close !== -1, 'the cross-sell branch is never closed')

    const branch = src.slice(open, close)

    assert.ok(
      branch.indexOf('<product-upgrades') === -1,
      'Accessories & Upgrades is inside the cross-sell branch, so an empty '
        + 'or failed recommendation request would hide it'
    )
    assert.ok(
      branch.indexOf('essentials-bundle-div') === -1,
      'the Essentials Bundle is inside the cross-sell branch'
    )
  })

  console.log('\nthe product page: the Essentials Bundle')

  /* A bundle as the server sends one: already complete and already priced. */
  const bundle = (id, overrides) => Object.assign({
    id: id,
    title: 'Essentials Bundle',
    description: 'Everything you need.',
    price: 29,
    old_price: 99,
    savings: 70,
    discount_type: 'percentage',
    discount_value: 10,
    items: [
      {id: 901, name: 'Case', image: 'c.png', inventory_id: 11, price: 9, old_price: 29},
      {id: 902, name: 'Cable', image: 'k.png', inventory_id: 12, price: 20, old_price: 70}
    ]
  }, overrides || {})

  /*
   * The checkbox carried :value="bundle.id" against a BOOLEAN v-model, and
   * Vue ignores the value on a non-array model -- so with two bundles
   * assigned, ticking either ticked both and the page could not say which.
   */
  await test('ticking one bundle does not tick the others', () => {
    const ctx = {
      productData: {bundle_deal: [bundle(1), bundle(2)]},
      selectedBundleIds: [2]
    }

    assert.deepStrictEqual(
      computed(product, 'selectedBundles', ctx).map(b => b.id), [2]
    )
  })

  /*
   * `bundle_deal` is a LIST. The total read `.final_price` straight off the
   * array, which is undefined, so ticking the bundle turned the whole strip
   * into "€NaN".
   */
  await test('a ticked bundle adds its price to the total, not NaN', () => {
    const ctx = {
      productData: {
        main_product: {price: 1000, old_price: 1100},
        cross_sell: [],
        bundle_deal: [bundle(1)]
      },
      bundleMainQuantity: 1,
      selectedCrossSell: [],
      selectedBundles: [bundle(1)],
      selectedBundle: undefined
    }

    const total = computed(product, 'totalPrice', ctx)

    assert.ok(!Number.isNaN(Number(total)), `total was ${total}`)
    assert.strictEqual(total, '1029.00')
  })

  await test('an unticked bundle costs nothing', () => {
    const ctx = {
      productData: {
        main_product: {price: 1000, old_price: 1100},
        cross_sell: [],
        bundle_deal: [bundle(1)]
      },
      bundleMainQuantity: 1,
      selectedCrossSell: [],
      selectedBundles: []
    }

    assert.strictEqual(computed(product, 'totalPrice', ctx), '1000.00')
  })

  /* The button says how many things it is about to add. */
  await test('the item count includes everything in the bundle', () => {
    const ctx = {
      productData: {main_product: {price: 10}, bundle_deal: [bundle(1)]},
      bundleMainQuantity: 1,
      selectedCrossSell: [],
      selectedBundles: [bundle(1)],
      selectedBundleItemCount: 2
    }

    assert.strictEqual(computed(product, 'bundleItemCount', ctx), 3)
  })

  await test('the saving counts the bundle it was quoted on', () => {
    const ctx = {
      productData: {main_product: {price: 1000, old_price: 1100}},
      bundleMainQuantity: 1,
      selectedCrossSell: [],
      selectedBundles: [bundle(1)]
    }

    // 1100 for the phone at list, plus 99 for the bundle at list.
    assert.strictEqual(computed(product, 'bundleOriginalTotal', ctx), 1199)
  })

  /*
   * A bundle is all or nothing, and the server drops it whole the moment an
   * item stops being buyable -- so a tick for a bundle that is no longer in
   * the answer has to go with it, or the total quotes a price the basket
   * would refuse.
   */
  await test('a bundle that went incomplete loses its tick', () => {
    const ctx = {
      productData: {cross_sell: [], bundle_deal: [bundle(2)]},
      selectedItems: [],
      selectedBundleIds: [1, 2],
      crossSellChoice: {},
    }

    product.methods.pruneCrossSellSelection.call(ctx)

    assert.deepStrictEqual(ctx.selectedBundleIds, [2])
  })

  /*
   * The struck-through figure is only drawn when the catalogue actually gives
   * that saving -- never as decoration beside an equal price.
   */
  await test('no saving means no struck-through bundle price', () => {
    assert.strictEqual(product.methods.bundleHasSaving.call(null, bundle(1)), true)
    assert.strictEqual(
      product.methods.bundleHasSaving.call(null, bundle(1, {savings: 0})), false
    )
  })

  await test('the bundle price shown is the server figure', () => {
    assert.strictEqual(product.methods.bundlePrice.call(null, bundle(1)), '29.00')
    assert.strictEqual(product.methods.bundleSellingPrice.call(null, bundle(1)), '99.00')
  })

  /*
   * The add sends the bundle id and the product it was offered under, and
   * nothing else. Sending the items or the price would let a stale page talk
   * the server into a different set or a different total.
   */
  await test('taking a bundle sends only what the server should be told', async () => {
    const sent = []

    const ctx = {
      product: {id: 7},
      langCode: 'en',
      $axios: {
        post: async (url, body) => {
          sent.push({url: url, body: body})

          return {data: {status: 200, data: {bundle_id: 1, added: []}}}
        }
      },
      $store: {dispatch: async () => {}},
      $t: key => key
    }

    const refusal = await product.methods.addBundle.call(ctx, bundle(1), 'tok')

    assert.strictEqual(refusal, null)
    assert.ok(sent[0].url.indexOf('api/v1/cart/bundle') !== -1, sent[0].url)
    assert.deepStrictEqual(sent[0].body, {
      user_token: 'tok', product_id: 7, bundle_id: 1
    })
  })

  await test('a refused bundle comes back as a reason, not a success', async () => {
    const ctx = {
      product: {id: 7},
      langCode: 'en',
      $axios: {
        post: async () => ({
          data: {status: 201, message: 'That bundle is no longer available.'}
        })
      },
      $store: {dispatch: async () => {}},
      $t: key => key
    }

    const refusal = await product.methods.addBundle.call(ctx, bundle(1), 'tok')

    assert.ok(refusal)
    assert.strictEqual(refusal.message, 'That bundle is no longer available.')
  })

  await test('the ticked bundles are what gets sent', () => {
    const ctx = {
      productData: {cross_sell: [], bundle_deal: [bundle(1), bundle(2)]},
      selectedItems: [],
      selectedCrossSell: [],
      selectedBundles: [bundle(2)]
    }

    assert.deepStrictEqual(
      product.methods.getCartExtras.call(ctx).bundles.map(b => b.id), [2]
    )
  })

  console.log('\nthe basket page')

  /*
   * Both figures are the server's, priced for this customer against the exact
   * variant. The old helpers guessed across `offered_price || price ||
   * current_price` and read the "was" from a product-level `selling_price`.
   */
  await test('the price and the was are the server own figures', () => {
    const item = card(2, { price: 19.5, old_price: 24 })

    assert.strictEqual(cart.methods.getUpsellPrice.call(cart.methods, item), 19.5)
    assert.strictEqual(cart.methods.getOriginalPrice.call(cart.methods, item), 24)
    assert.strictEqual(cart.methods.hasDiscount.call(cart.methods, item), true)
  })

  await test('no saving means nothing struck through', () => {
    const item = card(2, { price: 25, old_price: 25 })

    assert.strictEqual(cart.methods.hasDiscount.call(cart.methods, item), false)
  })

  /*
   * "Add Bundle" adds things without asking anything, so anything still
   * needing a variant chosen has to be left out of it -- counting it would
   * advertise a saving on something the button cannot buy.
   */
  await test('Add Bundle skips anything needing a choice', () => {
    const ctx = {
      bundleProducts: [
        card(2),
        card(3, { requires_option: true, inventory_id: null }),
        card(4, { addedToCart: true }),
      ],
    }

    assert.deepStrictEqual(computed(cart, 'bundleRemaining', ctx).map(i => i.id), [2])
  })

  /*
   * The server already drops what the basket holds; this is the second pass
   * that keeps the popup and the block on the page showing one list.
   */
  await test('what is already in the basket is not offered again', () => {
    const ctx = {
      cartProducts: [{ product_id: 3 }],
      upsellProducts: [card(2), card(3)],
    }

    assert.deepStrictEqual(computed(cart, 'bundleProducts', ctx).map(i => i.id), [2])
  })

  console.log('')
  console.log(passed + ' passed, ' + failed + ' failed')

  process.exit(failed ? 1 : 0)
}

run()
