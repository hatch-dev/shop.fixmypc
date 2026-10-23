/*
 * Exercises the admin Category pages without a browser or a build step.
 *
 * Mounts the real SFCs with the bundled vue-template-compiler and jsdom, and
 * stubs the store actions so every request the pages make is recorded rather
 * than sent. Covers the behaviours that are decided in the page rather than in
 * the API: debounced search, filters surviving pagination, the select-all
 * checkbox, status changes only applied once confirmed, the delete dialog's
 * impact, the slug that no longer clobbers a manual edit, and image validation.
 *
 * Run from admin-panel/: npm run check:categories
 */
const fs = require('fs')
const { translate, translateCount } = require('./i18n-en')
const path = require('path')
const { JSDOM } = require('jsdom')

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
global.Blob = dom.window.Blob
global.URL = dom.window.URL
global.FileReader = dom.window.FileReader
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

const tick = () => new Promise(resolve => setTimeout(resolve, 0))
const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms))

// ---- load a page component --------------------------------------------------

function loadComponent(file) {
  const src = fs.readFileSync(file, 'utf8')
  const sfc = compiler.parseComponent(src)

  const compiled = compiler.compile(sfc.template.content)

  if (compiled.errors.length) {
    throw new Error(file + ' template: ' + compiled.errors.join('; '))
  }

  // Strip the imports and hand back the options object.
  const js = sfc.script.content
    .replace(/^[ \t]*import[\s\S]*?from\s+['"][^'"]+['"];?[ \t]*\n/gm, '')
    .replace(/export\s+default/, 'module.exports =')

  const module = { exports: {} }
  const vm = require('vm')

  const context = vm.createContext({
    module, exports: module.exports, console, process,
    mapActions: () => ({}),
    mapGetters: () => ({}),
    util: {},
    DataPage: {}, Dropdown: {}, ListPage: {}, LazyImage: {}, TreeNode: {}, ConfirmDialog: {}, FieldError: {}, SkeletonBlock: {}, Shimmer: {}, ImageInput: {}, FileUpload: {}, Spinner: {},
    Intl, URL, Blob, document, navigator, window, setTimeout, clearTimeout
  })

  new vm.Script(js, { filename: file }).runInContext(context)

  const options = module.exports

  options.render = new Function(compiled.render)
  options.staticRenderFns = compiled.staticRenderFns.map(fn => new Function(fn))

  return options
}

// ---- the stubs the pages talk to --------------------------------------------

function makeHarness(options, overrides = {}) {
  const calls = []

  const responses = Object.assign({
    getCategories: () => ({
      data: [
        {
          id: '1', title: 'Mobile', slug: 'mobile', parent: null, parent_title: null,
          status: 1, display_order: 1, children_count: 2, products_count: 5,
          image: 'a.png', image_url: 'http://img/a.png',
          created_at: '2026-01-01 10:00:00', updated_at: '2026-02-01 10:00:00'
        },
        {
          id: '2', title: 'Apple', slug: 'apple', parent: '1', parent_title: 'Mobile',
          status: 2, display_order: 2, children_count: 0, products_count: 0,
          image: 'b.png', image_url: 'http://img/b.png',
          created_at: '2026-01-02 10:00:00', updated_at: '2026-02-02 10:00:00'
        }
      ],
      current_page: 1, last_page: 3, from: 1, to: 2, total: 6,
      stats: {
        total: 6, root_categories: 4, sub_categories: 2,
        active_categories: 5, inactive_categories: 1, with_products: 3, growth: 20
      }
    }),
    getAllCategories: () => ([
      { id: '1', title: 'Mobile', parent: null, status: 1 },
      { id: '2', title: 'Apple', parent: '1', status: 1 },
      { id: '3', title: 'Computer', parent: null, status: 1 }
    ]),
    getCategoryDeleteImpact: () => ({
      categories: 1, subcategories: 2, subcategory_ids: [2, 3],
      products: 5, products_including_subcategories: 9
    }),
    getCategorySlugAvailable: () => ({ slug: 'mobile', available: false, suggestion: 'mobile-2' }),
    setCategoryStatus: () => ({ updated: 1, status: 1 }),
    deleteCategory: () => ({ deleted: 1 })
  }, overrides)

  const record = (api, params) => {
    calls.push({ api, params })

    const handler = responses[api]

    if (!handler) return Promise.reject(new Error('no stub for ' + api))

    const value = handler(params)

    return value instanceof Error ? Promise.reject(value) : Promise.resolve(value)
  }

  const toasts = []

  const Component = Vue.extend(Object.assign({}, options, {
    // Replace the store-backed actions with recorders.
    methods: Object.assign({}, options.methods, {
      getRequest: ({ params, api }) => record(api, params),
      setRequest: ({ params, api }) => record(api, params),
      getById: ({ id, params, api }) => record(api, Object.assign({ id }, params)),
      deleteData: ({ params, api }) => record(api, params),
      getAllList: () => Promise.resolve(),
      emptyAllList: () => {}
    }),
    // The pages only ever render sub-components as placeholders here.
    components: {
      'data-page': { render: h => h('div') },
      dropdown: { render: h => h('div') },
      DataPage: { render: h => h('div') },
      Dropdown: { render: h => h('div') }
    }
  }))

  Component.prototype.$can = () => true
  Component.prototype.$t = translate
  Component.prototype.$tc = translateCount

  const vm = new Component({
    // Nuxt/i18n/router/store surface the pages touch.
    mocks: {}
  })

  vm.$can = () => true
  vm.$t = translate
  vm.$tc = translateCount
  vm.$route = { params: { id: '1' }, query: {} }
  vm.$router = { push: (to) => calls.push({ api: 'router.push', params: to }) }
  vm.$store = {
    state: { imgSrcUrl: 'http://img/' },
    dispatch: (action, payload) => {
      toasts.push({ action, payload })
      return Promise.resolve()
    }
  }
  vm.defaultImage = 'default-image.webp'
  vm.getImageURL = (image) => 'http://img/' + (image || 'default-image.webp')
  vm.convertToSlug = (text) => (text || '').toLowerCase().replace(/ /g, '-').replace(/[^\w-]+/g, '')

  return { vm, calls, toasts, responses }
}


/*
 * A page of the tree as the server sends it: pre-ordered, one depth per row,
 * `ancestor_lines` carrying one flag per level above the row, and a
 * visible_children that counts what survived the filters rather than what
 * exists. Accessories is here as context only — it did not match, its child
 * did — and it has two more children the filters removed.
 */
function treeResponse() {

  const row = (id, title, parent, depth, over) => Object.assign({
    id, title, parent,
    slug: title.toLowerCase().replace(/ /g, '-'),
    parent_title: null,
    status: 1, display_order: 1,
    children_count: 0, products_count: 0,
    image: 'a.png', image_url: 'http://img/a.png',
    created_at: '2026-01-01 10:00:00', updated_at: '2026-02-01 10:00:00',
    depth,
    is_last_child: true,
    ancestor_lines: depth ? [true] : [],
    visible_children: 0,
    matched: true,
    can_have_children: depth === 0
  }, over || {})

  return {
    view: 'tree',
    data: [
      row('1', 'Mobile', null, 0, { children_count: 2, visible_children: 2, is_last_child: false }),
      row('2', 'Apple', '1', 1, { parent_title: 'Mobile', is_last_child: false }),
      row('3', 'Samsung', '1', 1, { parent_title: 'Mobile' }),
      row('4', 'Accessories', null, 0, {
        children_count: 3, visible_children: 1, matched: false, is_last_child: false
      }),
      row('5', 'USB Cables', '4', 1, { parent_title: 'Accessories' }),
      row('6', 'Audio', null, 0, {})
    ],
    current_page: 1, last_page: 1, from: 1, to: 3, total: 3,
    row_count: 6, match_count: 5, truncated: false,
    stats: {
      total: 6, root_categories: 3, sub_categories: 3,
      active_categories: 6, inactive_categories: 0, with_products: 0, growth: 0
    }
  }
}
// =============================================================================

async function run() {

  const listOptions = loadComponent(path.join('pages', 'categories', 'index.vue'))
  const formOptions = loadComponent(path.join('pages', 'categories', '_id.vue'))

  // ---- LIST: loading and data -----------------------------------------------

  console.log('\nList — loading and data')
  {
    const { vm, calls } = makeHarness(listOptions)

    check('starts in a loading state (skeletons, not "Loading…")', vm.loading, true)
    ok('skeleton row count follows the page size', vm.skeletonRows > 0)

    await vm.fetchCategories()

    check('rows came from the server', vm.itemList.length, 2)
    check('loading cleared', vm.loading, false)
    check('pagination captured', [vm.pagination.current_page, vm.pagination.total], [1, 6])
    check('rows are not nested under the paginator', vm.pagination.data, undefined)

    // Every card figure is the server's.
    const byKey = {}
    vm.summaryCards.forEach(c => { byKey[c.key] = c.value })

    check('Total card', byKey.total, 6)
    check('Root card', byKey.root, 4)
    check('Sub card', byKey.sub, 2)
    check('Published card', byKey.active, 5)
    check('Draft card', byKey.inactive, 1)
    check('With Products card', byKey.with_products, 3)

    check('subcategory row is present and knows its parent',
      vm.itemList[1].parent_title, 'Mobile')
    ok('subcategory is classified as one', vm.isSub(vm.itemList[1]))
    ok('root is not', !vm.isSub(vm.itemList[0]))
  }

  // ---- LIST: search, filters, sorting, paging --------------------------------

  console.log('\nList — search, filters, sorting, paging')
  {
    const { vm, calls } = makeHarness(listOptions)
    await vm.fetchCategories()
    calls.length = 0

    // Debounce: three keystrokes, one request.
    vm.search = 'mob'
    vm.onSearchInput()
    vm.onSearchInput()
    vm.onSearchInput()

    check('no request fired synchronously', calls.length, 0)
    await wait(420)
    check('one request after the debounce', calls.length, 1)
    check('carrying the term', calls[0].params.q, 'mob')

    // Filters ride along, and survive a page change.
    vm.filters.status = '2'
    vm.filters.scope = 'sub'
    vm.filters.parent = '1'
    calls.length = 0

    await vm.fetchCategories(1)
    const filtered = calls[0].params
    check('status filter sent', filtered.status, '2')
    check('scope filter sent', filtered.scope, 'sub')
    check('parent filter sent', filtered.parent, '1')
    check('search still sent', filtered.q, 'mob')
    check('active filter count', vm.activeFilterCount, 3)

    calls.length = 0
    vm.pagination = { current_page: 1, last_page: 3, total: 6 }
    vm.changePage(2)
    await tick()

    const paged = calls[0].params
    check('page 2 requested', paged.page, 2)
    check('filters survived the page change',
      [paged.status, paged.scope, paged.parent, paged.q], ['2', 'sub', '1', 'mob'])

    // Sorting toggles direction on the same column and resets on a new one.
    calls.length = 0
    vm.orderby = 'title'
    vm.ordertype = 'asc'
    vm.sortBy('title')
    check('same column flips direction', vm.ordertype, 'desc')
    vm.sortBy('created_at')
    check('new column sorts ascending', [vm.orderby, vm.ordertype], ['created_at', 'asc'])
    check('sorting returns to page 1', calls[calls.length - 1].params.page, 1)

    check('aria-sort is exposed', vm.ariaSort('created_at'), 'ascending')
    check('idle columns say so', vm.ariaSort('title'), 'none')

    // Reset.
    vm.resetFilter()
    check('reset clears search', vm.search, '')
    check('reset clears filters', vm.activeFilterCount, 0)
  }

  // ---- LIST: selection and bulk ----------------------------------------------

  console.log('\nList — selection and bulk actions')
  {
    const { vm, calls } = makeHarness(listOptions)
    await vm.fetchCategories()

    check('nothing selected initially', vm.cbList.length, 0)
    check('select-all unchecked', vm.allOnPageSelected, false)

    vm.toggleSelectAll({ target: { checked: true } })
    check('select-all selects the page', vm.cbList.length, 2)
    check('select-all now reads as checked', vm.allOnPageSelected, true)

    vm.cbList = ['1']
    check('a partial selection is indeterminate', vm.someOnPageSelected, true)

    vm.cbList = ['1', '2']
    calls.length = 0
    await vm.bulkStatus(2)

    const bulk = calls.find(c => c.api === 'setCategoryStatus')
    check('bulk status sent both ids', bulk.params.id, '1,2')
    check('bulk status sent the status', bulk.params.status, 2)
    check('selection cleared afterwards', vm.cbList.length, 0)

    // A selection that is no longer on the page is dropped rather than acted on.
    vm.cbList = ['1', '999']
    await vm.fetchCategories()
    check('off-page selection dropped', vm.cbList, ['1'])
  }

  // ---- LIST: status toggle ---------------------------------------------------

  console.log('\nList — status toggle')
  {
    const { vm, calls, toasts } = makeHarness(listOptions)
    await vm.fetchCategories()

    const row = vm.itemList[0]
    check('starts published', row.status, 1)

    await vm.toggleStatus(row)
    const sent = calls.find(c => c.api === 'setCategoryStatus')
    check('asked the server for the opposite status', sent.params.status, 2)
    check('row updated after confirmation', row.status, 2)
    check('cards kept in step', vm.stats.active_categories, 4)
  }

  {
    // A refused change must not show as applied.
    const { vm, toasts } = makeHarness(listOptions, {
      setCategoryStatus: () => new Error('Unauthorized.')
    })
    await vm.fetchCategories()

    const row = vm.itemList[0]
    const before = row.status

    await vm.toggleStatus(row)

    check('row unchanged after a refusal', row.status, before)
    ok('an error toast was raised', toasts.some(t => t.action === 'ui/setToastError'))
    check('the row is usable again', vm.busyId, null)
  }

  // ---- LIST: delete ----------------------------------------------------------

  console.log('\nList — delete')
  {
    const { vm, calls, toasts } = makeHarness(listOptions)
    await vm.fetchCategories()

    await vm.deleteNode(vm.itemList[0])

    check('dialog opened', vm.showDeleteConfirm, true)
    check('impact fetched', vm.impact.subcategories, 2)
    check('products warned about', vm.impact.products_including_subcategories, 9)
    check('default keeps the children', vm.childrenMode, 'reparent')

    calls.length = 0
    vm.childrenMode = 'cascade'
    await vm.executeDelete()

    const del = calls.find(c => c.api === 'deleteCategory')
    check('delete carried the mode', del.params, '1?children=cascade')
    check('dialog closed on success', vm.showDeleteConfirm, false)
    ok('list was refreshed', calls.some(c => c.api === 'getCategories'))
  }

  {
    // A failed delete must not look like a success.
    const { vm, toasts } = makeHarness(listOptions, {
      deleteCategory: () => new Error('Category could not be deleted.')
    })
    await vm.fetchCategories()
    await vm.deleteNode(vm.itemList[0])
    await vm.executeDelete()

    check('dialog stays open', vm.showDeleteConfirm, true)
    ok('an error toast was raised', toasts.some(t => t.action === 'ui/setToastError'))
    ok('no success toast', !toasts.some(t => t.action === 'ui/setToastMessage'))
    check('not left stuck in a deleting state', vm.deleting, false)
  }

  // ---- LIST: empty and error states -----------------------------------------

  console.log('\nList — empty and error states')
  {
    const { vm } = makeHarness(listOptions, {
      getCategories: () => ({
        data: [], current_page: 1, last_page: 1, from: null, to: null, total: 0,
        stats: { total: 0, root_categories: 0, sub_categories: 0, active_categories: 0, inactive_categories: 0, with_products: 0, growth: 0 }
      })
    })

    await vm.fetchCategories()

    check('no rows', vm.hasRows, false)
    check('not treated as filtered', vm.isFiltered, false)
    check('empty summary', vm.resultSummary, 'No categories yet')

    vm.search = 'zzz'
    check('a search makes it the other empty state', vm.isFiltered, true)
    check('and the summary says so', vm.resultSummary, 'No categories match your filters')
  }

  {
    const { vm, toasts } = makeHarness(listOptions, {
      getCategories: () => new Error('The server is unavailable.')
    })

    await vm.fetchCategories()

    check('error captured for the page', vm.errorMessage, 'The server is unavailable.')
    check('no stale rows left on screen', vm.itemList.length, 0)
    check('loading finished', vm.loading, false)
    ok('error toast raised', toasts.some(t => t.action === 'ui/setToastError'))
  }

  // ---- LIST: images and pagination window ------------------------------------

  console.log('\nList — images and pagination')
  {
    const { vm } = makeHarness(listOptions)
    await vm.fetchCategories()

    check('uses the URL the server built', vm.rowImage(vm.itemList[0]), 'http://img/a.png')
    check('falls back when there is none',
      vm.rowImage({ image: null }), 'http://img/default-image.webp')

    const target = { dataset: {}, src: '' }
    vm.onImageError({ target })
    check('a broken image falls back once', target.src, 'http://img/default-image.webp')

    target.src = 'x'
    vm.onImageError({ target })
    check('and does not loop', target.src, 'x')

    vm.pagination = { current_page: 8, last_page: 20, total: 400 }
    const labels = vm.pageWindow.map(p => p.label)
    check('pager is windowed, not one button per page', labels, ['1', '…', '7', '8', '9', '…', '20'])

    vm.pagination = { current_page: 1, last_page: 3, total: 6 }
    check('a short pager lists every page', vm.pageWindow.map(p => p.label), ['1', '2', '3'])
  }

  // ---- FORM: slug ------------------------------------------------------------

  console.log('\nForm — slug')
  {
    const { vm, calls } = makeHarness(formOptions)

    vm.result.title = 'Mobile Phones'
    vm.titleChanged()
    check('slug derived from the name', vm.result.slug, 'mobile-phones')

    // Once the admin writes their own, the title stops overwriting it.
    vm.result.slug = 'my-custom-slug'
    vm.slugEdited()
    vm.result.title = 'Something Else Entirely'
    vm.titleChanged()
    check('a hand-written slug survives a title edit', vm.result.slug, 'my-custom-slug')

    // Format is checked in the browser too.
    vm.result.slug = 'Not A Slug!'
    vm.checkSlug()
    check('a malformed slug is flagged', vm.slugState, 'invalid')

    // Availability is asked of the server, debounced.
    calls.length = 0
    vm.result.slug = 'mobile'
    vm.checkSlug()
    check('shows a checking state', vm.slugState, 'checking')
    check('nothing sent yet', calls.length, 0)

    await wait(460)
    check('one availability request', calls.filter(c => c.api === 'getCategorySlugAvailable').length, 1)
    check('reported as taken', vm.slugState, 'taken')
    check('a free alternative offered', vm.slugSuggestion, 'mobile-2')

    vm.useSuggestedSlug()
    check('the suggestion can be taken', vm.result.slug, 'mobile-2')
  }

  // ---- FORM: parent picker ---------------------------------------------------

  console.log('\nForm — parent picker')
  {
    const { vm, calls } = makeHarness(formOptions)
    vm.result.id = '1'

    await vm.loadParentOptions()

    const sent = calls.find(c => c.api === 'getAllCategories')
    check('asks for every level', sent.params.scope, 'all')
    check('and excludes the category being edited', sent.params.exclude, '1')
    check('options are keyed by id', Object.keys(vm.parentOptions).sort(), ['1', '2', '3'])
    check('children are indented under their parent', vm.parentOptions['2'].title, '— Apple')
    check('roots are not', vm.parentOptions['1'].title, 'Mobile')
    check('loading state cleared', vm.parentsLoading, false)
  }

  // ---- FORM: images ----------------------------------------------------------

  console.log('\nForm — images')
  {
    const { vm, toasts } = makeHarness(formOptions)

    check('a PDF is refused', vm.validateImage({ type: 'application/pdf', size: 1000 }, 2), false)
    check('an oversized PNG is refused', vm.validateImage({ type: 'image/png', size: 5 * 1024 * 1024 }, 2), false)
    check('a small PNG is accepted', vm.validateImage({ type: 'image/png', size: 100 * 1024 }, 2), true)
    check('SVG is accepted', vm.validateImage({ type: 'image/svg+xml', size: 1000 }, 2), true)
    ok('refusals are surfaced, not silent', toasts.filter(t => t.action === 'ui/setToastError').length >= 2)

    vm.iconPreview = 'data:image/png;base64,xxx'
    vm.result.icon = 'data:image/png;base64,xxx'
    vm.removeIcon()
    check('removal clears the preview', vm.iconPreview, null)
    check('and sends an explicit null the API reads as "remove"', vm.result.icon, null)
    check('including the stored column', vm.result.image, null)
  }

  // ---- FORM: SEO score and canonical -----------------------------------------

  console.log('\nForm — SEO panel')
  {
    const { vm } = makeHarness(formOptions)

    vm.result.meta_title = ''
    vm.result.meta_description = ''
    vm.result.short_description = ''
    vm.result.slug = ''
    vm.keywords = []

    check('an empty form scores zero, not 85', vm.seoScore.score, 0)

    vm.result.meta_title = 'A meta title of a perfectly reasonable length'
    vm.result.meta_description = 'x'.repeat(160)
    vm.result.short_description = 'Short and useful.'
    vm.result.slug = 'a-slug'
    vm.keywords = ['one', 'two', 'three']

    check('a complete form scores 100', vm.seoScore.score, 100)
    ok('every check reports individually', vm.seoScore.checks.length === 5)
    ok('all passing', vm.seoScore.checks.every(c => c.ok))

    vm.result.canonical_url = ''
    ok('canonical no longer advertises yourstore.com',
      vm.canonicalUrl.indexOf('yourstore.com') === -1)
    ok('and ends in the slug', vm.canonicalUrl.endsWith('/category/a-slug'))
  }

  // ---- FORM: delete ----------------------------------------------------------

  console.log('\nForm — delete')
  {
    const { vm, calls, toasts } = makeHarness(formOptions)
    vm.result.id = '1'
    vm.result.title = 'Mobile'

    await vm.confirmDelete()

    check('a dialog rather than window.confirm', vm.showDeleteConfirm, true)
    check('impact loaded', vm.impact.subcategories, 2)

    calls.length = 0
    await vm.executeDelete()

    const del = calls.find(c => c.api === 'deleteCategory')
    check('delete sent with the chosen mode', del.params, '1?children=reparent')
    ok('redirected back to the list', calls.some(c => c.api === 'router.push' && c.params === '/categories'))
  }

  {
    const { vm, calls, toasts } = makeHarness(formOptions, {
      deleteCategory: () => new Error('Not allowed.')
    })
    vm.result.id = '1'

    await vm.confirmDelete()
    await vm.executeDelete()

    check('dialog stays open on failure', vm.showDeleteConfirm, true)
    ok('no navigation away', !calls.some(c => c.api === 'router.push'))
    ok('error surfaced', toasts.some(t => t.action === 'ui/setToastError'))
  }


  // ---- LIST: the hierarchy ---------------------------------------------------

  /*
   * The tree arrives already ordered and already measured — the server sends
   * depth, the guide flags and how many children survived the filters. What is
   * decided here is what gets drawn: collapsing, the placeholder under an empty
   * parent, and which columns the layout still needs.
   */
  console.log('\nList — hierarchy')
  {
    const { vm, calls } = makeHarness(listOptions, { getCategories: () => treeResponse() })

    check('tree is the default layout', vm.view, 'tree')
    ok('and the page knows it', vm.isTree)

    await vm.fetchCategories()

    ok('the layout is asked of the server, not sorted locally',
      calls.some(c => c.api === 'getCategories' && c.params.view === 'tree'))

    // Mobile > Apple, Samsung | Accessories > USB Cables | Audio (no children)
    check('rows arrive in branch order',
      vm.itemList.map(r => r.title),
      ['Mobile', 'Apple', 'Samsung', 'Accessories', 'USB Cables', 'Audio'])

    check('parents and children are told apart by depth',
      vm.itemList.map(r => vm.rowDepth(r)), [0, 1, 1, 0, 1, 0])

    check('a childless parent gets a placeholder row instead of silence',
      vm.displayRows.map(r => r.placeholder ? '(note)' : r.title),
      ['Mobile', 'Apple', 'Samsung', 'Accessories', 'USB Cables', '(note)', 'Audio', '(note)'])

    // The guides that draw the branch.
    const apple = vm.itemList[1]
    const samsung = vm.itemList[2]

    ok('a middle child is not marked as the last', !apple.is_last_child)
    ok('the last child is', samsung.is_last_child)
    check('the root level draws no gutter of its own', vm.gutters(samsung), [])

    check('a parent counts only the children actually on screen', vm.branchSize(vm.itemList[0]), 2)
    check('and a leaf has none', vm.branchSize(apple), 0)
  }

  console.log('\nList — collapsing a branch')
  {
    const { vm } = makeHarness(listOptions, { getCategories: () => treeResponse() })
    await vm.fetchCategories()

    vm.toggleBranch(vm.itemList[0])          // Mobile
    await tick()

    check('a collapsed parent takes its children off screen',
      vm.displayRows.map(r => r.placeholder ? '(empty)' : r.title),
      ['Mobile', 'Accessories', 'USB Cables', '(empty)', 'Audio', '(empty)'])

    ok('a collapsed parent does not claim to be empty',
      !vm.displayRows.some(r => r.placeholder && r.parent.title === 'Mobile'))

    /* The select-all checkbox has to cover what is on screen. Reading it off
     * the raw page would quietly include the two hidden children, so a bulk
     * delete could take rows the admin never saw. */
    check('select-all covers only visible rows', vm.pageIds.length, 4)

    vm.toggleSelectAll({ target: { checked: true } })
    ok('and the hidden children are not selected', vm.cbList.indexOf('2') === -1)

    vm.toggleBranch(vm.itemList[0])
    await tick()
    check('expanding puts them back', vm.displayRows.filter(r => !r.placeholder).length, 6)

    vm.collapseAll()
    await tick()
    check('collapse all leaves only the parents',
      vm.displayRows.filter(r => !r.placeholder).map(r => r.title),
      ['Mobile', 'Accessories', 'Audio'])
    ok('and the control flips to "Expand all"', !vm.allExpanded)

    vm.expandAll()
    await tick()
    ok('expand all restores every branch', vm.allExpanded)

    // A leaf has nothing to fold away, so the toggle must not act on it.
    const before = vm.displayRows.length
    vm.toggleBranch(vm.itemList[1])
    await tick()
    check('toggling a leaf does nothing', vm.displayRows.length, before)
  }

  console.log('\nList — hierarchy, filters and layout')
  {
    const { vm, calls } = makeHarness(listOptions, { getCategories: () => treeResponse() })
    await vm.fetchCategories()

    check('the Parent column is dropped — the tree says it already',
      vm.visibleColumns.some(c => c.key === 'parent'), false)
    check('and the colspan follows it', vm.columnCount, vm.visibleColumns.length + 3)

    check('the summary counts parents, not rows',
      vm.resultSummary, 'Showing 1–3 of 3 parent categories, 3 subcategories')

    /* A parent kept only so a match below it has somewhere to hang. It says so,
     * rather than reading as a result that should not be in the list. */
    ok('a context-only parent is marked as one', vm.isContext(vm.itemList[3]))
    ok('a real match is not', !vm.isContext(vm.itemList[0]))

    /* Filtered-away children are a different fact from no children at all. */
    const hiddenNote = vm.displayRows.find(r => r.placeholder && r.hidden)
    check('a parent whose children were filtered out says so, not "none"',
      hiddenNote && hiddenNote.hidden, 2)

    // The nesting cap lives on the server; the menu just honours what it sent.
    ok('a root may take a subcategory', vm.canAddChild(vm.itemList[0]))
    ok('a subcategory at the depth limit may not', !vm.canAddChild(vm.itemList[1]))

    vm.setView('flat')
    await tick()

    ok('switching layout re-asks the server',
      calls.some(c => c.api === 'getCategories' && c.params.view === 'flat'))
    check('and the Parent column comes back',
      vm.visibleColumns.some(c => c.key === 'parent'), true)
    check('a layout switch clears a selection made in the other one', vm.cbList.length, 0)
  }
  // ----------------------------------------------------------------------------

  console.log(`\n${checks} checks, ${failures ? failures + ' FAILED' : 'all passed'}`)
  process.exit(failures ? 1 : 0)
}

run().catch(e => { console.error(e); process.exit(1) })
