<template>
  <div
    class="embed-wrapper"
    :class="[layoutClass, productBorderClass]"
  >
    <div v-if="loading" class="loader">
      <div class="spinner"></div>
    </div>
    <template v-else-if="filteredProducts.length">
      <EmbedProductTile
        v-for="product in filteredProducts"
        :key="product.id"
        :product="product"
        :isLazyImage="false"
      />
    </template>
    <div v-else class="no-products">
      No products found
    </div>
  </div>
</template>

<script>
import EmbedProductTile from "~/components/EmbedProductTile"

export default {
  layout: 'blank', // remove header/footer if you have
  components: { EmbedProductTile },

  data() {
    return {
      products: [],
      loading: true,
    }
  },

  computed: {
    layoutClass() {
      return this.$route.query.layout === 'vertical'
        ? 'vertical'
        : 'horizontal'
    },
    productBorderClass() {
      return this.$route.query.product_border == 1
        ? 'with-product-border'
        : ''
    },
    filteredProducts() {
      let products = this.products

      if (this.$route.query.offers == 1) {
        products = products.filter(p => p.is_offer)
      }

      if (this.$route.query.limit && this.$route.query.limit !== 'all') {
        products = products.slice(0, Number(this.$route.query.limit))
      }

      return products
    }
  },

  async mounted() {
    try{
      const id = this.$route.params.id
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
      const response = await this.$axios.get(`${baseUrl}api/v1/embed/category/${id}/products`);
      this.products = response?.data?.data || [];
    }catch (error) {
      this.products = []
    } finally {
      this.loading = false
    }
  }
}
</script>

<style>
body {
  margin: 0;
}

/* Wrapper */
.embed-wrapper {
  width: 300px;
  padding: 20px;
  display: flex;
  gap: 16px;
}

/* Horizontal Layout */
.horizontal {
    flex-direction: row;
    width: 100%;
    flex-wrap: wrap;
    justify-content: center;
}

/* Vertical Layout */
.vertical {
  flex-direction: column;
}

/* Product Tile Animation */
.p-tile {
  position: relative;
  overflow: hidden;
  transition: 0.3s ease;
  width: 100%;
  max-width: 300px;
}

/* Blur entire card content */
.p-tile:hover .page-link {
  filter: blur(3px);
  transform: scale(1.02);
}

/* Overlay */
.card-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(2px);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: 0.3s ease;
  z-index: 5;
}

/* Show overlay on hover */
.p-tile:hover .card-overlay {
  opacity: 1;
}

/* Buy button */
.buy-btn {
  padding: 12px 24px;
  background: #4CAF50;
  color: #fff;
  border-radius: 30px;
  font-weight: bold;
  font-size: 14px;
  transition: 0.2s ease;
  cursor: pointer;
}

.buy-btn:hover {
  background: #388e3c;
}

.item-title {
  padding-left: 15px;
}

.with-product-border .p-tile {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, .05);
  padding: 12px;
  transition: all .3s ease;
  width: 100%;
  max-width: 300px;
}

.no-products {
  width: 100%;
  text-align: center;
  padding: 40px 20px;
  font-size: 16px;
  font-weight: 500;
  color: #6b7280;
}

.with-product-border .p-tile:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 20px rgba(0,0,0,0.08);
}

.loader {
  width: 100%;
  padding: 50px 0;
  display: flex;
  justify-content: center;
  align-items: center;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #e5e7eb;
  border-top: 4px solid #4CAF50;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>