<template>
  <div v-if="groups.length">
    <image-slider
      :image-count="groups.length"
      :per-view="2"
      :gap="20"
      :responsive="[2, 2, 1, 1, 1]"
      @glide="onGlide"
      @loaded="$emit('loaded', $event)"
    >
      <template v-slot:content>
        <li
          v-for="(group, groupIndex) in groups"
          :key="groupIndex"
          class="slider-item"
        >
          <div class="double-column">
            <div
              v-for="(product, index) in group"
              :key="index"
              class="product-wrap"
            >
              <product-tile
                :product="product"
              />
            </div>
          </div>
        </li>
      </template>
    </image-slider>

    <div
      v-if="pageCount > 1"
      class="m-dots"
    >
      <button
        v-for="i in pageCount"
        :key="i"
        type="button"
        class="m-dot"
        :class="{on: i - 1 === activePage}"
        :aria-label="String(i)"
        :aria-current="i - 1 === activePage ? 'true' : 'false'"
        @click="goToPage(i - 1)"
      />
    </div>
  </div>
</template>

<script>
  import ImageSlider from '~/components/ImageSlider'
  import ProductTile from '~/components/ProductTile'
  import glideDots from '~/mixin/glideDots'

  export default {
    name: 'FlashSaleRail',
    // One rail per flash sale. This is its own component so each rail owns its
    // own glide instance and page state - FlashSale renders a v-for over
    // flashSales, and a single shared set of those would collide.
    props: {
      products: {
        type: Array,
        default() {
          return []
        }
      }
    },
    components: {
      ImageSlider,
      ProductTile
    },
    mixins: [glideDots],
    computed: {
      // two products to a slide
      groups() {
        const out = []
        for (let i = 0; i < this.products.length; i += 2) {
          out.push(this.products.slice(i, i + 2))
        }
        return out
      },
      glideItemCount() {
        return this.groups.length
      }
    }
  }
</script>

<style scoped>
.slider-item {
  list-style: none;
  height: 100%;
}

:deep(.glide__slide) {
  height: auto;
}

.double-column {
  display: flex;
  flex-direction: column;
  gap: 20px;
  height: 100%;
}

.product-wrap {
  background: #F3F5FC;
  border-radius: 18px;
  overflow: hidden;
}
</style>
