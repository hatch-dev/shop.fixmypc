/**
 * The last line of defence against a translation key reaching a customer.
 *
 * `detailRight.freeCollection` was printed on the product page, in the buy
 * box, next to the price — because the component asked for a key that was in
 * no language file. vue-i18n's default behaviour for an unknown key is to
 * render the key itself, which is useful in development and indefensible in
 * front of a shopper.
 *
 * Order of defence:
 *   1. the requested locale                     (vue-i18n)
 *   2. fallbackLocale: 'en'                     (nuxt.config.js)
 *   3. this, which turns the key into readable English rather than printing it
 *
 * Step 3 should never fire — `npm run i18n:audit` fails the moment a key is
 * referenced that English does not define. It exists so that when someone adds
 * a key and forgets the string, the page reads "Free collection" rather than
 * leaking the internals of the application.
 */

/**
 * The last segment of a key, spelled the way a person would write it.
 *
 *   detailRight.freeCollection -> Free collection
 *   cart.outOfStock            -> Out of stock
 */
export function humanizeKey(key) {
  if (typeof key !== 'string' || !key) {
    return ''
  }

  const last = key.split('.').pop()

  const words = last
    // camelCase and snake_case both become spaced words.
    .replace(/[_-]+/g, ' ')
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/\s+/g, ' ')
    .trim()

  if (!words) {
    return ''
  }

  const lowered = words.toLowerCase()

  return lowered.charAt(0).toUpperCase() + lowered.slice(1)
}

/**
 * vue-i18n's `missing` hook. Returning a string makes it the rendered value.
 *
 * Only warns for the site's own default language: a locale that has not been
 * translated yet is an expected, quiet fallback, not a fault worth a console
 * full of noise on every page.
 */
export default function missingHandler(locale, key) {
  if (process.env.NODE_ENV !== 'production' && locale === 'en') {
    // eslint-disable-next-line no-console
    console.warn(`[i18n] no English string for "${key}" — add it to lang/en.json`)
  }

  return humanizeKey(key)
}
