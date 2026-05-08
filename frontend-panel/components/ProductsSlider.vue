<template>
  <div class="featured-box p-4 mb-4" v-if="itemList.product_collections.length">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0">{{ title }}</h5>
        <nuxt-link class="small text-dark" :to="collectionLink(linkObj)">
          {{ $t('featured.showAll') }}
        </nuxt-link>
    </div>
    <div class="area-content shimmer-wrapper">
      <image-slider
        :per-view="perView"
        :gap="20"
        :responsive="perViewResponsive"
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
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import ImageSlider from '~/components/ImageSlider'
  import ProductTile from '~/components/ProductTile'
  import TileShimmer from '~/components/TileShimmer'

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
      ProductTile,
      TileShimmer
    },
    computed: {
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
    },
    mixins: [util],
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
  border-radius: 20px;
}

.product-listing {
    background-color: #fff;
    border-radius: 20px;
    margin-right: 10px !important;
}
</style>

