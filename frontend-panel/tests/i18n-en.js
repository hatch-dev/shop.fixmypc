/*
 * $t / $tc / $te for the checks, answering from the real lang/en.json.
 *
 * Components and mixins print their text through vue-i18n, so a harness that
 * builds a bare `this` needs these to read the same English a shopper sees.
 * Keys are resolved, not echoed: a check that asserts "Ex-VAT" fails if the
 * English file stops saying it. Same {placeholder} and "one | many" rules as
 * vue-i18n 8.
 */
const path = require('path')

const en = require(path.join(__dirname, '..', 'lang', 'en.json'))

function lookup(key) {
  return String(key).split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), en)
}

function interpolate(message, params) {
  return message.replace(/\{(\w+)\}/g, (whole, name) =>
    params && params[name] !== undefined ? String(params[name]) : whole)
}

function t(key, params) {
  const message = lookup(key)

  return typeof message === 'string' ? interpolate(message, params) : key
}

function tc(key, count, params) {
  const message = lookup(key)

  if (typeof message !== 'string') {
    return key
  }

  const forms = message.split('|').map(form => form.trim())
  const n = Math.abs(Number(count) || 0)
  const index = forms.length === 3 ? Math.min(n, 2) : (forms.length === 2 ? (n === 1 ? 0 : 1) : 0)

  return interpolate(forms[index], Object.assign({ n: count, count }, params))
}

function te(key) {
  return typeof lookup(key) === 'string'
}

/* Give a plain object (a hand-built `this`) the three methods. */
function withI18n(target) {
  return Object.assign(target, { $t: t, $tc: tc, $te: te })
}

/*
 * Mounted components get the real vue-i18n, so the <i18n> component (a
 * sentence with a price or a link inside it) renders as it does in the shop.
 * Every root instance a check creates is handed the English instance.
 */
function installI18n(Vue) {
  const VueI18n = require(path.join(__dirname, '..', 'node_modules', 'vue-i18n'))
  let i18n = null

  // registered before VueI18n's own mixin, so the root has its instance by the time that one looks
  Vue.mixin({
    beforeCreate() {
      if (i18n && !this.$options.i18n && !this.$options.parent) {
        this.$options.i18n = i18n
      }
    },
  })
  Vue.use(VueI18n)
  i18n = new VueI18n({ locale: 'en', fallbackLocale: 'en', messages: { en }, silentTranslationWarn: true })
}

module.exports = { t, tc, te, withI18n, installI18n }
