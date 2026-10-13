<template>
  <div class="acc-skeleton" aria-busy="true" aria-live="polite">
    <span class="sr-only">{{ label }}</span>

    <!-- Page heading -->
    <div v-if="heading" class="acc-sk-head">
      <div class="acc-sk-head-text">
        <span class="sk sk-line sk-h-22 sk-w-220"></span>
        <span class="sk sk-line sk-w-280"></span>
      </div>
      <span class="sk sk-line sk-w-120 acc-sk-hide-sm"></span>
    </div>

    <!-- Summary cards -->
    <div v-if="cards" class="acc-sk-grid" :style="cardGridStyle">
      <div v-for="n in cards" :key="`c${n}`" class="acc-sk-panel acc-sk-card">
        <span class="sk sk-line sk-w-100"></span>
        <span class="sk sk-line sk-h-26 sk-w-160"></span>
        <span class="sk sk-line sk-w-120"></span>
      </div>
    </div>

    <!-- Table: header strip then rows -->
    <div v-if="rows" class="acc-sk-panel acc-sk-block">
      <span class="sk sk-line sk-h-20 sk-w-180"></span>
      <span class="sk sk-line sk-h-34 acc-sk-gap"></span>
      <span
        v-for="n in rows"
        :key="`r${n}`"
        class="sk sk-line sk-h-18 acc-sk-row"
      ></span>
    </div>

    <!-- Tiles: wishlist, recently viewed, vouchers -->
    <div v-if="tiles" class="acc-sk-grid acc-sk-tiles">
      <div v-for="n in tiles" :key="`t${n}`" class="acc-sk-panel">
        <span class="sk sk-block sk-thumb"></span>
        <span class="sk sk-line acc-sk-gap"></span>
        <span class="sk sk-line sk-w-120"></span>
      </div>
    </div>

    <!-- Form: label / field pairs -->
    <div v-if="fields" class="acc-sk-panel acc-sk-block">
      <span class="sk sk-line sk-h-20 sk-w-180"></span>
      <div class="acc-sk-fields">
        <div v-for="n in fields" :key="`f${n}`" class="acc-sk-field">
          <span class="sk sk-line sk-w-100 sk-h-12"></span>
          <span class="sk sk-line sk-h-40"></span>
        </div>
      </div>
    </div>

    <!-- Stacked panels -->
    <div v-if="panels" class="acc-sk-grid acc-sk-panels">
      <div v-for="n in panels" :key="`p${n}`" class="acc-sk-panel">
        <span class="sk sk-line sk-h-20 sk-w-180"></span>
        <span class="sk sk-line acc-sk-gap"></span>
        <span class="sk sk-line sk-w-220"></span>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * The loading state for the account pages.
 *
 * One component rather than a skeleton hand-written into each page: seven
 * pages drifting apart visually is exactly how a "professional" panel stops
 * looking like one, and a shared shimmer means a single place to change the
 * timing or honour reduced motion.
 *
 * Compose the shape of the page being loaded from the props, so the skeleton
 * occupies roughly the same space as the real content and the page does not
 * jump when it arrives. All counts default to 0, i.e. that block is not drawn.
 */
export default {
  name: 'AccountSkeleton',
  props: {
    // Page title + subtitle block.
    heading: { type: Boolean, default: false },
    // Number of summary cards across the top.
    cards: { type: Number, default: 0 },
    // Number of table rows.
    rows: { type: Number, default: 0 },
    // Number of product/voucher tiles.
    tiles: { type: Number, default: 0 },
    // Number of form fields.
    fields: { type: Number, default: 0 },
    // Number of side-by-side content panels.
    panels: { type: Number, default: 0 },
    // Minimum width of one card, so 2-up and 3-up rows both look right.
    cardMin: { type: Number, default: 260 },
    // Announced to screen readers while the page loads.
    label: { type: String, default: 'Loading' }
  },
  computed: {
    cardGridStyle() {
      return { gridTemplateColumns: `repeat(auto-fit, minmax(${this.cardMin}px, 1fr))` }
    }
  }
}
</script>

<style scoped>
.acc-skeleton { width: 100%; }

.sr-only {
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

.sk {
  display: block;
  border-radius: 6px;
  background: linear-gradient(90deg, #EEEFF5 25%, #F7F8FC 37%, #EEEFF5 63%);
  background-size: 400% 100%;
  animation: acc-sk-shimmer 1.4s ease infinite;
}

.sk-line  { height: 14px; width: 100%; margin-bottom: 10px; }
.sk-block { width: 100%; }
.sk-thumb { height: 150px; border-radius: 10px; }

.sk-h-12 { height: 12px; }
.sk-h-18 { height: 18px; }
.sk-h-20 { height: 20px; }
.sk-h-22 { height: 22px; }
.sk-h-26 { height: 26px; }
.sk-h-34 { height: 34px; }
.sk-h-40 { height: 40px; }

.sk-w-100 { max-width: 100px; }
.sk-w-120 { max-width: 120px; }
.sk-w-160 { max-width: 160px; }
.sk-w-180 { max-width: 180px; }
.sk-w-220 { max-width: 220px; }
.sk-w-280 { max-width: 280px; }

.acc-sk-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}

.acc-sk-head-text { flex: 1 1 240px; }

.acc-sk-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 16px;
}

.acc-sk-tiles  { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
.acc-sk-panels { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }

.acc-sk-panel {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 20px;
}

.acc-sk-card  { min-height: 132px; }
.acc-sk-block { margin-top: 16px; }
.acc-sk-gap   { margin-top: 16px; }
.acc-sk-row   { margin-top: 14px; }

.acc-sk-fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-top: 18px;
}

.acc-sk-field .sk-line:last-child { margin-bottom: 0; }

@keyframes acc-sk-shimmer {
  0%   { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}

/* A shimmer that cannot be turned off is a problem for some readers. */
@media (prefers-reduced-motion: reduce) {
  .sk { animation: none; }
}

@media (max-width: 767px) {
  .acc-sk-hide-sm { display: none; }
  .acc-sk-panel   { padding: 16px; }
}
</style>
