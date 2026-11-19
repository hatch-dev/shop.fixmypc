import Vue from 'vue'

/*
 * One open dropdown at a time, across the whole admin panel.
 *
 * Every menu used to manage itself: the shared <dropdown>, <inline-pop-over>,
 * <search-dropdown> and <pop-over> each carried their own outside-click, and
 * the hand-written menus on Products, Categories and Bulk Products listened
 * for a bubbling `click` on the document. None of them knew about the others,
 * none of them closed on Escape, and the page-level ones were defeated by the
 * `@click.stop` on their own triggers - so on Bulk Products opening a second
 * popup left the first one open underneath it.
 *
 * A menu now says it is open by carrying `v-dropdown-menu` (with the method
 * that closes it). While it is in the page:
 *
 *  - opening any other menu closes it, unless the new one is nested inside it
 *    (a select inside a filter popover keeps the popover open);
 *  - a press outside it closes it - read in the capture phase, so a `.stop`
 *    anywhere in the page cannot hide the press;
 *  - Escape closes the innermost open menu;
 *  - if a scrolling or overflow-hidden container (a table, a card) would cut
 *    it off, or it would run past the edge of the window, it is lifted out to
 *    `position: fixed` next to its trigger, flipping above it when there is
 *    no room below.
 *
 *   v-dropdown-menu="closeMenu"
 *   v-dropdown-menu="{close: closeMenu, within: $el}"   // trigger + menu root
 *   v-dropdown-menu="{close: closeMenu, within: '.cell-wrapper'}"
 *   v-dropdown-menu="{close: closePopup, key: 'activeRow', ignore: '.columns-btn'}"
 *   v-dropdown-menu="{key: 'openMenu'}"     // no method: resets that data key
 *
 * `within` is the element a press counts as inside: the menu together with
 * the button that opened it, so pressing that button again toggles the menu
 * rather than closing it and opening it straight back up. It defaults to the
 * menu's parent element, which is how every menu in the panel is laid out.
 * `ignore` names a trigger that lives elsewhere in the page, for the same
 * reason. `key` is passed to `close`, so one method can close several menus;
 * with no `close`, the component's own `key` property is reset (false / null).
 */

// Presses here never close a menu: a confirmation the menu itself asked for,
// and the toasts that report what it did.
const NEVER_OUTSIDE = '.confirm-overlay, .Vue-Toastification__container'

// Above the sidebar, the sticky table headers and the panel's modals; below
// the confirmation dialog (10000) and the toasts, which belong on top.
const FLOAT_Z_INDEX = 9990
const GAP = 4
const EDGE = 8

const openMenus = []   // in the order they opened; the innermost is last

function settings(binding) {
  const value = binding.value
  return typeof value === 'function' ? {close: value} : (value || {})
}

function boundaryOf(entry) {
  const within = entry.settings.within
  if (within && within.nodeType === 1) return within
  if (typeof within === 'string') return entry.el.closest(within) || entry.el.parentElement || entry.el
  // Opened from elsewhere in the page: its parent may hold half the screen,
  // so only the menu itself counts as inside.
  if (entry.settings.ignore) return entry.el
  return entry.el.parentElement || entry.el
}

function close(entry) {
  const index = openMenus.indexOf(entry)
  if (index !== -1) openMenus.splice(index, 1)
  const {close: closeMenu, key} = entry.settings
  if (typeof closeMenu === 'function') {
    closeMenu(key)
  } else if (key && entry.context) {
    entry.context[key] = typeof entry.context[key] === 'boolean' ? false : null
  }
}

function forget(entry) {
  const index = openMenus.indexOf(entry)
  if (index !== -1) openMenus.splice(index, 1)
}

function onPress(event) {
  const target = event.target
  if (!target || target.nodeType !== 1 || !openMenus.length) return
  if (target.closest(NEVER_OUTSIDE)) return

  for (let i = openMenus.length - 1; i >= 0; i--) {
    const entry = openMenus[i]
    if (entry && !boundaryOf(entry).contains(target) && !entry.el.contains(target) &&
        !(entry.settings.ignore && target.closest(entry.settings.ignore))) {
      close(entry)
    }
  }
}

function onKey(event) {
  if (event.key !== 'Escape' || !openMenus.length) return
  close(openMenus[openMenus.length - 1])
}

// ---------------------------------------------------------------- clipping

function cutOff(el) {
  const menu = el.getBoundingClientRect()
  if (menu.bottom > window.innerHeight || menu.right > window.innerWidth || menu.left < 0 || menu.top < 0) {
    return true
  }
  for (let node = el.parentElement; node && node !== document.body; node = node.parentElement) {
    const style = window.getComputedStyle(node)
    if (/(hidden|auto|scroll|clip)/.test(style.overflowX + ' ' + style.overflowY)) {
      const box = node.getBoundingClientRect()
      if (menu.top < box.top - 1 || menu.bottom > box.bottom + 1 ||
          menu.left < box.left - 1 || menu.right > box.right + 1) {
        return true
      }
    }
  }
  return false
}

function float(entry) {
  const el = entry.el
  // A menu opened from elsewhere in the page (`ignore`) has no trigger beside
  // it to be placed against, and `float: false` opts out.
  if (entry.settings.float === false || entry.settings.ignore) return
  if (window.getComputedStyle(el).position === 'fixed' || !cutOff(el)) return

  const width = el.offsetWidth
  const original = el.getAttribute('style')

  const place = (event) => {
    const anchor = boundaryOf(entry).getBoundingClientRect()

    // Scrolled until the trigger is out of sight: nothing left to point at.
    if (event && (anchor.bottom < 0 || anchor.top > window.innerHeight)) {
      close(entry)
      return
    }

    el.style.maxHeight = ''
    const height = el.offsetHeight
    const below = window.innerHeight - anchor.bottom - GAP - EDGE
    const above = anchor.top - GAP - EDGE

    let top = anchor.bottom + GAP
    if (height > below && above > below) {
      top = Math.max(EDGE, anchor.top - GAP - Math.min(height, above))
    }
    const room = height > below && above > below ? above : below
    if (height > room) el.style.maxHeight = Math.max(room, 120) + 'px'

    let left = anchor.left
    if (left + width > window.innerWidth - EDGE) left = anchor.right - width
    left = Math.min(Math.max(EDGE, left), Math.max(EDGE, window.innerWidth - EDGE - width))

    el.style.top = top + 'px'
    el.style.left = left + 'px'
  }

  Object.assign(el.style, {
    position: 'fixed',
    right: 'auto',
    bottom: 'auto',
    margin: '0',
    transform: 'none',
    width: width + 'px',
    overflowY: 'auto',
    zIndex: String(FLOAT_Z_INDEX)
  })
  place()

  window.addEventListener('scroll', place, true)
  window.addEventListener('resize', place)
  entry.unfloat = () => {
    window.removeEventListener('scroll', place, true)
    window.removeEventListener('resize', place)
    if (original === null) el.removeAttribute('style')
    else el.setAttribute('style', original)
  }
}

// --------------------------------------------------------------- directive

Vue.directive('dropdown-menu', {
  inserted(el, binding, vnode) {
    const entry = {el, settings: settings(binding), context: vnode.context, unfloat: null}
    el.__dropdownMenu = entry

    // Opening this one closes every menu it is not nested inside.
    openMenus.slice().forEach((other) => {
      if (!boundaryOf(other).contains(el)) close(other)
    })
    openMenus.push(entry)

    // After the menu has laid itself out (and any flip of its own has run).
    Vue.nextTick(() => {
      if (el.__dropdownMenu === entry && el.isConnected) float(entry)
    })
  },
  update(el, binding) {
    if (el.__dropdownMenu) el.__dropdownMenu.settings = settings(binding)
  },
  unbind(el) {
    const entry = el.__dropdownMenu
    if (!entry) return
    forget(entry)
    if (entry.unfloat) entry.unfloat()
    delete el.__dropdownMenu
  }
})

document.addEventListener('pointerdown', onPress, true)
document.addEventListener('keydown', onKey)

export default function (context, inject) {
  inject('dropdowns', {
    /** Close every open menu, e.g. before opening a modal. */
    closeAll() {
      openMenus.slice().reverse().forEach(close)
    }
  })
}
