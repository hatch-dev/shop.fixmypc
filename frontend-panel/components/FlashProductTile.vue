<template>
  <div class="p-tile">
    <nuxt-link
      :to="productLink(product)"
      class="block page-link"
      :title="product.title"
    >
      <div class="img-wrapper">
        <span
          v-if="product.badge"
          class="badge"
        >
          {{ product.badge }}
        </span>


        <button
          :aria-label="$t('ui.flashProductTileUi.aria.submit')"
          class="compare-btn"
          :title="$t('product.compare')"
          @click.prevent="addToCompare"
        >
          <i class="icon reload-icon"/>
        </button>

        <lazy-image
          :data-src="imageURL(product)"
          :title="product.title"
          :alt="product.title"
        />
      </div>
      <div class="item-title">
        <h5 class="ellipsis ellipsis-2">
          {{ product.title }}
        </h5>
        </div>
        
        <!-- Rating and Reviews Section -->
        <span class="block mtb-5">
          <span :title="`${product.rating || 0} out of 5`" class="rating-stars">
            <span>☆☆☆☆☆</span>
            <span class="rating" :style="`width: ${ratingPercentage}%;`">★★★★★</span>
          </span>
          <span class="f-10 ml-5 semi-bold color-lite">
            {{ reviewCountText }}
          </span>
        </span>


      <div class="flex wrap sided align-end item-title mt-0">
        <h4 class="price-wrapper">
          <span class="price">
            <price-format
              :price="livePrice"
            />
          </span>
          <!--
            Only when there is genuinely something to strike through. This
            used to render unconditionally, so a sale with no compare price
            printed a struck-through 0.00 beside the real one.
          -->
          <span class="strike-through" v-if="livePrevPrice">
            <price-format
              :price="livePrevPrice"
            />
          </span>
        </h4>
        <!-- Same guard: 0 / 0 rendered the badge as "NaN% off". -->
        <h5
          class="color-primary"
          v-if="liveReducedPercent"
        >
          <span class="discount">
            {{ $t('home.off', {percent: liveReducedPercent}) }}
          </span>
        </h5>

        <!-- The gross figure beside a net headline, as on every tile. -->
        <p class="price-vat-alt" v-if="liveVatCounterpart">
          {{ liveVatCounterpart.label }}
          <price-format :price="liveVatCounterpart.amount" />
        </p>

      </div>

    </nuxt-link>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import {mapGetters, mapActions} from 'vuex'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import compareHelper from '~/mixin/compareHelper'
  import PriceFormat from "./PriceFormat";

  export default {
    name: 'FlashProductTile',
    data() {
      return {}
    },
    watch: {},
    props: {
      product: {
        type: Object,
        default() {
          return null
        },
      }
    },
    components: {
      PriceFormat,
      LazyImage
    },
    mixins: [util, productPriceHelper, compareHelper],
    computed: {
      /*
       * The price, the compare price and the badge all come from
       * productPriceHelper, which reads the figures the server decided --
       * exactly as ProductTile does.
       *
       * This tile used to render product.price, the raw flash price, and
       * work the badge out by dividing it by the compare price. So a
       * business or approved-trade account whose band took the price below
       * the flash price was shown the walk-up figure on the flash sale page
       * and its own rate everywhere else, and a sale with no compare price
       * divided by zero and rendered "NaN% off".
       *
       * quantity/sold/remainingQtyStyle went with them: nothing in the
       * flash sale payload has ever carried a per-product stock allowance,
       * so they read undefined and rendered nothing.
       */
      // Calculate rating percentage for star display
      ratingPercentage() {
        const rating = this.product?.rating || 0;
        return (rating / 5) * 100;
      },
      reviewCountText() {
        const reviewCount = this.product?.review_count || this.product?.reviews_count || 0;
        return `${reviewCount} ${reviewCount === 1 ? this.$t('product.review') : this.$t('product.reviews')}`;
      },
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    methods: {
      ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError'])
    },
    created() {
    },
    mounted() {
    }
  }
</script>
<style>
.rating-stars {
  position: relative;
  display: inline-block;
  font-size: 14px;
  letter-spacing: 1px;
}

.rating-stars > span:first-child {
  color: #ddd;
}

.rating-stars .rating {
  position: absolute;
  top: 0;
  left: 0;
  white-space: nowrap;
  overflow: hidden;
  color: #ffc107;
}
</style>