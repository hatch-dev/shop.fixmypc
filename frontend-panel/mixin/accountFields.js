/*
 * The company questions a business or trade account is asked, in one place.
 *
 * Asked twice: at registration (pages/register.vue) and when an existing
 * customer upgrades their account (the upgrade card on pages/user/profile.vue).
 * The two screens look different - one is the sign-up wizard, the other the
 * account area - but which fields each type needs, the choices offered and
 * what counts as filled in must not differ, or the same company would be
 * accepted by one form and refused by the other.
 *
 * The server holds the same lists (App\Models\Helper\AccountUpgrade::
 * DETAIL_FIELDS and Validation::companyRules) and is what actually decides;
 * this is the copy that lets the form say so before the request is sent.
 */

export const COMPANY_FIELDS = {
  business: [
    'company_name', 'vat_number', 'cro_number', 'billing_address',
    'sector', 'requires_po', 'credit_application'
  ],
  trade: [
    'company_name', 'vat_number', 'cro_number', 'billing_address',
    'trade', 'expected_spend', 'website', 'resale_confirmed', 'pref_trade_list'
  ]
}

/** Sent as 1/0 rather than true/false. */
export const COMPANY_BOOLEAN_FIELDS = [
  'requires_po', 'credit_application', 'resale_confirmed', 'pref_trade_list'
]

export const COMPANY_FIELD_LABELS = {
  company_name: 'Company name',
  vat_number: 'VAT number',
  cro_number: 'CRO number',
  billing_address: 'Billing address',
  sector: 'Sector',
  requires_po: 'Purchase order requirement',
  credit_application: 'Credit account request',
  trade: 'Trade',
  expected_spend: 'Expected monthly spend',
  website: 'Website',
  resale_confirmed: 'Resale confirmation',
  pref_trade_list: 'Trade mailing list'
}

export const SECTORS = [
  'Professional services / office',
  'School or college',
  'Medical or dental practice',
  'Hospitality or retail',
  'Construction or trades',
  'Charity or community group',
  'Other'
]

export const TRADES = [
  'Computer / laptop repair shop',
  'Phone and tablet repair',
  'IT reseller or MSP',
  'Games console repair',
  'Electronics or appliance repair',
  'Field engineer / sole trader',
  'Other'
]

export const SPENDS = [
  'Under €500',
  '€500 – €2,000',
  '€2,000 – €5,000',
  'Over €5,000'
]

export function isValidVat(value) {
  return /^IE\d{7}[A-Z]{1,2}$/i.test((value || '').replace(/\s/g, ''))
}

/**
 * The company fields that are missing or malformed for this type, as
 * {field: true}. Empty when the company half of the form may be sent.
 */
export function companyFieldErrors(type, form) {

  const bad = {}
  const f = form || {}

  if (type === 'business') {
    if (!f.company_name) bad.company_name = true
    if (!f.sector) bad.sector = true
    if (!f.billing_address) bad.billing_address = true
    if (f.vat_number && !isValidVat(f.vat_number)) bad.vat_number = true
  }

  if (type === 'trade') {
    if (!f.company_name) bad.company_name = true
    if (!f.trade) bad.trade = true
    if (!f.vat_number || !isValidVat(f.vat_number)) bad.vat_number = true
    if (!f.billing_address) bad.billing_address = true
    if (!f.expected_spend) bad.expected_spend = true
    if (!f.resale_confirmed) bad.resale_confirmed = true
  }

  return bad
}

/**
 * The company half of a request body: only the fields this type asks for,
 * flags as 1/0. Anything the form holds for the other type stays behind.
 */
export function companyPayload(type, form) {

  const payload = {}

  ;(COMPANY_FIELDS[type] || []).forEach(name => {
    payload[name] = COMPANY_BOOLEAN_FIELDS.includes(name)
      ? (form[name] ? 1 : 0)
      : form[name]
  })

  return payload
}
