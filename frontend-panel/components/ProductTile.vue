<template>
  <div class="p-tile">
    <div
      class="card-wrapper"
      :class="{
        'collection-sale':
          showCollection && product?.collection_background_image
      }"
    >
      <nuxt-link :title="product.title" :to="productLink(product)" class="page-link p-tile-img-link">
        <span class="block img-wrapper" :style="tileBackgroundStyle">
          <lazy-image v-if="isLazyImage" :data-src="thumbImageURL(product)" :title="product.title"
            :alt="product.title" />
          <img v-else :src="thumbImageURL(product)" :title="product.title" :alt="product.title" height="50" width="50" :class="wishListed ? 'fas fa-heart' : 'far fa-heart'">
          <button class="wishlist-icon" :class="{ active: wishListed }" @click.prevent.stop="wishListAction">
           <svg width="16" height="15" viewBox="0 0 16 15" xmlns="http://www.w3.org/2000/svg">
                <path
                  d="M11.75 0.5C13.8327 0.5 15.5 2.31212 15.5 4.89551C15.5 6.25833 14.9911 7.42237 14.0352 8.64746C13.0664 9.88885 11.6708 11.1544 9.93457 12.7256C9.35164 13.2531 8.68906 13.8524 8 14.4922C7.31091 13.8524 6.64808 13.2529 6.06543 12.7256C4.32911 11.1543 2.93363 9.88886 1.96484 8.64746C1.00884 7.42234 0.500033 6.25831 0.5 4.89551C0.5 2.31212 2.16729 0.5 4.25 0.5C4.99688 0.5 5.68222 0.748469 6.29883 1.25488C6.89903 1.7479 7.30898 2.38648 7.55566 2.86523L8 3.72754L8.44434 2.86523C8.69103 2.3865 9.10098 1.7479 9.70117 1.25488C10.3178 0.748469 11.0031 0.5 11.75 0.5Z"
                  :fill="wishListed ? '#ff3b30' : 'none'"
                  :stroke="wishListed ? '#ff3b30' : '#130E2B'"
                  stroke-width="1.5"
                />
            </svg>
          </button>
        </span>
      </nuxt-link>
      <div class="product-tile-content">
        <div class="item-title">
          <h5 class="ellipsis" :class="`ellipsis-${titleEllipsis}`">
            {{ product.title }}
          </h5>
           <span class="block tile-rating-div">
              <rating-star :rating="productRating" />
            <span class="f-10 color-lite">
              ({{ $t('productReview.reviews', { count: productReviewCount }) }})</span>
             </span>
 
              <div class="ptile-price-and-reducedpercent">
               <h4 class="price-wrapper">
                <span class="price">
                    <!-- variants disagree on price, so the headline is the
                         cheapest one a customer can actually buy. A line
                         already in the basket has a settled quantity, so its
                         price is exact rather than a "from". -->
                    <span class="price-from" v-if="isVariablePrice && !cartLine">{{ $t('from') }}</span>
                    <price-format :price="livePrice" />
                </span>
                <span class="strike-through" v-if="livePrevPrice">
                    <price-format :price="livePrevPrice" />
                </span>
               </h4>
               <!-- The same price the other way round, so nobody has to work
                    it out. Server-split; the tile never divides by a rate. -->
               <p class="price-vat-alt" v-if="liveVatCounterpart">
                 {{ liveVatCounterpart.label }}
                 <price-format :price="liveVatCounterpart.amount" />
                 <span
                   v-if="liveVatCounterpart.vat !== null"
                   class="price-vat-amount"
                 >({{ $t('ui.productTileUi.vatRate', { rate: formatVatRate(liveVatCounterpart.rate) }) }}
                   <price-format :price="liveVatCounterpart.vat" />)</span>
               </p>
               <!--
                 The quantity is being repriced. Said out loud because the
                 figure above still belongs to the previous quantity until the
                 server answers, and on a quantity band that is a different
                 number rather than a slightly stale one.
               -->
               <p class="price-updating" v-if="ajaxingCart && cartLine">
                 {{ $t('productTile.updatingPrice') }}
               </p>
               <!--
                 The step was refused. The price and the quantity on screen
                 still agree with each other - the basket never moved - so this
                 offers the step again rather than implying the figure is wrong.
               -->
               <p class="price-error" v-else-if="priceError">
                 {{ $t('productTile.priceFailed') }}
                 <button
                   type="button"
                   class="price-retry"
                   :disabled="ajaxingCart"
                   @click.stop.prevent="retryCartQty"
                 >{{ $t('productTile.retry') }}</button>
               </p>
               <!-- once it is in the basket the button gives way to a
                    stepper, so the quantity can be changed without leaving
                    the listing -->
               <div v-if="cartQuantity > 0" class="tile-qty">
                 <button
                   type="button"
                   class="tile-qty-btn"
                   :disabled="ajaxingCart"
                   :aria-label="$t('detailRight.quantity')"
                   @click.stop.prevent="decreaseCartQty"
                 >&minus;</button>
                 <span class="tile-qty-value">{{ cartQuantity }}</span>
                 <button
                   type="button"
                   class="tile-qty-btn"
                   :disabled="ajaxingCart || !canAddMore"
                   :aria-label="$t('detailRight.quantity')"
                   @click.stop.prevent="increaseCartQty"
                 >+</button>
               </div>
               <div v-else class="add-to-cart-btn">
            <button :disabled="ajaxingCart || (!isBackOrder && productInventory.quantity === 0)"
              @click.stop.prevent="addProductToCart"
              :class="{ 'out-of-stock': !isBackOrder && productInventory.quantity === 0 }">
              <span v-if="!isBackOrder && productInventory.quantity === 0">
                {{ $t('ui.productTileUi.outOfStock') }}
              </span>
              <span v-else>
                {{ ajaxingCart ? $t('ui.productTileUi.adding') : $t('ui.productTileUi.addToCart') }}
              </span>
            </button>
                </div>
                <span class="badge-stack">
                  <span v-if="liveReducedPercent" class="badge-discount badge-same">
                    {{ $t('ui.productTileUi.percentOff', { percent: liveReducedPercent }) }}
                  </span>
                  <span v-if="product.end_time" class="badge-flash-sale badge-same">
                    {{ $t("product.flash_sale") }}
                  </span>
                  <span v-else-if="product.clearance" class="badge-clearance badge-same">
                    {{ $t("product.clearance") }}
                  </span>
                  <span v-if="product.is_new" class="badge-new badge-same">
                    {{ $t('ui.productTileUi.new') }}
                  </span>
                </span>
             </div>
          </div>
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
    /*
     * The collection artwork and green frame. The home page rails never show
     * them (their payload has no collection_background_image); the product
     * page's recommendation rows do get the field, so they turn this off to
     * render the tile exactly as the home page does.
     */
    showCollection: {
      type: Boolean,
      default: true
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
      localWishlisted: false,
      // The last quantity step was refused, so the tile offers it again.
      priceError: false,
      lastQtyDirection: null
    }
  },
  components: {
    RatingStar,
    PriceFormat,
    LazyImage
  },
  mixins: [util, productHelper, productPriceHelper, compareHelper, cartHelper],
  computed: {
    tileBackgroundStyle() {

      // off where the rail's own frame stands in for the collection (product page)
      const bg = this.showCollection
        ? this.product?.collection_background_image
        : null

      if (!bg) {
        return {}
      }

      // The collection artwork belongs to the image panel only — it must not
      // bleed behind the title, price and buttons.
      return {
        backgroundImage: `url(${this.getImageURL(bg)})`,
        backgroundSize: 'cover',
        backgroundPosition: 'center',
        backgroundRepeat: 'no-repeat'
      }
    },
    productRating() {
      return parseFloat(
        this.product?.rating ||
        this.product?.product?.rating ||
        0
      )
    },
    productReviewCount() {
      return (
        this.product?.review_count ||
        this.product?.product?.review_count ||
        0
      )
    },
    isBackOrder() {
      return this.productInventory?.is_active == 1
    },
    // The basket row for this tile's inventory. Rows loaded from the API
    // carry `updated_inventory`; rows added locally by the cart store carry
    // `inventory`, so both shapes have to be matched.
    cartEntry() {
      const inventoryId = this.productInventory?.id

      if (!inventoryId) return null

      return (this.cartProducts || []).find(item => {
        const id = item?.updated_inventory?.id || item?.inventory?.id
        return Number(id) === Number(inventoryId)
      }) || null
    },
    cartQuantity() {
      return parseInt(this.cartEntry?.quantity || 0)
    },
    canAddMore() {
      if (this.isBackOrder) return true
      return this.cartQuantity < Number(this.productInventory?.quantity || 0)
    },
    productInventory() {
      let inventories = []
      if (this.product?.product_inventories) {
        inventories = this.product.product_inventories
      } else if (this.product?.product?.product_inventories) {
        inventories = this.product.product.product_inventories
      }
      if (!inventories?.length) return { quantity: 0 }
      return [...inventories].sort((a, b) => b.quantity - a.quantity)[0]
    },
    badge() {
      return this.product?.badge
    },
    wishListed() {
      return this.localWishlisted
    },
    ...mapGetters('common', ['currencyIcon', 'setting']),
    ...mapGetters('cart', ['cartProducts'])
  },
  mounted() {
    this.localWishlisted =
      !!this.product?.is_wishlisted || !!this.product?.product?.is_wishlisted
  },
  methods: {
    async wishListAction() {
      try {
        // No sign-in gate: a guest wishlists against the token the server
        // issued it, the same identity its basket already uses.
        this.localWishlisted = !this.localWishlisted

        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const productId = this.product.id;
        const payload = {
          product_id: productId,
          user_token: this.$auth.loggedIn ? null : await this.getUserToken()
        }
        const response = await this.$axios.post(`${baseUrl}api/v1/user/wishlist/action`, payload);

        if (response?.data?.status !== 200) {
          // The write was refused, so put the heart back where it was.
          this.localWishlisted = !this.localWishlisted
          this.setToastError(response?.data?.message || response?.data?.data?.form?.join(', '))
          return
        }

        this.setToastMessage(response?.data?.message);

      } catch (e) {
        this.localWishlisted =
          !this.localWishlisted
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
    },
    /* Re-run the step that failed, with the quantity still where it was. */
    retryCartQty() {
      if (this.lastQtyDirection === null) return

      return this.changeCartQty(this.lastQtyDirection)
    },
    // the cart endpoint takes a delta, the same way CartList does it
    async changeCartQty(direction) {
      if (this.ajaxingCart || !this.cartEntry) return
      this.ajaxingCart = true
      this.lastQtyDirection = direction
      /*
       * Clear the previous failure before trying again, so a retry that works
       * does not leave the warning on screen.
       */
      this.priceError = false
      try {
        await this.cartAction({
          payload: {
            apiVal: {
              user_token: await this.getUserToken(),
              product_id: this.product.id,
              inventory_id: this.productInventory.id,
              quantity: direction
            },
            storeVal: {
              product: this.product,
              inventory: this.productInventory,
              quantity: direction,
              selected: '1'
            },
            isBundle: !!this.product?.bundle_deal
          },
          lang: this.langCode
        })
      } catch (e) {
        /*
         * The quantity is whatever the basket still says, so the price on
         * screen and the quantity beside it still agree — but the step the
         * customer asked for did not happen, and saying so beats a toast that
         * has already faded by the time they look back at the tile.
         */
        this.priceError = true
        this.setToastError(e.message)
      }
      this.ajaxingCart = false
    },
    increaseCartQty() {
      if (!this.canAddMore) return
      return this.changeCartQty(1)
    },
    // the last one leaves the basket rather than sitting there at zero
    async decreaseCartQty() {
      if (this.ajaxingCart || !this.cartEntry) return

      if (this.cartQuantity > 1) return this.changeCartQty(-1)

      this.ajaxingCart = true
      try {
        await this.cartDelete({
          payload: {
            id: this.cartEntry.id,
            user_token: await this.getUserToken()
          },
          lang: this.langCode
        })
      } catch (e) {
        this.setToastError(e.message)
      }
      this.ajaxingCart = false
    },
    ...mapActions('cart', ['cartDelete']),
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

/* one positioned stack, badges flow inside it - no hardcoded offsets to
   collide with each other or with the wishlist button */
.badge-stack {
    align-items: center;
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    gap: 5px;
    left: 8px;
    max-width: calc(100% - 48px);
    position: absolute;
    top: 10px;
    z-index: 5;
}

.badge-same {
    background: #D7FF71;
    border: 1px solid #333199;
    border-radius: 50px;
    color: #333199;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
    margin: 0;
    max-width: 100%;
    overflow: hidden;
    padding: 2px 9px;
    position: static;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.card-wrapper {
  border: 1px solid #E3E3EF;
  border-radius: 20px;
  flex: 1;
  display: flex;
  flex-direction: column; 
  justify-content: space-between;
  transition: 
    border 0.35s ease,
    transform 0.35s ease,
    box-shadow 0.35s ease;
  
}
.card-wrapper:hover{
  border: 4px solid #E3E3EF;
   box-shadow: 0 0 50px 0 rgba(0, 0, 0, 0.08);  
}
a.page-link {
  text-decoration: none;
  color: inherit;
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
  top: -10px;
  right: -10px;
  z-index: 5;
  background: #F0F3FD;
  border-radius: 50%;
  border: 1px solid #F0F3FD;
  width: 35px;
  height: 35px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all .25s ease;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.wishlist-icon:hover {
  transform: scale(1.08);
}

.wishlist-icon i {
  font-size: 16px;
  color: #999;
}

.wishlist-icon.active {
  background: #fff1f0;
  border-color: #ffb3ad;
}

.wishlist-icon i.fas {
  color: #ff3b30;
  /* filled heart */
}

.item-title {
  margin-top: 10px;
}

.ptile-price-and-reducedpercent {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
}

.price-wrapper span {
    font-size: 18px;
}

/* "From" sits with the price but must not compete with it */
.price-wrapper span.price-from {
    font-size: 12px;
    font-weight: 500;
    text-transform: lowercase;
    opacity: .65;
    margin-right: 3px;
}

.price-wrapper {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.add-to-cart-btn {
    display: inline;
    font-size: 14px;
    font-weight: 500;
    padding: 6px 18px;
    color: #333199;
    border: 1px solid #333199;
    font-family: 'Inter';
    border-radius: 50px;

}

/* Basket stepper, shown in place of the button once the product is in the
   cart. It sits beside .add-to-cart-btn rather than inside it, so the
   full-width green pill rules that target `.add-to-cart-btn button` on the
   home and product screens do not claim the + and - controls. */
.tile-qty {
    align-items: center;
    border: 1px solid #333199;
    border-radius: 50px;
    display: flex;
    justify-content: space-between;
    overflow: hidden;
}

.tile-qty-btn {
    background: none;
    border: 0;
    color: #333199;
    flex: 0 0 auto;
    font-family: 'Inter';
    font-size: 16px;
    height: auto;
    line-height: 1;
    padding: 8px 16px;
}

.tile-qty-btn:hover:not(:disabled) {
    background: #F1F1FC;
}

.tile-qty-btn:disabled {
    cursor: not-allowed;
    opacity: .35;
}

.tile-qty-value {
    color: #333199;
    flex: 1;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
}
.add-to-cart-btn:hover{
  background:#333199;
   color: #fff;
}
.block.tile-rating-div {
    margin-top: 10px;
}
.add-to-cart-btn button{
  height: auto !important;
  line-height: 0;
}
.product-tile-content {
    padding: 20px;
    border-top: 1px solid #E3E2F1;
}
.p-tile-img-link {
    padding: 20px;
}
.block.tile-rating-div .color-lite {
    font-size: 14px;
    color: #232159;
}

.collection-sale .product-tile-content {
    background: #F5FFF2;
}

/* Product shots are studio photos on a white ground. Sat on top of the
   collection artwork that white ground reads as a stray box around the
   product, so multiply it away: white blends to nothing and the artwork shows
   through, while the product itself stays opaque.
   The veil lightens the artwork just behind the photo so pale products don't
   pick up a colour cast from the multiply — raise/lower the alpha to trade
   artwork punch against how true the product colours stay. */
.collection-sale .img-wrapper {
    isolation: isolate;
}

/* stack inside the panel: artwork (background) -> veil (0) -> photo (1) -> heart (5) */
.collection-sale .img-wrapper::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 0;
    background: rgba(255, 255, 255, .45);
    pointer-events: none;
}

.collection-sale .img-wrapper img {
    z-index: 1;
    mix-blend-mode: multiply;
}
.collection-sale.card-wrapper {
    border: 1px solid #05B942;
    border-radius: 20px;
    display: flex;
    flex: 1;
    flex-direction: column;
    justify-content: space-between;
    transition: border .35s ease, transform .35s ease, box-shadow .35s ease;
    overflow: hidden;
}
.collection-sale.card-wrapper:hover {
    border: 4px solid #05B942;
    box-shadow: 0 0 50px 0 rgba(0, 0, 0, .08);
}
</style>
