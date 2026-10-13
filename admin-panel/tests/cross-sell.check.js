/*
 * Exercises the Cross-sell admin screens without a browser or a build.
 *
 * Mounts the real SFCs with the bundled vue-template-compiler and jsdom and
 * stubs only what Nuxt injects, so what is under test is the components' own
 * behaviour: a refused save not being read as a success, the product list
 * reaching the payload in the order it was arranged, a promotion that offers
 * the product being unpickable, and the form refusing an empty name. A
 * compile check sees none of that.
 *
 * Run from admin-panel/: npm run check:cross-sell
 */const assert = require('assert')
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
Vue.prototype.$can = () => true
// Nuxt registers this globally; the components link to the promotion.
Vue.component('nuxt-link', {props: ['to'], template: '<a :href="to"><slot /></a>'})
Vue.prototype.$store = {
  getters: {
    get 'ui/errors' () { return storeErrors }
  },
  dispatch (action, payload) { toasts.push({action, payload}) }
}

// jsdom's confirm() is a stub that returns undefined, which would read as a
// cancelled removal.
global.window.confirm = () => true

// ============================================================================
//  The promotion form
// ============================================================================

let saveResult = {id: 7, name: 'saved'}
const saves = []

function mountForm (crossSell = null) {
  const options = loadSfc('components/partials/CrossSellForm.vue', {
    'vuex': {
      mapActions: () => ({
        setById (payload) {
          saves.push(payload)

          return Promise.resolve(saveResult)
        }
      }),
      mapGetters: () => ({errors () { return storeErrors }})
    },
    'vuedraggable': {name: 'draggable', template: '<tbody><slot /></tbody>'},
    '~/mixin/util': {methods: {getImageURL: image => '/img/' + (image || 'default')}},
    '~/components/ErrorFormatter': {name: 'ErrorFormatter', template: '<div />'},
    '~/components/PriceFormat': {name: 'PriceFormat', props: ['price'], template: '<span>{{ price }}</span>'},
    '~/components/partials/CrossSellPicker': {
      name: 'CrossSellPicker', props: ['excluded'], template: '<div class="picker-stub" />'
    }
  })

  const vm = new (Vue.extend(options))({propsData: {crossSell}})

  vm.$mount()

  return vm
}

const catalogueRow = (id, extra = {}) => Object.assign({
  id, title: 'Product ' + id, image: 'p.png', sku: 'SKU-' + id, selling: 100, offered: 80, in_stock: true
}, extra)

async function run () {
  console.log('\nCross-sell form')

  await asyncTest('a new promotion starts as a draft with nothing in it', async () => {
    const vm = mountForm()

    assert.strictEqual(vm.form.status, 3)
    assert.strictEqual(vm.form.max_products, 4)
    assert.deepStrictEqual(vm.form.products, [])
  })

  await asyncTest('it hydrates from an existing promotion', async () => {
    const vm = mountForm({
      id: 5, name: 'Laptop Accessories', status: 1, max_products: 6,
      products: [{product_id: 11, title: 'Bag'}, {product_id: 12, title: 'Mouse'}]
    })

    assert.strictEqual(vm.form.name, 'Laptop Accessories')
    assert.strictEqual(vm.form.max_products, 6)
    assert.deepStrictEqual(vm.form.products.map(r => r.product_id), [11, 12])
  })

  await asyncTest('an empty name is refused before anything is sent', async () => {
    saves.length = 0

    const vm = mountForm()

    await vm.save()

    assert.strictEqual(saves.length, 0, 'a nameless promotion was sent to the server')
    assert.ok(vm.nameError)
  })

  /* Rule 12: the same product twice is dropped here, not left to the save. */
  await asyncTest('picking a product already in the list does not add it twice', async () => {
    const vm = mountForm()

    vm.addPicked([catalogueRow(1), catalogueRow(2)])
    vm.addPicked([catalogueRow(2), catalogueRow(3)])

    assert.deepStrictEqual(vm.form.products.map(r => r.product_id), [1, 2, 3])
  })

  await asyncTest('the payload carries the ids in the arranged order and nothing about price', async () => {
    saves.length = 0
    saveResult = {id: 7, name: 'saved'}

    const vm = mountForm()

    vm.form.name = 'QA'
    vm.form.status = 1
    vm.addPicked([catalogueRow(1), catalogueRow(2), catalogueRow(3)])

    // The drag reversed them.
    vm.form.products.reverse()

    await vm.save()

    assert.strictEqual(saves.length, 1)
    assert.strictEqual(saves[0].api, 'setCrossSell')
    assert.deepStrictEqual(saves[0].params.products, [3, 2, 1])
    assert.ok(!JSON.stringify(saves[0].params).includes('selling'), 'a price reached the payload')
  })

  /*
   * The trap this panel sets: setById resolves with UNDEFINED when the
   * server refuses. Reading that as success is how a screen shows a saved
   * state that was never saved.
   */
  await asyncTest('a refused save is not read as a success', async () => {
    saveResult = undefined
    storeErrors = {products: ['These products are not published: Product 9']}
    toasts.length = 0

    const vm = mountForm()
    let saved = null
    vm.$on('saved', payload => { saved = payload })

    vm.form.name = 'QA'
    await vm.save()

    assert.strictEqual(saved, null, 'an undefined resolve was treated as a save')

    const toast = toasts.find(t => t.action === 'ui/setToastError')

    assert.ok(toast && String(toast.payload).includes('Product 9'), 'the refusal lost the product it names')
    saveResult = {id: 7, name: 'saved'}
  })

  await asyncTest('a successful save hands the promotion to the page', async () => {
    saveResult = {id: 9, name: 'Laptop Accessories'}

    const vm = mountForm()
    let saved = null
    vm.$on('saved', payload => { saved = payload })

    vm.form.name = 'QA'
    await vm.save()

    assert.strictEqual(saved && saved.id, 9)
  })

  // ==========================================================================
  //  The assignment section on a product
  // ==========================================================================

  console.log('\nCross-sell assignments')

  let assignmentsResult = {rows: []}
  let writeResult = null
  const writes = []
  const deletes = []

  const assignmentRow = (id, extra = {}) => Object.assign({
    id, cross_sell_id: 100 + id, sort_order: id, status: 1, name: 'Promotion ' + id,
    cross_sell_status: 1, products_count: 4, live: true
  }, extra)

  function mountAssignments (productId = 55) {
    const options = loadSfc('components/partials/CrossSellAssignments.vue', {
      'vuex': {
        mapActions: () => ({
          getById () { return Promise.resolve(assignmentsResult) },
          setById (payload) { writes.push(payload); return Promise.resolve(writeResult) },
          deleteData (payload) { deletes.push(payload); return Promise.resolve(assignmentsResult) }
        }),
        mapGetters: () => ({errors () { return storeErrors }})
      },
      'vuedraggable': {name: 'draggable', template: '<div><slot /></div>'},
      '~/mixin/util': {methods: {getImageURL: image => '/img/' + (image || 'default')}},
      '~/mixin/crossSellStatus': {methods: {statusLabel: s => 'status-' + s, statusClass: s => 'cls-' + s}},
      '~/components/SkeletonBlock': {name: 'SkeletonBlock', props: ['height'], template: '<div class="sk" />'},
      '~/components/partials/CrossSellAssignPicker': {
        name: 'CrossSellAssignPicker', props: ['productId', 'saving'], template: '<div class="picker-stub" />'
      }
    })

    const vm = new (Vue.extend(options))({propsData: {productId}})

    vm.$mount()

    return vm
  }

  const settle = async (vm) => { await vm.$nextTick(); await Promise.resolve(); await vm.$nextTick() }

  await asyncTest('an unsaved product is told to save rather than offered an assign', async () => {
    const vm = mountAssignments(null)
    await vm.$nextTick()

    assert.ok(vm.$el.innerHTML.includes(translate('crossSell.saveFirst')))
  })

  /* There is no create here, by design. */
  await asyncTest('the section offers assign and never create', async () => {
    assignmentsResult = {rows: []}

    const vm = mountAssignments()
    await settle(vm)

    const html = vm.$el.innerHTML

    assert.ok(html.includes(translate('crossSell.assignButton')))
    assert.ok(!html.includes(translate('crossSell.createButton')), 'the product page offers to create a promotion')
  })

  await asyncTest('assigning sends the promotion id to the product endpoint', async () => {
    writes.length = 0
    writeResult = {rows: [assignmentRow(1)]}

    const vm = mountAssignments(55)
    await settle(vm)

    vm.pickerOpen = true
    await vm.assign(101)

    assert.strictEqual(writes[0].api, 'assignCrossSell')
    assert.strictEqual(writes[0].id, 55)
    assert.deepStrictEqual(writes[0].params, {cross_sell_id: 101})
    assert.strictEqual(vm.pickerOpen, false, 'a successful assign left the picker open')
  })

  await asyncTest('a refused assign leaves the picker open and says why', async () => {
    writeResult = undefined
    storeErrors = {cross_sell_id: ['Laptop Accessories is already assigned to this product.']}
    toasts.length = 0

    const vm = mountAssignments(55)
    await settle(vm)

    vm.pickerOpen = true
    await vm.assign(101)

    assert.strictEqual(vm.pickerOpen, true)
    assert.ok(toasts.some(t => t.action === 'ui/setToastError' && String(t.payload).includes('already assigned')))
  })

  await asyncTest('a drag sends every assignment in screen order', async () => {
    writes.length = 0
    assignmentsResult = {rows: [assignmentRow(1), assignmentRow(2)]}
    writeResult = {rows: [assignmentRow(2), assignmentRow(1)]}

    const vm = mountAssignments(55)
    await settle(vm)

    vm.rows = [assignmentRow(2), assignmentRow(1)]
    await vm.persistOrder()

    assert.strictEqual(writes[0].api, 'updateCrossSellAssignments')
    assert.deepStrictEqual(writes[0].params.order, [2, 1])
  })

  await asyncTest('an assignment switched off but the promotion live says which switch is off', async () => {
    assignmentsResult = {rows: [assignmentRow(1, {status: 2, live: false})]}

    const vm = mountAssignments(55)
    await settle(vm)

    assert.strictEqual(vm.liveHint(vm.rows[0]), translate('crossSell.offHereHint'))
  })

  await asyncTest('an assignment on but the promotion in draft says so', async () => {
    assignmentsResult = {rows: [assignmentRow(1, {cross_sell_status: 3, live: false})]}

    const vm = mountAssignments(55)
    await settle(vm)

    assert.strictEqual(vm.liveHint(vm.rows[0]), translate('crossSell.promotionOffHint'))
  })

  await asyncTest('removing is scoped to the product and the assignment row', async () => {
    deletes.length = 0
    assignmentsResult = {rows: [assignmentRow(1)]}

    const vm = mountAssignments(55)
    await settle(vm)

    await vm.remove(vm.rows[0])

    assert.strictEqual(deletes[0].api, 'unassignCrossSell')
    assert.strictEqual(deletes[0].params, '55/1')
  })

  // ==========================================================================
  //  The assign picker
  // ==========================================================================

  console.log('\nAssign picker')

  let optionsPage = {data: [], last_page: 1}
  const optionRequests = []

  function mountAssignPicker (productId = 55) {
    const options = loadSfc('components/partials/CrossSellAssignPicker.vue', {
      'vuex': {mapActions: () => ({getRequest (p) { optionRequests.push(p); return Promise.resolve(optionsPage) }})},
      '~/mixin/crossSellStatus': {methods: {statusLabel: s => 'status-' + s, statusClass: s => 'cls-' + s}},
      '~/components/PopOver': {name: 'PopOver', props: ['title', 'elemId', 'layer', 'outsideClickOn'], template: '<div><slot name="content" /><slot name="pop-footer" /></div>'},
      '~/components/SkeletonBlock': {name: 'SkeletonBlock', props: ['height'], template: '<div class="sk" />'}
    })

    const vm = new (Vue.extend(options))({propsData: {productId}})
    vm.$mount()
    return vm
  }

  const option = (id, extra = {}) => Object.assign({
    id, name: 'Promotion ' + id, status: 1, products_count: 3, already_assigned: false, offers_this_product: false
  }, extra)

  await asyncTest('it asks the server for the options of THIS product', async () => {
    optionRequests.length = 0
    optionsPage = {data: [option(1)], last_page: 1}

    const vm = mountAssignPicker(55)
    await vm.$nextTick()

    assert.strictEqual(optionRequests[0].api, 'getCrossSellOptions')
    assert.strictEqual(optionRequests[0].params.product_id, 55)
  })

  /* Rule 12, both halves, unpickable but visible. */
  await asyncTest('an assigned or self-offering promotion is shown but cannot be picked', async () => {
    optionsPage = {data: [option(1, {already_assigned: true}), option(2, {offers_this_product: true}), option(3)], last_page: 1}

    const vm = mountAssignPicker()
    await settle(vm)

    assert.ok(vm.isTaken(vm.rows[0]) && vm.isTaken(vm.rows[1]) && !vm.isTaken(vm.rows[2]))

    const html = vm.$el.innerHTML

    assert.ok(html.includes(translate('crossSell.alreadyAssigned')))
    assert.ok(html.includes(translate('crossSell.offersThisProduct')))
    assert.strictEqual(vm.$el.querySelectorAll('input[type="radio"]:disabled').length, 2)
  })

  await asyncTest('confirming emits the one chosen id', async () => {
    optionsPage = {data: [option(1), option(2)], last_page: 1}

    const vm = mountAssignPicker()
    await settle(vm)

    let emitted = null
    vm.$on('picked', id => { emitted = id })

    vm.picked = '2'
    await vm.$nextTick()
    vm.$el.querySelector('.primary-btn').click()

    assert.strictEqual(emitted, 2)
  })

  console.log('\n' + passed + ' passed, ' + failed + ' failed\n')
  process.exit(failed ? 1 : 0)
}

run()
