/*
 * The Flash Discount admin screens.
 *
 * Run with: node tests/flash-discount.check.js
 *
 * The list page and the edit form, mounted for real with ListPage/DataPage
 * stubbed down to the slot each page fills. What is checked is what an admin
 * reads off the screen:
 *
 *   - a percentage shows a percentage and a fixed amount shows money, in the
 *     store's own currency rather than a hard-coded symbol
 *   - "Active" is not claimed for a promotion whose window is closed or has
 *     not opened
 *   - an unset cap reads as unset, and 0 does not
 *   - the edit form can actually display the dates the API returns, which it
 *     could not while they came back with a space instead of a T
 *   - Max Discount starts empty, because 0 meant "no discount at all" to
 *     Pricing::flashDiscount
 */
const assert = require('assert')
const { translate, translateCount } = require('./i18n-en')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

// a DOM before Vue loads, or Vue 2 picks its non-browser build and renders
// nothing at all
const { JSDOM } = require(path.join(NM, 'jsdom'))
const dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>',
  { url: 'http://localhost/' })

global.window = dom.window
global.document = dom.window.document
global.navigator = dom.window.navigator
global.Element = dom.window.Element
global.HTMLElement = dom.window.HTMLElement
global.SVGElement = dom.window.SVGElement
global.getComputedStyle = dom.window.getComputedStyle

const Vue = require(path.join(NM, 'vue/dist/vue.common.dev.js'))
const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

Vue.config.productionTip = false
Vue.config.devtools = false

/*
 * The util mixin's data() reads this.$t() for its titles and this.$store
 * for the placeholder image, so a component that mixes it in cannot even be
 * constructed without both. Neither has anything to do with what is under
 * test; they are here so the component can exist.
 */
Vue.prototype.$store = { state: { defaultImage: '', imgSrcUrl: '', thumbPrefix: '' }, getters: {} }

// The util mixin's data() is full of this.$t() titles, so every component
// that mixes it in needs one before it can even be constructed.
Vue.prototype.$t = translate
Vue.prototype.$tc = translateCount

let renderError = null
Vue.config.errorHandler = (err) => { renderError = err }

function transform (code, filename) {
  return babel.transformSync(code, {
    filename, configFile: false, babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code
}

function loadMixin (name) {
  const code = transform(fs.readFileSync(path.join(ROOT, 'mixin', name + '.js'), 'utf8'), name + '.js')
  const m = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(m, m.exports, () => ({}))
  return m.exports.default || m.exports
}

const util = loadMixin('util')

/** The store settings the money helpers read. EUR, symbol after the amount. */
const SETTING = { currency_icon: '€', currency_position: 2, decimal_format: 'en-IE' }

function loadPage (file, stubName, stubTemplate) {
  const parsed = compiler.parseComponent(fs.readFileSync(path.join(ROOT, file), 'utf8'))
  const m = { exports: {} }

  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', transform(parsed.script.content, file))(
    m, m.exports, (request) => {
      if (request === 'vuex') {
        const spread = () => ({})
        return { mapGetters: spread, mapActions: spread, mapState: spread, mapMutations: spread }
      }
      return {}
    })

  const options = m.exports.default || m.exports

  options.template = parsed.template.content
  options.components = Object.assign({}, options.components, {
    [stubName]: { template: stubTemplate, props: ['gate', 'name', 'result', 'validationKeys'] },
  })

  // The real pages take `setting` from the settings store module.
  /*
   * The page mixes in util and bulkDelete. util is what prints the money,
   * so it is loaded for real; bulkDelete only supplies the select-all
   * checkbox and needs a store this harness has no use for, so it is left
   * out -- Vue warns about the missing change handler and renders the rest.
   */
  options.mixins = [util, { computed: { setting: () => SETTING }, data: () => ({ cbList: [] }) }]

  return options
}

const failures = []

function check (name, fn) {
  try {
    fn()
    console.log('  ok   ' + name)
  } catch (e) {
    failures.push(name + '\n       ' + String(e.message).split('\n')[0])
    console.log('  FAIL ' + name)
  }
}

/* ------------------------------------------------------------- the list */

/*
 * ListPage hands the slot the page's rows. Stubbed down to exactly that, so
 * what is measured is the markup the page itself writes.
 */
const listStub = '<table><tbody><slot name="table" :list="list" :from="1"/></tbody></table>'

const ListPage = loadPage('pages/flash-discount/index.vue', 'list-page', listStub)

function renderList (rows) {
  renderError = null

  const Ctor = Vue.extend(Object.assign({}, ListPage, {
    components: Object.assign({}, ListPage.components, {
      'list-page': { data: () => ({ list: rows }), template: listStub },
    }),
  }))

  const vm = new Ctor()
  vm.$mount(document.createElement('div'))

  return (vm.$el.outerHTML || '').replace(/<[^>]+>/g, ' | ').replace(/\s+/g, ' ').trim()
}

function row (overrides) {
  return Object.assign({
    id: 1,
    type: 'percentage',
    value: '15.00',
    min_cart_value: '100.00',
    max_discount: '200.00',
    start_time: '2026-09-18T09:00:00',
    end_time: '2026-09-18T21:00:00',
    is_active: true,
    live: true,
  }, overrides)
}

console.log('\nFlash Discount admin\n')

check('the list renders', () => {
  renderList([row()])
  assert.strictEqual(renderError, null, String(renderError))
})

check('a percentage discount is shown as a percentage', () => {
  const html = renderList([row()])
  assert.ok(html.indexOf('15%') !== -1, html.slice(0, 200))
})

check('a fixed discount is shown in the store currency', () => {
  const html = renderList([row({ type: 'fixed', value: '20.00' })])
  assert.ok(/20[.,]00\s*\|?\s*€/.test(html) || html.indexOf('20.00€') !== -1,
    'the value column reads: ' + html.slice(0, 220))
})

check('the currency is the store setting, not a hard-coded symbol', () => {
  const html = renderList([row({ type: 'fixed', value: '20.00' })])
  assert.ok(html.indexOf('$') === -1, html.slice(0, 200))
})

check('a live promotion reads Live', () => {
  assert.ok(renderList([row()]).indexOf('Live') !== -1)
})

check('a switched-on promotion whose window has closed does not read Live', () => {
  const html = renderList([row({
    live: false,
    start_time: '2026-01-01T00:00:00',
    end_time: '2026-01-02T00:00:00',
  })])

  assert.ok(html.indexOf('Ended') !== -1 && html.indexOf('Live') === -1,
    'status column reads: ' + html.slice(0, 260))
})

check('a promotion that has not started yet reads Scheduled', () => {
  const html = renderList([row({
    live: false,
    start_time: '2099-01-01T00:00:00',
    end_time: '2099-01-02T00:00:00',
  })])

  assert.ok(html.indexOf('Scheduled') !== -1, 'status column reads: ' + html.slice(0, 260))
})

check('a switched-off promotion reads Inactive', () => {
  assert.ok(renderList([row({ is_active: false, live: false })]).indexOf('Inactive') !== -1)
})

check('an unset cap reads as unset', () => {
  const html = renderList([row({ max_discount: null })])
  assert.ok(html.indexOf('-') !== -1, html.slice(0, 220))
})

check('a cap of zero is not printed as if it were unset', () => {
  const html = renderList([row({ max_discount: '0.00' })])
  assert.ok(/0[.,]00/.test(html), 'the cap column reads: ' + html.slice(0, 260))
})

/* --------------------------------------------------------- the edit form */

/*
 * DataPage hands the slot the record being edited. Stubbed to exactly that.
 */
const dataStub = '<form><slot name="form" :has-error="false"/></form>'

const DataPage = loadPage('pages/flash-discount/_id.vue', 'data-page', dataStub)

function renderForm (record) {
  renderError = null

  const Ctor = Vue.extend(Object.assign({}, DataPage, {
    components: Object.assign({}, DataPage.components, {
      'data-page': { template: dataStub },
    }),
  }))

  const vm = new Ctor()

  if (record) Object.assign(vm.result, record)

  vm.$mount(document.createElement('div'))

  return { vm, el: vm.$el, html: () => vm.$el.outerHTML }
}

check('the form renders', () => {
  renderForm()
  assert.strictEqual(renderError, null, String(renderError))
})

/*
 * The whole reason the model casts the columns with a T. While `find` came
 * back with "2026-09-18 10:08:07" this control showed nothing, and saving
 * then failed on "Start & End time required".
 */
check('the dates are bound in the grammar the datetime-local control reads', () => {
  const { el } = renderForm({
    start_time: '2026-09-18T09:00:00',
    end_time: '2026-09-18T21:00:00',
  })

  const inputs = Array.from(el.querySelectorAll('input[type=datetime-local]'))

  assert.strictEqual(inputs.length, 2)

  /*
   * jsdom does not sanitise a datetime-local value the way a browser does --
   * it will hold '2026-09-18 09:00:00' quite happily, where Chrome blanks the
   * field. So the grammar is asserted directly rather than trusted to the
   * control. That the API sends this form is pinned separately, by
   * FlashDiscountTest::test_find_returns_dates_the_edit_form_can_display.
   */
  inputs.forEach((input) => {
    assert.ok(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(input.value),
      'a datetime-local control was bound to "' + input.value + '"')
  })
})

check('Max Discount starts empty rather than at zero', () => {
  const { vm } = renderForm()

  assert.strictEqual(vm.result.max_discount, '',
    'a cap of 0 is a ceiling of zero to Pricing::flashDiscount, not "no cap"')
})

check('Min Cart Value starts empty rather than at zero', () => {
  assert.strictEqual(renderForm().vm.result.min_cart_value, '')
})

check('a percentage cannot be typed above 100', () => {
  const { el } = renderForm({ type: 'percentage' })
  const value = el.querySelectorAll('input[type=number]')[0]

  assert.strictEqual(value.getAttribute('max'), '100')
})

check('a fixed amount is not capped at 100', () => {
  const { el } = renderForm({ type: 'fixed' })
  const value = el.querySelectorAll('input[type=number]')[0]

  assert.strictEqual(value.getAttribute('max'), null)
})

check('no money field accepts a negative number', () => {
  const { el } = renderForm()

  Array.from(el.querySelectorAll('input[type=number]')).forEach((input) => {
    assert.strictEqual(input.getAttribute('min'), '0')
  })
})

check('the optional fields say they are optional', () => {
  const text = renderForm().html().replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ')

  assert.ok(text.indexOf('Leave empty for no limit') !== -1, text.slice(0, 200))
  assert.ok(text.indexOf('Leave empty for no minimum') !== -1, text.slice(0, 200))
})

/*
 * DataPage refuses to submit while a field named in validation-keys is empty:
 * it raises hasError and returns, without a request. So the form has to SAY
 * so, or pressing Save does nothing and reads as a broken button -- which is
 * exactly how it was reported.
 */
function renderFormWithError (record) {
  renderError = null

  const Ctor = Vue.extend(Object.assign({}, DataPage, {
    components: Object.assign({}, DataPage.components, {
      // hasError true: the state after Save was pressed on an empty form.
      'data-page': { template: '<form><slot name="form" :has-error="true"/></form>' },
    }),
  }))

  const vm = new Ctor()

  if (record) Object.assign(vm.result, record)

  vm.$mount(document.createElement('div'))

  return { vm, text: () => (vm.$el.outerHTML || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim(), el: vm.$el }
}

check('an empty form says which fields it is waiting for', () => {
  const f = renderFormWithError()

  assert.ok(/Value is required/.test(f.text()), f.text().slice(0, 240))
  assert.ok(/Start time is required/.test(f.text()), f.text().slice(0, 240))
  assert.ok(/End time is required/.test(f.text()), f.text().slice(0, 240))
})

check('and marks those fields invalid', () => {
  const f = renderFormWithError()

  assert.ok(f.el.querySelectorAll('input.invalid').length >= 3,
    'only ' + f.el.querySelectorAll('input.invalid').length + ' fields were marked')
})

check('a filled form is not marked invalid', () => {
  const f = renderFormWithError({
    value: 15,
    start_time: '2026-10-01T00:00:00',
    end_time: '2026-10-08T00:00:00',
  })

  assert.strictEqual(f.el.querySelectorAll('input.invalid').length, 0, f.text().slice(0, 240))
  assert.ok(f.text().indexOf('is required') === -1, f.text().slice(0, 240))
})

check('a percentage over 100 is named before the round trip', () => {
  const f = renderFormWithError({ type: 'percentage', value: 150 })

  assert.ok(/cannot be greater than 100/.test(f.text()), f.text().slice(0, 240))
})

check('a window that ends before it starts is named', () => {
  const f = renderFormWithError({
    value: 10,
    start_time: '2026-10-08T00:00:00',
    end_time: '2026-10-01T00:00:00',
  })

  assert.ok(/end time must come after/i.test(f.text()), f.text().slice(0, 240))
})

check('a fixed discount capped below its own value is named', () => {
  const f = renderFormWithError({ type: 'fixed', value: 50, max_discount: 20 })

  assert.ok(/maximum discount cannot be lower/i.test(f.text()), f.text().slice(0, 240))
})

check('an empty cap is not an error', () => {
  const f = renderFormWithError({ type: 'fixed', value: 50, max_discount: '' })

  assert.ok(f.text().indexOf('maximum discount cannot be lower') === -1, f.text().slice(0, 240))
})

console.log('')

if (failures.length) {
  console.log(failures.length + ' failing:\n')
  failures.forEach(f => console.log('  - ' + f))
  process.exitCode = 1
} else {
  console.log('all green')
}
