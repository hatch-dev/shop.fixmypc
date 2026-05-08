<template>
  <div class="p-tile">
    <div class="card-wrapper">
      <nuxt-link
        :title="product.title"
        :to="productLink(product)"
        class="page-link"
      >
        <span
          class="block img-wrapper"
        >
        <span
          v-if="product.end_time"
          class="badge"
          >
            {{ $t("product.flash_sale") }}
          </span>
        <!--- <span
            v-if="badge"
            class="badge"
          >
            {{ badge }}
          </span>--->
        

          <!-- <slot name="floating-btn">
            <button
              aria-label="submit"
              class="compare-btn"
              :title="$t('product.compare')"
              @click.prevent="addToCompare"
            >
              <i class="icon reload-icon"/>
            </button>
          </slot> -->


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
          <button
            class="wishlist-icon"
            @click.prevent.stop="wishListAction"
          >
            <i :class="wishListed ? 'fas fa-heart' : 'far fa-heart'"></i>
          </button>
        </span>

        <div class="item-title">
          <h5
            class="ellipsis"
            :class="`ellipsis-${titleEllipsis}`"
          >
            {{product.title}}
          </h5>
          <span class="block mtb-5">
            <rating-star
              :rating="parseFloat(product.rating)"
            />
            <span class="f-10 ml-5 semi-bold color-lite">
              ({{ $t('productReview.reviews', {count: product.review_count}) }})</span>
          </span>
          <span class="flex wrap start">
            <h4 class="price-wrapper">
              <span
                class="strike-through"
                v-if="prevPrice"
              >
                <price-format
                  :price="prevPrice"
                />
              </span>
              <span class="price">
                <price-format
                  :price="currentPricing"
                />
              </span>
            </h4>
            <span
              v-if="reducedPercent"
              class="badge-discount"
            >
              {{ reducedPercent }}% off
            </span>
          </span>
        </div>
      </nuxt-link>
      <div class="add-to-cart-btn">
        <button
          :disabled="ajaxingCart || (!isBackOrder && productInventory.quantity === 0)"
          @click.stop.prevent="addProductToCart"
          :class="{ 'out-of-stock': !isBackOrder && productInventory.quantity === 0 }"
        >
          <span v-if="!isBackOrder && productInventory.quantity === 0">
            Out of Stock
          </span>
          <span v-else>
            {{ ajaxingCart ? 'Adding...' : 'Add to cart' }}
          </span>
        </button>
      </div>
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
  import RatingStar from "./RatingStar";
  import cartHelper from '~/mixin/cartHelper'

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
        ajaxingCompare: false,
        ajaxingCart: false,
        quantity: 1,
      }
    },
    components: {
      RatingStar,
      PriceFormat,
      LazyImage
    },
    mixins: [util, productHelper, productPriceHelper, compareHelper, cartHelper],
    computed: {
      isBackOrder() {
        return this.productInventory?.is_active == 1
      },
      productInventory() {
        let inventories = []
        if(this.product?.product_inventories){
          inventories = this.product.product_inventories
        }else if (this.product?.product?.product_inventories) {
          inventories = this.product.product.product_inventories
        }
        if (!inventories?.length) return {quantity: 0}
        return [...inventories].sort((a, b) => b.quantity - a.quantity)[0]
      },
      badge(){
        return this.product?.badge
      },
      wishListed() {
        return process.client
          ? this.product?.is_wishlisted || false
          : false
      },
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    mounted() {
    },
    methods: {
      async wishListAction() {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          const productId = this.product.id;
          const payload = {
            product_id: productId,
          }
          const response=await this.$axios.post(`${baseUrl}api/v1/user/wishlist/action`, payload);

          this.product.is_wishlisted = !this.product.is_wishlisted

        } catch (e) {
          this.setToastError(e.message)
        }
      },
      ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError']),
      async addProductToCart() {
        this.ajaxingCart = true
        try {
          await this.addToCart();
        } catch (e) {
          this.setToastError(e.message)
        }
        this.ajaxingCart = false
      }
    },
  };
</script>
<style scoped>
h5.ellipsis.ellipsis-2 {
    color: #232159;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.3em;
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

span.rating-stars {
  width: 60px;
  height: 18px;
}

.card-wrapper {
  border: 1px solid #E3E3EF;
  border-radius: 20px;
  padding: 20px;
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

a.page-link {
  text-decoration: none;
  color: inherit;
}

.add-to-cart-btn {
  margin-top: 15px;
}

.add-to-cart-btn button {
  width: 100%;
  border: 1px solid #333199;
  border-radius: 50px;
  font-weight: 700;
  font-size: 16px;
  color: #333199;
  background: transparent;
}

.add-to-cart-btn button:hover {
  background-color: #33319A;
  color: #fff;
}

.img-wrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 180px;
  position: relative;
}

.wishlist-icon {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 5;
  background: #fff;
  border-radius: 50%;
  border: 1px solid #E3E3EF;
  width: 35px;
  height: 35px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.wishlist-icon i {
  font-size: 16px;
  color: #999;
}

.wishlist-icon i.fas {
  color: #ff3b30; /* filled heart */
}

.item-title {
  margin-top: 10px;
}

.price-wrapper {
  display: flex;
  align-items: center;
  gap: 10px;
}

.add-to-cart-btn button.out-of-stock {
  background-color: #ccc;
  color: #666;
  border-color: #ccc;
  cursor: not-allowed;
}
 
.add-to-cart-btn button.out-of-stock:hover {
  background-color: #ccc;
  color: #666;
}
</style>

