<template>
  <div class="sk-table" :class="{ 'sk-table--panel': panel }">
    <skeleton-base v-if="caption" :height="20" width="180px" :mb="16" :animated="animated" />

    <div v-if="header" class="sk-table-row sk-table-row--head" :style="gridStyle">
      <skeleton-base
        v-for="c in cols"
        :key="`h${c}`"
        :height="12"
        :width="c === 1 ? '70%' : '55%'"
        :animated="animated"
      />
    </div>

    <div v-for="r in rows" :key="`r${r}`" class="sk-table-row" :style="gridStyle">
      <div v-for="c in cols" :key="`r${r}c${c}`" class="sk-table-cell">
        <skeleton-base
          v-if="c === 1 && thumb"
          class="sk-table-thumb"
          :width="40"
          :height="40"
          :radius="8"
          :animated="animated"
        />
        <skeleton-base :height="14" :width="cellWidth(r, c)" :animated="animated" />
      </div>
    </div>
  </div>
</template>

<script>
/**
 * A tabular loading state: optional header strip, then rows of cells laid out
 * on the same grid so the columns line up with the real table's.
 *
 * Below `stackAt` the grid drops to one column, matching what the panel's own
 * tables do on a phone - a six-column skeleton squeezed into 360px would be
 * six slivers, and would promise a layout the page is not going to deliver.
 */
const WIDTHS = ['82%', '60%', '72%', '48%', '66%', '55%']

export default {
  name: 'SkeletonTable',
  props: {
    rows: { type: Number, default: 6 },
    cols: { type: Number, default: 4 },
    header: { type: Boolean, default: true },
    // A leading thumbnail in the first column (product/order tables).
    thumb: { type: Boolean, default: false },
    // A heading bar above the table.
    caption: { type: Boolean, default: false },
    panel: { type: Boolean, default: true },
    stackAt: { type: Number, default: 767 },
    animated: { type: Boolean, default: true }
  },
  computed: {
    gridStyle() {
      // The first column carries the name and gets the slack; the rest split
      // what is left evenly, which is how these tables are actually built.
      return { gridTemplateColumns: `minmax(0, 1.6fr) repeat(${Math.max(this.cols - 1, 0)}, minmax(0, 1fr))` }
    }
  },
  methods: {
    cellWidth(r, c) { return WIDTHS[(r + c) % WIDTHS.length] }
  }
}
</script>

<style scoped>
.sk-table { width: 100%; }

.sk-table--panel {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 18px;
}

.sk-table-row {
  display: grid;
  gap: 16px;
  align-items: center;
  padding: 14px 0;
}

.sk-table-row--head { padding-top: 0; border-bottom: 1px solid #E9EAF1; }

.sk-table-row + .sk-table-row { border-top: 1px solid #F0F1F6; }

.sk-table-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.sk-table-thumb { flex: none; }

@media (max-width: 767px) {
  .sk-table-row {
    grid-template-columns: 1fr !important;
    gap: 10px;
  }

  .sk-table-row--head { display: none; }

  .sk-table--panel { padding: 14px; }
}
</style>
