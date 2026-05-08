<template>
  <div v-if="bundle && bundle.products">
    <div class="row bundle-row mt-3">
      <div v-for="(product, index) in bundle.products" :key="product.id" class="col-6 col-sm-6 col-md-4 col-lg-3">
        <div class="bundle-item">
          <div class="bundle-img">
            <lazy-image :data-src="getImageURL(product.image)" :title="product.title" :alt="product.title"
              class="product-bundle-image" />
          </div>

          <div class="bundle-info">
            <div>
              <div class="bundle-title">
                {{ product.title }}
              </div>
              <div class="bundle-price">
                <price-format :price="getProductPrice(product)" />
              </div>
            </div>

            <div v-if="index !== bundle.products.length - 1" class="plus-icon">
              <i class="fa-solid fa-plus"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- BUNDLE BOX -->
    <div class="bundle-box mt-3">
      <div class="bundle-box-bg d-flex justify-content-between align-items-center px-3 py-2">
        <div class="bundle-box-title">
          <i class="fa-solid fa-box-open"></i>
          {{ bundle.title }}
        </div>

        <button class="btn save-btn">
          Save
          {{
            '€' + totalDiscount.toFixed(2)
          }}
        </button>
      </div>

      <div class="bundle-info-item-button">
        <div class="d-flex justify-content-between pt-3">
          <span class="total-price">€{{ totalPrice.toFixed(2) }}</span>

          <div class="text-end">
            <strong class="total-price">€{{ finalPrice.toFixed(2) }}</strong><br>
            <span class="bundle-small-text">
              {{ 'Bundle Free' }}
            </span>
          </div>
        </div>

        <button class="btn btn-purple w-100 mt-3" @click="addAll" :disabled="adding">
          <i class="fa-solid fa-cart-shopping"></i>
          {{ adding ? 'Adding...' : 'Add Complete Bundle' }}
        </button>
      </div>
    </div>
  </div>
</template>
<script>
import { mapActions, mapGetters } from 'vuex'
import cartHelper from '~/mixin/cartHelper'

export default {
  mixins: [cartHelper],
  computed: {
    totalPrice() {
      return this.bundle.products.reduce((sum, p) => {
        return sum + Number(p.selling)
      }, 0)
    },
    totalDiscount() {
      return this.bundle.products.reduce((sum, p) => {
        const selling = Number(p.selling || 0)
        const final = Number(p.price || 0)

        return sum + (selling - final)
      }, 0)
    },
    finalPrice() {
      return Math.max(this.totalPrice - this.totalDiscount, 0)
    },
    ...mapGetters('language', ['langCode']),
    ...mapGetters('common', ['setting']),
  },
  props: {
    bundle: {
      type: Object,
      required: true
    }
  },

  data() {
    return {
      adding: false
    }
  },

  methods: {
    ...mapActions('cart', ['cartAction']),
    ...mapActions('user', ['getUserToken']),
    getImageURL(image) {
      const baseUrl = 'https://shop.fixmypc.ie/uploads/'
      return baseUrl + image
    },

    getProductPrice(product) {
      return Number(product.price || 0)
    },

    // 🔥 distribute bundle discount
    getBundleItemPrice(product) {
      const base = this.getProductPrice(product)
      const discountValue = parseFloat(this.bundle.discount_value) || 0
      const type = this.bundle.discount_type
      let final = base
      if (type === 'percentage') {
        final = base - (base * discountValue / 100)
      } else {
        const perItem = discountValue / this.bundle.products.length
        final = base - perItem
      }

      return Math.max(final, 0)
    },

    async addAll() {
      this.adding = true

      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        for (let product of this.bundle.products) {

          const invRes = await this.$axios.get(
            `${baseUrl}api/inventory/find/${product.id}`
          )

          const inventory = invRes?.data?.data?.[0];

          if (!inventory) {
            console.warn('No inventory for', product.id)
            continue
          }

          const finalPrice = this.getBundleItemPrice(product)

          await this.cartAction({
            payload: {
              user_token: await this.getUserToken(),
              apiVal: {
                user_token: await this.getUserToken(),
                product_id: product.id,
                inventory_id: inventory.id,
                quantity: 1,
                price: finalPrice
              },
              isBundle: true,
              storeVal: {
                product: {
                  id: product.id,
                  title: product.title,
                  offered: product.offered,
                  selling: finalPrice,
                  image: product.image,
                  shipping_rule: product.shipping_rule
                },
                inventory: inventory,
                quantity: 1,
                selected: 1,
                offered: 0,
                bundle_deal: this.bundle,
                shipping_type: 1
              }
            },
            lang: this.langCode
          })
        }

      } catch (e) {
        console.error(e)
      }

      this.adding = false
    }
  }
}
</script>
<style scoped>
.bundle-info-item-button {
  padding: 25px;
}

.bundle-row {
  margin-left: -6px;
  margin-right: -6px;
}

.bundle-row>div {
  padding-left: 6px;
  padding-right: 6px;
}

.bundle-item {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.product-bundle-image {
  width: 100%;
  aspect-ratio: 4 / 3;
  background: #f3f4f8;
  border-radius: 16px;
  padding: 12px;
  object-fit: contain;
}

.bundle-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
}

.bundle-title {
  font-size: 14px;
  font-weight: 500;
  color: #1e1e2f;
}

.bundle-price {
  font-size: 13px;
  color: #6b7280;
}

.plus-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #eef2ff;
  color: #4f46e5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.bundle-box {
  border: 1px solid #e5e7eb;
  border-radius: 16px;
  overflow: hidden;
  background: #fff;
}

.bundle-box-bg {
  background: #f3f5fc;
  border-bottom: 1px solid #e5e7eb;
}

.bundle-box-title {
  font-size: 18px;
  font-weight: 400;
  color: #1e1e2f;
  display: flex;
  align-items: center;
  gap: 12px;
}

.bundle-box-title i {
  color: rgba(51, 49, 153, 1);
}

.save-btn {
  border: 1px solid rgba(51, 49, 153, 1);
  border-radius: 999px;
  color: rgba(51, 49, 153, 1);
  font-size: 14px;
  padding: 6px 17px;
  background: rgba(255, 255, 255, 1);
  height: unset;
  font-weight: 600;
}

.total-price {
  font-size: 18px;
  font-weight: 600;
  color: #1e1e2f;
}

.bundle-small-text {
  font-size: 12px;
  color: #6b7280;
}

.btn-purple {
  background: rgba(51, 49, 154, 1);
  color: #fff;
  border-radius: 12px;
  padding: 15px;
  font-size: 16px;
  border: none;
  height: unset;
}

@media (max-width: 991px) {
  .product-bundle-image {
    aspect-ratio: 1/1;
  }
}

@media (max-width: 576px) {

  .bundle-info {
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
  }

  .plus-icon {
    align-self: flex-end;
  }

  .total-price {
    font-size: 16px;
  }

  .btn-purple {
    font-size: 13px;
    padding: 10px;
  }

  .bundle-box-title {
    font-size: 16px;
  }

  .bundle-info-item-button {
    padding: 15px;
  }
}
</style>