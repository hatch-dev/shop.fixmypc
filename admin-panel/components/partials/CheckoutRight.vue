<template>

  <div class="detail-right">
    <div class="area pt-10 ptb-20 pb-sm-15">

      <div class="flex sided mtb-15 b-t pt-15">
        <h5 class="fw-400">{{ $t('ship.si', {itemCount: cartPrice.totalItems}) }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(cartPrice.totalPrice)"
          />
        </h5>
      </div>
      <div
        v-if="cartPrice.totalPrice !== cartPrice.totalPriceWithOffer"
        class="flex sided pb-10">

        <h5 class="fw-400">{{ $t('fSale.bOffer') }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(cartPrice.totalPrice - cartPrice.totalPriceWithOffer)"
          />
        </h5>
      </div>
      <div
        v-if="hasShipping"
        class="flex sided pb-10">
        <h5 class="fw-400">{{ $t('fSale.dFee') }}</h5>
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
        <h5 class="fw-400">{{ $t('fSale.voucher') }}</h5>
        <h5 class="price">
          <price-format
            :price="formatPrice(voucherResult.offered)"
          />
        </h5>
      </div>

      <div
        v-if="cartPrice.tax"
        class="flex sided mb-10"
      >
        <h5 class="fw-400">{{ $t('fSale.tax') }}</h5>
        <h5 class="price">

          <price-format
            :price="formatPrice(cartPrice.tax)"
          />
        </h5>
      </div>
      <div class="flex sided mb-20 mb-sm-15 b-t pt-10">
        <h5 class="fw-400">{{ $t('fSale.total') }}</h5>
        <h4 class="price">
          <price-format
            :price="formatPrice(totalPrice)"
          />
        </h4>
      </div>

      <slot
        name="confirm">
      </slot>

      <div v-if="discountedPrice > 0" class="discounted-price success-msg mt-20">
        <span class="">{{ $t('ship.ttl') }} </span>
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
  import productPriceHelper from "~/mixin/productPriceHelper"
  import AjaxButton from '~/components/AjaxButton'
  import PriceFormat from "~/components/PriceFormat";

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
      hasShipping: {
        type: Boolean,
        default: false
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
          - this.cartPrice.voucher
          + this.cartPrice.tax
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
          tax: 0,
          shippingPrice: 0,
          voucher: 0
        }

        let shippingId = []

        if(this.checkedProduct?.length) {
          this.checkedProduct?.forEach((curr) => {
            const currentShippingId = curr?.shipping_place?.shipping_rule?.id;
            const shippingIdExists = shippingId[currentShippingId]

            if(this.hasShipping) {
              if (curr?.shipping_option ||
                (!curr?.shipping_option && !shippingIdExists)) {

                if (parseInt(curr.shipping_type) === 1) {
                  cp.shippingPrice += parseInt(curr?.shipping_place?.price || 0)
                } else if (parseInt(curr.shipping_type) === 2 && this.hasShipping) {
                  cp.shippingPrice += parseInt(curr?.shipping_place?.pickup_price || 0)
                }
                shippingId[currentShippingId] = curr.shipping_type
              }
            }

            const qty = parseInt(curr?.quantity || 0)
            const bundleDeal = curr?.flash_product?.bundle_deal
            cp.totalItems += qty

            const currentInventoryPrice = this.currentInventoryPriceCalc(curr?.updated_inventory, curr?.flash_product)

            const bundleOffer = (bundleDeal?.buy <= qty) ? (currentInventoryPrice * parseInt(bundleDeal?.free || 0)) : 0
            cp.totalPriceWithOffer += qty * currentInventoryPrice - bundleOffer
            const taxRule = curr?.flash_product?.tax_rules
            cp.tax += qty * this.priceByType(currentInventoryPrice, taxRule?.price || 0, taxRule?.type)
            cp.totalPrice += qty * currentInventoryPrice

            cp.priceBeforeOffer += this.sellPriceCalc(curr?.flash_product) * qty
          })
        }


        cp.voucher = this.voucherResult?.offered || 0

        this.$emit('calculated-price', cp)
        return cp
      },
      ...mapGetters('common', ['currencyIcon', 'setting']),
    },
    mixins: [util, productPriceHelper],
    methods: {},
    created() {
    },
    mounted() {
    }
  }
</script>



