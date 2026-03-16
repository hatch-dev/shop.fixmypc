<template>
  <data-page
    ref="dataPage"
    set-api="setBundleDeal"
    get-api="getBundleDeal"
    empty-store-variable="allBundleDeals"
    route-name="bundle-deals"
    :name="$t('profile.dleDeal')"
    gate="bundle_deal"
    :validation-keys="['title', 'description']"
    :result="result"
    @result="result = $event"
  >

    <template v-slot:form="{hasError}">

      <!-- Title -->
      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          name="title"
          v-model="result.title"
          ref="title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>Description</label>
        <textarea
          name="description"
          v-model="result.description"
          placeholder="Enter bundle description"
          :class="{invalid: !!!result.description && hasError}"
        ></textarea>
        <span
          class="error"
          v-if="!!!result.description && hasError"
        >
          Description is required
        </span>
      </div>

      <div class="input-wrapper">
        <product-search
          :key="productSearchKey"
          ref="productSearch"
          @product-clicked="addBundleProduct"
        />
      </div>

      <div class="input-wrapper">
        <div class="bundle-wrapper">
          <div
            v-for="(product, index) in result.products"
            :key="product.id"
            class="bundle-group"
          >

            <div class="bundle-item">
              <product-tile
                :product="product"
                :is-lazy-image="false"
                :title-ellipsis="1"
              />

              <button
                type="button"
                class="remove-btn"
                @click="removeBundleProduct(index)"
              >
                ×
              </button>
            </div>

            <!-- PLUS -->
            <div
              v-if="index !== result.products.length - 1"
              class="bundle-plus"
            >
              +
            </div>

          </div>
        </div>
        <div
          class="error"
          v-if="result.products.length < 2 && hasError"
        >
          Please add at least two products to create a bundle
        </div>
      </div>

      <div class="input-wrapper">
        <div class="bundle-summary">
          <div class="summary-row">
            <span>Total Price:</span>
            <strong>{{ formatCurrency(totalPrice) }}</strong>
          </div>
          <div class="summary-row discount-row">
            <select v-model="result.discount_type" class="discount-select">
              <option value="fixed">Fixed</option>
              <option value="percentage">Percentage</option>
            </select>
            <input
              type="number"
              step="0.01"
              min="0"
              v-model.number="result.discount_value"
              placeholder="Discount"
              class="discount-input"
            />
          </div>
          <div class="summary-row final-price">
            <span>Final Price:</span>
            <strong>{{ formatCurrency(finalPrice) }}</strong>
          </div>
        </div>
      </div>

    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage";
  import Dropdown from "~/components/Dropdown";
  import util from "~/mixin/util";
  import ProductSearch from "../../components/partials/ProductSearch";
  import ProductTile from "../../components/ProductTile.vue";

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        showDropdown: false,
        productSearchKey: 0,
        result: {
          id: '',
          title: '',
          description: '',
          products: [],
          productsValidation: true,
          discount_type: 'fixed',
          discount_value: 0,
          buy: 0,
          free: 0
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown,
      ProductSearch,
      ProductTile
    },
    computed: {
      finalPrice() {
        const discount = Number(this.result.discount_value) || 0

        if (this.result.discount_type === 'percentage') {
          const discounted = this.totalPrice - (this.totalPrice * discount / 100)
          return Math.max(discounted, 0)
        }

        return Math.max(this.totalPrice - discount, 0)
      },
      totalPrice() {
        return this.result.products.reduce((sum, p) => {
          const selling = Number(p.selling || 0)
          const offered = Number(p.offered || 0)

          const price = offered > 0 ? offered : selling

          return sum + price
        }, 0)
      }
    },
    methods: {
      formatCurrency(value) {
        const amount = Number(value) || 0
        return `€${amount.toFixed(2)}`
      },
      closeDropdown() {
        this.showDropdown = false
      },
      addBundleProduct(product) {
        if (!product) return

        const exists = this.result.products.find(p => p.id === product.id)

        if (!exists) {
          this.result.products.push(product)
        }

        this.productSearchKey++
      },
      removeBundleProduct(index) {
        this.result.products.splice(index, 1)
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
    },
    async mounted() {
    },
    watch: {
      'result.products': {
        handler(val) {
          this.result.productsValidation = val.length >= 2
        },
        deep: true,
        immediate: true
      }
    }
  }
</script>

<style>
.bundle-wrapper {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  flex-wrap: wrap;
  gap: 24px;
}

.bundle-group {
  display: flex;
  align-items: center;
}

.bundle-item {
  position: relative;
  background: #fff;
  border: 1px solid #eee;
  border-radius: 10px;
  padding: 12px;
  text-align: center;
}

.bundle-item .p-tile {
  width: 100%;
}

.bundle-plus {
  font-size: 28px;
  font-weight: bold;
  color: #888;
  margin: 0 12px;
}

.remove-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: none;
  background: #ff4d4f;
  color: #fff;
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.bundle-operator {
  display: none; /* hide + since grid handles spacing */
}

.img-wrapper img {
  height: auto;
  width: 100%;
}

.bundle-summary {
  margin-top: 30px;
  padding: 20px;
  background: #fafafa;
  border: 1px solid #eee;
  border-radius: 12px;
  max-width: 400px;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-size: 15px;
}

.discount-row {
  gap: 10px;
}

.discount-select {
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid #ddd;
}

.discount-input {
  flex: 1;
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid #ddd;
}

.final-price {
  font-size: 18px;
  font-weight: bold;
  color: #111;
}
</style>
