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
      <div class="product-filter-bar">
        <select
          v-model="filters.category_id"
          class="border p-2 custom-dropdown"
          @change="applyFilters"
        >
          <option value="">{{ $t('pages.products.allCategories') }}</option>
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
          <option value="">{{ $t('pages.products.allSubcategories') }}</option>
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
          <option value="">{{ $t('pages.products.allStatus') }}</option>
          <!-- "Draft" everywhere: the editor badge and the status dropdown in
               the sidebar both call status 2 a draft, and this list was the
               only place calling the same thing Private. -->
          <option value="1">{{ $t('pages.products.public') }}</option>
          <option value="2">{{ $t('pages.products.draft') }}</option>
        </select>

        <div class="stock-filters">
          <label class="stock-chip">
            <input
              type="checkbox"
              :checked="filters.stock === 'in'"
              @change="toggleStock('in')"
            />
            <span>{{ $t('pages.products.inStock') }}</span>
          </label>

          <label class="stock-chip">
            <input
              type="checkbox"
              :checked="filters.stock === 'out'"
              @change="toggleStock('out')"
            />
            <span>{{ $t('pages.products.outOfStock') }}</span>
          </label>
      </div>

        <!-- Custom grid column picker -->
        <div class="grid-tool-wrap">
          <button
            type="button"
            class="grid-tool-btn grid-columns-btn"
            :class="{ 'is-open': showColumnSelector }"
            @click.stop="toggleColumnSelector"
          >
            <i class="fa-solid fa-table-columns" aria-hidden="true"></i>
            <span>{{ $t('pages.products.customGrid') }}</span>
            <span class="grid-tool-count">{{ visibleColumns.length }}</span>
          </button>

          <div
            v-if="showColumnSelector"
            class="grid-column-selector"
            @click.stop
          >
            <div class="grid-column-head">
              <strong>{{ $t('pages.products.chooseColumns') }}</strong>
              <span>{{ $t('pages.products.chooseColumnsHint') }}</span>
            </div>

            <div class="grid-column-list">
              <div
                v-for="(col, colIndex) in gridColumns"
                :key="col.key"
                class="grid-column-option"
                :class="{ 'is-dragging': draggedColumnIndex === colIndex }"
                draggable="true"
                @dragstart="dragColumn(colIndex)"
                @dragover.prevent
                @drop="dropColumn(colIndex)"
              >
                <label class="grid-column-label">
                  <input
                    type="checkbox"
                    :checked="col.visible"
                    :disabled="col.locked"
                    @change="toggleColumn(col)"
                  />
                  <span>{{ col.label }}</span>
                </label>
              </div>
            </div>

            <div class="grid-column-actions">
              <button type="button" class="grid-btn ghost xs" @click="resetColumns">
                {{ $t('pages.products.resetDefault') }}
              </button>
            </div>
          </div>
        </div>
      </div>
</template>

    <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">
            <input type="checkbox" @change="checkAll">
          </th>
          <th class="w-50x mx-w-50x">#</th>
          <th
            v-for="col in visibleColumns"
            :key="col.key"
            :class="['grid-head', 'grid-head--' + col.key]"
          >
            {{ col.label }}
          </th>
          <th>{{ $t('pages.products.actions') }}</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td class="w-50x mx-w-50x">
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td class="w-50x mx-w-50x">{{ from + index }}</td>

          <td
            v-for="col in visibleColumns"
            :key="col.key"
            :class="cellClass(col, value)"
          >
            <template v-if="col.key === 'title'">
              <nuxt-link
                class="product-link"
                :to="`/products/${value.id}`"
              >
                <lazy-image
                  class="product-thumb"
                  :lazy-src="getThumbImageURL(value.image)"
                  :alt="value.title"
                />
                <span class="product-title">{{ value.title }}</span>
              </nuxt-link>
            </template>

            <template v-else-if="col.key === 'status'">
              <span>{{ getStatus(value.status) }}</span>
            </template>

            <template v-else-if="col.key === 'brand_id'">
              <nuxt-link
                v-if="value.brand"
                class="link"
                :to="`brands/${value.brand.id}`"
              >
                {{ value.brand.title }}
              </nuxt-link>
              <span v-else>{{ $t('prod.na') }}</span>
            </template>

            <template v-else-if="col.key === 'categories'">
              <span
                v-if="value.product_categories && value.product_categories.length"
                class="product-categories"
              >
                <span
                  v-for="(data, catIndex) in value.product_categories"
                  :key="catIndex"
                  class="product-category"
                ><template v-if="catIndex">, </template><nuxt-link
                    class="link"
                    :to="`/categories/${data.category.id}`"
                  >{{ data.category.title }}</nuxt-link></span>
              </span>
              <span v-else>{{ $t('prod.na') }}</span>
            </template>

            <template v-else-if="col.key === 'tax_rule_id'">
              <nuxt-link
                v-if="value.tax_rules"
                class="link"
                :to="`tax-rules/${value.tax_rules.id}`"
              >
                {{ value.tax_rules.title }}
              </nuxt-link>
              <span v-else>{{ $t('prod.na') }}</span>
            </template>

            <template v-else-if="col.key === 'offered'">
              <span v-if="value.offered">{{ money(value.offered) }}</span>
            </template>

            <template v-else-if="col.currency">
              {{ money(value[col.key]) }}
            </template>

            <template v-else-if="col.key === 'procurement'">
              <span
                class="grid-pill"
                :class="value.procurement == 1 ? 'grid-pill--yes' : 'grid-pill--no'"
              >
                {{ value.procurement == 1 ? $t('prod.yes') : $t('prod.no') }}
              </span>
            </template>

            <template v-else-if="col.key === 'created'">
              <span class="cell-date">{{ createdDate(value.created) }}</span>
              <span v-if="createdTime(value.created)" class="cell-time">{{ createdTime(value.created) }}</span>
            </template>

            <template v-else-if="col.key === 'discount_type'">
              {{ value.discount_type === 'percentage' ? $t('pages.products.percentage') : $t('pages.products.fixed') }}
            </template>

            <template v-else>
              {{ value[col.key] }}
            </template>
          </td>

          <td class="product-actions">
            <div class="action-dropdown-wrapper">

              <button
                class="action-icon-btn"
                @click.stop="toggleActionDropdown(value.id)"
              >
                <i class="fa fa-pencil"></i>
              </button>

              <div
                v-if="activeDropdown === value.id"
                class="action-dropdown-menu"
              >

                <button
                  class="dropdown-item"
                  @click="handleAction('view', value.id)"
                >
                  {{ $t('fSale.view') }}
                </button>

                <button
                  class="dropdown-item"
                  @click="handleAction('reviews', value.id)"
                >
                  {{ $t('prod.reviews') }}
                </button>

                <button
                  v-if="$can('product', 'create')"
                  class="dropdown-item"
                  @click="handleAction('duplicate', value.id)"
                >
                  {{ $t('prod.duplicate') }}
                </button>

                <button
                  v-if="$can('product', 'edit')"
                  class="dropdown-item"
                  @click="handleAction('edit', value.id)"
                >
                  {{ $t('category.edit') }}
                </button>

                <button
                  v-if="$can('product', 'delete')"
                  class="dropdown-item text-danger"
                  @click="handleAction('delete', value.id)"
                >
                  {{ $t('category.delete') }}
                </button>

                <button
                  class="dropdown-item"
                  @click="handleAction('seo', value.id)"
                >
                  {{ $t('pages.products.seo') }}
                </button>

                <button
                  v-if="$can('product', 'edit')"
                  class="dropdown-item"
                  @click="handleAction('draft', value.id)"
                >
                  {{ value.status == 1 ? $t('pages.products.moveToDraft') : $t('pages.products.publish') }}
                </button>

                <button
                  class="dropdown-item"
                  @click="handleAction('embed', value.id)"
                >
                  {{ $t('pages.products.embed') }}
                </button>

              </div>

            </div>
          </td>
        </tr>
    </template>
  </list-page>

  <!-- Embed Modal -->
  <div v-if="showEmbedModal" class="embed-modal-overlay">
    <div class="embed-modal">

      <!-- Header -->
      <div class="embed-header">
        <h2>{{ $t('pages.products.productPreview') }}</h2>
        <button class="close-btn" :aria-label="$t('pages.products.close')" @click="closeEmbed">✕</button>
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
            <h3>{{ $t('pages.products.configuration') }}</h3>
            <div class="config-options">
              <label>
                <input type="radio" value="none" v-model="borderType" />
                {{ $t('pages.products.noBorder') }}
              </label>

              <label>
                <input type="radio" value="solid" v-model="borderType" />
                {{ $t('pages.products.haveBorder') }}
              </label>
            </div>
          </div>

          <!-- Code Box -->
          <div class="code-box">
            <h3>{{ $t('pages.products.code') }}</h3>

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

  const COLUMN_STORAGE_KEY = 'fmp.products.grid.columns'

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        activeDropdown: null,
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
        borderType: 'solid',

        /* ---------- custom grid columns ---------- */
        gridColumns: this.defaultGridColumns(),
        showColumnSelector: false,
        draggedColumnIndex: null
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
    },

      visibleColumns() {
        return this.gridColumns.filter(col => col.visible)
      }
    },
    methods: {
      /* =====================================================
       *  Custom grid columns
       * ===================================================== */
      defaultGridColumns() {
        return [
          { key: 'title', label: this.$t('index.title'), visible: true, locked: true },
          { key: 'slug', label: this.$t('pages.products.slug'), visible: false },
          { key: 'status', label: this.$t('category.status'), visible: true },
          { key: 'brand_id', label: this.$t('prod.brand'), visible: true },
          { key: 'categories', label: this.$t('error.cat'), visible: true },
          { key: 'tax_rule_id', label: this.$t('prod.tRule'), visible: true },
          { key: 'purchased', label: this.$t('prod.purchased'), currency: true, visible: true },
          { key: 'selling', label: this.$t('prod.selling'), currency: true, visible: true },
          { key: 'offered', label: this.$t('prod.offered'), currency: true, visible: true },
          { key: 'discount_type', label: this.$t('pages.products.discountType'), visible: false },
          { key: 'discount_value', label: this.$t('pages.products.discountValue'), visible: false },
          { key: 'unit', label: this.$t('pages.products.unit'), visible: false },
          { key: 'procurement', label: this.$t('pages.products.procurement'), visible: false },
          { key: 'views', label: this.$t('prod.views'), visible: true },
          { key: 'created', label: this.$t('category.created'), visible: true }
        ]
      },
      toggleColumnSelector() {
        this.showColumnSelector = !this.showColumnSelector
        this.activeDropdown = null
      },
      toggleColumn(col) {
        if (col.locked) return
        col.visible = !col.visible
        this.persistColumns()
      },
      resetColumns() {
        this.gridColumns = this.defaultGridColumns()
        this.persistColumns()
      },
      dragColumn(index) {
        this.draggedColumnIndex = index
      },
      dropColumn(index) {
        if (this.draggedColumnIndex === null || this.draggedColumnIndex === index) {
          this.draggedColumnIndex = null
          return
        }
        const columns = this.gridColumns.slice()
        const [dragged] = columns.splice(this.draggedColumnIndex, 1)
        columns.splice(index, 0, dragged)
        this.gridColumns = columns
        this.draggedColumnIndex = null
        this.persistColumns()
      },
      persistColumns() {
        if (!process.client) return
        try {
          window.localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(
            this.gridColumns.map(col => ({ key: col.key, visible: col.visible }))
          ))
        } catch (e) {
          /* storage unavailable - column choice simply is not remembered */
        }
      },
      restoreColumns() {
        if (!process.client) return
        let saved = null
        try {
          saved = JSON.parse(window.localStorage.getItem(COLUMN_STORAGE_KEY))
        } catch (e) {
          saved = null
        }
        if (!Array.isArray(saved) || !saved.length) return

        const defaults = this.defaultGridColumns()
        const ordered = []

        saved.forEach(item => {
          const col = defaults.find(c => c.key === item.key)
          if (col && !ordered.includes(col)) {
            col.visible = col.locked ? true : !!item.visible
            ordered.push(col)
          }
        })

        defaults.forEach(col => {
          if (!ordered.includes(col)) ordered.push(col)
        })

        this.gridColumns = ordered
      },
      /* =====================================================
       *  Display formatting
       *
       *  All of this is presentation. Nothing here re-derives a value from
       *  the raw record - the API stays exactly as it is.
       * ===================================================== */

      // A price column reads as a column: same symbol placement, same two
      // decimals, thousands grouped. Blank stays blank rather than NaN.
      money(amount) {
        if (amount === null || amount === undefined || amount === '') return ''
        const value = parseFloat(amount)
        if (isNaN(value)) return ''
        return this.priceFormatting(value, 2)
      },

      /*
       * The API sends `created` already converted to the viewer's timezone
       * and preformatted by Utils::formatDate as `h:i a, d M, y`, e.g.
       * "06:26 am, 09 Sep, 26". That default is shared by every admin list,
       * so it is split here rather than changed at the source, and the raw
       * created_at is deliberately not used - it is still UTC, and reading
       * it would silently undo the server's timezone conversion.
       *
       * Anything that does not match is passed through untouched.
       */
      parseCreated(created) {
        if (!created) return null
        const parts = String(created).match(
          /^\s*(\d{1,2}):(\d{2})\s*([ap]m)\s*,\s*(\d{1,2})\s+([A-Za-z]{3,})\s*,\s*(\d{2}|\d{4})\s*$/i
        )
        if (!parts) return null

        const year = parts[6].length === 2 ? `20${parts[6]}` : parts[6]

        return {
          date: `${parts[4].padStart(2, '0')} ${parts[5]} ${year}`,
          time: `${parts[1].padStart(2, '0')}:${parts[2]} ${parts[3].toLowerCase()}`
        }
      },
      createdDate(created) {
        const parsed = this.parseCreated(created)
        return parsed ? parsed.date : (created || '')
      },
      createdTime(created) {
        const parsed = this.parseCreated(created)
        return parsed ? parsed.time : ''
      },
      cellClass(col, product) {
        const classes = ['grid-cell', 'grid-cell--' + col.key]

        if (col.key === 'title') classes.push('product-cell')

        if (col.key === 'status') {
          classes.push('status')
          if (product.status == 1) classes.push('active')
        }

        return classes
      },

      /* =====================================================
       *  Product list behaviour
       * ===================================================== */
      closeDropdown(event) {
        this.activeDropdown = null

        const target = event && event.target
        if (!target || typeof target.closest !== 'function') {
          this.showColumnSelector = false
          return
        }

        if (!target.closest('.grid-column-selector') && !target.closest('.grid-columns-btn')) {
          this.showColumnSelector = false
        }
      },
      toggleActionDropdown(id) {
        this.showColumnSelector = false
        this.activeDropdown =
          this.activeDropdown === id
            ? null
            : id
      },
      handleAction(action, id) {
        this.activeDropdown = null
        this.onActionChange({
          target: {
            value: action
          }
        }, id)
      },
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
        this.showAlert(this.$t('pages.products.embedCopied'))
      },
      async duplicateProduct(productId) {
        if (!confirm(this.$t('prod.confirmDuplicate'))) return;

        try {
          this.loading = true;
          const baseUrl = this.apiBase();
          const response = await this.$axios.post(`${baseUrl}api/admin/products/${productId}/duplicate`);

          /*
           * A refusal - no product.create, or a vendor reaching for somebody
           * else's product - answers HTTP 200 carrying the error rather than
           * throwing, so `if (response.data)` was always true and the redirect
           * went to /products/undefined.
           */
          const duplicateProductId = response.data && response.data.id;

          if (!duplicateProductId) {
            this.showAlert(response.data?.message || this.$t('pages.products.cannotDuplicate'), 'error');
            return;
          }

          this.showAlert(this.$t('pages.products.duplicated'));

          // Redirect to the duplicated product after a short delay
          setTimeout(() => {
            this.$router.push(`/products/${duplicateProductId}`);
          }, 2000);
        } catch (error) {
          console.error('Error duplicating product:', error);
          this.showAlert(this.$t('pages.products.duplicateError'), 'error');
        } finally {
          this.loading = false;
        }
      },

      /*
       * Publish / unpublish from the list. `itemList` is the page the grid is
       * showing, so the current status is read from it rather than fetched.
       */
      async setProductStatus(productId) {
        const product = (this.itemList || []).find(p => `${p.id}` === `${productId}`)
        const next = `${product && product.status}` === '1' ? 2 : 1

        try {
          this.loading = true
          const baseUrl = this.apiBase();

          const { data } = await this.$axios.post(
            `${baseUrl}api/admin/product/update-status`,
            { id: productId, status: next }
          )

          if (Number(data.status) !== 200) {
            this.showAlert(data.message || this.$t('pages.products.statusChangeFailed'), 'error')
            return
          }

          this.showAlert(next === 1 ? this.$t('pages.products.published') : this.$t('pages.products.movedToDraft'))

          await this.$refs.listPage.fetchingData()
        } catch (e) {
          this.showAlert(this.$t('pages.products.statusChangeFailed'), 'error')
        } finally {
          this.loading = false
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

          /*
           * There is no /products/:id/seo page and there never was, so this
           * went straight to the Nuxt error page. The product editor has an
           * "SEO & Meta" tab; open the editor on it.
           */
          case 'seo':
            this.$router.push(`/products/${productId}?tab=seo`)
            break

          /*
           * This used to push the editor -- the same thing Edit and View
           * already did -- so a menu item called Draft changed nothing.
           */
          case 'draft':
            this.setProductStatus(productId)
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
      this.restoreColumns()

      const baseUrl = this.apiBase();
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
      document.addEventListener('click', this.closeDropdown)
    },
    beforeDestroy() {
      document.removeEventListener('click', this.closeDropdown)
    },
  }
</script>

<style scoped>
.product-filter-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

select.custom-dropdown {
  cursor: pointer;
  user-select: none;
  padding: 0 32px 0 12px;
  height: 36px;
  line-height: 34px;
  background: var(--surface-color);
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  color: var(--text-color);
  font-size: 13px;
  min-width: 116px;
  max-width: 165px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: all 0.12s ease;
}

select.custom-dropdown:hover,
select.custom-dropdown:focus {
  border-color: var(--primary-color);
  box-shadow: none;
}

.stock-filters {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.stock-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  height: 36px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  font-size: 13px;
  cursor: pointer;
  user-select: none;
  background: var(--surface-color);
  color: var(--text-muted);
  transition: all 0.12s ease;
}

.stock-chip input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.stock-chip:hover {
  border-color: var(--primary-color);
  background: var(--hover-color);
}

.stock-chip input:checked + span {
  font-weight: 650;
  color: var(--primary-color);
}

.stock-chip input:checked + span::before {
  content: "";
  display: inline-block;
  width: 6px;
  height: 6px;
  margin-right: 7px;
  border-radius: 50%;
  background: var(--primary-color);
  vertical-align: 1px;
}

/* The Title column carries the one piece of information the row is looked up
   by, so it gets a real width instead of an equal share of the table. */
.product-cell {
  min-width: 300px;
  width: 26%;
  text-align: left;
}

.product-link {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr);
  align-items: center;
  gap: 10px;
  color: var(--text-strong);
  text-decoration: none;
  text-align: left;
}

.product-thumb {
  width: 40px;
  height: 40px;
  min-width: 40px;
  border-radius: 6px;
  border: 1px solid var(--border-color);
  overflow: hidden;
  background: var(--hover-color);
}

/* No clamp and no ellipsis: a product title is never abbreviated, it wraps
   onto as many lines as it needs. overflow-wrap catches unbroken strings
   (long SKUs, model numbers) that would otherwise widen the column. */
.product-title {
  display: block;
  font-weight: 650;
  line-height: 18px;
  white-space: normal;
  overflow-wrap: anywhere;
}

.product-actions {
  text-align: center;
}

.action-select {
  width: 132px;
  min-width: 132px;
}

/* Two categories used to render as adjacent links with nothing between
   them, so they read as one word ("ComputerAccessories"), and nowrap plus
   a 190px cap spilled the overflow into the next column rather than
   clipping it. The comma is a real text node, not generated content, so it
   survives a copy-paste and is read out with the names. */
.product-categories {
  display: block;
  text-align: center;
  line-height: 20px;
}


/* Date on top, time under it in muted type: the date is what the eye is
   looking for, and neither half has to wrap. */
.grid-cell--created {
  white-space: nowrap;
}

.cell-date {
  display: block;
}

.cell-time {
  display: block;
  font-size: 11.5px;
  line-height: 15px;
  color: var(--text-muted);
}

.muted-more {
  color: var(--text-muted);
  margin-left: 3px;
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
  background: var(--surface-color);
  width: 100%;
  max-width: 1150px;
  border-radius: 10px;
  box-shadow: none;
  overflow: hidden;
  animation: modalFade 0.25s ease;
}

/* ===== Header ===== */
.embed-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 22px 28px;
  border-bottom: 1px solid var(--border-color);
}

.embed-header h2 {
  font-size: 20px;
  font-weight: 600;
  color: var(--text-strong);
  margin: 0;
}

/* Close Button */
.close-btn {
  background: var(--text-strong);
  color: var(--primary-foreground);
  border: none;
  width: 34px;
  height: 34px;
  border-radius: 8px;
  font-size: 14px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.close-btn:hover {
  background: var(--danger-text);
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
  background: var(--hover-color);
  border-radius: 8px;
  padding: 18px;
  box-shadow: inset 0 0 0 1px var(--border-color);
  display: flex;
  justify-content: center;
  align-items: center;
}

.embed-preview iframe {
  border-radius: 8px;
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
  background: var(--hover-color);
  border-radius: 8px;
  padding: 20px;
  box-shadow: inset 0 0 0 1px var(--border-color);
}

.config-box h3 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 14px;
  color: var(--text-strong);
}

.config-box label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: var(--text-color);
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
  background: var(--text-strong);
  border-radius: 8px;
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
  border-radius: 8px;
  border: none;
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
  border-radius: 8px;
  border: none;
  background: var(--surface-color);
  color: var(--text-strong);
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}

.copy-btn:hover {
  background: var(--border-color);
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

.action-dropdown-wrapper {
  position: relative;
  display: inline-block;
}

.action-icon-btn {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  border: 1px solid #D7D7E0;
  background: #fff;
  cursor: pointer;
  transition: 0.2s;
}

.action-icon-btn:hover {
  background: #f4f4f8;
}

.action-dropdown-menu {
  position: absolute;
  right: 0;
  top: 45px;
  min-width: 180px;
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.08);
  z-index: 999;
  overflow: hidden;
}

.dropdown-item {
  width: 100%;
  border: none;
  background: transparent;
  text-align: left;
  padding: 0px 15px;
  cursor: pointer;
  transition: 0.2s;
  font-size: 14px;
}

.dropdown-item:hover {
  background: #f5f5fa;
}

.text-danger {
  color: #dc2626;
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

/* =========================================================
   CUSTOM GRID COLUMN PICKER
   ========================================================= */

.grid-tool-wrap {
  position: relative;
}

.grid-tool-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  height: 36px;
  padding: 0 13px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-color);
  background: var(--surface-color);
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.12s ease;
}

.grid-tool-btn:hover {
  border-color: var(--primary-color);
  background: var(--hover-color);
}

.grid-tool-btn.is-open {
  border-color: var(--primary-color);
  color: var(--primary-color);
}

.grid-tool-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 5px;
  border-radius: 10px;
  font-size: 11px;
  background: var(--hover-color);
  color: var(--text-muted);
}

.grid-column-selector {
  position: absolute;
  right: 0;
  top: 44px;
  z-index: 1000;
  width: 288px;
  max-width: calc(100vw - 32px);
  padding: 14px;
  background: var(--surface-color);
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  box-shadow: 0 16px 40px rgba(15, 23, 42, 0.16);
  text-align: left;
}

.grid-column-head strong {
  display: block;
  font-size: 14px;
  color: var(--text-strong);
}

.grid-column-head span {
  display: block;
  margin-top: 2px;
  font-size: 11px;
  line-height: 1.45;
  color: var(--text-muted);
}

.grid-column-list {
  max-height: 280px;
  overflow-y: auto;
  margin: 12px 0 10px;
  border-top: 1px solid var(--border-color);
  padding-top: 8px;
}

.grid-column-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 5px 4px;
  border-radius: 6px;
  cursor: grab;
}

.grid-column-option:hover {
  background: var(--hover-color);
}

.grid-column-option.is-dragging {
  opacity: 0.45;
}

.grid-column-label {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 13px;
  color: var(--text-color);
  cursor: pointer;
  margin: 0;
}

.grid-column-actions {
  display: flex;
  justify-content: flex-end;
  border-top: 1px solid var(--border-color);
  padding-top: 10px;
}

.grid-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 38px;
  padding: 0 18px;
  font-size: 13px;
  font-weight: 650;
  border-radius: 8px;
  border: 1px solid transparent;
  cursor: pointer;
  transition: all 0.12s ease;
}

.grid-btn.xs {
  height: 30px;
  padding: 0 12px;
  font-size: 12px;
}

.grid-btn.ghost {
  background: var(--surface-color);
  border-color: var(--border-strong);
  color: var(--text-color);
}

.grid-btn.ghost:hover {
  background: var(--hover-color);
}

.grid-head {
  white-space: nowrap;
}

.grid-cell {
  vertical-align: middle;
}

.grid-pill {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}

.grid-pill--yes {
  background: rgba(22, 163, 74, 0.12);
  color: #15803d;
}

.grid-pill--no {
  background: rgba(100, 116, 139, 0.14);
  color: #475569;
}
</style>
