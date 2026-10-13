<template>
  <div class="sk-card" :class="{ 'sk-card--plain': !panel, 'sk-card--row': horizontal }">
    <skeleton-image
      v-if="image"
      class="sk-card-media"
      :ratio="ratio"
      :height="imageHeight"
      :width="horizontal ? thumbWidth : '100%'"
      :radius="10"
      :animated="animated"
    />

    <div class="sk-card-body">
      <skeleton-base v-if="title" :height="18" width="70%" :mb="14" :animated="animated" />
      <skeleton-text v-if="lines" :lines="lines" :animated="animated" />

      <div v-if="footer" class="sk-card-foot">
        <skeleton-base :height="34" width="110px" :radius="8" :animated="animated" />
        <skeleton-base :height="34" width="80px" :radius="8" :animated="animated" />
      </div>
    </div>
  </div>
</template>

<script>
/**
 * A generic content card: optional media, a heading, some body lines and an
 * optional action row. Used for dashboard widgets, promo blocks and any panel
 * whose exact shape does not warrant its own skeleton.
 *
 * `horizontal` gives the media-beside-text form used by order rows and
 * saved-item lists.
 */
export default {
  name: 'SkeletonCard',
  props: {
    image: { type: Boolean, default: true },
    title: { type: Boolean, default: true },
    lines: { type: Number, default: 2 },
    footer: { type: Boolean, default: false },
    // Draws the bordered white panel. Off when the caller already has one.
    panel: { type: Boolean, default: true },
    horizontal: { type: Boolean, default: false },
    ratio: { type: [String, Number], default: '4:3' },
    imageHeight: { type: [Number, String], default: null },
    thumbWidth: { type: [Number, String], default: 96 },
    animated: { type: Boolean, default: true }
  }
}
</script>

<style scoped>
.sk-card {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 16px;
  width: 100%;
}

.sk-card--plain {
  background: none;
  border: 0;
  padding: 0;
  border-radius: 0;
}

.sk-card-media { margin-bottom: 14px; }

.sk-card--row {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}

.sk-card--row .sk-card-media {
  margin-bottom: 0;
  flex: none;
}

.sk-card--row .sk-card-body { flex: 1 1 auto; min-width: 0; }

.sk-card-foot {
  display: flex;
  gap: 10px;
  margin-top: 16px;
  flex-wrap: wrap;
}
</style>
