/*
 * The Business Product pricing ladder screen, without a browser or a build.
 *
 * Mounts the real SFC with the bundled vue-template-compiler and jsdom and
 * drives the ladder the way an admin does: ticking No limit, adding rows,
 * deleting the top band, turning "21-30" into "21+".
 *
 * What these pin down is the rules the screen has to get right before the save
 * ever reaches BusinessProductsController::bandError - an open-ended band only
 * at the end, no overlaps, no gaps, and the maximum shown as "No limit" rather
 * than the 0 it is stored as. The bug that started this was a ladder that
 * stopped at 30, so quantity 31 fell through to the retail price; every
 * assertion here is about not being able to write that ladder again.
 *
 * Run from admin-panel/: npm run check:business-pricing
 */
const fs = require('fs')
const { translate, translateCount } = require('./i18n-en')
const path = require('path')
const { JSDOM } = require('jsdom')

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
global.Intl = Intl

const Vue = require('vue')
const compiler = require('vue-template-compiler')

Vue.config.productionTip = false
Vue.config.devtools = false

// ---- assertions -------------------------------------------------------------

let failures = 0
let checks = 0

function check(label, actual, expected) {
  checks++
  const a = JSON.stringify(actual)
  const e = JSON.stringify(expected)

  if (a === e) {
    console.log('  ok   ' + label)
  } else {
    failures++
    console.log('  FAIL ' + label + '\n         expected ' + e + '\n         got      ' + a)
  }
}

function ok(label, condition) {
  check(label, !!condition, true)
}

// ---- the page ---------------------------------------------------------------

function loadComponent(file) {
  const sfc = compiler.parseComponent(fs.readFileSync(file, 'utf8'))
  const compiled = compiler.compile(sfc.template.content)

  if (compiled.errors.length) {
    throw new Error(file + ' template: ' + compiled.errors.join('; '))
  }

  // The page is authored with CRLF line endings, so the import stripper has to
  // tolerate the carriage return or it matches nothing and vm is handed ESM.
  const js = sfc.script.content
    .replace(/^[ \t]*import[\s\S]*?from\s+['"][^'"]+['"];?[ \t]*\r?\n/gm, '')
    .replace(/export\s+default/, 'module.exports =')

  const module = { exports: {} }
  const vm = require('vm')

  const context = vm.createContext({
    module, exports: module.exports, console, process,
    mapActions: () => ({}),
    mapGetters: () => ({}),
    util: {}, outsideClick: {},
    DataPage: {}, Dropdown: {},
    Intl, document, navigator, window, setTimeout, clearTimeout
  })

  new vm.Script(js, { filename: file }).runInContext(context)

  const options = module.exports

  options.render = new Function(compiled.render)
  options.staticRenderFns = compiled.staticRenderFns.map(fn => new Function(fn))

  return options
}

function makeHarness(options) {
  const toasts = []

  const Component = Vue.extend(Object.assign({}, options, {
    // fetchProducts would go to the network on mount.
    methods: Object.assign({}, options.methods, {
      fetchProducts: () => Promise.resolve()
    }),
    components: {
      'data-page': { render: h => h('div') },
      DataPage: { render: h => h('div') },
      dropdown: { render: h => h('div') },
      Dropdown: { render: h => h('div') }
    },
    directives: { outsideClick: {}, 'outside-click': {} }
  }))

  Component.prototype.$t = translate
  Component.prototype.$tc = translateCount

  const vm = new Component()

  vm.$t = translate
  vm.$tc = translateCount
  vm.$route = { params: { id: '1' }, query: {} }
  vm.$router = { push: () => {} }
  vm.$store = {
    state: {},
    dispatch: (action, payload) => {
      toasts.push({ action, payload })
      return Promise.resolve()
    }
  }

  vm.products = [{ id: '99240288', title: 'testing product', offered: 500 }]
  vm.result.product_id = '99240288'

  return { vm, toasts }
}

/* The three-band ladder that was configured, as the API sends it. */
function ladderFromServer() {
  return {
    product_id: '99240288',
    pricing: [
      { min: 5, max: 10, wholesale_price: 500, discount_type: 'fixed', discount_value: 50, final_price: 450 },
      { min: 11, max: 20, wholesale_price: 500, discount_type: 'fixed', discount_value: 70, final_price: 430 },
      { min: 21, max: 30, wholesale_price: 500, discount_type: 'fixed', discount_value: 80, final_price: 420 }
    ]
  }
}

// =============================================================================

function run() {
  const options = loadComponent(path.join('pages', 'business-products', '_id.vue'))

  // ---- how an open-ended band is read and shown -----------------------------

  console.log('\nReading the stored ladder')
  {
    const { vm } = makeHarness(options)

    vm.settingResult({
      product_id: '99240288',
      pricing: [
        { min: 5, max: 10, wholesale_price: 500, discount_type: 'fixed', discount_value: 50, final_price: 450 },
        { min: 11, max: 0, wholesale_price: 500, discount_type: 'fixed', discount_value: 70, final_price: 430 }
      ]
    })

    // max_qty 0 is the store's "and over". Showing it as a literal 0 reads as
    // "a maximum of nothing" and invites staff to replace it with a number.
    check('a stored max of 0 arrives at the screen blank', vm.result.pricing[1].max, '')
    check('and is flagged open-ended', vm.isOpenEnded(vm.result.pricing[1]), true)
    check('the bounded band is not', vm.isOpenEnded(vm.result.pricing[0]), false)

    check('a bounded band reads as a range', vm.rangeLabel(vm.result.pricing[0]), '5 - 10')
    check('an open-ended band reads as "and over"', vm.rangeLabel(vm.result.pricing[1]), '11+')
    ok('never as a null or a 0', vm.rangeLabel(vm.result.pricing[1]).indexOf('0') === -1)
  }

  // ---- ticking No limit -----------------------------------------------------

  console.log('\nThe No limit tick box')
  {
    const { vm } = makeHarness(options)

    vm.settingResult(ladderFromServer())

    const top = vm.result.pricing[2]

    check('the stored ladder ends bounded at 30', vm.rangeLabel(top), '21 - 30')
    ok('which the screen calls out as a cliff', vm.ladderIssues.length === 1)
    ok('naming the quantity that falls through',
      vm.ladderIssues[0].indexOf('31') !== -1)

    // Turning 21-30 into 21+ by editing the row that is there, not by adding
    // a second one: the same ladder, one band changed.
    vm.setOpenEnded(top, true)

    check('ticking No limit clears the maximum', top.max, '')
    check('the band now reads 21+', vm.rangeLabel(top), '21+')
    check('and the ladder has no faults left', vm.ladderIssues, [])
    check('still three bands, not four', vm.result.pricing.length, 3)

    // Unticking has to put a usable number back, or the row stays open-ended
    // in everything but the tick box.
    vm.setOpenEnded(top, false)

    check('unticking restores a maximum', top.max, 21)
    check('and the band is bounded again', vm.isOpenEnded(top), false)
  }

  // ---- adding a row ---------------------------------------------------------

  console.log('\nAdd Row')
  {
    const { vm, toasts } = makeHarness(options)

    vm.settingResult(ladderFromServer())
    vm.addRow()

    check('a fourth band was added', vm.result.pricing.length, 4)
    // Bands have to run on without a gap; the commonest way to make one is to
    // type the next minimum from memory.
    check('starting one above the ladder', vm.result.pricing[3].min, 31)
    check('open-ended by default', vm.isOpenEnded(vm.result.pricing[3]), true)
    check('so the ladder is sound', vm.ladderIssues, [])

    // A row after an open-ended band could never apply.
    vm.addRow()

    check('a second row after an open-ended band is refused', vm.result.pricing.length, 4)
    check('with a reason', toasts.length, 1)
    check('on the error channel', toasts[0].action, 'ui/setToastError')
    ok('naming the rule', toasts[0].payload.indexOf('final tier') !== -1)
    ok('and what to do about it', toasts[0].payload.indexOf('maximum quantity') !== -1)

    // Bounding the top band makes room for another.
    vm.setOpenEnded(vm.result.pricing[3], false)
    vm.result.pricing[3].max = 50
    vm.addRow()

    check('once it is bounded, a row can be added', vm.result.pricing.length, 5)
    check('starting above the new maximum', vm.result.pricing[4].min, 51)
  }

  // ---- deleting the top band ------------------------------------------------

  console.log('\nDelete Row')
  {
    const { vm } = makeHarness(options)

    vm.settingResult(ladderFromServer())
    vm.setOpenEnded(vm.result.pricing[2], true)

    check('starting from 5-10, 11-20, 21+', vm.ladderIssues, [])

    vm.removeRow(2)

    check('the open-ended band is gone', vm.result.pricing.length, 2)
    ok('and the screen says the ladder now stops', vm.ladderIssues.length === 1)

    // Another final tier can be added straight afterwards.
    vm.addRow()

    check('a replacement band can be added', vm.result.pricing.length, 3)
    check('from 21', vm.result.pricing[2].min, 21)
    check('leaving a sound ladder', vm.ladderIssues, [])
  }

  // ---- the rules, one at a time ---------------------------------------------

  console.log('\nValidation')

  function issuesFor(rows) {
    const { vm } = makeHarness(options)
    vm.result.pricing = rows.map(r => Object.assign(
      { wholesale_price: 500, discount_type: 'fixed', discount_value: 0, final_price: 500 },
      r
    ))
    return vm.ladderIssues
  }

  check('5-10, 11-20, 21+ is valid',
    issuesFor([{min: 5, max: 10}, {min: 11, max: 20}, {min: 21, max: ''}]), [])

  check('1-5, 6-10, 11+ is valid',
    issuesFor([{min: 1, max: 5}, {min: 6, max: 10}, {min: 11, max: ''}]), [])

  // A gap below the first band is deliberate: bulk rates start at five.
  check('a ladder that starts at 5 is valid',
    issuesFor([{min: 5, max: 10}, {min: 11, max: ''}]), [])

  ok('5-10, 10-20 overlaps',
    issuesFor([{min: 5, max: 10}, {min: 10, max: 20}])[0].indexOf('overlaps') !== -1)

  ok('5-10, 8-20 overlaps',
    issuesFor([{min: 5, max: 10}, {min: 8, max: 20}])[0].indexOf('overlaps') !== -1)

  ok('5-10, 11-25, 21+ overlaps',
    issuesFor([{min: 5, max: 10}, {min: 11, max: 25}, {min: 21, max: ''}])[0]
      .indexOf('overlaps') !== -1)

  ok('5-10, 11-20, 20+ overlaps',
    issuesFor([{min: 5, max: 10}, {min: 11, max: 20}, {min: 20, max: ''}])[0]
      .indexOf('overlaps') !== -1)

  // The open-ended band swallows everything above it, so a row after it is a
  // row that can never apply. Named as its own rule rather than as an overlap.
  ok('5+, 11-20 is refused as a final-tier fault',
    issuesFor([{min: 5, max: ''}, {min: 11, max: 20}])[0]
      .indexOf('final one') !== -1)

  ok('two open-ended bands are refused',
    issuesFor([{min: 5, max: ''}, {min: 21, max: ''}])[0]
      .indexOf('Only the final band') !== -1)

  // 5-10 then 21+ leaves 11-20 paying retail.
  const gap = issuesFor([{min: 5, max: 10}, {min: 21, max: ''}])
  ok('5-10, 21+ is refused as a gap', gap[0].indexOf('gap') !== -1)
  ok('naming the quantities that would fall through',
    gap[0].indexOf('11-20 units') !== -1)

  ok('a maximum below the minimum is refused',
    issuesFor([{min: 20, max: 5}])[0].indexOf('below the minimum') !== -1)

  ok('a minimum below 1 is refused',
    issuesFor([{min: 0, max: 5}])[0].indexOf('at least 1') !== -1)

  // A row mid-type is not yet a fault.
  check('a blank new row is not reported as broken',
    issuesFor([{min: 5, max: 10}, {min: 11, max: ''}, {min: '', max: ''}]), [])

  // ---- the final price is still the discount's job --------------------------

  console.log('\nFinal price')
  {
    const { vm } = makeHarness(options)

    const fixed = { min: 21, max: '', wholesale_price: 500, discount_type: 'fixed', discount_value: 80, final_price: 0 }
    vm.calculate(fixed)
    check('a fixed discount comes off the band price', fixed.final_price, 420)

    const percent = { min: 21, max: '', wholesale_price: 500, discount_type: 'percentage', discount_value: 10, final_price: 0 }
    vm.calculate(percent)
    check('a percentage discount is taken on it', percent.final_price, 450)

    const over = { min: 21, max: '', wholesale_price: 100, discount_type: 'fixed', discount_value: 400, final_price: 0 }
    vm.calculate(over)
    check('a discount larger than the price floors at zero', over.final_price, 0)

    // Open-endedness is a range, not a price: it must not touch the maths.
    const bounded = { min: 21, max: 30, wholesale_price: 500, discount_type: 'fixed', discount_value: 80, final_price: 0 }
    vm.calculate(bounded)
    check('a bounded band prices identically', bounded.final_price, fixed.final_price)
  }

  console.log('\n' + checks + ' checks, ' + failures + ' failed\n')

  process.exit(failures ? 1 : 0)
}

run()
