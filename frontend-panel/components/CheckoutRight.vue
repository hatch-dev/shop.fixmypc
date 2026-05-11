  <template>

    <div class="col-lg-4">
      <div class="card p-3 summary">
        <h5 class="bold b-b pb-10 mb-15">
          Order Summary
        </h5>
        <!-- <div class="flex sided mb-15">
          <h5 class="fw-400">{{ $t('checkoutRight.subtotalItems', { itemCount: cartPrice.totalItems }) }}</h5>
          <h5 class="price">
            <price-format :price="formatPrice(cartPrice.totalPriceWithOffer)" />
          </h5>
        </div> -->
        <div class="flex sided mb-15">
          <h5 class="fw-400">Subtotal</h5>
          <h5 class="price">
            <price-format :price="formatPrice(cartPrice.priceBeforeOffer)" />
          </h5>
        </div>

        <!-- <div v-if="cartPrice.totalPrice !== cartPrice.totalPriceWithOffer" class="flex sided pb-10">

          <h5 class="fw-400">{{ $t('cartProductTile.bundleOffer') }}</h5>
          <h5 class="price">
            <price-format :price="formatPrice(cartPrice.totalPrice - cartPrice.totalPriceWithOffer)" />
          </h5>
        </div> -->

        <div class="flex sided pb-10">
          <h5 class="fw-400">Discount</h5>
          <h5 class="price text-success">
            - <price-format :price="formatPrice(cartPrice.priceBeforeOffer - cartPrice.totalPrice)" />
          </h5>
        </div>

        <div class="flex sided pb-10">
          <h5 class="fw-400">Flash Discount</h5>
          <h5 class="price text-success">
            - <price-format :price="formatPrice(flashDiscount)" />
          </h5>
        </div>

        <div class="flex sided pb-10">
          <h5 class="fw-400">{{ $t('checkoutRight.shipping') }}</h5>
          <h5 class="price">

            <span v-if="isFreeShipping" class="color-free">
              {{ $t('invent.fre') }}
            </span>
            <price-format v-else :price="formatPrice(cartPrice.shippingPrice)" />
          </h5>
        </div>
        <div class="flex sided pb-10">
          <h5 class="fw-400">Voucher</h5>
          <h5 class="price text-success">
           - <price-format :price="formatPrice(voucherResult?.offered || 0)" />
          </h5>
        </div>

        <!-- Tax Inclusive Display -->
        <!-- <div class="flex sided mb-10">
          <h5 class="fw-400">{{ $t('cart.tax_inclusive') }}</h5>
          <h5 class="price">
            <price-format :price="formatPrice(cartPrice.inclusiveTax)" />
          </h5>
        </div> -->

        <!-- Tax Exclusive Display -->
        <div class="flex sided mb-10">
          <!-- <h5 class="fw-400">{{ $t('cart.tax_exclusive') }}</h5> -->
          <h5 class="fw-400">Tax</h5>
          <h5 class="price">
            <price-format :price="formatPrice(cartPrice.exclusiveTax)" />
          </h5>
        </div>

        <div class="flex sided mb-20 mb-sm-15 b-t pt-10">
          <h5 class="fw-400">{{ $t('checkoutRight.total') }}</h5>
          <h4 class="price">
            <price-format :price="formatPrice(totalPrice)" />
          </h4>
        </div>
        <ajax-button v-if="!hideBtn" class="primary-btn  w-100" type="button" :fetching-data="submitting"
          :loading-text="''" :disabled="disabled" :text="btnText" @clicked="$emit('go-next')" />
        <slot name="checkout" />

        <!-- SECOND BUTTON -->
        <button class="continue-btn" @click="goToHome">
          Continue Shopping
        </button>

        <hr class="seprate-hr">
        </hr>

        <!-- PAYMENT IMAGE -->
        <div class="payment-icons">
          <img :src="require('~/assets/images/cards.png')" alt="cards" />
        </div>

        <!-- SECURE -->
        <div class="secure-box">
          <svg width="11" height="12" viewBox="0 0 11 12">
            <path
              d="M3.375 3.375V4.5H7.125V3.375C7.125 2.33906 6.28594 1.5 5.25 1.5C4.21406 1.5 3.375 2.33906 3.375 3.375V3.375M1.875 4.5V3.375C1.875 1.51172 3.38672 0 5.25 0C7.11328 0 8.625 1.51172 8.625 3.375V4.5H9C9.82734 4.5 10.5 5.17266 10.5 6V10.5C10.5 11.3273 9.82734 12 9 12H1.5C0.672656 12 0 11.3273 0 10.5V6C0 5.17266 0.672656 4.5 1.5 4.5H1.875V4.5"
              fill="#6B7280" />
          </svg>
          Secure Checkout Guaranteed
        </div>

      </div>

          <!-- SAVING -->
      <div v-if="discountedPrice > 0" class="saving-box mt-3">
        <span>
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
              d="M0 1.75198V7.22003C0 7.84182 0.245057 8.438 0.683964 8.87691L7.12127 15.3142C8.03566 16.2286 9.51697 16.2286 10.4314 15.3142L15.3142 10.4314C16.2286 9.51698 16.2286 8.03567 15.3142 7.12128L8.8769 0.683976C8.43799 0.245069 7.84181 1.27074e-05 7.22002 1.27074e-05H1.75563C0.786376 -0.00364485 0 0.78273 0 1.75198ZM4.09647 2.9224C4.51462 2.9224 4.90101 3.14548 5.11008 3.50761C5.31916 3.86974 5.31916 4.3159 5.11008 4.67803C4.90101 5.04016 4.51462 5.26324 4.09647 5.26324C3.4505 5.26324 2.92605 4.73879 2.92605 4.09282C2.92605 3.44685 3.4505 2.9224 4.09647 2.9224Z"
              fill="#130E2B" />
          </svg>
        </span>
        <span>You're saving €{{ discountedPrice.toFixed(2) }}!</span>
      </div>

    </div>

  </template>

<script>
import util from '~/mixin/util'
import { mapGetters } from 'vuex'
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
  watch: {
    checkedProduct: {
      handler(val) {
        if (!val.length) {
          this.$store.commit('cart/SET_FLASH_DISCOUNT', { amount: 0 })
        }
      },
      deep: true
    }
  },
  props: {
    checkedProduct: {
      type: Array
    },
    cartProducts: {
      type: Array,
      default: function () {
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
      default: () => ({
        offered: 0
      })
    }
  },
  components: {
    PriceFormat,
    AjaxButton
  },
  computed: {
    bundleDiscount() {
      return Math.max(
        this.cartPrice.totalPrice - this.cartPrice.totalPriceWithOffer,
        0
      )
    },
    flashDiscount() {
      return this.$store.state.cart.flashDiscount?.amount || 0
    },
    isFreeShipping() {
      return !(parseFloat(this.cartPrice?.shippingPrice) > 0)
    },
    totalPrice() {
      return this.cartPrice.totalPriceWithOffer
        + this.cartPrice.shippingPrice
        + this.cartPrice.exclusiveTax
        - this.cartPrice.voucher
        - this.flashDiscount
        ;
    },
    voucherDiscount() {
      return this.voucherResult?.offered ?? 0
    },
    discountedPrice() {
      const saving =
        this.cartPrice.priceBeforeOffer -
        this.cartPrice.totalPriceWithOffer +
        parseFloat(this.voucherDiscount || 0) +
        parseFloat(this.flashDiscount || 0)

      return saving > 0 ? saving : 0
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
        // const productPrice = hasUpsellPrice ? parseFloat(curr.upsell_price) : parseFloat(curr?.price || 0);

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
      console.log("cp", cp);
      return cp
    },
    ...mapGetters('common', ['currencyIcon', 'setting']),
  },
  mixins: [util, productHelper, productPriceHelper],
  methods: {
    goToHome() {
      this.$router.push('/categories')
    }
  },
  created() {
  },
  mounted() {
  }
}
</script>
<style scoped>
.seprate-hr {
  color: #E7E7EE;
}

.continue-btn {
  width: 100%;
  margin-top: 10px;
  background: #F7F7FA;
  color: #130E2B;
  border-radius: 10px;
  padding: 15px;
  height: unset;
  font-size: 16px;
  line-height: 1em;
  border: 1px solid #D7D7E0;
}

.payment-icons {
  display: flex;
  justify-content: center;
  margin-top: 15px;
}

.payment-icons img {
  height: 26px;
  object-fit: contain;
}

/* SECURE */
.secure-box {
  text-align: center;
  font-size: 13px;
  color: #6b7280;
  margin-top: 10px;
}

/* SAVING */
.saving-box{
    background: #ABF8C5;
    color: #130E2B;
    padding: 15px;
    border-radius: 10px;
    font-size: 14px;
}
</style>