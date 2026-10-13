/*
 * Runs profile.vue's own applySaved + the computeds the Business card is
 * gated on, against the exact payload the server returns.
 */
const fs = require('fs');
const compiler = require('vue-template-compiler');

const sfc = compiler.parseComponent(fs.readFileSync(require('path').join(__dirname,'..','pages','user','profile.vue'), 'utf8'));

// Stub every identifier the script imports, whatever they are.
const imported = [];
sfc.script.content.replace(
  /^\s*import\s+(?:\{([^}]*)\}|([A-Za-z0-9_$]+))\s+from/gm,
  (_m, braced, plain) => {
    (braced ? braced.split(',') : [plain]).forEach((n) => {
      const name = n.trim().split(/\s+as\s+/).pop().trim();
      if (name) imported.push(name);
    });
    return _m;
  }
);

const vuexHelpers = ['mapGetters', 'mapState', 'mapActions', 'mapMutations'];

const stubs = 'const ' + [...new Set(imported)]
  .map(n => vuexHelpers.includes(n) ? `${n}=()=>({})` : `${n}={}`)
  .join(',') + ';\n';

const body = stubs + sfc.script.content
  .replace(/^\s*import\s*\{[^}]*\}\s*from\s*'[^']*'\s*$/gm, '')
  .replace(/^\s*import .*$/gm, '')
  .replace(/\.\.\.map(Getters|State|Actions)\([^)]*\)/g, 'noop: 1')
  .replace(/export default/, 'return');

const options = new Function(body)();
const M = options.methods;
const C = options.computed;

// What the server actually returns from POST /api/v1/user/update-profile,
// copied from a real response.
const serverData = {
  name: 'Daniel Murphy',
  phone: '+353871234567',
  image: null,
  account: {
    account_type: 'business',
    status: 'active',
    status_note: null,
    verification_status: 'pending',
    trade_status: null,
    has_business_pricing: true,
    can_checkout: true,
    headline: 'Business pricing is applied automatically.'
  },
  business_profile: {
    company_name: 'Murphy IT Solutions Limited',
    vat_number: 'IE1234567A',
    cro_number: '123456',
    billing_address: '26 Main Street, Dublin, Dublin, D02 X286, Ireland',
    sector: 'Professional services / office',
    requires_po: true,
    credit_application: true
  },
  preferences: {
    pref_email_offers: true, pref_sms_offers: false,
    pref_recommendations: true, pref_trade_list: false
  }
};

let fails = 0;
const check = (n, c) => { console.log((c ? '  PASS  ' : '  FAIL  ') + n); if (!c) fails++; };

function makeVm(authUser, profile) {
  const vm = {
    $auth: {
      user: authUser,
      setUser(u) { this.user = u; }
    },
    profile,
    business: {}, preferences: {}, phoneVerified: false,
    email: '', name: '', phone: '', image: '',
    // As store/user.js does it: the action commits `params.data`, so a
    // caller passing the bare profile stored undefined. Stubbing it as a
    // plain assignment is how that went unnoticed.
    setProfile(p) { vm.profile = p ? p.data : undefined; },
    hydrateAccount: M.hydrateAccount,
    $set(o, k, v) { o[k] = v; }
  };
  vm.applySaved = M.applySaved.bind(vm);
  vm.hydrateAccount = M.hydrateAccount.bind(vm);
  return vm;
}

const accountTypeOf = vm => C.accountType.call({account: C.account.call(vm)});

console.log('\nBaseline (page freshly loaded):');
{
  // $auth.user is the SIGN-IN payload - it does not carry `account`.
  const authUser = {id: 5367, name: 'Daniel Murphy', email: 'b@e.test', token: 'x', session_id: 'y'};
  // profile came from GET /user/profile and DOES carry account.
  const profile = {...authUser, account: serverData.account, business_profile: serverData.business_profile};
  const vm = makeVm(authUser, profile);
  check('Business card is shown before saving', accountTypeOf(vm) === 'business');
}

console.log('\nAfter a save that returns account + business_profile:');
{
  const authUser = {id: 5367, name: 'Daniel Murphy', email: 'b@e.test', token: 'x', session_id: 'y'};
  const profile = {...authUser, account: serverData.account, business_profile: serverData.business_profile};
  const vm = makeVm(authUser, profile);

  vm.applySaved({account: serverData.account, business_profile: serverData.business_profile});

  check('accountType still business', accountTypeOf(vm) === 'business');
  check('company name still present',
    vm.profile.business_profile && vm.profile.business_profile.company_name === 'Murphy IT Solutions Limited');
}

console.log('\nAfter a save whose response omitted account:');
{
  const authUser = {id: 5367, name: 'Daniel Murphy', email: 'b@e.test', token: 'x', session_id: 'y'};
  const profile = {...authUser, account: serverData.account, business_profile: serverData.business_profile};
  const vm = makeVm(authUser, profile);

  vm.applySaved({account: undefined, business_profile: serverData.business_profile});

  check('accountType survives (undefined key filtered)', accountTypeOf(vm) === 'business');
}

console.log('\nSaving the Preferences section (passes preferences ONLY):');
{
  const authUser = {id: 5367, name: 'Daniel Murphy', email: 'b@e.test', token: 'x', session_id: 'y'};
  const profile = {...authUser, account: serverData.account, business_profile: serverData.business_profile};
  const vm = makeVm(authUser, profile);

  // This is literally what the preferences handler does.
  vm.applySaved(serverData.preferences);

  check('Business card still shown after saving preferences',
    accountTypeOf(vm) === 'business');
  check('business_profile not lost',
    !!vm.profile.business_profile && vm.profile.business_profile.company_name === 'Murphy IT Solutions Limited');
  check('preferences actually applied',
    vm.profile.pref_recommendations === true);
}

console.log('\nWhat applySaved keeps from the previous profile:');
{
  const authUser = {id: 5367, name: 'Daniel Murphy', email: 'b@e.test', token: 'x', session_id: 'y'};
  const profile = {
    ...authUser,
    account: serverData.account,
    business_profile: serverData.business_profile,
    // Things only the profile fetch supplies:
    phone_verified_at: '2026-01-01 10:00:00',
    pref_email_offers: true
  };
  const vm = makeVm(authUser, profile);

  vm.applySaved({account: serverData.account, business_profile: serverData.business_profile});

  check('profile-only fields survive a save',
    vm.profile.phone_verified_at === '2026-01-01 10:00:00');
  check('account survives a save', vm.profile.account.account_type === 'business');
  console.log('        (profile keys after save: ' + Object.keys(vm.profile).join(', ') + ')');
}

console.log(fails ? `\n${fails} check(s) FAILED` : '\nAll checks passed');
process.exitCode = fails ? 1 : 0;
