<template>
  <div :class="['admin-container', { 'focus-mode': isFocusMode }]">
    <div class="p-4 top-buttons">

      <!-- Filters -->

      <div class="filter-toolbar">
        <div class="filter-left">
            <!-- Status -->
            <select v-model="filters.status" class="filter-pill">
              <option value="">Any Status</option>
              <option value="1">Public</option>
              <option value="2">Private</option>
            </select>

            <!-- Stock -->
            <select v-model="filters.stock" class="filter-pill">
              <option value="">Any Stock</option>
              <option value="in">In Stock</option>
              <option value="out">Out of Stock</option>
            </select>

            <!-- Brands -->
            <select v-model="filters.brand" class="filter-pill">
              <option value="">All Brand</option>
              <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                  {{ brand.title }}
                </option>
            </select>

            <div class="search-input">
              <!-- <i class="fa-solid fa-magnifying-glass"></i> -->
              <input
                type="text"
                v-model="filters.q"
                placeholder="Search name, SKU, barcode..."
              />
            </div>

            <button class="btn-reset" @click="resetFilters">
              <i class="fa-solid fa-rotate-right"></i>
               Reset
            </button>
        </div>
        <div class="filter-right">
          <button type="button" class="btn-advance" @click="showAdvanced = !showAdvanced">
            <i class="fa-solid fa-sliders"></i>
            Advance Filters
          </button>
          <button class="btn-outline">
            <i class="fa-solid fa-bullhorn"></i>
            SEO
          </button>
          <button
            class="button custom-grid-btn"
            @click.stop="showColumnSelector = !showColumnSelector"
          >
            <i class="fa-solid fa-table-columns"></i>
            Custom Grid
          </button>
          <button
            class="btn-primary"
            @click="toggleBulkPreview"
          >
            {{ showBulkPreview ? 'Clear Action' : `Bulk Action (${selectedProducts.length})` }}
          </button>
          <button
            class="button focus-btn"
            @click="toggleFocusMode"
          >
            <i class="fa-solid" :class="isFocusMode ? 'fa-compress' : 'fa-expand'"></i> <span>Focus Mode</span>
          </button>
        </div>
      </div>

      <transition name="slide-fade">
        <div v-if="showAdvanced || hasActiveAdvancedFilters" class="advanced-card">

          <div class="advanced-header">
            <span>Advanced Filters</span>
          </div>

          <div class="advanced-row">
            
            <!-- Selects -->
            <div class="advanced-selects">
              <select v-model="filters.category_id" class="filter-select">
                <option value="">All Categories</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                  {{ cat.title }}
                </option>
              </select>

              <select
                v-if="subcategories.length"
                v-model="filters.subcategory_id"
                class="filter-select"
              >
                <option value="">All Sub Categories</option>
                <option v-for="sub in subcategories" :key="sub.id" :value="sub.id">
                  {{ sub.title }}
                </option>
              </select>

              <select v-model="filters.product_id" class="filter-select">
                <option value="">All Products</option>
                <option v-for="product in products" :key="product.id" :value="product.id">
                  <span class="truncate">{{ product.title }}</span>
                </option>
              </select>

              <select v-model="filters.collection_id" class="filter-select">
                <option value="">All Collections</option>
                <option v-for="col in collections" :key="col.id" :value="col.id">
                  {{ col.title }}
                </option>
              </select>

              <select v-model="filters.crosssell_id" class="filter-select">
                <option value="">Cross Sell</option>
                <option
                  v-for="(item, key) in allUpsells"
                  :key="key"
                  :value="Number(key)"
                >
                  {{ item.title }}
                </option>
              </select>
            </div>

            <!-- Checkboxes -->
            <div class="advanced-checkboxes">
              <label><input type="checkbox" v-model="filters.onlyVariants"> Only with Variants</label>
              <label><input type="checkbox" v-model="filters.recentlyAdded"> Recently Added</label>
              <label><input type="checkbox" v-model="filters.highReturn"> High Return</label>
              <label><input type="checkbox" v-model="filters.deadStock"> Dead Stock</label>
            </div>

          </div>
        </div>
      </transition>

      <!-- Product List -->
      <list-page
        ref="productList"
        list-api="getProducts"
        :name="$t('title.prod')"
        :filters="filters"
        @list="handleProductList"
      >
        <template v-slot:table-top>
          <div class="table-result-bar">

            <div></div>

            <div class="table-result-right">
              <button
                class="btn-outline"
                @click="toggleAllVariants"
              >
                <i
                  class="fa-solid"
                  :class="allVariantsExpanded ? 'fa-angles-up' : 'fa-angles-down'"
                ></i>

                {{ allVariantsExpanded ? 'Collapse All' : 'Expand All' }}
              </button>
            </div>

          </div>
        </template>
        <template v-slot:table="{list}">
          <tr class="admin-header">
            <th class="col-expand"></th>

            <th class="col-checkbox">
              <input
                type="checkbox"
                :checked="isAllSelected"
                @change="toggleSelectAll"
              >
            </th>

            <th
              v-for="(col, index) in columnOptions"
              v-if="col.visible"
              :key="col.key"
              draggable="true"
              class="draggable-header"
              @dragstart="dragStart(index)"
              @dragover.prevent
              @drop="dropColumn(index)"
            >
              <i class="fa-solid fa-grip-lines drag-icon"></i>
              {{ col.label }}
            </th>
          </tr>

          <!-- ✅ BULK PREVIEW -->
          <template v-if="showBulkPreview && selectedProductList.length">

            <tr class="bulk-preview-header">
              <td :colspan="previewColspan">
                <div class="bulk-preview-bar">

                  <div class="bulk-preview-left">
                    <i class="fa-solid fa-layer-group"></i>
                    <span>
                      Selected Products Preview
                      ({{ selectedProducts.length }})
                    </span>
                  </div>

                  <div class="bulk-preview-right">
                    <button
                      class="button primary-btn"
                      @click="confirmBulkChanges"
                    >
                      Apply
                    </button>

                    <button
                      class="btn-cancel"
                      @click="toggleBulkPreview"
                    >
                      Cancel
                    </button>
                  </div>

                </div>
              </td>
            </tr>

            <tr class="bulk-edit-row">
              <td></td>
              <td></td>

              <td
                v-for="col in columnOptions"
                v-if="col.visible"
                :key="'bulk-'+col.key"
              >
                <!-- Image -->
                <template v-if="col.key === 'image'"></template>

                <!-- Title -->
                <template v-else-if="col.key === 'title'">
                  <span class="bulk-label">Bulk Edit →</span>
                </template>

                <!-- Slug -->
                <template v-else-if="col.key === 'slug'"></template>

                <!-- Editor -->
                <template v-else-if="col.key === 'editor'"></template>

                <!-- Category -->
                <template v-else-if="col.key === 'category'">
                  <div class="category-cell-wrapper">
                    <div
                      class="category-trigger"
                      @click="activeBulkCategory = !activeBulkCategory"
                    >
                      <span v-if="bulkData.tempCategoryIds.length">
                        {{ bulkData.tempCategoryIds.length }}
                      </span>
                      <span v-else class="placeholder">Choose</span>
                      <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div v-if="activeBulkCategory" class="category-popup">
                      <div class="popup-header">Select Categories</div>

                      <div class="category-list">
                        <div
                          v-for="cat in categories"
                          :key="cat.id"
                          class="category-group"
                        >
                          <label class="category-option parent">
                            <input
                              type="checkbox"
                              :value="cat.id"
                              v-model="bulkData.tempCategoryIds"
                            />
                            {{ cat.title }}
                          </label>

                          <div v-if="cat.child?.length" class="subcategory-list">
                            <label
                              v-for="sub in cat.child"
                              :key="sub.id"
                              class="category-option child"
                            >
                              <input
                                type="checkbox"
                                :value="sub.id"
                                v-model="bulkData.tempCategoryIds"
                              />
                              {{ sub.title }}
                            </label>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>

                 <!-- Primary -->
                <template v-else-if="col.key === 'primary'">
                  <div class="primary-cell-wrapper">
                    <div
                      class="primary-trigger"
                      @click="activeBulkPrimary = !activeBulkPrimary"
                    >
                      <span v-if="bulkData.tempPrimaryCategoryId">
                        {{ findCategoryTitle(bulkData.tempPrimaryCategoryId) }}
                      </span>
                      <span v-else class="placeholder">Choose</span>
                      <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div v-if="activeBulkPrimary" class="primary-popup">
                      <label
                        v-for="id in bulkData.tempCategoryIds"
                        :key="id"
                        class="primary-option"
                      >
                        <input
                          type="radio"
                          :value="id"
                          v-model="bulkData.tempPrimaryCategoryId"
                        />
                        {{ findCategoryTitle(id) }}
                      </label>
                    </div>
                  </div>
                </template>

                <!-- Collection -->
                <template v-else-if="col.key === 'collection'">
                  <div class="collection-cell-wrapper">
                    <div
                      class="collection-trigger"
                      @click="activeBulkCollection = !activeBulkCollection"
                    >
                      <span v-if="bulkData.tempCollectionIds.length">
                        {{ bulkData.tempCollectionIds.length }}
                      </span>
                      <span v-else class="placeholder">Choose</span>
                      <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div v-if="activeBulkCollection" class="collection-popup">
                      <label
                        v-for="col in collections"
                        :key="col.id"
                        class="collection-option"
                      >
                        <input
                          type="checkbox"
                          :value="col.id"
                          v-model="bulkData.tempCollectionIds"
                        />
                        {{ col.title }}
                      </label>
                    </div>
                  </div>
                </template>

                <!-- Selling -->
                <template v-else-if="col.key === 'selling'">
                  <input
                    type="number"
                    v-model="bulkData.selling"
                    class="admin-input small"
                  />
                </template>

                <!-- Discount -->
                <template v-else-if="col.key === 'discount'">
                  <div class="discount-popup-row">
                    <input
                      type="number"
                      v-model="bulkData.discount_value"
                      class="discount-popup-input"
                    />
                    <select v-model="bulkData.discount_type" class="discount-popup-select">
                      <option value="percentage">%</option>
                      <option value="fixed">€</option>
                    </select>
                  </div>
                </template>

                <!-- Crosssell -->
                <template v-else-if="col.key === 'crosssell'">
                  <dropdown
                    :selectedKey="bulkData.upsell_id"
                    :options="allUpsells"
                    @clicked="({key}) => bulkData.upsell_id = Number(key)"
                  />
                </template>

                <!-- Upsell -->
                <template v-else-if="col.key === 'updated_upsell'">
                  <dropdown
                    :selectedKey="bulkData.updated_upsell_id ? String(bulkData.updated_upsell_id) : null"
                    :options="updatedUpsellOptions"
                    @clicked="({key}) => bulkData.updated_upsell_id = key"
                  />
                </template>

                <!-- Bundle -->
                <template v-else-if="col.key === 'bundle'">
                  <!-- <dropdown
                    :selectedKey="String(bulkData.bundle_deal_id || '')"
                    :options="bundleOptions"
                    @clicked="({key}) => bulkData.bundle_deal_id = key"
                  /> -->
                  <div class="collection-cell-wrapper">
                    <div
                      class="collection-trigger"
                      @click="activeBulkBundle = !activeBulkBundle"
                    >
                      <span v-if="bulkData.bundle_deal_ids?.length">
                        {{ bulkData.bundle_deal_ids.length }}
                      </span>
                      <span v-else class="placeholder">Choose</span>
                      <i class="fa-solid fa-chevron-down"></i>
                    </div>
                    <div v-if="activeBulkBundle" class="collection-popup">
                      <div class="popup-header">Select Bundles</div>

                      <div class="collection-list">
                        <label
                          v-for="bundle in bundles"
                          :key="bundle.id"
                          class="collection-option"
                        >
                          <input
                            type="checkbox"
                            :value="bundle.id.toString()"
                            v-model="bulkData.bundle_deal_ids"
                          />
                          {{ bundle.title }}
                        </label>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Status -->
                <template v-else-if="col.key === 'status'">
                  <dropdown
                    :selectedKey="String(bulkData.status)"
                    :options="statusObj"
                    @clicked="({key}) => bulkData.status = Number(key)"
                  />
                </template>

                <!-- Procurement -->
                <template v-else-if="col.key === 'procurement'">
                  <div class="primary-cell-wrapper">
                    <div
                      class="primary-trigger"
                      @click="activeBulkProcurement = !activeBulkProcurement"
                    >
                      <span v-if="bulkData.procurement !== null">
                        {{ bulkData.procurement == 1 ? 'true' : 'false' }}
                      </span>

                      <span v-else class="placeholder">
                        Choose
                      </span>

                      <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <!-- Popup -->
                    <div
                      v-if="activeBulkProcurement"
                      class="primary-popup"
                    >
                      <label class="primary-option">
                        <input
                          type="radio"
                          :value="1"
                          v-model="bulkData.procurement"
                        />
                        true
                      </label>

                      <label class="primary-option">
                        <input
                          type="radio"
                          :value="0"
                          v-model="bulkData.procurement"
                        />
                        false
                      </label>
                    </div>

                   </div>
                </template>
              </td>
            </tr>

            <tr
              v-for="product in selectedProductList"
              :key="'preview-' + product.id"
              class="admin-row preview-row"
            >
              <td></td>
              <td></td>

              <td
                v-for="col in columnOptions"
                v-if="col.visible"
                :key="'preview-'+product.id+'-'+col.key"
              >
                <!-- Image -->
                <template v-if="col.key === 'image'">
                  <img
                    :src="product.previewImage || getThumbImageURL(product.image)"
                    class="product-thumb"
                  />
                </template>

                <!-- Title -->
                <template v-if="col.key === 'title'">
                  {{ product.title }}
                </template>

                <!-- Slug -->
                <template v-if="col.key === 'slug'">
                  {{ product.slug }}
                </template>

                <!-- Editor -->
                <template v-if="col.key === 'editor'">
                  
                </template>

                <!-- Category -->
                <template v-if="col.key === 'category'">
                  <span
                    v-for="cat in product.selectedCategories"
                    :key="cat.id"
                    class="chip"
                  >
                    {{ cat.title }}
                  </span>
                </template>

                <!-- Primary -->
                <template v-if="col.key === 'primary'">
                  {{ findCategoryTitle(product.primaryCategoryId) }}
                </template>

                <!-- Collections -->
                <template v-if="col.key === 'collection'">
                  <span
                    v-for="col in product.selectedCollections"
                    :key="col.id"
                    class="chip chip-purple"
                  >
                    {{ col.title }}
                  </span>
                </template>

                <!-- Selling -->
                <template v-if="col.key === 'selling'">
                  €{{ Number(product.selling).toFixed(2) }}
                </template>

                <!-- Discount -->
                <template v-if="col.key === 'discount'">
                  €{{ product.discount_value }}
                </template>

                <!-- Cross Sell -->
                <template v-if="col.key === 'crosssell'">
                  {{ allUpsells[product.upsell_id]?.title }}
                </template>

                <!-- UpSell -->
                <template v-if="col.key === 'updated_upsell'">
                  {{ updated_upsells.find(u => u.id == product.updated_upsell_id)?.title }}
                </template>

                <!-- Bundle -->
                <template v-if="col.key === 'bundle'">
                  <!-- {{ bundles.find(b => b.id == product.bundle_deal_id)?.title }} -->
                  <span v-if="product.bundle_deal_ids?.length">
                    <span
                      v-for="id in product.bundle_deal_ids"
                      :key="id"
                      class="chip"
                    >
                      {{ bundles.find(b => b.id == id)?.title }}
                    </span>
                  </span>
                </template>

                <!-- Status -->
                <template v-if="col.key === 'status'">
                  {{ statusObj[product.status]?.title }}
                </template>

                <!-- Procurement -->
                <template v-if="col.key === 'procurement'">
                  <span
                    class="status-badge"
                    :class="product.procurement == 1 ? 'status-public' : 'status-private'"
                  >
                    {{ product.procurement == 1 ? 'true' : 'false' }}
                  </span>
                </template>
              </td>
            </tr>

          </template>

          <!-- NORMAL PRODUCT ROWS -->
          <template v-for="value in list">

            <!-- Product Row -->
            <tr
              :key="'product-' + value.id"
              :class="['admin-row']"
            >

              <!-- Expand -->
              <td class="col-expand">
                <span
                  v-if="hasVariants(value)"
                  class="expand-toggle"
                  :class="{ open: isExpanded(value.id) }"
                  @click.stop="toggleVariants(value.id)"
                >
                  ▸
                </span>
              </td>

              <!-- Checkbox -->
              <td class="col-checkbox">
                <input
                  type="checkbox"
                  :value="value.id"
                  v-model="selectedProducts"
                  @click.stop
                >
              </td>

              <td
                v-for="col in columnOptions"
                v-if="col.visible"
                :key="'row-'+value.id+'-'+col.key"
              >
                <!-- Image -->
                <template v-if="col.key === 'image'">
                  <div class="image-edit-wrapper">
                    <div
                      class="image-label"
                      @click="openImageManager(value)"
                    >
                      <img
                        :key="value.previewImage || value.image"
                        :src="value.previewImage || getThumbImageURL(value.image)"
                        :alt="value.title"
                        class="product-thumb fade-image"
                        @load="onImageLoaded"
                      />
                    </div>
                  </div>
                </template>

                <!-- Title -->
                <template v-if="col.key === 'title'">
                  <div
                    class="editable-text"
                    @click="openContentModal(value, 'title')"
                    @mouseenter="hoverTitleRow = value.id"
                    @mouseleave="hoverTitleRow = null"
                  >
                    {{ value.title }}

                    <div
                      v-if="hoverTitleRow === value.id"
                      class="category-hover-tooltip"
                    >
                      {{ value.title }}
                    </div>
                  </div>
                </template>

                <!-- Slug -->
                <template v-if="col.key === 'slug'">
                  <div
                    class="editable-text slug-text"
                    @click="openContentModal(value, 'slug')"
                    @mouseenter="hoverSlugRow = value.id"
                    @mouseleave="hoverSlugRow = null"
                  >
                    {{ value.slug }}

                    <div
                      v-if="hoverSlugRow === value.id"
                      class="category-hover-tooltip"
                    >
                      {{ value.slug }}
                    </div>
                  </div>
                </template>

                <!-- Editor -->

                <template v-if="col.key === 'editor'">
                  <button class="content-btn" @click="openEditorModal(value)" > Editor </button>
                </template>

                <!-- Category -->
                <template v-if="col.key === 'category'">
                  <div class="category-cell-wrapper">
                    
                    <div
                      class="category-trigger"
                      @click.stop="toggleCategoryPopup(value.id)"
                      @mouseenter="hoverCategoryRow = value.id"
                      @mouseleave="hoverCategoryRow = null"
                    >
                      <span v-if="value.selectedCategories?.length">
                        {{ value.selectedCategories.length }}
                      </span>
                      <span v-else class="placeholder">
                        Choose
                      </span>

                      <i class="fa-solid fa-chevron-down"></i>

                      <!-- Hover Tooltip -->
                      <div
                        v-if="hoverCategoryRow === value.id && value.selectedCategories?.length"
                        class="category-hover-tooltip"
                      >
                        <div
                          v-for="cat in value.selectedCategories"
                          :key="cat.id"
                          class="tooltip-item"
                        >
                          {{ cat.title }}
                        </div>
                      </div>

                    </div>

                    <!-- Popup -->
                    <div
                      v-if="activeCategoryRow === value.id"
                      class="category-popup"
                    >
                      <div class="popup-header">
                        Select Categories
                      </div>

                      <div class="category-list">
                        <div
                          v-for="cat in categories"
                          :key="cat.id"
                          class="category-group"
                        >
                          <label
                            class="category-option parent"
                          >
                            <input
                              type="checkbox"
                              :value="cat.id"
                              v-model="value.tempCategoryIds"
                            />
                            {{ cat.title }}
                          </label>
                          <div
                            v-if="cat.child && cat.child.length"
                            class="subcategory-list"
                          >
                            <label
                              v-for="sub in cat.child"
                              :key="sub.id"
                              class="category-option child"
                            >
                              <input
                                type="checkbox"
                                :value="sub.id"
                                v-model="value.tempCategoryIds"
                              />
                              {{ sub.title }}
                            </label>
                          </div>
                        </div>
                      </div>

                      <div class="popup-actions">
                        <button @click="cancelCategories(value)">
                          Cancel
                        </button>
                        <button
                          class="primary-btn"
                          @click="applyCategories(value)"
                        >
                          Apply
                        </button>
                      </div>
                    </div>

                  </div>
                </template>

                <!-- Primary -->
                <template v-if="col.key === 'primary'">
                  <div class="primary-cell-wrapper">

                    <!-- Trigger -->
                    <div
                      class="primary-trigger"
                      @click.stop="togglePrimaryPopup(value.id)"
                    >
                      <span v-if="value.primaryCategoryId">
                        {{
                          value.selectedCategories.find(
                            c => c.id === value.primaryCategoryId
                          )?.title
                        }}
                      </span>

                      <span v-else class="placeholder">
                        Choose
                      </span>

                      <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <!-- Popup -->
                    <div
                      v-if="activePrimaryRow === value.id"
                      class="primary-popup"
                    >
                      <div class="popup-header">
                        Select Primary Category
                      </div>

                      <div class="primary-list">
                        <label
                          v-for="cat in value.selectedCategories"
                          :key="cat.id"
                          class="primary-option"
                        >
                          <input
                            type="radio"
                            :name="'primary-' + value.id"
                            :value="cat.id"
                            v-model="value.tempPrimaryCategoryId"
                          />
                          {{ cat.title }}
                        </label>
                      </div>

                      <div class="popup-actions">
                        <button @click="cancelPrimary(value)">
                          Cancel
                        </button>
                        <button
                          class="primary-btn"
                          @click="applyPrimary(value)"
                        >
                          Apply
                        </button>
                      </div>
                    </div>

                  </div>
                </template>

                <!-- Collections -->
                <template v-if="col.key === 'collection'">
                  <div class="collection-cell-wrapper">
                    <div
                      class="category-trigger"
                      @click.stop="toggleCollectionPopup(value.id)"
                      @mouseenter="hoverCollectionRow = value.id"
                      @mouseleave="hoverCollectionRow = null"
                    >
                      <span v-if="value.selectedCollections?.length">
                        {{ value.selectedCollections.length }}
                      </span>

                      <span v-else class="placeholder">
                        Choose
                      </span>

                      <i class="fa-solid fa-chevron-down"></i>

                      <!-- Hover Tooltip -->
                      <div
                        v-if="hoverCollectionRow === value.id && value.selectedCollections?.length"
                        class="category-hover-tooltip"
                      >
                        <div
                          v-for="col in value.selectedCollections"
                          :key="col.id"
                          class="tooltip-item"
                        >
                          {{ col.title }}
                        </div>
                      </div>

                    </div>
                    <div
                      v-if="activeCollectionRow === value.id"
                      class="collection-popup"
                    >
                      <div class="popup-header">
                        Select Collections
                      </div>

                      <div class="collection-list">
                        <label
                          v-for="col in collections"
                          :key="col.id"
                          class="collection-option"
                        >
                          <input
                            type="checkbox"
                            :value="col.id"
                            v-model="value.tempCollectionIds"
                          />
                          {{ col.title }}
                        </label>
                      </div>

                      <div class="popup-actions">
                        <button @click="activeCollectionRow = null">
                          Cancel
                        </button>
                        <button
                          class="primary-btn"
                          @click="applyCollections(value)"
                        >
                          Apply
                        </button>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Price -->
                <template v-if="col.key === 'selling'">
                  <input
                    type="number"
                    v-model="value.selling"
                    @input="autoSave(value)"
                    class="admin-input small"
                  />
                </template>

                <!-- Discount -->
                <template v-if="col.key === 'discount'">
                  <div class="discount-cell-wrapper">
                    <div class="discount-modern">
                      <div class="discount-input-wrapper">
                        <input
                          type="number"
                          v-model.number="value.discount_value"
                          class="discount-input-modern"
                        />
                        <button
                          class="discount-calc-btn"
                          :class="{ active: activeDiscountRow === value.id }"
                          @click.stop="toggleDiscountPopup(value.id)"
                          title="Open discount calculator"
                        >
                          <i class="fa-solid fa-calculator"></i>
                        </button>
                      </div>
                    </div>
                    <!-- Popup -->
                    <div
                      v-if="activeDiscountRow === value.id"
                      class="discount-popup"
                    >
                      <div class="popup-header">
                        <span>Discount Operation</span>
                      </div>

                      <div class="discount-popup-row">
                        <input
                          type="number"
                          v-model.number="value.tempDiscount"
                          placeholder="Enter value"
                          class="discount-popup-input"
                        />

                        <select v-model="value.discount_type" class="discount-popup-select">
                          <option value="percentage">%</option>
                          <option value="fixed">€</option>
                        </select>
                      </div>

                      <div class="popup-preview">
                        Final Price:
                        <strong>{{ calculateFinalProductPrice(value) }}</strong>
                      </div>

                      <div class="discount-popup-actions">
                        <button class="popup-cancel" @click="activeDiscountRow = null">
                          Cancel
                        </button>
                        <button class="popup-button primary-btn" @click="applyDiscount(value)">
                          Apply
                        </button>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Crosssell -->
                 <template v-if="col.key === 'crosssell'">
                  <dropdown
                    :selectedKey="value.upsell_id"
                    :options="allUpsells"
                    @clicked="({key}) => {value.upsell_id = Number(key); autoSave(value);}"
                  />
                 </template>

                 <!-- Upsell -->
                 <template v-if="col.key === 'updated_upsell'">
                  <dropdown
                    :selectedKey="value.updated_upsell_id ? String(value.updated_upsell_id) : undefined"
                    :options="updatedUpsellOptions"
                    @clicked="({key}) => {value.updated_upsell_id = key || null; autoSave(value);}"
                  />
                 </template>

                  <!-- Bundle -->
                 <template v-if="col.key === 'bundle'">
                  <!-- <dropdown
                    :selectedKey="String(value.bundle_deal_id || '')"
                    :options="bundleOptions"
                    @clicked="({key}) => {
                      value.bundle_deal_id = key ? String(key) : null
                      autoSave(value)
                    }"
                  /> -->
                  <div class="collection-cell-wrapper">

                    <!-- Trigger -->
                    <div
                      class="category-trigger"
                      @click.stop="toggleBundlePopup(value.id)"
                      @mouseenter="hoverBundleRow = value.id"
                      @mouseleave="hoverBundleRow = null"
                    >
                      <span v-if="value.bundle_deal_ids?.length">
                        {{ value.bundle_deal_ids.length }}
                      </span>

                      <span v-else class="placeholder">
                        Choose
                      </span>

                      <i class="fa-solid fa-chevron-down"></i>

                      <!-- Hover Tooltip -->
                      <div
                        v-if="hoverBundleRow === value.id && value.bundle_deal_ids?.length"
                        class="category-hover-tooltip"
                      >
                        <div
                          v-for="id in value.bundle_deal_ids"
                          :key="id"
                          class="tooltip-item"
                        >
                          {{ bundles.find(b => b.id == id)?.title }}
                        </div>
                      </div>
                    </div>

                    <!-- Popup -->
                    <div
                      v-if="activeBundleRow === value.id"
                      class="collection-popup"
                    >
                      <div class="popup-header">
                        Select Bundles
                      </div>

                      <div class="collection-list">
                        <label
                          v-for="bundle in bundles"
                          :key="bundle.id"
                          class="collection-option"
                        >
                          <input
                            type="checkbox"
                            :value="bundle.id.toString()"
                            v-model="value.bundle_deal_ids"
                          />
                          {{ bundle.title }}
                        </label>
                      </div>

                      <div class="popup-actions">
                        <button @click="activeBundleRow = null">
                          Cancel
                        </button>
                        <button
                          class="primary-btn"
                          @click="applyBundles(value)"
                        >
                          Apply
                        </button>
                      </div>
                    </div>

                  </div>
                 </template>

                 <!-- Status -->
                 <template v-if="col.key === 'status'">
                    <dropdown
                      :selectedKey="String(value.status)"
                      :options="statusObj"
                      @clicked="({key}) => {value.status = Number(key); autoSave(value);}"
                    />
                 </template>

                 <!-- Procurement -->
                <template v-if="col.key === 'procurement'">
                  <div class="primary-cell-wrapper">
                    <div
                      class="primary-trigger"
                      @click.stop="toggleProcurementPopup(value.id)"
                    >
                      <span v-if="value.procurement !== null">
                        {{ value.procurement == 1 ? 'true' : 'false' }}
                      </span>

                      <span v-else class="placeholder">
                        Choose
                      </span>

                      <i class="fa-solid fa-chevron-down"></i>
                    </div>
                    <div
                      v-if="activeProcurementRow === value.id"
                      class="primary-popup"
                    >
                      <label class="primary-option">
                        <input
                          type="radio"
                          :name="'procurement-'+value.id"
                          :value="1"
                          v-model="value.procurement"
                          @change="applyProcurement(value)"
                        />
                        true
                      </label>

                      <label class="primary-option">
                        <input
                          type="radio"
                          :name="'procurement-'+value.id"
                          :value="0"
                          v-model="value.procurement"
                          @change="applyProcurement(value)"
                        />
                        false
                      </label>
                    </div>
                  </div>
                </template>
              </td>
            </tr>

            <!-- Variant Row -->
            <tr
              v-if="isExpanded(value.id)"
              :key="'variant-' + value.id"
              class="variant-row-clean"
            >
              <td :colspan="previewColspan">
                <div class="variant-box">

                  <div class="variant-header-clean">
                    <span>Image</span>
                    <span>Attributes</span>
                    <span>SKU</span>
                    <span>Price</span>
                    <span>Qty</span>
                    <span>IMEI / S.No</span>
                    <span>Barcode</span>
                  </div>

                  <div
                    v-for="(variant, vIndex) in value.product_inventories || []"
                    :key="'inv-' + (variant.id || vIndex)"
                    class="variant-row-clean-item"
                  >

                    <div class="variant-image-wrapper">
                      <input
                        type="checkbox"
                        class="variant-checkbox"
                        v-model="variant.is_active"
                        @change="autoSave(value)"
                      />
                      <div class="variant-images" @click="openVariantImageManager(value, variant)">
                        <img
                          v-if="variant.images && variant.images.length"
                          :src="getThumbImageURL(variant.images[0].image.image)"
                          class="variant-thumb"
                        />

                        <div v-else class="variant-thumb placeholder">
                          <i class="fas fa-image variant-icon"></i>
                        </div>
                      </div>
                    </div>

                    <span class="variant-attr-clean">
                      {{ formatAttributes(variant) }}
                    </span>

                    <input
                      type="text"
                      v-model="variant.sku"
                      class="admin-input small"
                      @input="autoSave(value)"
                    />

                    <input
                      type="number"
                      v-model="variant.price"
                      class="admin-input small"
                      @input="autoSave(value)"
                    />

                    <input
                      type="number"
                      v-model="variant.quantity"
                      class="admin-input small"
                      @input="autoSave(value)"
                    />

                    <input
                      type="text"
                      v-model="variant.imei"
                      class="admin-input small"
                      placeholder="IMEI"
                      @input="autoSave(value)"
                    />

                    <input
                      type="text"
                      v-model="variant.barcode"
                      class="admin-input small"
                      placeholder="Barcode"
                      @input="autoSave(value)"
                    />
                  </div>

                </div>
              </td>
            </tr>

          </template>
        </template>
      </list-page>

      <div v-if="showContentModal" class="modal-overlay">
        <div class="modal-box">
          <h3>Edit {{ editingField }}</h3>

          <textarea
            v-model="editingValue"
            class="modal-textarea"
          ></textarea>

          <div class="modal-actions">
            <button @click="closeContentModal" class="btn-cancel">
              Cancel
            </button>
            <button @click="saveContentModal" class="button primary-btn">
              Save
            </button>
          </div>
        </div>
      </div>

      <!-- Bulk Action Modal -->

      <div v-if="showBulkModal" class="modal-overlay">
        <div class="modal-box bulk-action-modal">
          <h3>Bulk Actions</h3>

          <p>
            {{ selectedProducts.length }} product(s) selected
          </p>

          <div class="bulk-table-wrapper">
            <table class="admin-table">
              <thead>
                <tr class="admin-header">
                  <th>Image</th>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Primary</th>
                  <th>Collections</th>
                  <th>Selling (€)</th>
                  <th>Discount (€)</th>
                  <th>Cross Sell</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="product in selectedProductList"
                  :key="'bulk-preview-' + product.id"
                  class="admin-row bulk-preview-row"
                >
                  <!-- Image -->
                  <td>
                    <img
                      :src="product.previewImage || getThumbImageURL(product.image)"
                      class="product-thumb bulk-thumb"
                    />
                  </td>

                  <!-- Title -->
                  <td class="bulk-title-cell">
                    <div class="bulk-title">
                      {{ product.title }}
                    </div>
                    <div class="bulk-id">
                      ID: {{ product.id }}
                    </div>
                  </td>

                  <!-- Categories -->
                  <td>
                    <div class="chip-wrap">
                      <span
                        v-for="cat in product.selectedCategories"
                        :key="cat.id"
                        class="chip"
                      >
                        {{ cat.title }}
                      </span>
                    </div>
                  </td>

                  <!-- Primary -->
                  <td>
                    <span class="primary-badge" v-if="product.primaryCategoryId">
                      {{ findCategoryTitle(product.primaryCategoryId) }}
                    </span>
                    <span v-else class="muted">—</span>
                  </td>

                  <!-- Collections -->
                  <td>
                    <div class="chip-wrap">
                      <span
                        v-for="col in product.selectedCollections"
                        :key="col.id"
                        class="chip chip-purple"
                      >
                        {{ col.title }}
                      </span>
                    </div>
                  </td>

                  <!-- Selling -->
                  <td class="price-cell">
                    €{{ Number(product.selling).toFixed(2) }}
                  </td>

                  <!-- Discount -->
                  <td>
                    <div v-if="product.discount_value > 0">
                      <span class="discount-value">
                        {{ product.discount_type === 'percentage'
                          ? product.discount_value + '%'
                          : '€' + Number(product.discount_value).toFixed(2)
                        }}
                      </span>
                      <div class="final-price">
                        Final: €{{ calculateFinalProductPrice(product) }}
                      </div>
                    </div>
                    <span v-else class="muted">—</span>
                  </td>

                  <!-- Cross Sell -->
                  <td>
                    <span v-if="product.upsell_id" class="chip chip-green">
                      {{ allUpsells[product.upsell_id]?.title }}
                    </span>
                    <span v-else class="muted">—</span>
                  </td>

                  <!-- Status -->
                  <td>
                    <span
                      class="status-badge"
                      :class="product.status == 1 ? 'status-public' : 'status-private'"
                    >
                      {{ statusObj[product.status]?.title }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="bulk-editor-card">
            <div class="bulk-editor-header">
              Apply Changes To Selected Products
            </div>
            <div class="bulk-editor-grid">
              <!-- CATEGORY -->
              <div class="bulk-field">
                <label>Category</label>
                <div class="category-cell-wrapper">
                  <div
                    class="category-trigger"
                    @click="activeBulkCategory = !activeBulkCategory"
                  >
                    <span v-if="bulkData.tempCategoryIds.length">
                      {{ bulkData.tempCategoryIds.length }}
                    </span>
                    <span v-else class="placeholder">Choose</span>
                    <i class="fa-solid fa-chevron-down"></i>
                  </div>

                  <div v-if="activeBulkCategory" class="category-popup">
                    <div class="popup-header">Select Categories</div>

                    <div class="category-list">
                      <div
                        v-for="cat in categories"
                        :key="cat.id"
                        class="category-group"
                      >
                        <label class="category-option parent">
                          <input
                            type="checkbox"
                            :value="cat.id"
                            v-model="bulkData.tempCategoryIds"
                          />
                          {{ cat.title }}
                        </label>

                        <div v-if="cat.child?.length" class="subcategory-list">
                          <label
                            v-for="sub in cat.child"
                            :key="sub.id"
                            class="category-option child"
                          >
                            <input
                              type="checkbox"
                              :value="sub.id"
                              v-model="bulkData.tempCategoryIds"
                            />
                            {{ sub.title }}
                          </label>
                        </div>
                      </div>
                    </div>

                    <div class="popup-actions">
                      <button @click="activeBulkCategory = false">Close</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- PRIMARY -->
              <div class="bulk-field">
                <label>Primary</label>
                <div class="primary-cell-wrapper">
                  <div
                    class="primary-trigger"
                    @click="activeBulkPrimary = !activeBulkPrimary"
                  >
                    <span v-if="bulkData.tempPrimaryCategoryId">
                      {{ findCategoryTitle(bulkData.tempPrimaryCategoryId) }}
                    </span>
                    <span v-else class="placeholder">Choose</span>
                    <i class="fa-solid fa-chevron-down"></i>
                  </div>

                  <div v-if="activeBulkPrimary" class="primary-popup">
                    <div class="popup-header">Select Primary</div>

                    <div class="primary-list">
                      <label
                        v-for="id in bulkData.tempCategoryIds"
                        :key="id"
                        class="primary-option"
                      >
                        <input
                          type="radio"
                          :value="id"
                          v-model="bulkData.tempPrimaryCategoryId"
                        />
                        {{ findCategoryTitle(id) }}
                      </label>
                    </div>

                    <div class="popup-actions">
                      <button @click="activeBulkPrimary = false">Close</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- COLLECTIONS -->
              <div class="bulk-field">
                <label>Collections</label>
                <div class="collection-cell-wrapper">
                  <div
                    class="collection-trigger"
                    @click="activeBulkCollection = !activeBulkCollection"
                  >
                    <span v-if="bulkData.tempCollectionIds.length">
                      {{ bulkData.tempCollectionIds.length }}
                    </span>
                    <span v-else class="placeholder">Choose</span>
                    <i class="fa-solid fa-chevron-down"></i>
                  </div>

                  <div v-if="activeBulkCollection" class="collection-popup">
                    <div class="popup-header">Select Collections</div>

                    <div class="collection-list">
                      <label
                        v-for="col in collections"
                        :key="col.id"
                        class="collection-option"
                      >
                        <input
                          type="checkbox"
                          :value="col.id"
                          v-model="bulkData.tempCollectionIds"
                        />
                        {{ col.title }}
                      </label>
                    </div>

                    <div class="popup-actions">
                      <button @click="activeBulkCollection = false">Close</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- SELLING -->
              <div class="bulk-field">
                <label>Selling Price</label>
                <input
                  type="number"
                  v-model="bulkData.selling"
                  class="admin-input"
                  step="0.01"
                  @blur="formatDecimal('selling')"
                />
              </div>

              <!-- DISCOUNT -->
              <div class="bulk-field">
                <label>Discount</label>
                <div class="discount-popup-row">
                  <input
                    type="number"
                    v-model="bulkData.discount_value"
                    class="discount-popup-input"
                    step="0.01"
                    @blur="formatDecimal('discount_value')"
                  />
                  <select
                    v-model="bulkData.discount_type"
                    class="discount-popup-select"
                  >
                    <option value="percentage">%</option>
                    <option value="fixed">€</option>
                  </select>
                </div>
              </div>

              <!-- CROSSSELL -->
              <div class="bulk-field">
                <label>Cross Sell</label>
                <dropdown
                  :selectedKey="bulkData.upsell_id"
                  :options="allUpsells"
                  @clicked="({key}) => bulkData.upsell_id = Number(key)"
                />
              </div>

              <!-- STATUS -->
              <div class="bulk-field">
                <label>Status</label>
                <dropdown
                  :selectedKey="String(bulkData.status)"
                  :options="statusObj"
                  @clicked="({key}) => bulkData.status = Number(key)"
                />
              </div>
            </div>
          </div>
          <div class="modal-actions">
            <button @click="closeBulkModal" class="btn-cancel">
              Cancel
            </button>
            <button class="button primary-btn" @click="confirmBulkChanges">
              Apply Changes
            </button>
          </div>
        </div>
      </div>

      <!-- Product Image Manager Modal -->
      <div v-if="showImageModal" class="modal-overlay">
        <div class="image-manager">

          <div class="image-manager-body">

            <h2 class="image-manager-title">
              {{ editingVariant ? 'Variant Images' : 'Product Images' }}
            </h2>

            <div class="image-grid">

              <div
                v-for="img in imageGallery"
                :key="img.id"
                class="image-card"
                :class="{ active: selectedImageIds?.includes(img.id) }"
                @click="toggleImageSelection(img.id)"
              >
                <img :src="getThumbImageURL(img.image)" />

                <div
                  v-if="selectedImageIds.includes(img.id)"
                  class="image-check"
                >
                  ✓
                </div>

              </div>

            </div>

          </div>

          <!-- Bottom Blue Bar -->
          <div class="image-manager-footer">

            <label class="add-image-btn">
              + Add {{ editingVariant ? 'Variant' : 'Product' }} Image
              <input
                type="file"
                accept="image/*"
                multiple
                @change="uploadNewImages"
                hidden
              />
            </label>

            <div class="footer-actions">
              <button @click="closeImageManager" class="btn-cancel">
                Cancel
              </button>

              <button
                class="button primary-btn"
                @click="applySelectedImage"
              >
                Save {{ editingVariant ? 'Variant' : 'Product' }} Image
              </button>
            </div>

          </div>

        </div>
      </div>

      <!-- Column Selector -->

      <div v-if="showColumnSelector" class="column-selector" @click.stop>
        <div class="column-box">
          <div class="column-header">
            <strong>Choose Columns</strong>
            <span>To show on the bulk editor grid</span>
          </div>

          <div class="column-list">
            <div
              v-for="col in columnOptions"
              :key="col.key"
              class="column-option"
            >
              <label class="column-label">
                <input
                  type="checkbox"
                  v-model="col.visible"
                />
                {{ col.label }}
              </label>
            </div>
          </div>
          <div class="column-actions">
             <button class="btn-reset" @click="resetColumns">
              <i class="fa-solid fa-rotate-right"></i>
               Reset Default
            </button>
          </div>
        </div>
      </div>

      <!-- Bulk Confirm Modal -->
      <div v-if="showBulkConfirm" class="modal-overlay">
        <div class="confirm-modal-box">

          <div class="confirm-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
          </div>

          <h3>Confirm Bulk Update</h3>

          <p>
            You are about to update 
            <strong>{{ selectedProducts.length }}</strong> 
            product(s).
          </p>

          <p class="confirm-warning">
            This action will overwrite existing values.  
            Are you sure you want to continue?
          </p>

          <div class="modal-actions">
            <button
              class="btn-cancel"
              @click="showBulkConfirm = false"
            >
              Cancel
            </button>

            <button
              class="button primary-btn"
              @click="executeBulkUpdate"
            >
              Yes, Apply Changes
            </button>
          </div>

        </div>
      </div>

      <!-- Editor Modal -->

      <div v-if="showEditorModal" class="modal-overlay">
        <div class="editor-modal">

          <h2 class="editor-title">
            Product Details Editor
          </h2>

          <div class="editor-layout">
            <!-- LEFT PANEL -->
            <div class="editor-left">

              <div class="editor-card">
                <h3>Basic Information</h3>

                <div class="editor-field">
                  <label>Title</label>
                  <input type="text" v-model="editorTitle" class="admin-input" />
                </div>

                <div class="editor-field">
                  <label>Slug</label>
                  <input type="text" v-model="editorSlug" class="admin-input" />
                </div>
              </div>


              <div class="editor-card">
                <h3>SEO Settings</h3>

                <div class="editor-field">
                  <label>Meta Title</label>
                  <input type="text" v-model="editorMetaTitle" class="admin-input" />
                </div>

                <div class="editor-field">
                  <label>Meta Keywords</label>
                  <textarea v-model="editorMetaKeywords" class="admin-textarea"></textarea>
                </div>

                <div class="editor-field">
                  <label>Meta Description</label>
                  <textarea v-model="editorMetaDescription" class="admin-textarea"></textarea>
                </div>

              </div>

            </div>
            <!-- RIGHT PANEL -->
            <div class="editor-right">

              <div class="editor-card">
                <h3>Overview</h3>

                <WYSIWYGEditor
                  title="Overview"
                  :description="editorOverview"
                  @change="editorOverview = $event"
                />
              </div>

              <div class="editor-card">
                <h3>Description</h3>

                <WYSIWYGEditor
                  title="Description"
                  :description="editorDescription"
                  @change="editorDescription = $event"
                />
              </div>

            </div>
          </div>
          <div class="modal-actions">
            <button
              class="btn-cancel"
              @click="closeEditorModal"
            >
              Cancel
            </button>

            <button
              class="button primary-btn"
              @click="saveEditorModal"
            >
              Save
            </button>
          </div>

        </div>
      </div>

    </div>
  </div>
</template>
<script>

import Multiselect from 'vue-multiselect'
import 'vue-multiselect/dist/vue-multiselect.min.css'
import ListPage from '~/components/partials/ListPage'
import {mapGetters, mapActions} from 'vuex'
import util from '~/mixin/util'
import LazyImage from "~/components/LazyImage"
import debounce from 'lodash/debounce'
import draggable from 'vuedraggable'
import WYSIWYGEditor from '~/components/WYSIWYGEditor'
import { update } from 'lodash'

export default {
  mixins: [util],
  components: { ListPage, Multiselect, draggable, WYSIWYGEditor},
  data() {
    return {
      activeBundleRow: null,
      hoverBundleRow: null,
      isPageLoaded: false,
      showEditorModal: false,
      editorProduct: null,
      editorTitle: '',
      editorSlug: '',
      editorOverview: '',
      editorDescription: '',
      editorMetaTitle:'',
      editorMetaKeywords:'',
      editorMetaDescription:'',
      hoverTitleRow: null,
      hoverSlugRow: null,
      draggedColumnIndex: null,
      showBulkPreview: false,
      showBulkConfirm: false,
      isSyncingFromRoute: false,
      isFocusMode: false,
      itemList: [],
      filters: {
        category_id: '',
        subcategory_id: '',
        product_id: '',
        collection_id: '',
        crosssell_id: '',
        status: '',
        stock: '',
        brand: '',
        q: '',
        onlyVariants: false,
        recentlyAdded: false,
        highReturn: false,
        deadStock: false
      },
      products: [],
      brands: [],
      categories: [],
      collections: [],
      subCategories: [],
      allUpsells: {},
      bundles: [],
      updated_upsells: [],
      loadingUpsells: false,
      statusObj: {
        1: { title: 'Public' },
        2: { title: 'Private' }
      },
      activeCollectionRow: null,
      activeProcurementRow: null,
      collectionSearch: '',
      searchedCollections: {},
      expandedRows: [],
      showContentModal: false,
      editingProduct: null,
      editingField: '',
      editingValue: '',
      activeDiscountRow: null,
      activeCategoryRow: null,
      activePrimaryRow: null,
      selectedProducts: [],
      showBulkModal: false,
      bulkData: {
        tempCategoryIds: [],
        tempPrimaryCategoryId: '',
        tempCollectionIds: [],
        procurement:null,
        selling: '',
        discount_value: '',
        discount_type: 'fixed',
        upsell_id: null,
        updated_upsell_id: null,
        bundle_deal_id: null,
        status: '',
      },
      activeBulkCategory: false,
      activeBulkPrimary: false,
      activeBulkCollection: false,
      activeBulkProcurement: false,
      activeBulkDiscount: false,
      showImageModal: false,
      editingImageProduct: null,
      imageGallery: [],
      selectedImageIds: [],
      columnOptions: [
        { key: 'image', label: 'Image', visible: true },
        { key: 'title', label: 'Title', visible: true },
        { key: 'slug', label: 'Slug', visible: false },
        { key: 'editor', label: 'Editor', visible: false },
        { key: 'category', label: 'Category', visible: true },
        { key: 'primary', label: 'Primary', visible: true },
        { key: 'collection', label: 'Collection', visible: true },
        { key: 'selling', label: 'Selling', visible: true },
        { key: 'discount', label: 'Discount', visible: true },
        { key: 'crosssell', label: 'Cross Sell', visible: true },
        { key: 'updated_upsell', label: 'Upsell', visible: true },
        { key: 'bundle', label: 'Bundle', visible: true },
        { key: 'status', label: 'Status', visible: true },
        { key: 'procurement', label: 'Procurement', visible: true },
      ],
      showColumnSelector: false,
      showAdvanced: false,
      hoverCategoryRow: null,
      hoverCollectionRow: null
    }
  },
  computed: {
    updatedUpsellOptions() {
      return [
        { key: '', title: 'Select Upsell' },
        ...this.updated_upsells.map(u => ({
          key: String(u.id),
          title: u.title
        }))
      ]
    },

    bundleOptions() {
      return [
        { key: '', title: 'Select Bundle' },
        ...this.bundles.map(b => ({
          key: String(b.id),
          title: b.title
        }))
      ]
    },
    allVariantsExpanded() {
      const variantProducts = this.itemList
        .filter(p => this.hasVariants(p))
        .map(p => p.id)
      return (
        variantProducts.length &&
        variantProducts.every(id => this.expandedRows.includes(id))
      )
    },
    previewColspan() {
      const visible = this.columnOptions.filter(c => c.visible).length
      return visible + 2
    },
    selectedProductList() {
      return this.itemList.filter(p =>
        this.selectedProducts.includes(p.id)
      )
    },
    hasActiveAdvancedFilters() {
      const advancedKeys = [
        'category_id',
        'subcategory_id',
        'product_id',
        'collection_id',
        'crosssell_id',
        'onlyVariants',
        'recentlyAdded',
        'highReturn',
        'deadStock'
      ]

      return advancedKeys.some(key => {
        const value = this.filters[key]
        return value !== '' && value !== null && value !== false
      })
    },
    isAllSelected() {
      if (!this.itemList.length) return false
      return this.itemList.every(p =>
        this.selectedProducts.includes(p.id)
      )
    },
    subcategories() {
      const selected = this.categories.find(
        c => c.id == this.filters.category_id
      )
      return selected ? selected.child : []
    },
    ...mapGetters('setting', ['setting']),
    currencyIcon() {
      return this.setting?.currency_icon || '$'
    },
    collectionOptions() {
      return this.collections || []
    }
  },
  methods: {
    toggleBundlePopup(id) {
      if (this.activeBundleRow === id) {
        this.activeBundleRow = null
        return
      }
      this.activeBundleRow = id
    },

    applyBundles(product) {
      product.bundle_deal_id =
        (product.bundle_deal_ids || []).join(',')

      this.activeBundleRow = null
      this.autoSave(product)
    },
    onBundleChange(product) {
      product.bundle_deal_id = (product.bundle_deal_ids || []).join(',');
      this.autoSave(product);
    },

    onBulkBundleChange() {
      this.bulkData.bundle_deal_id =
        (this.bulkData.bundle_deal_ids || []).join(',');
    },
    handleProductList(list) {
      list.forEach(item => {
        item.updated_upsell_id =
          item.updated_upsell_id !== null
            ? String(item.updated_upsell_id)
            : null

        item.bundle_deal_ids = item.bundle_deal_id
          ? item.bundle_deal_id.split(',').map(String)
          : [];

        // ✅ IMPORTANT FIX
        item.tempCategoryIds = (item.selectedCategories || []).map(c => c.id)

        item.tempPrimaryCategoryId = item.primaryCategoryId || ''

        item.tempCollectionIds = (item.selectedCollections || []).map(c => c.id)
      })

      this.itemList = list
    },
    toggleAllVariants() {
      const variantProducts = this.itemList
        .filter(p => this.hasVariants(p))
        .map(p => p.id)
      const allExpanded =
        variantProducts.length &&
        variantProducts.every(id => this.expandedRows.includes(id))
      if (allExpanded) {
        this.expandedRows = []
      } else {
        this.expandedRows = variantProducts
      }
    },
    openEditorModal(product){
      this.editorProduct = product
      this.editorTitle = product.title || ''
      this.editorSlug = product.slug || ''
      this.editorOverview = product.overview || ''
      this.editorDescription = product.description || ''
      this.editorMetaTitle = product.meta_title || ''
      this.editorMetaKeywords = product.meta_keywords || ''
      this.editorMetaDescription = product.meta_description || ''
      this.showEditorModal = true
    },
    closeEditorModal(){
      this.showEditorModal = false
      this.editorProduct = null
    },
    saveEditorModal(){
      if(!this.editorProduct) return
      this.$set(this.editorProduct,'title',this.editorTitle)
      this.$set(this.editorProduct,'slug',this.editorSlug)
      this.$set(this.editorProduct,'overview',this.editorOverview)
      this.$set(this.editorProduct,'description',this.editorDescription)
      this.$set(this.editorProduct,'meta_title',this.editorMetaTitle)
      this.$set(this.editorProduct,'meta_keywords',this.editorMetaKeywords)
      this.$set(this.editorProduct,'meta_description',this.editorMetaDescription)
      this.autoSave(this.editorProduct)
      this.closeEditorModal()
    },
    dragStart(index) {
      this.draggedColumnIndex = index
    },
    dropColumn(index) {
      const dragged = this.columnOptions[this.draggedColumnIndex]
      this.columnOptions.splice(this.draggedColumnIndex, 1)
      this.columnOptions.splice(index, 0, dragged)
      this.draggedColumnIndex = null
    },
    applyProcurement(product) {
      this.activeProcurementRow = null
      this.autoSave(product)
    },
    toggleProcurementPopup(id) {
      if (this.activeProcurementRow === id) {
        this.activeProcurementRow = null
        return
      }
      this.activeProcurementRow = id
    },
    toggleBulkPreview() {
      if (this.showBulkPreview) {
        this.showBulkPreview = false
        this.selectedProducts = []
        return
      }
      if (!this.selectedProducts.length) {
        this.$toast.warning("Please select at least one product")
        return
      }

      this.showBulkPreview = true
    },
    resetColumns(){

      const defaults = [
        'image',
        'title',
        'slug',
        'editor',
        'category',
        'primary',
        'collection',
        'selling',
        'discount',
        'crosssell',
        'updated_upsell',
        'bundle',
        'status',
        'procurement'
      ]

      this.columnOptions.forEach(col => {
        col.visible = defaults.includes(col.key)
      })

    },
    toggleImageSelection(id){

      if(!this.selectedImageIds){
        this.selectedImageIds = []
      }

      if(!this.editingVariant){

        if(this.selectedImageIds.includes(id)){
          this.selectedImageIds = []
        }else{
          this.selectedImageIds = [id]
        }

        return
      }

      if(this.selectedImageIds.includes(id)){
        this.selectedImageIds =
          this.selectedImageIds.filter(i => i !== id)
      }else{
        this.selectedImageIds.push(id)
      }

    },
    openVariantImageManager(product, variant){
      this.editingImageProduct = product
      this.editingVariant = variant
      this.showImageModal = true
      this.selectedImageIds = []

      this.fetchProductImages(product.id)

      if(variant.images?.length){

        this.selectedImageIds =
          variant.images.map(v => v.product_image_id)

      }
    },
    async executeBulkUpdate() {
      this.showBulkConfirm = false
      await this.applyBulkChanges()
    },
    confirmBulkChanges() {
      if (!this.selectedProducts.length) {
        this.$toast.warning("No products selected")
        return
      }

      this.showBulkConfirm = true
    },
    getDefaultFilterValue(key) {
      const defaults = {
        category_id: '',
        subcategory_id: '',
        product_id: '',
        collection_id: '',
        crosssell_id: '',
        status: '',
        stock: '',
        brand: '',
        q: '',
        onlyVariants: false,
        recentlyAdded: false,
        highReturn: false,
        deadStock: false
      }

      return defaults[key]
    },
    resetFilters() {
      this.filters = {
        category_id: '',
        subcategory_id: '',
        product_id: '',
        collection_id: '',
        crosssell_id: '',
        status: '',
        stock: '',
        brand: '',
        q: '',
        onlyVariants: false,
        recentlyAdded: false,
        highReturn: false,
        deadStock: false
      }
    },
    handleGlobalClick(e) {

      // Category popup
      if (!e.target.closest('.category-cell-wrapper')) {
        this.activeCategoryRow = null
        this.activeBulkCategory = false
      }

      // Primary popup
      if (!e.target.closest('.primary-cell-wrapper')) {
        this.activePrimaryRow = null
        this.activeBulkPrimary = false
      }

      // Collection popup
      if (!e.target.closest('.collection-cell-wrapper')) {
        this.activeCollectionRow = null
        this.activeBulkCollection = false
      }

      // Discount popup
      if (!e.target.closest('.discount-cell-wrapper')) {
        this.activeDiscountRow = null
      }

      // Column selector
      if (!e.target.closest('.column-selector') &&
          !e.target.closest('.custom-grid-btn')) {
        this.showColumnSelector = false
      }

      if (!e.target.closest('.primary-cell-wrapper')) {
        this.activeBulkProcurement = false
        this.activeProcurementRow = null
      }
    },
    isVisible(key) {
      const col = this.columnOptions.find(c => c.key === key)
      return col ? col.visible : false
    },
    closeImageManager() {
      this.showImageModal = false
      this.editingImageProduct = null
      this.editingVariant = null
      this.imageGallery = []
      this.selectedImageIds = []
    },
    async applySelectedImage() {
      if(!this.selectedImageIds?.length){
        this.$toast.warning("Please select image(s)")
        return
      }

      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        if(this.editingVariant){
          await this.$axios.post(
            `${baseUrl}api/admin/product/set-variant-images`,
            {
              inventory_id: this.editingVariant.id,
              image_ids: this.selectedImageIds
            }
          )

          const selectedImages = this.imageGallery.filter(
            img => this.selectedImageIds.includes(img.id)
          )

          this.$set(
            this.editingVariant,
            'images',
            selectedImages.map(img => ({
              image:{
                image:img.image
              }
            }))
          )
        }else{
          await this.$axios.post(
            `${baseUrl}api/admin/product/set-main-image`,
            {
              product_id: this.editingImageProduct.id,
              image_id: this.selectedImageIds[0]
            }
          )

          const selected = this.imageGallery.find(
            img => img.id === this.selectedImageIds[0]
          )

          this.$set(this.editingImageProduct,'image',selected.image)
        }
        
        this.closeImageManager()
        this.$toast.success("Image updated")

      } catch (e) {
        console.error(e)
        this.$toast.error("Failed to update image")
      }
    },
    async uploadNewImages(e) {
      const files = Array.from(e.target.files)
      if (!files.length) return

      const formData = new FormData()

      files.forEach((file, index) => {
        formData.append("images[]", file)
      })

      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'

        await this.$axios.post(
          `${baseUrl}api/admin/product/upload-images/${this.editingImageProduct.id}`,
          formData,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        )

        await this.fetchProductImages(this.editingImageProduct.id)

        this.$toast.success("Images uploaded successfully")

      } catch (e) {
        console.error(e)
        this.$toast.error("Upload failed")
      }

      e.target.value = ''
    },
    async openImageManager(product) {
      this.editingImageProduct = product
      this.editingVariant = null
      this.showImageModal = true
      this.selectedImageIds = []

      await this.fetchProductImages(product.id)

      if(product.image){

        const img = this.imageGallery.find(
          g => g.image === product.image
        )

        if(img){
          this.selectedImageIds = [img.id]
        }

      }
    },
    async fetchProductImages(productId) {
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const res = await this.$axios.get(
          `${baseUrl}api/admin/product/all-images/${productId}`
        )
        this.imageGallery = res.data.data
      } catch (e) {
        console.error(e)
      }
    },
    resetBulkData() {
      this.bulkData = {
        tempCategoryIds: [],
        tempPrimaryCategoryId: '',
        tempCollectionIds: [],
        selling: '',
        discount_value: '',
        discount_type: 'fixed',
        upsell_id: null,
        updated_upsell_id: null,
        bundle_deal_ids: [],
        bundle_deal_id: null,
        status: '',
      }

      this.activeBulkCategory = false
      this.activeBulkPrimary = false
      this.activeBulkCollection = false
      this.activeBulkDiscount = false
    },
    formatDecimal(field) {
      const value = this.bulkData[field]

      if (value !== '' && !isNaN(value)) {
        this.$set(
          this.bulkData,
          field,
          Number(value).toFixed(2)
        )
      }
    },
    async applyBulkChanges() {
      const selected = this.itemList.filter(p =>
        this.selectedProducts.includes(p.id)
      )
      const updatedProducts = []
      selected.forEach(product => {

        // Categories
        if (this.bulkData.tempCategoryIds.length) {
          const all = []
          this.categories.forEach(cat => {
            all.push(cat)
            cat.child?.forEach(sub => all.push(sub))
          })

          product.selectedCategories =
            all.filter(cat =>
              this.bulkData.tempCategoryIds.includes(cat.id)
            )
        }

        // Primary
        if (this.bulkData.tempPrimaryCategoryId) {
          product.primaryCategoryId =
            this.bulkData.tempPrimaryCategoryId
        }

        // Collections
        if (this.bulkData.tempCollectionIds.length) {
          product.selectedCollections =
            this.collections.filter(col =>
              this.bulkData.tempCollectionIds.includes(col.id)
            )
        }

        // Selling
        if (this.bulkData.selling !== '') {
          product.selling = Number(this.bulkData.selling).toFixed(2)
        }

        // Discount
        if (this.bulkData.discount_value !== '') {
          product.discount_value = Number(this.bulkData.discount_value).toFixed(2)
          product.discount_type = this.bulkData.discount_type
        }

        // Crossell
        if (this.bulkData.upsell_id !== null) {
          product.upsell_id = this.bulkData.upsell_id
        }

        // Upsell
        if (this.bulkData.updated_upsell_id !== null) {
          product.updated_upsell_id = this.bulkData.updated_upsell_id
        }

        // Bundle
        if (this.bulkData.bundle_deal_ids?.length) {
          product.bundle_deal_ids = [...this.bulkData.bundle_deal_ids];
          product.bundle_deal_id = this.bulkData.bundle_deal_ids.join(',');
        }

        // Status
        if (this.bulkData.status !== '') {
          product.status = this.bulkData.status
        }

        if (this.bulkData.procurement !== null) {
          product.procurement = Number(this.bulkData.procurement)
        }

        updatedProducts.push(product)
      })
      await this.bulkUpdateProducts(updatedProducts)
      this.closeBulkModal()
      this.$router.replace({
        query: {
          ...this.$route.query,
          _refresh: Date.now()
        }
      })
    },
    findCategoryTitle(id) {
      let title = ''
      this.categories.forEach(cat => {
        if (cat.id === id) title = cat.title
        cat.child?.forEach(sub => {
          if (sub.id === id) title = sub.title
        })
      })
      return title
    },
    toggleSelectAll(e) {
      if (e.target.checked) {
        this.selectedProducts = this.itemList.map(p => p.id)
      } else {
        this.selectedProducts = []
      }
    },
    openBulkModal() {
      if (!this.selectedProducts.length) {
        this.$toast.warning("Please select at least one product")
        return
      }
      this.resetBulkData() 
      this.showBulkModal = true
    },
    closeBulkModal() {
      this.showBulkModal = false
      this.selectedProducts = []
      this.resetBulkData()
    },
    toggleFocusMode() {
      this.isFocusMode = !this.isFocusMode
    },
    cancelCategories(product) {
      product.tempCategoryIds =
        (product.selectedCategories || []).map(c => c.id)

      this.activeCategoryRow = null
    },
    togglePrimaryPopup(id) {
      if (this.activePrimaryRow === id) {
        this.activePrimaryRow = null
        return
      }

      const product = this.itemList.find(p => p.id === id)

      if (product) {
        this.$set(
          product,
          'tempPrimaryCategoryId',
          product.primaryCategoryId || ''
        )
      }

      this.activePrimaryRow = id
    },
    applyPrimary(product) {
      product.primaryCategoryId =
        product.tempPrimaryCategoryId || ''

      this.activePrimaryRow = null
      this.autoSave(product)
    },
    cancelPrimary(product) {
      product.tempPrimaryCategoryId =
        product.primaryCategoryId

      this.activePrimaryRow = null
    },
    async bulkUpdateProducts(products) {
      let toastId

      try {
        toastId = this.$toast.warning("Applying bulk update...", {
          duration: 0
        })

        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const formData = new FormData()

        const formatted = products.map(product => ({
          id: product.id,
          mode: 'merge',
          title: product.title,
          slug: product.slug,
          overview: product.overview,
          description: product.description,
          meta_title: product.meta_title,
          meta_keywords: product.meta_keywords,
          meta_description: product.meta_description,
          selling: product.selling,
          discount_type: product.discount_type,
          discount_value: product.discount_value,
          offered: this.calculateFinalProductPrice(product),

          upsell_id: product.upsell_id,
          updated_upsell_id: product.updated_upsell_id,
          bundle_deal_id: product.bundle_deal_id,
          status: product.status,
          procurement: product.procurement,
          primary_category_id: product.primaryCategoryId,

          categories: (product.selectedCategories || [])
            .filter(c => !c.parent)
            .map(c => c.id),

          subcategories: (product.selectedCategories || [])
            .filter(c => c.parent)
            .map(c => ({
              id: c.id,
              category_id: c.parent
            })),

          collections: (product.selectedCollections || [])
            .map(c => c.id),

          product_inventories: (product.product_inventories || []).map(inv => ({
            id: inv.id,
            price: inv.price,
            quantity: inv.quantity,
            sku: inv.sku,
            imei: inv.imei,
            barcode: inv.barcode,
            is_active: inv.is_active ? 1 : 0
          }))
        }))

        formData.append("products", JSON.stringify(formatted))

        await this.$axios.post(
          `${baseUrl}api/admin/product/bulk-update`,
          formData,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        )

        this.$toast.dismiss(toastId)
        this.$toast.success("Bulk update successful")

      } catch (e) {
        this.$toast.dismiss(toastId)
        this.$toast.error("Bulk update failed")
        console.error(e)
      }
    },
    autoSave(product) {
      if (!this.isPageLoaded) return
      if (product._skipAutoSave) return
      if (!product._debouncedSave) {
        product._debouncedSave = debounce(async () => {
          let toastId;
          try {
            toastId = this.$toast.warning("Saving changes...", {
              duration: 0
            })
            const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
            const formData = new FormData()
            const products = [{
              id: product.id,
              title: product.title,
              slug: product.slug,
              overview: product.overview,
              description: product.description,
              meta_title: product.meta_title,
              meta_keywords: product.meta_keywords,
              meta_description: product.meta_description,
              selling: product.selling,
              discount_type: product.discount_type,
              discount_value: product.discount_value,
              offered: this.calculateFinalProductPrice(product),
              upsell_id: product.upsell_id,
              updated_upsell_id: product.updated_upsell_id,
              bundle_deal_id: product.bundle_deal_id,
              status: product.status,
              procurement: product.procurement,
              primary_category_id: product.primaryCategoryId,
              categories: (product.selectedCategories || []).filter(c => !c.parent).map(c => c.id),
              subcategories: (product.selectedCategories || []).filter(c => c.parent).map(c => ({
                id: c.id,
                category_id: c.parent
              })),
              collections: (product.selectedCollections || []).map(c => c.id),
              product_inventories: (product.product_inventories || []).map(inv => ({
                id: inv.id,
                price: inv.price,
                quantity: inv.quantity,
                sku: inv.sku,
                imei: inv.imei,
                barcode: inv.barcode,
                is_active: inv.is_active ? 1 : 0
              }))
            }]
            if (product.newImage) {
              formData.append(`images[${product.id}]`, product.newImage)
            }
            formData.append("products", JSON.stringify(products))
            await this.$axios.post(
              `${baseUrl}api/admin/product/bulk-update`,
              formData,
              { headers: { 'Content-Type': 'multipart/form-data' } }
            )
            await new Promise(resolve => setTimeout(resolve, 200))
            this.$toast.dismiss(toastId)
            await new Promise(resolve => setTimeout(resolve, 200))
            this.$toast.success("Changes saved", {
              duration: 2000
            })
          } catch (e) {
            this.$toast.error("Changes failed")
          }
        }, 800)
      }
      product._debouncedSave()
    },
    toggleCollectionPopup(id) {
      if (this.activeCollectionRow === id) {
        this.activeCollectionRow = null
        return
      }
      const product = this.itemList.find(p => p.id === id)
      if (product) {
        this.$set(
          product,
          'tempCollectionIds',
          (product.selectedCollections || []).map(c => c.id)
        )
      }

      this.activeCollectionRow = id
    },
    applyCollections(product) {
      const selected = this.collections.filter(col =>
        (product.tempCollectionIds || []).includes(col.id)
      )

      this.$set(product, 'selectedCollections', selected)

      this.activeCollectionRow = null

      this.autoSave(product)
    },
    toggleCategoryPopup(id) {
      if (this.activeCategoryRow === id) {
        this.activeCategoryRow = null
        return
      }

      const product = this.itemList.find(p => p.id === id)

      if (product) {
        this.$set(
          product,
          'tempCategoryIds',
          (product.selectedCategories || []).map(c => c.id)
        )
      }

      this.activeCategoryRow = id
    },
    applyCategories(product) {
      const allCategories = []

      this.categories.forEach(cat => {
        allCategories.push(cat)
        if (cat.child?.length) {
          cat.child.forEach(sub => {
            allCategories.push(sub)
          })
        }
      })

      const selected = allCategories.filter(cat =>
        (product.tempCategoryIds || []).includes(cat.id)
      )

      product.selectedCategories = selected

      this.activeCategoryRow = null
      this.autoSave(product)
    },
    onImageLoaded(e) {
      e.target.classList.add('loaded')
    },
    toggleDiscountPopup(id) {
      if (this.activeDiscountRow === id) {
        this.activeDiscountRow = null
        return
      }
      const product = this.itemList.find(p => p.id === id)
      if (product) {
        this.$set(product, 'tempDiscount', product.discount_value || 0)
      }
      this.activeDiscountRow = id
    },
    applyDiscount(product) {
      if (!product.tempDiscount) return

      product.discount_value = product.tempDiscount
      this.activeDiscountRow = null

      this.autoSave(product)
    },
    hasVariants(product) {
      return (
        product.product_inventories &&
        product.product_inventories.length > 0
      )
    },
    openContentModal(product, field) {
      this.editingProduct = product
      this.editingField = field
      this.editingValue = product[field]
      this.showContentModal = true
    },
    closeContentModal() {
      this.showContentModal = false
      this.editingProduct = null
      this.editingField = ''
      this.editingValue = ''
    },
    saveContentModal() {
      if (!this.editingValue?.trim()) {
        this.$toast.error("Value cannot be empty")
        return
      }
      if (this.editingProduct && this.editingField) {
        this.$set(this.editingProduct, this.editingField, this.editingValue)
        this.autoSave(this.editingProduct)
      }
      this.closeContentModal()
    },
    calculateFinalProductPrice(product) {
      const base = Number(product.selling) || 0
      const discount = Number(product.discount_value) || 0

      if (!discount) return base.toFixed(2)

      let final = base

      if (product.discount_type === 'percentage') {
        final = base - (base * discount / 100)
      } else {
        final = base - discount
      }

      if (final < 0) final = 0

      return final.toFixed(2)
    },
    formatAttributes(inventory) {
      if (!inventory.inventory_attributes) return ''
      return inventory.inventory_attributes
        .map(attr => attr.attribute_value.title)
        .join(' + ')
    },
    toggleVariants(id) {
      if (this.expandedRows.includes(id)) {
        this.expandedRows = this.expandedRows.filter(rowId => rowId !== id)
      } else {
        this.expandedRows.push(id)
      }
    },
    isExpanded(id) {
      return this.expandedRows.includes(id)
    },
    async fetchUpsells() {
      this.loadingUpsells = true
      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/upsells/active`);
        this.allUpsells = response.data
      } catch (e) {
        console.error(e)
      } finally {
        this.loadingUpsells = false
      }
    },
    async fetchCategories() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/admin/product/with-categories`, {
          params: {
            category_id: this.filters.category_id
          }
        });
        this.categories = response.data.categories;
        this.collections = response.data.collections;
        this.brands = response.data.brands;
        this.products = response.data.products;
        this.bundles = response.data.bundles;
        this.updated_upsells = response.data.updated_upsells;
    },
    toggleStock(value) {
      this.filters.stock = this.filters.stock === value ? '' : value
    },
    onImageChange(event, product) {
      const file = event.target.files[0]
      if (!file) return

      product.newImage = file

      const reader = new FileReader()
      reader.onload = e => {
        this.$set(product, 'previewImage', e.target.result)
      }
      reader.readAsDataURL(file)
      event.target.value = ''
      this.autoSave(product)
    },
    mapProductsWithRelations() {

      if (!this.itemList.length) return
      if (!this.categories.length) return
      if (!this.collections.length) return

      this.itemList.forEach(product => {

        product._skipAutoSave = true

        const selectedCategories =
          product.product_categories?.map(pc => pc.category) || []

        this.$set(product, 'selectedCategories', selectedCategories)
        this.$set(product, 'tempCategoryIds', selectedCategories.map(c => c.id))
        this.$set(
          product,
          'primaryCategoryId',
          selectedCategories.length ? selectedCategories[0].id : ''
        )

        const selectedCollections =
          product.collection_with_products
            ? product.collection_with_products.map(
                c => c.product_collection
              )
            : []

        this.$set(product, 'selectedCollections', selectedCollections)
        this.$set(
          product,
          'tempCollectionIds',
          selectedCollections.map(c => c.id)
        )

        this.$set(product, 'upsell_id', product.upsell_id ? Number(product.upsell_id) : null)
        this.$set(product, 'updated_upsell_id', product.updated_upsell_id ? Number(product.updated_upsell_id) : null)
        this.$set(product, 'bundle_deal_id', product.bundle_deal_id ? Number(product.bundle_deal_id) : null)

        this.$set(product, 'status', product.status ? Number(product.status) : 1)
        this.$set(product, 'procurement', product.procurement !== undefined ? Number(product.procurement) : null)
        this.$set(product, 'discount_type', product.discount_type || 'fixed')
        this.$set(product, 'discount_value', product.discount_value || 0)

      })

      this.$nextTick(() => {
        this.itemList.forEach(product => {
          product._skipAutoSave = false
        })
      })
    },
  },
  async mounted() {
    await Promise.all([
      this.fetchCategories(),
      this.fetchUpsells()
    ])
    this.$nextTick(() => {
      this.isPageLoaded = true
    })
    this.$refs.productList?.reload?.()
    document.addEventListener('click', this.handleGlobalClick)
  },
  watch: {
      '$route.query': {
        handler(query) {
          this.isSyncingFromRoute = true

          const numberKeys = [
            'status',
            'brand',
            'category_id',
            'subcategory_id',
            'product_id',
            'collection_id',
            'crosssell_id'
          ]

          const booleanKeys = [
            'onlyVariants',
            'recentlyAdded',
            'highReturn',
            'deadStock'
          ]

          Object.keys(this.filters).forEach(key => {
            let value = query[key]

            
            if (booleanKeys.includes(key)) {
              value = value === 'true'
            }

            else {
              value = value !== undefined ? value : ''
            }


            this.filters[key] = value
          })

          this.$nextTick(() => {
            this.isSyncingFromRoute = false
            this.$refs.productList?.reload?.()
          })
        },
        immediate: true
      },
      filters: {
        handler: debounce(function () {

          if (this.isSyncingFromRoute) return

          const query = {}

          Object.keys(this.filters).forEach(key => {
            const value = this.filters[key]

            if (
              value !== '' &&
              value !== null &&
              value !== false &&
              value !== undefined
            ) {
              query[key] = value
            }
          })

          this.$router.replace({ query })

        }, 400),
        deep: true
      },
      itemList() {
        this.$nextTick(() => {
          this.expandedRows = this.itemList
          .filter(p => this.hasVariants(p))
          .map(p => p.id)
          
          this.mapProductsWithRelations()
        })
      },
      categories() {
        this.mapProductsWithRelations()
      },

      collections() {
        this.mapProductsWithRelations()
      },
      editorTitle(val){
        if(!this.editorSlug){
          this.editorSlug = val
            .toLowerCase()
            .replace(/[^\w\s-]/g,'')
            .replace(/\s+/g,'-')
        }
      }
    }
}
</script>
<style>
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
.top-buttons .left-button {
    float: left;
}
.top-buttons .right-button {
    float: right;
    margin-left: 10px;
}
.top-buttons h5 {
    clear: both;
    display: block !important;
    padding-top: 25px;
}
.custom-input {
    height: 60px !important;
    width: auto !important;
}
.image-input {
    display: flex;
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

.stock-chip input:checked {
    opacity: 0;
}

.image-edit-wrapper {
  position: relative;
  display: inline-block;
}

.hidden-file-input {
  display: none;
}

.image-label {
  position: relative;
  display: inline-block;
  cursor: pointer;
}

.image-label.disabled {
  cursor: not-allowed;
  opacity: 1;
}

.editable-image {
  width: 80px;
  height: 80px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid #ddd;
  transition: all 0.2s ease;
}

.image-label:hover .editable-image {
  transform: scale(1.05);
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.edit-overlay {
  position: absolute;
  bottom: 0;
  width: 100%;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  font-size: 12px;
  text-align: center;
  padding: 4px 0;
  border-radius: 0 0 10px 10px;
}
.multiselect-count {
  background: #4CAF50;
  color: #fff;
  border-radius: 20px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 500;
}

.expand-btn {
  cursor: pointer;
  font-size: 14px;
  width: 30px;
  text-align: center;
}

.variant-tr {
  background: #f8fafc;
}

.variant-wrapper {
  padding: 20px;
  border-top: 1px solid #e5e7eb;
}

/* Header */
.variant-header {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  font-weight: 600;
  font-size: 13px;
  margin-bottom: 10px;
  color: #475467;
}

/* Rows */
.variant-row {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  align-items: center;
  padding: 10px 0;
  border-top: 1px solid #eee;
}

.variant-row:first-of-type {
  border-top: none;
}

.variant-col {
  padding-right: 10px;
}

.attr-col {
  font-size: 13px;
  color: #344054;
}

/* Attribute text */
.attribute-text {
  background: #eef2ff;
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 12px;
  display: inline-block;
}

/* Inputs */
.variant-input {
  width: 100%;
  height: 42px;
  padding: 0 10px;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  transition: all 0.2s;
}

.variant-input:focus {
  border-color: #4CAF50;
  outline: none;
  box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.variant-input:disabled {
  background: #f2f4f7;
  cursor: not-allowed;
}

/* Discount Cell Layout */
.discount-cell {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 120px;
}

/* Select + Input Shared Style */
.discount-select,
.discount-input {
  height: 36px;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  padding: 0 8px;
  font-size: 13px;
  background: #fff;
  transition: all 0.2s ease;
}

/* Focus effect */
.discount-select:focus,
.discount-input:focus {
  border-color: #4CAF50;
  outline: none;
  box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

/* Disabled state */
.discount-select:disabled,
.discount-input:disabled {
  background: #f2f4f7;
  cursor: not-allowed;
}

/* Final price preview */
.discount-preview {
  font-size: 12px;
  font-weight: 600;
  color: #16a34a;
  padding: 4px 6px;
  background: #ecfdf3;
  border-radius: 4px;
  text-align: center;
}

.admin-header th {
  background: #f9fafb;
  font-size: 13px;
  font-weight: 600;
  padding: 10px;
  border-bottom: 1px solid #e5e7eb;
}

.admin-row {
  overflow: visible !important;
}

.admin-row td {
  padding: 8px 10px;
  border-bottom: 1px solid #f1f1f1;
  font-size: 13px;
  overflow: visible !important;
  position: relative;
}

.admin-row:hover {
  background: #f8fbff;
}


.row-selected {
  background: #eef4ff;
}

.expand-toggle {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  font-size: 16px;
  font-weight: 700;   /* make bold */
  cursor: pointer;
  color: #475467;
  margin: auto;       /* perfect center */
}

.product-thumb {
  width: 45px;
  height: 45px;
  border-radius: 6px;
  border: 1px solid #e5e7eb;
  object-fit: contain;
  transition: transform 0.25s ease, box-shadow 0.25s ease;
  position: relative;
  z-index: 1;
}

.image-label {
  display: inline-block;
  position: relative;
  cursor: pointer;
}

/* 🔥 FIXED ZOOM */
.image-label:hover .product-thumb {
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 0px 10px 0 rgb(0 0 0 / 18%);
    transform: scale(2);
    z-index: 9999;
}

.admin-input {
  height: 34px;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  padding: 0 8px;
  font-size: 13px;
  width: 100%;
}

.admin-input.small {
  width: 85px;
}

.admin-select {
  height: 34px;
  border-radius: 6px;
  border: 1px solid #d0d5dd;
  font-size: 13px;
  padding: 0 6px;
}

.discount-inline {
  display: flex;
  align-items: center;
  gap: 6px;
}

.price-preview {
  font-weight: 600;
  color: #16a34a;
  font-size: 12px;
}

.badge-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 22px;
  padding: 0 8px;
  background: #2563eb;
  color: #fff;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
}

.variant-box {
  background: #f9fafb;
  padding: 18px;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
}

.variant-header-clean {
  display: grid;
  grid-template-columns: 1fr 2fr 0.5fr 0.5fr 0.5fr 0.5fr 0.5fr;
  gap: 12px;
  font-weight: 600;
  font-size: 13px;
  padding: 8px 0;
  border-bottom: 1px solid #e5e7eb;
  text-align: center;
  color: #344054;
}

.variant-row-clean{
  animation: slideDown 0.25s ease;
}

@keyframes slideDown{
  from{
    opacity:0;
    transform:translateY(-5px);
  }
  to{
    opacity:1;
    transform:translateY(0);
  }
}

.variant-row-clean-item {
  display: grid;
  grid-template-columns: 1fr 2fr 0.5fr 0.5fr 0.5fr 0.5fr 0.5fr;
  gap: 12px;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid #f2f4f7;
  text-align: center;
}

.variant-row-clean-item .admin-input {
  text-align: center;
}

.variant-attr-clean {
  display: inline-block;
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 500;
  background: #eef2ff;
  color: #3730a3;
  border: 1px solid #e0e7ff;
}

.admin-header th:first-child,
.admin-row td:first-child {
  padding-left: 4px !important;
}

.admin-header th:nth-child(2),
.admin-row td:nth-child(2) {
  padding-left: 4px !important;
}

.col-checkbox {
  width: 36px;
  text-align: center;
  padding-left: 4px !important;
  padding-right: 4px !important;
}

input[type="checkbox"] {
  margin: 0;
  cursor: pointer;
}

.col-expand {
  width: 28px !important;
  min-width: 28px !important;
  max-width: 28px !important;
  padding: 0 4px !important;   /* small breathing space */
  text-align: center;
}

.admin-header th.col-expand,
.admin-row td.col-expand {
  padding: 0 !important;
}

table {
  table-layout: fixed;
}

.expand-toggle {
  transition: transform 0.2s ease;
}

.expand-toggle.open {
  transform: rotate(90deg);
}

.admin-header th {
  text-align: center !important;
  vertical-align: middle;
}

.admin-row td {
  text-align: center !important;
  vertical-align: middle;
}

.admin-input,
.admin-select {
  text-align: center;
}




.col-expand,
.col-checkbox {
  text-align: center;
}

.content-btn {
  border-radius: 6px;
  background: #eff6ff;
  font-size: 12px;
  cursor: pointer;
  transition: 0.2s ease;
}


.content-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Modal */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  overflow-y: scroll;
}

.modal-box {
  background: #fff;
  width: 500px;
  max-width: 90%;
  border-radius: 10px;
  padding: 20px;
  box-shadow: 0 20px 40px rgba(0,0,0,0.15);
}

.bulk-action-modal {
  width: 1100px;
  max-width: 95%;
}

.modal-textarea {
  width: 100%;
  min-height: 120px;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  padding: 10px;
  margin-top: 10px;
  font-size: 14px;
}

.modal-actions{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:20px;
  border-top:1px solid #eee;
  padding-top:15px;
}

.btn-cancel {
  border-radius: 6px;
  border: 1px solid #d0d5dd;
  background: #fff;
  cursor: pointer;
}

.btn-save {
  border-radius: 6px;
  border: none;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
}

.discount-cell-wrapper {
  position: relative;
  display: inline-block;
}

.discount-popup {
  position: absolute;
  top: 42px;
  left: 0;
  background: #fff;
  border-radius: 10px;
  padding: 14px;
  width: 240px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.18);
  border: 1px solid #e5e7eb;
  z-index: 9999;
}

.popup-header {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 10px;
  color: #111827;
}

.discount-popup-row {
  display: flex;
  gap: 8px;
}

.discount-popup-input {
  flex: 1;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  padding: 6px 8px;
  font-size: 13px;
}

.discount-popup-select {
  width: 60px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

.popup-preview {
  font-size: 12px;
  background: #f9fafb;
  padding: 6px 8px;
  border-radius: 6px;
  margin-bottom: 12px;
}

.discount-popup-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.popup-cancel {
  border: 1px solid #d1d5db;
  background: #fff;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
}

.discount-modern {
  display: flex;
  justify-content: center;
}

.discount-input-wrapper {
  display: flex;
  align-items: center;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  overflow: hidden;
  height: 38px;
  min-width: 120px;
  transition: all 0.2s ease;
}

.discount-input-wrapper:focus-within {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.currency-label {
  padding: 0 10px;
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  background: #f9fafb;
  border-right: 1px solid #e5e7eb;
}

.discount-input-modern {
  width: 60px;
  border: none;
  outline: none;
  text-align: center;
  font-size: 14px;
  font-weight: 500;
  color: #111827;
  background: transparent;
}

.discount-calc-btn {
  border: none;
  background: #f3f4f6;
  width: 38px;
  height: 100%;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  justify-content: center;
}

.discount-calc-btn i {
  font-size: 14px;
  color: #6b7280;
}

.discount-calc-btn:hover {
  background: #e5e7eb;
}

.discount-calc-btn:hover i {
  color: #2563eb;
}

.discount-calc-btn.active {
  background: #2563eb;
}

.discount-calc-btn.active i {
  color: #ffffff;
}

.fade-image {
  opacity: 0;
  transition: opacity 0.3s ease;
}

.fade-image.loaded {
  opacity: 1;
}



.category-cell-wrapper {
  position: relative;
}

.category-trigger {
  height: 34px;
  min-width: 70px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  background: #fff;
  font-size: 13px;
}

.category-trigger:hover {
  border-color: #2563eb;
}

.category-trigger .placeholder {
  color: #9ca3af;
}

.category-popup {
  position: absolute;
  top: 42px;
  left: 0;
  width: 240px;
  background: #fff;
  border-radius: 10px;
  padding: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.18);
  border: 1px solid #e5e7eb;
  z-index: 9999;
}

.category-list {
  max-height: 220px;
  overflow-y: auto;
  margin-bottom: 12px;
}

.category-option {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 4px;
  font-size: 13px;
  cursor: pointer;
}

.category-option input {
  cursor: pointer;
}

.category-group {
  margin-bottom: 6px;
}

.category-option.parent {
  font-weight: 600;
}

.subcategory-list {
  padding-left: 18px;
}

.category-option.child {
  font-size: 12px;
  color: #475467;
}

.popup-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.collection-cell-wrapper {
  position: relative;
}

.collection-trigger {
  height: 34px;
  min-width: 70px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  background: #fff;
  font-size: 13px;
}

.collection-trigger:hover {
  border-color: #2563eb;
}

.collection-popup {
  position: absolute;
  top: 42px;
  left: 0;
  width: 240px;
  background: #fff;
  border-radius: 10px;
  padding: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.18);
  border: 1px solid #e5e7eb;
  z-index: 9999;
}

.collection-list {
  max-height: 220px;
  overflow-y: auto;
  margin-bottom: 12px;
}

.collection-option {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 4px;
  font-size: 13px;
  cursor: pointer;
}

.primary-cell-wrapper {
  position: relative;
}

.primary-trigger {
  height: 34px;
  min-width: 90px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  background: #fff;
  font-size: 13px;
}

.primary-trigger:hover {
  border-color: #2563eb;
}

.primary-trigger .placeholder {
  color: #9ca3af;
}

.primary-popup {
  position: absolute;
  top: 42px;
  left: 0;
  width: 240px;
  background: #fff;
  border-radius: 10px;
  padding: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.18);
  border: 1px solid #e5e7eb;
  z-index: 9999;
}

.primary-list {
  max-height: 200px;
  overflow-y: auto;
  margin-bottom: 12px;
}

.primary-option {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 4px;
  font-size: 13px;
  cursor: pointer;
}
body:has(.focus-mode) .sidebar,
body:has(.focus-mode) header {
    display: none !important;
}
body:has(.focus-mode) .content {
  margin-left: 0 !important;
  padding-top: 20px;
}

/* Bulk Modal Product Preview */
.selected-preview {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  margin-top: 15px;
  max-height: 85px;
  overflow-y: auto;
  padding: 10px;
}

.bulk-product-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border-radius: 8px;
  transition: background 0.2s ease;
}

.bulk-product-row:hover {
  background: #eef4ff;
}

.bulk-product-image {
  width: 50px;
  height: 50px;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid #e5e7eb;
  background: #fff;
}

.bulk-product-info {
  display: flex;
  flex-direction: column;
}

.bulk-product-title {
  font-size: 14px;
  font-weight: 600;
  color: #111827;
}

.bulk-product-price {
  font-size: 12px;
  color: #6b7280;
}

.bulk-editor {
  padding: 0 !important;
  margin-top: 20px;
}


.bulk-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.bulk-field label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
}

.image-manager {
  width: 900px;
  max-width: 95%;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 25px 60px rgba(0,0,0,0.2);
  display: flex;
  flex-direction: column;
}

.image-manager-body {
  padding: 30px;
}

.image-manager-title {
  font-size: 22px;
  font-weight: 700;
  margin-bottom: 25px;
}

.image-grid {
  display: grid;
  gap: 25px;
  grid-template-columns: repeat(3, 1fr);
  max-height: 415px;
  overflow-y: scroll;
  padding-top: 5px;
}

.image-card {
  position: relative;
  border: 2px solid #ddd;
  border-radius: 8px;
  cursor: pointer;
  overflow: hidden;
  transition: all 0.2s ease;
}

.image-card img {
  width: 100%;
  height: 180px;
  object-fit: contain;
}

.image-card:hover {
  transform: translateY(-2px);
  border-color: #2563eb;
}

.image-card.active {
  border:2px solid #2563eb;
  box-shadow:0 0 0 2px rgba(37,99,235,0.2);
}

.image-check {
  position: absolute;
  top: 10px;
  right: 10px;
  background: #111;
  color: #fff;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
}

.image-manager-footer {
  background: linear-gradient(to bottom, #f7f8fa, #cfcfcf);
  padding: 20px 30px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.add-image-btn {
  background: #fff;
  padding: 10px 18px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: 0.2s ease;
}

.add-image-btn:hover {
  background: #f3f4f6;
}

.footer-actions {
  display: flex;
  gap: 12px;
}

.column-selector {
  position: fixed;
  right: 40px;
  top: 120px;
  z-index: 999999;
}

.column-box {
  width: 260px;
  background: #fff;
  border: 2px solid #000;
  padding: 15px;
  border-radius: 10px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.25);
}

.column-header {
  margin-bottom: 12px;
}

.column-header strong {
  display: block;
  font-size: 15px;
}

.column-header span {
  font-size: 12px;
  color: #555;
}

.column-option {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  padding: 4px 0;
  cursor: pointer;
}

/* ============================= */
/* FILTER TOOLBAR */
/* ============================= */

.filter-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 14px 18px;
  border-radius: 12px;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
  margin-bottom: 18px;
}

.filter-left,
.filter-right {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

/* Dropdown pills */
.filter-pill {
  padding: 7px 14px;
  border-radius: 30px;
  border: 1px solid #dcdcdc;
  background: #f9fafb;
  font-size: 13px;
  cursor: pointer;
  transition: 0.2s ease;
}

.filter-pill:hover {
  border-color: #6366f1;
  background: #f3f4ff;
}

/* Search */
.search-input input {
  padding: 8px 14px;
  border-radius: 30px;
  border: 1px solid #ddd;
  width: 260px;
  font-size: 13px;
  transition: 0.2s;
}

.search-input input:focus {
  border-color: #6366f1;
  outline: none;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

/* Buttons */
.btn-primary {
  background: #6366f1;
  color: #fff;
  border-radius: 8px;
  border: none;
  font-size: 13px;
  transition: 0.2s;
}

.btn-primary:hover {
  background: #4f46e5;
}

.btn-outline {
  border: 1px solid #ddd;
  background: #fff;
  border-radius: 8px;
  font-size: 13px;
}

.btn-advance {
  background: #f3f4f6;
  border: 1px solid #ddd;
  border-radius: 8px;
  font-size: 13px;
  transition: 0.2s;
}

.btn-advance:hover {
  background: #e5e7eb;
}

/* ============================= */
/* ADVANCED FILTER CARD */
/* ============================= */

.advanced-card {
  background: #f3f4f6;
  border-radius: 12px;
  padding: 20px;
  margin-top: 12px;
  border: 1px solid #e5e7eb;
  margin-bottom: 16px;
}


.advanced-header {
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 16px;
  color: #111827;
}

.advanced-row {
  display: flex;
  flex-direction: row;
  gap: 18px;
}

/* Selects */
.advanced-selects {
  display: grid;
  grid-template-columns: repeat(5, minmax(160px, auto));
  gap: 14px;
}

.filter-select {
  height: 38px;
  padding: 0 12px;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  background: #ffffff;
  font-size: 13px;
  transition: all 0.2s ease;
}

.filter-select:focus {
  border-color: #6366f1;
  outline: none;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

/* Checkboxes */
.advanced-checkboxes {
  display: grid;
  grid-template-columns: repeat(2, auto);
  gap: 10px 40px;
}

.advanced-checkboxes label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  cursor: pointer;
  color: #374151;
}

.advanced-checkboxes input {
  width: 20px;
  height: 20px;
  cursor: pointer;
}

.advanced-checkboxes label:hover {
  color: #111827;
}

.bulk-table-wrapper {
  max-height: 280px;
  overflow: auto;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  margin: 15px 0 25px 0;
  background: #fff;
}

.bulk-table-wrapper table {
  width: 100%;
  min-width: 1050px;
  border-collapse: collapse;
  table-layout: auto;
}

.bulk-table-wrapper thead th {
  position: sticky;
  top: 0;
  background: #f9fafb;
  z-index: 5;
  white-space: nowrap;
  padding: 12px 14px;
  font-size: 13px;
}

.bulk-table-wrapper td {
  padding: 12px 14px;
  vertical-align: middle;
  white-space: nowrap;
}

.bulk-preview-row {
  border-top: 1px solid #f1f1f1;
}

.bulk-preview-row:hover {
  background: #f8fbff;
}

.bulk-thumb {
  width: 50px;
  height: 50px;
  object-fit: contain;
}

.bulk-title {
  font-weight: 600;
  font-size: 14px;
}

.bulk-id {
  font-size: 11px;
  color: #9ca3af;
}

.chip-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.chip {
  background: #eef2ff;
  color: #3730a3;
  padding: 4px 8px;
  font-size: 11px;
  border-radius: 20px;
  border: 1px solid #e0e7ff;
}

.chip-purple {
  background: #f3e8ff;
  color: #7e22ce;
  border: 1px solid #e9d5ff;
}

.chip-green {
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #d1fae5;
}

.primary-badge {
  background: #dbeafe;
  color: #1d4ed8;
  padding: 5px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
}

.price-cell {
  font-weight: 600;
}

.discount-value {
  font-weight: 600;
  color: #dc2626;
}

.final-price {
  font-size: 11px;
  color: #16a34a;
}

.status-badge {
  padding: 5px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.status-public {
  background: #dcfce7;
  color: #166534;
}

.status-private {
  background: #fee2e2;
  color: #991b1b;
}

.muted {
  color: #9ca3af;
  font-size: 12px;
}

.bulk-editor-card {
  background: #ffffff;
  border-radius: 16px;
  padding: 28px;
  margin-top: 20px;
  border: 1px solid #eef2f7;
  box-shadow: 0 10px 30px rgba(0,0,0,0.04);
}

.bulk-editor-header {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 20px;
  color: #111827;
}

.editor-grid{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:20px;
}

.editor-field.full{
  grid-column:1 / -1;
}

.bulk-editor-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 28px 32px;
}

.confirm-modal-box {
  background: #fff;
  width: 420px;
  max-width: 90%;
  border-radius: 14px;
  padding: 28px;
  text-align: center;
  box-shadow: 0 25px 60px rgba(0,0,0,0.25);
}

.confirm-icon {
  font-size: 32px;
  color: #f59e0b;
  margin-bottom: 14px;
}

.confirm-warning {
  font-size: 13px;
  color: #6b7280;
  margin-top: 10px;
}

.category-trigger{
  position: relative;
}

.category-hover-tooltip{
  position: absolute;
  top: 40px;
  left: 0;
  background: #111;
  color: #fff;
  padding: 8px 10px;
  border-radius: 6px;
  font-size: 12px;
  min-width: 140px;
  z-index: 9999;
  box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}

.tooltip-item{
  padding: 3px 0;
}

.variant-image{
  width:40px;
  height:40px;
  border:1px dashed #d0d5dd;
  border-radius:6px;
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  background:#fff;
}

.variant-placeholder{
  width:30px;
  height:30px;
  border:1px dashed #d0d5dd;
  border-radius:4px;
  display:flex;
  align-items:center;
  justify-content:center;
  color:#9ca3af;
  font-size:14px;
  background:#fff;
}

.variant-image-wrapper{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:20px;
}

.variant-checkbox{
  width:16px;
  height:16px;
  cursor:pointer;
}

.variant-images{
  display:flex;
  justify-content:center;
  align-items:center;
  gap:3px;
  cursor:pointer;
}

.variant-thumb{
  width:30px;
  height:30px;
  border-radius:4px;
  object-fit:cover;
  display:block;
}

.variant-more{
  font-size:11px;
  color:#666;
}

.column-actions{
  margin-top:12px;
  text-align:right;
}

.preview-row{
  background:#fffbeb;
}

.bulk-preview-header th{
  background:#fff3cd;
  padding:12px 16px;
}

.bulk-preview-header td{
  padding:0;
  border:none;
  background:transparent;
}


.bulk-preview-bar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:10px 14px;
  background:#fef3c7;
  border-bottom:1px solid #f1e3a3;
}


.bulk-preview-title{
  display:flex;
  justify-content:space-between;
  align-items:center;
}

.bulk-preview-title span{
  font-weight:600;
  font-size:13px;
  color:#6b4f00;
}

.bulk-preview-actions{
  display:flex;
  gap:8px;
}


.bulk-preview-actions .primary-btn{
  height:32px;
  padding:0 14px;
}

.bulk-edit-row{
  background:#f9fafb;
  border-bottom:2px solid #e5e7eb;
}

.bulk-edit-row td{
  padding:10px;
}

.bulk-edit-row td:first-child{
  font-weight:600;
  color:#475467;
}

.bulk-label{
  font-weight:600;
  color:#2563eb;
}

.bulk-preview-left{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:13px;
  font-weight:600;
  color:#7c5e10;
}

.bulk-preview-right{
  display:flex;
  gap:8px;
}

.bulk-preview-right button{
  height:42px;
}

.procurement-wrapper{
  display:flex;
  gap:10px;
  justify-content:center;
}

.procurement-option{
  display:flex;
  align-items:center;
  gap:4px;
  font-size:12px;
  cursor:pointer;
}

.draggable-header{
  cursor: grab;
  user-select: none;
}

.draggable-header:active{
  cursor: grabbing;
}

.drag-icon{
  margin-right:6px;
  color:#9ca3af;
}

.admin-container{
  overflow: visible !important;
}

.column-list{
  max-height:260px;
  overflow-y:auto;
}

.editable-text{
  max-width:180px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  cursor:pointer;
  transition:0.2s;
  font-weight: 600;
}

.editable-text:hover{
  color:#2563eb;
  text-decoration:underline;
}

.slug-text{
  font-size:12px;
  color:#6b7280;
}

.editor-modal {
  width: 100%;
  max-width: 95%;
  background: #fff;
  border-radius: 12px;
  padding: 25px;
  height: stretch;
}

.editor-title{
  font-size:20px;
  font-weight:600;
}

.editor-field{
  display:flex;
  flex-direction:column;
  margin-bottom:12px;
}

.editor-field label{
  font-size:12px;
  font-weight:600;
  margin-bottom:4px;
}

.primary-trigger span {
    font-size: 11px;
}

.table-result-bar{
  display:flex;
  justify-content:flex-end;
  align-items:center;
  margin-bottom: -50px;
}

.table-result-right button{
  height:36px;
  margin-right: 20px;
}

.admin-textarea{
  border:1px solid #d0d5dd;
  border-radius:6px;
  padding:8px;
  resize:vertical;
}

.admin-textarea:focus{
  border-color:#2563eb;
  outline:none;
  box-shadow:0 0 0 2px rgba(37,99,235,0.1);
}

.editor-layout{
  display:grid;
  grid-template-columns:420px 1fr;
  gap:28px;
  margin-top:20px;
}

.editor-left{
  display:flex;
  flex-direction:column;
  gap:55px;
}

.editor-right{
  display:flex;
  flex-direction:column;
  gap:20px;
}

.editor-card{
  background:#f9fafb;
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:18px;
}

.editor-card h3{
  font-size:14px;
  font-weight:600;
  margin-bottom:12px;
  color:#111827;
}

</style>

