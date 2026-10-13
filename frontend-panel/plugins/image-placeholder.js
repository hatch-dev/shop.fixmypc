/*
 * A placeholder wherever an image fails to load.
 *
 * Without this a broken image shows its alt text ("Site Logo", "Slider
 * image") or an empty box, depending on the browser. Several components
 * already had a fallback of their own (LazyImage, the product gallery, the
 * slider, the basket's bundle tiles) - but each fell back to the API's
 * uploads/default-image.webp, which is served from the same place as the
 * image that just failed and so fails with it.
 *
 * One listener on the document catches every failed <img>, wherever it
 * came from - templates, v-html product descriptions, images added later -
 * and gives the component a turn first: if the component swapped in a
 * fallback of its own, that is left to load, and only an image still broken
 * after that gets the placeholder. The placeholder is an inline SVG, so it
 * cannot fail the way a server image can.
 *
 * An image can opt out with data-no-placeholder.
 */

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90" viewBox="0 0 120 90">'
  + '<rect width="120" height="90" fill="#F1F2F6"/>'
  + '<g fill="none" stroke="#B8BCCB" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">'
  + '<rect x="38" y="27" width="44" height="36" rx="5"/>'
  + '<circle cx="51" cy="39" r="4"/>'
  + '<path d="M41 59l13-13 9 9 6-6 10 10"/>'
  + '</g></svg>'

export const IMAGE_PLACEHOLDER = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(SVG)

const CLASS = 'img-placeholder'

export function usePlaceholder (img) {
  if (!img || !img.isConnected || img.hasAttribute('data-no-placeholder')) return false

  // Already showing it: nothing more to do, and nothing to loop on.
  if (img.getAttribute('src') === IMAGE_PLACEHOLDER) return false

  // srcset and <picture> sources win over src, so they go too.
  img.removeAttribute('srcset')
  img.removeAttribute('sizes')

  if (img.parentElement && img.parentElement.tagName === 'PICTURE') {
    img.parentElement.querySelectorAll('source').forEach(source => source.remove())
  }

  img.classList.add(CLASS)
  img.classList.remove('is-broken')
  // LazyImage fades images in from opacity 0; a placeholder is shown at once.
  img.style.opacity = 1
  img.setAttribute('src', IMAGE_PLACEHOLDER)

  return true
}

export function install (doc, win) {
  if (!doc || !win || win.__imagePlaceholderInstalled) return
  win.__imagePlaceholderInstalled = true

  const style = doc.createElement('style')
  // scale-down: the icon keeps its own size in a big box (a hero slide) and
  // only shrinks to fit a small one (a menu icon); the fill covers the rest.
  // !important: a slider or gallery rule (object-fit: cover) would otherwise
  // crop or blow up the placeholder.
  style.textContent = 'img.' + CLASS + '{object-fit:scale-down !important;background-color:#F1F2F6 !important;}'
  doc.head.appendChild(style)

  // error does not bubble, so listen in the capture phase.
  doc.addEventListener('error', (event) => {
    const img = event.target

    if (!img || img.tagName !== 'IMG') return

    const failed = img.getAttribute('src')

    // The component's own @error runs after this listener. If it swaps in a
    // fallback, let that load - its failure brings us back here.
    win.setTimeout(() => {
      if (img.getAttribute('src') === failed) usePlaceholder(img)
    }, 0)
  }, true)

  // A placeholder that is later replaced by a real image that loads stops
  // looking like a placeholder.
  doc.addEventListener('load', (event) => {
    const img = event.target

    if (img && img.tagName === 'IMG' && img.classList.contains(CLASS) && img.getAttribute('src') !== IMAGE_PLACEHOLDER) {
      img.classList.remove(CLASS)
    }
  }, true)

  // Images that had already failed before this ran. SVGs are skipped: one
  // with no width of its own can report a natural width of 0 and still be fine.
  const sweep = () => {
    Array.prototype.forEach.call(doc.images, (img) => {
      const src = img.getAttribute('src') || ''

      if (src && img.complete && img.naturalWidth === 0 && !/\.svg(\?|#|$)|^data:image\/svg/i.test(src)) {
        usePlaceholder(img)
      }
    })
  }

  if (doc.readyState === 'complete') {
    sweep()
  } else {
    win.addEventListener('load', sweep)
  }
}

export default () => {
  if (typeof window !== 'undefined') install(document, window)
}
