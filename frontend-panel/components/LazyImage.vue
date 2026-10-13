<template>
  <img
    :data-src="lazySrc"
    :style="style"
    :alt="alt"
    :title="title"
    :width="width"
    :height="height"
    :loading="native ? 'lazy' : null"
    decoding="async"
    @error="onError"
    class="lazy-img"
  >
</template>

<script>
  import lozad from 'lozad'
  import util from '~/mixin/util'

  export default {
    name: 'LazyImage',
    props: {
      alt: {
        type: String,
        default: null,
      },
      title: {
        type: String,
        default: null,
      },
      backgroundColor: {
        type: String,
        default: '#d9f4eb',
      },
      lazySrc: {
        type: String,
        default: null,
      },
      lazySrcset: {
        type: String,
        default: null,
      },
      /*
       * The intrinsic size, in px.
       *
       * These were hard-coded to 50 for every image in the panel, and the
       * computed style below read this.width / this.height as though they were
       * props - which they were not, so the aspect-ratio branch could never
       * run. They are props now. The default stays at 50 because roughly forty
       * call sites currently rely on the contexts' own CSS to size the image
       * over the top of it, and quietly changing the intrinsic box under all
       * of them is not a change that belongs in a lazy-loading pass. Pass the
       * real numbers, or `ratio`, where the box is not already pinned by CSS.
       */
      width: {
        type: [Number, String],
        default: 50,
      },
      height: {
        type: [Number, String],
        default: 50,
      },
      /*
       * 'w:h' - reserves the box by ratio rather than by fixed pixels, so the
       * image can be fluid and still not shift the page when it lands.
       */
      ratio: {
        type: String,
        default: null,
      },
      /*
       * Adds the browser's own loading="lazy" as well. Off by default: lozad
       * already starts the fetch 300px out, and the native attribute uses a
       * much larger, browser-chosen margin, so switching it on mainly helps
       * where JavaScript has not run yet.
       */
      native: {
        type: Boolean,
        default: false,
      },
    },
    data() {
      return {
        loading: true,
        // Set once the fallback has also failed, so onError cannot loop.
        failed: false,
      }
    },
    mixins: [util],
    watch: {
      // lozad loads an element once and then stops watching it. When a list is
      // re-rendered with different data (search results, filters, pagination)
      // Vue re-uses the same <img> node, so only data-src changes and the old
      // picture stays on screen next to the new title. Swap it by hand.
      imageSource(value) {
        if (!value) {
          return
        }

        this.$nextTick(() => {
          const el = this.$el

          if (!el || el.getAttribute('data-loaded') !== 'true') {
            return
          }

          if (el.src !== value) {
            this.loading = true
            // a fresh source deserves a fresh attempt at the fallback
            this.failed = false
            el.classList.remove('is-broken')
            // let the replacement fade in too, rather than swapping hard
            el.classList.remove('is-loaded')
            el.style.opacity = 0
            el.src = value
          }
        })
      }
    },
    computed: {
      imageSource() {
        return this.$attrs['data-src'] || this.lazySrc
      },
      aspectRatio() {
        if (!this.ratio) {
          return null
        }

        const parts = String(this.ratio).split(':')
        const w = parseFloat(parts[0])
        const h = parseFloat(parts[1])

        if (!w || !h || !isFinite(w) || !isFinite(h)) {
          return null
        }

        return `${w} / ${h}`
      },
      style() {
        const style = {}

        // Holding the box open is the whole point: an image that arrives into
        // a zero-height space pushes everything beneath it down the page, which
        // is the layout shift lazy loading is meant to remove, reintroduced by
        // the loading state itself.
        if (this.aspectRatio) {
          style.aspectRatio = this.aspectRatio
        }

        return style
      },
    },
    mounted() {

      const setLoadingState = () => {
        this.loading = false
        // the class drives the fade in common.styl; the inline opacity stays
        // because other code (mixin/sliderHelper.js) sets it the same way
        this.$el.classList.add('is-loaded')
        this.$el.style.opacity = 1
      }

      this.$el.addEventListener('load', setLoadingState)

      // A cached image can finish decoding before the listener is attached -
      // on a refresh that would leave it sitting at opacity 0 for good.
      if (this.$el.complete && this.$el.naturalWidth > 0) {
        setLoadingState()
      }
      this.$once('hook:destroyed', () => {
        this.$el.removeEventListener('load', setLoadingState)
      })

      // Without IntersectionObserver lozad never loads anything at all, and
      // the page is left with a grid of permanently blank boxes. Fall back to
      // loading the image outright - eager beats invisible.
      if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
        const src = this.$el.getAttribute('data-src')
        if (src) {
          this.$el.src = src
        }
        return
      }

      const observer = lozad(this.$el, {
        // Start a screen early. Carousel slides sit outside the viewport
        // horizontally, so with the default zero margin their images only
        // begin loading once the rail has already moved - which reads as the
        // lazy loading being broken.
        rootMargin: '300px 300px',
        threshold: 0,
        load: function(el) {
          // Custom implementation to load an element
          el.src = el.getAttribute('data-src')
        }
      })
      observer.observe()
    },
    methods: {

      onError(e) {
        const fallback = this.getImageURL()

        // The placeholder can 404 too - the uploads directory is served from
        // the project root and has been missing before. Retrying it on every
        // error event would spin forever, so give up visibly instead.
        if (this.failed || e.target.src === fallback) {
          this.failed = true
          e.target.classList.add('is-broken')
          // Reveal the box; a transparent broken image reads as a gap.
          e.target.classList.add('is-loaded')
          e.target.style.opacity = 1
          return
        }

        this.failed = true
        e.target.src = fallback
      },
    },
  };
</script>
