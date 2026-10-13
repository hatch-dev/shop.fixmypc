/*
 * The image placeholder (plugins/image-placeholder.js, 2026-10-13).
 *
 * Run with: node tests/image-placeholder.check.js
 *
 * Any <img> that fails to load gets an inline placeholder - after the
 * component's own @error has had its turn - and the admin panel ships the
 * same file. Failures are dispatched by hand: jsdom does not fetch images.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')
const babel = require(path.join(NM, '@babel/core'))
const { JSDOM } = require(path.join(NM, 'jsdom'))

const SOURCE = path.join(ROOT, 'plugins/image-placeholder.js')

function load () {
  const code = babel.transformSync(fs.readFileSync(SOURCE, 'utf8'), {
    filename: 'image-placeholder.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, () => ({}))

  return module.exports
}

const { install, IMAGE_PLACEHOLDER } = load()

function page (html) {
  const dom = new JSDOM(`<!doctype html><html><head></head><body>${html}</body></html>`, { pretendToBeVisual: true })
  install(dom.window.document, dom.window)
  return dom.window
}

const tick = win => new Promise(resolve => win.setTimeout(resolve, 5))
const fail = (win, img) => img.dispatchEvent(new win.Event('error'))

const checks = []
const check = (name, fn) => checks.push([name, fn])

check('a failed image gets the placeholder', async () => {
  const win = page('<img id="a" src="https://x.test/missing.webp" alt="Slider image">')
  const img = win.document.getElementById('a')

  fail(win, img)
  await tick(win)

  assert.strictEqual(img.getAttribute('src'), IMAGE_PLACEHOLDER)
  assert.ok(img.classList.contains('img-placeholder'))
  assert.strictEqual(img.getAttribute('alt'), 'Slider image', 'the alt text stays for screen readers')
})

check("a component's own fallback gets the first try", async () => {
  const win = page('<img id="a" src="https://x.test/missing.webp">')
  const img = win.document.getElementById('a')
  // What LazyImage and the slider do on error: try the shop's default image.
  img.addEventListener('error', () => {
    if (!img.getAttribute('src').includes('default')) img.setAttribute('src', 'https://x.test/default-image.webp')
  })

  fail(win, img)
  await tick(win)
  assert.strictEqual(img.getAttribute('src'), 'https://x.test/default-image.webp', 'left to load')

  // ...and when that fails too, the placeholder.
  fail(win, img)
  await tick(win)
  assert.strictEqual(img.getAttribute('src'), IMAGE_PLACEHOLDER)
})

check('srcset and <picture> sources are cleared so they cannot win over it', async () => {
  const win = page('<picture><source srcset="https://x.test/a.avif"><img id="a" src="https://x.test/a.jpg" srcset="https://x.test/a-2x.jpg 2x" sizes="100vw"></picture>')
  const img = win.document.getElementById('a')

  fail(win, img)
  await tick(win)

  assert.strictEqual(img.hasAttribute('srcset'), false)
  assert.strictEqual(img.hasAttribute('sizes'), false)
  assert.strictEqual(win.document.querySelectorAll('source').length, 0)
})

check('an image can opt out', async () => {
  const win = page('<img id="a" src="https://x.test/missing.webp" data-no-placeholder>')
  const img = win.document.getElementById('a')

  fail(win, img)
  await tick(win)

  assert.strictEqual(img.getAttribute('src'), 'https://x.test/missing.webp')
})

check('an image the component removed is left alone', async () => {
  const win = page('<img id="a" src="https://x.test/logo.png">')
  const img = win.document.getElementById('a')
  // The auth layout swaps a broken logo for text.
  img.addEventListener('error', () => img.remove())

  fail(win, img)
  await tick(win)

  assert.strictEqual(img.getAttribute('src'), 'https://x.test/logo.png')
})

check('the placeholder itself never loops', async () => {
  const win = page('<img id="a" src="https://x.test/missing.webp">')
  const img = win.document.getElementById('a')

  fail(win, img); await tick(win)
  fail(win, img); await tick(win)

  assert.strictEqual(img.getAttribute('src'), IMAGE_PLACEHOLDER)
})

check('a real image that later loads sheds the placeholder look', async () => {
  const win = page('<img id="a" src="https://x.test/missing.webp">')
  const img = win.document.getElementById('a')

  fail(win, img); await tick(win)
  img.setAttribute('src', 'https://x.test/real.webp')
  img.dispatchEvent(new win.Event('load'))

  assert.strictEqual(img.classList.contains('img-placeholder'), false)
})

check('it installs once, with its style', async () => {
  const win = page('')
  install(win.document, win)

  assert.strictEqual(win.document.querySelectorAll('style').length, 1)
  assert.ok(win.document.querySelector('style').textContent.includes('object-fit:scale-down'))
})

check('the admin panel ships the same file', async () => {
  const admin = path.resolve(ROOT, '../admin-panel/plugins/image-placeholder.js')
  assert.strictEqual(fs.readFileSync(admin, 'utf8'), fs.readFileSync(SOURCE, 'utf8'))
})

;(async () => {
  let failed = 0
  for (const [name, fn] of checks) {
    try { await fn(); console.log('ok -', name) } catch (e) { failed++; console.log('FAIL -', name, '\n  ', e.message) }
  }
  console.log(`\n${checks.length - failed} passed, ${failed} failed`)
  process.exitCode = failed ? 1 : 0
})()
