/**
 * Page indicator for a glide rail (ImageSlider).
 *
 * Glide's own `bullets` are one-per-slide and render as a dark pill absolutely
 * positioned over the track - right for a hero image, wrong for a rail of
 * cards. This drives the shared `.m-dots` / `.m-dot` markup below the rail
 * instead, counting *pages* rather than slides.
 *
 * A component opts in by:
 *   - passing `@glide="onGlide"` to <image-slider>
 *   - overriding `glideItemCount` with the number of slides in the rail
 *   - rendering `.m-dots` when `pageCount > 1`, with `activePage` / `goToPage`
 *
 * Glide is not reactive, so its state is mirrored into data whenever it
 * settles or re-reads its breakpoint - `resize` matters because perView
 * changes there, and a stale count would leave the wrong number of dots.
 */
export default {
  data() {
    return {
      glide: null,
      activePage: 0,
      pageCount: 0
    }
  },
  computed: {
    glideItemCount() {
      return 0
    }
  },
  methods: {
    onGlide(glide) {
      this.glide = glide
      glide.on(['mount.after', 'run.after', 'resize'], this.syncDots)
      this.syncDots()
    },
    // the rail moves a full page at a time (perSwipe '|'), so glide.index sits
    // on a multiple of perView - except at the end, where `bound` clamps it
    syncDots() {
      const perView = this.glide?.settings?.perView || 1
      this.pageCount = Math.max(1, Math.ceil(this.glideItemCount / perView))
      const page = Math.round((this.glide?.index || 0) / perView)
      this.activePage = Math.min(this.pageCount - 1, Math.max(0, page))
    },
    goToPage(page) {
      const perView = this.glide?.settings?.perView || 1
      this.glide?.go(`=${page * perView}`)
    }
  }
}
