<template>
    <div class="">
      <div  class="dply-felx sided align-start">
        <div>
          <p class="ellipsis-2">{{ product.title }}</p>

          <p class="dply-felx gap-10 j-left mtb-5">
            <span
              v-for="(iv, i) in inventoryAttributes"
              :key="`${i}-${i}`"
            >
              <span>{{ iv.attribute_value.attribute.title }}: {{ iv.attribute_value.title }}</span>
            </span>
          </p>


        </div>

        <div class="mt-sm-10 mn-w-50x right-text">
          <p class="price inl-b-sm">
            <price-format
              :price="parseFloat(productPrice)"
            />
          </p>

          <p class="inl-b-sm">x {{ productQuantity }}</p>
        </div>
      </div>
        <p
          v-if="bundleOffer"
          class="dply-felx sided"
        >
          <span class="mr-5">{{ $t('fSale.bOffer') }}: </span>
          <span class="inl-b-sm">(-) x {{ bundleOffer }}</span>
        </p>



    </div>

</template>

<script>

  import PopOver from "../PopOver";
  import PriceFormat from "./PriceFormat";
  import productPriceHelper from "../../mixin/productPriceHelper";
  export default {
    name: "PosOrderedProduct",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
      }
    },
    components: {
      PriceFormat,
      PopOver
    },
    props: {
      item: {
        type: Object
      },
    },
    watch: {

    },
    mixins: [productPriceHelper],
    computed: {
      productQuantity() {
        return parseInt(this.item?.quantity)
      },
      bundleOffer() {
        return this.item?.bundle_offer
      },
      product() {
        return this.item?.product
      },
      productInventory() {
        return this.item?.updated_inventory
      },
      inventoryAttributes() {
        return this.productInventory?.inventory_attributes
      },
    },
    methods: {


    },
    async mounted() {

    }
  }
</script>

<style scoped>

</style>
