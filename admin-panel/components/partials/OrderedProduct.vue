<template>
  <tr>
    <td>
      <div class="dply-felx j-left">
        <router-link
          :to="`/products/${product.id}`"
        >
          <lazy-image
            :data-src="getThumbImageURL(productImage)"
            :alt="orderedProduct.product.title"
            class="mr-20"
          />
        </router-link>

        <div>
          <router-link
            :to="`/products/${product.id}`"
          >
            <span class="mn-w-200x">{{ product.title }}</span>
          </router-link>

          <p v-if="orderedProduct.updated_inventory.sku" class="mt-10">SKU: {{ orderedProduct.updated_inventory.sku }}</p>

          <span class="mt-10">
            <span
              v-for="i in currentAttribute"
              class="mr-15"
            >
              <b class="mr-10">{{ i[0] }}:</b>{{ i[1] }}
            </span>
          </span>
        </div>

      </div>


    </td>
    <td>{{ shippingType[orderedProduct.shipping_type] }}</td>
    <td>{{ priceFormatting(orderedProduct.shipping_price) }}</td>
    <td>{{ orderedProduct.quantity }}</td>
    <td>{{ priceFormatting((orderedProduct.selling * orderedProduct.bundle_offer) || 0) }}</td>
    <td>{{ priceFormatting(orderedProduct.selling) }}</td>
    <td>{{ priceFormatting(orderedProduct.selling * orderedProduct.quantity) }}</td>
  </tr>
</template>

<script>
  import {mapGetters} from "vuex"
  import LazyImage from "../LazyImage";
  import util from "../../mixin/util";
  import productImageHelper from "../../mixin/productImageHelper";

  export default {
    name: 'OrderedProduct',
    data() {
      return {
      }
    },
    props: {
      orderedProduct: {
        type: Object
      },
    },
    watch:{

    },
    components: {LazyImage},
    mixins: [util, productImageHelper],
    computed: {
      currentAttribute() {
        return this.inventoryAttributes?.map(i => {
          return [i?.attribute_value?.attribute?.title, i?.attribute_value?.title]
        })
      },
      inventoryAttributes() {
        return this.orderedProduct.updated_inventory?.inventory_attributes
      },
      product() {
        return this.orderedProduct?.product
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {

    },
    mounted() {

    }
  }
</script>
