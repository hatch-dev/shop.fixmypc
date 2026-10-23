/*
 * My Orders: quick tab switches and searches must end on the answer for what
 * is on screen (QA case M-13). Runs the page's real fetchingData() against the
 * real order store, with an API whose answers arrive in an order the check
 * chooses, so an older request can land after a newer one.
 *
 * Run from frontend-panel/: npm run check:my-orders-race
 */
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const Vue = require('vue')
const Vuex = require('vuex')

Vue.use(Vuex)

// ---- an API whose answers are released by hand ------------------------------

const pending = []

const Service = {
  orderByUser(payload) {
    return new Promise(resolve => {
      pending.push({payload, release() {
        resolve({data: {status: 200, data: {
          tab: payload.tab, q: payload.q, current_page: payload.page,
          data: [{id: payload.tab + '|' + payload.q + '|' + payload.page}],
          counts: {all: 1},
        }}})
      }})
    })
  },
}

function load(file, injected) {
  const src = fs.readFileSync(file, 'utf8')
  const stripped = src
    .replace(/^[ \t]*import[\s\S]*?from\s+['"][^'"]+['"];?[ \t]*\r?\n/gm, '')
    .replace(/^[ \t]*export\s+default\s+/m, 'module.exports.default = ')
    .replace(/^[ \t]*export\s*\{[\s\S]*?\}[ \t]*;?[ \t]*$/m, '')
  const module = {exports: {}}
  const context = vm.createContext(Object.assign({module, exports: module.exports, console, Promise, setTimeout, clearTimeout}, injected))
  new vm.Script(stripped + '\n;module.exports.__names = {state, getters, mutations, actions};', {filename: file}).runInContext(context)
  return module.exports.__names
}

// The page's <script>, with its imports stubbed: only fetchingData is exercised.
function loadPage(file) {
  const sfc = fs.readFileSync(file, 'utf8')
  const script = sfc.slice(sfc.indexOf('<script>') + 8, sfc.lastIndexOf('</script>'))
  const names = [...script.matchAll(/^[ \t]*import\s+([A-Za-z_$][\w$]*)\s+from/gm)].map(m => m[1])
  const stubs = Object.fromEntries(names.map(n => [n, {}]))
  const module = {exports: {}}
  const context = vm.createContext(Object.assign({module, console, Promise, setTimeout, clearTimeout,
    mapGetters: () => ({}), mapActions: () => ({})}, stubs))
  new vm.Script(script.replace(/^[ \t]*import[\s\S]*?from\s+['"][^'"]+['"];?[ \t]*\r?\n/gm, '')
    .replace(/^[ \t]*export\s+default\s+/m, 'module.exports.default = '), {filename: file}).runInContext(context)
  return module.exports.default
}

const order = load(path.join('store', 'order.js'), {Service})
const page = loadPage(path.join('pages', 'user', 'orders.vue'))

function makePage() {
  const store = new Vuex.Store({modules: {order: {namespaced: true, ...order}}})
  store.$auth = {loggedIn: true, strategy: {token: {get: () => 'bearer'}}}

  const vm = {
    activeTab: 'all', searchText: '', page: 1, requestSeq: 0,
    fetchingOrderData: false, loadError: false, changedSelectedOrder: false,
    timeZone: 'UTC', orderBy: 'created_at', orderByType: 'desc', langCode: 'en',
    settingRouteParam() {},
    hasError() {},
    getUserToken: async () => null,
    getOrderByUser: args => store.dispatch('order/getOrderByUser', args),
    get orderedList() { return store.getters['order/orderedList'] },
  }
  vm.fetchingData = page.methods.fetchingData.bind(vm)
  vm.currentOrders = () => page.computed.currentOrders.call(vm)

  return vm
}

const flush = () => new Promise(r => setTimeout(r, 0))
let failures = 0

function expect(label, actual, wanted) {
  const ok = JSON.stringify(actual) === JSON.stringify(wanted)
  if (!ok) failures++
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : `\n       wanted ${JSON.stringify(wanted)}, got ${JSON.stringify(actual)}`))
}

/** Start two requests (old state, then new), answer them in `order`, report what is shown. */
async function race(label, change, answerOrder) {
  pending.length = 0
  const vm = makePage()

  vm.fetchingData()
  await flush()
  change(vm)
  vm.fetchingData()
  await flush()

  const [first, second] = answerOrder === 'newer-first' ? [pending[1], pending[0]] : [pending[0], pending[1]]
  first.release(); await flush()
  second.release(); await flush()
  // A retry the page made, if any, answers last.
  while (pending.length > 2) { pending.splice(2, 1)[0].release(); await flush() }

  const wanted = vm.activeTab + '|' + vm.searchText + '|' + vm.page
  expect(`${label} (${answerOrder})`, vm.currentOrders().map(o => o.id), [wanted])
  expect(`${label} (${answerOrder}): spinner off`, vm.fetchingOrderData, false)
}

;(async () => {
  for (const answerOrder of ['older-first', 'newer-first']) {
    await race('switch tab all -> shipped', vm => { vm.activeTab = 'shipped' }, answerOrder)
    await race('search 12 -> 123 on one tab', vm => { vm.searchText = '123' }, answerOrder)
    await race('page 1 -> page 2 on one tab', vm => { vm.page = 2 }, answerOrder)
  }

  console.log(failures ? `\n${failures} failure(s)` : '\nall passed')
  process.exit(failures ? 1 : 0)
})()
