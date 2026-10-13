/*
 * What the skeleton components must actually render.
 *
 * Run with: npm run check:skeletons
 *
 * These stand in for content on every data-driven page in the panel, so the
 * things worth pinning down are the ones that go wrong silently: a placeholder
 * that reserves the wrong box shifts the page when the real content lands,
 * and a placeholder that renders nothing at all just reintroduces the blank
 * screen it was added to remove. Neither throws.
 *
 * The SFCs are compiled and server-rendered here rather than string-matched,
 * so what is asserted is the output a reader would get.
 */
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const cwd = process.cwd()
const compiler = require(cwd + '/node_modules/vue-template-compiler')
const Vue = require(cwd + '/node_modules/vue')
const renderer = require(cwd + '/node_modules/vue-server-renderer').createRenderer()

Vue.config.productionTip = false
Vue.config.devtools = false

const dir = path.join(cwd, 'components', 'skeleton')

// Build each component's options object, then register globally so the
// composite skeletons can find their children by tag.
const defs = {}
for (const file of fs.readdirSync(dir).sort()) {
  if (!file.endsWith('.vue')) continue
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(dir, file), 'utf8'))
  const sandbox = { module: { exports: {} }, exports: {}, require }
  sandbox.exports = sandbox.module.exports
  vm.createContext(sandbox)
  vm.runInContext(sfc.script.content.replace(/export default/, 'module.exports ='), sandbox)
  const options = sandbox.module.exports
  options.template = sfc.template.content
  defs[options.name] = options
}
for (const [name, options] of Object.entries(defs)) Vue.component(name, options)

const render = (template, data) =>
  new Promise((resolve, reject) => {
    const app = new Vue({ template: `<div>${template}</div>`, data: () => (data || {}) })
    renderer.renderToString(app, (err, html) => (err ? reject(err) : resolve(html)))
  })

const checks = []
const check = (label, fn) => checks.push([label, fn])

check('SkeletonBase renders one shimmering bar at the asked size', async () => {
  const html = await render('<skeleton-base :height="20" width="120px" />')
  if (!html.includes('sk-base')) throw new Error('missing .sk-base')
  if (!/height:\s*20px/.test(html)) throw new Error('height not applied: ' + html)
  if (!/width:\s*120px/.test(html)) throw new Error('width not applied: ' + html)
})

check('SkeletonBase circle is square and round', async () => {
  const html = await render('<skeleton-base :width="48" circle />')
  if (!html.includes('sk-base--circle')) throw new Error('missing circle class')
  if (!/width:\s*48px/.test(html) || !/height:\s*48px/.test(html)) throw new Error('not square: ' + html)
})

check('SkeletonText tapers its last line only', async () => {
  const html = await render('<skeleton-text :lines="3" />')
  const widths = [...html.matchAll(/width:\s*([0-9]+%)/g)].map(m => m[1])
  if (widths.length !== 3) throw new Error('expected 3 lines, got ' + widths.length)
  if (widths[0] !== '100%' || widths[2] !== '65%') throw new Error('bad taper: ' + widths.join(','))
})

check('SkeletonText with one line keeps full width', async () => {
  const html = await render('<skeleton-text :lines="1" />')
  if (/65%/.test(html)) throw new Error('a single line should not taper')
})

check('SkeletonImage reserves the box by ratio', async () => {
  const html = await render('<skeleton-image ratio="4:3" />')
  if (!/padding-bottom:\s*75%/.test(html)) throw new Error('4:3 should reserve 75%: ' + html)
})

check('SkeletonImage falls back to a square on a bad ratio', async () => {
  const html = await render('<skeleton-image ratio="banana" />')
  if (!/padding-bottom:\s*100%/.test(html)) throw new Error('should fall back to 100%: ' + html)
})

check('SkeletonImage prefers an explicit height over the ratio', async () => {
  const html = await render('<skeleton-image ratio="16:9" height="300px" />')
  if (/padding-bottom/.test(html)) throw new Error('ratio should not be used when height is given')
  if (!/height:\s*300px/.test(html)) throw new Error('height not applied')
})

check('SkeletonProduct wears the real tile class names', async () => {
  const html = await render('<skeleton-product />')
  for (const cls of ['p-tile', 'card-wrapper', 'img-wrapper', 'product-tile-content']) {
    if (!html.includes(cls)) throw new Error('missing ' + cls + ' (it inherits the real geometry from these)')
  }
})

check('SkeletonProductGrid draws the asked number on the real grid', async () => {
  const html = await render('<skeleton-product-grid :count="6" />')
  const tiles = html.split('p-tile sk-product').length - 1
  if (tiles !== 6) throw new Error('expected 6 tiles, got ' + tiles)
  if (!html.includes('tile-container')) throw new Error('not using the real .tile-container')
})

check('SkeletonProductGrid rail form does not use the page grid', async () => {
  const html = await render('<skeleton-product-grid :count="3" rail :rail-item-width="200" />')
  if (html.includes('tile-container')) throw new Error('rail should not render the page grid')
  if (!/width:\s*200px/.test(html)) throw new Error('rail item width not applied')
})

check('SkeletonTable lays rows on one grid with the header', async () => {
  const html = await render('<skeleton-table :rows="4" :cols="5" />')
  // the header carries both sk-table-row and sk-table-row--head, so count elements
  const rows = (html.match(/class="sk-table-row/g) || []).length
  const heads = (html.match(/sk-table-row--head/g) || []).length
  if (rows !== 5) throw new Error('expected 4 rows + 1 header, got ' + rows)
  if (heads !== 1) throw new Error('expected exactly one header strip, got ' + heads)
  if (!/grid-template-columns:\s*minmax\(0, 1\.6fr\) repeat\(4,/.test(html)) throw new Error('column track wrong: ' + html.slice(0, 300))
})

check('SkeletonList varies row widths rather than repeating one', async () => {
  const html = await render('<skeleton-list :rows="4" />')
  const widths = [...html.matchAll(/width:\s*([0-9]+%)/g)].map(m => m[1])
  if (new Set(widths).size < 3) throw new Error('rows are too uniform: ' + widths.join(','))
})

check('SkeletonPage renders every variant', async () => {
  for (const v of ['listing', 'detail', 'cart', 'checkout', 'account', 'home', 'generic']) {
    const html = await render(`<skeleton-page variant="${v}" :count="3" />`)
    if (!html.includes('sk-page')) throw new Error(v + ': nothing rendered')
    if (html.length < 400) throw new Error(v + ': suspiciously empty (' + html.length + ' bytes)')
  }
})

check('SkeletonPage announces itself to a screen reader', async () => {
  const html = await render('<skeleton-page variant="listing" label="Loading products" />')
  if (!html.includes('aria-busy="true"')) throw new Error('missing aria-busy')
  if (!html.includes('Loading products')) throw new Error('missing the spoken label')
})

check('a still skeleton drops the animation', async () => {
  const html = await render('<skeleton-base :animated="false" />')
  if (!html.includes('sk-base--still')) throw new Error('missing the static modifier')
})

;(async () => {
  let failed = 0
  for (const [label, fn] of checks) {
    try { await fn(); console.log('  ok   ' + label) }
    catch (e) { failed++; console.log('  FAIL ' + label + '\n         ' + e.message) }
  }
  console.log('\n' + checks.length + ' checks, ' + failed + ' failing')
  process.exit(failed ? 1 : 0)
})()
