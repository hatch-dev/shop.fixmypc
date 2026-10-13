<template>
  <div class="featured-box p-4 mb-4" :style="{
      backgroundImage: `url(uploads/${backgroundImage})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center'
    }" v-if="itemList.product_collections.length">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="slider-collection-heading">{{ title }}</h5>
        <nuxt-link class="link" :to="collectionLink(linkObj)">
          {{ $t('featured.showAll') }}
        </nuxt-link>
    </div>
    <div class="area-content shimmer-wrapper">
      <image-slider
        :per-view="perView"
        :gap="20"
        :responsive="perViewResponsive"
        :image-count="itemList.product_collections.length"
        @glide="onGlide"
      >
        <template v-slot:content>
          <li class="product-listing"
            v-for="(value, index) in itemList.product_collections"
            :key="index"
          >
            <product-tile
              :product="value"
            />
          </li>
        </template>
      </image-slider>
    </div>

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
  import util from '~/mixin/util'
  import glideDots from '~/mixin/glideDots'
  import ImageSlider from '~/components/ImageSlider'
  import ProductTile from '~/components/ProductTile'

  export default {
    name: 'ProductsSlider',
    data() {
      return {}
    },
    watch: {},
    props: {
      collection: {
        type: Object
      },
      perView: {
        type: Number,
        default: 4
      },
      perViewResponsive: {
        type: Array,
        default(){
          return [4, 3, 1, 1, 1]
        }
      },
    },
    components: {
      ImageSlider,
      ProductTile
    },
    computed: {
      backgroundImage() {
        return this.collection?.image
      },
      itemList() {
        return this.collection
      },
      title() {
        return this.collection?.title
      },
      slug() {
        return this.collection?.slug
      },
      linkObj() {
        return {
          slug: this.slug,
          title: this.title,
          id: this.collection?.id
        }
      },
      glideItemCount() {
        return this.itemList?.product_collections?.length || 0
      },
    },
    mixins: [util, glideDots],
    methods: {},
    created() {
    },
    mounted() {
    }
  }
</script>
<style scoped>
.featured-box {
  background: #F3F5FC;
  border-radius: 30px;
  border: 1px solid #E3E2F1;
}

/* No margin here: glide implements its `gap` as inline marginLeft/marginRight
   on each slide, and an !important rule overrides inline styles - which left
   the slides wider than glide's translate assumed and clipped the rail at both
   edges. Spacing comes from the :gap prop. */
.product-listing {
    background-color: #fff;
    border-radius: 20px;
}

/* On mobile the rail reads as the category listing grid: the white wrapper
   sits behind an opaque card, and the tile needs a flex parent to stretch into
   so both cards in a row end level. */
@media only screen and (max-width: 991px) {
  .product-listing {
    background-color: transparent;
    display: flex;
    border-radius: 16px;
  }
}
</style>

