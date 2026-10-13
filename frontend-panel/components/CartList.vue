<template>
  <div>
    <transition name="fade" mode="out-in">
      <!--
        Basket lines, drawn at the size the real ones will be.

        `ajaxing` is the basket fetch (cart.vue raises it in fetchingData),
        not a button press, so replacing the list while it runs is right -
        and unlike the spinner it used to show, this keeps the page the
        height it is about to be, so the totals panel beside it does not
        slide up and back down on every refresh.
      -->
      <div
        class="cart-skeleton"
        v-if="fetchingCartData || ajaxing"
      >
        <skeleton-card
          v-for="index in skeletonLines"
          :key="`sk-cart-${index}`"
          class="cart-skeleton-line"
          horizontal
          :thumb-width="120"
          image-height="120px"
          :lines="3"
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
          @saved-for-later="$emit('saved-for-later')"
          @quantity="valueChanged"
          @update-cart-shipping="updateCartShipping"
          @current-shipping="currentShipping"
        />
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
      CartProductTile,
      QuantityNav,
      LazyImage
    },
    computed: {
      ...mapGetters('language', ['langCode']),
      /*
       * How many placeholder lines to draw.
       *
       * A refresh of a basket we already know the size of draws that many, so
       * the list does not change height at all while it reloads. Only the very
       * first load has to guess, and two lines is a smaller wrong guess than a
       * full-height block of them.
       */
      skeletonLines() {
        const known = this.cartProducts ? this.cartProducts.length : 0
        return Math.min(known || 2, 6)
      },
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
/* Same rhythm as the real basket lines, so the swap does not nudge the page. */
.cart-skeleton-line + .cart-skeleton-line { margin-top: 14px; }
</style>



