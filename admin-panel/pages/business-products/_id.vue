<template>
    <data-page
        class="detail-width"
        ref="dataPage"
        set-api="setBusinessProduct"
        get-api="getBusinessProduct"
        route-name="business-products"
        name="Business Product"
        :validation-keys="['product_id', 'pricing']"
        :result="result"
        @result="settingResult"
    >
        <template v-slot:form="{hasError}">
            <!-- PRODUCT SELECT -->
            <div class="card">
            <div class="input-wrapper">
                <label>Select Product</label>
                <select v-model="result.product_id" class="input"  @change="onProductChange">
                <option disabled value="">Select product</option>
                <option v-for="p in products" :key="p.id" :value="p.id">
                    {{ p.title }}
                </option>
                </select>
            </div>
            </div>

            <!-- PRICING TABLE -->
            <div v-if="result.product_id" class="card">

            <div class="pricing-table">

                <!-- HEADER -->
                <div class="pricing-row header">
                <div>Quantity Range</div>
                <div>Offered Price</div>
                <div>Discount Type</div>
                <div>Discount Value</div>
                <div>Final Price</div>
                </div>

                <!-- ROWS -->
                <div
                v-for="(row, index) in result.pricing"
                :key="index"
                class="pricing-row"
                >

                <!-- RANGE -->
                <div class="range">
                    <input type="number" v-model="row.min" placeholder="Min" />
                    <span>-</span>
                    <input type="number" v-model="row.max" placeholder="Max" />
                </div>

                <!-- WHOLESALE -->
                <input
                    type="number"
                    step="0.01"
                    v-model="row.wholesale_price"
                    disabled
                />

                <!-- TYPE -->
                <div class="select-wrapper">
                    <select v-model="row.discount_type" @change="calculate(row)">
                        <option value="percentage">Percentage</option>
                        <option value="fixed">Fixed</option>
                    </select>
                    <span class="arrow">⌄</span>
                </div>

                <!-- VALUE -->
                <div class="discount-value">
                    <input
                    type="number"
                    step="0.01"
                    v-model="row.discount_value"
                    @input="handleDecimal(row, 'discount_value')"
                    />
                    <span v-if="row.discount_type === 'percentage'">%</span>
                    <span v-else>€</span>
                </div>

                <!-- FINAL -->
                <div class="final">
                    € {{ row.final_price.toFixed(2) }}
                </div>

                <button class="delete-btn" @click="removeRow(index)">
                    ✕
                </button>
                </div>

            </div>

            <!-- ADD ROW -->
            <button type="button" class="add-row-btn" @click="addRow()">
                + Add Row
            </button>

            </div>
        </template>
    </data-page>
</template>
<script>
import DataPage from '~/components/partials/DataPage'
import Dropdown from '~/components/Dropdown'
import util from '~/mixin/util'
import { mapActions } from 'vuex'

export default {
    name: "business-products",
    middleware: ['common-middleware','auth'],
    mixins: [util],
    components: {
        DataPage,
        Dropdown
    },
  data(){
    return {
        products: [],
        result: {
            id: '',
            product_id : '',
            pricing : []
        }
    }
  },
  computed: {

  },
  methods: {
    onProductChange() {
      const product = this.products.find(p => p.id == this.result.product_id);
      if (!product) return;
      const basePrice = Number(product.offered ?? 0);
      if (this.result.pricing.length === 0) {
          this.addRow(basePrice);
      }
      this.result.pricing.forEach(row => {
        row.wholesale_price = basePrice;
        this.calculate(row);
      });
    },
    settingResult(evt) {
        this.result = {
            id: evt.product_id,
            product_id: evt.product_id || '',
            pricing: evt.pricing || []
        };

        this.result.pricing.forEach(row => {
            row.wholesale_price = Number(row.wholesale_price ?? 0);
            row.discount_value = Number(row.discount_value ?? 0);
            row.final_price = Number(row.final_price ?? 0);

            this.calculate(row); // recalc safely
        });
    },
    handleDecimal(row, field) {
        let value = row[field];
        if (value === '' || value === null) return;
        value = parseFloat(value);
        if (!isNaN(value)) {
            row[field] = Math.round(value * 100) / 100;
        }
        this.calculate(row);
    },
    removeRow(index) {
        this.result.pricing.splice(index, 1);
    },
    async fetchProducts() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/admin/product/with-categories`);
        this.products = response.data.products;
    },
    addRow(price = null) {
      console.log('Adding row with price:', price);
      if (price === null) {
          const product = this.products.find(p => p.id == this.result.product_id);
          price = Number(product?.offered ?? 0);
      }
      console.log('Calculated price for new row:', price);
      const safePrice = Number(price);
      const row = {
          min: '',
          max: '',
          wholesale_price: isNaN(safePrice) ? 0 : safePrice,
          discount_type: 'fixed',
          discount_value: 0,
          final_price: isNaN(safePrice) ? 0 : safePrice
      };

      this.calculate(row);

      this.result.pricing.push(row);
    },
    calculate(row) {
        let price = Number(row.wholesale_price) || 0;
        let discount = Number(row.discount_value) || 0;

        if (row.discount_type === 'percentage') {
          row.final_price = price - (price * discount / 100);
        } else {
          row.final_price = price - discount;
        }

        if (row.final_price < 0) {
          row.final_price = 0;
        }
        row.final_price = Number(row.final_price);
    }
  },
  mounted(){
    this.fetchProducts();
  },
}
</script>
<style scoped>

.detail-width {
  max-width: 900px;
  margin: auto;
}

/* CARD */
.card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 20px;
  background: #fff;
  margin-bottom: 16px;
}

/* INPUT */
.input-wrapper {
  margin-bottom: 10px;
}

.input-wrapper label {
  display: block;
  margin-bottom: 6px;
  font-weight: 500;
}

.input {
  width: 100%;
  height: 36px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  padding: 0 10px;
}

/* TABLE */
.pricing-table {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}

.pricing-row {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr 50px;
  gap: 10px;
  padding: 12px;
  align-items: center;
  border-bottom: 1px solid #f1f1f1;
}

.pricing-row.header {
  background: #f9fafb;
  font-weight: 600;
  font-size: 13px;
}

/* RANGE */
.range {
  display: flex;
  align-items: center;
  gap: 6px;
}

.range input {
  width: 75px;
}

/* DISCOUNT */
.discount-value {
  display: flex;
  align-items: center;
  gap: 6px;
}

/* FINAL */
.final {
  font-weight: 600;
}

/* BUTTON */
.add-row-btn {
  margin-top: 12px;
  border: 1px dashed #4f6ef7;
  background: #fff;
  width: 100%;
  cursor: pointer;
  border-radius: 6px;
  color: #4f6ef7;
}

.add-row-btn:hover {
  background: #f5f7ff;
}
.select-wrapper {
  position: relative;
  width: 100%;
}

.select-wrapper select {
  width: 100%;
  height: 38px;
  padding: 0 35px 0 10px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #fff;
  appearance: none;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s ease;
}

/* Hover + Focus */
.select-wrapper select:hover {
  border-color: #4f6ef7;
}

.select-wrapper select:focus {
  outline: none;
  border-color: #4f6ef7;
  box-shadow: 0 0 0 2px rgba(79,110,247,0.1);
}

/* Arrow */
.select-wrapper .arrow {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  font-size: 14px;
  color: #6b7280;
}

.delete-btn:hover {
  background: #fecaca;
}
</style>