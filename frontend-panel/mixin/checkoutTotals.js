/*
 * The checkout's money, added up from the server's own line figures.
 *
 * Every per-line amount here was decided server-side
 * (CartsController::describeVat -> Pricing::lineVat): the unit price, the
 * units paid for, and the VAT per unit rounded to the cent and multiplied out.
 * This module only adds those figures up and applies the order-wide discounts
 * in the order OrdersController takes them (CheckoutTotals::chain). It applies
 * no rate and splits nothing.
 *
 * In whole cents throughout. Every input has two decimals, so
 * Math.round(x * 100) recovers it exactly, and integer sums cannot pick up
 * the 0.1 + 0.2 drift that once put a cent between the summary and the card
 * gateway.
 *
 * It used to be done four ways: CheckoutRight, checkout.vue (which left the
 * Essentials Bundle discount out of the amount the gateway was handed),
 * guest-checkout (its own VAT, for tax rule id 1 only) and the store's flash
 * preview (on the full quantity, free units included). Plain functions, so a
 * page, a component and a test can all call the same ones.
 */

/** A money value as whole cents. */
export function cents(value) {
  const number = Number(value)

  return Number.isFinite(number) ? Math.round(number * 100) : 0
}

/** Whole cents back to money. */
export function money(minor) {
  return minor / 100
}

/** Has this line been priced by the server (its VAT split is on it)? */
export function isPriced(line) {
  return !!line && line.vat_rate !== undefined && line.vat_rate !== null
}

/*
 * Which way VAT applies to a priced line:
 *
 *   none      no VAT either way - zero-rated or margin scheme
 *   included  the price contains its VAT; nothing is added
 *   added     the price is ex-VAT; VAT is added on top
 *
 * Told apart by whether ANY tax applies, never by vat_inclusive alone: a 0%
 * line carries vat_inclusive false too, and calling it "VAT added" would
 * promise tax that is never charged. A flat-amount rule has rate 0 and a
 * vat_flat amount, and its tax is real.
 */
export function vatKind(line) {
  const taxed = Number(line?.vat_rate) > 0 || Number(line?.vat_flat) > 0

  if (!taxed) return 'none'

  return line.vat_inclusive ? 'included' : 'added'
}

/*
 * What the goods in a basket come to.
 *
 *   exVat        the goods net of VAT (units paid for)
 *   vatIncluded  VAT already inside inc-VAT prices - shown, never added
 *   vatAdded     VAT added to ex-VAT prices - the part that moves the total
 *   vatTotal     both: all the VAT on the order, counted once
 *   incVat       the goods with their VAT = exVat + vatTotal
 *   listIncVat   the same goods at their list prices, gross, full quantity
 *   saving       listIncVat - incVat: the price reductions and the value of
 *                any free units, both gross - the basis the order
 *                confirmation states its discount on (discount_inc_vat)
 *   freeUnitsValue  the free units' share of that saving
 *   stale        lines that arrived without a split (rehydrated, not priced)
 */
export function basketTotals(lines) {
  const sum = {
    exVat: 0, vatIncluded: 0, vatAdded: 0, incVat: 0, listIncVat: 0,
    freeUnitsValue: 0, items: 0, stale: 0,
  }

  ;(lines || []).forEach((line) => {
    if (!isPriced(line)) {
      sum.stale += 1
      return
    }

    const kind = vatKind(line)
    const vat = cents(line.line_vat)

    sum.exVat += cents(line.line_ex_vat)
    sum.incVat += cents(line.line_inc_vat)
    sum.items += parseInt(line.quantity || 0)

    if (kind === 'included') sum.vatIncluded += vat
    if (kind === 'added') sum.vatAdded += vat

    sum.listIncVat += cents(line.line_list_inc_vat != null ? line.line_list_inc_vat : line.line_inc_vat)
    sum.freeUnitsValue += cents(line.unit_inc_vat) * parseInt(line.free_units || 0)
  })

  const saving = Math.max(0, sum.listIncVat - sum.incVat)

  return {
    exVat: money(sum.exVat),
    vatIncluded: money(sum.vatIncluded),
    vatAdded: money(sum.vatAdded),
    vatTotal: money(sum.vatIncluded + sum.vatAdded),
    incVat: money(sum.incVat),
    listIncVat: money(sum.listIncVat),
    saving: money(saving),
    freeUnitsValue: money(Math.min(sum.freeUnitsValue, saving)),
    priceSaving: money(Math.max(0, saving - sum.freeUnitsValue)),
    items: sum.items,
    stale: sum.stale,
  }
}

/*
 * The goods a line is charged for before any VAT is added: net on an ex-VAT
 * line, gross on the rest. The base the store-wide flash discount and the
 * loyalty group are measured against, as the order measures them.
 */
export function lineGoods(line) {
  if (line && line.line_goods != null) return cents(line.line_goods)

  // A payload from before line_goods: what is charged, less the VAT added.
  if (isPriced(line)) {
    return cents(line.line_inc_vat) - (vatKind(line) === 'added' ? cents(line.line_vat) : 0)
  }

  return 0
}

export function goodsTotal(lines) {
  return money((lines || []).reduce((total, line) => total + lineGoods(line), 0))
}

/*
 * The order-wide discounts and the amount payable, in the order and with the
 * caps OrdersController applies (CheckoutTotals::chain):
 *
 *   gross  = goods with their VAT + shipping
 *   voucher, flash discount     already capped server-side by the goods
 *   Essentials Bundle           capped by what is left after those two
 *   loyalty / membership        capped by what is left after the bundle
 *   points                      can only pay for what is left after that
 *   wallet                      a payment, not a discount: leaves a cent
 */
export function payable({
  goodsIncVat = 0, shipping = 0, voucher = 0, flash = 0, bundle = 0,
  loyalty = 0, points = 0, wallet = 0,
} = {}) {
  const gross = cents(goodsIncVat) + cents(shipping)

  const take = (offered, owed) => Math.max(0, Math.min(cents(offered), Math.max(0, owed)))

  const voucherC = Math.max(0, cents(voucher))
  const flashC = Math.max(0, cents(flash))
  const bundleC = take(bundle, gross - voucherC - flashC)
  const loyaltyC = take(loyalty, gross - voucherC - flashC - bundleC)
  const pointsC = take(points, gross - voucherC - flashC - bundleC - loyaltyC)

  const total = Math.max(0, gross - voucherC - flashC - bundleC - loyaltyC - pointsC)
  const walletC = Math.max(0, Math.min(cents(wallet), total))

  return {
    gross: money(gross),
    voucher: money(voucherC),
    flash: money(flashC),
    bundle: money(bundleC),
    loyalty: money(loyaltyC),
    points: money(pointsC),
    discounts: money(voucherC + flashC + bundleC + loyaltyC + pointsC),
    total: money(total),
    wallet: money(walletC),
    amountDue: money(total - walletC),
  }
}
