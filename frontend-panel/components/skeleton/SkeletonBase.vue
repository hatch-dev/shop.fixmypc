<template>
  <component
    :is="tag"
    class="sk-base"
    :class="{
      'sk-base--circle': circle,
      'sk-base--still': !animated
    }"
    :style="style"
    aria-hidden="true"
  />
</template>

<script>
/**
 * The one element in the panel that actually paints a skeleton.
 *
 * Every other Skeleton* component is layout: it arranges SkeletonBase
 * instances and paints nothing itself. That is deliberate - the shimmer
 * timing, the colours and the reduced-motion opt-out then live in exactly one
 * stylesheet instead of being re-tuned in ten components that slowly drift
 * apart.
 *
 * Sizes take a Number (treated as px) or a String (any CSS length, so '100%',
 * '12rem' and 'calc(100% - 20px)' all work).
 */
const toCss = (value, fallback) => {
  if (value === null || value === undefined || value === '') return fallback
  return typeof value === 'number' ? `${value}px` : value
}

export default {
  name: 'SkeletonBase',
  props: {
    width: { type: [Number, String], default: '100%' },
    height: { type: [Number, String], default: 14 },
    // Corner rounding. Ignored when `circle` is set.
    radius: { type: [Number, String], default: 6 },
    // Forces a 1:1 box with a full round - avatars, icon chips.
    circle: { type: Boolean, default: false },
    // Space below, so callers stack bars without a wrapper per bar.
    mb: { type: [Number, String], default: 0 },
    tag: { type: String, default: 'span' },
    // Off for a static placeholder (print, or a very long list where a
    // hundred shimmering bars is just noise).
    animated: { type: Boolean, default: true }
  },
  computed: {
    style() {
      const width = toCss(this.width, '100%')
      const height = toCss(this.height, '14px')

      const style = {
        width,
        height,
        marginBottom: toCss(this.mb, '0px')
      }

      if (this.circle) {
        // A circle is defined by one dimension; honour whichever was given.
        const size = this.width === '100%' ? height : width
        style.width = size
        style.height = size
      } else {
        style.borderRadius = toCss(this.radius, '6px')
      }

      return style
    }
  }
}
</script>

<style>
/*
 * Unscoped on purpose: this is the panel's skeleton token set, and the other
 * Skeleton* components render <skeleton-base> children whose scoped-style
 * attribute belongs to them, not here.
 */
.sk-base {
  display: block;
  flex: none;
  background-color: #EEEFF5;
  background-image: linear-gradient(90deg, #EEEFF5 25%, #F7F8FC 37%, #EEEFF5 63%);
  background-size: 400% 100%;
  animation: sk-base-shimmer 1.4s ease infinite;
}

.sk-base--circle { border-radius: 50%; }

.sk-base--still { animation: none; }

@keyframes sk-base-shimmer {
  0%   { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}

/*
 * A shimmer that cannot be turned off is a genuine problem for some readers,
 * and this one covers whole pages.
 */
@media (prefers-reduced-motion: reduce) {
  .sk-base { animation: none; }
}

/* Shared wrapper: announces the wait once, for the whole skeleton. */
.sk-root { width: 100%; }

.sk-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
