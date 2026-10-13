/*
 * The product Upgrades tab, without a browser or a build.
 *
 * The cases here are the ones that cost a working feature rather than a
 * rendering glitch. Upgrades run automatically with no configuration, and
 * saving REPLACES the whole configuration -- so a form that opens empty
 * invites an admin to add one group, press Save, and silently switch off the
 * upgrades that were already working. That happened on a real product during
 * development, which is why the seeding and the warning are both pinned here.
 *
 * Run from admin-panel/: npm run check:product-upgrades
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
    params[name] === undefined ? '{' + name + '}' : params[name])
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
Vue.prototype.$store = {getters: {}, dispatch () {}}

/* ---- the component under test -------------------------------------------- */

let findAnswer = null
let previewAnswer = {groups: []}
const saves = []

function mount () {
  const options = loadSfc('components/partials/ProductUpgrades.vue', {
    'vuex': {
      mapActions: () => ({
        getById () { return Promise.resolve(findAnswer) },
        setById (payload) {
          if (payload.api === 'previewProductUpgrades') {
            return Promise.resolve(previewAnswer)
          }

          saves.push(payload)

          return Promise.resolve(findAnswer)
        },
        setRequest () { return Promise.resolve({updated: 0}) }
      })
    }
  })

  const vm = new (Vue.extend(options))({propsData: {productId: 1}})

  vm.$mount()

  return vm
}

const attribute = (id, title, rankable, values) => ({
  id,
  title,
  rankable,
  value_count: values.length,
  values: values.map((v, i) => ({
    id: id * 100 + i,
    title: v,
    rank: null,
    derived_rank: rankable ? i + 1 : null,
    scale: rankable ? 'parsed' : null
  }))
})

const discovered = (id, name) => ({
  id: null,
  name,
  attribute_id: id,
  mode: 'automatic',
  status: 1,
  display_order: 1,
  out_of_stock: 'hide',
  customer_type: 'all',
  max_options: null,
  option_value_ids: []
})

const settle = async () => {
  for (let i = 0; i < 8; i++) await new Promise(resolve => setTimeout(resolve, 5))
}

async function run () {
  console.log('\nProduct upgrades (admin)')

  /*
   * The trap this pins. An empty form invites "add one group, Save", which
   * replaces the automatic behaviour with that single group -- and a group on
   * an unrankable attribute shows nothing, so the product silently loses the
   * upgrades it had.
   */
  await test('with nothing saved, the form opens as what already runs', async () => {
    findAnswer = {
      product_id: 1,
      is_using_defaults: true,
      groups: [],
      discovered: [discovered(1, 'Storage Upgrade')],
      attributes: [
        attribute(1, 'Storage', true, ['128GB', '256GB']),
        attribute(5, 'Colour', false, ['Black', 'Blue'])
      ]
    }

    const vm = mount()
    await settle()

    assert.strictEqual(vm.groups.length, 1, 'the form opened empty')
    assert.strictEqual(vm.groups[0].name, 'Storage Upgrade')
    assert.strictEqual(vm.groups[0].attribute_id, 1)
  })

  await test('an untouched form warns about nothing', async () => {
    const vm = mount()
    await settle()

    assert.deepStrictEqual(vm.droppedGroups, [])
  })

  await test('removing a group that runs automatically warns before Save', async () => {
    const vm = mount()
    await settle()

    vm.removeGroup(0)
    await vm.$nextTick()

    assert.deepStrictEqual(vm.droppedGroups, ['Storage Upgrade'])

    const note = vm.$el.querySelector('.pu-note-warn')

    assert.ok(note, 'no warning rendered')
    assert.ok(/Storage Upgrade/.test(note.textContent), note.textContent.trim())
  })

  /* Saving an untouched form persists exactly what was already happening. */
  await test('saving an untouched form keeps the automatic group', async () => {
    saves.length = 0

    const vm = mount()
    await settle()

    await vm.save()

    assert.strictEqual(saves.length, 1, 'nothing was sent')

    const sent = saves[0].params.groups

    assert.strictEqual(sent.length, 1)
    assert.strictEqual(sent[0].attribute_id, 1)
    assert.strictEqual(sent[0].mode, 'automatic')
  })

  /*
   * An active group on an unrankable attribute can never show anything. Said
   * before Save rather than discovered on the storefront.
   */
  await test('an active group that can never show anything is called out', async () => {
    const vm = mount()
    await settle()

    vm.groups[0].attribute_id = 5
    await vm.$nextTick()

    assert.deepStrictEqual(vm.inertGroups, ['Storage Upgrade'])
    assert.ok(vm.$el.querySelector('.pu-note-warn'), 'no warning rendered')
  })

  await test('saved configuration is shown instead of the defaults', async () => {
    findAnswer = {
      product_id: 1,
      is_using_defaults: false,
      groups: [{
        id: 9,
        name: 'My Storage Upgrade',
        attribute_id: 1,
        mode: 'manual',
        status: 1,
        display_order: 1,
        out_of_stock: 'disable',
        customer_type: 'business',
        max_options: 2,
        option_value_ids: [101]
      }],
      discovered: [discovered(1, 'Storage Upgrade')],
      attributes: [attribute(1, 'Storage', true, ['128GB', '256GB'])]
    }

    const vm = mount()
    await settle()

    assert.strictEqual(vm.groups.length, 1)
    assert.strictEqual(vm.groups[0].name, 'My Storage Upgrade')
    assert.strictEqual(vm.groups[0].mode, 'manual')
    assert.deepStrictEqual(vm.groups[0].option_value_ids, [101])

    // Covered by a saved group, so nothing is being turned off.
    assert.deepStrictEqual(vm.droppedGroups, [])
  })

  /* One group per attribute: the second would offer the same variant twice. */
  await test('an attribute already in use cannot be chosen twice', async () => {
    const vm = mount()
    await settle()

    assert.strictEqual(vm.isAttributeTaken(1, -1), true)
    assert.strictEqual(vm.isAttributeTaken(1, 0), false, 'a group blocked its own attribute')
  })

  await test('switching a group to manual clears values from the old attribute', async () => {
    const vm = mount()
    await settle()

    assert.deepStrictEqual(vm.groups[0].option_value_ids, [101])

    vm.groups[0].attribute_id = 5
    vm.onAttributeChanged(vm.groups[0])

    assert.deepStrictEqual(vm.groups[0].option_value_ids, [])
  })

  console.log('\n' + passed + ' passed, ' + failed + ' failed\n')
  process.exit(failed ? 1 : 0)
}

run()
