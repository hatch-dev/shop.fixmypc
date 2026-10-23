/*
 * A $t / $tc for checks that renders the real English from lang/en.json, so
 * a check can assert on what an admin reads ("Live", "Changed") and a missing
 * key shows up as the key. A `key => key` stub stopped being enough once the
 * pages moved their text into en.json (2026-10-23).
 */
const path = require('path')
const messages = require(path.resolve(__dirname, '../lang/en.json'))

function lookup (key) {
  return key.split('.').reduce((all, part) => (all ? all[part] : undefined), messages)
}

function fill (text, params) {
  if (!params || typeof params !== 'object') return text
  return String(text).replace(/\{(\w+)\}/g, (_, name) =>
    params[name] === undefined ? `{${name}}` : params[name])
}

function translate (key, params) {
  const value = lookup(key)
  return typeof value === 'string' ? fill(value, params) : key
}

// vue-i18n 8 plural rule for English: "one | many", or "none | one | many".
function translateCount (key, count = 1, params) {
  const value = lookup(key)
  if (typeof value !== 'string') return key
  const forms = value.split('|').map(s => s.trim())
  const n = Math.abs(Number(count))
  let form = forms[0]
  if (forms.length === 2) form = n === 1 ? forms[0] : forms[1]
  else if (forms.length >= 3) form = n === 0 ? forms[0] : n === 1 ? forms[1] : forms[2]
  return fill(form, Object.assign({ count, n: count }, params))
}

module.exports = { translate, translateCount, messages }
