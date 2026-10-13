/*
 * The clearance countdown, against the clock the server actually keeps.
 *
 * Run with: npm run check:clearance-timer
 *
 * The server stores its timers in UTC and sends them as "Y-m-d H:i:s" with no
 * zone marker. This page used to do
 *
 *     new Date(String(end_time).replace(' ', 'T'))
 *
 * which JavaScript reads as the VISITOR's local time. For a shopper in IST
 * that put the deal's end five and a half hours earlier than it really was:
 * the page showed the countdown at zero, and the expired styling with it,
 * while the basket was still selling the product at the deal price.
 */
const assert = require('assert')
const path = require('path')
const fs = require('fs')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

const parsed = compiler.parseComponent(
  fs.readFileSync(path.join(ROOT, 'pages/clearance.vue'), 'utf8')
)

const code = babel.transformSync(parsed.script.content, {
  filename: 'clearance.vue',
  configFile: false,
  babelrc: false,
  presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
}).code

const module_ = { exports: {} }
// eslint-disable-next-line no-new-func
new Function('module', 'exports', 'require', code)(module_, module_.exports, () => ({
  mapGetters: () => ({}), mapActions: () => ({}), default: {},
}))

const page = module_.exports.default || module_.exports
const serverTime = page.methods.serverTime

let failures = 0

function check(name, fn) {
  try {
    fn()
    console.log(`  ok   ${name}`)
  } catch (err) {
    failures++
    console.log(`  FAIL ${name}`)
    console.log(`       ${err.message}`)
  }
}

console.log('\nthe clearance countdown')

check('a bare server timestamp is read as UTC', () => {
  assert.strictEqual(
    serverTime('2026-09-24 05:55:00'),
    Date.UTC(2026, 8, 24, 5, 55, 0)
  )
})

check('the T-separated form reads the same', () => {
  assert.strictEqual(
    serverTime('2026-09-24T05:55:00'),
    Date.UTC(2026, 8, 24, 5, 55, 0)
  )
})

check('it does not drift with the machine timezone', () => {
  // The whole defect: a local-time parse moves with TZ, a UTC parse does not.
  const before = process.env.TZ
  const readings = []

  for (const tz of ['UTC', 'Asia/Kolkata', 'America/Los_Angeles']) {
    process.env.TZ = tz
    readings.push(serverTime('2026-09-24 05:55:00'))
  }

  if (before === undefined) delete process.env.TZ
  else process.env.TZ = before

  assert.strictEqual(
    new Set(readings).size, 1,
    'the deal end moved when the viewer did: ' + JSON.stringify(readings)
  )
})

check('a value that already carries a zone is left alone', () => {
  assert.strictEqual(serverTime('2026-09-24T05:55:00Z'), Date.UTC(2026, 8, 24, 5, 55, 0))
  assert.strictEqual(serverTime('2026-09-24T11:25:00+05:30'), Date.UTC(2026, 8, 24, 5, 55, 0))
})

check('no timer is null, not NaN and not 1970', () => {
  for (const empty of [null, undefined, '', 0]) {
    assert.strictEqual(serverTime(empty), null, `serverTime(${JSON.stringify(empty)})`)
  }
})

check('rubbish is null rather than NaN', () => {
  assert.strictEqual(serverTime('not a date'), null)
})

check('nothing parses the timer as local time any more', () => {
  const text = parsed.script.content.replace(/\/\*[\s\S]*?\*\//g, '')

  assert.ok(
    !/new Date\(String\(this\.hero/.test(text),
    'clearance.vue has gone back to parsing the deal end as local time'
  )
})

console.log('')

if (failures > 0) {
  console.log(`${failures} check(s) failed\n`)
  process.exit(1)
}

console.log('all clearance timer checks passed\n')
