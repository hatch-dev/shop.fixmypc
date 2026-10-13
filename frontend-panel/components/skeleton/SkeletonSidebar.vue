<template>
  <aside class="sk-sidebar" :class="{ 'sk-sidebar--panel': panel }" aria-hidden="true">
    <div v-for="g in groups" :key="g" class="sk-sb-group">
      <div class="sk-sb-head">
        <skeleton-base :height="15" :width="headWidth(g)" :animated="animated" />
        <skeleton-base :height="10" width="10px" :radius="2" :animated="animated" />
      </div>

      <div class="sk-sb-items">
        <div v-for="i in itemsFor(g)" :key="`${g}-${i}`" class="sk-sb-item">
          <skeleton-base v-if="checkbox" :width="16" :height="16" :radius="4" :animated="animated" />
          <skeleton-base :height="12" :width="itemWidth(g + i)" :animated="animated" />
        </div>
      </div>
    </div>
  </aside>
</template>

<script>
/**
 * The filter column on listing pages: a stack of collapsible groups, each a
 * heading and a few checkbox rows.
 *
 * Group sizes are staggered so the column does not read as one repeated
 * block - the real filter set never has five groups of identical length.
 */
const HEADS = ['58%', '44%', '66%', '50%', '62%', '48%']
const ITEMS = ['86%', '64%', '75%', '58%', '80%', '68%', '54%']
const COUNTS = [5, 3, 4, 3, 5, 4]

export default {
  name: 'SkeletonSidebar',
  props: {
    groups: { type: Number, default: 5 },
    // Fixed row count per group; 0 staggers them.
    items: { type: Number, default: 0 },
    checkbox: { type: Boolean, default: true },
    panel: { type: Boolean, default: false },
    animated: { type: Boolean, default: true }
  },
  methods: {
    itemsFor(g) { return this.items || COUNTS[(g - 1) % COUNTS.length] },
    headWidth(g) { return HEADS[(g - 1) % HEADS.length] },
    itemWidth(n) { return ITEMS[n % ITEMS.length] }
  }
}
</script>

<style scoped>
.sk-sidebar { width: 100%; }

.sk-sidebar--panel {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 18px;
}

.sk-sb-group { padding: 18px 0; }

.sk-sb-group + .sk-sb-group { border-top: 1px solid #EEEFF5; }

.sk-sb-group:first-child { padding-top: 0; }

.sk-sb-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.sk-sb-head .sk-base:first-child { flex: 0 1 auto; }

.sk-sb-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.sk-sb-item + .sk-sb-item { margin-top: 13px; }
</style>
