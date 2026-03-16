<template>
  <pop-over
    :no-header="true"
    @close="$emit('close')"
    elem-id="pos-ordered-pop-over"
    :layer="true"
    class="pos-invoice-wrap"
  >
    <template v-slot:content>

      <div  class="pos-invoice" :style="{width: `${posSetting.width}px`}" >
        <div class="invoice-inner sb" ref="contentToPrint">
          <div class="invoice-head">
            <img
              v-if="posSetting.image"
              :src="getImageURL(posSetting.image)"
            >
            <p class="f-9">{{ posSetting.header_text }}</p>
            <p class="f-9">{{ posSetting.address }}</p>
          </div>

          <div class="order-info">
            <p>{{ $t('category.date') }}: {{ order.created }}</p>
            <p>{{ $t('ship.oi') }}: {{ orderId }}</p>
            <p>{{ $t('ship.pmt') }}: {{ posPaymentDrop[order.pos_order.payment_method].title }}</p>
            <p v-if="order.pos_order">{{$t('fSale.user')}}: {{ order.pos_order.admin.name }}</p>
            <p>{{ $t('ship.cs') }}:
              <span v-if="order.user">{{ order.user.name }}</span>
              <span v-else>{{ $t('ship.wc') }}</span>
            </p>

          </div>

          <div class="cart-tile-wrap">
            <pos-ordered-product
              v-for="(item, index) in order.ordered_products"
              :key="index"
              :item="item"
            />
          </div>

          <div class="invoice-footer">
            <p>
              <span>{{ $t('fSale.sTotal') }}</span>
              <span>{{ priceFormatting(subtotalPrice) }}</span>
            </p>
            <p>
              <span>{{ $t('fSale.sCost') }}</span>
              <span
                v-if="isFreeShipping"
              >
          {{ $t('ship.fre') }}
        </span>
              <span
                v-else
              >{{ priceFormatting(shippingPrice) }}</span>
            </p>
            <p v-if="bundleOffer">
              <span>{{ $t('fSale.bOffer') }}</span>
              <span>{{ priceFormatting(bundleOffer) }}</span>
            </p>
            <p v-if="voucherPrice">
              <span>{{ $t('fSale.voucher') }}</span>
              <span>{{priceFormatting(voucherPricen)}}</span>
            </p>
            <p
              v-if="taxPrice"
            >
              <span>{{ $t('fSale.tax') }}</span>
              <span>{{ priceFormatting(taxPrice) }}</span>
            </p>
            <p>
              <span>{{ $t('fSale.total') }}</span>
              <span>{{ priceFormatting(totalPrice) }}</span>
            </p>
          </div>

          <div class="invoice-info">
            <p class="f-9">
              {{ posSetting.footer_text }}
            </p>
          </div>
        </div>


        <div class="print-btn">
          <button class="primary-btn w-100" @click="printPage">
            {{$t('setting.pi')}}
          </button>
        </div>


      </div>

    </template>
  </pop-over>
</template>

<script>
  import {mapGetters} from 'vuex'
  import PopOver from "../PopOver";
  import PriceFormat from "./PriceFormat";
  import PosOrderedProduct from "./PosOrderedProduct";
  import CheckoutRight from "./CheckoutRight";
  import util from "../../mixin/util";
  import productPriceHelper from "../../mixin/productPriceHelper";
  export default {
    name: "PosOrdered",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
      }
    },
    components: {
      CheckoutRight,
      PosOrderedProduct,
      PriceFormat,
      PopOver
    },
    props: {
      order: {
        type: Object
      },
    },
    watch: {
    },
    mixins: [util, productPriceHelper],
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      totalPrice(){
        return this.order?.calculated.total_price
      },
      voucherPrice(){
        return this.order?.calculated.voucher_price
      },
      bundleOffer(){
        return this.order?.calculated.bundle_offer
      },
      shippingPrice(){
        return this.order?.calculated.shipping_price
      },
      orderId(){
        return this.order?.order
      },
      taxPrice(){
        return this.order?.calculated.tax
      },
      subtotalPrice(){
        return this.order?.calculated.subtotal
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('admin', ['posSetting']),
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      printPage() {
        let content = this.$refs.contentToPrint.outerHTML;
        let printWindow = window.open('', '_blank');
        printWindow.document.open();
        printWindow.document.write(`
        <html>
          <head>
            <title>${this.$t('ship.pr')}</title>
            <style>
             .invoice-inner{
                width: ${this.posSetting.width}px;
                margin: 0 auto;
             }

             .order-info{
                display: flex;
                justify-content: space-between;
              column-gap: 10px;
                flex-wrap: wrap;
              margin 20px 0 0;
              border-top: 1px dashed #333;
              border-bottom: 1px dashed #333;
              padding 15px 0;
              }

                .cart-tile-wrap{
    padding 15px 0;
    border-bottom: 1px dashed #333;
  }
.invoice-footer{
  border-bottom: 1px dashed #333;
  padding 15px 0;
}
.invoice-footer p{
  display: flex;
  justify-content: space-between;
}
.invoice-info{
  padding: 20px 20px 0;
  text-align: center;
}
.invoice-head{
  padding 20px 0 0;
  text-align:center;
}

.invoice-head img{
  max-height: 30px;
  width auto;
  margin: 0 auto;
}


.dply-felx{
gap: 5px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

            </style>
          </head>
          <body>${content}</body>
        </html>
      `);
        printWindow.document.close();
        printWindow.print();
      }
    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
