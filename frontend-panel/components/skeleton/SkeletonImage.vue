<template>
  <span class="sk-image" :style="frameStyle">
    <skeleton-base
      class="sk-image-fill"
      width="100%"
      height="100%"
      :radius="radius"
      :animated="animated"
    />
  </span>
</template>

<script>
/**
 * An image placeholder that reserves the picture's box before the picture
 * exists.
 *
 * The point is the reservation. A placeholder that collapses to nothing and
 * then springs to 300px when the response lands moves everything below it
 * down the page - which is the layout shift that lazy loading is supposed to
 * remove, reintroduced by the loading state. So the height comes from an
 * aspect ratio held open with the padding-bottom trick (no `aspect-ratio`
 * dependency: this panel still serves older Safari), or from an explicit
 * height when the caller knows it.
 */
const toCss = (value) => (typeof value === 'number' ? `${value}px` : value)

export default {
  name: 'SkeletonImage',
  props: {
    // 'w:h' ('4:3', '16:9') or a Number read as width / height.
    ratio: { type: [String, Number], default: '1:1' },
    // Overrides `ratio` entirely when the real height is known.
    height: { type: [Number, String], default: null },
    width: { type: [Number, String], default: '100%' },
    radius: { type: [Number, String], default: 10 },
    animated: { type: Boolean, default: true }
  },
  computed: {
    paddingBottom() {
      let w
      let h

      if (typeof this.ratio === 'number') {
        w = this.ratio
        h = 1
      } else {
        const parts = String(this.ratio).split(':')
        w = parseFloat(parts[0])
        h = parseFloat(parts[1])
      }

      // Anything unparseable falls back to a square rather than to a zero
      // height box, which would be the layout shift all over again.
      if (!w || !h || !isFinite(w) || !isFinite(h)) return '100%'

      return `${(h / w) * 100}%`
    },
    frameStyle() {
      const style = { width: toCss(this.width) }

      if (this.height !== null && this.height !== '') {
        style.height = toCss(this.height)
      } else {
        style.paddingBottom = this.paddingBottom
      }

      return style
    }
  }
}
</script>

<style scoped>
.sk-image {
  display: block;
  position: relative;
  overflow: hidden;
}

/*
 * A plain descendant selector, not the >>> deep combinator: Vue puts this
 * component's scope id on a child component's root element, so .sk-image-fill
 * is already in scope here. >>> also only exists after vue-loader has rewritten
 * it, which makes the rule invisible to anything that reads this file as CSS.
 */
.sk-image .sk-image-fill {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  width: 100%;
  height: 100%;
}
</style>
