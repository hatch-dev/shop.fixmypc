<template>
  <div
    class="cart-item"
  >
      <div class="img-wrap">

        <lazy-image
          :data-src="getThumbImageURL(productImage)"
        />

      </div>

      <div>
        <div class="cart-content">
          <nuxt-link
            :to="`/products/${product.id}`"
            :title="product.title"
          >
            <h5 class="ellipsis-2">{{ product.title }}</h5>
          </nuxt-link>

          <div class="dply-felx gap-10 j-left mb-10">
            <div
              v-for="(iv, i) in inventoryAttributes"
              :key="`${i}-${i}`"
            >
              <span><b>{{ iv.attribute_value.attribute.title }}:</b> {{ iv.attribute_value.title }}</span>
            </div>
          </div>

          <span
            v-if="hasBundleDeal"
            class="ellipsis-1 mr-10"
          >
            <span class="bold mr-5">{{ $t('fSale.bOffer') }}: </span>
              {{ bundleDeal.title }}
            </span>
        </div>

        <div class="dply-felx gap-10 f-wrap j-left">
          <quantity-nav
            :readonly="true"
            class="mt-15 mb-10"
            :quantity="parseInt(quantity)"
            :product-inventory="[productInventory]"
            @value-changed="qtyChanged"
          />
          <ajax-button
            class="delete-btn dply-felx xs"
            onlyIcon="delete-icon"
            type="button"
            color="primary"
            loadingText=""
            :fetching-data="deleting"
            @clicked="deleteCart"
          />
        </div>

        <template v-if="shippingOption">
          <p v-if="hasAddress && selectedShipping && !currentShipRule" class="error">{{ noShipMessage }}</p>
          <div
            v-else-if="hasAddress && selectedShipping && currentShipRule"
            class="dply-felx gap-10 f-wrap j-left"
          >
            <label class="mt-15 mb-0 cp rd-container color-lite">
              <input
                class="mt-5 cp"
                type="radio"
                :value="shippingTypeIn.location"
                :name="`shipping_${cartId}_type`"
                v-model="cartWithShipping[cartId].shipping_type"
                @change="updateCartShipping"
              >
              <span class="rd-checkmark"></span>
              {{ $t('ship.fl') }}
              (<span v-if="isFreeShipping" class="color-free">{{ $t('invent.fre') }}</span>
              <price-format
                v-else
                :price="parseFloat(currentShipRule.price)"
              />)
            </label>
            <label
              v-if="parseInt(currentShipRule.pickup_point) === 1"
              class="cp rd-container color-lite"
            >
              <input
                class="mt-5 cp"
                type="radio"
                :value="shippingTypeIn.pickup"
                :name="`shipping_${cartId}_type`"
                v-model="cartWithShipping[cartId].shipping_type"
                @change="updateCartShipping"
              >
              <span class="rd-checkmark"></span>
              {{ $t('ship.fpp') }}
              (<span v-if="isFreePickup" class="color-free">{{ $t('invent.fre') }}</span>
              <price-format
                v-else
                :price="parseFloat(currentShipRule.pickup_price)"
              />)
            </label>
          </div>
        </template>


      </div>

      <div class="mt-sm-10 mn-w-50x right-text">
        <h5 class="price inl-b-sm">
          <price-format
            :price="parseFloat(productPrice)"
          />
        </h5>

        <p class="inl-b-sm">x {{ productQuantity }}</p>
        <p class="inl-b-sm" v-if="hasBundleDeal">(-) x {{ bundleDeal.free }}</p>

      </div>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import {mapGetters, mapActions} from 'vuex'
  import LazyImage from "~/components/LazyImage";
  import SearchDropdown from "~/components/SearchDropdown";
  import Spinner from "~/components/Spinner";
  import AjaxButton from "~/components/AjaxButton";
  import QuantityNav from "./QuantityNav";
  import PriceFormat from "./PriceFormat";
  import productImageHelper from "../../mixin/productImageHelper";

  export default {
    name: "CartItem",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        quantity: 0,
        deleting: false
      }
    },
    props: {
      hasAddress: {
        type: Boolean,
        default: false
      },
      selectedShipping: {
        type: Object,
        default: null
      },
      singleShippings: {
        type: Object,
        default: null
      },
      cartWithShipping: {
        type: Object,
        default: null
      },
      cartItem: {
        type: Object,
        default: null
      }
    },
    watch:{
      cartItem(value){
        this.quantity = value?.quantity
      }
    },
    components: {
      PriceFormat,
      QuantityNav,
      AjaxButton,
      Spinner,
      SearchDropdown,
      LazyImage
    },
    mixins: [util, productPriceHelper, productImageHelper],
    computed: {
      noShipMessage() {
        const state = this.selectedShipping?.stateTitle ? `${this.selectedShipping?.stateTitle},` : ''
        return this.$t('ship.nsm', {state: state, country: this.selectedShipping?.countryTitle})
      },
      cartId() {
        return this.cartItem?.id
      },
      isFreePickup() {
        return !(parseFloat(this.currentShipRule?.pickup_price) > 0)
      },
      isFreeShipping() {
        return !(parseFloat(this.currentShipRule?.price) > 0)
      },
      shippingOption() {
        return this.cartWithShipping[this.cartItem?.id]?.shipping_option
      },
      currentShipRule() {
        let matched = null
        if (this.selectedShipping) {

          this.product?.shipping_rule?.shipping_places.forEach((obj) => {
            if (obj.country === this.selectedShipping.country) {
              if (obj.state === this.selectedShipping.state) {
                matched = obj
                return
              } else if (obj.state === 'ALL') {

                matched = obj
              }
            } else if (obj.country === 'ALL') {
              if (!matched) {
                matched = obj
              }
            }
          })
        }

        if(matched && !matched?.shipping_rule){
          matched = {...matched, ...{shipping_rule: this.product?.shipping_rule}}
        }


        return matched
      },
      productQuantity() {
        return parseInt(this.cartItem?.quantity)
      },
      hasBundleDeal() {
        return (this.productQuantity >= this.bundleDeal?.buy)
      },
      bundleDeal() {
        return this.product?.bundle_deal
      },
      product() {
        return this.cartItem?.flash_product
      },
      productInventory() {
        return this.cartItem?.updated_inventory
      },
      inventoryAttributes() {
        return this.productInventory?.inventory_attributes
      },
      ...mapGetters('admin', ['profile']),
    },
    methods: {
      async updateCartShipping(){

        Object.keys(this.cartWithShipping).forEach(i => {
          const curr = this.cartWithShipping[i]

          if(curr.shipping_rule === this.cartWithShipping[this.cartItem.id].shipping_rule && !curr.shipping_option){
            this.cartWithShipping[i].shipping_type = this.cartWithShipping[this.cartItem.id].shipping_type
          }
        })

        const data = await this.setRequest({
          params: {
            cart: this.cartWithShipping, selected_address: this.selectedShipping?.id
          },
          api: 'updateShipping'
        })

        this.$emit('shipping-changed', data)
      },
      async qtyChanged(evt){
        const direction = evt - this.quantity
        this.quantity = evt

        await this.setRequest({
          params: {
            inventory_id: this.productInventory.id,
            product_id: this.product?.id,
            admin_id: this.profile.id,
            quantity: direction
          },
          api: 'setCart'
        })

        this.$emit('quantity-changed')
      },
      async deleteCart() {
        if (confirm(this.$t('admin.dltMsg'))) {
          try {
            this.loading = true
            await this.deleteData({params: this.cartItem.id, api: 'deleteCart' })
            this.$emit('deleted', this.cartItem.id)

            this.loading = false

          }catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      ...mapActions('common', ['deleteData', 'setRequest'])
    },
    mounted() {
      this.quantity = this.cartItem.quantity
    }
  }
</script>

<style scoped>

</style>
