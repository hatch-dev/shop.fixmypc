<template>
  <div v-if="product">
    <div class="cart-product-list-card">
      <div class="cart-list-item">
        <div class="cart-item-img-title">
          <div class="cart-list-img">
            <nuxt-link :to="productLink(product)" :title="title">
              <lazy-image :data-src="getThumbImageURL(productImage)" :title="title" :alt="title"
                class="product-img-add-card" />
            </nuxt-link>
          </div>

          <div class="product-cart-title">
            <h6 class="cart-heading ">
              <nuxt-link class="ellipsis-1" :to="productLink(product)" :title="title">
                {{ title }}
              </nuxt-link>
            </h6>
            <span v-if="isBackorder" class="badge-secondary">
              Back Order
            </span>
            <div class="add-cart-small-text">
              <span class="mute-price-text">Price </span>
              <span v-if="discountAmount > 0" class="strike-through color-reduced mr-5">
                <price-format :price="originalPrice" />
              </span>
              <price-format class="price-show" :price="displayPrice" />
              <span v-if="discountAmount > 0" class="save-text ml-5">
                Save {{ currencyIcon }}{{ discountAmount.toFixed(2) }}
              </span>
            </div>
            <a href="#" class="small-text" @click.prevent="saveForLaterAction">
              Save for later
            </a>
            <form v-if="isShipping && currentAddresses.length && isSingleShipping" class="mt-10">
              <p v-if="!currentShipRule" class="error">{{ noShipMessage }}</p>
              <p v-else-if="error && error.length" class="error">
                <span class="block" v-for="e in error">{{ e }}</span>
              </p>
              <div v-else-if="cartShipping[cart.id]">
                <label class="mr-15 cp rd-container color-lite">

                  <input class="mt-5 cp" type="radio" :value="shippingTypeIn.location" :name="`shipping_${cartId}_type`"
                    v-model="cartShipping[cartId].shipping_type" @change="updateCartShipping">
                  <span class="rd-checkmark"></span>
                  {{ $t('cartProductTile.fromLocation') }}
                  (<span v-if="isFreeShipping" class="color-free">
                    {{ $t('invent.fre') }}
                  </span>
                  <price-format v-else :price="currentShipRule.price" />)
                </label>
                <label v-if="parseInt(currentShipRule.pickup_point) === 1" class="mr-15 cp rd-container color-lite">
                  <input class="mt-5 cp" type="radio" :value="shippingTypeIn.pickup" :name="`shipping_${cartId}_type`"
                    v-model="cartShipping[cartId].shipping_type" @change="updateCartShipping">
                  <span class="rd-checkmark"></span>
                  {{ $t('cartProductTile.fromPickupPlace') }}
                  (<span v-if="isFreePickup" class="color-free">
                    {{ $t('invent.fre') }}
                  </span>
                  <price-format v-else :price="currentShipRule.pickup_price" />)
                </label>
              </div>
            </form>
          </div>
        </div>

        <div class="action-box">
          <div class="delete-and-check">
            <div class="delete-btn" @click="deleting">
              <i class="fa-solid fa-trash-can"></i>
            </div>
            <label class="cb-container">
              <input type="checkbox" :value="cartId" v-model="cbChecked" class="cp"
                @change="$emit('cb-changed', { id: cart.id, checked: $event })">
              <span class="checkmark"></span>
            </label>
          </div>
          <div class="qty-box">
            <quantity-nav class="mtb-5" :value="parseInt(productQuantity)" :product-inventory="cart.updated_inventory"
              :max="maxQuantity" @value-changed="valueChanged" />
          </div>
        </div>
      </div>
    </div>

    <!-- <div class="card p-3 mb-3">
      <h6 class="bundle-list-title">Frequently Bought Together</h6>
        <bundle-carousel
          v-for="bundle in bundleList"
          :key="bundle.id"
          :bundle="bundle"
        />
    </div> -->

    <!-- <flash-discount/> -->
  </div>

  <!-- <div class="flex gap-15">

      <label class="cb-container">
        <input
          type="checkbox"
          :value="cartId"
          v-model="cbChecked"
          class="cp"
          @change="$emit('cb-changed', {id: cart.id, checked: $event})"
        >
        <span class="checkmark"></span>
      </label>

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

            <span v-if="hasUpsellDiscount">
              <price-format
                class="color-reduced strike-through"
                :price="originalPrice"
              />
              <span class="bold color-offer">{{ $t('date.offer', {amount: upsellDiscountPercent }) }}</span>
            </span>
            
            <span
              v-if="!hasUpsellDiscount && hasRegularDiscount"
            >
              <price-format
                class="color-reduced strike-through"
                :price="regularPrevPrice"
              />
              <span class="bold color-offer">{{ $t('date.offer', {amount: regularDiscountPercent }) }}</span>
            </span>
            
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

        <div
          v-if="!isShipping"
          class="flex gap-10 start wrap mt-10 payment-checkout"
        >
          <quantity-nav
            class="mtb-5"
            :quantity="parseInt(productQuantity)"
            :product-inventory="cart.updated_inventory"
            :max="maxQuantity"
            @value-changed="valueChanged"
          />
          <ajax-button
            class="outline-btn plr-20 mtb-5"
            type="button"
            :text="$t('userAddress.delete')"
            color="primary"
            @clicked="deleting"
          />
        </div>

      </div>

      <div class="mt-sm-10 mn-w-90x right-text">
        <h5 class="price inl-b-sm">
          <price-format
            :price="displayPrice"
          />
        </h5>

        <p class="inl-b-sm">x {{ productQuantity }}</p>
        <p class="inl-b-sm" v-if="hasBundleDeal">(-) x {{ bundleDeal.free }}</p>

      </div>
    </div> -->
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
import LazyImage from '~/components/LazyImage'
import util from '~/mixin/util'
import QuantityNav from '~/components/QuantityNav'
import productPriceHelper from '~/mixin/productPriceHelper'
import productImageHelper from '~/mixin/productImageHelper'
import PriceFormat from "~/components/PriceFormat"
import AjaxButton from "~/components/AjaxButton"
import FlashDiscount from "~/components/FlashDiscount"
import Dropdown from "./Dropdown";

export default {
  name: 'CartProductTile',
  data() {
    return {
      // bundleList: [],
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
      default() {
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
    LazyImage,
    FlashDiscount
  },
  computed: {
    isGuest() {
      return !this.$auth?.loggedIn
    },
    isBackorder() {
      const inventory = this.productInventory

      return (
        parseInt(inventory?.quantity) <= 0 &&
        parseInt(inventory?.is_active) == 1
      )
    },
    discountAmount() {
      if (!this.originalPrice || !this.displayPrice) return 0

      return Number(this.originalPrice) - Number(this.displayPrice)
    },

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
      // if (this.hasUpsellPrice) {
        // For upsell items, use the mixin's product price as original price
        // return this.$options.computed.productPrice.call(this);
      // }
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

      if (matched && !matched?.shipping_rule) {
        matched = { ...matched, ...{ shipping_rule: this.product?.shipping_rule } }
      }

      if (matched && this.cartShipping[this.cart?.id]) {
        this.cartShipping[this.cart?.id].shipping_place = matched
        this.updateCartShipping()
      }

      this.$emit('current-shipping', { cart: this.cart?.id, shipping: matched })
      return matched
    },
    productImages() {
      this.product?.product_images
    },
    currentImage() {
      if (this.inventoryAttributes?.length && this.inventoryAttributes[0]?.attribute_value) {
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
      const inventory = this.productInventory
      if (parseInt(inventory?.is_active) === 1) {
        return 9999
      }
      return parseInt(inventory?.quantity || 0)
    },
    productQuantity() {
      return parseInt(this.cart?.quantity)
    },
    noShipMessage() {
      const state = this.address?.stateTitle ? `${this.address?.stateTitle},` : ''
      return this.$t('cartProductTile.noShipMessage', { state: state, country: this.address?.countryTitle })
    },

    ...mapGetters('common', ['currencyIcon', 'setting']),
  },
  mixins: [util, productPriceHelper, productImageHelper],
  methods: {
    // async loadBundleDeals(){
    //   try{
    //     const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
    //     const response = await this.$axios.get(`${baseUrl}api/v1/bundle-deals`);
    //     const data = response?.data?.data;
    //     this.bundleList = data;
    //   }catch(error){
    //     console.error("error", error);
    //   }
    // },
    ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError']),
    saveForLaterGuest() {
      let saved = JSON.parse(localStorage.getItem('save_for_later')) || []

      const item = {
        product_id: this.cart.product_id,
        inventory_id: this.cart.inventory_id,
        quantity: this.cart.quantity,
        product: this.cart.flash_product, // for UI reuse
        inventory: this.cart.updated_inventory
      }
      
      const exists = saved.find(i =>
        i.product_id === item.product_id &&
        i.inventory_id === item.inventory_id
      )

      if (exists) {
        saved = saved.filter(i =>
          !(i.product_id === item.product_id &&
            i.inventory_id === item.inventory_id)
        )
      } else {
        saved.push(item)
      }

      localStorage.setItem('save_for_later', JSON.stringify(saved))

      this.$emit('deleting', { id: this.cartId })
    },
    async saveForLaterAction() {
      if (this.isGuest) {
        this.saveForLaterGuest()
        return
      }
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const payload = {
          cart_id: this.cartId
        }
        await this.$axios.post(`${baseUrl}api/v1/user/save-for-later/action`, payload)

        this.$emit('deleting', {
          id: this.cartId,
        })
        
        this.setToastMessage('Moved to Save for later');
      } catch (e) {
        console.error(e)
      }
    },
    updateCartShipping() {
      this.$emit('shipping-changed', this.cartShipping)
    },
    async deleting() {
      if (confirm(this.$t('cartProductTile.deleteAlert'))) {
        this.$emit('deleting', { id: this.cartId, isBundle: !!this.bundleDeal, user_token: await this.getUserToken() })
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
  async mounted() {
    // await this.loadBundleDeals();
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

.card-cart {
  border-radius: 14px;
  border: 1px solid #e5e7eb;
}

.delete-btn {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: rgba(19, 14, 43, 1);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

h6.cart-heading {
  font-size: 20px;
  color: #232159;
  font-weight: 600;
}

/* Cart List style Start */
.cart-product-list-card {
  background: rgba(255, 255, 255, 1);
  border: 1px solid rgba(227, 227, 239, 1);
  overflow: hidden;
  border-radius: 20px;
  padding: 5px 25px 5px 5px;
  margin-bottom: 25px;
}

.cart-product-list-card .cart-list-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.cart-product-list-card .cart-item-img-title {
  display: flex;
  gap: 20px;
  align-items: center;
}

.cart-product-list-card .cart-list-img {
  width: 180px;
  height: 170px;
  padding: 15px;
  border-radius: 16px;
  background: rgba(241, 239, 253, 1);
}

.cart-product-list-card .cart-list-img a {
  width: 100%;
  height: 100%;
}

.cart-product-list-card .cart-list-img .product-img-add-card {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.cart-product-list-card .action-box {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: flex-end;
  gap: 60px;
}

.cart-product-list-card .add-cart-small-text .mute-price-text {
  color: rgba(105, 103, 155, 1);
}

.cart-product-list-card .add-cart-small-text {
  margin-top: 60px;
}

.cart-product-list-card .add-cart-small-text .price-show {
  font-size: 18px;
  font-weight: 600;
  color: rgba(35, 33, 89, 1);
}

.cart-product-list-card .small-text {
  font-size: 14px;
  color: rgba(35, 33, 89, 1);
  -webkit-text-decoration: underline;
  text-decoration: underline;
  font-weight: 500;
  margin-top: 6px;
}

.cart-product-list-card .quantity-area {
  display: flex;
  color: rgba(19, 14, 43, 1);
}

.cart-product-list-card .delete-and-check {
  display: flex;
  gap: 20px;
  align-items: center;
}

.cart-product-list-card .cb-container {
  margin: 0;
  padding: 0;
  line-height: 0;
}

.cart-product-list-card .checkmark {
  position: unset;
}

.save-text {
  color: #ff6b6b;
  font-size: 13px;
  font-weight: 600;
}

.badge-secondary {
  border: 1px solid #605982;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #605982;
  margin-bottom: 25px;
}

@media (max-width:600px) {
  .cart-product-list-card .cart-list-img {
    width: 120px;
    height: 100px;
  }

  .cart-product-list-card .add-cart-small-text {
    margin-top: 15px;
  }

  .cart-product-list-card .action-box {
    gap: 40px;
  }

  .cart-heading a {
    font-size: 16px;
  }

  .cart-product-list-card .add-cart-small-text .price-show {
    font-size: 14px;
  }

  .cart-product-list-card .cart-item-img-title {
    gap: 10px;
  }
}

@media (max-width:425px) {
  .cart-product-list-card .action-box {
    gap: 10px;
  }

  .cart-product-list-card .cart-list-img[data-v-66b861f2] {
    width: 85px;
    height: 85px;
  }

  .quantity-area .no-control,
  .quantity-area button {
    height: 25px !important;
    line-height: 22px !important;
    width: 25px !important;
  }

  .cart-heading a {
    display: -webkit-box;
     -webkit-line-clamp: 1;
     line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .cart-product-list-card .add-cart-small-text{
     margin-top: 10px;
  }
  .cart-product-list-card .delete-and-check{
    gap:10px;
  }
  .cart-product-list-card{
        padding: 5px 10px 5px 5px;
  }

}

</style>