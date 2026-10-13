/*
 * Exercises the register wizard's payload / field-map logic without Vue:
 * these methods are plain functions of `this.form` and `this.accountType`,
 * so they can be called directly with a synthetic `this`.
 *
 * There is no test runner in this project - run it directly:
 *
 *     cd frontend-panel && node tests/wizard-account-type.js
 *
 * Exits non-zero on the first failure, so it works in CI as-is.
 */
const fs = require('fs');
const path = require('path');
const compiler = require('vue-template-compiler');

const sfc = compiler.parseComponent(
  fs.readFileSync(path.join(__dirname, '..', 'pages', 'register.vue'), 'utf8')
);
// Stub every identifier the script imports; only the methods are under test.
const stubs = 'const AjaxButton={},AuthPasswordField={},AuthSteps={},AccountTypeCards={},'
  + 'OtpInput={},PhoneField={},validation={},global={},configJson={},'
  + 'mapGetters=()=>({}),mapState=()=>({}),mapActions=()=>({});\n';

/*
 * The company field lists and rules live in mixin/accountFields.js, shared
 * with the account-upgrade form. Load the real module rather than a stub:
 * payload and validation behaviour are what this test is about.
 */
const shared = fs.readFileSync(path.join(__dirname, '..', 'mixin', 'accountFields.js'), 'utf8')
  .replace(/^export (const|function)/gm, '$1')
  + '\nconst vatLooksValid = isValidVat;\n';

const body = stubs + shared + sfc.script.content
  .replace(/^\s*import\s*\{[^}]*\}\s*from\s*'[^']*'\s*$/gm, '')
  .replace(/^\s*import .*$/gm, '')
  .replace(/\.\.\.map(Getters|State|Actions)\([^)]*\)/g, 'noop: 1')
  .replace(/export default/, 'return');
const options = new Function(body)();
const M = options.methods;

// A form filled in as though the shopper completed BOTH the business and the
// trade step before switching type - the worst case for leakage.
const fullForm = () => ({
  name: 'Daniel Murphy', email: 'business.test01@example.com', phone: '+353871234567',
  password: 'Business@2026Test!', confirmPassword: 'Business@2026Test!', terms: true,
  company_name: 'Murphy IT Solutions Ltd', sector: 'Professional services / office',
  vat_number: 'IE1234567A', cro_number: '123456',
  billing_address: '25 Main Street, Dublin, Ireland',
  requires_po: true, credit_application: true,
  trade: 'Field engineer / sole trader', expected_spend: '2,000 - 5,000',
  website: 'https://example.com', resale_confirmed: true,
  pref_email_offers: true, pref_sms_offers: false, pref_recommendations: true,
  pref_trade_list: true
});

const ctx = (type, form) => ({
  accountType: type, step: 'details', form: form || fullForm(),
  serverFieldErrors: {}, errors: [], showErrors: false,
  fieldsFor: M.fieldsFor, strandedBy: M.strandedBy
});

let fails = 0;
const check = (n, c) => { console.log((c ? '  PASS  ' : '  FAIL  ') + n); if (!c) fails++; };

console.log('\nPayload for account_type = business:');
const b = M.registrationPayload.call(ctx('business'));
check('account_type is business', b.account_type === 'business');
check('carries company_name', b.company_name === 'Murphy IT Solutions Ltd');
check('carries sector', b.sector === 'Professional services / office');
check('carries requires_po as 1', b.requires_po === 1);
check('carries credit_application as 1', b.credit_application === 1);
check('carries vat_number', b.vat_number === 'IE1234567A');
check('carries billing_address', !!b.billing_address);
check('OMITS trade', !('trade' in b));
check('OMITS expected_spend', !('expected_spend' in b));
check('OMITS website', !('website' in b));
check('OMITS resale_confirmed', !('resale_confirmed' in b));
check('OMITS pref_trade_list', !('pref_trade_list' in b));
check('OMITS confirmPassword', !('confirmPassword' in b));

console.log('\nPayload for account_type = trade:');
const t = M.registrationPayload.call(ctx('trade'));
check('account_type is trade', t.account_type === 'trade');
check('carries trade', t.trade === 'Field engineer / sole trader');
check('carries expected_spend', !!t.expected_spend);
check('carries website', t.website === 'https://example.com');
check('carries resale_confirmed as 1', t.resale_confirmed === 1);
check('carries pref_trade_list as 1', t.pref_trade_list === 1);
check('keeps shared company_name', t.company_name === 'Murphy IT Solutions Ltd');
check('keeps shared vat_number', t.vat_number === 'IE1234567A');
check('keeps shared cro_number', t.cro_number === '123456');
check('keeps shared billing_address', !!t.billing_address);
check('OMITS sector', !('sector' in t));
check('OMITS requires_po', !('requires_po' in t));
check('OMITS credit_application', !('credit_application' in t));

console.log('\nPayload for account_type = personal:');
const p = M.registrationPayload.call(ctx('personal'));
check('account_type is personal', p.account_type === 'personal');
check('carries name/email/phone', !!p.name && !!p.email && !!p.phone);
check('carries terms as 1', p.terms === 1);
check('carries marketing prefs', p.pref_email_offers === 1 && p.pref_sms_offers === 0);
check('OMITS company_name', !('company_name' in p));
check('OMITS vat_number', !('vat_number' in p));
check('OMITS cro_number', !('cro_number' in p));
check('OMITS billing_address', !('billing_address' in p));
check('OMITS sector', !('sector' in p));
check('OMITS every trade field',
  !('trade' in p) && !('expected_spend' in p) && !('website' in p) && !('resale_confirmed' in p));

console.log('\nBoolean encoding:');
const unticked = fullForm();
unticked.requires_po = false; unticked.credit_application = false;
const b2 = M.registrationPayload.call(ctx('business', unticked));
check('unticked boxes send 0, not false', b2.requires_po === 0 && b2.credit_application === 0);
check('0 is sent rather than omitted', 'requires_po' in b2);

console.log('\nWhat a switch would strand:');
const sb = M.strandedBy.call(ctx('business'), 'trade');
check('business to trade warns about Sector', sb.includes('Sector'));
check('business to trade warns about PO requirement', sb.includes('Purchase order requirement'));
check('business to trade does NOT warn about Company name', !sb.includes('Company name'));
check('business to trade does NOT warn about VAT number', !sb.includes('VAT number'));

const st = M.strandedBy.call(ctx('trade'), 'business');
check('trade to business warns about Trade', st.includes('Trade'));
check('trade to business warns about Expected monthly spend', st.includes('Expected monthly spend'));
check('trade to business warns about Resale confirmation', st.includes('Resale confirmation'));
check('trade to business does NOT warn about Billing address', !st.includes('Billing address'));

const sp = M.strandedBy.call(ctx('business'), 'personal');
check('business to personal warns about company fields',
  sp.includes('Company name') && sp.includes('Sector'));

const empty = M.strandedBy.call(ctx('business', {name: 'x', email: 'y', phone: 'z'}), 'trade');
check('nothing entered means no warning', empty.length === 0);

const same = M.strandedBy.call(ctx('business'), 'business');
check('same type strands nothing', same.length === 0);

console.log('\nClearing errors that no longer apply:');
const c = ctx('business');
c.serverFieldErrors = {company_name: 'Enter the company name.', email: 'That email is taken.'};
c.errors = ['Something']; c.showErrors = true;
M.clearErrorsOutside.call(c, 'personal');
check('business-only error dropped', !('company_name' in c.serverFieldErrors));
check('common field error kept', c.serverFieldErrors.email === 'That email is taken.');
check('top-level errors cleared', c.errors.length === 0);
check('showErrors reset', c.showErrors === false);

const c2 = ctx('business');
c2.serverFieldErrors = {vat_number: 'Bad VAT.', sector: 'Choose what the business does.'};
M.clearErrorsOutside.call(c2, 'trade');
check('shared VAT error survives business to trade', c2.serverFieldErrors.vat_number === 'Bad VAT.');
check('business-only sector error dropped', !('sector' in c2.serverFieldErrors));

/*
 * Back -> forward preservation is a property of how the page is routed, not of
 * any method: the wizard moves between steps by changing ?step= / ?type= on
 * the same route, and Nuxt reuses the component unless watchQuery is set. Set
 * it and data() re-runs, wiping everything typed. These guard that.
 */
console.log('\nBack/forward keeps what was typed:');
check('no watchQuery (it would remount and clear the form)',
  options.watchQuery === undefined);
check('no asyncData/fetch that could reset state',
  options.asyncData === undefined && options.fetch === undefined);
check('the only watcher is on step', Object.keys(options.watch).join(',') === 'step');

const w = ctx('business');
w.code = '123456'; w.codeInvalid = true; w.errors = ['x']; w.showErrors = true;
options.watch.step.call(w);
check('changing step clears errors but not the form',
  w.errors.length === 0 && w.showErrors === false && w.code === ''
  && w.form.company_name === 'Murphy IT Solutions Ltd'
  && w.form.sector === 'Professional services / office'
  && w.form.billing_address === '25 Main Street, Dublin, Ireland'
  && w.form.requires_po === true
  && w.form.credit_application === true);

console.log('\nField map is symmetric with the template:');
check('personal has no type-specific fields', M.fieldsFor.call({}, 'personal').length === 8);
check('business and trade share the 4 company fields',
  ['company_name', 'vat_number', 'cro_number', 'billing_address']
    .every(f => M.fieldsFor.call({}, 'business').includes(f)
             && M.fieldsFor.call({}, 'trade').includes(f)));

console.log(fails ? `\n${fails} check(s) FAILED` : '\nAll wizard logic checks passed');
process.exitCode = fails ? 1 : 0;
