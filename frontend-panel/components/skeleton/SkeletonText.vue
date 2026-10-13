<template>
  <span class="sk-text" :class="{ 'sk-text--block': block }">
    <skeleton-base
      v-for="n in lines"
      :key="n"
      :width="widthFor(n)"
      :height="lineHeight"
      :radius="radius"
      :mb="n === lines ? 0 : gap"
      :animated="animated"
    />
  </span>
</template>

<script>
/**
 * A run of text lines. The last line is shortened by default because a
 * paragraph of equal-length bars reads as a table, not as prose.
 */
export default {
  name: 'SkeletonText',
  props: {
    lines: { type: Number, default: 3 },
    lineHeight: { type: [Number, String], default: 14 },
    gap: { type: [Number, String], default: 10 },
    radius: { type: [Number, String], default: 6 },
    width: { type: [Number, String], default: '100%' },
    // Width of the final line. Set equal to `width` for a flush block.
    lastWidth: { type: [Number, String], default: '65%' },
    block: { type: Boolean, default: true },
    animated: { type: Boolean, default: true }
  },
  methods: {
    widthFor(n) {
      // A single line has no "last line" to taper - it is a label, not a
      // paragraph, so it keeps its full width.
      if (this.lines === 1) return this.width
      return n === this.lines ? this.lastWidth : this.width
    }
  }
}
</script>

<style scoped>
.sk-text--block { display: block; width: 100%; }
</style>
