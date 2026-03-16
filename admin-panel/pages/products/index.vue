<template>
  <div>
  <list-page
    ref="listPage"
    list-api="getProducts"
    delete-api="deleteProduct"
    route-name="products"
    :name="$t('title.prod')"
    :order-options="orderByProduct"
    gate="product"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >

  <template v-slot:extra-filters>
    <!-- Filters -->
      <div class="flex gap-15 align-center f-wrap category-filter">
        <select
          v-model="filters.category_id"
          class="border p-2 custom-dropdown"
          @change="applyFilters"
        >
          <option value="">All Categories</option>
          <option
            v-for="cat in categories"
            :key="cat.id"
            :value="cat.id"
          >
            {{ cat.title }}
          </option>
        </select>

        <select
          v-if="subcategories.length"
          v-model="filters.subcategory_id"
          class="border p-2 custom-dropdown"
          @change="applyFilters"
        >
          <option value="">All Subcategories</option>
          <option
            v-for="sub in subcategories"
            :key="sub.id"
            :value="sub.id"
          >
            {{ sub.title }}
          </option>
        </select>

        <select
          v-model="filters.status"
          class="border p-2 custom-dropdown"
          @change="applyFilters"
        >
          <option value="">All Status</option>
          <option value="1">Public</option>
          <option value="2">Private</option>
        </select>

        <div class="stock-filters flex gap-10 align-center">
          <label class="stock-chip">
            <input
              type="checkbox"
              :checked="filters.stock === 'in'"
              @change="toggleStock('in')"
            />
            <span>In Stock</span>
          </label>

          <label class="stock-chip">
            <input
              type="checkbox"
              :checked="filters.stock === 'out'"
              @change="toggleStock('out')"
            />
            <span>Out of Stock</span>
          </label>
      </div>
      </div>
</template>
    
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('prod.brand') }}</th>
          <th>{{ $t('error.cat') }}</th>
          <th>{{ $t('prod.tRule') }}</th>
          <th>{{ $t('prod.purchased') }}({{ currencyIcon }})</th>
          <th>{{ $t('prod.selling') }}({{ currencyIcon }})</th>
          <th>{{ $t('prod.offered') }}({{ currencyIcon }})</th>
          <th>{{ $t('prod.views') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>Actions</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td class="w-50x mx-w-50x">
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td>
            <nuxt-link
              class="dply-felx j-left link"
              :to="`/products/${value.id}`"
            >
              <lazy-image
                class="mr-20"
                :data-src="getThumbImageURL(value.image)"
                :alt="value.title"
              />
              <h5 class="mx-w-400x">{{ value.title }}</h5>
            </nuxt-link>
          </td>
          <td
            class="status"
            :class="{active: value.status == 1 }"
          >
            <span>{{ getStatus(value.status) }}</span>
          </td>
          <td>
            <nuxt-link
              v-if="value.brand"
              class="link"
              :to="`brands/${value.brand.id}`"
            >
              {{ value.brand.title }}
            </nuxt-link>
            <span v-else>{{ $t('prod.na') }}</span>
          </td>

          <td>
            <span class="dply-felx f-wrap gap-10 mx-w-300x j-left">
               <nuxt-link
                 v-for="(data, index) in value.product_categories"
                 :key="index"
                 class="link"
                 :to="`/categories/${data.category.id}`"
               >
                {{ data.category.title }}
              </nuxt-link>
            </span>

          </td>

          <td>
            <nuxt-link
              v-if="value.tax_rules"
              class="link"
              :to="`tax-rules/${value.tax_rules.id}`"
            >
              {{ value.tax_rules.title }}
            </nuxt-link>
            <span v-else>{{ $t('prod.na') }}</span>
          </td>
          <td>{{ value.purchased }} {{ currencyIcon }}</td>
          <td>{{ value.selling }} {{ currencyIcon }}</td>
          <td>
            <span v-if="value.offered">
               {{ value.offered }} {{ currencyIcon }}
            </span>
          </td>
          <td>{{ value.views }}</td>
          <td>{{ value.created }}</td>
          <td class="flex">
            <select class="lite-btn custom-dropdown" @change="onActionChange($event, value.id)">
              <option value="" selected disabled>Select Action</option>
              <option value="view">
                {{ $t('fSale.view') }}
              </option>
              <option value="reviews">
                {{ $t('prod.reviews') }}
              </option>

              <option
                v-if="$can('product', 'create')"
                value="duplicate"
              >
                {{ $t('prod.duplicate') }}
              </option>

              <option
                v-if="$can('product', 'edit')"
                value="edit"
              >
                {{ $t('category.edit') }}
              </option>

              <option
                v-if="$can('product', 'delete')"
                value="delete"
              >
                {{ $t('category.delete') }}
              </option>
              <option value="seo">
                {{ 'SEO' }}
              </option>
              <option value="draft">
                {{ 'Draft' }}
              </option>
              <option value="embed">
                {{ 'Embed' }}
              </option>
            </select>
          </td>
        </tr>
    </template>
  </list-page>

  <!-- Embed Modal -->
  <div v-if="showEmbedModal" class="embed-modal-overlay">
    <div class="embed-modal">

      <!-- Header -->
      <div class="embed-header">
        <h2>Product Preview</h2>
        <button class="close-btn" @click="closeEmbed">✕</button>
      </div>

      <div class="embed-body">

        <!-- LEFT: Preview -->
        <div class="embed-preview">
          <iframe
            :src="embedUrl"
            width="100%"
            height="450"
            :style="previewStyle"
          ></iframe>
        </div>

        <!-- RIGHT SIDE -->
        <div class="embed-right">

          <!-- Configuration -->
          <div class="config-box">
            <h3>Configuration</h3>
            <div class="config-options">
              <label>
                <input type="radio" value="none" v-model="borderType" />
                No Border
              </label>

              <label>
                <input type="radio" value="solid" v-model="borderType" />
                Have Border
              </label>
            </div>
          </div>

          <!-- Code Box -->
          <div class="code-box">
            <h3>Code</h3>

            <textarea
              readonly
              :value="generatedIframeCode"
            ></textarea>

            <button class="copy-btn icon-btn" @click="copyGeneratedCode">
              <i class="fas fa-copy"></i>
            </button>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>
</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import {mapGetters} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from "~/components/LazyImage"
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        filters: {
          category_id: '',
          subcategory_id: '',
          status: '',
          stock: ''
        },
        categories: [],
        orderByProduct: {
          title: { title: this.$t('index.title') },
          category_id: { title: this.$t('category.catUp') },
          purchased: { title: this.$t('prod.purchased') },
          selling: { title: this.$t('prod.selling') },
          offered: { title: this.$t('prod.offered') },
          created_at: { title: this.$t('category.date') },
          status: { title: this.$t('category.status') }
        },
        loading: false,
        showEmbedModal: false,
        selectedProductId: null,
        borderType: 'solid'
      }
    },
    mixins: [util, bulkDelete],
    components: {
      LazyImage,
      ListPage
    },
    computed: {
      previewStyle() {
        return {
          border: this.borderType === 'none'
            ? 'none'
            : '3px solid black'
        }
      },
      subcategories() {
        const selected = this.categories.find(
          c => c.id == this.filters.category_id
        )
        return selected ? selected.child : []
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting']),
      embedUrl() {
        if (!this.selectedProductId) return ''
        return `https://shop.fixmypc.ie/embed/product/${this.selectedProductId}`
      },

      generatedIframeCode() {
       const borderStyle =
        this.borderType === 'none'
      ? 'border:none;'
      : 'border: 2px solid black; border-radius:10px;'

      return `<iframe 
      src="${this.embedUrl}"
      width="320"
      height="450"
      style="${borderStyle}">
    </iframe>`
    }
    },
    methods: {
      openEmbedModal(productId) {
        this.selectedProductId = productId
        this.borderType = 'solid'
        this.showEmbedModal = true
      },

      closeEmbed() {
        this.showEmbedModal = false
        this.selectedProductId = null
      },

      copyGeneratedCode() {
        navigator.clipboard.writeText(this.generatedIframeCode)
        this.showAlert('Embed code copied!')
      },
      async duplicateProduct(productId) {
        if (!confirm(this.$t('prod.confirmDuplicate'))) return;
        
        try {
          this.loading = true;
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
          const response = await this.$axios.post(`${baseUrl}api/admin/products/${productId}/duplicate`);
          
          if (response.data) {
            const duplicateProductId = response.data.id;
            
            // Show success message
            this.showAlert('Product duplicated successfully!');
            
            // Redirect to the duplicated product after a short delay
            setTimeout(() => {
              this.$router.push(`/products/${duplicateProductId}`);
            }, 2000);
          }
        } catch (error) {
          console.error('Error duplicating product:', error);
          this.showAlert('Error duplicating product', 'error');
        } finally {
          this.loading = false;
        }
      },
      
      showAlert(message, type = 'success') {
        if (type === 'success') {
          this.$toast.success(message)
        } else if (type === 'error') {
          this.$toast.error(message)
        } else if (type === 'info') {
          this.$toast.info(message)
        } else {
          this.$toast(message)
        }
      },
      applyFilters() {
        const nextQuery = { ...this.$route.query }
        if (this.filters.category_id) {
          nextQuery.category_id = this.filters.category_id
        }else{
          delete nextQuery.category_id
          this.filters.subcategory_id = ''
          delete nextQuery.subcategory_id
        }

        if (this.filters.subcategory_id) {
          nextQuery.subcategory_id = this.filters.subcategory_id
        } else {
          delete nextQuery.subcategory_id
        }

        if (this.filters.status) {
          nextQuery.status = this.filters.status
        } else {
          delete nextQuery.status
        }

        if(this.filters.stock){
          nextQuery.stock = this.filters.stock
        } else {
          delete nextQuery.stock
        }

        nextQuery.page = 1

        this.$router.replace({ query: nextQuery })

        this.$refs.listPage.fetchingData()
      },
      toggleStock(value) {
        this.filters.stock = this.filters.stock === value ? '' : value
        this.applyFilters()
      },
      onActionChange(event, productId) {
        const action = event.target.value;
        if (!action) return

        switch (action) {
          case 'view':
            this.$router.push(`/products/${productId}`)
            break
          case 'reviews':
            this.$router.push(`/rating-reviews?product=${productId}`)
            break

          case 'duplicate':
            this.duplicateProduct(productId)
            break

          case 'edit':
            this.$refs.listPage.editItem(productId)
            break

          case 'delete':
            this.$refs.listPage.deleteItem(productId)
            break

          case 'seo':
            this.$router.push(`/products/${productId}/seo`)
            break

          case 'draft':
            this.$router.push(`/products/${productId}`)
            break

          case 'embed':
            this.openEmbedModal(productId)
            break
        }

        // reset select after action
        event.target.value = ''
      }
    },
    async mounted() {
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
      const response = await this.$axios.get(`${baseUrl}api/admin/product/with-categories`);
      this.categories = response.data.categories;

      this.filters = {
        category_id: this.$route.query.category_id
          ? Number(this.$route.query.category_id)
          : '',
        subcategory_id: this.$route.query.subcategory_id
        ? Number(this.$route.query.subcategory_id)
        : '',
        status: this.$route.query.status
          ? Number(this.$route.query.status)
          : '',
        stock: this.$route.query.stock ? this.$route.query.stock : ''
      }
    }
  }
</script>

<style scoped>
.duplicate-btn {
  background-color: #4CAF50;
  color: white;
}

.duplicate-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
select.custom-dropdown {
    cursor: pointer;
    -webkit-user-select: none;
    -moz-user-select: none;
    user-select: none;
    padding: 0 15px 0 20px;
    height: 42px;
    line-height: 42px;
    background: linear-gradient(to bottom, #f7f8fa, #e7e9ec);
    border: 1px solid #bbb;
    border-radius: 50px;
    font-size: 0.95em;
    min-width: 80px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: all 0.1s;
}

.stock-filters {
  flex-wrap: wrap;
}

.stock-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid #d0d5dd;
  border-radius: 20px;
  font-size: 13px;
  cursor: pointer;
  user-select: none;
  background: #fff;
  color: #344054;
  transition: all 0.2s ease;
}

.stock-chip input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.stock-chip:hover {
  border-color: #4CAF50;
  background: #f6fff8;
}

.stock-chip input:checked + span {
  font-weight: 600;
  color: #1b5e20;
}

.stock-chip input:checked ~ span,
.stock-chip input:checked + span {
  color: #1b5e20;
}

.stock-chip input:checked ~ span::before,
.stock-chip input:checked + span::before {
  content: "✓";
  margin-right: 6px;
  font-size: 12px;
}

.stock-chip input:checked ~ span {
  background: transparent;
}

.stock-chip input:checked {
}

.stock-chip input[value="out"]:checked + span {
  color: #b42318;
}

.stock-chip input[value="out"]:checked + span::before {
  content: "✕";
}

.dply-felx {
    align-items: baseline;
}

.category-filter {
    display: flex;
    align-items: anchor-center;
}

.stock-chip {
    padding: 12px 25px !IMPORTANT;
    background: linear-gradient(to bottom, #f7f8fa, #e7e9ec) !important;
}

/* ===== Overlay ===== */
.embed-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.65);
  backdrop-filter: blur(4px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 9999;
  padding: 20px;
}

/* ===== Modal Container ===== */
.embed-modal {
  background: #ffffff;
  width: 100%;
  max-width: 1150px;
  border-radius: 16px;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
  overflow: hidden;
  animation: modalFade 0.25s ease;
}

/* ===== Header ===== */
.embed-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 22px 28px;
  border-bottom: 1px solid #f1f5f9;
}

.embed-header h2 {
  font-size: 20px;
  font-weight: 600;
  color: #111827;
  margin: 0;
}

/* Close Button */
.close-btn {
  background: #111827;
  color: #fff;
  border: none;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.close-btn:hover {
  background: #ef4444;
  transform: scale(1.05);
}

/* ===== Body Layout ===== */
.embed-body {
  display: flex;
  gap: 20px;
  padding: 15px;
}

/* ===== Preview Card ===== */
.embed-preview {
  background: #f9fafb;
  border-radius: 14px;
  padding: 18px;
  box-shadow: inset 0 0 0 1px #e5e7eb;
  display: flex;
  justify-content: center;
  align-items: center;
}

.embed-preview iframe {
  border-radius: 10px;
  transition: all 0.2s ease;
}

/* ===== Right Section ===== */
.embed-right {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 25px;
}

/* ===== Configuration Card ===== */
.config-box {
  background: #f9fafb;
  border-radius: 14px;
  padding: 20px;
  box-shadow: inset 0 0 0 1px #e5e7eb;
}

.config-box h3 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 14px;
  color: #111827;
}

.config-box label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: #374151;
  margin-bottom: 10px;
  cursor: pointer;
}

.config-box input[type="checkbox"] {
  accent-color: #111827;
  width: 16px;
  height: 16px;
}

/* ===== Code Card ===== */
.code-box {
  background: #0f172a;
  border-radius: 14px;
  padding: 20px;
  color: #f8fafc;
  position: relative;
}

.code-box h3 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 12px;
  color: #f8fafc;
}

.code-box textarea {
  width: 100%;
  height: 130px;
  resize: none;
  padding: 14px;
  border-radius: 10px;
  border: none;
  font-family: "Fira Code", monospace;
  font-size: 13px;
  background: #1e293b;
  color: #e2e8f0;
  outline: none;
}

/* Copy Button */
.copy-btn {
  margin-top: 14px;
  width: 42px;
  height: 42px;
  border-radius: 10px;
  border: none;
  background: #ffffff;
  color: #111827;
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}

.copy-btn:hover {
  background: #e5e7eb;
  transform: scale(1.05);
}

.copy-btn:active {
  transform: scale(0.95);
}

.embed-header button.close-btn:hover {
    background: #0f172a !important;
    opacity: 0.7;
}
.embed-preview {
    width: fit-content;
}
 
@media only screen and (max-width:980px) {
.embed-body {
    flex-direction: column;
}
}

/* ===== Animation ===== */
@keyframes modalFade {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
</style>