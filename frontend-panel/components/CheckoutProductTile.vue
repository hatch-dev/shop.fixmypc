<template>
  <div
    class="gap-20 flex sided align-start b-b pb-20 pt-10 mb-10 cart-product-tile"
    v-if="product"
  >
    <div class="flex gap-15">
      <nuxt-link
        class="w-100x img-wrapper"
        :to="productLink(product)"
        :title="title"
      >
        <lazy-image
          :data-src="getThumbImageURL(productImage)"
          :title="title"
          :alt="title"
        />
      </nuxt-link>
    </div>
    <div class="content-wrap flex align-start grow block-sm gap-15">
      <div class="grow">

        <div>
          <h5 class="semi-bold mb-5">
            <nuxt-link
              class="ellipsis-1"
              :to="productLink(product)"
              :title="title"
            >
              {{ title }}
            </nuxt-link>
          </h5>
          <p class="qty-text">
            Qty: {{ productQuantity }}
          </p>
          <h6 class="color-lite mb-15 mt-10">
            <span class="mr-15" v-for="i in currentAttr">
              <b class="mr-5">{{i[0]}}</b> : {{i[1]}}
            </span>

            <span
              v-if="hasBundleDeal"
              class="ellipsis-1 mr-10"
            >
              <span class="bold mr-5">{{ $t('cartProductTile.bundleOffer') }}: </span>
              {{ bundleDeal.title }}
            </span>

            <!-- Show upsell discount if applicable -->
            <span v-if="hasUpsellDiscount">
              <price-format
                class="color-reduced strike-through"
                :price="originalPrice"
              />
              <span class="bold color-offer">{{ $t('date.offer', {amount: upsellDiscountPercent }) }}</span>
            </span>
            
            <!-- Show regular discount if no upsell discount -->
            <span
              v-if="!hasUpsellDiscount && hasRegularDiscount"
            >
              <price-format
                class="color-reduced strike-through"
                :price="regularPrevPrice"
              />
              <span class="bold color-offer">{{ $t('date.offer', {amount: regularDiscountPercent }) }}</span>
            </span>
            
            <!-- Upsell badge -->
            <span v-if="hasUpsellPrice" class="upsell-badge">
              {{ $t('cartProductTile.upsellOffer') }}
            </span>
          </h6>

        </div>

        <form
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

      <div class="mt-sm-10 mn-w-90x right-text">
        <h5 class="price inl-b-sm">
          <price-format
            :price="displayPrice"
          />
        </h5>

        <p class="inl-b-sm" v-if="hasBundleDeal">(-) x {{ bundleDeal.free }}</p>

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

      ...mapGetters('common', ['currencyIcon', 'setting']),
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
.upsell-badge {
  background-color: #ff6b6b;
  color: white;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: bold;
  margin-left: 8px;
}

.color-offer {
  color: #ff6b6b;
}

.strike-through {
  text-decoration: line-through;
}

.color-reduced {
  color: #999;
}
</style>