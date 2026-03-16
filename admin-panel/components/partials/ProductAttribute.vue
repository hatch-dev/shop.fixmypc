<template>
  <div>
    <div>
      <div class="img-wrap mb-15">
        <lazy-image
          :data-src="getThumbImageURL(product.image)"
          :alt="product.title"
        />
      </div>

      <nuxt-link
        :to="`/products/${product.id}`"
        :title="product.title"
      >
        <h5 class="ellipsis-2">{{ product.title }}</h5>
      </nuxt-link>

      <p class="error">{{cartError}}</p>
    </div>

    <div class="mtb-10 product-attribute">
      <div
        v-for="(value, index) in Object.values(productAttributes)"
        :key="index"
        class="dply-felx gap-10 j-left mb-10 f-wrap"
      >
        <h5 class="attr-title ellipsis" :title="value.title">{{ value.title }}</h5>
        <label
          v-for="(av, ai) in Object.values(value.values)"
          :key="`${index}-${ai}`"
          class="rd-container rd-attr"
        >
          <input
            type="radio"
            :name="value.id"
            class=""
            :value="av.id"

            v-model="clickedAttributes[value.id]"
          >
          <span class="rd-checkmark" :title="av.title"></span>

          <span class="input-content ellipsis" :title="av.title">{{ av.title }}</span>
        </label>
      </div>

      <div class="f-wrap dply-felx j-left gap-10">
        <quantity-nav
          class="mt-15 mb-10"
          :quantity="parseInt(quantity)"
          :product-inventory="productInventory"
          @value-changed="quantity = $event"
        />

        <ajax-button
          class="primary-btn"
          type="button"
          onlyIcon="add-to-cart"
          :fetching-data="adding"
          @clicked="addToCart"
        />
      </div>

    </div>
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton";
  import QuantityNav from "~/components/partials/QuantityNav";
  import LazyImage from "~/components/LazyImage";
  import util from "~/mixin/util";

  export default {
    name: 'ProductAttribute',
    data() {
      return {
        clickedAttributes: {},
        cartError: null,
        quantity: 1,
        adding: false
      }
    },
    props: {
      product: {
        type: Object,
        default: null
      }
    },
    mixins: [util],
    components: {
      LazyImage,
      QuantityNav,
      AjaxButton
    },
    computed: {
      productInventory() {
        return this.product?.product_inventories
      },
      productAttributes() {
        const attr = {}
        this.productInventory.forEach(i => {
          i.inventory_attributes.forEach(j => {

            const av = j.attribute_value

            if (!attr[av.attribute_id]) {
              this.clickedAttributes[av.attribute_id] = null

              attr[av.attribute_id] = {
                id: av.attribute_id,
                title: av.attribute?.title,
                values: {}
              }
            }

            if (!attr[av.attribute_id]?.values[av?.id]) {
              attr[av.attribute_id].values[av?.id] = {
                title: av?.title,
                id: av?.id
              }
            }
          })
        })
        return attr
      },
    },
    methods: {
      checkArrayInObject(array, object) {
        for (const key in object) {

          if (object.hasOwnProperty(key) && object[key].length === array.length) {
            const objArray = object[key];
            if (array.every(value => objArray.includes(value))) {
              return key;
            }
          }
        }
        return null;
      },
      async cartAction(inventoryId) {
        try {
          this.adding = true
          await this.setRequest({
            params: {
              product_id: this.product?.id,
              inventory_id: inventoryId,
              quantity: this.quantity
            },
            api: 'setCart'
          })

          this.adding = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async addToCart() {
        this.cartError = ''
        const selectedValues = Object.values(this.clickedAttributes)?.filter(i => {
          return i
        })
        const invArr = {}
        this.productInventory?.forEach(i => {
          if (!invArr[i.id]) {
            invArr[i.id] = []
          }

          i.inventory_attributes?.map(j => {
            invArr[i.id].push(j.attribute_value_id)
          })
        })

        const inventoryId = this.checkArrayInObject(selectedValues, invArr)

        if (!inventoryId) {
          this.cartError = 'Please choose all attributes'
          return false;
        }

        const selectedInventory = this.productInventory.find(i => {
          return i.id === parseInt(inventoryId)
        })

        if (selectedInventory?.quantity < this.quantity) {
          this.cartError = 'Max quantity can be ' + selectedInventory.quantity
          return false;
        }

        await this.cartAction(inventoryId)

        this.$emit('cart-added')
      },
      ...mapActions('common', ['setRequest'])
    },
    beforeCreate() {
    },
    destroyed() {
    },
    created() {
    },
    async mounted() {
    }
  }
</script>
