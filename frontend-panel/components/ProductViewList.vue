<template>
  <!--
    One saved product: the wishlist and Recently Viewed both render these.

    Desktop is one row (item, price, actions). A phone gets a compact card:
    thumbnail beside the name, the price and stock on one line, and a single
    action row. It used to stack price, old price, stock, button and a 12px
    "Remove" on five separate lines per item.
  -->
  <article class="pvl" :class="{ 'is-out': !inStock }">

    <nuxt-link :to="productLink(product)" class="pvl-thumb">
      <lazy-image
        :data-src="thumbImageURL(product)"
        :alt="product.title"
      />
    </nuxt-link>

    <div class="pvl-info">
      <nuxt-link :to="productLink(product)" class="pvl-title" :title="product.title">
        {{ product.title }}
      </nuxt-link>

      <!-- "SKU: N/A" was printed for every product without one -->
      <span v-if="product.sku" class="pvl-sku">SKU {{ product.sku }}</span>

      <span class="pvl-rating" :aria-label="`Rated ${Math.floor(product.rating || 0)} out of 5`">
        <span class="pvl-stars" aria-hidden="true">{{ stars }}</span>
        <span class="pvl-reviews">({{ product.review_count || 0 }})</span>
      </span>

      <!-- stockClass existed but was never applied: every badge was grey -->
      <span class="pvl-stock" :class="stockClass">
        <span class="pvl-stock-dot" aria-hidden="true"></span>
        {{ stockText }}
      </span>
    </div>

    <div class="pvl-price">
      <span class="pvl-price-now">
        <small v-if="isVariablePrice" class="pvl-from">{{ $t('from') }}</small>
        {{ currencyIcon }}{{ currentPricing }}
      </span>
      <span v-if="prevPrice" class="pvl-price-was">
        {{ currencyIcon }}{{ prevPrice }}
      </span>
    </div>

    <div class="pvl-actions">
      <button
        v-if="inStock"
        type="button"
        class="pvl-btn pvl-btn-cart"
        :disabled="ajaxingCart"
        @click="addProductToCart"
      >
        <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
        Add to cart
      </button>
      <span v-else class="pvl-btn pvl-btn-out" aria-disabled="true">
        Out of stock
      </span>

      <!--
        "Notify Me" was a dead href="#": there is no back-in-stock service
        behind it, so it is not offered.
      -->
      <button
        type="button"
        class="pvl-btn pvl-btn-remove"
        :disabled="removing"
        :aria-label="`Remove ${product.title}`"
        :title="type === 'wishlist' ? 'Remove from wishlist' : 'Remove'"
        @click="wishListAction"
      >
        <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
        <span class="pvl-remove-label">Remove</span>
      </button>
    </div>
  </article>
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
      ajaxingCart: false,
      removing: false
    }
  },
  computed: {
    ...mapGetters('common', ['currencyIcon']),
    productInventory() {
      // a copy: sorting the prop in place reordered the parent's data
      const inventories = [...(this.product?.product_inventories || [])]
      return inventories.sort((a, b) => b.quantity - a.quantity)[0]
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
      if (this.ajaxingCart) return
      this.ajaxingCart = true
      try {
        await this.addToCart()
      } catch (e) {
        this.setToastError(e.message)
      } finally {
        this.ajaxingCart = false
      }
    },
    async wishListAction() {
      if (this.removing) return
      this.removing = true
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        if (this.type === 'wishlist') {
          await this.$axios.post(`${baseUrl}api/v1/user/wishlist/action`, {
            product_id: this.product.id,
            user_token: this.$auth.loggedIn ? null : await this.getUserToken()
          })
        }else{
          await this.$axios.delete(
            `${baseUrl}api/v1/user/recently-viewed/${this.product.id}`
          )
        }
        this.$emit('removed')
      } catch (e) {
        this.setToastError(e.message)
      } finally {
        this.removing = false
      }
    },
    ...mapActions('common', ['setToastError'])
  }
}
</script>
<style scoped>
/*
 * Buttons set height and line-height themselves and hover backgrounds are
 * !important: base.styl's global button rules (50px / 48px, and a hover that
 * outranks scoped CSS) otherwise win.
 */
.pvl {
  --ink: #130E2B;
  --muted: #6B7280;
  --line: #E3E3EF;
  --brand: #33319A;
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr) auto auto;
  grid-template-areas: "thumb info price actions";
  align-items: center;
  gap: 16px 20px;
  padding: 14px 16px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: #fff;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.pvl + .pvl { margin-top: 12px; }

.pvl:hover {
  border-color: #D5D6EA;
  box-shadow: 0 6px 18px rgba(19, 14, 43, .05);
}

.pvl-thumb {
  grid-area: thumb;
  width: 88px;
  height: 88px;
  border: 1px solid #EEF0F5;
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
}

.pvl-thumb ::v-deep img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.pvl.is-out .pvl-thumb { opacity: .55; filter: grayscale(40%); }

.pvl-info {
  grid-area: info;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  min-width: 0;
}

.pvl-title {
  font-size: clamp(14px, .85rem + .2vw, 16px);
  font-weight: 600;
  line-height: 1.35;
  color: var(--ink);
  text-decoration: none;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.pvl-title:hover { color: var(--brand); text-decoration: underline; }

.pvl-sku { font-size: 12px; color: var(--muted); }

.pvl-rating { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; }
.pvl-stars { color: #F5A524; letter-spacing: 1px; }
.pvl-reviews { color: var(--muted); }

.pvl-stock {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  background: #F3F4F6;
  color: #374151;
}

.pvl-stock-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

.pvl-stock.in  { background: #E6F6EC; color: #1E7E34; }
.pvl-stock.low { background: #FFF5DB; color: #946200; }
.pvl-stock.out { background: #FDECEA; color: #C82333; }

.pvl-price {
  grid-area: price;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  min-width: 90px;
  font-variant-numeric: tabular-nums;
}

.pvl-price-now {
  font-size: clamp(16px, .9rem + .4vw, 19px);
  font-weight: 700;
  color: var(--ink);
  white-space: nowrap;
}

.pvl-from {
  font-size: 12px;
  font-weight: 500;
  color: var(--muted);
  margin-right: 2px;
}

.pvl-price-was {
  font-size: 13px;
  color: #9CA3AF;
  text-decoration: line-through;
  white-space: nowrap;
}

.pvl-actions {
  grid-area: actions;
  display: flex;
  align-items: center;
  gap: 8px;
}

.pvl-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 40px;
  line-height: 1;
  padding: 0 16px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}

.pvl-btn:disabled { opacity: .6; cursor: wait; }

.pvl-btn-cart {
  border: 1px solid var(--brand);
  background: var(--brand);
  color: #fff;
}

.pvl-btn-cart:hover:enabled {
  background: #05B942 !important;
  border-color: #05B942;
  color: #fff;
}

.pvl-btn-out {
  border: 1px solid var(--line);
  background: #F3F4F6;
  color: var(--muted);
  cursor: not-allowed;
}

.pvl-btn-remove {
  width: 40px;
  padding: 0;
  border: 1px solid var(--line);
  background: #fff;
  color: var(--muted);
}

.pvl-btn-remove:hover:enabled {
  background: #FDECEA !important;
  border-color: #F3B6B1;
  color: #C82333;
}

/* the word is for phones and screen readers; desktop shows the bin alone */
.pvl-remove-label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
}

/* ---------- tablet: price joins the text column ---------- */

@media (max-width: 991.98px) {
  .pvl {
    grid-template-columns: 80px minmax(0, 1fr) auto;
    grid-template-areas:
      "thumb info  actions"
      "thumb price actions";
    row-gap: 6px;
  }

  .pvl-thumb { width: 80px; height: 80px; }

  .pvl-price {
    flex-direction: row;
    align-items: baseline;
    gap: 8px;
    justify-self: start;
    min-width: 0;
  }
}

/* ---------- phone: a compact card ---------- */

@media (max-width: 767.98px) {
  .pvl {
    grid-template-columns: 72px minmax(0, 1fr);
    grid-template-areas:
      "thumb   info"
      "thumb   price"
      "actions actions";
    gap: 6px 12px;
    padding: 12px;
  }

  .pvl-thumb { width: 72px; height: 72px; align-self: start; }

  .pvl-actions {
    margin-top: 8px;
  }

  .pvl-actions .pvl-btn-cart,
  .pvl-actions .pvl-btn-out {
    flex: 1 1 auto;
    height: 44px;
  }

  .pvl-btn-remove {
    width: 44px;
    height: 44px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pvl, .pvl-btn { transition: none; }
}
</style>