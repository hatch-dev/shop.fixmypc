/*
 * The upgrade section on the product page, without a browser or a build.
 *
 * Mounts the real SFC with the bundled vue-template-compiler and jsdom and
 * stubs only what Nuxt injects. What is under test is the component's own
 * behaviour: that it reads its selection from the page rather than holding its
 * own, that it re-asks when the quantity or the account changes, that a signed
 * difference renders honestly, and that an unavailable option cannot be
 * chosen.
 *
 * Run from frontend-panel/: npm run check:product-upgrades
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const {JSDOM} = require('jsdom')

const ROOT = path.resolve(__dirname, '..')

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

const Vue = require('vue/dist/vue.common.dev.js')
const compiler = require('vue-template-compiler')

Vue.config.productionTip = false
Vue.config.devtools = false

const messages = require(path.join(ROOT, 'lang/en.json'))

/** Compile one .vue file into component options, stubbing its imports. */
function loadSfc (relative, stubs = {}) {
  const parts = compiler.parseComponent(fs.readFileSync(path.join(ROOT, relative), 'utf8'))

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

  if (parts.template) options.template = parts.template.content

  return options
}

function translate (key, params) {
  const value = key.split('.').reduce((all, part) => (all ? all[part] : undefined), messages)

  if (value === undefined) return key
  if (!params) return value

  return String(value).replace(/\{(\w+)\}/g, (_, name) =>
    params[name] === undefined ? `{${name}}` : params[name])
}

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

Vue.prototype.$t = translate

/* ---- the component under test -------------------------------------------- */

const requests = []
let answer = {groups: []}
let failNext = false

/*
 * Nuxt injects $axios and $auth before a component is created, and the
 * component's `immediate: true` watcher fires during init -- so both have to be
 * on the prototype, not assigned after mount, or the first load runs against
 * an undefined $axios.
 *
 * $auth is made observable because it is reactive under Nuxt too: the auth
 * module keeps the user in Vuex, which is how signing in mid-page re-quotes
 * the prices without a reload.
 */
Vue.prototype.$axios = {
  get (url, config) {
    requests.push({url, params: config && config.params})

    return failNext
      ? Promise.reject(new Error('network down'))
      : Promise.resolve({data: {data: answer}})
  }
}

Vue.prototype.$auth = Vue.observable({user: null})

function mount (props = {}) {
  const options = loadSfc('components/ProductUpgrades.vue')

  const vm = new (Vue.extend(options))({
    propsData: Object.assign({productId: 1, inventoryId: 10, quantity: 1}, props)
  })

  vm.$mount()

  return vm
}

/** One group, in the shape the API returns. */
const group = (options = []) => ({
  id: null,
  name: 'Storage Upgrade',
  attribute_id: 1,
  mode: 'automatic',
  display_order: 1,
  is_discovered: true,
  current: {
    inventory_id: 10,
    attribute_value_id: 4,
    label: '128GB',
    sku: 'A',
    price: 699,
    difference: 0,
    in_stock: true,
    backorder: false,
    available: true,
    quantity: 5
  },
  options
})

const option = (extra = {}) => Object.assign({
  inventory_id: 11,
  attribute_value_id: 5,
  label: '256GB',
  sku: 'B',
  price: 799,
  difference: 100,
  in_stock: true,
  backorder: false,
  available: true,
  quantity: 5,
  reason: null
}, extra)

/* Let the fetch promise chain and Vue's render queue both drain. */
const settle = async () => {
  for (let i = 0; i < 6; i++) {
    await new Promise(resolve => setTimeout(resolve, 5))
  }
}

async function run () {
  console.log('\nProduct upgrades')

  await test('it asks the server for the selected variant and quantity', async () => {
    requests.length = 0
    answer = {groups: [group([option()])]}

    const vm = mount({inventoryId: 10, quantity: 3})
    await settle()

    assert.strictEqual(requests.length, 1, 'it did not ask')
    assert.ok(requests[0].url.endsWith('/api/v1/product/1/upgrades'), requests[0].url)
    assert.strictEqual(requests[0].params.inventory_id, 10)
    assert.strictEqual(requests[0].params.quantity, 3)
  })

  await test('nothing renders when there is nothing to offer', async () => {
    answer = {groups: []}

    const vm = mount()
    await settle()
    await vm.$nextTick()

    // v-if on the root renders a comment placeholder, which has no
    // querySelector at all -- that is the section being absent rather than
    // present and empty.
    assert.strictEqual(vm.hasGroups, false)
    assert.strictEqual(vm.$el.nodeType, 8, 'the empty section rendered an element')
  })

  await test('it renders the current variant as Included and the upgrade as a price', async () => {
    answer = {groups: [group([option()])]}

    const vm = mount()
    await settle()
    await vm.$nextTick()

    const rows = [...vm.$el.querySelectorAll('.upgrade-row')].map(row => ({
      label: row.querySelector('.upgrade-label').textContent.trim(),
      price: row.querySelector('.accessories-upgrades-price-span').textContent.trim(),
      checked: row.querySelector('input').checked
    }))

    assert.strictEqual(rows.length, 2)
    assert.strictEqual(rows[0].price, 'Included')
    assert.strictEqual(rows[0].checked, true, 'the current variant was not ticked')
    assert.strictEqual(rows[1].price, '+€100.00')
    assert.strictEqual(rows[1].checked, false)
  })

  /*
   * A flash sale can make a bigger variant the same price or cheaper. Both are
   * real, and saying so is more honest than hiding a better deal.
   */
  await test('a free or cheaper upgrade is rendered honestly', async () => {
    answer = {groups: [group([
      option({label: '256GB', difference: 0}),
      option({inventory_id: 12, label: '512GB', difference: -50})
    ])]}

    const vm = mount()
    await settle()
    await vm.$nextTick()

    const prices = [...vm.$el.querySelectorAll('.upgrade-row .accessories-upgrades-price-span')]
      .map(el => el.textContent.trim())

    assert.deepStrictEqual(prices, ['Included', '+€0.00', '−€50.00'])
  })

  /*
   * The selection is read from the PAGE, never held here. That is what keeps
   * the section correct when the variant changes from anywhere else -- a
   * colour swatch, the back button, a refresh -- instead of showing a stale
   * tick beside the right price.
   */
  await test('the ticked option follows the page, not a local state', async () => {
    answer = {groups: [group([option(), option({inventory_id: 12, label: '512GB', difference: 300})])]}

    const vm = mount({inventoryId: 10})
    await settle()
    await vm.$nextTick()

    assert.strictEqual(vm.pendingFor(vm.groups[0]), null, 'a non-current variant was ticked')

    // The page moves onto the 256GB without telling the component directly.
    vm.$props.inventoryId = 11
    await settle()
    await vm.$nextTick()

    assert.strictEqual(vm.pendingFor(vm.groups[0]), 11)
  })

  await test('choosing an option emits the target rather than changing anything', async () => {
    answer = {groups: [group([option()])]}

    const vm = mount()
    await settle()
    await vm.$nextTick()

    const emitted = []
    vm.$on('select', payload => emitted.push(payload))

    vm.choose(vm.groups[0], vm.groups[0].options[0])

    assert.strictEqual(emitted.length, 1)
    assert.strictEqual(emitted[0].inventory_id, 11)

    // The component itself holds no selection: the page owns it.
    assert.strictEqual(Number(vm.inventoryId), 10)
  })

  await test('an unavailable option cannot be chosen', async () => {
    answer = {groups: [group([option({available: false, in_stock: false})])]}

    const vm = mount()
    await settle()
    await vm.$nextTick()

    const emitted = []
    vm.$on('select', payload => emitted.push(payload))

    vm.choose(vm.groups[0], vm.groups[0].options[0])

    assert.strictEqual(emitted.length, 0, 'a sold-out option was selectable')

    const row = vm.$el.querySelectorAll('.upgrade-row')[1]

    assert.ok(row.className.includes('is-unavailable'))
    assert.strictEqual(row.querySelector('input').disabled, true)
    assert.ok(/Out of stock/i.test(row.textContent), row.textContent.trim())
  })

  await test('reverting emits the variant the group started from', async () => {
    answer = {groups: [group([option()])]}

    const vm = mount({inventoryId: 11})
    await settle()
    await vm.$nextTick()

    const emitted = []
    vm.$on('select', payload => emitted.push(payload))

    vm.revert(vm.groups[0])

    assert.strictEqual(emitted[0].inventory_id, 10)
  })

  /*
   * A band boundary changes what BOTH sides of the difference cost, so an
   * answer is only true for the quantity it was asked about.
   */
  await test('changing the quantity re-asks the server', async () => {
    answer = {groups: [group([option()])]}

    const vm = mount({quantity: 1})
    await settle()

    requests.length = 0
    vm.$props.quantity = 10
    await settle()

    assert.strictEqual(requests.length, 1, 'the quantity change was not re-quoted')
    assert.strictEqual(requests[0].params.quantity, 10)
  })

  /*
   * Signing in, or being approved for trade, changes what every variant costs.
   * A stale "+€100" beside a freshly banded price is the sort of disagreement
   * a customer notices at the till.
   */
  await test('signing in re-asks the server', async () => {
    answer = {groups: [group([option()])]}

    const vm = mount()
    await settle()

    requests.length = 0

    Vue.prototype.$auth.user = {id: 7, account_type: 'business', trade_status: null}

    await settle()

    assert.ok(requests.length >= 1, 'the account change was not re-quoted')

    Vue.prototype.$auth.user = null
    await settle()
  })

  await test('a failed lookup hides the section rather than breaking the page', async () => {
    answer = {groups: [group([option()])]}

    const vm = mount()
    await settle()
    await vm.$nextTick()

    assert.strictEqual(vm.hasGroups, true)

    failNext = true
    vm.$props.inventoryId = 99

    await settle()

    assert.strictEqual(vm.hasGroups, false)
    assert.strictEqual(vm.groups.length, 0)

    failNext = false
  })

  console.log('\n' + passed + ' passed, ' + failed + ' failed\n')
  process.exit(failed ? 1 : 0)
}

run()
