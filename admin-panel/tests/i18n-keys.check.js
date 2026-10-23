/*
 * No translation key may reach an admin, in any language.
 *
 * Run with: npm run check:i18n-keys
 *
 * The Turkish dashboard printed "index.revenue" and "index.liveActivity":
 * new keys had been added to en.json only, and no fallback locale was set,
 * so vue-i18n rendered the key itself. This fails for any key English does
 * not answer, any key another language lacks, and any placeholder a
 * translation dropped.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const SKIP = new Set(['node_modules', 'dist', '.nuxt', '.git', 'static', 'tests', 'lang'])
const OTHER_LANGUAGES = ['tr', 'ar', 'fr', 'hi']

function walk(dir, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue
    const p = path.join(dir, entry.name)
    if (entry.isDirectory()) walk(p, out)
    else if (/\.(vue|js)$/.test(entry.name)) out.push(p)
  }
  return out
}

function flatten(node, prefix = '', out = {}) {
  for (const [key, value] of Object.entries(node)) {
    if (value && typeof value === 'object') flatten(value, prefix + key + '.', out)
    else out[prefix + key] = value
  }
  return out
}

const load = code => flatten(JSON.parse(fs.readFileSync(path.join(ROOT, `lang/${code}.json`), 'utf8')))
const english = load('en')
const bundles = Object.fromEntries(OTHER_LANGUAGES.map(code => [code, load(code)]))

// A key built in code ($t('x.status.' + s), $t('x.per_' + unit)) is found as its prefix: the group has to exist.
const present = (bundle, key) => /[._]$/.test(key)
  ? Object.keys(bundle).some(k => k.startsWith(key))
  : typeof bundle[key] === 'string'

const referenced = new Map()
for (const file of walk(ROOT, [])) {
  const source = fs.readFileSync(file, 'utf8')
  // $t('a.b'), $tc('a.b'), and the sidebar's t('a.b')
  const rx = /(?:\$tc?|\bt|i18n\.t)\(\s*['"]([A-Za-z0-9_$]+\.[A-Za-z0-9_$.]+)['"]/g
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

console.log('\nadmin translation keys (' + referenced.size + ' referenced)')

test('every key the admin asks for has English text', () => {
  const missing = [...referenced].filter(([key]) => !present(english, key))
    .map(([key, files]) => `${key} (${[...files].join(', ')})`)
  assert.deepStrictEqual(missing, [], 'these render as raw keys:\n           ' + missing.join('\n           '))
})

test('every language has every English key', () => {
  const missing = []
  for (const code of OTHER_LANGUAGES) {
    for (const key of Object.keys(english)) if (!(key in bundles[code])) missing.push(`${code}: ${key}`)
  }
  assert.deepStrictEqual(missing.slice(0, 40), [], missing.length + ' fall back to English')
})

test('translations keep every {placeholder}', () => {
  const names = s => (String(s).match(/\{\w+\}/g) || []).sort().join(',')
  const broken = []
  for (const code of OTHER_LANGUAGES) {
    for (const [key, text] of Object.entries(english)) {
      if (key in bundles[code] && names(bundles[code][key]) !== names(text)) broken.push(`${code}: ${key}`)
    }
  }
  assert.deepStrictEqual(broken, [])
})

test('no "%{" anywhere (vue-i18n 8 swallows the % before a placeholder)', () => {
  const bad = []
  for (const [code, bundle] of Object.entries({ en: english, ...bundles })) {
    for (const [key, text] of Object.entries(bundle)) if (/%\{/.test(text)) bad.push(`${code}: ${key}`)
  }
  assert.deepStrictEqual(bad, [])
})

test('fallbackLocale is English, so a slip shows English text and never a key', () => {
  const config = fs.readFileSync(path.join(ROOT, 'nuxt.config.js'), 'utf8')
  assert.ok(/fallbackLocale:\s*'en'/.test(config))
})

console.log('')
if (failures) {
  console.log(failures + ' failing\n')
  process.exit(1)
}
console.log('all passing\n')
