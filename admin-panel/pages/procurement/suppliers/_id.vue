<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setSupplier"
    get-api="getSupplier"
    route-name="procurement/suppliers"
    name="Supplier"
    :validation-keys="['name','email','country_code','phone','address','categories']"
    :result="result"
    @result="settingResult"
  >
    <template v-slot:form="{ hasError }">

      <!-- Name -->
      <div class="input-wrapper">
        <label>Name *</label>
        <input
          type="text"
          v-model="result.name"
          :class="{ invalid: !result.name && hasError }"
        >
        <span class="error" v-if="!result.name && hasError">
          Name is required
        </span>
      </div>

      <!-- Email -->
      <div class="input-wrapper">
        <label>Email *</label>
        <input
          type="email"
          v-model="result.email"
          :class="{ invalid: !result.email && hasError }"
        >
        <span class="error" v-if="!result.email && hasError">
          Email is required
        </span>
      </div>

      <!-- Country Code Dropdown -->
      <div class="input-wrapper">
        <label>Country Code *</label>
        <select
          v-model="result.country_code"
          :class="{ invalid: !result.country_code && hasError }"
        >
          <option value="">Select Code</option>
          <option v-for="code in countryCodes" :key="code" :value="code">
            {{ code }}
          </option>
        </select>
        <span class="error" v-if="!result.country_code && hasError">
          Country code required
        </span>
      </div>

      <!-- Phone (numbers only) -->
      <div class="input-wrapper">
        <label>Phone *</label>
        <input
          type="text"
          v-model="result.phone"
          @input="result.phone = result.phone.replace(/[^0-9]/g, '')"
          :class="{ invalid: !result.phone && hasError }"
        >
        <span class="error" v-if="!result.phone && hasError">
          Phone is required
        </span>
      </div>

      <!-- Address -->
      <div class="input-wrapper">
        <label>Address *</label>
        <textarea
          v-model="result.address"
          :class="{ invalid: !result.address && hasError }"
        ></textarea>
        <span class="error" v-if="!result.address && hasError">
          Address required
        </span>
      </div>

      <!-- Multiple Categories -->
      <div class="input-wrapper">
        <label>Categories *</label>
        <multiselect
          v-model="selectedCategories"
          :options="categoryList"
          :multiple="true"
          :searchable="true"
          track-by="id"
          label="title"
          placeholder="Select categories"
          class="category-multiselect"
        >
          <template #selection="{ values }">
            <span v-if="values.length" class="multiselect-count">
              Choose ({{ values.length }})
            </span>
          </template>
        </multiselect>
        <span
          class="error"
          v-if="!selectedCategories.length && hasError"
        >
          Select at least one category
        </span>
      </div>

    </template>
  </data-page>
</template>

<script>
import DataPage from "~/components/partials/DataPage"
import util from "~/mixin/util"
import Multiselect from 'vue-multiselect'
import 'vue-multiselect/dist/vue-multiselect.min.css'
import { mapActions } from "vuex"

export default {
  name: "suppliers",
  middleware: ['common-middleware', 'auth'],

  components: {
    DataPage,
    Multiselect
  },

  mixins: [util],

  data() {
    return {
      categoryList: [],
      selectedCategories: [],
      countryCodes: ["+91","+1","+44","+971","+61","+353","+81","+49","+33","+86"],

      result: {
        id: '',
        name: '',
        email: '',
        country_code: '',
        phone: '',
        address: '',
        categories: []
      }
    }
  },

  async mounted() {
    await this.fetchCategories()
  },

  watch: {
    // sync selected categories into result before save
    selectedCategories(val) {
      this.result.categories = val.map(c => c.id)
    }
  },

  methods: {
    settingResult(evt) {
      this.result = {
        ...evt,
        categories: evt.categories
          ? evt.categories.map(c => c.id)
          : []
      }
      if (evt.categories) {
        this.selectedCategories = evt.categories
      }
    },

    async fetchCategories(){
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
      const response = await this.$axios.get(`${baseUrl}api/admin/product/with-categories`);
      this.categoryList = response.data.categories || []
    },
    ...mapActions('common', ['getRequest'])
  }
}
</script>

<style scoped>
.input-wrapper {
  margin-bottom: 20px;
}

label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
}

input,
textarea,
select {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

input.invalid,
textarea.invalid,
select.invalid {
  border-color: #ff3860;
}

.error {
  color: #ff3860;
  font-size: 12px;
  margin-top: 5px;
  display: block;
}
</style>