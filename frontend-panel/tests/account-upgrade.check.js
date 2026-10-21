/*
 * The account-upgrade card on pages/user/profile.vue, run without a browser:
 * the card's own computeds and methods against a stubbed API, with the real
 * shared field rules (mixin/accountFields.js).
 *
 *     cd frontend-panel && node tests/account-upgrade.check.js
 *
 * Exits non-zero on any failure.
 */
const fs = require('fs')
const path = require('path')
const compiler = require('vue-template-compiler')
const { withI18n, installI18n } = require('./i18n-en')

const sfc = compiler.parseComponent(
  fs.readFileSync(path.join(__dirname, '..', 'pages', 'user', 'profile.vue'), 'utf8')
)

// Stub every imported identifier except the shared rules, which are loaded for real.
const shared = fs.readFileSync(path.join(__dirname, '..', 'mixin', 'accountFields.js'), 'utf8')
  .replace(/^export (const|function)/gm, '$1')
const sharedNames = ['SECTORS', 'TRADES', 'SPENDS', 'companyFieldErrors', 'companyPayload',
  'COMPANY_FIELDS', 'COMPANY_BOOLEAN_FIELDS', 'COMPANY_FIELD_LABELS', 'isValidVat']

const imported = []
sfc.script.content.replace(
  /^\s*import\s+(?:\{([^}]*)\}|([A-Za-z0-9_$]+))\s+from/gm,
  (_m, braced, plain) => {
    (braced ? braced.split(',') : [plain]).forEach(n => {
      const name = n.trim().split(/\s+as\s+/).pop().trim()
      if (name && !sharedNames.includes(name)) imported.push(name)
    })
    return _m
  }
)
const vuexHelpers = ['mapGetters', 'mapState', 'mapActions', 'mapMutations']
const stubs = 'const ' + [...new Set(imported)]
  .map(n => vuexHelpers.includes(n) ? `${n}=()=>({})` : `${n}={}`)
  .join(',') + ';\n'

const body = stubs + shared + sfc.script.content
  .replace(/^\s*import\s*\{[^}]*\}\s*from\s*'[^']*'\s*$/gm, '')
  .replace(/^\s*import .*$/gm, '')
  .replace(/\.\.\.map(Getters|State|Actions)\([^)]*\)/g, 'noop: 1')
  .replace(/export default/, 'return')

const options = new Function(body)()
const M = options.methods
const C = options.computed

let fails = 0
const check = (name, ok) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + name); if (!ok) fails++ }

/*
 * A component-shaped object: data() plus every computed as a live getter and
 * every method bound, so methods see computeds the way Vue would give them.
 */
function makeVm(profile, upgrade, api = {}) {
  const vm = Object.assign(options.data.call(withI18n({})), {
    profile,
    upgrade,
    langCode: 'en',
    toasts: [],
    errorsShown: [],
    posted: [],
    cartRefreshed: 0,
    $auth: {user: {id: 1}, setUser(u) { this.user = u }},
    $nextTick(fn) { fn && fn() },
    $set(o, k, v) { o[k] = v },
    $delete(o, k) { delete o[k] },
    setProfile(p) { vm.profile = p ? p.data : undefined },
    setToastMessage(m) { vm.toasts.push(m) },
    setToastError(m) { vm.errorsShown.push(m) },
    async getRequest() { return api.get ? api.get() : {status: 200, data: upgrade} },
    async postRequest(args) { vm.posted.push(args); return api.post(args) },
    getCartByUser() { vm.cartRefreshed++; return Promise.resolve() }
  })
  withI18n(vm)
  Object.keys(C).forEach(k => {
    if (typeof C[k] === 'function') Object.defineProperty(vm, k, {get: () => C[k].call(vm), configurable: true})
  })
  // mapActions entries compile to placeholders; the stubs above stand in for them.
  Object.keys(M).forEach(k => { if (typeof M[k] === "function") vm[k] = M[k].bind(vm) })
  return vm
}

global.document = {getElementById: () => null}

const personal = {
  id: 1, name: 'Pat', email: 'pat@example.test',
  account: {account_type: 'personal', status: 'active', has_business_pricing: false},
  business_profile: null
}
const business = {
  id: 2, name: 'Bea', email: 'bea@example.test',
  account: {account_type: 'business', status: 'active', has_business_pricing: true, verification_status: 'verified'},
  business_profile: {company_name: 'Bea Ltd', vat_number: 'IE1234567A', cro_number: '123', billing_address: '1 Street', sector: 'Other', requires_po: true}
}

;(async () => {

  console.log('What the card offers:')
  {
    const vm = makeVm(personal, {options: ['business', 'trade'], pending: null, last_decision: null})
    check('personal sees both upgrades', vm.showUpgradeCard && vm.upgradeOptionCards.map(c => c.type).join() === 'business,trade')
    check('trade card does not promise cheaper prices than business',
      !vm.upgradeOptionCards[1].points.join(' ').match(/cheaper|lower|better price/i))

    const trade = makeVm({...personal, account: {account_type: 'trade', status: 'active'}}, {options: [], pending: null, last_decision: null})
    check('an account with nothing to move to gets no card', trade.showUpgradeCard === false)

    const loading = makeVm(personal, null)
    check('no card before the state has loaded', loading.showUpgradeCard === false)

    const waiting = makeVm(business, {options: [], pending: {status: 'pending', to_type: 'trade', details: {company_name: 'X'}}, last_decision: null})
    check('a waiting application keeps the card on screen', waiting.showUpgradeCard === true)
    check('...showing what was sent', waiting.pendingDetail('company_name') === 'X' && waiting.pendingDetail('trade') === '—')

    const rejected = makeVm(business, {options: ['trade'], pending: null, last_decision: {status: 'rejected', to_type: 'trade', review_note: 'No VAT'}})
    check('a rejection is shown while the customer may apply again', rejected.lastRejection && rejected.lastRejection.review_note === 'No VAT')
  }

  console.log('\nThe form:')
  {
    const vm = makeVm(business, {options: ['trade'], pending: null, last_decision: null})
    vm.startUpgrade('trade')
    check('a business account starts from its current company details',
      vm.upgradeForm.company_name === 'Bea Ltd' && vm.upgradeForm.vat_number === 'IE1234567A')
    check('the trade answers start empty', vm.upgradeForm.trade === '' && vm.upgradeForm.resale_confirmed === false)

    await vm.submitUpgrade()
    check('nothing is sent while required answers are missing', vm.posted.length === 0)
    check('the missing ones are marked', vm.upgradeBad('trade') && vm.upgradeBad('expected_spend') && vm.upgradeBad('resale_confirmed') && vm.upgradeBad('password'))
    check('filled-in fields are not marked', !vm.upgradeBad('company_name'))
  }

  console.log('\nUpgrading to business:')
  {
    const saved = {
      options: ['trade'], pending: null, last_decision: null,
      account: {account_type: 'business', status: 'active', has_business_pricing: true, verification_status: 'pending'},
      business_profile: {company_name: 'Pat Co', sector: 'Other'}
    }
    const vm = makeVm(personal, {options: ['business', 'trade'], pending: null, last_decision: null}, {
      post: async () => ({status: 200, message: 'Your account is now a business account. Business pricing is on.', data: saved})
    })
    vm.startUpgrade('business')
    Object.assign(vm.upgradeForm, {company_name: 'Pat Co', sector: 'Other', billing_address: '2 Road', trade: 'leaked?'})
    vm.upgradePassword = 'secret'
    await vm.submitUpgrade()

    const params = vm.posted[0] && vm.posted[0].params
    check('it posts to the upgrade endpoint', vm.posted[0] && vm.posted[0].api === 'accountUpgrade' && vm.posted[0].requiredToken === true)
    check('the body carries the type, password and business fields', params.to_type === 'business' && params.password === 'secret' && params.sector === 'Other')
    check('trade answers are not sent with a business upgrade', !('trade' in params))
    check('flags go as 1/0', params.requires_po === 0 && params.credit_application === 0)
    check('the page now reads as a business account', vm.accountType === 'business' && vm.profile.business_profile.company_name === 'Pat Co')
    check('other profile fields survive', vm.profile.email === 'pat@example.test')
    check('the form closes and the card shows the new options', vm.upgradeChoice === null && vm.upgradeOptions.join() === 'trade')
    check('the basket is refreshed for the new prices', vm.cartRefreshed === 1)
    check('the customer is told', vm.toasts[0] && vm.toasts[0].includes('business'))
  }

  console.log('\nRefusals:')
  {
    const vm = makeVm(personal, {options: ['business', 'trade'], pending: null, last_decision: null}, {
      post: async () => ({status: 201, message: 'That password is not right.', data: {form: ['That password is not right.'], fields: {password: ['That password is not right.']}}})
    })
    vm.startUpgrade('business')
    Object.assign(vm.upgradeForm, {company_name: 'Pat Co', sector: 'Other', billing_address: '2 Road'})
    vm.upgradePassword = 'wrong'
    await vm.submitUpgrade()
    check('a wrong password is shown at the password field', vm.upgradeBad('password') && vm.upgradeMessage('password', '') === 'That password is not right.')
    check('...and not repeated as a toast', vm.errorsShown.length === 0)
    check('the account is unchanged', vm.accountType === 'personal' && vm.upgradeChoice === 'business')
    check('the basket is left alone', vm.cartRefreshed === 0)
    vm.upgradePassword = 'better'
    vm.clearUpgradeError('password')
    check('typing again clears the server message', !vm.upgradeBad('password'))

    const refused = makeVm(personal, {options: ['business'], pending: null, last_decision: null}, {
      post: async () => ({status: 201, message: 'You already have an application with us.', data: {form: ['You already have an application with us.']}})
    })
    refused.startUpgrade('business')
    Object.assign(refused.upgradeForm, {company_name: 'Pat Co', sector: 'Other', billing_address: '2 Road'})
    refused.upgradePassword = 'secret'
    await refused.submitUpgrade()
    check('a refusal not tied to a field becomes a toast', refused.errorsShown[0] === 'You already have an application with us.')
  }

  console.log('\nWithdrawing:')
  {
    const after = {options: ['trade'], pending: null, last_decision: null}
    const vm = makeVm(business, {options: [], pending: {status: 'pending', to_type: 'trade', details: {}}, last_decision: null}, {
      post: async () => ({status: 200, message: 'Your trade application has been withdrawn.', data: after})
    })
    vm.withdrawConfirm = true
    await vm.withdrawUpgrade()
    check('it posts to the withdraw endpoint', vm.posted[0].api === 'accountUpgradeWithdraw')
    check('the card goes back to the options', vm.upgradePending === null && vm.upgradeOptions.join() === 'trade' && !vm.withdrawConfirm)
  }

  console.log(fails ? `\n${fails} check(s) failed` : '\nAll account-upgrade checks passed')
  process.exit(fails ? 1 : 0)
})()
