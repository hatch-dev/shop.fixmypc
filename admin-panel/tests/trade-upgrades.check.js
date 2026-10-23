/*
 * Trade applications -> "Upgrades from existing accounts", without a browser
 * or a build: mounts the real pages/trade-applications/index.vue with jsdom
 * and stubs only what Nuxt injects.
 *
 * Pins the reviewer-facing rules: which rows say Changed and which say New,
 * the identity warning, what Approve/Reject send, and that a refused decision
 * (which the admin store resolves with nothing) is not read as a success.
 *
 * Run from admin-panel/: npm run check:trade-upgrades
 */
const assert = require('assert')
const { translate, translateCount } = require('./i18n-en')
const fs = require('fs')
const path = require('path')
const {JSDOM} = require('jsdom')

const ROOT = path.resolve(__dirname, '..')

const dom = new JSDOM('<!doctype html><html><body></body></html>', {url: 'http://localhost/'})
global.window = dom.window
global.document = dom.window.document
global.navigator = dom.window.navigator
global.Element = dom.window.Element
global.HTMLElement = dom.window.HTMLElement
global.SVGElement = dom.window.SVGElement
global.Node = dom.window.Node
global.getComputedStyle = dom.window.getComputedStyle

// After jsdom, or $mount silently renders nothing.
const Vue = require('vue/dist/vue.common.dev.js')
const compiler = require('vue-template-compiler')
Vue.config.productionTip = false
Vue.config.devtools = false

function loadSfc (relative, stubs = {}) {
  const parts = compiler.parseComponent(fs.readFileSync(path.join(ROOT, relative), 'utf8'))
  let body = parts.script.content
  const imports = []

  body = body.replace(/^[ \t]*import\s+(.+?)\s+from\s+['"](.+?)['"];?[ \t]*$/gm, (_, what, from) => {
    imports.push({what: what.trim(), from})
    return ''
  })

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
  options.template = parts.template.content
  return options
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

// ---- what Nuxt injects -------------------------------------------------------

const toasts = []
let storeErrors = {}
let canEdit = true

Vue.prototype.$t = translate
Vue.prototype.$tc = translateCount
Vue.prototype.$can = (_module, action) => action === 'view' || canEdit
Vue.prototype.$store = {
  getters: {get 'ui/errors' () { return storeErrors }},
  dispatch (action, payload) { toasts.push({action, payload}) }
}
Vue.component('nuxt-link', {props: ['to'], template: '<a :href="to"><slot /></a>'})

// ---- fixtures ----------------------------------------------------------------

const change = (field, label, current, submitted, changed) => ({field, label, current, submitted, changed})

const businessApp = () => ({
  id: 2, from_type: 'business', to_type: 'trade', status: 'pending',
  details: {company_name: 'Renamed Ltd'}, review_note: null, reviewed_by: null, reviewed_at: null,
  created_at: '2026-09-29T09:00:00+00:00',
  user: {id: 50, name: 'Bea', email: 'bea@x.test', account: {status: 'active', verification_status: 'verified', has_business_pricing: true}},
  changes: [
    change('company_name', 'Company name', 'Bea Ltd', 'Renamed Ltd', true),
    change('vat_number', 'VAT number', 'IE1234567A', 'IE1234567A', false),
    change('cro_number', 'Company registration number', null, null, false),
    change('trade', 'Trade', null, 'Phone and tablet repair', true),
    change('pref_trade_list', 'Trade mailing list', false, false, false)
  ]
})

const personalApp = () => ({
  id: 3, from_type: 'personal', to_type: 'trade', status: 'pending',
  details: {company_name: 'Pat Fix'}, created_at: '2026-09-29T10:00:00+00:00',
  user: {id: 51, name: 'Pat', email: 'pat@x.test', account: {status: 'active', verification_status: 'not_required', has_business_pricing: false}},
  changes: [
    change('company_name', 'Company name', null, 'Pat Fix', true),
    change('trade', 'Trade', null, 'Phone and tablet repair', true)
  ]
})

let listResult
let saveResult
const requests = []
const saves = []

function mountPage (query = {mode: 'upgrades'}) {
  const options = loadSfc('pages/trade-applications/index.vue', {
    'vuex': {
      mapActions: () => ({
        getRequest (payload) { requests.push(payload); return Promise.resolve({data: listResult()}) },
        setById (payload) { saves.push(payload); return Promise.resolve(saveResult) }
      })
    },
    '~/mixin/util': {},
    '~/components/Spinner': {template: '<div class="spinner" />'}
  })

  Vue.prototype.$route = {query}
  Vue.prototype.$router = {replace () {}}

  const holder = document.createElement('div')
  document.body.appendChild(holder)

  return new (Vue.extend(options))().$mount(holder)
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0))
const cardFor = (vm, title) => [...vm.$el.querySelectorAll('.ta-card')]
  .find(card => card.querySelector('h3').textContent.includes(title))
const badges = card => [...card.querySelectorAll('.ta-changes-row')]
  .filter(row => row.querySelector('.ta-changed'))
  .map(row => row.querySelector('.ta-changes-label').textContent.trim() + ':' + row.querySelector('.ta-changed').textContent.trim())

;(async () => {

  console.log('\nTrade applications: upgrades')

  await test('opening with ?mode=upgrades loads the pending upgrade queue', async () => {
    listResult = () => [businessApp(), personalApp()]
    requests.length = 0
    const vm = mountPage()
    await flush()
    assert.strictEqual(requests[0].api, 'getTradeUpgrades')
    assert.deepStrictEqual(requests[0].params, {status: 'pending', q: ''})
    assert.strictEqual(vm.$el.querySelectorAll('.ta-card').length, 2)
  })

  await test('the sign-up queue is untouched by default', async () => {
    listResult = () => []
    requests.length = 0
    mountPage({})
    await flush()
    assert.strictEqual(requests[0].api, 'getTradeApplications')
  })

  await test('a business applicant: Changed only where the account held something else, New for new answers', async () => {
    listResult = () => [businessApp()]
    const vm = mountPage()
    await flush()
    const card = cardFor(vm, 'Renamed Ltd')
    assert.deepStrictEqual(badges(card), ['Company name:Changed', 'Trade:New'])
    assert.ok(card.querySelector('.ta-move').textContent.includes('Business'))
  })

  await test('rows with nothing on either side are left out', async () => {
    listResult = () => [businessApp()]
    const vm = mountPage()
    await flush()
    const labels = [...cardFor(vm, 'Renamed Ltd').querySelectorAll('.ta-changes-label')].map(l => l.textContent.trim())
    assert.ok(!labels.includes('Company registration number'), labels.join())
    assert.ok(!labels.includes('Trade mailing list'), labels.join())
  })

  await test('a renamed business gets the warning, saying "verified" only when it was', async () => {
    listResult = () => [businessApp()]
    let vm = mountPage()
    await flush()
    assert.ok(cardFor(vm, 'Renamed Ltd').querySelector('.ta-warn').textContent.includes('which we have verified'))

    listResult = () => { const a = businessApp(); a.user.account.verification_status = 'pending'; return [a] }
    vm = mountPage()
    await flush()
    const warn = cardFor(vm, 'Renamed Ltd').querySelector('.ta-warn').textContent
    assert.ok(!warn.includes('verified'), warn)
  })

  await test('a personal applicant has no badges and no warning (everything is new)', async () => {
    listResult = () => [personalApp()]
    const vm = mountPage()
    await flush()
    const card = cardFor(vm, 'Pat Fix')
    assert.deepStrictEqual(badges(card), [])
    assert.strictEqual(card.querySelector('.ta-warn'), null)
  })

  await test('Approve sends the decision and the note, and the card leaves the pending tab', async () => {
    listResult = () => [businessApp(), personalApp()]
    saves.length = 0
    toasts.length = 0
    saveResult = Object.assign(personalApp(), {status: 'approved', notification: {triggered: true, delivered: true, queued: false}})
    const vm = mountPage()
    await flush()
    vm.$set(vm.upgradeNotes, 3, 'Welcome aboard')
    const approve = [...cardFor(vm, 'Pat Fix').querySelectorAll('button')].find(b => b.textContent.trim() === 'Approve')
    approve.click()
    await flush(); await flush()
    assert.deepStrictEqual(saves[0], {id: 3, api: 'setTradeUpgrade', params: {decision: 'approved', review_note: 'Welcome aboard'}})
    assert.strictEqual(cardFor(vm, 'Pat Fix'), undefined)
    assert.ok(cardFor(vm, 'Renamed Ltd'))
    assert.ok(toasts.some(t => t.action === 'ui/setToastMessage' && /approved/.test(t.payload)), JSON.stringify(toasts))
  })

  await test('a refused decision is reported, not treated as done, and the list is reloaded', async () => {
    listResult = () => [personalApp()]
    saveResult = undefined
    storeErrors = {form: ['This application has already been withdrawn.']}
    toasts.length = 0
    requests.length = 0
    const vm = mountPage()
    await flush()
    ;[...cardFor(vm, 'Pat Fix').querySelectorAll('button')].find(b => b.textContent.trim() === 'Reject').click()
    await flush(); await flush()
    assert.ok(toasts.some(t => t.action === 'ui/setToastError' && t.payload.includes('withdrawn')), JSON.stringify(toasts))
    assert.strictEqual(requests.length, 2, 'loaded, then reloaded')
    storeErrors = {}
  })

  await test('a decided application shows its note and offers no buttons', async () => {
    listResult = () => [Object.assign(personalApp(), {status: 'rejected', review_note: 'Send a VAT cert', reviewed_at: '2026-09-29T11:00:00+00:00', reviewed_by: {id: 1, name: 'Staff'}})]
    const vm = mountPage()
    await flush()
    const card = cardFor(vm, 'Pat Fix')
    assert.ok(card.querySelector('.ta-note').textContent.includes('Send a VAT cert'))
    assert.strictEqual(card.querySelector('.ta-acts'), null)
    assert.deepStrictEqual(badges(card), [])
  })

  await test('without edit permission nothing can be decided', async () => {
    canEdit = false
    listResult = () => [businessApp()]
    const vm = mountPage()
    await flush()
    assert.strictEqual(cardFor(vm, 'Renamed Ltd').querySelector('.ta-acts'), null)
    canEdit = true
  })

  console.log(`\n${passed} passed, ${failed} failed`)
  process.exit(failed ? 1 : 0)
})()
