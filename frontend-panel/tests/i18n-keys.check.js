/*
 * No translation key may reach a customer.
 *
 * Run with: npm run check:i18n-keys
 *
 * `detailRight.freeCollection` was printed in the buy box, beside the price,
 * because the component asked for a key no language file defined and
 * vue-i18n's default for an unknown key is to render the key. This fails the
 * build for any key English does not answer, and checks that the fallback
 * still produces readable text if one ever slips through.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const SKIP = new Set([
  'node_modules', 'dist', '.nuxt', '.git', 'static', '_backup', 'coverage', '.output', 'tests',
])

function walk(dir, out) {
  let entries
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true })
  } catch (e) {
    return out
  }
  for (const entry of entries) {
    if (SKIP.has(entry.name)) continue
    const p = path.join(dir, entry.name)
    if (entry.isDirectory()) walk(p, out)
    else if (/\.(vue|js)$/.test(entry.name)) out.push(p)
  }
  return out
}

function lookup(bundle, key) {
  let cur = bundle
  for (const segment of key.split('.')) {
    if (cur === null || typeof cur !== 'object' || !(segment in cur)) return undefined
    cur = cur[segment]
  }
  return typeof cur === 'string' ? cur : undefined
}

const english = JSON.parse(fs.readFileSync(path.join(ROOT, 'lang/en.json'), 'utf8'))

/*
 * A key built in code ($t('myOrders.refund.' + r.key)) is found as its prefix.
 * The prefix names a group: it has to exist and hold text, the rest comes at run time.
 */
function present(bundle, key) {
  if (!key.endsWith('.')) return lookup(bundle, key) !== undefined

  let cur = bundle
  for (const segment of key.slice(0, -1).split('.')) {
    if (cur === null || typeof cur !== 'object' || !(segment in cur)) return false
    cur = cur[segment]
  }
  return cur !== null && typeof cur === 'object' && Object.values(cur).some(v => typeof v === 'string')
}

const OTHER_LANGUAGES = ['tr', 'ar', 'fr', 'hi']
const bundles = Object.fromEntries(OTHER_LANGUAGES.map(code =>
  [code, JSON.parse(fs.readFileSync(path.join(ROOT, `lang/${code}.json`), 'utf8'))]))

const referenced = new Map()

for (const file of walk(ROOT, [])) {
  const source = fs.readFileSync(file, 'utf8')
  const rx = /\$tc?\(\s*['"]([A-Za-z0-9_$.]+)['"]/g
  let match
  while ((match = rx.exec(source))) {
    if (!referenced.has(match[1])) referenced.set(match[1], new Set())
    referenced.get(match[1]).add(path.relative(ROOT, file).split(path.sep).join('/'))
  }
}

let failures = 0
function test(name, fn) {
  try {
    fn()
    console.log('  ok   ' + name)
  } catch (e) {
    failures++
    console.log('  FAIL ' + name + '\n         ' + e.message)
  }
}

console.log('\ntranslation keys')

test('every key the storefront asks for has English text', () => {
  const missing = [...referenced.entries()]
    .filter(([key]) => !present(english, key))
    .map(([key, files]) => `${key} (${[...files].join(', ')})`)

  assert.deepStrictEqual(
    missing, [],
    'these would render as raw keys:\n           ' + missing.join('\n           ')
  )
})

test('every key the storefront asks for is translated in every language', () => {
  // Otherwise that text silently stays English for a shopper who picked Turkish.
  const missing = []
  for (const code of OTHER_LANGUAGES) {
    for (const [key, files] of referenced) {
      if (present(english, key) && !present(bundles[code], key)) {
        missing.push(`${code}: ${key} (${[...files].join(', ')})`)
      }
    }
  }

  assert.deepStrictEqual(
    missing, [],
    'these fall back to English:\n           ' + missing.join('\n           ')
  )
})

test('the key from the report is translated', () => {
  assert.strictEqual(lookup(english, 'detailRight.freeCollection'), 'Free collection')
  assert.notStrictEqual(lookup(english, 'detailRight.collection'), undefined)
  assert.notStrictEqual(lookup(english, 'detailRight.collectionIn'), undefined)
})

test('no English string is itself a key', () => {
  // A placeholder like "detailRight.freeCollection": "detailRight.freeCollection"
  // passes the check above and still shows the customer a key.
  const offenders = []

  const visit = (node, prefix) => {
    for (const [key, value] of Object.entries(node)) {
      const here = prefix ? prefix + '.' + key : key
      if (value && typeof value === 'object') visit(value, here)
      else if (typeof value === 'string' && /^[a-z][A-Za-z0-9]*(\.[A-Za-z0-9_]+)+$/.test(value)) {
        offenders.push(here + ' = ' + value)
      }
    }
  }

  visit(english, '')

  assert.deepStrictEqual(offenders, [])
})

console.log('\nfallback when a string is missing anyway')

const { humanizeKey } = (() => {
  const src = fs.readFileSync(path.join(ROOT, 'lang/missing-handler.js'), 'utf8')
  const NM = path.join(ROOT, 'node_modules')
  const code = require(path.join(NM, '@babel/core')).transformSync(src, {
    filename: 'missing-handler.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code
  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', 'process', code)(
    module, module.exports, require, { env: { NODE_ENV: 'production' } }
  )
  return module.exports
})()

test('a key becomes readable text, never the key itself', () => {
  assert.strictEqual(humanizeKey('detailRight.freeCollection'), 'Free collection')
  assert.strictEqual(humanizeKey('cart.outOfStock'), 'Out of stock')
  assert.strictEqual(humanizeKey('checkout.grand_total'), 'Grand total')
  assert.strictEqual(humanizeKey('from'), 'From')
})

test('it never returns something that still looks like a key', () => {
  for (const key of ['a.b.cDe', 'x', 'namespace.key', 'deep.nested.someValueHere']) {
    const text = humanizeKey(key)
    assert.ok(!text.includes('.'), key + ' produced "' + text + '"')
  }
})

test('rubbish in does not throw', () => {
  assert.strictEqual(humanizeKey(''), '')
  assert.strictEqual(humanizeKey(null), '')
  assert.strictEqual(humanizeKey(undefined), '')
})

console.log('')

if (failures) {
  console.log(failures + ' failing\n')
  process.exit(1)
}

console.log('all passing\n')
