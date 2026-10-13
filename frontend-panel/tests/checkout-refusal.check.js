/*
 * What the customer is shown when checkout refuses the order.
 *
 * Run with: npm run check:checkout-refusal
 *
 * The server keys a refusal by the field it is about — a missing purchase
 * order number arrives as {purchase_order_number: ["Enter the purchase order
 * number for this order."]}. PaymentGateways only understood two shapes,
 * 'form' and 'product', so that refusal fell through both branches: no banner,
 * no reason, and the promise never settled, which left the Place Order button
 * spinning for ever.
 */
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const NM = path.join(ROOT, 'node_modules')

const babel = require(path.join(NM, '@babel/core'))
const compiler = require(path.join(NM, 'vue-template-compiler'))

function loadComponent(sfcPath) {
  const sfc = compiler.parseComponent(fs.readFileSync(sfcPath, 'utf8'))

  const code = babel.transformSync(sfc.script.content, {
    filename: path.basename(sfcPath) + '.js',
    configFile: false,
    babelrc: false,
    presets: [[path.join(NM, '@babel/preset-env'), { targets: { node: 'current' } }]],
  }).code

  const stub = { default: {}, __esModule: true }
  const fakeRequire = (request) => {
    if (request === 'vuex') {
      return { mapGetters: () => ({}), mapActions: () => ({}), mapState: () => ({}) }
    }
    if (request.indexOf('~/') === 0 || request.indexOf('@/') === 0) return stub
    try {
      return require(path.join(NM, request))
    } catch (e) {
      return stub
    }
  }

  const module = { exports: {} }
  // eslint-disable-next-line no-new-func
  new Function('module', 'exports', 'require', code)(module, module.exports, fakeRequire)

  return module.exports.default || module.exports
}

const PaymentGateways = loadComponent(path.join(ROOT, 'components/PaymentGateways.vue'))
const Checkout = loadComponent(path.join(ROOT, 'pages/checkout.vue'))

function context(component, state) {
  const vm = Object.create(null)
  Object.assign(vm, state)

  for (const source of [...(component.mixins || []), component]) {
    for (const [name, fn] of Object.entries(source.methods || {})) {
      if (!(name in state)) vm[name] = fn.bind(vm)
    }
  }
  for (const source of [...(component.mixins || []), component]) {
    for (const [name, fn] of Object.entries(source.computed || {})) {
      if (name in state || typeof fn !== 'function') continue
      Object.defineProperty(vm, name, { get: fn.bind(vm), configurable: true })
    }
  }
  return vm
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

const PO_REFUSAL = {
  purchase_order_number: ['Enter the purchase order number for this order.'],
}

console.log('\nreading a refusal body')

const gateways = context(PaymentGateways, { orderError: null })

test('a refusal keyed by a field name yields its message', () => {
  assert.deepStrictEqual(
    gateways.refusalMessages(PO_REFUSAL),
    ['Enter the purchase order number for this order.']
  )
})

test('several fields at once all get through', () => {
  assert.deepStrictEqual(
    gateways.refusalMessages({ a: ['one'], b: ['two', 'three'] }),
    ['one', 'two', 'three']
  )
})

test('a plain string value is read too', () => {
  assert.deepStrictEqual(gateways.refusalMessages({ a: 'one' }), ['one'])
})

test('an unreadable body still says something', () => {
  for (const body of [null, undefined, {}, { a: 1 }, { a: [] }]) {
    const messages = gateways.refusalMessages(body)
    assert.ok(messages.length > 0, JSON.stringify(body) + ' produced nothing')
    assert.ok(typeof messages[0] === 'string' && messages[0].length > 0)
  }
})

test('the customer is never shown a raw field name', () => {
  for (const message of gateways.refusalMessages(PO_REFUSAL)) {
    assert.ok(!message.includes('purchase_order_number'), message)
  }
})

console.log('\nnot stomping on a precise message with a generic one')

test('a generic toast is suppressed once the server has explained', () => {
  const toasts = []
  const vm = context(PaymentGateways, {
    orderError: ['Enter the purchase order number for this order.'],
    setToastError: (m) => toasts.push(m),
  })

  vm.reportPaymentFailure('Bank payment failed')

  assert.deepStrictEqual(toasts, [], 'the vague toast buried the precise one')
})

test('a genuine payment failure still gets its toast', () => {
  const toasts = []
  const vm = context(PaymentGateways, {
    orderError: null,
    setToastError: (m) => toasts.push(m),
  })

  vm.reportPaymentFailure('Bank payment failed')

  assert.deepStrictEqual(toasts, ['Bank payment failed'])
})

console.log('\nmarking the field the refusal is about')

function checkoutVm(overrides) {
  return context(Checkout, Object.assign({
    purchaseOrderError: '',
    $nextTick: () => {},
  }, overrides))
}

test('the purchase order box is marked and given the reason', () => {
  const vm = checkoutVm({})

  vm.onFieldError(PO_REFUSAL)

  assert.strictEqual(
    vm.purchaseOrderError,
    'Enter the purchase order number for this order.'
  )
})

test('a refusal about some other field leaves the box alone', () => {
  const vm = checkoutVm({})

  vm.onFieldError({ some_other_field: ['Nothing to do with the PO.'] })

  assert.strictEqual(vm.purchaseOrderError, '')
})

test('an empty body does not throw', () => {
  for (const body of [null, undefined, {}]) {
    const vm = checkoutVm({})
    vm.onFieldError(body)
    assert.strictEqual(vm.purchaseOrderError, '')
  }
})

console.log('\nwho is shown the field at all')

function needsPo(accountType, requiresPo) {
  return context(Checkout, {
    profile: {
      account: { account_type: accountType },
      business_profile: { requires_po: requiresPo },
    },
  }).needsPurchaseOrder
}

test('a business account that asked for one sees the field', () => {
  assert.strictEqual(needsPo('business', 1), true)
  assert.strictEqual(needsPo('business', true), true)
})

test('a trade account that asked for one sees it too', () => {
  assert.strictEqual(needsPo('trade', 1), true)
})

test('an account that did not ask never sees it', () => {
  assert.strictEqual(needsPo('business', 0), false)
  assert.strictEqual(needsPo('trade', 0), false)
})

test('a personal account never sees it', () => {
  assert.strictEqual(needsPo('personal', 1), false)
})

test('no profile loaded yet means no field', () => {
  assert.strictEqual(
    context(Checkout, { profile: null }).needsPurchaseOrder, false
  )
  assert.strictEqual(
    context(Checkout, { profile: { account: { account_type: 'business' } } })
      .needsPurchaseOrder,
    false
  )
})

console.log('')

if (failures) {
  console.log(failures + ' failing\n')
  process.exit(1)
}

console.log('all passing\n')
