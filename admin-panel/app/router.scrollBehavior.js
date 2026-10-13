/**
 * The admin shell scrolls `.content`, not the window (see
 * assets/styles/content.styl: height 100vh + overflow-y auto), so Vue Router's
 * default scroll handling targets an element that never scrolls. Every route
 * change therefore inherited the previous page's offset — clicking "Add one"
 * part-way down the category list landed on /categories/add already scrolled
 * to the bottom of the form. Drive the real scroller instead.
 */

const SCROLLER = '.content'

// Where each route was left, so a back-navigation can return to it. Vue
// Router's own `savedPosition` only ever records window scroll, which is
// always 0 here, so it tells us *that* this is a popstate, not where to go.
const positions = Object.create(null)

function getScroller () {
  return typeof document !== 'undefined' ? document.querySelector(SCROLLER) : null
}

function hashTarget (hash) {
  let selector = hash
  if (typeof window.CSS !== 'undefined' && typeof window.CSS.escape !== 'undefined') {
    selector = '#' + window.CSS.escape(hash.substr(1))
  }
  try {
    return document.querySelector(selector)
  } catch (e) {
    return null
  }
}

export default function (to, from, savedPosition) {
  const nuxt = window.$nuxt

  // Runs before the incoming page renders, so this is still the outgoing
  // page's offset.
  const leaving = getScroller()
  if (leaving && from && from.fullPath) {
    positions[from.fullPath] = leaving.scrollTop
  }

  if (!nuxt) {
    return false
  }

  const isRouteChanged = to !== from

  if (!isRouteChanged || (to.path === from.path && to.hash !== from.hash)) {
    nuxt.$nextTick(() => nuxt.$emit('triggerScroll'))
  }

  return new Promise((resolve) => {
    nuxt.$once('triggerScroll', () => {
      const scroller = getScroller()

      // Always hand back false: we position the scroller ourselves, and any
      // coordinates we returned would be applied to the window instead.
      if (!scroller) {
        return resolve(false)
      }

      if (to.hash) {
        const el = hashTarget(to.hash)
        if (el) {
          const padding = parseFloat(getComputedStyle(scroller).paddingTop) || 0
          scroller.scrollTop +=
            el.getBoundingClientRect().top - scroller.getBoundingClientRect().top - padding
          return resolve(false)
        }
      }

      if (savedPosition) {
        scroller.scrollTop = positions[to.fullPath] || 0
      } else if (isRouteChanged && to.path !== from.path) {
        // A query-only change (search, filters, pagination) keeps its place;
        // moving to a different page always starts at the top.
        scroller.scrollTop = 0
      }

      resolve(false)
    })
  })
}
