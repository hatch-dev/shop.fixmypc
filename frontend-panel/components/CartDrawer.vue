<template>
    <div>
        <div v-if="isOpen && cartProducts.length > 0" class="overlay" :class="{ active: isOpen }" @click="$emit('close')"></div>
        <div class="cart-drawer d-flex flex-column" :class="{ active: isOpen }" style="height: 100vh;">

            <!-- HEADER -->
            <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-bold fs-5 cart-color">Added to Your Cart</h6>
            <i class="fa-solid fa-xmark" @click="$emit('close')" style="cursor:pointer"></i>
            </div>

            <div class="flex-grow-1 overflow-auto">
            <!-- CART ITEMS -->
                <div 
                    v-for="item in cartProducts" 
                    :key="item.id" 
                    class="cart-item"
                    >
                    <lazy-image
                        :data-src="getImageURL(item?.flash_product?.image)"
                        :alt="item?.flash_product?.title"
                    />
                    <div class="my-3">
                        <strong class="cart-color">{{ item?.flash_product?.title }}</strong>
                        <p class="small-text mb-0">
                          Price: <span class="cart-color">€ {{ item?.upsell_price }}</span>
                        </p>
                        <p class="small-text">
                        Qty: <span class="cart-color">{{ item.quantity }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-auto text-center pt-3" style="border-top: 1px solid #E9E9F4;">
                <strong>Sub Total: €{{ totalPrice }}</strong>
                <!-- BUTTONS -->
                <button 
                    class="btn btn-primary-custom w-100 mt-3 rounded-5"
                    @click="goToCart"
                >
                    View Basket
                </button>

                <button 
                    class="btn btn-green w-100 mt-3 rounded-5"
                    @click="$emit('close')"
                >
                    Continue Shopping
                </button>
            </div>
            
        </div>
    </div>
</template>

<script>
import { mapGetters, mapActions  } from 'vuex'
import util from '~/mixin/util'

export default {
  props: ['isOpen'],
  mixins: [util],

  computed: {
    ...mapGetters('cart', ['cartProducts']),
    cartProducts() {
      return this.$store.state.cart.cartProducts
    },
    totalPrice() {
        console.log("Calculating total price for cart drawer", this.cartProducts);
      return this.cartProducts.reduce((sum, item) => {
        return sum + (item?.upsell_price * item.quantity)
      }, 0).toFixed(2)
    }
  },

  methods: {
    async refreshCart() {
        try {
        await this.getCartByUser({
            lang: this.$store.state.language.langCode,
            params: {
            user_token: await this.getUserToken()
            }
        })
        } catch (e) {
        console.log("Cart refresh failed", e)
        }
    },
    goToCart() {
      this.$router.push('/cart')
    },
    ...mapActions('cart', ['getCartByUser']),
    ...mapActions('user', ['getUserToken'])
  },
  async mounted() {
    await this.refreshCart()
  },
  watch: {
  '$route'() {
    this.refreshCart()
  }
}
}
</script>

<style scoped>
.overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgb(12 10 45 / 70%);
    display: none;
    z-index: 999;
}

.cart-drawer{
  position:fixed;
  top:0;
  right:-600px;
  width:400px;
  height:100%;
  background:#fff;
  z-index:1000;
  transition:0.3s;
  padding:40px 20px;
  overflow-y:auto;
  border-radius:35px 0 0 35px;
}

.cart-drawer.active{
  right:0;
}

.overlay.active{
  display:block;
}

/* PRODUCT */
.cart-item{
  display:flex;
  gap:10px;
  background:#f4f5fb;
  padding:20px;
  border-radius:10px;
  margin-bottom:10px;
}

.cart-item img{
    width:95px;
    object-fit: contain;
}

/* BUTTONS */
.btn-primary-custom{
  background:#3f3d9c;
  color:#fff;
}
.btn-green{
  background:#22c55e;
  color:#fff;
}

.small-text{font-size:13px;color:#666}

.cart-color{
    color: #232159;
    font-weight: 600;
}
</style>