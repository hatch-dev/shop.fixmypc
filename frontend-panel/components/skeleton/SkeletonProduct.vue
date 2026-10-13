<template>
  <div class="p-tile sk-product">
    <div class="card-wrapper">
      <span class="page-link p-tile-img-link">
        <span class="block img-wrapper" :style="imageStyle">
          <skeleton-base class="sk-product-img" width="100%" height="100%" :radius="0" :animated="animated" />
        </span>
      </span>

      <div class="product-tile-content">
        <div class="sk-product-title">
          <skeleton-base
            v-for="n in titleLines"
            :key="n"
            :height="13"
            :width="n === titleLines ? '64%' : '92%'"
            :mb="n === titleLines ? 0 : 8"
            :animated="animated"
          />
        </div>

        <skeleton-base v-if="rating" class="sk-product-rating" :height="11" width="46%" :animated="animated" />

        <skeleton-base class="sk-product-price" :height="19" width="58%" :animated="animated" />

        <skeleton-base v-if="action" class="sk-product-btn" :height="38" width="100%" :radius="8" :animated="animated" />
      </div>
    </div>
  </div>
</template>

<script>
/**
 * The loading state for one product tile.
 *
 * It borrows ProductTile's own class names - p-tile, card-wrapper,
 * img-wrapper, product-tile-content - rather than inventing a parallel set.
 * That is the whole trick: the listing, the home rails and the suggested
 * strip each size those classes differently, and because the skeleton wears
 * them it picks up each context's real geometry for free. The tile that
 * replaces it therefore lands in the same box, and nothing below it moves.
 */
export default {
  name: 'SkeletonProduct',
  props: {
    /*
     * How many lines the title takes.
     *
     * One, because that is what the real tile shows: ProductTile clamps with
     * .ellipsis-{titleEllipsis}, titleEllipsis defaults to 2 everywhere, and
     * .ellipsis-2 is defined as -webkit-line-clamp: 1. Drawing the two lines
     * the class name implies would make every placeholder a line taller than
     * the tile replacing it.
     */
    titleLines: { type: Number, default: 1 },
    /*
     * An explicit height for the image box.
     *
     * Normally left alone: the box comes from the context, which is what keeps
     * the placeholder the same size as the tile. Set it only where the context
     * gives no box at all - a bare .tile-container outside the listing has its
     * padding-bottom cleared and no height to replace it, so both this and a
     * real tile collapse to nothing there.
     */
    imageHeight: { type: [Number, String], default: null },
    rating: { type: Boolean, default: true },
    // An add-to-basket button bar, for the rails that show one.
    action: { type: Boolean, default: false },
    animated: { type: Boolean, default: true }
  },
  computed: {
    imageStyle() {
      if (this.imageHeight === null || this.imageHeight === '') return {}
      const height = typeof this.imageHeight === 'number' ? `${this.imageHeight}px` : this.imageHeight
      // padding must go too, or a context that squares the box by padding
      // would add that on top of the height asked for here
      return { height, paddingBottom: '0' }
    }
  }
}
</script>

<style scoped>
.sk-product { cursor: default; }

/*
 * Deliberately almost no CSS here.
 *
 * Every box this component draws is sized by the panel's own rules for
 * p-tile / card-wrapper / img-wrapper / product-tile-content, which differ per
 * context: the listing pins the image to 180px (140px below 900px wide), while
 * elsewhere the global .img-wrapper padding-bottom: 100% makes it square.
 * Inheriting whichever applies is the entire point - it is what makes the
 * placeholder the same size as the tile that replaces it.
 *
 * So this file must not add a height, a min-height, or a display mode the real
 * tile does not have. Each would look like a safety net and behave like a bug:
 * a 180px min-height, for instance, beats a 140px height whatever the
 * specificity, and would hold every mobile listing row 40px too tall.
 */
/*
 * The one layout rule this file does add. .p-tile-img-link is an inline <a> by
 * default and only the listing makes it a block; anywhere else the image box
 * inside it has no width to be a percentage of, and collapses. Every context
 * that styles it already sets block, so this changes nothing where it matters
 * and rescues the box where it does.
 */
.sk-product .p-tile-img-link { display: block; }

.sk-product .img-wrapper { overflow: hidden; }

.sk-product .sk-product-img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.sk-product .product-tile-content { padding: 14px; }

/*
 * The gaps are 15px rather than a round 12 because that is what makes the
 * placeholder the same height as the tile. Measured against a real ProductTile
 * in a .category-listing at 1440 / 1000 / 860 / 600: tests/skeleton.check.js
 * has the numbers, and scratch/probe.html is how they were taken.
 */
.sk-product .sk-product-title { margin-bottom: 15px; }

.sk-product .sk-product-rating { margin-bottom: 15px; }

.sk-product .sk-product-price { margin-bottom: 0; }

.sk-product .sk-product-btn { margin-top: 14px; }

@media (max-width: 576px) {
  .sk-product .product-tile-content { padding: 10px; }
}
</style>
