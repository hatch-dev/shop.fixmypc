<template>
  <div class="sk-list" :class="{ 'sk-list--panel': panel, 'sk-list--divided': divided }">
    <div v-for="n in rows" :key="n" class="sk-list-row">
      <skeleton-base
        v-if="avatar"
        class="sk-list-avatar"
        :width="avatarSize"
        :height="avatarSize"
        :circle="round"
        :radius="8"
        :animated="animated"
      />

      <div class="sk-list-body">
        <skeleton-base :height="14" :width="titleWidth(n)" :mb="lines > 1 ? 9 : 0" :animated="animated" />
        <skeleton-base v-if="lines > 1" :height="12" :width="subWidth(n)" :animated="animated" />
      </div>

      <skeleton-base v-if="trailing" class="sk-list-trail" :height="14" width="64px" :animated="animated" />
    </div>
  </div>
</template>

<script>
/**
 * A vertical list of rows: search suggestions, address books, order lines,
 * notifications.
 *
 * Row widths are varied by index rather than being uniform. A column of
 * identical bars reads as a loaded table of empty records; a ragged one reads
 * as text that has not arrived yet, which is what is actually happening.
 */
const TITLES = ['78%', '62%', '85%', '54%', '70%']
const SUBS = ['40%', '52%', '34%', '46%', '38%']

export default {
  name: 'SkeletonList',
  props: {
    rows: { type: Number, default: 5 },
    avatar: { type: Boolean, default: true },
    avatarSize: { type: [Number, String], default: 44 },
    round: { type: Boolean, default: false },
    // 1 = title only, 2 = title plus a sub-line.
    lines: { type: Number, default: 2 },
    // A right-hand value column (price, status, date).
    trailing: { type: Boolean, default: false },
    panel: { type: Boolean, default: false },
    divided: { type: Boolean, default: true },
    animated: { type: Boolean, default: true }
  },
  methods: {
    titleWidth(n) { return TITLES[(n - 1) % TITLES.length] },
    subWidth(n) { return SUBS[(n - 1) % SUBS.length] }
  }
}
</script>

<style scoped>
.sk-list { width: 100%; }

.sk-list--panel {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 6px 16px;
}

.sk-list-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 0;
}

.sk-list--divided .sk-list-row + .sk-list-row { border-top: 1px solid #F0F1F6; }

.sk-list-avatar { flex: none; }

.sk-list-body { flex: 1 1 auto; min-width: 0; }

.sk-list-trail { flex: none; }
</style>
