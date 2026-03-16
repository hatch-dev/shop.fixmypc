<template>

  <div class="detail-right">
    <div class="area pt-10 plr-20 plr-sm-15 pb-20 pb-sm-15">
      <h5 class="bold b-b pb-10 mb-15">
        {{ $t('filter.ckout') }}
      </h5>
      <div class="flex sided mb-15">
        <h5 class="fw-400">{{ $t('checkoutRight.subtotalItems', {itemCount: cartPrice.totalItems}) }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(cartPrice.totalPriceWithOffer)"
          />
        </h5>
      </div>
      <div
        v-if="cartPrice.totalPrice !== cartPrice.totalPriceWithOffer"
        class="flex sided pb-10">

        <h5 class="fw-400">{{ $t('cartProductTile.bundleOffer') }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(cartPrice.totalPrice - cartPrice.totalPriceWithOffer)"
          />
        </h5>
      </div>
      <div
        v-if="hasShipping"
        class="flex sided pb-10">
        <h5 class="fw-400">{{ $t('checkoutRight.shipping') }}</h5>
        <h5 class="price">

          <span
            v-if="isFreeShipping"
            class="color-free">
            {{ $t('invent.fre') }}
          </span>
          <price-format
            v-else
            :price="formatPrice(cartPrice.shippingPrice)"
          />
        </h5>
      </div>
      <div
        v-if="voucherResult"
        class="flex sided pb-10">
        <h5 class="fw-400">{{ $t('checkoutRight.voucher') }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(voucherResult.offered)"
          />
        </h5>
      </div>

      <!-- Tax Inclusive Display -->
      <div
        v-if="cartPrice.inclusiveTax > 0"
        class="flex sided mb-10"
      >
        <h5 class="fw-400">{{ $t('cart.tax_inclusive') }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(cartPrice.inclusiveTax)"
          />
        </h5>
      </div>

      <!-- Tax Exclusive Display -->
      <div
        v-if="cartPrice.exclusiveTax > 0"
        class="flex sided mb-10"
      >
        <h5 class="fw-400">{{ $t('cart.tax_exclusive') }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(cartPrice.exclusiveTax)"
          />
        </h5>
      </div>

      <div class="flex sided mb-20 mb-sm-15 b-t pt-10">
        <h5 class="fw-400">{{ $t('checkoutRight.total') }}</h5>
        <h4 class="price">
          <price-format
            :price="formatPrice(totalPrice)"
          />
        </h4>
      </div>
      <ajax-button
        v-if="!hideBtn"
        class="primary-btn  w-100"
        type="button"
        :fetching-data="submitting"
        :loading-text="''"
        :disabled="disabled"
        :text="btnText"
        @clicked="$emit('go-next')"
      />
      <slot
        name="checkout"
      />

      <div v-if="discountedPrice > 0" class="discounted-price success-msg mt-20">
        <span class="">{{ $t('date.ttl') }} </span>
        <h4 class="price">
          <price-format
            :price="formatPrice(discountedPrice)"
          />
        </h4>
      </div>

    </div>
  </div>

</template>

<script>
  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import productHelper from "../mixin/productHelper"
  import productPriceHelper from "../mixin/productPriceHelper"
  import AjaxButton from '~/components/AjaxButton'
  import PriceFormat from "./PriceFormat";

  export default {
    name: 'CheckoutRight',
    data() {
      return {
        voucher: ''
      }
    },
    watch: {},
    props: {
      checkedProduct: {
        type: Array
      },
      cartProducts: {
        type: Array,
        default: function() {
          return [];
        }
      },
      btnText: {
        type: String,
        default: function () {
          return this.$t('checkoutRight.proceedToCheckout')
        }
      },
      hasShipping: {
        type: Boolean,
        default: false
      },
      disabled: {
        type: Boolean,
        default: false,
      },
      submitting: {
        type: Boolean,
        default: false,
      },
      hideBtn: {
        type: Boolean,
        default: false,
      },
      voucherResult: {
        type: Object,
        default: () => {
          return null
        }
      }
    },
    components: {
      PriceFormat,
      AjaxButton
    },
    computed: {
      isFreeShipping() {
        return !(parseFloat(this.cartPrice?.shippingPrice) > 0)
      },
      totalPrice() {
        return this.cartPrice.totalPriceWithOffer
          + this.cartPrice.shippingPrice
          + this.cartPrice.exclusiveTax
          - this.cartPrice.voucher;
      },
      voucherDiscount(){
        return this.voucherResult?.offered ?? 0
      },
      discountedPrice() {
        return this.cartPrice.priceBeforeOffer - this.cartPrice.totalPriceWithOffer + parseFloat(this.voucherDiscount)
      },
      cartPrice() {
        let cp = {
          priceBeforeOffer: 0,
          totalItems: 0,
          totalPriceWithOffer: 0,
          totalPrice: 0,
          inclusiveTax: 0,    // Tax already included in price (excludeVAT = 0)
          exclusiveTax: 0,    // Tax to be added to price (excludeVAT = 1)
          shippingPrice: 0,
          voucher: 0
        }

        let shippingId = []

        this.checkedProduct.forEach((curr) => {
          // Check if this product has an upsell price
          const hasUpsellPrice = curr?.upsell_price && parseFloat(curr.upsell_price) > 0;
          const productPrice = hasUpsellPrice ? parseFloat(curr.upsell_price) : this.currentInventoryPriceCalc(curr?.updated_inventory, curr?.flash_product);

          const currentShippingId = curr?.shipping_place?.shipping_rule?.id;
          const shippingIdExists = shippingId[currentShippingId]

          if (!curr?.shipping_place?.shipping_rule?.single_price ||
            (curr?.shipping_place?.shipping_rule?.single_price && !shippingIdExists)) {

            if (parseInt(curr.shipping_type) === 1 && this.hasShipping) {
              cp.shippingPrice += parseInt(curr?.shipping_place?.price || 0)
            } else if (parseInt(curr.shipping_type) === 2 && this.hasShipping) {
              cp.shippingPrice += parseInt(curr?.shipping_place?.pickup_price || 0)
            }
            shippingId[currentShippingId] = curr.shipping_type
          }

          const qty = parseInt(curr?.quantity || 0)
          const bundleDeal = curr?.flash_product?.bundle_deal
          cp.totalItems += qty
          
          // Use upsell price if available, otherwise use regular price
          const bundleOffer = (bundleDeal?.buy <= qty) ? (productPrice * parseInt(bundleDeal?.free || 0)) : 0
          cp.totalPriceWithOffer += qty * productPrice - bundleOffer
          
          // Calculate tax only for tax_rule_id = 1
          const taxRule = curr?.flash_product?.tax_rules;
          if (taxRule && taxRule.id === 1) { // Only calculate tax for tax_rule_id = 1
            const taxRate = taxRule.price || 0;
            
            if (parseInt(curr.flash_product?.excludeVAT) === 0) {
              // VAT Inclusive: Tax is already included in price, calculate the tax amount
              const productVAT = (productPrice * taxRate) / 100;
              cp.inclusiveTax += productVAT * qty;
            } else {
              // VAT Exclusive: Calculate tax to be added
              const taxAmount = this.priceByType(productPrice, taxRate, taxRule.type);
              cp.exclusiveTax += taxAmount * qty;
            }
          }
          
          cp.totalPrice += qty * productPrice
          
          // For priceBeforeOffer, use the original price (not upsell price)
          const originalPrice = this.currentInventoryPriceCalc(curr?.updated_inventory, curr?.flash_product);
          cp.priceBeforeOffer += this.sellPriceCalc(curr?.flash_product) * qty
        })
        cp.voucher = this.voucherResult?.offered || 0

        this.$emit('calculated-price', cp)
        return cp
      },
      ...mapGetters('common', ['currencyIcon', 'setting']),
    },
    mixins: [util, productHelper, productPriceHelper],
    methods: {},
    created() {
    },
    mounted() {
    }
  }
</script>