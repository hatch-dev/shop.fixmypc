<template>
  <div>
    <transition name="fade" mode="out-in">
      <div
        class="spinner-wrapper flex"
        v-if="fetchingCartData || ajaxing"
      >
        <spinner
          :radius="100"
        />
      </div>
      <div v-else>
        <cart-product-tile
          v-for="(value) in cartProducts"
          :key="value.id"
          :cart="value"
          :checked="checked"
          :is-shipping="isShipping"
          :cart-shipping="cartShipping"
          :current-addresses="currentAddresses"
          :address="address"
          :error="dataFromObject(errorFromApi, value.id, null)"
          @cb-changed="cbChangedFn"
          @deleting="deleting"
          @quantity="valueChanged"
          @update-cart-shipping="updateCartShipping"
          @current-shipping="currentShipping"
        />
        <div class="card p-3 mb-3">
          <h6 class="bundle-list-title">Frequently Bought Together</h6>
          <bundle-carousel
            v-for="bundle in upsellBundles"
            :key="bundle.id"
            :bundle="bundle"
          />
        </div>
        <flash-discount/>
    </div>
    </transition>
  </div>
</template>

<script>
  import { mapGetters, mapActions } from 'vuex'
  import LazyImage from '~/components/LazyImage'
  import util from '~/mixin/util'
  import QuantityNav from '~/components/QuantityNav'
  import CartProductTile from "~/components/CartProductTile";
  import Spinner from "~/components/Spinner";

  export default {
    name: 'CartList',
    data() {
      return {
        bundleList: [],
        fetchingCartData: false,
        ajaxDeleting: 0,
      }
    },
    watch: {
    },
    props: {
      cartProducts: {
        type: Array
      },
      checked: {
        type: Array
      },
      cartShipping: {
        type: Object,
        default() {
          return null
        }
      },
      isShipping: {
        type: Boolean,
        default: false
      },
      ajaxing: {
        type: Boolean,
        default: false
      },
      currentAddresses: {
        type: Array,
        default(){
          return []
        }
      },
      errorFromApi: {
        type: Object,
        default(){
          return null
        }
      },
      address: {
        type: Object,
        default(){
          return null
        }
      },
    },
    components: {
      Spinner,
      CartProductTile,
      QuantityNav,
      LazyImage
    },
    computed: {
      upsellBundles() {
        if (!this.cartProducts?.length) return []
        const map = new Map()
        this.cartProducts.forEach(item => {
          if (!item.upsell_products?.length) return
          const key = item.upsell_products
            .map(p => p.id)
            .sort()
            .join('-')

          if (!map.has(key)) {
            map.set(key, {
              id: key,
              title: "Frequently Bought Together",
              products: item.upsell_products
            })
          }
        });
        return Array.from(map.values())
      },
      ...mapGetters('language', ['langCode']),
    },
    mixins: [util],
    methods: {
      currentShipping(evt){
        this.$emit('current-shipping', evt)
      },
      updateCartShipping(){
        this.$emit('shipping-changed', this.cartShipping)
      },
      async valueChanged({bundleDeal, product, inventory, direction}){
        try {
          await this.cartAction({
            payload: {
              apiVal:{
                user_token: await this.getUserToken(),
                product_id: product.id,
                inventory_id: inventory.id,
                quantity: direction
              },
              storeVal:{
                product: product,
                inventory: inventory,
                quantity: direction,
                selected: '1'
              },
              isBundle: !!bundleDeal
            },
            lang: this.langCode
          })
        }catch (e) {
          this.$nuxt.error(e)
        }
      },
      async deleting(evt){

        try {
          await this.cartDelete({
            payload: evt,
            lang: this.langCode
          })
          this.$emit('cart-changed', true);
        }catch (e) {
          this.$nuxt.error(e)
        }
      },
      async cbChangedFn(evt){
        const cbChecked = this.checked
        if(evt.checked.target.checked) {
          cbChecked.push(evt.id)
        } else {
          const index = this.checked.findIndex((obj)=>{
            return parseInt(obj) === parseInt(evt.id)
          })
          delete cbChecked[index]
        }

        await this.cartChanged({
          lang: this.langCode,
          payload: {
            checked: cbChecked
          }
        })
        this.$emit('cart-changed', true)
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('cart', ['cartDelete', 'cartAction', 'cartChanged'])
    },
    created() {
    },
    async mounted() {
    }
  }
</script>
<style scoped>
.bundle-list-title{
  font-weight: 500;
  color: #130E2B;
  font-size: 16px;
}
</style>



