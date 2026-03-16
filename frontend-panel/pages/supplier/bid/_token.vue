<template>
  <div class="page-container">

    <!-- Order Number Heading -->
    <div class="order-header">
      <h2>Order Number : #{{ orderId }}</h2>
    </div>

    <div class="wholesale-wrapper">
      <div
        class="wholesale-card"
        v-for="(item, index) in bidItems"
        :key="item.id"
      >
        <div class="wholesale-inner">

          <!-- Left Image -->
          <div class="wholesale-image">
            <img
              :src="getProductImage(item)"
              alt="Product"
            />
          </div>

          <!-- Right Content -->
          <div class="wholesale-content">

            <h3 class="wholesale-title">
              {{ item.product.title }}
            </h3>

            <!-- Dynamic Attributes -->
            <div class="attr-badges">
              <span
                class="badge"
                v-for="attr in item.inventory.inventory_attributes"
                :key="attr.attribute_value.id"
              >
                {{ attr.attribute_value.attribute.title }} :
                {{ attr.attribute_value.title }}
              </span>
            </div>

            <p class="quantity-text">
              Quantity : {{ item.quantity }} pcs
            </p>

            <div class="wholesale-actions" v-if="item.inventory.quantity > 0">

              <div class="price-input">
                <span class="currency">€</span>
                <input
                  type="number"
                  v-model="item.price"
                  placeholder="Enter wholesale price"
                />
              </div>

              <select v-model="item.grade" class="grade-select">
                <option disabled value="">--GRADE--</option>
                <option>Brand New</option>
                <option>Like New</option>
                <option>Grade A</option>
                <option>Grade B</option>
                <option>Grade C</option>
              </select>

              <button
                class="submit-btn ajax-btn primary-btn mlr-5 mtb-sm-5"
                @click="submitBid(item)"
              >
                Submit
              </button>

            </div>
          </div>
        </div>

        <!-- No Stock -->
        <div class="no-stock" v-if="item.inventory.quantity <= 0">
          Sorry, no stock remaining
        </div>

      </div>
    </div>

  </div>
</template>

<script>
import axios from "axios"

export default {
  layout: 'blank',

  data() {
    return {
      token: '',
      orderId: '',
      supplierId: '',
      bidItems: [],
      loading: false
    }
  },

  mounted() {
    this.token = this.$route.params.token
    if (!this.token) {
      console.error("Token missing in URL")
      return
    }
    this.fetchOrder()
  },

  methods: {

    async fetchOrder() {

      try {
        this.loading = true

        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const response = await this.$axios.get(`${baseUrl}api/v1/order/${this.token}`);
        this.orderId = response.data.order_number
        this.bidItems = response.data.data.map(item => ({
          ...item,
          price: '',
          grade: ''
        }))

      } catch (error) {
        console.error("API Error:", error)
      } finally {
        this.loading = false
      }
    },

    getProductImage(item) {
      if (item.product.product_images.length > 0) {
        return `https://shop.fixmypc.ie/uploads/${item.product.product_images[0].image}`
      }
      return `https://shop.fixmypc.ie/uploads/${item.product.image}`
    },

    submitBid(item) {
      if (!item.price || !item.grade) {
        alert("Please enter price and select grade")
        return
      }

      console.log("Submitted:", {
        order_id: this.orderId,
        supplier_id: this.supplierId,
        product_id: item.product_id,
        inventory_id: item.inventory_id,
        price: item.price,
        grade: item.grade
      })
    }
  }
}
</script>
<style>
/* Page Layout */
.page-container {
  max-width: 1100px;
  margin: 60px auto;   /* top & bottom spacing */
  padding: 0 20px;     /* left & right spacing */
}

/* Order Header */
.order-header {
  text-align: center;
  margin-bottom: 40px;
}

.order-header h2 {
  font-size: 24px;
  font-weight: 600;
}

/* Wrapper */
.wholesale-wrapper {
  display: flex;
  flex-direction: column;
  gap: 30px;
}

/* Card */
.wholesale-card {
  border: 2px solid #000;
  background: #fff;
  border-radius: 6px;
  overflow: hidden;
}

/* Inner Layout */
.wholesale-inner {
  display: flex;
  padding: 25px;
  gap: 25px;
}

/* Image */
.wholesale-image {
  width: 180px;
  height: 180px;
  border: 2px solid #000;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.wholesale-image img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

/* Content */
.wholesale-content {
  flex: 1;
}

.wholesale-title {
  font-size: 20px;
  font-weight: 600;
  margin-bottom: 15px;
}

.attr-badges {
  display: flex;
  gap: 10px;
  margin-bottom: 15px;
  flex-wrap: wrap;
}

.badge {
  border: 2px solid #000;
  padding: 6px 14px;
  font-size: 14px;
  background: #f5f5f5;
  border-radius: 4px;
}

.quantity-text {
  font-size: 18px;
  margin-bottom: 20px;
}

/* Actions */
.wholesale-actions {
  display: flex;
  align-items: center;
  gap: 15px;
  flex-wrap: wrap;
}

.price-input {
  display: flex;
  align-items: center;
  border: 2px solid #000;
  border-radius: 4px;
  overflow: hidden;
}

.currency {
  padding: 10px 12px;
  background: #f3f3f3;
  border-right: 2px solid #000;
  font-weight: 600;
}

.price-input input {
  border: none;
  padding: 10px;
  width: 150px;
  outline: none;
}

.grade-select {
  border: 2px solid #000;
  padding: 10px;
  border-radius: 4px;
  min-width: 140px;
}

.submit-btn {
  padding: 10px 25px;
  border: 2px solid #000;
  cursor: pointer;
  font-weight: 600;
  border-radius: 4px;
  transition: 0.2s ease;
}

/* No Stock */
.no-stock {
  border-top: 2px solid #000;
  padding: 15px;
  text-align: center;
  font-size: 16px;
  background: #f7f7f7;
}

/* ✅ Mobile Responsive */
@media (max-width: 768px) {

  .page-container {
    margin: 30px auto;
  }

  .wholesale-inner {
    flex-direction: column;
    align-items: center;
    text-align: center;
  }

  .wholesale-image {
    margin-bottom: 20px;
  }

  .wholesale-actions {
    flex-direction: column;
    align-items: stretch;
    width: 100%;
  }

  .price-input input {
    width: 100%;
  }

  .submit-btn {
    width: 100%;
  }

  .grade-select {
    width: 100%;
  }
}
</style>

