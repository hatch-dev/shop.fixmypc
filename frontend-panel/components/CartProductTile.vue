<template>
  <div v-if="product">
    <div class="cart-product-list-card">
      <div class="cart-list-item">
        <!-- selection sits at the start of the row, where it reads as "this
             line is included" rather than as a second action beside delete -->
        <label class="cb-container cart-select-box">
          <input type="checkbox" :value="cartId" v-model="cbChecked" class="cp"
            @change="$emit('cb-changed', { id: cart.id, checked: $event })">
          <span class="checkmark"></span>
        </label>
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
            <!--
              Which variant of the product this line is. Two lines for the same
              title, differing only by capacity or grade, were indistinguishable
              in the basket until the shopper opened each one.
            -->
            <!--
              Variant, stock and pricing basis: three short facts about the
              same line. The wrapper is `display: contents` on a desktop, so
              this column stacks exactly as it did, and a flex row on a phone,
              where three of them one under another were most of the card's
              height for about forty characters of text.
            -->
            <div class="cart-line-tags">
            <span v-if="variantLabel" class="cart-variant">{{ variantLabel }}</span>
            <span v-if="isBackorder" class="badge-secondary">
              {{ $t('ui.cartProductTileUi.backOrder') }}
            </span>
            <!--
              Which convention this line is priced on, said once and nowhere
              else on the card.

              A basket can hold one of each, and the same "€899" means a
              different thing under each — so the badge is what lets the price
              below it be read correctly rather than guessed at. The icon on it
              opens the split; it is a button rather than a title attribute
              because the figures are worth reading and a native tooltip cannot
              be reached on a phone.

              Everything that used to be printed underneath — the gross
              counterpart, the VAT amount, the rate, and a sentence explaining
              the convention — lives behind that icon now. Four restatements of
              one line's tax were what made the card unreadable; the price, the
              saving and the line total are the only figures a customer needs
              in order to decide.
            -->
            <span
              v-if="cartVatBasis"
              class="cart-basis-badge"
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
            </div>

            <div class="add-cart-small-text">
              <price-format class="price-show" :price="displayPrice" />
              <span v-if="discountAmount > 0" class="strike-through color-reduced">
                <price-format :price="originalPrice" />
              </span>
              <span v-if="discountAmount > 0" class="save-text">
                {{ $t('ui.cartProductTileUi.save', { amount: (currencyIcon || '') + discountAmount.toFixed(2) }) }}
              </span>
            </div>

            <!--
              Whether this line can be bought as it stands, and whether its
              price has moved since the shopper chose it. Both are the
              server's answer (availability and price_changed_from on the
              line): once a reservation runs out the item can sell out, and it
              can be unpublished or repriced while it sits here.
            -->
            <p
              v-if="availabilityNote"
              class="cart-availability"
              :class="`is-${cart.availability}`"
            >{{ availabilityNote }}</p>
            <p v-if="priceChangedFrom" class="cart-price-changed">
              {{ $t('cart.priceChangedFrom') }}
              <price-format :price="priceChangedFrom" />
              {{ $t('cart.priceChangedTo') }}
              <price-format :price="displayPrice" />
            </p>

            <a href="#" class="small-text" @click.prevent="saveForLaterAction">
              {{ $t('ui.cartProductTileUi.saveForLater') }}
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
          <div class="qty-box">
            <quantity-nav :value="parseInt(productQuantity)" :product-inventory="cart.updated_inventory"
              :max="maxQuantity" @value-changed="valueChanged" />
          </div>
          <!--
            The line, on the basis the line is priced on, and the multiplication
            that produced it.

            Stated the way the product is quoted rather than converted to gross:
            "€674.00 × 3" and "€2,022.00 Ex-VAT" are the same number the price
            above it is, so the card is checkable by eye. Converting it here
            instead put a figure on the card (€2,487.06) that appears nowhere
            else on the line and that the customer has no way to arrive at.

            Normalising the two conventions so they can be added is the Order
            Summary's job, and the summary is where VAT is stated. The card
            says what this product costs; the ⓘ on the badge has the split for
            anyone who wants it.

            An Ex-VAT line also shows its Inc-VAT total underneath, because the
            net figure is not what will be charged: without it the Order
            Summary's total reads as higher than the cards for no visible
            reason. An Inc-VAT line gets the matching second line as its Ex-VAT
            total ("€730.89 Ex-VAT"), by the store's choice, so both kinds of
            line show both sides. The headline stays the price charged.
          -->
          <div class="cart-line-total">
            <span class="cart-line-total-label">{{ $t('ui.cartProductTileUi.lineTotal') }}</span>

            <span class="cart-line-total-calc">
              <price-format :price="displayPrice" /> &times; {{ productQuantity }}
            </span>

            <span class="cart-line-total-main">
              <price-format class="cart-line-total-value" :price="lineTotal" />
              <em v-if="cartVatBasis" class="cart-line-total-basis">
                {{ cartVatBasis.short }}
              </em>
            </span>

            <span v-if="lineIncVat !== null" class="cart-line-total-inc">
              <price-format :price="lineIncVat" :decimals="2" /> {{ $t('ui.cartProductTileUi.incVat') }}
            </span>
            <span v-else-if="lineExVat !== null" class="cart-line-total-inc">
              <price-format :price="lineExVat" :decimals="2" /> {{ $t('ui.cartProductTileUi.exVat') }}
            </span>
          </div>
          <div class="delete-and-check">
            <button type="button" class="line-icon-btn delete-icon-btn" :aria-label="$t('ui.cartProductTileUi.removeItem')" @click="deleting">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
            <button type="button" class="line-icon-btn save-icon-btn" :aria-label="$t('ui.cartProductTileUi.saveForLater')"
              @click.prevent="saveForLaterAction">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!--
        The tinted strip that used to sit here restated the badge above it in a
        sentence, on every line, and was the single biggest thing making a
        three-item basket scroll. The badge names the convention and its
        popover explains it; a customer who has not asked does not need telling
        twice.
      -->
    </div>
  </div>
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
import InfoPopover from "~/components/InfoPopover"

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
    InfoPopover,
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
    /*
     * What this line costs.
     *
     * The server sends line_total alongside the unit price it decided, so the
     * figure on screen is the one the order will charge rather than a
     * multiplication done here against whatever price the page happens to be
     * holding. The fallback covers a cached or older payload.
     */
    /*
     * The server's gross total for an Ex-VAT line, or null. Null on an
     * Inc-VAT or zero-rated line, and on a line priced before the split was
     * sent — never worked out here from a rate.
     */
    lineIncVat() {
      if (!this.cartVatBasis || this.cartVatBasis.inclusive) return null

      const inc = parseFloat(this.cart?.line_inc_vat)

      return Number.isFinite(inc) ? inc : null
    },
    /* The server's net total for an Inc-VAT line, or null. */
    lineExVat() {
      if (!this.cartVatBasis || !this.cartVatBasis.inclusive) return null

      const ex = parseFloat(this.cart?.line_ex_vat)

      return Number.isFinite(ex) && ex > 0 ? ex : null
    },
    lineTotal() {
      const serverTotal = this.cart?.line_total

      if (serverTotal !== null && serverTotal !== undefined && serverTotal !== '') {
        const total = parseFloat(serverTotal)

        if (Number.isFinite(total)) return total
      }

      return Number(this.displayPrice || 0) * (this.productQuantity || 0)
    },

    /*
     * The unit price the server decided for this line.
     *
     * It is the figure checkout will charge, and it already accounts for the
     * business or trade quantity band this customer is entitled to at this
     * quantity — which the retail ladder in productPriceHelper cannot know
     * about. unit_price is the explicit name; upsell_price is the same value
     * under the name the cart API has always used.
     */
    hasUpsellPrice() {
      const price = this.cart?.unit_price ?? this.cart?.upsell_price
      return price !== null && price !== undefined && parseFloat(price) > 0;
    },

    upsellPrice() {
      if (!this.hasUpsellPrice) return null
      return parseFloat(this.cart?.unit_price ?? this.cart?.upsell_price);
    },

    // Display price (server price if available, otherwise the retail ladder)
    displayPrice() {
      return this.hasUpsellPrice ? this.upsellPrice : this.$options.computed.productPrice.call(this);
    },

    /*
     * The struck-through "was" figure.
     *
     * original_price comes from the same server pass as unit_price, so when a
     * quantity band is what brought the price down the customer sees the
     * retail price it undercut rather than nothing at all.
     */
    originalPrice() {
      const serverOriginal = this.cart?.original_price

      if (serverOriginal !== null && serverOriginal !== undefined && parseFloat(serverOriginal) > 0) {
        return parseFloat(serverOriginal)
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
    /*
     * "128GB • Black • Very Good" — the chosen value of each attribute, in the
     * order the inventory lists them. Only the values, not "Capacity: 128GB":
     * the attribute names are the same on every line and say nothing that
     * distinguishes one from another.
     */
    variantLabel() {
      const attributes = this.productInventory?.inventory_attributes

      if (!attributes?.length) return ''

      return attributes
        .map(i => i?.attribute_value?.title)
        .filter(Boolean)
        .join(' • ')
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
    /** The line's availability, in words; nothing when it is simply in stock. */
    availabilityNote() {
      const count = this.cart?.available_quantity

      switch (this.cart?.availability) {
        case 'low_stock': return this.$t('cart.onlyLeft', { count })
        case 'short': return this.$t('cart.onlyAvailable', { count })
        case 'out_of_stock': return this.$t('cart.lineOutOfStock')
        case 'unavailable': return this.$t('cart.lineUnavailable')
        default: return null
      }
    },
    /** The unit price the shopper chose this line at, when it differs from now. */
    priceChangedFrom() {
      const was = Number(this.cart?.price_changed_from)

      return was > 0 ? was : null
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

    ...mapGetters('common', ['currencyIcon', 'setting', 'currencyPosition']),
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

    async saveForLaterAction() {
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const payload = {
          cart_id: this.cartId,
          // A guest is identified by its token, the same one that owns the
          // basket this line is being taken out of.
          user_token: this.isGuest ? await this.getUserToken() : null
        }
        const {data} = await this.$axios.post(`${baseUrl}api/v1/user/save-for-later/action`, payload)

        if (data?.status !== 200) {
          this.setToastError(data?.message || this.$t('ui.cartProductTileUi.saveError'))
          return
        }

        this.$emit('deleting', {
          id: this.cartId,
        })

        this.$emit('saved-for-later')

        this.setToastMessage(this.$t('ui.cartProductTileUi.movedToSaved'));
      } catch (e) {
        this.setToastError(e.message)
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
.cart-availability {
  margin: 4px 0 0;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.35;
}
.cart-availability.is-low_stock {
  color: #b45309;
}
.cart-availability.is-short,
.cart-availability.is-out_of_stock,
.cart-availability.is-unavailable {
  color: #b91c1c;
}
.cart-price-changed {
  margin: 4px 0 0;
  font-size: 13px;
  line-height: 1.35;
  color: #374151;
}
.cart-price-changed >>> * {
  display: inline;
}
/* ===========================================================================
   Cart line.

   A dense row: selection, thumbnail, details, stepper, and a line-total panel
   the vertical rule separates from the rest — then one full-width strip saying
   what the line's VAT basis means. Nothing in the row is allowed to introduce
   vertical slack; the card's height is set by the details column alone.

   Sizes are deliberately small and on a 2/4/6/8/10/12px step. The previous
   layout used a 60px gap in the action column, which set the height of every
   line and left a single-item basket rendering as a band of empty white.
   =========================================================================== */

/* --- shell ---------------------------------------------------------------- */
.cart-product-list-card {
  background: #fff;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
  overflow: hidden;
  padding: 0;
  margin-bottom: 10px;
  transition: border-color .15s;
}

.cart-product-list-card:hover {
  border-color: #D6DEEA;
}

.cart-product-list-card .cart-list-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 12px 10px;
}

/* --- selection ------------------------------------------------------------ */
/* first in the row, where it reads as "this line is included" rather than as a
   second action beside delete */
.cart-product-list-card .cart-select-box {
  flex: 0 0 auto;
  align-self: center;
  position: relative;
  width: 22px;
  height: 22px;
  margin: 0;
  padding: 0;
  line-height: 0;
  cursor: pointer;
}

.cart-product-list-card .cart-select-box input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.cart-product-list-card .cart-select-box .checkmark {
  position: absolute;
  inset: 0;
  top: 0;
  left: 0;
  width: 22px;
  height: 22px;
  border: 1.5px solid #D6DEEA;
  border-radius: 6px;
  background: #fff;
  transition: background .12s, border-color .12s;
}

.cart-product-list-card .cart-select-box input:checked ~ .checkmark {
  background: #1A56DB;
  border-color: #1A56DB;
}

.cart-product-list-card .cart-select-box .checkmark:after {
  content: "";
  position: absolute;
  display: block;
  left: 7.5px;
  top: 3.5px;
  width: 5px;
  height: 10px;
  border: solid #fff;
  border-width: 0 2.2px 2.2px 0;
  transform: rotate(45deg) scale(0);
  transition: transform .12s;
}

.cart-product-list-card .cart-select-box input:checked ~ .checkmark:after {
  transform: rotate(45deg) scale(1);
}

/* --- thumbnail + details -------------------------------------------------- */
/* min-width:0 on both, or the row refuses to shrink below its content and the
   card pushes past the viewport on a phone — taking the sidebar's amounts off
   the right edge with it. A flex item's default min-width is auto, so
   flex-wrap alone does not save you here. */
.cart-product-list-card .cart-item-img-title {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1 1 auto;
  min-width: 0;
}

.cart-product-list-card .cart-list-img {
  flex: 0 0 auto;
  width: 102px;
  height: 102px;
  padding: 6px;
  border: 1px solid #E3E8F0;
  border-radius: 8px;
  background: #F8FAFD;
}

.cart-product-list-card .cart-list-img a {
  display: block;
  width: 100%;
  height: 100%;
}

.cart-product-list-card .cart-list-img .product-img-add-card {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.cart-product-list-card .product-cart-title {
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
}

h6.cart-heading {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.25;
  letter-spacing: -.01em;
  color: #0F2547;
}

h6.cart-heading a {
  color: inherit;
  text-decoration: none;
}

h6.cart-heading a:hover {
  color: #1A56DB;
}

/* No box of its own until a phone asks for one: the column below lays these
   out itself, and wrapping them in a real element would change the desktop
   card that is not being asked to change. */
.cart-line-tags {
  display: contents;
}

.cart-variant {
  font-size: 13px;
  line-height: 1.35;
  color: #6B7C93;
}

.badge-secondary {
  display: inline-block;
  margin: 0;
  padding: 2px 9px;
  border: 1px solid #D6DEEA;
  border-radius: 6px;
  font-size: 11.5px;
  font-weight: 600;
  color: #6B7C93;
}

/* --- pricing basis badge -------------------------------------------------- */
/* Which convention the line is priced on. Two quiet tints rather than two loud
   ones: this is a fact about the price, not a warning about it. */
.cart-basis-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 2px 0 1px;
  padding: 3px 9px;
  border-radius: 6px;
  font-size: 12.5px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
  cursor: help;
}

.cart-basis-badge.is-exclusive {
  background: #DBE8FF;
  color: #1D4ED8;
}

.cart-basis-badge.is-inclusive {
  background: #DCF6E4;
  color: #15803D;
}

.cart-basis-badge:focus-visible {
  outline: 2px solid rgba(26, 86, 219, .45);
  outline-offset: 1px;
}

/* the "i" riding on the badge is InfoPopover's trigger now; it takes the
   badge's colour through currentColor, so nothing is needed here */

/* --- price row ------------------------------------------------------------ */
.cart-product-list-card .add-cart-small-text {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  margin-top: 1px;
  font-size: 12.5px;
}

.cart-product-list-card .add-cart-small-text .price-show {
  font-size: 19px;
  font-weight: 800;
  letter-spacing: -.02em;
  color: #0F2547;
}

.cart-product-list-card .add-cart-small-text .strike-through {
  font-size: 14px;
  color: #8C9AB0 !important;
}

.strike-through {
  text-decoration: line-through;
}

/* The saving, as a pill rather than loose red text — it is good news, and the
   global .save-text is styled for a warning. */
.cart-product-list-card .add-cart-small-text .save-text {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  background: #DCF6E4;
  color: #15803D !important;
  font-size: 12.5px;
  font-weight: 700;
  white-space: nowrap;
}

/* the per-line save link is gone from the card: it is one of the bulk actions
   under the list now, and the corner keeps only the destructive button */
.cart-product-list-card .small-text {
  display: none;
}

/* --- controls column ------------------------------------------------------ */
/* stepper, line total and the delete button read left to right on one
   baseline, so the freed middle of the row carries the line total instead of
   nothing at all */
.cart-product-list-card .action-box {
  flex: 0 0 auto;
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
}

.cart-product-list-card .qty-box {
  flex: 0 0 auto;
}

.cart-product-list-card ::v-deep .quantity-area {
  display: inline-flex;
  align-items: center;
  height: 40px;
  border: 1px solid #D6DEEA;
  border-radius: 8px;
  background: #fff;
  overflow: hidden;
  color: #33486A;
}

.cart-product-list-card ::v-deep .quantity-area button {
  width: 40px;
  height: 100%;
  padding: 0;
  border: 0;
  background: none;
  color: #33486A;
  font-size: 19px;
  line-height: 1;
  cursor: pointer;
}

.cart-product-list-card ::v-deep .quantity-area button:hover:not(:disabled) {
  background: #EFF5FF;
  color: #1A56DB;
}

.cart-product-list-card ::v-deep .quantity-area button:disabled {
  opacity: .4;
  cursor: not-allowed;
}

.cart-product-list-card ::v-deep .quantity-area .no-control {
  min-width: 48px;
  height: auto;
  border: 0;
  text-align: center;
  font-size: 15px;
  font-weight: 700;
  color: #0F2547;
  font-variant-numeric: tabular-nums;
}

/* --- line total ----------------------------------------------------------- */
/*
  The line stated on both sides of VAT, in a panel of its own.

  A rule rather than a filled box: the figures need separating from the
  stepper, not lifting into a second card. Each amount carries the side it is
  on, because in a basket holding both conventions the same column would
  otherwise mix net and gross figures with nothing to tell them apart.

  Deliberately NOT !important, and scoped to the row: a later media query hides
  this panel on phones, where there is no room for it beside the stepper. An
  !important here beat that rule and pushed the whole card wider than the
  viewport — every amount on the page, sidebar included, was clipped off the
  right edge.
*/
.cart-list-item .cart-line-total {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  min-width: 132px;
  padding: 0 0 0 14px;
  border-left: 1px solid #E3E8F0;
  border-radius: 0;
  background: none;
  text-align: left;
  font-variant-numeric: tabular-nums;
}

.cart-line-total-label {
  display: block;
  margin: 0;
  font-size: 12.5px;
  font-weight: 400;
  color: #6B7C93;
}

/* The multiplication, above the answer: "€674.00 × 3". Quiet, because it is
   working rather than a figure to read — but present, because it is what makes
   the total below it checkable without a calculator. */
.cart-line-total-calc {
  display: block;
  font-size: 12.5px;
  color: #6B7C93;
  white-space: nowrap;
}

.cart-line-total-main {
  display: flex;
  align-items: baseline;
  gap: 7px;
  white-space: nowrap;
}

.cart-line-total-value {
  font-size: 18px;
  font-weight: 800;
  letter-spacing: -.01em;
  color: #0F2547;
}

/* Which side each figure is on — the one word that changes what the number
   means between two products in the same basket. */
.cart-line-total-basis {
  font-style: normal;
  font-size: 12px;
  font-weight: 500;
  color: #6B7C93;
}

/* The charge for an Ex-VAT line, under its net total. */
.cart-line-total-inc {
  display: block;
  font-size: 12.5px;
  font-weight: 600;
  color: #0F2547;
  white-space: nowrap;
}

/* --- row actions ---------------------------------------------------------- */
.cart-product-list-card .delete-and-check {
  display: flex;
  align-self: flex-start;
  align-items: center;
  gap: 8px;
}

/* trash and save-for-later share one quiet, outlined treatment. Delete used to
   be a solid near-black disc, which made the destructive action the heaviest
   thing on the card. */
.cart-product-list-card .line-icon-btn {
  width: 30px;
  height: 30px;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #E3E8F0;
  border-radius: 6px;
  background: #fff;
  color: #6B7C93;
  cursor: pointer;
  transition: color .15s, border-color .15s, background .15s;
}

.cart-product-list-card .line-icon-btn:hover {
  color: #1A56DB;
  border-color: #BFD4FF;
  background: #EFF5FF;
}

.cart-product-list-card .delete-icon-btn:hover {
  color: #C8324B;
  border-color: #F0C2CB;
  background: #FEF4F6;
}

/* the corner keeps only the destructive action. Saving for later is one of the
   bulk actions under the list, so repeating it per line put two icons in the
   corner where the shopper only ever reaches for one. */
/*
 * Save-for-later, beside remove.
 *
 * It was hidden once, on the grounds that the bulk bar under the list already
 * offers it. That bar acts on a selection though — using it for one line means
 * selecting that line, acting, and deselecting it — so the per-line action is
 * not really a duplicate of it, and the brief asks for both on the card. As an
 * icon in the corner it costs no height: the card is exactly as tall with it
 * as without.
 */
.cart-product-list-card .save-icon-btn {
  display: inline-flex;
}

/* --- basis explainer strip ------------------------------------------------ */
/* --- shipping picker, kept from the original row -------------------------- */
.cart-product-list-card .error {
  font-size: 12.5px;
}

/* --- misc kept selectors -------------------------------------------------- */
.color-reduced {
  color: #8C9AB0;
}

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

/* ===========================================================================
   Cart line, phone.

   The line-total panel has no room beside the stepper, so it becomes a row of
   its own under the details, and the delete button stays in the corner rather
   than dropping below the totals.
   =========================================================================== */
@media (max-width: 767px) {
  .cart-product-list-card {
    margin-bottom: 7px;
    border-radius: 9px;
  }

  .cart-product-list-card .cart-list-item {
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 8px;
    padding: 9px 10px;
  }

  /* the row is tall on a phone, and a vertically centred checkbox floated in
     the middle of the empty column beside the thumbnail */
  .cart-product-list-card .cart-select-box {
    align-self: flex-start;
    margin-top: 2px;
  }

  .cart-product-list-card .cart-item-img-title {
    flex: 1 1 0;
    align-items: flex-start;
    gap: 9px;
  }

  /* a thumbnail, not a hero image */
  .cart-product-list-card .cart-list-img {
    width: 62px;
    height: 62px;
    padding: 4px;
    border-radius: 7px;
  }

  /* The details column set its own 3px between six stacked children, which on
     a phone was a fifth of the card's height spent on gaps. */
  .cart-product-list-card .product-cart-title {
    gap: 2px;
  }

  h6.cart-heading {
    font-size: 13.5px;
    line-height: 1.3;
  }

  /*
    Variant, stock and basis on one wrapped row. Stacked, these three short
    labels took three rows of the card between them - more height than the
    thumbnail beside them - to say about forty characters.
  */
  .cart-line-tags {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 5px;
    margin: 1px 0;
  }

  .cart-variant {
    font-size: 11.5px;
  }

  .badge-secondary {
    padding: 1px 6px;
    font-size: 10.5px;
  }

  .cart-basis-badge {
    gap: 4px;
    margin: 0;
    padding: 1px 6px;
    font-size: 10.5px;
  }

  .cart-product-list-card .add-cart-small-text {
    gap: 6px;
    font-size: 11.5px;
  }

  .cart-product-list-card .add-cart-small-text .price-show {
    font-size: 15.5px;
  }

  .cart-product-list-card .add-cart-small-text .strike-through {
    font-size: 12px;
  }

  .cart-product-list-card .add-cart-small-text .save-text {
    padding: 1px 6px;
    font-size: 10.5px;
  }

  /* a full-width band of its own under the details: stepper at the left,
     delete at the right, and the figures on the line below them */
  .cart-product-list-card .action-box {
    order: 3;
    width: 100%;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    gap: 7px;
  }

  .cart-product-list-card .qty-box {
    order: 1;
  }

  /* `order` only sorts siblings, and these three are children of .action-box —
     ordering the totals against .cart-list-item put the trash button in the
     middle of the figures instead */
  .cart-product-list-card .delete-and-check {
    order: 2;
    margin-left: auto;
    align-self: center;
  }

  /* still a comfortable target at 32px tall by 30 wide - the guideline is
     about the tappable area, and these two sit alone on their row */
  .cart-product-list-card ::v-deep .quantity-area {
    height: 32px;
    border-radius: 7px;
  }

  .cart-product-list-card ::v-deep .quantity-area button {
    width: 30px;
    font-size: 17px;
  }

  .cart-product-list-card ::v-deep .quantity-area .no-control {
    min-width: 34px;
    font-size: 13.5px;
  }

  .cart-product-list-card .line-icon-btn {
    width: 28px;
    height: 28px;
  }

  /* One line under the stepper: "Line Total  €674.00 × 3 ... €2,022.00 Ex-VAT",
     with the amount pushed to the right edge where the eye already looks. The
     working sits beside the label rather than under it, so the band stays one
     row tall. */
  .cart-list-item .cart-line-total {
    order: 3;
    width: 100%;
    min-width: 0;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 2px 6px;
    padding: 6px 0 0;
    border-left: 0;
    border-top: 1px solid #E3E8F0;
  }

  .cart-line-total-label,
  .cart-line-total-calc {
    font-size: 11px;
  }

  .cart-line-total-value {
    font-size: 15.5px;
  }

  .cart-line-total-basis {
    font-size: 10.5px;
  }

  .cart-line-total-main {
    gap: 5px;
  }

  /* pushes the amount to the right whatever the working's width */
  .cart-line-total-main {
    margin-left: auto;
  }

  /* its own row, right-aligned under the amount it restates */
  .cart-line-total-inc {
    width: 100%;
    text-align: right;
    font-size: 11px;
  }
}
</style>
