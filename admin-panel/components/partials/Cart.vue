<template>
  <div class="cart-wrap">


    <error-formatter
      ref="errorFormatter"
    />

    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <search-dropdown
      ref="userSearch"
      :placeholder="$t('error.users')"
      list-api="getUsers"
      currentId="userSearch"
      :selected-text="selectedUser"
      title-key="name"
      :pre-item="{name: $t('ship.wc'), id: -1}"
      @clicked="userClicked"
    />
    <div v-if="cart" class="cart-tile-wrap mt-20">
      <CartItem
        v-for="(data, index) in cart"
        :key="index"
        :cart-item="data"
        :hasAddress="hasAddress && !!user"
        :selectedShipping="selectedShipping"
        :cartWithShipping="cartWithShipping"
        :singleShippings="singleShippings"
        @shipping-changed="shippingChanged"
        @quantity-changed="quantityChanged"
        @deleted="dataDeleted"
      />
    </div>

    <pos-shipping
      ref="posShipping"
      :selected-user="user"
      :address-list="addressList"
      @has-address="selectionChanged"
      @address-added="addressAdded"
      @selected-address="addressChanged"
      @address-removed="addressRemoved"
    />

    <pos-voucher
      ref="posVoucher"
      :calculated-price="calculatedPrice"
      :selected-user="user"
      @voucher-result="voucherResult = $event"
    />

    <pos-payment
      @pos-payment="posPaymentData = $event"
    />

    <checkout-right
      :checked-product="cart"
      :hasShipping="validShippingPlace"
      :voucher-result="voucherResult"
      @calculated-price="calculatedPrice = $event"
    >
      <template v-slot:confirm>
        <ajax-button
          class="primary-btn w-100"
          type="button"
          :fetching-data="formSubmitting"
          :loading-text="''"
          :text="$t('ship.con')"
          @clicked="goToNext"
        />
      </template>

    </checkout-right>


    <pos-ordered
      v-if="order"
      :order="order"
      @close="order = null"
    />
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import LazyImage from "~/components/LazyImage";
  import SearchDropdown from "~/components/SearchDropdown";
  import Spinner from "~/components/Spinner";
  import AjaxButton from "~/components/AjaxButton";
  import QuantityNav from "./QuantityNav";
  import CartItem from "./CartItem";
  import CheckoutRight from "./CheckoutRight";
  import PosShipping from "./PosShipping";
  import PosVoucher from "./PosVoucher";
  import PosPayment from "./PosPayment";
  import ErrorFormatter from "../ErrorFormatter";
  import PosOrdered from "./PosOrdered";

  export default {
    name: "Cart",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        order: null,
        loading: false,
        formSubmitting: false,
        hasAddress: false,
        preventGoing: true,
        calculatedPrice: null,
        posPaymentData: null,
        selectedShipping: null,
        selectedUser: this.$t('ship.wc'),
        voucherResult: null,
        cart: null,
        user: null,
        cartWithShipping: {},
        singleShippings: {},
      }
    },
    components: {
      PosOrdered,
      ErrorFormatter,
      PosPayment,
      PosVoucher,
      PosShipping,
      CheckoutRight,
      CartItem,
      QuantityNav,
      AjaxButton,
      Spinner,
      SearchDropdown,
      LazyImage
    },
    watch: {
    },
    mixins: [util],
    computed: {
      validShippingPlace(){
        return this.hasAddress && !!this.selectedShipping && Object.values(this.cartWithShipping).findIndex(i => {
          return i?.shipping_place === null
        }) === -1
      },
      addressList() {
        if(this.userAddresses[this.user?.id]?.length){
          this.selectedShipping = this.userAddresses[this.user?.id][0]
        }
        return this.userAddresses[this.user?.id] || []
      },
      ...mapGetters('user', ['userAddresses']),
      ...mapGetters('admin', ['profile']),
    },
    methods: {
      selectionChanged(evt){
        this.hasAddress = evt
        if(!this.hasAddress) {
          this.selectedShipping = null
        }
      },
      shippingChanged(evt){
        this.cart = evt
        this.generatingSingleShipping()
      },

      currentShipRule(shippingPlaces) {
        let matched = null
        if (this.selectedShipping) {

          shippingPlaces?.forEach((obj) => {
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
      generatingSingleShipping(){
        this.singleShippings = {}
        const cart = []
        this.cart.forEach(i => {
          const sr = i.flash_product.shipping_rule
          let shippingOption = true
          let shippingPlace = null

          if(this.selectedShipping) {
            shippingPlace = this.currentShipRule(sr.shipping_places)
          }

          if(parseInt(sr.single_price) === 1) {
            if(this.singleShippings[sr.id]){
              shippingOption = false
            } else {
              this.singleShippings[sr.id] = 1
            }
          }
          this.cartWithShipping = {...this.cartWithShipping, ...{
              [i.id] : {
                ...this.cartWithShipping[i.id],
                ...{shipping_option: shippingOption},
                ...{shipping_place: shippingPlace}
              }
            }}

          i = {...i, ...{shipping_option: shippingOption}}
          cart.push(i)
        })
        this.cart = cart
      },
      addressChanged(evt) {
        this.selectedShipping = evt
        this.generatingSingleShipping()
        this.updateCartShipping()
      },
      async goToNext() {
        this.formSubmitting = true
        try {
          const data = await this.setRequest({
            params: {
              ...{
                user_id: this.user?.id || '',
                voucher: this.voucherResult?.voucher || '',
                user_address_id: this.selectedShipping?.id || ''
              },
              time_zone: this.timeZone,
              ...this.posPaymentData
            },
            api: 'setPosOrder'
          })

          if(parseInt(data?.status) === 201){
            this.$refs["errorFormatter"]?.$el?.scrollIntoView({behavior: "smooth"})
          } else {
            await this.fetchingCartData()

            this.order = data
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },
      async updateCartShipping(){
        if(Object.keys(this.cartWithShipping)?.length) {
          const data = await this.setRequest({
            params: {
              cart: this.cartWithShipping, selected_address: this.selectedShipping?.id
            },
            api: 'updateShipping'
          })

          this.shippingChanged(data)
        }
      },
      async quantityChanged(){
        await this.fetchingCartData()
        this.$refs.posVoucher.checkVoucher()
      },
      dataDeleted(){
       this.fetchingCartData()
      },
      userClicked(evt) {
        this.user = evt
        this.fetchUserAddress(this.user)
        this.$refs.posShipping.userChanged()
        this.$refs.posVoucher.emptyError()
        this.hasAddress = false
        this.selectedShipping = null
        this.generatingSingleShipping()
        this.updateCartShipping()
      },
      addressRemoved(address) {
        this.deleteUserAddress({user: this.user.id, addressId: address.id})
      },
      addressAdded(address) {

        this.selectedUser = address?.user?.name ||  address?.user?.email


        this.userClicked(address?.user)

        this.updateUserAddress({user: this.user?.id, address})
      },
      async fetchUserAddress(user){
        try {
          if(user?.id === -1 || !user?.id || this.addressList?.length){
            return
          }

          const data = await this.getRequest({
            params: {
              user_id: user?.id
            },
            api: 'getUserAddresses'
          })

          await this.setUserAddresses({user: this.user.id, addresses: data?.data})

        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async fetchingCartData() {
        try {
          this.loading = true
          const temp = await this.getRequest({
            params: {
              admin_id: this.profile.id
            },
            api: 'getCart'
          })

          this.cart = temp.map(item => {

            this.cartWithShipping[item.id] = {
              cart: item.id,
              shipping_rule: item.flash_product.shipping_rule.id,
              shipping_type: item?.shipping_type ||this.shippingTypeIn.location
            }

            return item
          });

          this.generatingSingleShipping()

          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      ...mapActions('user', ['setUserAddresses', 'deleteUserAddress', 'updateUserAddress']),
      ...mapActions('common', ['getRequest', 'setRequest']),
      ...mapActions('ui', ['setToastMessage'])
    },
    mounted() {

      this.posPaymentData = {
        payment_method: this.posPaymentIn.CASH,
        offline_payment_method: '',
        offline_trans_id: '',
        offline_payment_proof: ''
      }

      this.fetchingCartData()
    }
  }
</script>

<style scoped>

</style>
