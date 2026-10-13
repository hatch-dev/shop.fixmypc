<template>
  <router-link
    :to="productLink(product)"
    class="text-decoration-none sp-tile"
  >
    <span class="sp-tile-media">
      <lazy-image
        :data-src="thumbImageURL(product)"
        :title="product.title"
        :alt="product.title"
      />
    </span>
    <span class="sp-tile-info">
      <span class="sp-tile-title">
        {{ product.title }}
      </span>
      <span class="sp-tile-pricing">
        <span class="sp-tile-price">
          <price-format :price="currentPricing" />
        </span>
        <span
          class="sp-tile-old"
          v-if="hasDiscount"
        >
          <price-format :price="prevPrice" />
        </span>
        <span
          v-if="hasDiscount"
          class="sp-tile-badge"
        >
          -{{ reducedPercent }}%
        </span>
      </span>
    </span>
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
    name: 'SearchedPopupProductTile',
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
      hasDiscount() {
        return this.prevPrice > 0
          && this.prevPrice > this.currentPricing
          && this.reducedPercent > 0
      },
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
/* Scoped to this component only - no global stylesheet is modified. */
.sp-tile {
  align-items: center;
  background: #fff;
  border: 1px solid #ECECF6;
  border-radius: 12px;
  display: flex;
  gap: 12px;
  padding: 10px;
  text-decoration: none;
  transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}

.sp-tile:hover {
  border-color: #C9C8E8;
  box-shadow: 0 6px 16px rgba(19, 14, 43, .1);
  transform: translateY(-2px);
}

.sp-tile:focus-visible {
  border-color: #C9C8E8;
  box-shadow: 0 6px 16px rgba(19, 14, 43, .1);
  transform: translateY(-2px);
}

.sp-tile-media {
  align-items: center;
  background: #F7F7FC;
  border-radius: 10px;
  display: flex;
  flex-shrink: 0;
  height: 58px;
  justify-content: center;
  overflow: hidden;
  width: 58px;
}

.sp-tile-media >>> img {
  height: 46px;
  object-fit: contain;
  width: 46px;
}

.sp-tile-info {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
}

.sp-tile-title {
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  color: #232159;
  display: -webkit-box;
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.35;
  overflow: hidden;
  word-break: break-word;
}

.sp-tile:hover .sp-tile-title {
  color: #333199;
}

.sp-tile-pricing {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.sp-tile-price {
  color: #130E2B;
  font-size: 13.5px;
  font-weight: 700;
}

.sp-tile-old {
  color: #8A89A6;
  font-size: 12px;
  font-weight: 500;
  text-decoration: line-through;
}

.sp-tile-badge {
  background: #EEF0FF;
  border-radius: 20px;
  color: #333199;
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
  padding: 3px 7px;
}

@media only screen and (max-width: 768px) {
  .sp-tile-media {
    height: 50px;
    width: 50px;
  }

  .sp-tile-media >>> img {
    height: 40px;
    width: 40px;
  }
}
</style>
