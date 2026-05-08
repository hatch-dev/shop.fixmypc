<template>
  <client-only>
    <div>
     <Breadcrumbs />
      <navigation-step /> 
      <div class="container-fluid my-5">
        <div class="row g-4">
          <div class="col-lg-8">
            <div class="d-flex justify-content-between mb-3">
              <h5>Shopping Cart</h5>

              <div>
                <button
                  v-if="checked.length !== cartProducts.length"
                  class="btn btn-link p-0"
                  @click.prevent="selectAllItems"
                >
                  Select All
                </button>

                <button
                  v-else
                  class="btn btn-link p-0"
                  @click.prevent="deselectAllItems"
                >
                  Deselect All
                </button>
              </div>
            </div>
            <cart-list
              :cart-products="cartProducts"
              :ajaxing="ajaxing"
              :checked="checked"
            />
            <div class="accordion mt-3 mb-3" id="deliveryAccordion">
              <div class="accordion-item ">
                <h5 class="accordion-header"  id="headingAddress">
                  <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#collapseAddress" aria-expanded="false" aria-controls="collapseAddress">
                    <span class="delivery-heading">Delivery Address</span>
                  </button>
                </h5>
                <div id="collapseAddress" class="accordion-collapse collapse" aria-labelledby="headingAddress" data-bs-parent="#deliveryAccordion">
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
            :disabled="preventGoing"
            :checked-product="checkedProduct"
            @calculated-price="cartPrice = $event"
            @go-next="goToAddress"
          />
          <!-- Upsell Popup -->
          <div v-if="showUpsellModal" class="modal-overlay">
            <div class="modal-content upsell-modal">
              <div class="modal-header">
                <h3>{{ $t('cart.upsellTitle') }}</h3>
                <button class="modal-close" @click="closeUpsellPopup">
                  <i class="icon close"></i>
                </button>
              </div>
              
              <div class="modal-body">
                <div v-if="loadingUpsells" class="loading-spinner">
                  <spinner :radius="30" color="primary" />
                </div>
                
                <div v-if="upsellError" class="error-message">
                  <i class="icon error"></i>
                  <p>{{ upsellError }}</p>
                  <button class="retry-btn" @click="loadUpsellProducts">
                    {{ $t('cart.retry') }}
                  </button>
                </div>
                
                <div v-else-if="upsellProducts.length" class="upsell-products">
                  <div class="upsell-product" v-for="product in upsellProducts" :key="product.id">
                    <div class="product-image">
                      <img 
                        :src="getImageURL(product.image)" 
                        :alt="product.title"
                        @error="handleImageError($event, product)"
                        :class="{ 'image-error': !product.imageLoaded }"
                      >
                      <div v-if="!product.imageLoaded" class="image-placeholder">
                        <i class="icon image"></i>
                      </div>
                    </div>
                    
                    <div class="product-info">
                      <h4>{{ product.title }}</h4>
                        
                        <template v-if="hasDiscount(product)">
                          <price-format 
                            :price="getOriginalPrice(product)" 
                            class="original-price strike-through"
                          />
                          <span class="discount-percent">
                            {{ getDiscountPercent(product) }}% OFF
                          </span>
                        </template>
                        
                        <div class="product-price">
                        <price-format 
                          :price="getUpsellPrice(product)" 
                          class="current-price"
                        />
                      </div>
                      
                      <p v-if="product.inventoryError" class="inventory-error">
                        {{ product.inventoryError }}
                      </p>
                      <p v-if="product.addToCartError" class="add-to-cart-error">
                        {{ product.addToCartError }}
                      </p>
                    </div>
                    
                    <div class="product-actions">
                      <button 
                        class="add-to-cart-btn ajax-btn w-100 w-sm-50 primary-btn mtb-10 mlr-sm-2-5"
                        :class="{
                          'added-to-cart': product.addedToCart,
                          'adding-to-cart': addingToCart === product.id,
                          'error-state': product.inventoryError || product.addToCartError
                        }"
                        @click="addUpsellToCart(product)"
                        :disabled="addingToCart === product.id || product.addedToCart || product.inventoryError"
                      >
                        <span v-if="addingToCart === product.id">
                          <spinner :radius="15" color="white" />
                        </span>
                        <span v-else-if="product.addedToCart">
                          {{ $t('cart.addedToCart') }}
                        </span>
                        <span v-else-if="product.inventoryError || product.addToCartError">
                          {{ $t('cart.unavailable') }}
                        </span>
                        <span v-else>{{ $t('cart.addToCart') }}</span>
                      </button>
                    </div>
                  </div>

                  <div class="upsell-footer">
                    <button 
                      class="skip-btn ajax-btn w-100 w-sm-50 primary-btn mtb-10 mlr-sm-2-5"
                      @click="closeUpsellPopup"
                      :disabled="addingToCart !== null"
                    >
                      {{ hasAddedItems ? $t('cart.continueToCheckout') : $t('cart.skipOffer') }}
                    </button>
                  </div>
                </div>
                
                <div v-else class="no-upsells">
                  <p>{{ $t('cart.noUpsellsAvailable') }}</p>
                  <button class="continue-btn ajax-btn primary-btn w-100" @click="goToAddress">
                    {{ $t('cart.continueToCheckout') }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </client-only>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import CheckoutRight from '~/components/CheckoutRight'
  import CartList from '~/components/CartList'
  import util from '~/mixin/util'
  import productHelper from "~/mixin/productHelper";
  import PriceFormat from "~/components/PriceFormat";
  import global from '~/mixin/global'
  import Spinner from '~/components/Spinner'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import NavigationStep from '../components/NavigationStep.vue'
  import Breadcrumbs from '../components/Breadcrumbs.vue'
  import UserAddress from '~/components/UserAddress'
  import PaymentGateways from "~/components/PaymentGateways";

  export default {
    middleware: ['common-middleware'],
    data() {
      return {
        voucherResult: null,
        addressPopup: false,
        editing: 0,
        preventGoing: true,
        checked: [],
        // bundleList: [],
        ajaxing: false,
        isMounting: false,
        hasBundle: false,
        cartPrice: {
          totalItems: 0,
          totalPriceWithOffer: 0,
          totalPrice: 0,
        },
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
        // Upsell popup data
        showUpsellModal: false,
        upsellProducts: [],
        loadingUpsells: false,
        addingToCart: null,
        currentUpsellId: null,
        upsellError: null,
        autoCloseTimer: null // Add timer reference
      }
    },
    components: {
      PriceFormat,
      AjaxButton,
      CheckoutRight,
      CartList,
      Spinner,
      UserAddress,
      PaymentGateways
    },
    mixins: [util, productHelper, global, productPriceHelper],
    computed: {
      totalPrice() {
        return this.cartPrice?.totalPrice || 0
      },
      checkedProduct() {
        this.checked = []
        let checkedP = []
        this.cartProducts.forEach(obj => {
          if (parseInt(obj.selected) === 1) {
            this.checked.push(obj.id)
            checkedP.push(obj)
          }
        })
        this.preventGoing = checkedP.length === 0
        return checkedP
      },
      cartCount() {
        return this.$auth?.user?.cart_count
      },
      // Check if any upsell items have been added to cart
      hasAddedItems() {
        return this.upsellProducts.some(product => product.addedToCart);
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('common', ['currencyIcon', 'setting']),
      ...mapGetters('cart', ['cartProducts'])
    },
    methods: {
      editAddress(value) {
        this.addressPopup = true
        this.editing = value.id
        this.addressData = Object.assign({}, value)
        this.states = this.countryList[value.country].states
      },

      // Price calculation methods for upsell products
      getUpsellPrice(product) {
        // Use offered_price if available, otherwise use price or current_price
        return product.offered_price || product.price || product.current_price || 0;
      },
      
      getOriginalPrice(product) {
        ////console.log(product);
        // Use price if available, otherwise use offered_price (if no discount)
        ///return product.price || product.offered_price || product.current_price || 0;
        return product.selling_price;
      },
      
      hasDiscount(product) {
      
        const originalPrice = this.getOriginalPrice(product);
        const upsellPrice = this.getUpsellPrice(product);
          //console.log("original price"+originalPrice+"upsell"+upsellPrice);

        return originalPrice > upsellPrice;
      },
      
      getDiscountPercent(product) {
        if (!this.hasDiscount(product)) return 0;
        
        const originalPrice = this.getOriginalPrice(product);
        const upsellPrice = this.getUpsellPrice(product);
        const discount = originalPrice - upsellPrice;
        const discountPercent = (discount / originalPrice) * 100;
        
        return Math.round(discountPercent);
      },
      
      handleImageError(event, product) {
        this.$set(product, 'imageLoaded', false);
        event.target.style.display = 'none';
      },
      
      async showUpsellPopup() {
        if (!this.$auth?.user) {
          this.$router.push({
            path: '/login',
            query: { redirect: '/cart' } // or '/shipping'
          })
          return
        }
        const productWithUpsell = this.cartProducts.find(product => 
          product.upsell_id && parseInt(product.selected) === 1
        );

        if (productWithUpsell?.upsell_id) {
          this.currentUpsellId = productWithUpsell.upsell_id;
          await this.loadUpsellProducts();
          this.showUpsellModal = true;
        } else {
          this.goToAddress();
        }
      },

      // async loadBundleDeals(){
      //   try{
      //     const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
      //     const response = await this.$axios.get(`${baseUrl}api/v1/bundle-deals`);
      //     const data = response?.data?.data;
      //     this.bundleList = data;
      //   }catch(error){
      //     console.error("error", error);
      //   }
      // },
      
      async loadUpsellProducts() {
        this.loadingUpsells = true;
        this.upsellError = null;
        
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          const response = await this.$axios.get(`${baseUrl}api/upsells/${this.currentUpsellId}/products`);
          
          this.upsellProducts = (response.data.data || []).map(product => ({
            ...product,
            imageLoaded: true,
            addedToCart: false,
            inventoryError: null,
            addToCartError: null
          }));
          
          await this.loadInventoriesForUpsellProducts();
          
        } catch (error) {
          console.error('Error loading upsell products:', error);
          this.upsellError = this.$t('cart.upsellLoadError');
          this.upsellProducts = [];
        } finally {
          this.loadingUpsells = false;
        }
      },
      
      async loadInventoriesForUpsellProducts() {
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          
          for (const product of this.upsellProducts) {
            try {
              const productId = product.product_id || product.id;
              
              const response = await this.$axios.get(`${baseUrl}api/inventory/find/${productId}`);
              
              if (response.data.data && response.data.data.length > 0 && response.data.data[0]?.id) {
                const inventoryData = response.data.data[0];
                
                this.$set(product, 'inventory_id', inventoryData.id);
                
                if (inventoryData.quantity <= 0) {
                  this.$set(product, 'inventoryError', this.$t('cart.outOfStock'));
                }
              } else {
                this.$set(product, 'inventoryError', this.$t('cart.noInventory'));
              }
            } catch (error) {
              console.error(`Error loading inventory for product ${product.id}:`, error);
              this.$set(product, 'inventoryError', this.$t('cart.inventoryLoadError'));
            }
          }
        } catch (error) {
          console.error('Error loading product inventories:', error);
        }
      },
      
      async addUpsellToCart(product) {
        // Clear any existing auto-close timer
        if (this.autoCloseTimer) {
          clearTimeout(this.autoCloseTimer);
          this.autoCloseTimer = null;
        }

        this.addingToCart = product.id;
        this.$set(product, 'addToCartError', null);
        
        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          const productId = product.product_id || product.id;
          
          if (!product.inventory_id) {
            throw new Error(this.$t('cart.noInventoryAvailable'));
          }
          
          const payload = {
            product_id: productId,
            inventory_id: product.inventory_id,
            quantity: 1,
            price: this.getUpsellPrice(product), // Use the upsell price
            user_token: await this.getUserToken()
          };
          
          const response=await this.$axios.post(`${baseUrl}api/v1/cart/action`, payload);
       
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          });
           
          // Set a timer to auto-close after success, but don't close immediately
          this.autoCloseTimer = setTimeout(() => {
            if (this.addingToCart === product.id) {
              this.addingToCart = null;
            }
          }, 1500);

          let errorMessage = response.data.message;

           if(errorMessage!=''){
             errorMessage= this.$t('cart.outStockMessage');
             this.$set(product, 'addToCartError', errorMessage);
             this.$set(product, 'addedToCart', false);
            this.addingToCart = null;
           }
           else{
              this.$set(product, 'addedToCart', true);
           }

        } catch (error) {
          console.error('Error adding to cart:', error);
          
          let errorMessage = this.$t('cart.addToCartError');
          
          if (error.response?.data?.message) {
            errorMessage = error.response.data.message;
          } else if (error.message) {
            errorMessage = error.message;
          }
          
          this.$set(product, 'addToCartError', errorMessage);
          //this.$toast.error(errorMessage);
          
        } finally {
          this.addingToCart = null;
        }
      },
      
      closeUpsellPopup() {
        // Clear any pending auto-close timer
        if (this.autoCloseTimer) {
          clearTimeout(this.autoCloseTimer);
          this.autoCloseTimer = null;
        }
        
        this.showUpsellModal = false;
        this.upsellProducts = [];
        this.currentUpsellId = null;
        this.upsellError = null;
        this.goToAddress();
      },
      
      goToAddress() {
        if (!this.$auth?.user) {
          this.$router.push({
            path: '/login',
            query: { redirect: '/shipping' }
          })
          return
        }
        this.$router.push('/shipping');
      },
      
      async selectAllItems() {
        this.checked = []
        this.cartProducts.forEach((obj) => {
          this.checked.push(obj.id)
        })
        await this.cartChanged({
          payload: {
            checked: this.checked
          },
          lang: this.langCode
        })
        await this.cartChangedApi()
      },
      
      async deselectAllItems() {
        this.checked = []
        await this.cartChanged({
          payload: {
            checked: this.checked
          },
          lang: this.langCode
        })
        await this.cartChangedApi()
      },
      
      async cartChangedApi(bundleDeal = false) {
        await this.cartChanged({
          payload: {
            checked: this.checked,
            unchecked: this.unchecked,
            isBundle: bundleDeal
          },
          lang: this.langCode
        })
      },
      
      settingChecked() {
        this.checkedProduct = []
        this.checked = []

        this.cartProducts.forEach(obj => {
          if (parseInt(obj.selected) === 1) {
            this.checked.push(obj.id)
            this.checkedProduct.push(obj)
          }
        })
      },
      
      async fetchingData() {
        this.ajaxing = true
        try {
          await this.getCartByUser({
            lang: this.langCode,
            params: {
              user_token: await this.getUserToken()
            }
          })
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.ajaxing = false
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', ['fetchLocation', 'setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('cart', ['getCartByUser', 'cartChanged', 'updateCartShipping']),
      ...mapActions('user', ['getUserToken', 'userAddressAction'])
    },
    beforeDestroy() {
      // Clean up any pending timers when component is destroyed
      if (this.autoCloseTimer) {
        clearTimeout(this.autoCloseTimer);
      }
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
      await this.fetchingData()
      // await this.loadBundleDeals();
    }
  }
</script>

<style>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  max-width: 90%;
  max-height: 90%;
  overflow: auto;
}

.upsell-modal {
  width: 800px;
  max-width: 95%;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid #eee;
}

.modal-close {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
}

.modal-body {
  padding: 20px;
}

.loading-spinner {
  display: flex;
  justify-content: center;
  padding: 40px;
}

.error-message {
  text-align: center;
  padding: 20px;
  color: #d32f2f;
}

.retry-btn {
  margin-top: 10px;
  padding: 8px 16px;
  background: #1976d2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.upsell-products {
  display: grid;
  gap: 20px;
}

.upsell-product {
  display: flex;
  gap: 15px;
  padding: 15px;
  border: 1px solid #eee;
  border-radius: 8px;
  align-items: center;
}

.product-image {
  width: 80px;
  height: 80px;
  position: relative;
}

.product-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 4px;
}

.image-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f5f5;
  border-radius: 4px;
}

.product-info {
  flex: 1;
}

.product-info h4 {
  margin: 0 0 8px 0;
  font-size: 16px;
}

.product-price {
  margin: 0 0 8px 0;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.current-price {
  font-weight: bold;
  color: #1976d2;
  font-size: 20px;
}

.original-price {
  text-decoration: line-through;
  color: #999;
  font-size: 14px;
}

.discount-percent {
  color: #d32f2f;
  font-weight: bold;
  font-size: 14px;
  background: #ffebee;
  padding: 2px 6px;
  border-radius: 4px;
}

.inventory-error,
.add-to-cart-error {
  color: #d32f2f;
  margin: 5px 0 0 0;
  font-size: 14px;
}

.product-actions {
  min-width: 120px;
}

.add-to-cart-btn {
  padding: 10px 15px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.3s;
}

.add-to-cart-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.add-to-cart-btn.added-to-cart {
  background: green !important;
  opacity:1 !important;
}

.add-to-cart-btn.error-state {
  background: #d32f2f !important;
}

.add-to-cart-btn.adding-to-cart {
  opacity: 0.8;
}

.upsell-footer {
  margin-top: 30px;
  text-align: center;
}

.skip-btn {
  padding: 12px 24px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
}

.skip-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.no-upsells {
  text-align: center;
  padding: 40px 20px;
}

.continue-btn {
  padding: 12px 24px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
  margin-top: 20px;
}

.image-error {
  display: none;
}

.ajax-btn {
  position: relative;
}

.ajax-btn:disabled::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(255, 255, 255, 0.5);
}

.strike-through {
  text-decoration: line-through;
}

.save-address {
 width: 330px;
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

.new-address {
  width: 160px;
}

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

@media (max-width: 768px) {
  .upsell-product {
    flex-direction: column;
    text-align: center;
  }
  
  .product-actions {
    width: 100%;
  }
  
  .modal-content {
    margin: 20px;
  }
  
  .product-price {
    justify-content: center;
  }
}
</style>