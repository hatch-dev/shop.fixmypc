/**
 * Shared behaviour for the home page's scroll-snap carousels.
 *
 * The track itself is pure CSS (overflow-x + scroll-snap); this only reports
 * where it has landed and drives it when a dot is tapped. Used by
 * ProductBanner, StaticSection and the trending grid in pages/index.vue.
 *
 * A component opts in by:
 *   - putting `ref="track"` on the scrolling element and `@scroll.passive="onScroll"`
 *   - overriding `carouselCount` with the number of children in the track
 *   - overriding `carouselStep` when a page holds more than one child
 *     (the CSS must put `scroll-snap-align` on every `carouselStep`-th child)
 *   - overriding `carouselAutoplay` if it should advance itself
 *   - rendering `.m-dots` / `.m-dot` when `showDots` is true, over `pageCount`
 *
 * `active` is a page index, not a child index. Component computed properties
 * override the mixin's, so the defaults below are only a floor.
 */
export default {
  data() {
    return {
      active: 0,
      isCarousel: false,
      reduceMotion: false,
      carouselTimer: null,
      carouselTicking: false,
      carouselMq: null
    }
  },
  computed: {
    // how many children the track holds
    carouselCount() {
      return 0
    },
    // how many of those children make up one page
    carouselStep() {
      return 1
    },
    // milliseconds between pages; 0 leaves the track entirely user-driven
    carouselAutoplay() {
      return 0
    },
    pageCount() {
      return Math.max(1, Math.ceil(this.carouselCount / this.carouselStep))
    },
    showDots() {
      return this.isCarousel && this.pageCount > 1
    }
  },
  methods: {
    // gap-agnostic: ask the slide where it actually sits rather than
    // multiplying up a width. The track is position:relative on mobile, so it
    // is the offsetParent and offsetLeft is the scrollLeft we want.
    goTo(page) {
      const track = this.$refs.track
      const slide = track?.children?.[page * this.carouselStep]
      if (!track || !slide) return
      this.active = page
      track.scrollTo({
        left: slide.offsetLeft,
        behavior: this.reduceMotion ? 'auto' : 'smooth'
      })
      this.restartAuto()
    },
    onScroll() {
      if (!this.isCarousel || this.carouselTicking) return
      this.carouselTicking = true
      window.requestAnimationFrame(() => {
        this.carouselTicking = false
        const track = this.$refs.track
        if (!track) return
        let best = 0
        let bestDistance = Infinity
        for (let i = 0; i < track.children.length; i++) {
          const distance = Math.abs(track.children[i].offsetLeft - track.scrollLeft)
          if (distance < bestDistance) {
            bestDistance = distance
            best = i
          }
        }
        const page = Math.round(best / this.carouselStep)
        this.active = Math.min(this.pageCount - 1, Math.max(0, page))
        this.restartAuto()
      })
    },
    startAuto() {
      this.stopAuto()
      if (!this.isCarousel || this.reduceMotion) return
      if (!this.carouselAutoplay || this.pageCount < 2) return
      this.carouselTimer = setInterval(() => {
        this.goTo((this.active + 1) % this.pageCount)
      }, this.carouselAutoplay)
    },
    // pushes the next advance out past whatever the reader just did, rather
    // than yanking the track mid-swipe
    restartAuto() {
      if (this.carouselTimer) this.startAuto()
    },
    stopAuto() {
      clearInterval(this.carouselTimer)
      this.carouselTimer = null
    },
    onBreakpoint(e) {
      this.isCarousel = e.matches
      if (this.isCarousel) {
        this.startAuto()
      } else {
        this.stopAuto()
        this.active = 0
      }
    }
  },
  mounted() {
    this.reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    this.carouselMq = window.matchMedia('(max-width: 991px)')
    this.onBreakpoint(this.carouselMq)
    // Safari below 14 only has the deprecated listener
    if (this.carouselMq.addEventListener) {
      this.carouselMq.addEventListener('change', this.onBreakpoint)
    } else {
      this.carouselMq.addListener(this.onBreakpoint)
    }
  },
  beforeDestroy() {
    this.stopAuto()
    if (!this.carouselMq) return
    if (this.carouselMq.removeEventListener) {
      this.carouselMq.removeEventListener('change', this.onBreakpoint)
    } else {
      this.carouselMq.removeListener(this.onBreakpoint)
    }
  }
}
