<template>
  <!-- <div class="container-fluid mtb-20 mtb-sm-15"> -->
    <!-- <div class="product-detail checkout-detail"> -->

      <div>
        <navigation-step /> 
        <div class="container-fluid my-5">
          <div class="row g-4">
            <div class="col-lg-8">
              <!-- <div
                class="detail-left p-20 p-sm-15 pb-10 area mr-20 mr-sm mb-sm-15 "
              >
                <h5 class="mb-20">{{ $t('date.dad') }}</h5>

                <div class="flex align-start gap-15 start">

                  <transition
                    name="fade"
                    mode="out-in"
                  >
                    <div
                      class="spinner-wrapper flex layer-white"
                      v-if="loading"
                    >
                      <spinner
                        :radius="100"
                      />
                    </div>
                  </transition>

                  <div class="address-wrapper">
                    <user-address
                      ref="shippingAddress"
                      :has-radio="true"
                      @editing="editAddress"
                      @selected-address="selectedCurrentAddress = $event"
                      @add-address="addressPopup = true"
                    />
                  </div>

                  <form
                    class="address-form"
                    @submit.prevent="savingAddressData"
                  >
                    <pop-over
                      v-if="addressPopup"
                      :title="$t('filter.address')"
                      @close="closeAddressPopup"
                      elem-id="shipping-address-pop-over"
                      :layer="true"
                    >
                      <template
                        v-slot:content
                      >
                        <div class="flex block-xxs gap-15">
                          <div
                            class="input-wrap flex-1"
                            :class="{invalid: !addressData.name && hasAddressErrors}"
                          >
                            <label>
                              {{ $t('addressPopup.name') }}
                            </label>
                            <input
                              type="text"
                              v-model="addressData.name"
                            />
                            <span
                              class="error"
                              v-if="!addressData.name && hasAddressErrors"
                            >
                            {{ $t('addressPopup.isRequired', {type: $t('addressPopup.name')}) }}
                          </span>
                          </div>

                          <div
                            class="input-wrap flex-1"
                            :class="{invalid: !emailValid && hasAddressErrors}"
                          >
                            <label>
                              {{ $t('addressPopup.email') }}
                            </label>
                            <div class="icon-input">
                              <i
                                class="icon email-icon"
                              />
                              <input
                                type="text"
                                :placeholder="$t('contact.your', { type: $t('contact.email') })"
                                v-model.trim="addressData.email"
                              >
                            </div>

                            <span
                              class="error"
                              v-if="!addressData.email && hasAddressErrors"
                            >
                              {{ $t('addressPopup.isRequired', {type: $t('addressPopup.email') }) }}
                            </span>
                            <span
                              class="error"
                              v-else-if="invalidEmail && hasAddressErrors"
                            >
                              {{ $t('contact.invalidEmail') }}
                            </span>
                          </div>
                        </div>

                        <div class="flex block-xxs gap-15">
                          <div
                            v-if="phoneList"
                            class="input-wrap flex-1"
                            :class="{invalid: !numberValid && hasAddressErrors}"
                          >
                            <label>
                              {{ $t('addressPopup.phone') }}
                            </label>
                            <div class="input-text">
                          <span>
                            {{ phoneList[addressData.country] }}
                          </span>
                              <input
                                type="text"
                                v-model="addressData.phone"
                              />
                            </div>
                            <span
                              class="error"
                              v-if="!addressData.phone && hasAddressErrors"
                            >
                              {{ $t('addressPopup.isRequired', {type: $t('addressPopup.phone')}) }}
                            </span>

                            <span
                              class="error"
                              v-else-if="invalidNumber && hasAddressErrors"
                            >
                              {{ $t('invent.in') }}
                            </span>
                          </div>
                        </div>

                        <div
                          class="input-wrap"
                          :class="{invalid: !addressData.address_1 && hasAddressErrors}"
                        >
                          <label>
                            {{ $t('addressPopup.address') }}
                          </label>
                          <input
                            class="mb-10"
                            type="text"
                            v-model="addressData.address_1"
                            :placeholder="$t('addressPopup.addressPlaceholder')"
                          />
                          <input
                            type="text"
                            v-model="addressData.address_2"
                            :placeholder="$t('addressPopup.address2Placeholder')"
                          />
                          <span
                            class="error"
                            v-if="!addressData.address_1 && hasAddressErrors"
                          >
                          {{ $t('addressPopup.isRequired', {type: $t('addressPopup.address')}) }}
                        </span>
                        </div>

                        <div class="flex block-xxs gap-15 sided">
                          <div
                            class="input-wrap flex-1"
                            :class="{invalid: !addressData.city && hasAddressErrors}"
                          >
                            <label>
                              {{ $t('addressPopup.city') }}
                            </label>
                            <input
                              type="text"
                              v-model="addressData.city"
                            />
                            <span
                              class="error"
                              v-if="!addressData.city && hasAddressErrors"
                            >
                            {{ $t('addressPopup.isRequired', {type: $t('addressPopup.city')}) }}
                          </span>
                          </div>

                          <div
                            v-if="Object.keys(states).length"
                            class="input-wrap flex-1"
                          >
                            <label>
                              {{ $t('addressPopup.state') }}
                            </label>
                            <dropdown
                              :selected-key="addressData.state"
                              position="right"
                              :position-fixed="false"
                              :options="states"
                              key-name="name"
                              @clicked="selectState"
                            />
                          </div>
                        </div>

                        <div class="flex block-xxs gap-15 sided">
                          <div
                            class="input-wrap flex-1"
                            :class="{invalid: !addressData.zip && hasAddressErrors}"
                          >
                            <label>
                              {{ $t('addressPopup.zipCode') }}
                            </label>
                            <input
                              type="text"
                              v-model="addressData.zip"
                            />
                            <span
                              class="error"
                              v-if="!addressData.zip && hasAddressErrors"
                            >
                            {{ $t('addressPopup.isRequired', {type: $t('addressPopup.zipCode')}) }}
                          </span>
                          </div>

                          <div
                            v-if="countryList"
                            class="input-wrap flex-1"
                          >
                            <label>
                              {{ $t('addressPopup.country') }}
                            </label>
                            <dropdown
                              :selected-key="addressData.country"
                              :options="countryList"
                              :position-fixed="false"
                              key-name="name"
                              :searching="true"
                              @clicked="selectCountry"
                            />
                          </div>
                        </div>

                        <div class="input-wrap mb-0">
                          <label>
                            {{ $t('shipping.instruction') }}
                          </label>
                          <textarea
                            v-model="addressData.delivery_instruction"
                          />
                        </div>

                      </template>

                      <template v-slot:pop-footer>
                        <div class="flex sided mlr-0 gap-10">
                          <button
                            aria-label="submit"
                            class="outline-btn plr-30 plr-sm-15"
                            @click.prevent="clearData"
                          >
                            {{ $t('addressPopup.cancel') }}
                          </button>
                          <ajax-button
                            class="primary-btn plr-30 plr-sm-15"
                            :fetching-data="submittingAddressData"
                            :loading-text="$t('addressPopup.saving')"
                            :text=" $t('addressPopup.thisAddress', {type: editing > 0 ? $t('addressPopup.update') : $t('addressPopup.save')})"
                          />
                        </div>
                      </template>

                    </pop-over>

                  </form>
                </div>


                <div v-if="selectedSippingPlace" class="pickup-addr">
                  <h5 class="mt-30 mb-10">{{ $t('date.sl') }}</h5>
                  <div class="flex start">
                    <p class="success-msg" v-html="selectedSippingPlace"/>
                  </div>

                </div>

                <div class="mt-30">
                  <h5 class="mb-10">{{ $t('date.os') }}</h5>


                  
                </div>
              </div> -->
              <div class="delivery-box mb-3">
                <div class="delivery-header">
                  <div class="delivery-left">
                    <div class="check-circle">✓</div>
                    <div>
                      <div class="delivery-title mb-2">Delivery or Collection?</div>
                      <div class="delivery-sub">
                        For Delivery <br />
                        <strong>
                          <div v-for="(item, index) in productSummary" :key="index">
                            {{ item.text }}
                          </div>
                        </strong>
                      </div>
                    </div>
                  </div>
                  <button class="delivery-edit-btn" @click="goToCart">Edit</button>
                </div>
                <div class="delivery-options">
                  <div
                    :class="['delivery-option', { active: deliveryType === 'delivery' }]"
                    @click="setDeliveryType('delivery')"
                  >
                    <div class="icon-circle">
                      <i class="fa-solid fa-file-pen"></i>
                    </div>
                    <span class="delivery-type">Send by Post 
                      <small>
                        ({{ deliveryPrice > 0 ? '€' + formattedDeliveryPrice : 'Free' }})
                      </small>
                    </span>
                  </div>

                  <div
                    :class="['delivery-option', { active: deliveryType === 'collection' }]"
                    @click="setDeliveryType('collection')"
                  >
                    <div class="icon-circle">
                      <i class="fa-solid fa-file-pen"></i>
                    </div>
                    <span class="delivery-type">Collection Store <small>(Free)</small></span>
                  </div>
                </div>
              </div>
              <div class="accordion mt-3 mb-3" id="deliveryAccordion">
                <div class="accordion-item ">
                  <h5 class="accordion-header"  id="headingAddress">
                    <button type="button" class="accordion-button" data-bs-toggle="collapse" data-bs-target="#collapseAddress" aria-expanded="true" aria-controls="collapseAddress">
                      <span class="delivery-heading">Delivery Address</span>
                    </button>
                  </h5>
                  <div id="collapseAddress" class="accordion-collapse collapse show" aria-labelledby="headingAddress" data-bs-parent="#deliveryAccordion">
                    <div class="container mt-2">
                      <div class="container ">
                        <div class="row g-3">
                          <user-address
                            ref="shippingAddress"
                            :has-radio="true"
                            @editing="editAddress"
                            @selected-address="selectedCurrentAddress = $event"
                            @add-address="addressPopup = true"
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <!-- <cart-list
                :error-from-api="errorFromApi"
                :cart-products="checkedProduct"
                :cart-shipping="cartShipping"
                :checked="checked"
                :current-addresses="currentAddresses"
                :is-shipping="true"
                :address="selectedCurrentAddress"
                @shipping-changed="shippingChanged"
                @cart-changed="cartChanged"
                @current-shipping="currentShipping"
              /> -->
              <div class="accordion mt-3 mb-3" id="paymentAccordion">
              <div class="accordion-item ">
                <h5 class="accordion-header"  id="headingPayment">
                  <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#collapsePayment" aria-expanded="false" aria-controls="collapsePayment">
                    <span class="delivery-heading">Payment Method</span>
                  </button>
                </h5>
                <div id="collapsePayment" class="accordion-collapse collapse" aria-labelledby="headingPayment" data-bs-parent="#paymentAccordion">
                  <div class="container mt-2">
                    <div class="container ">
                      <div class="row g-3">
                        <payment-gateways
                          ref="paymentGateways"
                          :total-price="totalPrice"
                          :voucher="voucherResult"
                          :hide-confirm-btn="true"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            </div>
            <checkout-right
              route-link="checkout"
              :checked-product="checkedProduct"
              :btn-text="$t('date.ptp')"
              @calculated-price="priceCalculated"
              :submitting="checkingOut"
              :disabled="fetchingAddressData || Object.keys(cartShipping).length === 0 || !selectedCurrentAddress"
              @go-next="goToCheckout"
            />
          </div>
        </div>
      </div>
    <!-- </div> -->

  <!-- </div> -->
</template>


<script>
  import CartList from '~/components/CartList'
  import AjaxButton from '~/components/AjaxButton'
  import Dropdown from '~/components/Dropdown'
  import PopOver from '~/components/PopOver'
  import CheckoutRight from '~/components/CheckoutRight'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import UserAddress from '~/components/UserAddress'
  import addressHelper from '~/mixin/addressHelper'
  import validation from "~/mixin/validation"
  import Spinner from "~/components/Spinner"
  import global from '~/mixin/global'
  import PaymentGateways from "~/components/PaymentGateways";

  export default {
    middleware: ['common-middleware'],
    data() {
      return {
        deliveryType: 'delivery',
        voucherResult: null,
        addressPopup: false,
        cartShipping: {},
        checked: [],
        cartPrice: {
          totalItems: 0,
          totalPriceWithOffer: 0,
          totalPrice: 0,
        },
        cartPopOver: false,
        editing: 0,
        checkingOut: false,
        states: {},
        loading: false,
        checkedProduct: [],
        singleShippingCart: {},
        hasAddressErrors: false,
        addressData: {
          id: '',
          name: '',
          phone: '',
          city: '',
          country: '',
          state: '',
          zip: '',
          address_1: '',
          address_2: '',
          delivery_instruction: ''
        },
        submittingAddressData: false,
        selectedCurrentAddress: null,
        errorFromApi: null
      }
    },
    watch: {
      profile() {
        this.addressData.name = this.profile.name
        this.addressData.email = this.profile.email
      },
      location() {
        this.settingCountry()
      },
    },
    components: {
      Spinner,
      CheckoutRight,
      Dropdown,
      AjaxButton,
      CartList,
      PopOver,
      UserAddress,
      PaymentGateways
    },
    mixins: [util, addressHelper, validation, global],
    computed: {
      productSummary() {
        if (!this.checkedProduct.length) return []
        return this.checkedProduct.map(p => ({
          text: `${p.quantity} x ${p?.flash_product?.title}`
        }))
      },
      formattedDeliveryPrice() {
        return this.deliveryPrice.toFixed(2)
      },
      deliveryPrice() {
        console.log(Object.values(this.cartShipping), "shipping");
        let total = 0
        Object.values(this.cartShipping)
          .forEach(item => {
            if (
              item &&
              item.shipping_place
            ) {
              total += Number(
                item.shipping_place.price || 0
              )
            }
          })
        return total
      },
      totalPrice() {
        return this.cartPrice?.totalPrice || 0
      },
      invalidNumber() {
        return !this.isValidNumber(this.addressData?.phone)
      },
      numberValid() {
        return this.addressData.phone && !this.invalidNumber
      },
      selectedSippingPlace(){
        const cartShippingValues = Object.values(this.cartShipping)
        if(cartShippingValues?.length) {

          const sp = cartShippingValues[0]?.shipping_place

          if(!sp?.pickup_point){
            return null
          }

          const addrArr = [sp.pickup_address_line_1, sp.pickup_address_line_2,
            sp.pickup_zip, sp.pickup_state, sp.pickup_city, sp.pickup_country]

          let addr = addrArr.filter(i => i).join(', ')

          if(sp.pickup_phone) {
            addr = `${addr}, <span class="block">${this.$t('date.tl')}: ${sp.pickup_phone}</span>`
          }
          return  addr
        }
        return null
      },

      invalidEmail() {
        return !this.isValidEmail(this.addressData.email)
      },
      emailValid() {
        return this.addressData.email && !this.invalidEmail
      },
      currentAddresses() {
        return this.allAddress?.data
      },
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('language', ['langCode']),
      ...mapGetters('user', ['allAddress', 'profile']),
      ...mapGetters('common', ['location']),
      ...mapGetters('cart', ['cartProducts']),
    },
    methods: {
      goToCart() {
        this.$router.push('/cart')
      },
      setDeliveryType(type) {
        this.deliveryType = type
        Object.keys(this.cartShipping).forEach(key => {
          this.cartShipping[key].shipping_type = type === 'delivery' ? 1 : 2
          if (!this.cartShipping[key].shipping_place) {
            const product = this.checkedProduct.find(p => p.id == key)
            this.cartShipping[key].shipping_place = product?.shipping_place || product?.flash_product?.shipping_rule?.shipping_places?.[0] || null
          }
        })
      },
      initAddress() {
        this.addressData = {
          id: '',
          email: '',
          name: '',
          phone: '',
          city: '',
          country: '',
          state: '',
          zip: '',
          address_1: '',
          address_2: '',
          delivery_instruction: ''
        }
      },
      loadData() {
        this.$refs.shippingAddress.loadData()
      },
      cartChanged(evt) {
        this.singleShippingCart = []

        if (evt) {
          this.getCheckedProducts()
        }
      },
      shippingChanged(evt) {
        this.cartShipping = evt
      },
      currentShipping({cart, shipping}) {
        if(this.cartShipping[cart]) {

          this.cartShipping[cart].shipping_place = shipping

          const sr = shipping?.shipping_rule

          if(sr?.single_price && (!this.singleShippingCart[sr?.id] || (this.singleShippingCart[sr?.id] === cart))) {

            this.singleShippingCart[sr?.id] = cart
            this.cartShipping[cart].single_shipping = true

          } else if(sr?.single_price && this.singleShippingCart[sr?.id]){

            this.cartShipping[cart].single_shipping = false
          }
        }
      },
      priceCalculated(evt) {
        this.cartPrice = evt
      },
      getCheckedProducts() {
        this.checked = []
        this.checkedProduct = []

        this.cartProducts.forEach(obj => {
          if (parseInt(obj.selected) === 1) {
            this.checked.push(obj.id)

            this.checkedProduct.push(obj)

            const sp = obj?.shipping_place || obj?.flash_product?.shipping_rule?.shipping_places?.[0] || obj?.available_shipping?.[0] || null;

            this.cartShipping = {
              ...this.cartShipping,
              ...{
                [obj.id]: {
                  cart: obj.id,
                  shipping_place: sp,
                  single_shipping: true,
                  shipping_type: this.deliveryType === 'delivery' ? 1 : 2,
                }
              }
            }
          }
        })

      },
      async goToCheckout() {
        let unableToShip = false
        Object.keys(this.cartShipping).forEach(key => {
          const item = this.cartShipping[key]

          if (!item.shipping_place) {
            const product = this.checkedProduct.find(p => p.id == key)
            item.shipping_place = product?.shipping_place || product?.flash_product?.shipping_rule?.shipping_places?.[0] || product?.available_shipping?.[0] || null;
          }
        })
        // if (unableToShip) {
        //   this.setToastError(this.$t('shipping.unableShipped'))
        //   return
        // }
        if (!this.checkedProduct.length) {
          this.cartPopOver = false
          this.setToastError(this.$t('shipping.noProductSelected'))
          this.$router.push('/cart')
          return
        }
        try {
          this.checkingOut = true
          this.$store.commit('cart/SET_SELECTED_ADDRESS', this.selectedCurrentAddress)
          const data = await this.updateCartShipping({
            cart: this.cartShipping,
            user_token: await this.getUserToken(),
            selected_address: this.selectedCurrentAddress?.id
          })
          this.checkingOut = false
          if (data?.status === 200) {
            this.cartPopOver = false
            this.$router.push('/checkout')
          } else {
            if (data.data?.form) {
              this.setToastError(data.data?.form[0])
            } else if (data.data?.product) {
              this.errorFromApi = data.data?.product[0]
            }
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      goToShipping() {
        if (!this.currentAddresses.length) {
          this.setToastError(this.$t('shipping.addAddress'))
          return
        }
        if (!this.checkedProduct.length) {
          this.cartPopOver = false
          this.setToastError(this.$t('shipping.noProductSelected'))
          this.$router.push({path: 'cart'})
          return
        }

        this.$router.push({path: 'checkout'})
        //this.cartPopOver = true
      },
      clearData() {

        this.addressPopup = false

        this.initAddress()
        this.submittingAddressData = false
        this.editing = 0
        this.settingCountry()
        this.hasAddressErrors = false
      },
      async savingAddressData() {
        if (this.numberValid && this.emailValid) {
          await this.addressAction()
          if (!this.hasAddressErrors) {
            this.clearData()
          }
        } else {
          this.hasAddressErrors = true
        }
      },
      selectCountry(evt) {

        this.addressData = {...this.addressData, ...{country: evt.value?.code2}}
        this.states = evt.value?.states
        this.addressData.state = Object.keys(evt.value?.states).length ? Object.values(evt.value?.states)[0]?.code : ''
      },
      selectState(evt) {
        this.addressData.state = evt.value.code
      },
      settingCountry() {
        if (this.addressData) {
          if(this.location.countryCode && this.countryList[this.location.countryCode]){
            this.addressData.country = this.location.countryCode
          } else{

            this.addressData.country = Object.keys(this.countryList)[0]
          }

          this.states = this.addressData?.country ? this.countryList[this.addressData.country].states : ''
          this.addressData.state = this.location.region
        }
      },
      async deleting(address) {
        if (confirm(this.$t('deleteAlert.cartProductTile'))) {
          this.ajaxDeleting = address.id
          await this.addressDelete(address.id)
          this.ajaxDeleting = 0
        }
      },
      closeAddressPopup(){
        this.addressPopup = false
        this.addressData = {}

        this.addressData.country = this.location?.countryCode
        this.states = this.countryList[this.location?.countryCode]?.states
        this.addressData.state = this.location?.region
        this.addressData.email = this.profile?.email
      },
      editAddress(value) {
        this.addressPopup = true
        this.editing = value.id
        this.addressData = Object.assign({}, value)
        this.states = this.countryList[value.country].states
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('user', ['userAddressAction', 'getUserToken']),
      ...mapActions('common', ['fetchLocation', 'setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('cart', ['getCartByUser', 'updateCartShipping'])
    },
    async asyncData({store, $auth, error}) {
      try {
        if(!store.state?.common?.setting?.guest_checkout) {
          if (!$auth.loggedIn) {
            $auth.redirect('login')
            return false
          }
        }
        if(!store.state.common.paymentGateway){
          const data = await store.dispatch('common/getRequest', {
            params: {},
            api: 'paymentGateway'
          })
          store.commit('common/SET_PAYMENT_GATEWAY', data.data)
        }
      } catch (e) {
        error(e)
      }
    },
    async mounted() {
      try {
        if (!this.countryList || !this.phoneList) {
          this.loading = true

          const {data} = await this.getRequest({
            params: null,
            lang: this.langCode,
            api: 'countriesPhones'
          })
          this.setCountryList(data?.countries)
          this.setPhoneList(data?.phones)
          this.loading = false
        }
        if (this.cartProducts.length === 0) {
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })
          this.getCheckedProducts()
        } else {
          if (this.cartProducts.length) {
            this.getCheckedProducts()
          }
        }
      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.initAddress()
      this.$nextTick(() => {
        if (this.profile) {
          this.addressData.name = this.profile?.name
          this.addressData.email = this.profile?.email
        }
      })
      if (!this.addressData.country) {
        this.settingCountry()
      }
    },
  }
</script>
<style>
.custom-dropdown{
  width:250px;
}

.pop-over .pop-over-inner .pop-over-content{
  max-height:700px;
}


.address-card {
    border: 1px solid #dcdde1;
    border-radius: 12px;
    padding: 18px;
    position: relative;
    background: #fff;
    cursor: pointer;
    transition: 0.3s;
    height: 100%;
  }

  .address-card.active {
    border: 2px solid #333199;
    background: #f3f4ff;
  }

  .badge-custom {
    background: #333199;
    color: #fff;
    font-size: 12px;
    border-radius: 20px;
    padding: 3px 10px;
    margin-left: 8px;
  }

  .check-icon {
    position: absolute;
    top: -10px;
    right: -10px;
    background: #333199;
    color: #fff;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 14px;
  }

  .address-card.active .check-icon {
    display: flex;
  }

  /* Add New Card */
  .add-card {
    border: 2px dashed #dcdde1;
    border-radius: 16px;
    background: #f8f9fc;
    cursor: pointer;
    min-height: 180px;
    transition: 0.3s;
  }

  .add-card:hover {
    background: #eef0ff;
    border-color: #4b4bff;
  }

  .plus-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #dcdde1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #333;
  }
  .new-address {
    width: 160px;
}
.save-address {
 width: 330px;
}
span.delivery-heading {
    font-size: 18px;
    font-weight: 500;
    color: #130E2B;
}
.form-label {
    margin-bottom: 10px;
    color: #130E2B;
    font-size: 14px;
    font-weight: 500;
}
.delivery-filed {
    padding: 12px 12px !important;
    border-radius: 10px;
    font-size: 14px;
    color: #8C8999;
}
span.default-address {
    font-size: 14px;
    font-weight: 500;
    color: #130E2B;
}
.cart-product-list-card .add-cart-small-text{
      margin-top: 35px !important;
}
.delivery-box {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  border: 1px solid #E3E3EF;
}

.delivery-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.delivery-left {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.check-circle {
  width: 30px;
  height: 30px;
  background: #16a34a;
  color: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}

.delivery-title {
  font-size: 20px;
  font-weight: 600;
  color: #130E2B;
}

.delivery-sub {
  font-size: 14px;
  font-weight: 500;
  color: #232159;
}

.delivery-options {
  display: flex;
  gap: 20px;
}

.delivery-option {
  flex: 1;
  background: #E9F6EE;
  border-radius: 14px;
  padding: 15px;
  display: flex;
  align-items: center;
  gap: 20px;
  cursor: pointer;
  transition: 0.3s;
}

.delivery-option.active {
  background: #b4d8c7;
}

.icon-circle {
  width: 40px;
  height: 40px;
  background: #16a34a;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
}

.delivery-type{
  font-weight: 600;
  color: #130E2B;
  font-size: 20px;
}
.delivery-edit-btn {
    border: 1px solid #33319A;
    padding: 0px 20px;
    border-radius: 100px;
    color: #33319A;
}
</style>
