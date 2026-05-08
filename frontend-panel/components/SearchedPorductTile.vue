<template>
  <router-link
    :to="productLink(product)"
    class="col-md-4 text-decoration-none product-link-tile"
  >
    <div class="product-card">
      <div class="product-img">
        <lazy-image
          :data-src="thumbImageURL(product)"
          :title="product.title"
          :alt="product.title"
        />
      </div>
      <div class="product-info">
        <div class="product-title ellipsis ellipsis-2">
          {{ product.title }}
        </div>
        <div class="product-pricing"> 
          <span class="price">
            <price-format :price="currentPricing" />
          </span>
          <span
            class="old-price"
            v-if="prevPrice"
          >
            <price-format :price="prevPrice" class="old-price-discount" />
          </span>
          <span
            v-if="reducedPercent"
            class="badge-discount"
          >
            {{ reducedPercent }}% off
          </span>
        </div>
      </div>
      <button
        class="compare-btn"
        :title="$t('product.compare')"
        @click.prevent="addToCompare"
      >
        <i class="icon reload-icon" />
      </button>
    </div>
  </router-link>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import compareHelper from '~/mixin/compareHelper'
  import LazyImage from "./LazyImage";
  import PriceFormat from "./PriceFormat";


  export default {
    name: 'SearchedProductTile',
    components: {PriceFormat, LazyImage},
    directives: {},
    props: {
      product: {
        type: Object,
        default() {
          return null
        },
      },

    },
    mixins: [util, productPriceHelper, compareHelper],
    watch: {

    },
    computed: {
      ...mapGetters('common', ['currencyIcon', 'setting']),
    },

    data() {
      return {
      }
    },
    methods: {
      ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError'])
    },
    async mounted() {

    },
    destroyed() {
    }
  }
</script>
<style scoped>
.product-card {
  background: #fff;
  border-radius: 10px;
  padding: 12px;
  display: flex;
  align-items: center;
  gap: 12px;
  transition: 0.3s;
  flex-direction: column
}

.product-card:hover {
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  transform: translateY(-3px);
}

.product-card img {
  width: 70px;
  height: 70px;
  object-fit: contain;
}

.product-title{
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
    color: #232159;
}

.price{
    font-size: 14px;
    font-weight: 600;
    color: #130E2B;
}

.old-price {
  text-decoration: line-through;
  color: #75748F;
  font-size: 16px;
  margin-left: 5px;
  font-weight: 500;
}

.badge-discount{
    border: 1px solid #333199;
    border-radius: 50px;
    color: #333199;
    font-size: 16px;
    margin-left: 12px;
    padding: 2px 12px;
    font-weight: 600;
}

.section-title {
  font-weight: 600;
}

.show-all {
  font-size: 12px;
  text-decoration: none;
  color: #333;
}

.old-price-discount{
  text-decoration: line-through !important;
}

</style>
