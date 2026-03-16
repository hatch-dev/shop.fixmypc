<template>
  <div class="embed-wrapper">
    <EmbedProductTile
      v-if="product"
      :product="product"
      :isLazyImage="false"
    />
  </div>
</template>

<script>
import EmbedProductTile from '~/components/EmbedProductTile'

export default {
  layout: 'blank',
  components: { EmbedProductTile },

  async asyncData({ $axios, params }) {
    const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
    const response = await $axios.get(`${baseUrl}api/v1/product/${params.id}?id=${params.id}`);
    const data = response?.data?.data;
    return { product: data }
  },

  data() {
    return {
      product: null
    }
  }
}
</script>

<style>
body {
  margin: 0;
}

.embed-wrapper {
  width: 300px;
  padding: 20px;
}

.p-tile {
  position: relative;
  overflow: hidden;
  transition: 0.3s ease;
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


</style>