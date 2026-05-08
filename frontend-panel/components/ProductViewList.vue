<template>
  <div class="wishlist-row" :class="{ 'mt-3': index !== 0 }">

    <!-- LEFT -->
    <div class="left" :class="{ 'disabled-row': !inStock }">

        <nuxt-link
            :to="productLink(product)"
            class="product-thumb"
        >
            <lazy-image
                :data-src="thumbImageURL(product)"
                :alt="product.title"
            />
            <span v-if="!inStock" class="stock-out">Out of Stock</span>
        </nuxt-link>

      <div>
        <nuxt-link
            :to="productLink(product)"
            class="order-product-name"
            >
            {{ product.title }}
        </nuxt-link>
        <br>
        <small class="text-muted">
          SKU: {{ product.sku || 'N/A' }}
        </small>

        <div class="rating">
          {{ stars }}
          <span>({{ product.review_count || 0 }} reviews)</span>
        </div>
      </div>
    </div>

    <!-- PRICE -->
    <div class="price" :class="{ 'disabled-row': !inStock }">
      {{ currencyIcon }}{{ currentPricing }}<br>

      <small v-if="prevPrice" class="old-price">
        {{ currencyIcon }}{{ prevPrice }}
      </small>
    </div>

    <!-- STOCK -->
    <p class="stockin" :class="{ 'disabled-row': !inStock }">
      {{ inStock ? 'In Stock' : 'Out of Stock' }}
    </p>

    <!-- ACTIONS -->
    <div class="actions text-end">

      <!-- IN STOCK -->
      <template v-if="inStock">
        <button class="track-package-btn" @click="addProductToCart">
          <i class="fa-solid fa-cart-shopping me-2"></i>
          Add to Cart
        </button>
        <br>

        <a href="#" class="remove" @click.prevent="wishListAction">
          <i class="fa-solid fa-trash me-2"></i>
          Remove
        </a>
      </template>

      <!-- OUT OF STOCK -->
      <template v-else>
        <button class="track-stockout-btn disabled-row">
          <i class="fa-solid fa-cart-shopping"></i>
          Add to Cart
        </button>
        <br>

        <div class="d-flex gap-2">
          <a href="#" class="notify">
            <i class="fa-regular fa-bell me-1"></i>
            Notify Me
          </a>

          <a href="#" class="remove" @click.prevent="wishListAction">
            <i class="fa-solid fa-trash me-1"></i>
            Remove
          </a>
        </div>
      </template>

    </div>
  </div>
</template>
<script>
import LazyImage from '~/components/LazyImage'
import RatingStar from '~/components/RatingStar'
import PriceFormat from '~/components/PriceFormat'
import util from '~/mixin/util'
import productHelper from '~/mixin/productHelper'
import productPriceHelper from '~/mixin/productPriceHelper'
import cartHelper from '~/mixin/cartHelper'
import { mapGetters, mapActions } from 'vuex'

export default {
  props: {
    product: Object,
    index: Number,
    type: {
      type: String,
      default: 'wishlist'
    }
  },
  components: {
    LazyImage,
    RatingStar,
    PriceFormat
  },
  mixins: [util, productHelper, productPriceHelper, cartHelper],
  data() {
    return {
      ajaxingCart: false
    }
  },
  computed: {
    ...mapGetters('common', ['currencyIcon']),
    productInventory() {
      let inventories = this.product?.product_inventories || []
      return inventories.sort((a,b)=>b.quantity-a.quantity)[0]
    },
    inStock() {
      return this.productInventory?.quantity > 0
    },
    stars() {
      const rating = Math.floor(this.product?.rating || 0)
      return '★'.repeat(rating) + '☆'.repeat(5 - rating)
    },
    stockText() {
      if (this.productInventory?.quantity > 10) return 'In Stock'
      if (this.productInventory?.quantity > 0) return 'Low Stock'
      return 'Out of Stock'
    },
    stockClass() {
      if (this.productInventory?.quantity > 10) return 'stock in'
      if (this.productInventory?.quantity > 0) return 'stock low'
      return 'stock out'
    }
  },

  methods: {
    async addProductToCart() {
      try {
        await this.addToCart()
      } catch (e) {
        this.setToastError(e.message)
      }
    },
    async wishListAction() {
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        if (this.type === 'wishlist') {
          await this.$axios.post(`${baseUrl}api/v1/user/wishlist/action`, {
            product_id: this.product.id
          })
        }else{
          await this.$axios.delete(
            `${baseUrl}api/v1/user/recently-viewed/${this.product.id}`
          )
        }
        this.$emit('removed')
      } catch (e) {
        this.setToastError(e.message)
      }
    },
    ...mapActions('common', ['setToastError'])
  }
}
</script>
<style scoped>
.wishlist-row {
    display: grid;
    grid-template-columns: 5.0fr 1fr 1fr 1.5fr;
    align-items: center;
    gap: 15px;
    padding: 15px;
    border: 1px solid #F5F5F5;
}

.product-thumb {
    display: block;
    position: relative;
}

.product-thumb img {
    width: 100px;
    height: 100px;
    object-fit: contain;
}

.left {
    display: flex;
    align-items: center;
    gap: 15px;
}
p.order-product-name {
    display: block;
    margin-bottom: 4px;
    font-size: 16px;
    font-weight: 400;
    color: #130E2B;
    text-decoration: none;
}

.order-product-name:hover {
    text-decoration: underline;
}

.rating {
    font-size: 12px;
    color: #ffc107;
}
.rating span {
    color: #888;
}

.price {
    font-weight: 600;
}
.old-price {
    text-decoration: line-through;
    color: #aaa;
    font-size: 12px;
}

p.stockin {
    background-color: #F5F5F5;
    text-align: center;
    width: 94px;
    font-size: 12px;
    font-weight: 400;
    color: #262626;
    padding: 5px 0;
    border-radius: 15px;
}
.stock.in { background:#d4edda; color:#28a745; }
.stock.low { background:#fff3cd; color:#856404; }
.stock.out { background:#fdecea; color:#dc3545; }

.actions .remove {
    font-size: 12px;
    color: #777;
    text-decoration: none;
    display: inline-block;
    margin-top: 5px;
}
.actions .notify {
    font-size: 12px;
    color: #130E2B;
    margin-right: 10px;
    display: inline-block;
}

button.track-package-btn {
    background-color: #33319A;
    border-radius: 8px;
    color: #fff;
    font-weight: 400;
    font-size: 14px;
    padding: 0px 20px;
    border: none;
}
button.track-package-btn:hover {
    background-color: #05B942;
}
.disabled-row {
    opacity: 0.5;
    pointer-events: none;
    filter: grayscale(40%);
}

a.notify {
    font-size: 12px;
    color: #777;
    text-decoration: none;
    display: inline-block;
    margin-top: 7px;
}
span.stock-out {
    position: absolute;
    margin-top: 37px;
    margin-left: -95px;
    font-size: 12px;
    font-weight: 400;
    background-color: #6B7280;
    color: #fff;
    padding: 5px 10px 5px 10px;
    border-radius: 8px;
}

button.track-stockout-btn {
    background-color: #E4E5ED;
    border-radius: 8px;
    color: #525A6B;
    font-weight: 400;
    font-size: 14px;
    padding: 0px 20px;
    border: none;
}
</style>