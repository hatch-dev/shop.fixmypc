<template>
  <div class="p-tile">
      <span
        class="block img-wrapper"
      >
       <span
        v-if="product.end_time"
        class="badge"
        >
          {{ $t("product.flash_sale") }}
        </span>

        <lazy-image
          v-if="isLazyImage"
          :data-src="thumbImageURL(product)"
          :title="product.title"
          :alt="product.title"
        />
        <img
          v-else
          :src="thumbImageURL(product)"
          :title="product.title"
          :alt="product.title"
          height="50"
          width="50"
        >
      </span>

      <div class="item-title">
        <h5
          class="ellipsis"
          :class="`ellipsis-${titleEllipsis}`"
        >
          {{product.title}}
        </h5>
         <span class="flex wrap start">
          <h4 class="price-wrapper">
            <span class="price">
              <price-format
                :price="currentPricing"
              />
            </span>
          </h4>
        </span>
      </div>
  </div>
</template>

<script>
  import LazyImage from '~/components/LazyImage'
  import util from '~/mixin/util'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import productHelper from '~/mixin/productHelper'
  import compareHelper from '~/mixin/compareHelper'
  import { mapGetters, mapActions } from 'vuex'
  import PriceFormat from "./PriceFormat";

  export default {
    name: 'ProductTile',
    props: {
      product: {
        type: Object,
        default() {
          return null
        },
      },
      isLazyImage: {
        type: Boolean,
        default: true
      },
      compared: {
        type: Boolean,
        default: false
      },
      titleEllipsis: {
        type: Number,
        default: 2
      },
    },
    data() {
      return {
        ajaxingCompare: false
      }
    },
    components: {
      PriceFormat,
      LazyImage
    },
    mixins: [util, productHelper, productPriceHelper, compareHelper],
    computed: {
      badge(){
        return this.product?.badge
      },
      currentPricing() {
        const selling = Number(this.product?.selling || 0)
        const offered = Number(this.product?.offered || 0)

        return offered > 0 ? offered : selling
      },
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    mounted() {
    },
    methods: {

      ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError']),
    },
  };
</script>

