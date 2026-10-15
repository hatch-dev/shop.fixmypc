<template>
  <div class="detail-right">
    <div class="sticky-right">
      <div class="content">
        <h2 class="price-wrapper mb-5">
        <template v-if="selectedVoucher">
          <span class="strike-through f-7 mr-10">
            <price-format
              :price="displayUnitPrice"
            />
          </span>
          <span class="color-deep price voucher-price">
            <price-format
              :price="discountedPrice"
            />
          </span>
        </template>
        <template v-else>
          <span
            class="color-deep price"
          >
             <price-format
               :price="displayUnitPrice"
             />
          </span>
          <span
            class="strike-through f-7"
            v-if="displayOldPrice"
          >
            <price-format
              :price="displayOldPrice"
            />
          </span>
        </template>
        </h2>

        <!--
          The gross figure, on an ex-VAT quote only.

          An ex-VAT headline is not what the customer will be charged, so the
          inc-VAT figure has to appear beside it. An inc-VAT headline already
          is the charged amount and stands on its own — nothing is printed
          here for it, neither a label nor a net equivalent.

          Both figures come from the server's quote for the quantity on screen;
          this never divides by a rate.
        -->
        <div class="price-vat" v-if="vatSplit && !vatSplit.inclusive">
          <span class="price-vat-alt">
            Inc-VAT
            <price-format :price="vatSplit.inc" />
            <span class="price-vat-amount">
              (VAT {{ vatRateLabel }} <price-format :price="vatSplit.vat" />)
            </span>
          </span>
        </div>
        <div
          v-if="selectedVoucher"
          class="voucher-save-text"
        >
          You save
          <price-format
            :price="voucherDiscount"
          />
        </div>
        <div>

          <div class="pos-rel lh-30 z-7 inline ">
            <button
              class="semi-bold clear-height mt-10"
              aria-label="submit"
              @click.prevent="pricePopOver = !pricePopOver"
              data-ignore="price-pop-over"
            >
              <span
                class="flex no-click"
              >
                <span class="mt-2">{{ $t('detailRight.details') }}</span>
                <i
                  class="icon black scale-8"
                  :class="[{'arrow-up': pricePopOver}, {'arrow-down': !pricePopOver}]"
                />
              </span>
            </button>
            <client-only>
              <pop-over
                v-if="pricePopOver"
                :title="$t('detailRight.shippingFeeDetails')"
                @close="closePricePopOver"
                :elem-id="'price-pop-over'"
                :layer="false"
              >
                <template v-slot:content>
                  <div class="flex sided">
                    <div>
                      <p>{{ $t('detailRight.price') }}</p>
                      <p>{{ $t('detailRight.shippingFee') }}</p>
                    </div>
                    <div class="right-text">
                      <p>
                        <price-format
                          :price="toFixed(displayUnitPrice)"
                        />
                      </p>
                      <p>

                        <span v-if="isFreeShipping" class="color-free">
                          {{ $t('invent.fre') }}
                        </span>
                        <price-format
                          v-else
                          :price="toFixed(shippingPrice)"
                        />
                      </p>
                    </div>
                  </div>
                </template>
                <template v-slot:pop-footer>
                  <div class="flex sided">
                    <h5 class="semi-bold">{{ $t('checkoutRight.total') }}</h5>
                    <h5 class="semi-bold">
                      <price-format
                        :price="toFixed(displayTotalPrice)"
                      />
                    </h5>
                  </div>
                </template>
              </pop-over>
            </client-only>
          </div>
        </div>

        <!-- Delivery facts, grouped under a rule the way the buy box in
             reference/index.html and the pricebox in reference/mobile.html
             both present them. Icons take currentColor so they follow the
             configured brand colour. -->
        <div class="bb-deliv">
          <!-- Sold only in some countries: said before it is in the basket. -->
          <div v-if="sellCountries.length" class="bb-deliv-row" :class="{ 'bb-deliv-warn': notSoldHere }">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <circle cx="12" cy="12" r="9"/>
              <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>
            </svg>
            <span>
              <b>Sold only in {{ sellCountryNames }}</b>
              <template v-if="notSoldHere"> &mdash; cannot be shipped to {{ countryLabel(visitorCountry) }}</template>
            </span>
          </div>

          <!-- No shipping row covers the visitor's country (or it is not
               known yet): the cost is worked out from the address at checkout. -->
          <div v-if="tooHeavy" class="bb-deliv-row bb-deliv-warn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/>
              <circle cx="7" cy="18" r="1.7"/>
              <circle cx="17.5" cy="18" r="1.7"/>
            </svg>
            <span>{{ deliveryQuote.message }}</span>
          </div>

          <div v-else-if="!shippingPlace" class="bb-deliv-row">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/>
              <circle cx="7" cy="18" r="1.7"/>
              <circle cx="17.5" cy="18" r="1.7"/>
            </svg>
            <span>Delivery cost is worked out from your address at checkout</span>
          </div>

          <div v-else class="bb-deliv-row">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/>
              <circle cx="7" cy="18" r="1.7"/>
              <circle cx="17.5" cy="18" r="1.7"/>
            </svg>
            <span>
              <b v-if="isFreeShipping" class="color-free">{{ $t('invent.fs') }}</b>
              <b v-else>+ <price-format :price="parseFloat(shippingPrice)"/></b>
              <template v-if="!isFreeShipping">
                {{ $t('detailRight.shippingFee') }}
              </template>
              <client-only>
                — {{ $t('detailRight.arrives') }} <b>{{ arrivesAt }}</b>
              </client-only>
            </span>
          </div>

          <!-- collection only shows where a shipping rule offers a pickup
               point; its price and town come from that row -->
          <div v-if="pickupPlace" class="bb-deliv-row">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <path d="M3 21h18M5 21V9l7-5 7 5v12"/>
              <path d="M10 21v-6h4v6"/>
            </svg>
            <span>
              <b v-if="!pickupPrice" class="color-free">
                {{ $t('detailRight.freeCollection') }}
              </b>
              <b v-else>
                {{ $t('detailRight.collection') }}
                <price-format :price="pickupPrice"/>
              </b>
              <template v-if="pickupLocation">
                {{ $t('detailRight.collectionIn', { place: pickupLocation }) }}
              </template>
            </span>
          </div>
        </div>

        <div class="quantity-area-with-text" >
           <span
            class="mn-w-70x"
           >
            {{ $t('detailRight.quantity') }}
          </span>

          <quantity-nav
            v-model="quantity"
            :product-inventory="productInventory"
            :max="maxQuantity"
          />
           <p
            v-if="cartError.inventory"
            class="error mb-10"
           >
            {{cartError.inventory}}
          </p>
        </div>
        <div class="flex-sm mlr-sm--2-5">
          <ajax-button
            id="add-to-cart"
            class="w-100 w-sm-50 primary-btn mtb-10 mlr-sm-2-5"
            :disabled="disabled"
            type="button"
            :fetching-data="ajaxing"
            @clicked="addToCart"
            :loading-text="$t('detailRight.adding')"
            :text="$t('detailRight.addToCart')"
          />
          <ajax-button
            class="w-100 w-sm-50 outline-btn  mtb-10 mlr-sm-2-5"
            :disabled="disabled"
            type="button"
            color="primary"
            :fetching-data="buyingNow"
            @clicked="addToCart(true)"
            :loading-text="$t('detailRight.buyNow')"
            :text="$t('detailRight.buyNow')"
          />
        </div>

        <div class="pos-rel inline">
          <button
            class="clear-height ml--7-5 mtb-10 f-10 semi-bold flex color-deep"
            aria-label="submit"
            @click.prevent="secureTrans = !secureTrans"
            data-ignore="secure-trans"
          >
            <i
              class="no-click icon lock-icon mr-5 opacity-35 dimen-20x"
            />
            {{ $t('detailRight.secureTransaction') }}
          </button>
          <pop-over
            :title="$t('detailRight.transactionIsSecured')"
            v-if="secureTrans"
            @close="closeSecureTrans"
            class="secure-trans"
            elem-id="secure-trans"
          >
            <template v-slot:content>
              <p class="mn-w-350x mn-w-sm-0">
                {{ $t('detailRight.secureTransaction') }}
                {{ $t('detailRight.secureTransactionMsg') }}
              </p>
            </template>
          </pop-over>
        </div>

        <client-only>
          <ajax-button
            class="mt-15 w-100 outline-btn wishlist-btn hide-sm"
            type="button"
            color="primary"
            :fetching-data="ajaxingWishlist"
            @clicked="wishListAction"
            :loading-text="!wishListed ? $t('detailRight.addingToWishlist') : $t('detailRight.removingFromWishlist')"
            :text="!wishListed ? $t('detailRight.addToWishlist') : $t('detailRight.removeFromWishlist')"
          />
        </client-only>
      </div>

      <client-only>
        <social-share
          class="hide-sm mb-15"
          :product="product"
        />
      </client-only>

      <store-tile
        class="mt-10"
        :store="product.store"
      />

    </div><!-- detail-right -->
  </div><!-- detail-right -->
</template>

<script>
  import moment from 'moment'
  import util from '~/mixin/util'
  import cartHelper from '~/mixin/cartHelper'
  import productHelper from '~/mixin/productHelper'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import businessPricing from '~/mixin/businessPricing'
  import Dropdown from '~/components/Dropdown'
  import PopOver from '~/components/PopOver'
  import SocialShare from '~/components/SocialShare'
  import QuantityNav from '~/components/QuantityNav'
  import AjaxButton from '~/components/AjaxButton'
  import {mapGetters, mapActions} from 'vuex'
  import PriceFormat from "~/components/PriceFormat"
  import StoreTile from "~/components/StoreTile"

  export default {
    data() {
      return {
        // The server's delivery quote for the visitor's country.
        deliveryQuote: null,
        currentAttributes: [],
        inventory: null,
        price: 0,
        selectedAttributesTitle: {},
        quantity: 1,
        pricePopOver: false,
        secureTrans: false,
      }
    },
    props: {
      extrasOldPrice: {
        type: [String, Number],
        default: null
      },
      extrasTotalPrice: {
        type: [String, Number],
        default: null
      },
      disabled: {
        type: Boolean,
        default: false
      },
      product: {
        type: Object
      },
      productInventory: {
        type: Object
      },
      bulkPricing: {
        type: Array,
        default: () => []
      },
      /*
       * The server's price for the quantity currently in the box, or null
       * before the first quote lands. Authoritative: it is decided by the same
       * helper the basket and the order use, so the page cannot advertise a
       * rate the till will not honour.
       */
      quotedUnitPrice: {
        type: Number,
        default: null
      },
      /*
       * The VAT split for that quote: rate, which convention applies, and
       * the price both ways round. Decided server-side so this component
       * never divides by a rate - the catalogue runs two conventions and
       * guessing wrong is a 46% error on the VAT.
       */
      quotedVat: {
        type: Object,
        default: null
      },
      selectedVoucher: {
        type: Object,
        default: null
      },
      discountedPrice: {
        type: [String, Number],
        default: null
      },
      voucherDiscount: {
        type: [String, Number],
        default: 0
      }
    },
    components: {
      StoreTile,
      PriceFormat,
      Dropdown,
      PopOver,
      SocialShare,
      QuantityNav,
      AjaxButton
    },
    mixins: [util, productHelper, productPriceHelper, cartHelper, businessPricing],
    computed: {
      /*
       * The struck-through "was", made of the same parts as the price beside
       * it: this product's own compare price plus the list price of whatever
       * add-ons are ticked. Zero means there is nothing genuine to strike
       * through, and the template hides it.
       */
      displayOldPrice() {
        const extras = Number(this.extrasOldPrice)

        const oldAddOns =
          this.extrasOldPrice !== null &&
          this.extrasOldPrice !== '' &&
          extras > 0
            ? extras
            : 0

        const was = Number(this.prevPrice || 0)

        if (was <= 0 && oldAddOns <= 0) {
          return 0
        }

        return (was > 0 ? was : Number(this.finalUnitPrice || 0)) + oldAddOns
      },
      /*
       * The headline price: this product at the chosen quantity, plus whatever
       * add-ons are ticked.
       *
       * It used to be *replaced* by the "frequently bought together" total,
       * which always includes this product at its retail price and never
       * changes with quantity. That is what put €699 above a quantity box set
       * to 30 for an account entitled to €599 — the band price was computed,
       * and then thrown away.
       */
      /*
       * The VAT split for the price on screen.
       *
       * The server's quote for this exact quantity and variant when it has
       * landed; the product's own quantity-1 split until then, so the block
       * does not flicker in and out while the quote is in flight. Both are
       * server-decided.
       */
      vatSplit() {
        if (this.quotedVat) {
          return this.quotedVat
        }

        const rate = parseFloat(this.product?.vat_rate || 0)

        if (!(rate > 0)) {
          return null
        }

        return {
          rate,
          inclusive: !!this.product.vat_inclusive,
          ex: parseFloat(this.product.display_price_ex_vat),
          vat: parseFloat(this.product.display_price_vat),
          inc: parseFloat(this.product.display_price_inc_vat),
        }
      },
      /* "23%", from the product's own rule; never a constant. */
      vatRateLabel() {
        if (!this.vatSplit) {
          return null
        }

        return String(Number(this.vatSplit.rate).toFixed(2)).replace(/\.?0+$/, '') + '%'
      },
      displayUnitPrice() {
        const extras = Number(this.extrasTotalPrice)

        const addOns =
          this.extrasTotalPrice !== null &&
          this.extrasTotalPrice !== '' &&
          extras > 0
            ? extras
            : 0

        return Number(this.finalUnitPrice || 0) + addOns
      },
      displayTotalPrice() {
        return Number(this.displayUnitPrice) + Number(this.shippingPrice || 0)
      },
      /*
       * What one unit costs at the quantity in the box.
       *
       * The server's quote for this exact quantity and variant wins whenever
       * it has caught up — it is the figure the basket and the order will
       * charge, and it is the only side that knows whether this account is
       * entitled to a band at all.
       *
       * Until it lands, the band the server already sent for this quantity
       * gives the stepper something immediate to show. Both are server-decided
       * prices; this component no longer works a discount out for itself.
       */
      finalUnitPrice (){
        if (this.quotedUnitPrice !== null) {
          return this.quotedUnitPrice
        }

        return Math.max(
          this.bandedUnitPrice(this.bulkPricing, this.quantity, this.productPrice),
          0
        )
      },
      /*
       * The band the customer is on, for the "you are buying at the 21-30
       * rate" line. Null when no band applies, which is every account that is
       * not entitled to them.
       */
      appliedBand (){
        return this.bandForQuantity(this.bulkPricing, this.quantity)
      },
      totalPrice() {
        return parseFloat(this.productPrice) + parseFloat(this.shippingPrice)
      },
      /*
       * The shipping row that would deliver to the visitor's country, as the
       * server decides it (cart/shipping-quote) - products no longer carry a
       * rule. Null until it has answered, or when no rule covers the country.
       */
      shippingPlace() {
        return this.deliveryQuote?.place || null
      },
      visitorCountry() {
        return (this.location?.countryCode || '').toUpperCase()
      },
      sellCountries() {
        const value = this.product?.sell_countries

        return value ? String(value).split(',').filter(Boolean) : []
      },
      sellCountryNames() {
        return this.sellCountries.map(code => this.countryLabel(code)).join(', ')
      },
      notSoldHere() {
        return !!this.visitorCountry && this.sellCountries.length > 0 &&
          !this.sellCountries.includes(this.visitorCountry)
      },
      arrivesAt() {
        const momentDate = moment()
          .add(this.shippingPlace?.day_needed, 'days')

        const day = momentDate.format('ddd').toLowerCase()
        const mon = momentDate.format('MMM').toLowerCase()
        const date = momentDate.format('D')
        return this.$t('date.ddddMMMD', {day: this.$t(`date.${day}`), mon: this.$t(`date.${mon}`), date: date})
      },
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      /*
       * The quote's price: the band for this item's weight at the quantity in
       * the box, when the row prices by weight (Helper\Shipping). The row's
       * flat price only when the quote was refused for some other reason -
       * a basket line that cannot go to this country - as it always was.
       */
      shippingPrice() {
        if (this.deliveryQuote?.ok) {
          return this.deliveryQuote.price || 0
        }

        return this.shippingPlace?.price || 0
      },
      tooHeavy() {
        return !!this.deliveryQuote?.too_heavy
      },
      // The store's collection point, when a shipping row offers one.
      pickupPlace() {
        return (this.deliveryQuote?.collection_points || [])[0] || null
      },
      pickupPrice() {
        return parseFloat(this.pickupPlace?.pickup_price || 0)
      },
      pickupLocation() {
        return this.pickupPlace?.pickup_city
          || this.pickupPlace?.pickup_state
          || this.product?.store?.pickup_address
          || ''
      },
      maxQuantity() {
        const inventory = this.productInventory || {}
        const isBackOrder = inventory?.is_active == 1
        if (isBackOrder) {
          return 999999
        }
        return Number(inventory?.quantity || 0)
      },
      ...mapGetters('cart', ['cartProducts']),
      ...mapGetters('common', ['location']),
      ...mapGetters('resource', ['countryList']),
    },
    methods: {
      toFixed(num) {
        return parseFloat(num).toFixed(2)
      },
      closePricePopOver() {
        this.pricePopOver = false
      },
      closeSecureTrans() {
        this.secureTrans = false
      },
      countryLabel(code) {
        if (this.countryList?.[code]?.name) return this.countryList[code].name

        try {
          return new Intl.DisplayNames(['en'], {type: 'region'}).of(code) || code
        } catch (e) {
          return code
        }
      },
      /*
       * What delivery costs to the shopper's default saved address, or to
       * the visitor's country when there is none (default_address). Asking
       * by country alone priced the whole-country row for a Dublin shopper
       * that checkout then charged the cheaper Dublin row for. Only the price
       * and the collection point are read here; whether this basket can go
       * there is checked on the shipping page, against the real address.
       */
      async loadDeliveryQuote() {
        if (!this.visitorCountry && !this.$auth?.loggedIn) {
          this.deliveryQuote = null
          return
        }

        try {
          const data = await this.$store.dispatch('cart/shippingQuote', {
            shipping_type: 1,
            country: this.visitorCountry,
            default_address: 1,
            // weighed as this item at the quantity chosen, not the basket
            product_id: this.product?.id,
            quantity: Math.max(1, parseInt(this.quantity) || 1),
            user_token: await this.$store.dispatch('user/getUserToken')
          })

          this.deliveryQuote = data?.status === 200 ? data.data : null
        } catch (e) {
          this.deliveryQuote = null
        }
      },
    },
    beforeDestroy() {
      clearTimeout(this.quoteTimer)
    },
    mounted() {
      this.loadDeliveryQuote()

      // The bar and the voucher maths read these; publish the opening values
      // so neither has to reach into $refs, which does not re-render.
      this.$emit('quantity-change', this.quantity)
      this.$emit('unit-price-change', this.finalUnitPrice)
    },
    watch: {
      // The visitor's country is looked up after the page loads.
      visitorCountry(value, previous) {
        if (value !== previous) this.loadDeliveryQuote()
      },
      /*
       * The page re-quotes on both of these, because the server prices a
       * quantity against a variant. Reaching into $refs.detailRight from the
       * parent template — which is how the sticky bar used to read the price —
       * is not reactive, so the bar kept showing the price from whenever the
       * page last happened to re-render.
       */
      quantity: {
        immediate: true,
        handler(value, previous) {
          this.$emit('quantity-change', value)

          if (previous === undefined || value === previous) return

          clearTimeout(this.quoteTimer)
          this.quoteTimer = setTimeout(() => this.loadDeliveryQuote(), 350)
        }
      },
      finalUnitPrice: {
        immediate: true,
        handler(value) {
          this.$emit('unit-price-change', Number(value || 0))
        }
      },
      productInventory: {
        immediate: true,
        handler(val) {
          if (!val) return

          const isBackOrder = val?.is_active == 1

          if (isBackOrder) {
            this.quantity = this.quantity > 0 ? this.quantity : 1
            return
          }

          if (!val.quantity || val.quantity <= 0) {
            this.quantity = 0
          }else {
            this.quantity = 1
          }

        }
      }
    }
  }
</script>

<style scoped>
.wishlist-btn {
  background: #05B942;
  color: #fff;
}

.wishlist-btn:hover {
  background: #05B942;
  color: #fff;
}
.voucher-price {
  color: #16a34a;
}

.voucher-save-text {
  color: #16a34a;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 10px;
}
.quantity-area-with-text {
    display: flex;
    align-items: center;
    gap: 15px;
}
.quantity-area-with-text .mn-w-70x {
    font-size: 16px;
    font-weight: 400;
}
</style>
