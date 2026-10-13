/*
 * Strips leading whitespace from text inputs across the whole app.
 *
 * Listens in the capture phase on the document, so the value is cleaned before
 * Vue's own v-model handler reads event.target.value on the way back up. That
 * keeps the model and what is on screen in step — `v-model.trim` alone trims
 * the model but never writes the clean value back to the DOM, which is why a
 * typed leading space stayed visible in the field.
 *
 * Opt a field out with data-allow-leading-space.
 */

// Whitelisted rather than blacklisted: anything not listed here is left alone.
const TEXTUAL_TYPES = ['', 'text', 'search', 'tel', 'url', 'email', 'password']

/* Only these keep a caret we can safely reposition; email/number throw. */
const SELECTABLE_TYPES = ['', 'text', 'search', 'tel', 'url', 'password']

function shouldClean(el) {

  if (!el || el.dataset.allowLeadingSpace !== undefined) {
    return false
  }

  if (el.tagName === 'TEXTAREA') {
    return true
  }

  if (el.tagName !== 'INPUT') {
    return false
  }

  /*
   * Passwords are included at the product owner's request. The trade-off: an
   * existing password that genuinely begins with a space can no longer be
   * typed at sign-in. Only leading whitespace is affected — spaces inside and
   * at the end of a password are untouched.
   */
  return TEXTUAL_TYPES.includes((el.type || '').toLowerCase())
}

export default function () {

  if (!process.client) {
    return
  }

  /*
   * Refuse the keystroke when a space would land at the very start. Cleaning
   * on `input` alone still lets the character appear for a frame, and relies
   * on the event firing at all — autofill and some IMEs do not always oblige.
   */
  document.addEventListener('keydown', function (event) {

    if (event.key !== ' ' && event.key !== 'Spacebar') {
      return
    }

    // Let shortcuts through untouched.
    if (event.ctrlKey || event.metaKey || event.altKey) {
      return
    }

    const el = event.target

    if (!shouldClean(el)) {
      return
    }

    let caret

    try {
      caret = el.selectionStart
    } catch (e) {
      caret = null
    }

    const before = caret === null ? el.value : el.value.slice(0, caret)

    // Only whitespace to the left means this space would be a leading one.
    if (before.trim() === '') {
      event.preventDefault()
    }

  }, true)

  document.addEventListener('input', function (event) {

    const el = event.target

    if (!shouldClean(el)) {
      return
    }

    const value = el.value

    // Fast path: nothing to do unless it actually starts with whitespace.
    if (!value || !/^\s/.test(value)) {
      return
    }

    const cleaned = value.replace(/^\s+/, '')

    if (cleaned === value) {
      return
    }

    const removed = value.length - cleaned.length
    const type = (el.type || '').toLowerCase()
    const selectable = el.tagName === 'TEXTAREA' || SELECTABLE_TYPES.includes(type)

    let caret = null

    if (selectable) {
      try {
        caret = Math.max(0, (el.selectionStart || 0) - removed)
      } catch (e) {
        caret = null
      }
    }

    el.value = cleaned

    if (caret !== null) {
      try {
        el.setSelectionRange(caret, caret)
      } catch (e) {
        // Some input types refuse a caret; the value is still cleaned.
      }
    }

  }, true)
}
