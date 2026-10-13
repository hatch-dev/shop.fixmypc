/*
 * Exercises Promotions -> Product Recommendations -> Upsell without a browser
 * or a build.
 *
 * Mounts the real SFCs with the bundled vue-template-compiler and jsdom and
 * stubs only what Nuxt injects, so what is under test is the components' own
 * behaviour: the wizard's step gating, the self-upgrade rule, the option order
 * reaching the payload, a refused save not being read as a success, and the
 * picker's variant handling. A compile check sees none of that.
 *
 * Run from admin-panel/: npm run check:upsell
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const {JSDOM} = require('jsdom')

const ROOT = path.resolve(__dirname, '..')

// ---- a DOM, before Vue is loaded -------------------------------------------

const dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>', {
  url: 'http://localhost/'
})

global.window = dom.window
global.document = dom.window.document
global.navigator = dom.window.navigator
global.Element = dom.window.Element
global.HTMLElement = dom.window.HTMLElement
global.SVGElement = dom.window.SVGElement
global.Node = dom.window.Node
global.getComputedStyle = dom.window.getComputedStyle

// jsdom does not implement it, and a component is right to call it.
global.window.scrollTo = () => {}

// The full build, not the runtime-only one: the stubs below carry templates.
const Vue = require('vue/dist/vue.common.dev.js')
const compiler = require('vue-template-compiler')

Vue.config.productionTip = false
Vue.config.devtools = false

const messages = require(path.join(ROOT, 'lang/en.json'))

// ---- loading an SFC without webpack ----------------------------------------

/**
 * Compile one .vue file into component options.
 *
 * `~/` means nothing outside the build, so every import is either handed a
 * stub or given an empty object. Destructured imports are unpacked by name,
 * because a stub for 'vuex' is a bag of named exports rather than a value.
 */
function loadSfc (relative, stubs = {}) {
  const parts = compiler.parseComponent(
    fs.readFileSync(path.join(ROOT, relative), 'utf8')
  )

  let body = parts.script.content
  const imports = []

  body = body.replace(/^[ \t]*import\s+(.+?)\s+from\s+['"](.+?)['"];?[ \t]*$/gm, (_, what, from) => {
    imports.push({what: what.trim(), from})
    return ''
  })
  body = body.replace(/^[ \t]*import\s+['"].+?['"];?[ \t]*$/gm, '')

  const names = []
  const values = []

  for (const entry of imports) {
    if (entry.what.startsWith('{')) {
      const bag = stubs[entry.from] || {}

      for (const key of entry.what.replace(/[{}]/g, '').split(',').map(k => k.trim())) {
        names.push(key)
        values.push(bag[key] !== undefined ? bag[key] : (() => ({})))
      }

      continue
    }

    names.push(entry.what)
    values.push(stubs[entry.from] !== undefined ? stubs[entry.from] : {})
  }

  const options = new Function(...names, body.replace(/export\s+default/, 'return'))(...values)

  if (parts.template) {
    options.template = parts.template.content
  }

  return options
}

/** A $t that renders the real string, so a missing key shows up as the key. */
function translate (key, params) {
  const value = key.split('.').reduce((all, part) => (all ? all[part] : undefined), messages)

  if (value === undefined) return key
  if (!params) return value

  return String(value).replace(/\{(\w+)\}/g, (_, name) =>
    params[name] === undefined ? `{${name}}` : params[name])
}

// ---- the tiny runner --------------------------------------------------------

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

async function asyncTest (name, fn) {
  try {
    await fn()
    console.log('  ok   ' + name)
    passed++
  } catch (e) {
    console.log('  FAIL ' + name + '\n         ' + e.message)
    failed++
  }
}

// ---- what Nuxt injects ------------------------------------------------------

const toasts = []
let storeErrors = {}

Vue.prototype.$t = translate
Vue.prototype.$store = {
  getters: {
    get 'ui/errors' () { return storeErrors }
  },
  dispatch (action, payload) { toasts.push({action, payload}) }
}

// ============================================================================
//  The wizard
// ============================================================================

const saves = []
let saveResult = {id: 7, name: 'saved'}

function mountWizard (campaign = null) {
  const options = loadSfc('components/partials/UpsellWizard.vue', {
    'vuex': {
      mapActions: () => ({
        setById (payload) {
          saves.push(payload)
          return Promise.resolve(saveResult)
        }
      })
    },
    '~/mixin/util': {
      methods: {getImageURL: image => '/img/' + (image || 'default')}
    },
    /*
     * The picker is a black box here. This suite is about the wizard's own
     * rules; the picker has its own section below.
     */
    '~/components/partials/UpsellProductPicker': {
      name: 'UpsellProductPicker',
      props: ['value', 'excluded', 'selectedTitle'],
      template: '<div class="picker-stub"></div>'
    }
  })

  const vm = new (Vue.extend(options))({propsData: {campaign}})

  vm.$mount()

  return vm
}

const pick = (id, extra = {}) => Object.assign({
  product_id: id,
  inventory_id: null,
  title: 'Product ' + id,
  image: 'p.png',
  variant: null
}, extra)

console.log('\nUpsell wizard')

test('a new wizard starts inactive and on step one', () => {
  const vm = mountWizard()

  assert.strictEqual(vm.step, 0)
  assert.strictEqual(Number(vm.form.status), 2, 'a new campaign must not be active')
})

test('step one refuses to advance without a name', () => {
  const vm = mountWizard()

  vm.next()

  assert.strictEqual(vm.step, 0, 'it advanced past an empty name')
  assert.ok(vm.error, 'no reason was shown')
})

test('step two refuses to advance with nothing selected', () => {
  const vm = mountWizard()

  vm.form.name = 'QA'
  vm.next()

  assert.strictEqual(vm.step, 1)

  vm.next()

  assert.strictEqual(vm.step, 1, 'it advanced past an empty selection')
})

test('a product offered as an upgrade to itself is refused', () => {
  const vm = mountWizard()

  vm.form.name = 'QA'
  vm.form.sources = [pick(10)]
  vm.form.options = [pick(10)]
  vm.step = 2

  vm.next()

  assert.strictEqual(vm.step, 2, 'a self-upgrade was accepted')
})

/*
 * The canonical upsell. 128GB -> 256GB is the same catalogue product on both
 * sides, so a naive "same product id" check would refuse the one case the
 * whole module exists for.
 */
test('one variant may be upgraded to another of the same product', () => {
  const vm = mountWizard()

  vm.form.name = 'QA'
  vm.form.sources = [pick(10, {inventory_id: 1, variant: '128GB'})]
  vm.form.options = [pick(10, {inventory_id: 2, variant: '256GB'})]
  vm.step = 2

  vm.next()

  assert.strictEqual(vm.step, 3, 'the 128GB -> 256GB upsell was refused')
})

test('a campaign showing nowhere is refused', () => {
  const vm = mountWizard()

  vm.form.name = 'QA'
  vm.form.sources = [pick(10)]
  vm.form.options = [pick(11)]
  vm.form.show_on_product_page = false
  vm.form.show_on_cart_page = false
  vm.form.show_on_checkout_page = false
  vm.step = 3

  vm.next()

  assert.strictEqual(vm.step, 3, 'a campaign with nowhere to show was accepted')
})

test('an end date before the start date is refused', () => {
  const vm = mountWizard()

  vm.form.name = 'QA'
  vm.form.sources = [pick(10)]
  vm.form.options = [pick(11)]
  vm.form.start_date = '2026-10-01T09:00'
  vm.form.end_date = '2026-09-01T09:00'
  vm.step = 3

  vm.next()

  assert.strictEqual(vm.step, 3, 'a backwards date range was accepted')
})

test('the arrows reorder the upgrade options', () => {
  const vm = mountWizard()

  vm.form.options = [pick(1), pick(2), pick(3)]
  vm.move(2, -1)

  assert.deepStrictEqual(vm.form.options.map(row => row.product_id), [1, 3, 2])
})

// ============================================================================
//  The product picker
// ============================================================================

const requests = []
let page = {data: [], last_page: 1}

function mountPicker (value = [], excluded = []) {
  const options = loadSfc('components/partials/UpsellProductPicker.vue', {
    'vuex': {
      mapActions: () => ({
        getRequest (payload) {
          requests.push(payload)
          return Promise.resolve(page)
        }
      }),
      mapGetters: () => ({
        allCategories () { return {1: {title: 'Phones'}} },
        allBrands () { return {2: {title: 'Apple'}} }
      })
    },
    '~/mixin/util': {
      methods: {getImageURL: image => '/img/' + (image || 'default')}
    },
    '~/components/PriceFormat': {
      name: 'PriceFormat',
      props: ['price'],
      template: '<span class="price">{{ price }}</span>'
    }
  })

  const vm = new (Vue.extend(options))({
    propsData: {value, excluded, selectedTitle: 'Selected'}
  })

  // The emitted list is applied back, the way v-model would.
  vm.$on('input', next => {
    vm.$props.value.splice(0, vm.$props.value.length, ...next)
  })

  vm.$mount()

  return vm
}

const catalogueRow = (id, variants = []) => ({
  id,
  title: 'Product ' + id,
  image: 'p.png',
  sku: 'SKU-' + id,
  category: 'Phones',
  brand: 'Apple',
  selling: 899,
  offered: 699,
  status: 1,
  in_stock: true,
  variants
})

// ============================================================================
//  Everything that has to await something
// ============================================================================

async function run () {
  console.log('\nUpsell wizard (saving)')

  await asyncTest('the on-screen order becomes the stored priority', async () => {
    saves.length = 0

    const vm = mountWizard()

    vm.form.name = 'QA'
    vm.form.sources = [pick(10)]
    vm.form.options = [pick(21), pick(22), pick(23)]

    await vm.save()

    assert.strictEqual(saves.length, 1, 'nothing was sent')

    assert.deepStrictEqual(
      saves[0].params.upgrade_options.map(row => [row.product_id, row.priority]),
      [[21, 1], [22, 2], [23, 3]]
    )
  })

  /*
   * No money on an upsell, anywhere. The pricing ladder stays the single
   * source of truth for what an upgrade costs; a figure sent from here would
   * go stale the moment the catalogue moved.
   */
  await asyncTest('the payload carries no price, discount or VAT', async () => {
    saves.length = 0

    const vm = mountWizard()

    vm.form.name = 'QA'
    vm.form.sources = [pick(10, {selling: 899, offered: 699})]
    vm.form.options = [pick(11, {selling: 999})]

    await vm.save()

    const sent = JSON.stringify(saves[0].params).replace(/minimum_cart_value/g, '')

    for (const word of ['price', 'discount', 'vat', 'selling', 'offered']) {
      assert.ok(
        !new RegExp('"[a-z_]*' + word, 'i').test(sent),
        'the payload mentions ' + word + ': ' + sent
      )
    }
  })

  /*
   * setById resolves with NOTHING when the server refused: a 201 goes to
   * ui/setErrors instead of throwing. Reading that as success would navigate
   * away from a campaign that was never written.
   */
  await asyncTest('a refused save is not treated as a success', async () => {
    saveResult = undefined
    storeErrors = {source_products: ['That product is not available.']}

    const vm = mountWizard()
    const emitted = []

    vm.$on('saved', payload => emitted.push(payload))

    vm.form.name = 'QA'
    vm.form.sources = [pick(10)]
    vm.form.options = [pick(11)]

    await vm.save()

    assert.strictEqual(emitted.length, 0, 'a refusal was emitted as a save')
    assert.ok(/not available/.test(vm.error), 'the reason was not shown: ' + vm.error)

    saveResult = {id: 7, name: 'saved'}
    storeErrors = {}
  })

  await asyncTest('saving from the review step re-checks the earlier ones', async () => {
    saves.length = 0

    const vm = mountWizard()

    // Walked to the end, then emptied step 2 by going back.
    vm.form.name = 'QA'
    vm.form.sources = []
    vm.form.options = [pick(11)]
    vm.step = 4
    vm.furthest = 4

    await vm.save()

    assert.strictEqual(saves.length, 0, 'an incomplete campaign was sent')
    assert.strictEqual(vm.step, 1, 'the admin was not returned to the broken step')
  })

  /*
   * The server stores "Y-m-d H:i:s"; a datetime-local input can only render
   * "Y-m-dTH:i". Without the conversion the field is blank and an admin saves
   * the dates away without noticing.
   */
  await asyncTest('an existing campaign loads its dates into the date inputs', async () => {
    const vm = mountWizard({
      id: 3,
      name: 'Existing',
      status: 1,
      customer_type: 'business',
      priority: 4,
      minimum_cart_value: 50,
      show_on_product_page: true,
      show_on_cart_page: false,
      show_on_checkout_page: false,
      start_date: '2026-10-01 09:30:00',
      end_date: '2026-11-01 18:00:00',
      sources: [{product_id: 10, inventory_id: null, title: 'A'}],
      options: [{product_id: 11, inventory_id: 2, title: 'B', variant: '256GB'}]
    })

    assert.strictEqual(vm.form.start_date, '2026-10-01T09:30')
    assert.strictEqual(vm.form.end_date, '2026-11-01T18:00')
    assert.strictEqual(vm.form.customer_type, 'business')
    assert.strictEqual(vm.form.options[0].inventory_id, 2)

    // Every step reachable, so a correction is one click.
    assert.strictEqual(vm.furthest, 4)
  })

  console.log('\nUpsell product picker')

  await asyncTest('it asks the server, paginated, on mount', async () => {
    requests.length = 0
    page = {data: [catalogueRow(1), catalogueRow(2)], last_page: 3}

    const vm = mountPicker()
    await vm.$nextTick()

    assert.strictEqual(requests.length, 1, 'it did not fetch')
    assert.strictEqual(requests[0].api, 'searchUpsellProducts')
    assert.strictEqual(requests[0].params.page, 1)
  })

  /*
   * Debounced, and to the SERVER. The catalogue runs to tens of thousands of
   * rows, so filtering the loaded page would answer the wrong question -- and
   * a query per keystroke is a LIKE over two tables, eight times.
   */
  await asyncTest('the search goes to the server, debounced', async () => {
    page = {data: [catalogueRow(1)], last_page: 1}

    const vm = mountPicker()
    await vm.$nextTick()

    requests.length = 0
    vm.term = 'iphone'
    vm.onTermChanged()

    assert.strictEqual(requests.length, 0, 'it fired on the keystroke')

    await new Promise(resolve => setTimeout(resolve, 420))

    assert.strictEqual(requests.length, 1, 'the debounced search never fired')
    assert.strictEqual(requests[0].params.q, 'iphone')
  })

  await asyncTest('ticking a product selects the whole product', async () => {
    page = {data: [catalogueRow(1)], last_page: 1}

    const vm = mountPicker([])
    await vm.$nextTick()

    vm.toggle(catalogueRow(1))

    assert.strictEqual(vm.value.length, 1)
    assert.strictEqual(vm.value[0].product_id, 1)
    assert.strictEqual(vm.value[0].inventory_id, null, 'a variant was assumed')
  })

  /*
   * Ticking a product and then choosing its 256GB means "that one", not "the
   * product AND the 256GB". Leaving both is how a campaign ends up offering
   * the same upgrade twice.
   */
  await asyncTest('choosing a variant replaces the pick rather than adding a second', async () => {
    const product = catalogueRow(1, [
      {id: 11, label: '128GB', sku: 'A', in_stock: true},
      {id: 12, label: '256GB', sku: 'B', in_stock: true}
    ])

    page = {data: [product], last_page: 1}

    const vm = mountPicker([])
    await vm.$nextTick()

    vm.toggle(product)
    vm.setVariant(product, '12')

    assert.strictEqual(vm.value.length, 1, 'the product and its variant were both kept')
    assert.strictEqual(vm.value[0].inventory_id, 12)
    assert.strictEqual(vm.value[0].variant, '256GB')
  })

  await asyncTest('a product the other step has taken is refused with a reason', async () => {
    toasts.length = 0

    const product = catalogueRow(1)
    page = {data: [product], last_page: 1}

    const vm = mountPicker([], [{product_id: 1, inventory_id: null}])
    await vm.$nextTick()

    vm.toggle(product)

    assert.strictEqual(vm.value.length, 0, 'the overlapping product was accepted')
    assert.ok(toasts.length, 'nothing was said about it')
  })

  await asyncTest('the same product is still pickable under a different variant', async () => {
    const product = catalogueRow(1, [
      {id: 11, label: '128GB', sku: 'A', in_stock: true},
      {id: 12, label: '256GB', sku: 'B', in_stock: true}
    ])

    page = {data: [product], last_page: 1}

    // The other step took the 128GB; the 256GB is a legitimate upgrade.
    const vm = mountPicker([], [{product_id: 1, inventory_id: 11}])
    await vm.$nextTick()

    vm.setVariant(product, '12')

    assert.strictEqual(vm.value.length, 1, 'the 128GB -> 256GB upsell was blocked')
    assert.strictEqual(vm.value[0].inventory_id, 12)
  })

  await asyncTest('removing a pill takes the product out', async () => {
    page = {data: [catalogueRow(1)], last_page: 1}

    const vm = mountPicker([{product_id: 1, inventory_id: null, title: 'Product 1'}])
    await vm.$nextTick()

    vm.removeAt({product_id: 1})

    assert.strictEqual(vm.value.length, 0)
  })

  await asyncTest('load more appends rather than replacing', async () => {
    page = {data: [catalogueRow(1), catalogueRow(2)], last_page: 2}

    const vm = mountPicker()
    await vm.$nextTick()

    page = {data: [catalogueRow(3)], last_page: 2}

    await vm.loadMore()

    assert.deepStrictEqual(vm.rows.map(row => row.id), [1, 2, 3])
  })

  /* Every column section 13 of the brief asks for. */
  await asyncTest('the table renders the columns the brief asks for', async () => {
    page = {
      data: [catalogueRow(1, [{id: 11, label: '128GB', sku: 'A', in_stock: true}])],
      last_page: 1
    }

    const vm = mountPicker()
    await vm.$nextTick()
    await Promise.resolve()
    await vm.$nextTick()

    const html = vm.$el.innerHTML

    for (const needle of ['Product 1', 'SKU-1', 'Phones', 'In stock', 'Published']) {
      assert.ok(html.includes(needle), 'the table is missing ' + needle)
    }

    assert.ok(html.includes('ups-picker-thumb'), 'no product image')
  })

  console.log('\n' + passed + ' passed, ' + failed + ' failed\n')
  process.exit(failed ? 1 : 0)
}

run()
