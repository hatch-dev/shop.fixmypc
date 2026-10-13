<template>
  <div class="sk-grid-root sk-root" role="status" aria-busy="true">
    <span class="sk-sr">{{ label }}</span>

    <div v-if="rail" class="sk-rail">
      <skeleton-product
        v-for="n in count"
        :key="n"
        class="sk-rail-item"
        :rating="rating"
        :action="action"
        :image-height="imageHeight"
        :animated="animated"
        :style="railItemStyle"
      />
    </div>

    <div v-else :class="[min ? 'sk-grid' : 'tile-container', gridClass]" :style="gridStyle">
      <skeleton-product
        v-for="n in count"
        :key="n"
        :rating="rating"
        :action="action"
        :image-height="imageHeight"
        :animated="animated"
      />
    </div>
  </div>
</template>

<script>
/**
 * A grid - or a horizontal rail - of product skeletons.
 *
 * By default it renders the panel's own .tile-container, so the placeholder
 * columns are the real columns at every breakpoint. Pass `min` to override
 * with an auto-fit track when the caller's context has no tile-container of
 * its own (popups, embeds).
 */
export default {
  name: 'SkeletonProductGrid',
  props: {
    count: { type: Number, default: 8 },
    // Carousel form: one row that runs off the edge, matching the rails.
    rail: { type: Boolean, default: false },
    railItemWidth: { type: [Number, String], default: 220 },
    /*
    * Minimum column width. Setting it swaps .tile-container for this
    * component's own grid, which is the point: .tile-container is only sound
    * inside a page that styles it, and the panel additionally pins
    * .tile-container .p-tile to width: 20% below 1400px. Borrowing the class
    * outside a listing gives 20%-wide tiles - to a real ProductTile as much as
    * to this - so anything drawing its own layout passes the min prop instead.
    */
    min: { type: Number, default: 0 },
    // Mirrors the listing's grid/list toggle.
    view: { type: String, default: '' },
    // Passed to each tile; see SkeletonProduct's own note. Only needed where
    // the surrounding CSS gives the image box no height of its own.
    imageHeight: { type: [Number, String], default: null },
    rating: { type: Boolean, default: true },
    action: { type: Boolean, default: false },
    animated: { type: Boolean, default: true },
    label: { type: String, default: 'Loading products' }
  },
  computed: {
    gridClass() {
      return this.view ? `view-${this.view}` : ''
    },
    gridStyle() {
      if (!this.min) return {}
      return { gridTemplateColumns: `repeat(auto-fill, minmax(${this.min}px, 1fr))` }
    },
    railItemStyle() {
      const width = typeof this.railItemWidth === 'number' ? `${this.railItemWidth}px` : this.railItemWidth
      return { width, minWidth: width }
    }
  }
}
</script>

<style scoped>
.sk-grid {
  display: grid;
  gap: 20px;
}

.sk-rail {
  display: flex;
  gap: 16px;
  overflow: hidden;
}

.sk-rail-item { flex: none; }
</style>
