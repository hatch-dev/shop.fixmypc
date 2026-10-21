<template>
  <div class="checkout-tile" v-if="product">
    <nuxt-link
      class="tile-media"
      :to="productLink(product)"
      :title="title"
    >
      <lazy-image
        :data-src="getThumbImageURL(productImage)"
        :title="title"
        :alt="title"
      />
    </nuxt-link>

    <div class="tile-body">
      <div class="tile-info">
        <h5 class="tile-title">
          <nuxt-link
            :to="productLink(product)"
            :title="title"
          >
            {{ title }}
          </nuxt-link>
        </h5>

        <ul class="tile-meta">
          <li>
            <span class="meta-label">{{ $t('ui.checkoutProductTile.qty') }}</span>
            <span class="meta-value">{{ productQuantity }}</span>
          </li>
          <li v-for="(i, index) in currentAttr" :key="`attr-${index}`">
            <span class="meta-label">{{ i[0] }}</span>
            <span class="meta-value">{{ i[1] }}</span>
          </li>
          <li v-if="hasBundleDeal">
            <span class="meta-label">{{ $t('cartProductTile.bundleOffer') }}</span>
            <span class="meta-value">{{ bundleDeal.title }}</span>
          </li>
        </ul>

        <div class="tile-tags" v-if="hasUpsellPrice || hasUpsellDiscount || hasRegularDiscount">
          <span
            v-if="hasUpsellDiscount"
            class="tag tag-offer"
          >
            {{ $t('date.offer', {amount: upsellDiscountPercent }) }}
          </span>
          <span
            v-else-if="hasRegularDiscount"
            class="tag tag-offer"
          >
            {{ $t('date.offer', {amount: regularDiscountPercent }) }}
          </span>
          <span v-if="hasUpsellPrice" class="tag tag-upsell">
            {{ $t('cartProductTile.upsellOffer') }}
          </span>
        </div>

        <form
          class="tile-shipping"
          v-if="isShipping && currentAddresses.length && isSingleShipping"
        >
          <p v-if="!currentShipRule" class="error">{{ noShipMessage }}</p>
          <p v-else-if="error && error.length" class="error">
            <span class="block" v-for="e in error">{{ e }}</span>
          </p>
          <div v-else-if="cartShipping[cart.id]">
            <label class="mr-15 cp rd-container color-lite">

              <input
                class="mt-5 cp"
                type="radio"
                :value="shippingTypeIn.location"
                :name="`shipping_${cartId}_type`"
                v-model="cartShipping[cartId].shipping_type"
                @change="updateCartShipping"
              >
              <span class="rd-checkmark"></span>
              {{ $t('cartProductTile.fromLocation') }}
              (<span
                v-if="isFreeShipping" class="color-free">
                {{ $t('invent.fre') }}
              </span>
              <price-format
                v-else
                :price="currentShipRule.price"
              />)
            </label>
            <label
              v-if="parseInt(currentShipRule.pickup_point) === 1"
              class="mr-15 cp rd-container color-lite"
            >
              <input
                class="mt-5 cp"
                type="radio"
                :value="shippingTypeIn.pickup"
                :name="`shipping_${cartId}_type`"
                v-model="cartShipping[cartId].shipping_type"
                @change="updateCartShipping"
              >
              <span class="rd-checkmark"></span>
              {{ $t('cartProductTile.fromPickupPlace') }}
              (<span
                v-if="isFreePickup" class="color-free">
                {{ $t('invent.fre') }}
              </span>
              <price-format
                v-else
                :price="currentShipRule.pickup_price"
              />)
            </label>
          </div>
        </form>
      </div>

      <div class="tile-price">
        <span class="price-now">
          <price-format :price="displayPrice" :decimals="2" />
        </span>
        <!--
          Which convention this line is priced on, and its split behind the
          icon — the same badge and the same popover as the basket card, from
          the same mixin, so a customer moving from one screen to the other is
          reading one thing rather than two descriptions of it.

          It replaces the sentence that used to be printed here: "Inc-VAT
          €829.02 (VAT 23% €155.02)", restating in the line what the summary
          beside it already states for the basket.
        -->
        <span
          v-if="cartVatBasis"
          class="price-basis-badge"
          :class="cartVatBasis.inclusive ? 'is-inclusive' : 'is-exclusive'"
        >{{ cartVatBasis.short }}<info-popover
          v-if="vatExplainer"
          variant="badge"
          :title="vatExplainer.title"
          :label="vatExplainer.label"
          :lede="vatExplainer.lede"
          :rows="vatExplainer.rows"
          :note="vatExplainer.note"
        /></span>
        <span
          v-if="hasUpsellDiscount"
          class="price-was"
        >
          <price-format :price="originalPrice" :decimals="2" />
        </span>
        <span
          v-else-if="hasRegularDiscount"
          class="price-was"
        >
          <price-format :price="regularPrevPrice" :decimals="2" />
        </span>
        <span class="price-note" v-if="hasBundleDeal">(-) x {{ bundleDeal.free }}</span>
      </div>
    </div>
  </div>
</template>


<script>
  import {mapGetters, mapActions} from 'vuex'
  import LazyImage from '~/components/LazyImage'
  import util from '~/mixin/util'
  import QuantityNav from '~/components/QuantityNav'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import productImageHelper from '~/mixin/productImageHelper'
  import PriceFormat from "~/components/PriceFormat"
  import AjaxButton from "~/components/AjaxButton"
  import Dropdown from "./Dropdown";
  import InfoPopover from "~/components/InfoPopover"

  export default {
    name: 'CheckoutProductTile',
    data() {
      return {
        cbChecked: this.checked
      }
    },
    watch: {
      checked() {
        this.cbChecked = this.checked
      }
    },
    props: {
      checked: {
        type: Array
      },
      cart: {
        type: Object
      },
      isShipping: {
        type: Boolean,
        default: false
      },
      cartShipping: {
        type: Object,
        default() {
          return null
        }
      },
      error: {
        type: Array,
        default() {
          return []
        }
      },
      currentAddresses: {
        type: Array,
         default(){
          return []
        }
      },
      address: {
        type: Object,
        default() {
          return null
        }
      },
    },
    components: {
      InfoPopover,
      Dropdown,
      AjaxButton,
      PriceFormat,
      QuantityNav,
      LazyImage
    },
    computed: {
      // Check if this cart item has an upsell price
      hasUpsellPrice() {
        return this.cart?.upsell_price && parseFloat(this.cart.upsell_price) > 0;
      },
      
      // Get the upsell price if available
      upsellPrice() {
        return this.hasUpsellPrice ? parseFloat(this.cart.upsell_price) : null;
      },
      
      // Display price (uses upsell price if available, otherwise uses mixin's productPrice)
      displayPrice() {
        return this.hasUpsellPrice ? this.upsellPrice : this.$options.computed.productPrice.call(this);
      },
      
      // Get the original price for comparison (before upsell discount)
      originalPrice() {
        if (this.hasUpsellPrice) {
          // For upsell items, use the mixin's product price as original price
          return this.$options.computed.productPrice.call(this);
        }
        return this.$options.computed.prevPrice.call(this) || this.$options.computed.productPrice.call(this);
      },
      
      // Check if there's an upsell discount
      hasUpsellDiscount() {
        return this.hasUpsellPrice && this.upsellPrice < this.originalPrice;
      },
      
      // Calculate upsell discount percentage
      upsellDiscountPercent() {
        if (!this.hasUpsellDiscount) return 0;
        
        const discount = this.originalPrice - this.upsellPrice;
        const discountPercent = (discount / this.originalPrice) * 100;
        return Math.round(discountPercent);
      },
      
      // Check if there's a regular discount (for non-upsell items)
      hasRegularDiscount() {
        const prevPrice = this.$options.computed.prevPrice.call(this);
        const productPrice = this.$options.computed.productPrice.call(this);
        return prevPrice && productPrice && prevPrice > productPrice;
      },
      
      // Get regular previous price from mixin
      regularPrevPrice() {
        return this.$options.computed.prevPrice.call(this);
      },
      
      // Calculate regular discount percentage
      regularDiscountPercent() {
        if (!this.hasRegularDiscount) return 0;
        
        const prevPrice = this.$options.computed.prevPrice.call(this);
        const productPrice = this.$options.computed.productPrice.call(this);
        const discount = prevPrice - productPrice;
        const discountPercent = (discount / prevPrice) * 100;
        return Math.round(discountPercent);
      },
      
      isFreePickup() {
        return !(parseFloat(this.currentShipRule?.pickup_price) > 0)
      },
      isFreeShipping() {
        return !(parseFloat(this.currentShipRule?.price) > 0)
      },
      isSingleShipping() {
        return this.cartShipping[this.cart?.id]?.single_shipping
      },
      hasBundleDeal() {
        return (this.productQuantity >= this.bundleDeal?.buy)
      },
      bundleDeal() {
        return this.product?.bundle_deal
      },
      cartId() {
        return this.cart?.id
      },
      product() {
        return this.cart?.flash_product
      },
      productInventory() {
        return this.cart?.updated_inventory
      },
      currentShipRule() {
        let matched = null
        if (this.address) {
          this.product?.shipping_rule?.shipping_places.forEach((obj) => {
            if (obj.country === this.address.country) {
              if (obj.state === this.address.state) {
                matched = obj
                return
              } else if (obj.state === 'ALL') {

                matched = obj
              }
            } else if (obj.country === 'ALL') {
              if (!matched) {
                matched = obj
              }
            }
          })
        }

        if(matched && !matched?.shipping_rule){
          matched = {...matched, ...{shipping_rule: this.product?.shipping_rule}}
        }

        if (matched && this.cartShipping[this.cart?.id]) {
          this.cartShipping[this.cart?.id].shipping_place = matched
          this.updateCartShipping()
        }

        this.$emit('current-shipping', {cart: this.cart?.id, shipping: matched})
        return matched
      },
      productImages(){
        this.product?.product_images
      },
      currentImage(){
        if(this.inventoryAttributes?.length && this.inventoryAttributes[0]?.attribute_value) {
          const item = this.cart?.product_images?.find(i => {
            return i.attribute_value_id === this.inventoryAttributes[0]?.attribute_value.id
          })
          return item?.image?.image ?? this.product?.image
        }
        return this.product?.image
      },
      inventoryAttributes() {

        return this.productInventory?.inventory_attributes
      },
      currentAttr() {
        return this.inventoryAttributes?.map(i => {
          return [i?.attribute_value?.attribute?.title, i?.attribute_value?.title]
        })
      },
      title() {
        return this.product?.title || ''
      },
      maxQuantity() {
        return parseInt(this.productInventory?.quantity)
      },
      productQuantity() {
        return parseInt(this.cart?.quantity)
      },
      noShipMessage() {
        const state = this.address?.stateTitle ? `${this.address?.stateTitle},` : ''
        return this.$t('cartProductTile.noShipMessage', {state: state, country: this.address?.countryTitle})
      },

      ...mapGetters('common', ['currencyIcon', 'setting', 'currencyPosition']),
    },
    mixins: [util, productPriceHelper, productImageHelper],
    methods: {

      updateCartShipping() {
        this.$emit('shipping-changed', this.cartShipping)
      },
      async deleting() {
        if (confirm(this.$t('cartProductTile.deleteAlert'))) {
          this.$emit('deleting', {id: this.cartId, isBundle: !!this.bundleDeal, user_token: await this.getUserToken()})
        }
      },
      valueChanged(evt) {
        this.$emit('quantity', {
            bundleDeal: this.bundleDeal,
            product: this.product,
            inventory: this.productInventory,
            direction: evt.direction
          }
        )
      },
      ...mapActions('user', ['getUserToken']),
    },
    created() {
    },
    mounted() {
    }
  }
</script>

<style scoped>
.checkout-tile {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 18px 0;
  border-bottom: 1px solid #EDEDF3;
}

.checkout-tile:last-child {
  border-bottom: none;
  padding-bottom: 4px;
}

/* Thumbnail keeps a fixed box so a tall and a wide image line up the same */
.tile-media {
  flex: 0 0 88px;
  width: 88px;
  height: 88px;
  border: 1px solid #ECECF2;
  border-radius: 10px;
  background: #F7F7FA;
  padding: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.tile-media >>> img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  mix-blend-mode: multiply;
}

.tile-body {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex: 1 1 auto;
  min-width: 0;
}

.tile-info {
  min-width: 0;
  flex: 1 1 auto;
}

.tile-title {
  margin: 0 0 8px;
  font-size: 16px;
  line-height: 1.35em;
  font-weight: 600;
  color: #130E2B;
}

.tile-title a {
  color: inherit;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.tile-title a:hover {
  color: #33319A;
}

.tile-meta {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  font-size: 13px;
  line-height: 1.4em;
  color: #737987;
}

.tile-meta .meta-label::after {
  content: ':';
  margin-right: 4px;
}

.tile-meta .meta-value {
  color: #130E2B;
  font-weight: 600;
}

.tile-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.tag {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .2px;
  line-height: 1.5em;
  white-space: nowrap;
}

.tag-offer {
  background: #FFECEC;
  color: #D83A3A;
}

.tag-upsell {
  background: #EEEEFB;
  color: #33319A;
}

.tile-shipping {
  margin-top: 12px;
}

.tile-price {
  flex: 0 0 auto;
  text-align: right;
  white-space: nowrap;
}

.price-now {
  display: block;
  font-size: 18px;
  line-height: 1.3em;
  font-weight: 700;
  color: #130E2B;
}

.price-was {
  display: block;
  margin-top: 2px;
  font-size: 13px;
  color: #9A9AAA;
  text-decoration: line-through;
}

.price-note {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: #737987;
}

/* The pricing-basis badge, in the same two quiet tints as the basket card:
   this is a fact about the price, not a warning about it. Right-aligned with
   the column, and one line — the sentence it replaced was the only thing in
   this tile that wrapped. */
.price-basis-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  cursor: help;
}

.price-basis-badge.is-exclusive {
  background: #DBE8FF;
  color: #1D4ED8;
}

.price-basis-badge.is-inclusive {
  background: #DCF6E4;
  color: #15803D;
}

.price-basis-badge:focus-visible {
  outline: 2px solid rgba(26, 86, 219, .45);
  outline-offset: 1px;
}

@media only screen and (max-width: 767px) {
  .checkout-tile {
    gap: 12px;
    padding: 16px 0;
  }

  .tile-media {
    flex: 0 0 68px;
    width: 68px;
    height: 68px;
    padding: 6px;
    border-radius: 8px;
  }

  /*
   * Price drops under the details instead of squeezing the title into a
   * two-word column on a 360px screen.
   */
  .tile-body {
    display: block;
  }

  .tile-title {
    font-size: 15px;
    margin-bottom: 6px;
  }

  .tile-meta {
    gap: 4px 14px;
    font-size: 12.5px;
  }

  .tile-price {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-top: 10px;
    text-align: left;
  }

  .price-now {
    font-size: 16px;
  }

  .price-was,
  .price-note {
    margin-top: 0;
  }
}
</style>
