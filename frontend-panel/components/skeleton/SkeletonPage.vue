<template>
  <div class="sk-page sk-root" role="status" aria-busy="true" :aria-label="label">
    <span class="sk-sr">{{ label }}</span>

    <!-- product listing: filters beside a grid -->
    <template v-if="variant === 'listing'">
      <div class="sk-page-in">
        <skeleton-base :height="12" width="240px" :mb="22" :animated="animated" />
        <skeleton-base :height="28" width="320px" :mb="10" :animated="animated" />
        <skeleton-base :height="14" width="420px" :mb="26" :animated="animated" />

        <div class="sk-chips">
          <skeleton-base v-for="n in 7" :key="n" :height="38" width="120px" :radius="20" :animated="animated" />
        </div>

        <div class="sk-listing">
          <skeleton-sidebar class="sk-listing-side" :groups="5" :animated="animated" />
          <div class="sk-listing-main">
            <div class="sk-bar">
              <skeleton-base :height="14" width="180px" :animated="animated" />
              <skeleton-base :height="38" width="200px" :radius="8" :animated="animated" />
            </div>
            <skeleton-product-grid :count="count" :min="200" :image-height="200" :animated="animated" label="" />
          </div>
        </div>
      </div>
    </template>

    <!-- product detail: gallery, buy box, then the tabs -->
    <template v-else-if="variant === 'detail'">
      <div class="sk-page-in">
        <skeleton-base :height="12" width="300px" :mb="24" :animated="animated" />

        <div class="sk-detail">
          <div class="sk-detail-gallery">
            <skeleton-image ratio="1:1" :radius="14" :animated="animated" />
            <div class="sk-thumbs">
              <skeleton-base v-for="n in 5" :key="n" :width="68" :height="68" :radius="8" :animated="animated" />
            </div>
          </div>

          <div class="sk-detail-buy">
            <skeleton-base :height="26" width="88%" :mb="12" :animated="animated" />
            <skeleton-base :height="26" width="60%" :mb="20" :animated="animated" />
            <skeleton-base :height="14" width="150px" :mb="24" :animated="animated" />
            <skeleton-base :height="36" width="180px" :mb="12" :animated="animated" />
            <skeleton-base :height="13" width="220px" :mb="26" :animated="animated" />
            <skeleton-text :lines="3" :animated="animated" />
            <div class="sk-detail-acts">
              <skeleton-base :height="50" width="100%" :radius="10" :animated="animated" />
              <skeleton-base :height="50" width="100%" :radius="10" :animated="animated" />
            </div>
          </div>
        </div>

        <div class="sk-detail-tabs">
          <skeleton-base :height="20" width="200px" :mb="20" :animated="animated" />
          <skeleton-text :lines="5" :animated="animated" />
        </div>
      </div>
    </template>
    <!-- basket: lines beside the totals -->
    <template v-else-if="variant === 'cart'">
      <div class="sk-page-in">
        <skeleton-base :height="28" width="220px" :mb="24" :animated="animated" />
        <div class="sk-split">
          <div class="sk-split-main">
            <skeleton-card
              v-for="n in count"
              :key="n"
              class="sk-split-line"
              horizontal
              :thumb-width="110"
              image-height="110px"
              :lines="2"
              :animated="animated"
            />
          </div>
          <div class="sk-split-side">
            <skeleton-base :height="20" width="150px" :mb="20" :animated="animated" />
            <div v-for="n in 4" :key="n" class="sk-sum-row">
              <skeleton-base :height="13" width="40%" :animated="animated" />
              <skeleton-base :height="13" width="26%" :animated="animated" />
            </div>
            <skeleton-base :height="50" width="100%" :radius="10" :animated="animated" />
          </div>
        </div>
      </div>
    </template>

    <!-- checkout: the form column beside the order summary -->
    <template v-else-if="variant === 'checkout'">
      <div class="sk-page-in">
        <skeleton-base :height="28" width="240px" :mb="24" :animated="animated" />
        <div class="sk-split">
          <div class="sk-split-main">
            <div v-for="n in 3" :key="n" class="sk-form-panel">
              <skeleton-base :height="18" width="180px" :mb="18" :animated="animated" />
              <div class="sk-fields">
                <div v-for="f in 4" :key="f" class="sk-field">
                  <skeleton-base :height="11" width="90px" :mb="8" :animated="animated" />
                  <skeleton-base :height="44" width="100%" :radius="8" :animated="animated" />
                </div>
              </div>
            </div>
          </div>
          <div class="sk-split-side">
            <skeleton-base :height="20" width="150px" :mb="20" :animated="animated" />
            <div v-for="n in 4" :key="n" class="sk-sum-row">
              <skeleton-base :height="13" width="44%" :animated="animated" />
              <skeleton-base :height="13" width="24%" :animated="animated" />
            </div>
            <skeleton-base :height="50" width="100%" :radius="10" :animated="animated" />
          </div>
        </div>
      </div>
    </template>

    <!-- account: heading, stat cards, then a table -->
    <template v-else-if="variant === 'account'">
      <div class="sk-page-in">
        <skeleton-base :height="24" width="220px" :mb="10" :animated="animated" />
        <skeleton-base :height="14" width="300px" :mb="26" :animated="animated" />
        <div class="sk-cards">
          <skeleton-card v-for="n in 3" :key="n" :image="false" :lines="2" :animated="animated" />
        </div>
        <skeleton-table class="sk-gap-top" :rows="count" :cols="4" :animated="animated" />
      </div>
    </template>

    <!-- home: hero, then a rail of products -->
    <template v-else-if="variant === 'home'">
      <div class="sk-page-in">
        <skeleton-image height="320px" :radius="14" :animated="animated" />
        <div class="sk-cards sk-gap-top">
          <skeleton-card v-for="n in 4" :key="n" ratio="16:9" :lines="1" :animated="animated" />
        </div>
        <skeleton-base class="sk-gap-top" :height="22" width="240px" :mb="20" :animated="animated" />
        <skeleton-product-grid :count="count" :min="200" :image-height="200" :animated="animated" label="" />
      </div>
    </template>

    <!-- anything else -->
    <template v-else>
      <div class="sk-page-in">
        <skeleton-base :height="24" width="240px" :mb="12" :animated="animated" />
        <skeleton-base :height="14" width="360px" :mb="26" :animated="animated" />
        <skeleton-card :image="false" :lines="4" :animated="animated" />
        <skeleton-card class="sk-gap-top" :image="false" :lines="3" :animated="animated" />
      </div>
    </template>
  </div>
</template>

<script>
/**
 * A whole-page loading state, shaped like the page it stands in for.
 *
 * This is what replaces a centred spinner on a route that has nothing to show
 * yet. A spinner says "wait"; a skeleton says "wait, and here is what is
 * coming" - the reader can start parsing the layout before the data lands,
 * and when it does the page fills in rather than cutting from grey to
 * content.
 *
 * Variants exist because a page skeleton that does not match its page is
 * worse than none: it promises a layout, then breaks the promise.
 */
const VARIANTS = ['listing', 'detail', 'cart', 'checkout', 'account', 'home', 'generic']

export default {
  name: 'SkeletonPage',
  props: {
    variant: {
      type: String,
      default: 'generic',
      validator: (value) => VARIANTS.indexOf(value) !== -1
    },
    // Repeat count for the variant main collection (tiles, rows, lines).
    count: { type: Number, default: 8 },
    animated: { type: Boolean, default: true },
    label: { type: String, default: 'Loading' }
  }
}
</script>

<style scoped>
.sk-page { width: 100%; }

.sk-page-in {
  width: 100%;
  max-width: 1440px;
  margin: 0 auto;
  padding: 24px 20px 40px;
}

.sk-gap-top { margin-top: 28px; }

/* listing */
.sk-chips {
  display: flex;
  gap: 12px;
  margin-bottom: 26px;
  overflow: hidden;
}

.sk-listing {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  gap: 28px;
}

.sk-listing-main { min-width: 0; }

.sk-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 22px;
}

/* detail */
.sk-detail {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 40px;
}

.sk-thumbs {
  display: flex;
  gap: 10px;
  margin-top: 14px;
}

.sk-detail-buy { min-width: 0; }

.sk-detail-acts {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-top: 26px;
}

.sk-detail-tabs { margin-top: 44px; }

/* cart + checkout */
.sk-split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 28px;
  align-items: start;
}

.sk-split-main { min-width: 0; }

.sk-split-line + .sk-split-line { margin-top: 14px; }

.sk-split-side {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 20px;
  position: sticky;
  top: 20px;
}

.sk-sum-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.sk-form-panel {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 14px;
  padding: 20px;
}

.sk-form-panel + .sk-form-panel { margin-top: 16px; }

.sk-fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
}

.sk-field { min-width: 0; }

/* account + home */
.sk-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
}

@media (max-width: 991px) {
  .sk-listing { grid-template-columns: minmax(0, 1fr); }
  .sk-listing-side { display: none; }
  .sk-detail { grid-template-columns: minmax(0, 1fr); gap: 26px; }
  .sk-split { grid-template-columns: minmax(0, 1fr); }
  .sk-split-side { position: static; }
}

@media (max-width: 576px) {
  .sk-page-in { padding: 16px 14px 32px; }
  .sk-detail-acts { grid-template-columns: 1fr; }
}
</style>
