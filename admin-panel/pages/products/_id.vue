<template>
  <div class="product-form pv2">
    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <!-- ============ TOP ACTION BAR ============ -->
    <div class="pv2-topbar">
      <div class="pv2-topbar-left">
        <button
          class="btn secondary-btn pv2-back"
          @click.prevent="goBack"
        >
          <i class="fas fa-arrow-left mr-5" /> {{ $t('pages.products.back') }}
        </button>
        <span class="pv2-divider" />
        <h4 class="pv2-title">{{ isAdding ? $t('pages.products.newProduct') : (result.title || $t('pages.products.product')) }}</h4>
        <span
          class="pv2-badge"
          :class="statusBadgeClass"
        >{{ statusLabel(result.status) }}</span>
      </div>
      <div
        v-if="validLicence && ($can('product', 'edit') || $can('product', 'create'))"
        class="pv2-topbar-right"
      >
        <button
          class="btn secondary-btn"
          @click.prevent="goBack"
        >{{ $t('pages.products.discard') }}</button>
        <ajax-button
          name="save-edit"
          class="secondary-btn"
          :text="$t('pages.products.saveDraft')"
          :fetching-data="formSubmitting && !redirect"
          @click.native="submitAll('save-edit')"
        />
        <ajax-button
          name="save"
          class="primary-btn"
          :text="isAdding ? $t('pages.products.publish') : $t('pages.products.update')"
          :fetching-data="formSubmitting && redirect"
          @click.native="submitAll('save')"
        />
      </div>
    </div>

    <div class="pv2-body">
      <!-- ============ LEFT TAB RAIL ============ -->
      <nav class="pv2-rail">
        <div
          v-for="group in tabGroups"
          :key="group.g"
          class="pv2-rail-group"
        >
          <div class="pv2-rail-gtitle">{{ group.g }}</div>
          <button
            v-for="tab in group.items"
            :key="tab.k"
            type="button"
            class="pv2-rail-item"
            :class="{active: activeTab === tab.k, 'has-err': tabErrors[tab.k]}"
            @click="setTab(tab.k)"
          >
            <i
              class="pv2-rail-ic fas"
              :class="tab.ic"
            />
            <span>{{ tab.l }}</span>
            <span
              v-if="tabErrors[tab.k]"
              class="pv2-rail-badge"
            >{{ tabErrors[tab.k] }}</span>
          </button>
        </div>
      </nav>

      <!-- ============ CENTER CONTENT ============ -->
      <div
        ref="productForm"
        class="pv2-content"
      >
        <div
          v-if="errorCount"
          class="pv2-err-banner"
        >
          <i class="fas fa-exclamation-circle" />
          <span>{{ $t('pages.products.pleaseFix') }} <strong>{{ errorCount }}</strong> {{ errorCount > 1 ? $t('pages.products.requiredFieldsMany') : $t('pages.products.requiredFieldsOne') }}</span>
        </div>

        <error-formatter />

        <div class="form-wrapper pv2-form">

          <!-- ==================== BASIC INFO ==================== -->
          <section v-show="activeTab === 'basic'">
            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.productInformation') }}</h5>

              <div class="input-wrapper">
                <label>{{ $t('index.title') }}</label>
                <input
                  type="text"
                  :placeholder="$t('index.title')"
                  @change="slugChange"
                  v-model="result.title"
                  :class="{invalid: !result.title && hasError}"
                >
                <span
                  class="error"
                  v-if="!result.title && hasError"
                >
                  {{ $t('category.req', { type: $t('index.title')}) }}
                </span>
              </div>

              <div class="input-wrapper">
                <label>{{ $t('pages.products.shortHeading') }}</label>
                <input
                  type="text"
                  :placeholder="$t('pages.products.shortHeading')"
                  v-model="result.short_heading"
                  :class="{invalid: !result.short_heading && hasError}"
                >
                <span
                  class="error"
                  v-if="!result.short_heading && hasError"
                >
                  {{ $t('category.req', { type: $t('pages.products.shortHeading') }) }}
                </span>
              </div>

              <div class="input-wrapper">
                <label>{{ $t('category.slug') }}</label>
                <input
                  type="text"
                  :placeholder="$t('category.slug')"
                  name="slug"
                  v-model="result.slug"
                  ref="slug"
                  :class="{invalid: !!!result.slug && hasError}"
                >
                <span
                  class="error"
                  v-if="!!!result.slug && hasError"
                >
                  {{ $t('category.req', { type: $t('category.slug')}) }}
                </span>
              </div>

              <div class="pv2-grid c3">
                <div class="input-wrapper">
                  <label>{{ $t('prod.unit') }}</label>
                  <input
                    type="text"
                    :placeholder="$t('prod.unit')"
                    v-model="result.unit"
                    :class="{invalid: !!!result.unit && hasError}"
                  >
                  <span
                    class="error"
                    v-if="!!!result.unit && hasError"
                  >
                    {{ $t('category.req', { type: $t('prod.unit')}) }}
                  </span>
                </div>

                <div class="input-wrapper">
                  <label>{{ $t('prod.badge') }}</label>
                  <input
                    type="text"
                    :placeholder="$t('prod.badge')"
                    v-model="result.badge"
                  >
                </div>
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.variantType') }}</h5>
              <p class="pv2-help">{{ $t('pages.products.variantTypeHelp') }}</p>
              <div class="pv2-type-grid two">
                <div
                  class="pv2-type"
                  :class="{sel: !uiVariable}"
                  @click="uiVariable = false"
                >
                  <i class="fas fa-box" />
                  <div class="pv2-type-l">{{ $t('pages.products.simpleProduct') }}</div>
                  <div class="pv2-type-d">{{ $t('pages.products.simpleProductDesc') }}</div>
                </div>
                <div
                  class="pv2-type"
                  :class="{sel: uiVariable}"
                  @click="setVariable"
                >
                  <i class="fas fa-sitemap" />
                  <div class="pv2-type-l">{{ $t('pages.products.variableProduct') }}</div>
                  <div class="pv2-type-d">{{ $t('pages.products.variableProductDesc') }}</div>
                </div>
              </div>
            </div>
          </section>

          <!-- ==================== CONTENT ==================== -->
          <section v-show="activeTab === 'content'">
            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.overviewDescription') }}</h5>
              <div
                class="input-wrapper"
                :class="{'whysigwyg-error': !!!result.overview && hasError}"
              >
                <WYSIWYGEditor
                  :title="$t('prod.overview')"
                  :description="result.overview"
                  @change="result.overview = $event"
                  @file="editorOverviewFile"
                />
                <span
                  class="error"
                  v-if="!!!result.overview && hasError"
                >
                  {{ $t('category.req', { type: $t('prod.overview')}) }}
                </span>
              </div>
              <div
                class="input-wrapper"
                :class="{'whysigwyg-error': !!!result.description && hasError}"
              >
                <WYSIWYGEditor
                  :description="result.description"
                  @change="result.description = $event"
                  @file="editorDescriptionFile"
                />
                <span
                  class="error"
                  v-if="!!!result.description && hasError"
                >
                  {{ $t('category.req', { type: $t('prod.desc')}) }}
                </span>
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.technicalSpecifications') }}</h5>

              <div
                class="pv2-spec-box"
                v-for="(group, groupIndex) in specifications"
                :key="groupIndex"
              >
                <div class="pv2-spec-cat">
                  <label class="pv2-spec-label">{{ $t('pages.products.specCategory') }}</label>
                  <div class="pv2-spec-row">
                    <input
                      type="text"
                      class="pv2-spec-input"
                      :placeholder="$t('pages.products.specCategoryPlaceholder')"
                      v-model="group.group"
                    >
                    <button
                      class="pv2-spec-del"
                      @click.prevent="removeGroup(groupIndex)"
                      :title="$t('pages.products.removeCategory')"
                    >
                      <i class="fas fa-trash"></i>
                    </button>
                  </div>
                </div>

                <div
                  class="pv2-spec-row"
                  v-for="(item, itemIndex) in group.items"
                  :key="itemIndex"
                >
                  <input
                    type="text"
                    class="pv2-spec-input"
                    :placeholder="$t('pages.products.enterSpecification')"
                    v-model="item.value"
                  >
                  <button
                    class="pv2-spec-del"
                    @click.prevent="removeItem(groupIndex, itemIndex)"
                    :title="$t('pages.products.removeSpecification')"
                  >
                    <i class="fas fa-trash"></i>
                  </button>
                </div>

                <button
                  class="pv2-spec-add"
                  @click.prevent="addItem(groupIndex)"
                >
                  <i class="fas fa-plus mr-5" /> {{ $t('pages.products.addSpec') }}
                </button>
              </div>

              <button
                class="primary-btn pv2-spec-addcat"
                @click.prevent="addGroup"
              >
                <i class="fas fa-plus mr-5" /> {{ $t('pages.products.addSpecCategory') }}
              </button>
            </div>
          </section>

          <!-- ==================== MEDIA ==================== -->
          <section v-show="activeTab === 'media'">
            <div class="pv2-card">
              <h5 class="pv2-ctitle">
                {{ $t('prod.pVideo') }}
                <span class="fw-400 f-8 mt">{{ $t('prod.maxVid') }}</span>
              </h5>
              <div
                v-if="$can('product', 'edit') || $can('product', 'create')"
              >
                <error-formatter type="video" />
                <video-input
                  v-if="mediaStorageData.URL === mediaStorage"
                  :saving="videoUploading"
                  :video="result.video"
                  :video-thumb="result.video_thumb"
                  @image-change="uploadVideo(null, $event)"
                />
                <video-upload
                  v-else
                  class="upload-block"
                  :video="result.video"
                  type="video"
                  :file-uploading="videoUploading"
                  @file-upload="uploadVideo"
                />
              </div>
              <div
                v-else-if="$can('product', 'view')"
              >
                <video
                  v-if="result.video"
                  controls autoplay
                >
                  <source
                    :src="getVideoURL(result.video)"
                    type="video/mp4">
                </video>
                <div v-else>{{ $t('prod.noVid') }}</div>
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">
                {{ $t('prod.pImg') }}
                <span class="fw-400 f-8 mt">{{ $t('prod.suggImg') }}</span>
              </h5>
              <div v-if="!loading">
                <div
                  v-if="$can('product', 'edit') || $can('product', 'create')"
                  class="upload-block"
                >
                  <error-formatter type="image" />
                  <image-input
                    v-if="mediaStorageData.URL === mediaStorage"
                    :saving="fileUploading"
                    :image="result.image"
                    @image-change="imageInputChanged"
                  />
                  <file-upload
                    v-else
                    class="upload-block"
                    :image="result.image"
                    :file-uploading="fileUploading"
                    @file-upload="uploadFile"
                  />
                </div>
                <img
                  v-else-if="$can('product', 'view')"
                  class="mx-w-300x"
                  :src="getImageURL(result.image)"
                >
              </div>
            </div>

            <div
              class="pv2-card"
              v-if="!isAdding"
            >
              <h5 class="pv2-ctitle">
                {{ $t('prod.pImgs') }}
                <span class="fw-400 f-8 mt">{{ $t('prod.suggImg') }}</span>
              </h5>
              <product-images
                :product-images="result.product_images"
                @result="productImages"
              />
            </div>

            <div
              class="pv2-card"
              v-if="!isAdding"
            >
              <h5 class="pv2-ctitle">{{ $t('pages.products.productFeatureImages') }}</h5>
              <product-feature-images
                :feature-images="result.feature_images"
                @result="featureImagesResult"
              />
            </div>

            <div
              class="pv2-card pv2-note"
              v-if="isAdding"
            >
              <i class="fas fa-info-circle mr-5" />
              {{ $t('pages.products.galleryAfterSave') }}
            </div>
          </section>

          <!-- ==================== PRICING & TAX ==================== -->
          <section v-show="activeTab === 'pricing'">
            <div
              v-for="(flash, index) in result.flash_sale_product"
              :key="'flash-' + index"
              class="info-msg mb-20 mb-sm-15"
            >
              <p>
                {{ $t('prod.flash') }}
                (<nuxt-link
                  class="link"
                  :to="`/flash-sales/${flash.flash_sale.id}`"
                >{{ flash.flash_sale.title }}</nuxt-link>)
              </p>
              <p>{{ $t('prod.flashPrice') }}: <b>{{ priceFormatting(flash.price) }}</b></p>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.pricing') }}</h5>
              <div class="pv2-grid c3">
                <!--
                  A "Wholesale Price" and "Discount" pair used to sit here,
                  bound to products.wholesale_price and
                  products.wholesale_discount_*. Nothing reads those columns:
                  trade and business pricing is decided by the quantity bands
                  in business_product_pricing, which the storefront, the cart
                  and checkout all price from. Setting a figure here changed no
                  price anywhere, which is worse than having no field at all.
                -->
                <div class="input-wrapper pv2-span-2">
                  <label>{{ $t('pages.products.wholesaleTradePricing') }}</label>
                  <p class="pv2-field-note">
                    {{ $t('pages.products.quantityBandsNote') }}
                    <nuxt-link class="link" to="/business-products">{{ $t('pages.products.businessProducts') }}</nuxt-link>.
                  </p>
                </div>

                <div class="input-wrapper">
                  <label>{{ $t('prod.purchased') }} ({{ currencyIcon }})</label>
                  <input
                    type="number"
                    step="any"
                    placeholder="0.00"
                    v-model="result.purchased"
                    :class="{invalid: (result.purchased < 1 || !result.purchased) && hasError}"
                  >
                  <span
                    class="error"
                    v-if="(result.purchased < 1 || !result.purchased) && hasError"
                  >
                    {{ $t('category.req', { type: $t('brand.price')}) }}
                  </span>
                </div>

                <div class="input-wrapper">
                  <label>{{ $t('prod.selling') }} ({{ currencyIcon }})</label>
                  <input
                    type="number"
                    step="any"
                    placeholder="0.00"
                    v-model="result.selling"
                    :class="{invalid: (result.selling < 1 || !result.selling) && hasError}"
                  >
                  <span
                    class="error"
                    v-if="(result.selling < 1 || !result.selling) && hasError"
                  >
                    {{ $t('category.req', { type: $t('brand.price')}) }}
                  </span>
                </div>

                <div class="input-wrapper">
                  <label>{{ $t('prod.offered') }} ({{ currencyIcon }})</label>
                  <input
                    type="number"
                    step="any"
                    placeholder="0.00"
                    v-model="result.offered"
                  >
                </div>
              </div>
            </div>

            <div class="pv2-card pv2-profit">
              <h5 class="pv2-ctitle">{{ $t('pages.products.liveProfitCalculator') }}</h5>
              <div class="pv2-profit-grid">
                <div>
                  <div class="pv2-profit-l">{{ $t('pages.products.cost') }}</div>
                  <div class="pv2-profit-v">{{ currencyIcon }}{{ uiCost.toFixed(2) }}</div>
                </div>
                <div>
                  <!-- the price a customer pays: the offer when there is one -->
                  <div class="pv2-profit-l">
                    {{ $t('pages.products.price') }}
                    <span v-if="result.offered > 0 && result.offered < result.selling" class="pv2-profit-note">{{ $t('pages.products.offer') }}</span>
                  </div>
                  <div class="pv2-profit-v">{{ currencyIcon }}{{ uiPrice.toFixed(2) }}</div>
                </div>
                <div v-if="uiPriceIncludesVat">
                  <!-- VAT is collected on Revenue's behalf, so it is not income -->
                  <div class="pv2-profit-l">{{ $t('pages.products.exVat') }} <span class="pv2-profit-note">&minus;{{ uiVatRate }}%</span></div>
                  <div class="pv2-profit-v">{{ currencyIcon }}{{ uiNetRevenue.toFixed(2) }}</div>
                </div>
                <div>
                  <div class="pv2-profit-l">{{ $t('pages.products.profit') }}</div>
                  <div class="pv2-profit-v" :style="{color: uiProfit >= 0 ? '#059669' : '#dc2626'}">{{ currencyIcon }}{{ uiProfit.toFixed(2) }}</div>
                </div>
                <div>
                  <div class="pv2-profit-l">{{ $t('pages.products.margin') }}</div>
                  <div class="pv2-profit-v" :style="{color: marginColor}">{{ uiMargin.toFixed(1) }}%</div>
                </div>
                <div>
                  <div class="pv2-profit-l">{{ $t('pages.products.roi') }}</div>
                  <div class="pv2-profit-v" style="color:#7c3aed">{{ uiCost > 0 ? uiRoi.toFixed(0) + '%' : '—' }}</div>
                </div>
              </div>

              <p v-if="uiCost <= 0" class="pv2-profit-hint">
                {{ $t('pages.products.setA') }} <strong>{{ $t('pages.products.purchased') }}</strong> {{ $t('pages.products.costToSeeProfit') }}
              </p>
              <p v-else-if="uiVariable" class="pv2-profit-hint">
                {{ $t('pages.products.figuresUseProductPrice') }}
              </p>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.tax') }}</h5>
              <div class="pv2-grid c3">
                <div class="input-wrapper">
                  <label class="block">{{ $t('prod.tRule') }}</label>
                  <dropdown
                    v-if="allTaxRules"
                    :selectedKey="result.tax_rule_id"
                    :options="allTaxRules"
                    @clicked="taxRuleSelected"
                  />
                  <span
                    class="error"
                    v-if="!!!result.tax_rule_id && hasError"
                  >
                    {{ $t('category.req', { type: $t('brand.tRule')}) }}
                  </span>
                </div>

                <div class="input-wrapper">
                  <label class="block pv2-spacer-lbl">&nbsp;</label>
                  <label class="checkbox-container">
                    <input
                      class="checkbox-input"
                      type="checkbox"
                      v-model="result.excludeVAT"
                      true-value="1"
                      false-value="0"
                    >
                    <span class="checkbox-checkmark"></span>
                    <span class="checkbox-label">{{ $t('prod.excludeVAT') }}</span>
                  </label>

                  <label class="checkbox-container mt-10">
                    <input
                      class="checkbox-input"
                      type="checkbox"
                      v-model="result.procurement"
                      true-value="1"
                      false-value="0"
                    >
                    <span class="checkbox-checkmark"></span>
                    <span class="checkbox-label">{{ $t('prod.procurement') }}</span>
                  </label>
                </div>
              </div>
            </div>
          </section>

          <!-- ==================== INVENTORY ==================== -->
          <section v-show="activeTab === 'inventory'">
            <div
              class="pv2-card"
              ref="productInventory"
            >
              <product-inventory
                ref="inventoryComponent"
                :product-id="result.id"
                :attributes="allAttributes"
                :product-price="parseFloat(currentPrice)"
                :is-variable="uiVariable"
                @variant-detected="uiVariable = $event"
                @has-error="scrollToTop('productInventory')"
              />
            </div>
          </section>

          <!-- ==================== UPGRADES ==================== -->
          <section v-show="activeTab === 'upgrades'">
            <div class="pv2-card">
              <!--
                Only meaningful once the product exists: the panel reads the
                product's variants to work out what could upgrade, and a
                product being created has none yet.
              -->
              <product-upgrades v-if="result.id" :product-id="result.id" />

              <div v-else class="pv2-note">
                <i class="fas fa-info-circle mr-5" />
                <span>{{ $t('pages.products.saveFirstUpgrades') }}</span>
              </div>
            </div>
          </section>

          <!-- ==================== SHIPPING ==================== -->
          <section v-show="activeTab === 'shipping'">
            <!--
              Products are not assigned a shipping rule (2026-09-28). What
              delivery costs comes from the customer's address and the Shipping
              Rules page; all a product can say is where it may be sold.
            -->
            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.whereSold') }}</h5>
              <p class="pv2-help">
                {{ $t('pages.products.deliveryPricesHelp') }}
              </p>

              <div class="pv2-chip-group" role="radiogroup" :aria-label="$t('pages.products.whereSold')">
                <label class="pv2-chk-chip" :class="{active: !sellRestricted}">
                  <input type="radio" name="sell-where" :checked="!sellRestricted" @change="setSellRestricted(false)">
                  {{ $t('pages.products.anywhereWeDeliver') }}
                </label>
                <label class="pv2-chk-chip" :class="{active: sellRestricted}">
                  <input type="radio" name="sell-where" :checked="sellRestricted" @change="setSellRestricted(true)">
                  {{ $t('pages.products.onlySelectedCountries') }}
                </label>
              </div>

              <div v-if="sellRestricted" class="pv2-sell-countries">
                <div v-if="sellCountryCodes.length" class="pv2-chip-group">
                  <span
                    v-for="code in sellCountryCodes"
                    :key="code"
                    class="pv2-chk-chip active"
                  >
                    {{ countryName(code) }}
                    <a
                      href="#"
                      role="button"
                      class="pv2-chip-remove"
                      :aria-label="$t('pages.products.removeCountry', { name: countryName(code) })"
                      @click.prevent="removeSellCountry(code)"
                    >&times;</a>
                  </span>
                </div>

                <div class="input-wrapper pv2-sell-add">
                  <select
                    v-model="sellCountryToAdd"
                    :aria-label="$t('pages.products.addCountry')"
                    @change="addSellCountry"
                  >
                    <option value="">{{ $t('pages.products.addCountryEllipsis') }}</option>
                    <option v-for="c in countryOptions" :key="c.code" :value="c.code">{{ c.name }}</option>
                  </select>
                </div>

                <p v-if="!sellCountryCodes.length" class="pv2-help pv2-sell-warn">
                  {{ $t('pages.products.addAtLeastOneCountry') }}
                </p>
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.dimensions') }}</h5>
              <p class="pv2-help">{{ $t('pages.products.dimensionsHelp') }}</p>
              <div class="pv2-grid c4">
                <div class="input-wrapper">
                  <label>{{ $t('pages.products.lengthCm') }}</label>
                  <input type="number" step="any" placeholder="0" v-model="result.length">
                </div>
                <div class="input-wrapper">
                  <label>{{ $t('pages.products.widthCm') }}</label>
                  <input type="number" step="any" placeholder="0" v-model="result.width">
                </div>
                <div class="input-wrapper">
                  <label>{{ $t('pages.products.heightCm') }}</label>
                  <input type="number" step="any" placeholder="0" v-model="result.height">
                </div>
                <div class="input-wrapper">
                  <label>{{ $t('pages.products.weightKg') }}</label>
                  <input type="number" step="any" placeholder="0" v-model="result.weight">
                </div>
              </div>
            </div>
          </section>

          <!-- ==================== SEO & META ==================== -->
          <section v-show="activeTab === 'seo'">
            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.focusKeyphrase') }}</h5>
              <div class="input-wrapper">
                <input
                  type="text"
                  :placeholder="$t('pages.products.focusKeyphrasePlaceholder')"
                  v-model="uiFocusKw"
                >
                <!--
                  There is no column behind this. It scores the checks below
                  while the page is open and is gone on reload; saying so beats
                  an SEO score that silently resets to 33% every time.
                -->
                <p class="pv2-field-note">
                  {{ $t('pages.products.focusKeyphraseNote') }}
                </p>
              </div>
            </div>

            <div class="pv2-grid c2 pv2-seo-row">
              <div class="pv2-card">
                <h5 class="pv2-ctitle">{{ $t('pages.products.googlePreview') }}</h5>
                <div class="pv2-google">
                  <div class="pv2-google-t">{{ result.meta_title || result.title || $t('pages.products.metaTitlePreview') }}</div>
                  <div class="pv2-google-u">shop.fixmypc.ie/product/{{ result.slug || '...' }}</div>
                  <div class="pv2-google-d">{{ result.meta_description || $t('pages.products.metaDescriptionPreview') }}</div>
                </div>
              </div>
              <div class="pv2-card">
                <h5 class="pv2-ctitle">{{ $t('pages.products.seoScore', { score: seoScore }) }}</h5>
                <div
                  v-for="(c, i) in seoChecks"
                  :key="i"
                  class="pv2-seo-check"
                >
                  <span
                    class="pv2-seo-dot"
                    :style="{background: c.ok ? '#059669' : '#d97706'}"
                  />
                  <span>{{ c.l }}</span>
                </div>
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.metaFields') }}</h5>
              <div class="input-wrapper">
                <label>{{ $t('category.mTitle') }}</label>
                <input
                  type="text"
                  :placeholder="$t('category.mTitle')"
                  v-model="result.meta_title"
                  :class="{invalid: !!!result.meta_title && hasError}"
                >
                <span class="pv2-count">{{ (result.meta_title || '').length }}/60</span>
                <span
                  class="error"
                  v-if="!!!result.meta_title && hasError"
                >
                  {{ $t('category.req', { type: $t('category.mTitle')}) }}
                </span>
              </div>

              <div class="input-wrapper">
                <label>{{ $t('category.mDesc') }}</label>
                <textarea
                  :placeholder="$t('category.mDesc')"
                  v-model="result.meta_description"
                  :class="{invalid: !!!result.meta_description && hasError}"
                />
                <span class="pv2-count">{{ (result.meta_description || '').length }}/160</span>
                <span
                  class="error"
                  v-if="!!!result.meta_description && hasError"
                >
                  {{ $t('category.req', { type: $t('category.mDesc')}) }}
                </span>
              </div>

              <div class="input-wrapper">
                <label>{{ $t('ship.mk') }} ({{ $t('ship.csk') }})</label>
                <textarea
                  :placeholder="$t('ship.mk')"
                  v-model="result.meta_keywords"
                  :class="{invalid: !!!result.meta_keywords && hasError}"
                />
                <span
                  class="error"
                  v-if="!!!result.meta_keywords && hasError"
                >
                  {{ $t('category.req', { type: $t('ship.mk')}) }}
                </span>
              </div>
            </div>
          </section>

          <!-- ==================== ORGANISATION ==================== -->
          <section v-show="activeTab === 'organisation'">
            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.relationships') }}</h5>
              <div class="pv2-grid c3">
                <div class="input-wrapper pos-rel">
                <label class="block">{{ $t('pages.products.bundleDeals') }}</label>
                <div
                  class="custom-dropdown cp"
                  @click="showBundleDeals = !showBundleDeals"
                >
                  <span>
                    {{ $t('pages.products.selectBundles') }}
                    <i
                      class="icon black"
                      :class="showBundleDeals ? 'arrow-up' : 'arrow-down'"
                    />
                  </span>
                </div>
                <div
                  v-if="showBundleDeals"
                  class="multiple-wrap"
                  v-outside-click="() => showBundleDeals = false"
                >
                  <label
                    v-for="(item, key) in allBundleDeals"
                    :key="key"
                    class="bundle-item"
                  >
                    <input
                      type="checkbox"
                      :value="key"
                      v-model="result.bundle_deal_ids"
                    >
                    <span class="bundle-text">{{ item.title }}</span>
                  </label>
                </div>
              </div>


              <!--
                Assigns EXISTING cross-sell promotions, several at a time.
                Promotions are still created once under Promotions ->
                Cross-sell; this only says which of them hang off this
                product, which is the same question Bundle Deals beside it
                answers.

                On a saved product each tick writes on its own. On one being
                created there is no id to write against, so the picker holds
                the ticks and checkForm() flushes them through this ref once
                the save returns an id.
              -->
              <cross-sell-select
                ref="crossSellSelect"
                :product-id="result.id || null"
              />

              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.collections') }}</h5>
              <div class="pv2-chip-group">
                <label
                  v-for="(item, index) in allProductCollections"
                  :key="index"
                  class="pv2-chk-chip"
                  :class="{ active: result.product_collections.includes(item.id) }"
                >
                  <input
                    type="checkbox"
                    v-model="result.product_collections"
                    :value="item.id"
                  >
                  <span>{{ item.title }}</span>
                </label>
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('prod.tags') }}</h5>
              <tag-search
                @add="addTag"
                @delete="deleteTag"
                :tags="result.tags"
              />
            </div>
          </section>

          <!-- ==================== ADVANCED ==================== -->
          <section v-show="activeTab === 'advanced'">
            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('prod.pInfo') }}</h5>
              <p>
                <label class="mr-15">{{ $t('prod.refund') }}</label>
                <label>
                  <input type="radio" name="refundable" value="1" v-model="result.refundable">
                  {{ $t('prod.yes') }}
                </label>
                <label>
                  <input type="radio" name="refundable" value="2" v-model="result.refundable">
                  {{ $t('prod.no') }}
                </label>
              </p>
              <p>
                <label class="mr-15">{{ $t('prod.warranty') }}</label>
                <label>
                  <input type="radio" name="warranty" value="1" v-model="result.warranty">
                  {{ $t('prod.yes') }}
                </label>
                <label>
                  <input type="radio" name="warranty" value="2" v-model="result.warranty">
                  {{ $t('prod.no') }}
                </label>
              </p>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.purchaseNote') }}</h5>
              <p class="pv2-help">{{ $t('pages.products.purchaseNoteHelp') }}</p>
              <div class="input-wrapper">
                <textarea
                  rows="3"
                  :placeholder="$t('pages.products.purchaseNotePlaceholder')"
                  v-model="result.purchase_note"
                />
              </div>
            </div>

            <div class="pv2-card">
              <h5 class="pv2-ctitle">{{ $t('pages.products.pageTemplates') }}</h5>
              <div class="input-wrapper">
                <label for="templateDropdown">{{ $t('templates.select_template') }}</label>
                <div class="pv2-inline pv2-tpl-row">
                  <select
                    id="templateDropdown"
                    class="form-control"
                    :disabled="isLoadingTemplates"
                    v-model="selectedTemplateId"
                  >
                    <option value="">{{ $t('templates.select_default') }}</option>
                    <option
                      v-for="template in templates"
                      :key="template.id"
                      :value="template.id"
                      :disabled="productCustomizations.some(c => c.template_id == template.id)"
                    >
                      {{ template.title }}
                    </option>
                  </select>
                  <button
                    class="btn primary-btn pv2-tpl-add"
                    :disabled="!selectedTemplateId"
                    @click.prevent="addSelectedTemplate"
                    :title="$t('pages.products.addTemplate')"
                  >
                    +
                  </button>
                </div>
              </div>

              <div
                v-if="productCustomizations.length"
                class="selected-templates"
              >
                <div
                  v-for="(customization, index) in productCustomizations"
                  :key="customization.template_id || index"
                  class="template-pill"
                >
                  <span class="template-name">
                    {{ customization.template && customization.template.title }}
                  </span>
                  <span
                    class="remove-template"
                    @click.prevent="removeTemplate(index)"
                  >
                    ✕
                  </span>
                </div>
              </div>

              <button
                class="ajax-btn primary-btn template_save_button"
                :disabled="isSavingCustomizations || !productCustomizations.length"
                @click.prevent="saveTemplateCustomizations"
              >
                {{ $t('setting.sv') }}
                <span v-if="isSavingCustomizations" class="ml-2 spinner"></span>
              </button>
            </div>
          </section>

        </div>
      </div>

      <!-- ============ RIGHT SIDEBAR ============ -->
      <aside class="pv2-side">
        <div class="pv2-side-card">
          <div class="pv2-side-title">{{ $t('category.status') }}</div>
          <dropdown
            :selectedKey="`${result.status}`"
            :options="productStatusObj"
            @clicked="dropdownSelected"
          />
          <div class="pv2-side-note">{{ `${result.status}` === '1' ? $t('pages.products.liveOnStorefront') : $t('pages.products.notVisibleToCustomers') }}</div>
        </div>

        <div class="pv2-side-card">
          <div class="pv2-side-title">{{ $t('pages.products.brandCategory') }}</div>
          <div class="input-wrapper">
            <label class="block">{{ $t('prod.brand') }}</label>
            <dropdown
              v-if="allBrands"
              :default-null="true"
              :selectedKey="result.brand_id"
              :options="allBrands"
              @clicked="brandSelected"
            />
          </div>

          <div class="input-wrapper pos-rel mt-15">
            <label class="block">{{ $t('error.cat') }}</label>
            <div
              data-ignore="multiple-category"
              @click.prevent="showCategories = !showCategories"
              class="custom-dropdown cp"
            >
              <span class="ignore-click">{{ $t('title.choose') }} ({{ chosenCategoryCount }})
                <i
                  class="icon black ignore-click"
                  :class="[{'arrow-up': showCategories}, {'arrow-down': !showCategories}]"
                />
              </span>
            </div>
            <ul v-if="showCategories" class="multiple-wrap" v-outside-click="closeDrop" id="multiple-category">
              <input
                class="mb-10"
                type="text"
                :placeholder="$t('list.sh')"
                v-model="categorySearch"
                @input="doCategorySearch"
              >
              <!--
                 Each root with its subcategories under it. The options used
                 to be top-level only: `product/dropdown-data` sends every
                 root with its `child` array attached and the store threw the
                 children away, so a subcategory could not be put on a product
                 from this form at all - even though the API writes them and
                 reads them back.
              -->
              <li
                v-for="(item, index) in searchedCategories"
                :key="index"
                class="cat-group"
              >
                <label :label-for="`all-cat-${index}`" class="cat-group__parent">
                  <input
                    :id="`all-cat-${index}`"
                    type="checkbox"
                    v-model="result.product_categories"
                    :value="index"
                  >
                  {{ item.title }}
                </label>
                <label
                  v-for="sub in (item.child || [])"
                  :key="`${index}-${sub.id}`"
                  :label-for="`all-sub-${sub.id}`"
                  class="cat-group__sub"
                >
                  <input
                    :id="`all-sub-${sub.id}`"
                    type="checkbox"
                    :checked="isSubcategoryChosen(sub.id)"
                    @change="toggleSubcategory(sub, index)"
                  >
                  {{ sub.title }}
                </label>
              </li>
            </ul>
          </div>

          <div v-if="selectedCategories" class="input-wrapper mt-15">
            <label class="block">{{ $t('title.prim') }}</label>
            <dropdown
              :selectedKey="result.primary_category_id"
              :options="selectedCategories"
              @clicked="primaryCategorySelected"
            />
          </div>
        </div>

        <div class="pv2-side-card">
          <div class="pv2-side-title">{{ $t('pages.products.featuredImage') }}</div>
          <div class="pv2-thumb">
            <img
              v-if="result.image"
              :src="getImageURL(result.image)"
            >
            <i v-else class="fas fa-image" />
          </div>
          <div class="pv2-side-note">{{ $t('pages.products.manageInMediaTab') }}</div>
        </div>

        <div class="pv2-side-card">
          <div class="pv2-side-title">{{ $t('pages.products.quickInfo') }}</div>
          <!-- "Price", not "Selling": this is the offer price when one is set -->
          <div class="pv2-kv"><span class="k">{{ $t('pages.products.price') }}</span><span class="v">{{ currencyIcon }}{{ uiPrice.toFixed(2) }}</span></div>
          <div class="pv2-kv"><span class="k">{{ $t('pages.products.margin') }}</span><span class="v" :style="{color: marginColor}">{{ uiMargin.toFixed(1) }}%</span></div>
          <!--
            A "Condition" row used to sit here reading uiCondition, which was
            the hardcoded string 'New'. There is no condition on a product, so
            every product in a shop that sells refurbished machines reported
            itself as new. A stated fact that is not read from anything is
            worse than no row.
          -->
          <div class="pv2-kv"><span class="k">{{ $t('pages.products.variant') }}</span><span class="v">{{ uiVariable ? $t('pages.products.variable') : $t('pages.products.simple') }}</span></div>
          <div class="pv2-kv"><span class="k">{{ $t('pages.products.categories') }}</span><span class="v">{{ result.product_categories.length }}</span></div>
        </div>
      </aside>
    </div>
  </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import WYSIWYGEditor from '~/components/WYSIWYGEditor'
  import ProductImages from '~/components/partials/ProductImages'
  import FileUpload from '~/components/FileUpload'
  import VideoUpload from '~/components/VideoUpload'
  import TagSearch from '~/components/TagSearch'
  import AjaxButton from '~/components/AjaxButton'
  import ProductUpgrades from '~/components/partials/ProductUpgrades'
  import CrossSellSelect from '~/components/partials/CrossSellSelect'
  import ProductInventory from '~/components/partials/ProductInventory'
  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import Spinner from '~/components/Spinner'
  import util from '~/mixin/util'
  import ImageInput from "~/components/ImageInput";
  import VideoInput from "~/components/VideoInput";
  import outsideClick from '~/directives/outside-click.js'
  import {debounce} from "debounce";
  import ProductFeatureImages from '~/components/partials/ProductFeatureImages'

  export default {
    name: "product",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        specifications: [],
        showBundleDeals: false,
        licence: null,
        categorySearch: '',
        excludeVAT: 0,
        allUpsells: {},
        loadingUpsells: false,
        productFormOpen: true,
        showCategories: false,
        validLicence: false,
        routeName: 'products',
        getApi: 'getProduct',
        setApi: 'setProduct',
        setImageApi: 'setProductImage',
        setVideoApi: 'setProductVideo',
        fileKeys: ['id', 'tax_rule_id'],
        validationKeys: ['title', 'slug', 'short_heading', 'unit', 'meta_title', 'meta_description',
          'description', 'overview', 'selling', 'purchased', 'tax_rule_id'],
        // "Sell only in": off = anywhere a shipping rule delivers.
        sellRestricted: false,
        sellCountryToAdd: '',
        // ---- Tabbed UI state ----
        activeTab: 'basic',
        tabGroups: [
          {g: this.$t('pages.products.groupProduct'), items: [
            {k: 'basic', l: this.$t('pages.products.tabBasic'), ic: 'fa-info-circle'},
            {k: 'content', l: this.$t('pages.products.tabContent'), ic: 'fa-align-left'},
            {k: 'media', l: this.$t('pages.products.tabMedia'), ic: 'fa-images'}
          ]},
          {g: this.$t('pages.products.groupCommerce'), items: [
            {k: 'pricing', l: this.$t('pages.products.tabPricing'), ic: 'fa-tags'},
            {k: 'inventory', l: this.$t('pages.products.tabInventory'), ic: 'fa-boxes'},
            // How this product upgrades between its own variants. Under
            // Commerce rather than Marketing: it is part of what the product
            // IS, and it is derived from the variants on the tab above it.
            {k: 'upgrades', l: this.$t('pages.products.tabUpgrades'), ic: 'fa-arrow-up-right-dots'},
            {k: 'shipping', l: this.$t('pages.products.tabShipping'), ic: 'fa-truck'}
          ]},
          {g: this.$t('pages.products.groupMarketing'), items: [
            {k: 'seo', l: this.$t('pages.products.tabSeo'), ic: 'fa-search'},
            // Cross-sell is assigned on Organisation, beside Bundle Deals:
            // both answer "what else goes with this", and a rail entry of its
            // own put one relationship a click away from the other.
            {k: 'organisation', l: this.$t('pages.products.tabOrganisation'), ic: 'fa-sitemap'}
          ]},
          {g: this.$t('pages.products.groupSettings'), items: [
            {k: 'advanced', l: this.$t('pages.products.tabAdvanced'), ic: 'fa-cog'}
          ]}
        ],
        fieldTabMap: {
          title: 'basic', short_heading: 'basic', slug: 'basic', unit: 'basic',
          selling: 'pricing', purchased: 'pricing', tax_rule_id: 'pricing',
          sell_countries: 'shipping',
          overview: 'content', description: 'content',
          meta_title: 'seo', meta_description: 'seo'
        },
        // UI-only fields (not persisted until backend support is added)
        uiVariable: false,
        uiFocusKw: '',
        // uiPurchaseNote / uiDims removed: these inputs are bound straight to
        // result now, so what the admin types is actually saved.
        result: {
          feature_images: [],
          id: '',
          title: '',
          short_heading: '',
          tags: ',',
          overview: '',
          description: '',
          status: '2',
          brand_id: '',
          primary_category_id: '',
          category_id: '',
          bundle_deal_ids: [],
          unit: '',
          badge: '',
          subcategory_id: '',
          tax_rule_id: '',
          sell_countries: [],
          purchased: '',
          selling: '',
          offered: '',
          refundable: 1,
          warranty: 1,
          meta_description: '',
          meta_keywords: '',
          flash_sale_product: '',
          meta_title: '',
          image: '',
          slug: '',
          video: '',
          product_images: [],
          product_collections: [],
          product_categories: [],
          /*
           * [{id, category_id}] - the shape ProductsController::action reads
           * and find() returns. Carried on `result` so it posts with the rest
           * of the form without a transform step to forget.
           */
          subcategories: [],
          excludeVAT: 0,
          procurement: 0,
          back_order: 0,
          upsell_id: ''
        },
        hasError: false,
        loading: false,
        formSubmitting: false,
        redirect: false,
        fileUploading: false,
        videoUploading: false,
        searchedCategories: {},
        // Template-related data
        templates: [],
        productCustomizations: [],
        selectedTemplateId: '',
        isLoadingTemplates: false,
        templateError: null,
        isSavingCustomizations: false,
        productId: null,
      }
    },
    directives: {
      outsideClick
    },
    mixins: [util],
    components: {
      VideoInput,
      ImageInput,
      DataPage,
      Dropdown,
      WYSIWYGEditor,
      ProductImages,
      FileUpload,
      VideoUpload,
      TagSearch,
      AjaxButton,
      ProductInventory,
      ProductUpgrades,
      CrossSellSelect,
      ErrorFormatter,
      Spinner,
      ProductFeatureImages
    },
    computed: {
      productCategories() {
        return this.result.product_categories
      },
      /** Categories and subcategories together, which is what was chosen. */
      chosenCategoryCount() {
        return (this.result.product_categories || []).length
          + (this.result.subcategories || []).length
      },
      selectedCategories() {
        let sc = null;
        if(this.allCategories && this.productCategories.length) {

          Object.keys(this.allCategories).forEach(i => {
            if(this.productCategories.includes(i)) {
              sc = {...sc, ...{[i]: this.allCategories[i] }}
            }
          });
        }
        return sc;
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      currentPrice() {
        return this.result.offered > 0 ? this.result.offered : this.result.selling > 0 ? this.result.selling : 0
      },
      id(){
        return !this.isAdding ? this.$route?.params?.id : ''
      },
      isAdding(){
        return isNaN(this.$route?.params?.id)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      // ---- Tabbed UI computed ----
      uiCost() {
        return parseFloat(this.result.purchased) || 0
      },
      /*
       * The price a customer actually pays.
       *
       * This used to read result.selling, so a product on offer was measured
       * against a price it is never sold at — a €1,000 phone offered at €899
       * reported €990 profit instead of €889. currentPrice is the same
       * offer-aware figure the storefront and checkout use.
       */
      uiPrice() {
        return parseFloat(this.currentPrice) || 0
      },
      // The rate attached to the selected tax rule, as a percentage.
      uiVatRate() {
        const rule = this.allTaxRules ? this.allTaxRules[this.result.tax_rule_id] : null

        if (!rule) {
          return 0
        }

        // type 1 is a flat amount, which is not a percentage of the price.
        return parseInt(rule.type) === 1 ? 0 : (parseFloat(rule.price) || 0)
      },
      /*
       * Is the price the customer sees inclusive of VAT?
       *
       * excludeVAT marks a product as quoted *excluding* VAT, which checkout
       * then adds on top. Anything else is already VAT-inclusive, so the tax
       * has to come out before the figure counts as revenue.
       */
      uiPriceIncludesVat() {
        return `${this.result.excludeVAT}` !== '1' && this.uiVatRate > 0
      },
      // Revenue the business keeps: VAT is collected for Revenue, not earned.
      uiNetRevenue() {
        if (!this.uiPriceIncludesVat) {
          return this.uiPrice
        }

        return this.uiPrice / (1 + (this.uiVatRate / 100))
      },
      uiVatPortion() {
        return this.uiPrice - this.uiNetRevenue
      },
      uiProfit() {
        return this.uiNetRevenue - this.uiCost
      },
      uiMargin() {
        return this.uiNetRevenue > 0
          ? ((this.uiNetRevenue - this.uiCost) / this.uiNetRevenue * 100)
          : 0
      },
      uiRoi() {
        return this.uiCost > 0
          ? ((this.uiNetRevenue - this.uiCost) / this.uiCost * 100)
          : 0
      },
      marginColor() {
        const m = this.uiMargin
        return m >= 40 ? '#059669' : m >= 20 ? '#d97706' : '#dc2626'
      },
      /*
       * Status 2 is called "Private" by the shared statusObj that every admin
       * list uses, but the product editor's own primary action is "Save
       * Draft" -- so this one screen said both. Products say Draft; renaming
       * the shared key would rename status 2 for categories, brands and
       * everything else that shares it.
       */
      productStatusObj() {
        return {
          1: {title: this.$t('pages.products.statusPublic')},
          2: {title: this.$t('pages.products.statusDraft')}
        }
      },
      statusBadgeClass() {
        return `${this.result.status}` === '1' ? 'is-public' : 'is-muted'
      },
      seoChecks() {
        const mt = this.result.meta_title || ''
        const md = this.result.meta_description || ''
        const kw = (this.uiFocusKw || '').toLowerCase()
        const slug = (this.result.slug || '').toLowerCase()
        return [
          {l: this.$t('pages.products.seoFocusSet'), ok: !!kw},
          {l: this.$t('pages.products.seoKwTitle'), ok: !!kw && mt.toLowerCase().includes(kw)},
          {l: this.$t('pages.products.seoKwSlug'), ok: !!kw && slug.includes(kw.replace(/\s+/g, '-'))},
          {l: this.$t('pages.products.seoTitleLength'), ok: mt.length >= 50 && mt.length <= 60},
          {l: this.$t('pages.products.seoDescLength'), ok: md.length >= 120 && md.length <= 160},
          {l: this.$t('pages.products.seoKeywordsAdded'), ok: !!(this.result.meta_keywords || '').trim()}
        ]
      },
      seoScore() {
        const c = this.seoChecks
        return Math.round(c.filter(x => x.ok).length / c.length * 100)
      },
      // Count of missing required fields per tab (only once validation has run)
      tabErrors() {
        if (!this.hasError) return {}
        const errs = {}
        this.validationKeys.forEach(k => {
          if (!this.result[k]) {
            const tab = this.fieldTabMap[k]
            if (tab) errs[tab] = (errs[tab] || 0) + 1
          }
        })
        return errs
      },
      errorCount() {
        return Object.values(this.tabErrors).reduce((a, b) => a + b, 0)
      },
      ...mapGetters(['mediaStorage']),
      ...mapGetters('admin', ['publicKey']),
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('setting', ['setting']),
      ...mapGetters('common', ['allCategories', 'allTaxRules', 'allAttributes',
        'allBrands','allProductCollections','allBundleDeals', 'allShippingRules']),
      ...mapGetters('resource', ['countryList']),
      // The API sends "GB,IE" (or null); the form keeps an array.
      sellCountryCodes() {
        const value = this.result.sell_countries

        if (Array.isArray(value)) return value

        return value ? String(value).split(',').filter(Boolean) : []
      },
      countryOptions() {
        return Object.values(this.countryList || {})
          .filter(c => c?.code2 && !this.sellCountryCodes.includes(c.code2))
          .map(c => ({code: c.code2, name: c.name}))
          .sort((a, b) => a.name.localeCompare(b.name))
      },
    },
    watch: {
      /**
       * Unticking a category takes its subcategories with it.
       *
       * The row in product_subcategories names both the subcategory and its
       * parent, so one left behind after its parent was removed is a row
       * pointing at a category the product is no longer in.
       */
      'result.product_categories'(chosen) {
        const keep = (chosen || []).map(String)

        const pruned = (this.result.subcategories || [])
          .filter(s => keep.includes(String(s.category_id)))

        if (pruned.length !== (this.result.subcategories || []).length) {
          this.result.subcategories = pruned
        }
      }
    },
    methods: {
      setTab(k) {
        this.activeTab = k
      },
      setVariable() {
        this.uiVariable = true
        this.activeTab = 'inventory'
      },
      goBack() {
        this.$router.push({path: `/${this.routeName}`})
      },
      statusLabel(s) {
        const option = this.productStatusObj[`${s}`] || this.productStatusObj[s]
        return option ? option.title : this.$t('pages.products.statusDraft')
      },
      featureImagesResult(data) {
        this.result.feature_images = data
      },
      removeGroup(groupIndex) {
        this.specifications.splice(groupIndex, 1)
      },
      addGroup() {
        this.specifications.push({
          group: '',
          items: [
            {
              value: ''
            }
          ]
        })
      },
      addItem(groupIndex) {
        this.specifications[groupIndex]
          .items
          .push({
            value: ''
          })
      },
      removeItem(groupIndex, itemIndex) {
        this.specifications[groupIndex]
          .items
          .splice(itemIndex, 1)
      },
      /*
       * The two buttons used to differ only in where they navigated
       * afterwards: "Save Draft" saved and stayed, "Publish" saved and went
       * back to the list, and neither touched the product's status. Clicking
       * Publish on a private product left it private, and Save Draft on a
       * live one left it live.
       *
       * Save Draft now means draft. Publish, on a product being added, means
       * public. Update, on an existing product, leaves the status alone --
       * that is what the Status dropdown in the sidebar is for, and a button
       * called Update should not quietly overrule it.
       */
      async submitAll(type) {
        this.redirectingEnable(type)

        if (type === 'save-edit') {
          this.result.status = '2'
        } else if (this.isAdding) {
          this.result.status = '1'
        }

        await this.checkForm()
      },
      refreshProductMedia(payload = {}) {

        this.result = {
          ...this.result,
          ...payload
        }

        this.$nextTick(() => {
          this.$forceUpdate()
        })
      },
      updateProductState(data) {
        this.result = {
          ...this.result,
          ...data
        }

        this.result.product_collections = [
          ...new Set(
            (this.result?.product_collections || []).map(
              o => o.product_collection_id || o
            )
          )
        ]

        this.result.product_categories = [
          ...new Set(
            (this.result?.product_categories || []).map(
              o => o.category_id?.toString() || o
            )
          )
        ]
      },

      upsellSelected(data) {
        this.result.upsell_id = data.key
      },
      async loadUpsells() {
        this.loadingUpsells = true
        try {
          const baseUrl = this.apiBase();
          const response = await this.$axios.get(`${baseUrl}api/upsells/active`);

          this.allUpsells = response.data
        } catch (error) {
          console.error('Error loading upsells:', error)
        } finally {
          this.loadingUpsells = false
        }
      },
      doCategorySearch: debounce(function () {
          this.searchOnCategory()
        }, 500),
      /**
       * Search matches subcategories as well as roots.
       *
       * A root whose own name matches keeps all of its children; a root that
       * does not match is still listed when one of its children does, with
       * only the matching children under it - otherwise searching for "Apple"
       * returned nothing, because Apple is a subcategory.
       */
      searchOnCategory() {
        const term = (this.categorySearch || '').toLowerCase().trim()

        if (!term) {
          this.searchedCategories = this.allCategories
          return
        }

        const matches = {}

        Object.keys(this.allCategories).forEach(id => {
          const category = this.allCategories[id]
          const children = category.child || []
          const parentHit = (category.title || '').toLowerCase().includes(term)

          if (parentHit) {
            matches[id] = category
            return
          }

          const hitChildren = children.filter(
            c => (c.title || '').toLowerCase().includes(term))

          if (hitChildren.length) {
            matches[id] = {...category, child: hitChildren}
          }
        })

        this.searchedCategories = matches
      },
      /** Is this subcategory on the product? */
      isSubcategoryChosen(id) {
        return (this.result.subcategories || []).some(s => String(s.id) === String(id))
      },
      /**
       * Tick or untick one subcategory.
       *
       * Ticking one also ticks its parent: the row carries both ids, and a
       * product filed under "Apple" that is not also under "Mobile" would be
       * missing from the parent category on the storefront.
       */
      toggleSubcategory(sub, parentId) {
        const chosen = (this.result.subcategories || []).slice()
        const at = chosen.findIndex(s => String(s.id) === String(sub.id))

        if (at > -1) {
          chosen.splice(at, 1)
        } else {
          chosen.push({id: String(sub.id), category_id: String(parentId)})

          if (!this.result.product_categories.includes(String(parentId))) {
            this.result.product_categories.push(String(parentId))
          }
        }

        this.result.subcategories = chosen
      },
      closeDrop(){
        this.showCategories = false
      },
      productImages(evt){
        this.result.product_images = []

        this.$nextTick(()=>{
          this.result.product_images = evt
        })
      },
      imageInputChanged(file) {
        this.uploadFile(file)
      },
      addTag(tag){
        if (!this.result.tags){
          this.result.tags = ','
        }
        this.result.tags = `${this.result.tags}${tag},`
      },
      deleteTag(tag){
        this.result.tags = this.result.tags.replace(`${tag},`, '')
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      primaryCategorySelected(data) {
        this.result.primary_category_id = data.key
      },
      categorySelected(data) {
        this.result.category_id = data.key

        if (this.selectedSubCategories) {
          this.result.subcategory_id = Object.keys(this.selectedSubCategories)[0]
        } else {
          this.result.subcategory_id = ''
        }
      },
      taxRuleSelected(data) {
        this.result.tax_rule_id = data.key
      },
      countryName(code) {
        return this.countryList?.[code]?.name || code
      },
      setSellRestricted(on) {
        this.sellRestricted = on

        if (!on) {
          this.result.sell_countries = []
        }
      },
      addSellCountry() {
        const code = this.sellCountryToAdd

        if (code && !this.sellCountryCodes.includes(code)) {
          this.result.sell_countries = [...this.sellCountryCodes, code]
        }

        this.sellCountryToAdd = ''
      },
      removeSellCountry(code) {
        this.result.sell_countries = this.sellCountryCodes.filter(c => c !== code)
      },
      brandSelected(data) {
        this.result.brand_id = data.key
      },
      redirectingEnable(buttonType) {
        this.redirect = buttonType === 'save'
      },
      async checkForm() {

        const invalidKey = this.validationKeys.find((i) => !this.result[i])
        if(invalidKey){
          this.hasError = true
          if(this.fieldTabMap[invalidKey]) {
            this.activeTab = this.fieldTabMap[invalidKey]
          }
          this.$nextTick(() => this.scrollToTop('productForm'))
          return false
        }
        this.formSubmitting = true
        try {

          delete this.result.created_at
          delete this.result.updated_at
          this.result.bundle_deal_id = (this.result.bundle_deal_ids || []).join(',');
          delete this.result.bundle_deal_ids;
          this.result.specifications = this.specifications;
          this.result.sell_countries = this.sellRestricted ? this.sellCountryCodes : [];
          const inventoryComponent = this.$refs.inventoryComponent
          const inventoryValid = await inventoryComponent.validateInventoryBeforeSubmit()
          if (!inventoryValid) {
            this.formSubmitting = false
            this.activeTab = 'inventory'
            this.scrollToTop('productInventory')
            return
          }
          const data = await this.setById({id: this.id, params: this.result, api: this.setApi})

          if (data) {

            // this.result = Object.assign({}, data)
            this.updateProductState(data)
            await this.$nextTick()


            // Set product ID for template customizations
            this.productId = this.result.id;

            const inventorySaved =
              await this.$refs.inventoryComponent.submitInventory()

            if (!inventorySaved) {
              this.formSubmitting = false
              this.activeTab = 'inventory'
              this.scrollToTop('productInventory')
              return
            }


            /*
             * Cross-sells ticked before the product existed. Awaited, and
             * before the redirect below: the picker writes one row per
             * assignment and the route change would cut it off partway.
             *
             * A refused assignment is reported by the picker and does not
             * fail the save -- the product itself is written by this point.
             */
            if (this.$refs.crossSellSelect) {
              await this.$refs.crossSellSelect.flushPending()
            }

            if (this.productCustomizations.length) {
              await this.saveTemplateCustomizations()
            }

            this.$router.push({path: `/${this.routeName}${this.redirect ? '' : '/' + this.result.id}`})
          }else {
            this.scrollToTop()
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },

      scrollToTop(ref = "productForm"){
        this.$refs[ref] && this.$refs[ref].scrollIntoView({ behavior: "smooth" })
      },
      async fetchingData() {
        try {
          this.loading = true
          this.result = Object.assign({}, await this.getById({id: this.id, params: {}, api: this.getApi}))

          this.result.product_collections = [...new Set(this.result?.product_collections?.map((o) => { return o.product_collection_id }))]

          /*
           * find() hangs this product's subcategories under each category as
           * `child`, and the line below flattens product_categories to bare
           * ids - so read them out first or the selection is lost on every
           * edit and the next save wipes the rows.
           */
          this.result.subcategories = (this.result?.product_categories || []).reduce((all, pc) => {
            (pc?.category?.child || []).forEach(c => {
              all.push({id: String(c.id), category_id: String(pc.category_id)})
            })
            return all
          }, [])

          this.result.product_categories = [...new Set(this.result?.product_categories?.map((o) => { return o.category_id.toString() }))]
          this.result.bundle_deal_ids = this.result.bundle_deal_id ? this.result.bundle_deal_id.split(',').map(String) : [];
          this.excludeVAT = this.result.excludeVAT === 1;
          this.result.sell_countries = this.sellCountryCodes;
          this.sellRestricted = this.result.sell_countries.length > 0;

          // Set product ID and load customizations
          this.productId = this.result.id;
          this.specifications =
            this.result.specifications?.length
              ? this.result.specifications
              : [
                  {
                    group: '',
                    items: [
                      {
                        value: ''
                      }
                    ]
                  }
                ]
          await this.loadProductCustomizations(this.productId);

          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async uploadVideo(file, video = null){
        this.videoUploading = true
        try {
          let params = {}
          if(file) {
            const fd = new FormData()

            this.fileKeys.forEach(i => {
              fd.append(i, this.result[i])
            })
            fd.append('video_file', file.video)
            fd.append('thumb', file.thumb)

            params = fd
          } else {
            this.fileKeys.forEach(i => {
              params[i] = this.result[i]
            })

            params['video_file'] = video.video
            params['thumb'] = video.thumb
          }
          const data = await this.setImageById({id: this.id, params: params, api: this.setVideoApi})

          if (data) {
            // this.result = Object.assign({}, data)
            this.refreshProductMedia({
              video: data.video,
              video_thumb: data.video_thumb
            })
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.videoUploading = false
      },
      async uploadFile(file, name = null){
        this.fileUploading = true
        try {

          const fd = new FormData()

          this.fileKeys.forEach(i => {
            fd.append(i, this.result[i])
          })

          fd.append('photo', file)

          const data = await this.setImageById({id: this.id, params: fd, api: this.setImageApi})

          if (data) {
            this.refreshProductMedia({
              image: data.image,
              product_images:
                data.product_images || this.result.product_images
            })
          }
        }catch (e) {
          return this.$nuxt.error(e)
        }
        this.fileUploading = false
      },
      editorDescriptionFile({deleted, file, Editor, cursorLocation, resetUploader}){
        this.editorFile({deleted, file, Editor, cursorLocation, resetUploader}, this.wysiwygType.PRODUCT_DESCRIPTION)
      },
      editorOverviewFile({deleted, file, Editor, cursorLocation, resetUploader}){
        this.editorFile({deleted, file, Editor, cursorLocation, resetUploader}, this.wysiwygType.PRODUCT_OVERVIEW)
      },
      async editorFile({deleted, file, Editor, cursorLocation, resetUploader}, type){
        if(!deleted){
          this.loading = true
          try {
            const fd = new FormData()
            if (!this.result.id) {
              fd.append('product', JSON.stringify(this.result))
            } else {
              fd.append('overview', this.result.overview)
              fd.append('description', this.result.description)
              fd.append('item_id', this.result.id)
            }
            fd.append('type', type)
            fd.append('photo', file)

            const data = await this.setWysiwygImage(fd)
            if(data){
              if (!this.result.id) {
                // await this.$router.push({path: `/${this.routeName}/${data.item_id}`})
                this.result.id = data.item_id
              } else {
                Editor.insertEmbed(cursorLocation, "image", data.url);
                resetUploader();
              }
            }
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false

        } else {
          this.loading = true
          try {
            await this.deleteData({params: this.getImageName(file), api: 'deleteWysiwygImage'})
          }catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
        }
      },

      // Template-related methods
      async loadTemplates() {
        this.isLoadingTemplates = true;
        this.templateError = null;
        try {
          const baseUrl = this.apiBase();
          const response = await this.$axios.get(`${baseUrl}api/templates/all`);

          this.templates = response.data;
        } catch (error) {
          this.templateError = this.$t('pages.products.failedLoadTemplates');
          console.error('Error loading templates:', error);
        } finally {
          this.isLoadingTemplates = false;
        }
      },

      async loadProductCustomizations(productId) {
        if (!productId) return;

        try {
         const baseUrl = this.apiBase();

          const response = await this.$axios.get(
            `${baseUrl}api/products/${productId}/customizations`
          );
          this.productCustomizations = response.data || [];
        } catch (error) {
          console.error('Error loading customizations:', error);
          this.productCustomizations = [];
        }
      },

      addSelectedTemplate() {
        if (!this.selectedTemplateId) return;

        const selectedTemplate = this.templates.find(
          tpl => tpl.id == this.selectedTemplateId
        );

        if (!selectedTemplate) return;

        // Prevent duplicates (extra safety)
        if (
          this.productCustomizations.some(
            c => c.template_id == selectedTemplate.id
          )
        ) {
          return;
        }

        this.productCustomizations.push({
          template_id: selectedTemplate.id,
          custom_content: selectedTemplate.content,
          template: {
            id: selectedTemplate.id,
            title: selectedTemplate.title
          }
        });

        // Reset dropdown after adding
        this.selectedTemplateId = '';
      },

      updateTemplateContent(index, content) {
        // This ensures reactivity
        this.$set(this.productCustomizations, index, {
          ...this.productCustomizations[index],
          custom_content: content
        });
      },

      removeTemplate(index) {
        this.productCustomizations.splice(index, 1);
      },

      async saveTemplateCustomizations() {
        if (!this.productId) {
          console.error('Product ID is required');
          return;
        }

        try {
          this.isSavingCustomizations = true;
          const baseUrl = this.apiBase();

          const response = await this.$axios.post(
            `${baseUrl}api/products/${this.productId}/customizations/bulk`,
            {
              customizations: this.productCustomizations.map(c => ({
                template_id: c.template_id,
                custom_content: c.custom_content
              }))
            }
          );

          // Update with saved data (including any server-generated fields)
          this.productCustomizations = response.data;

        } catch (error) {
          console.error('Error saving customizations:', error);
        } finally {
          this.isSavingCustomizations = false;
        }
      },

      ...mapActions('common', ['getById', 'setById', 'setImageById', 'getDropdownList', 'setWysiwygImage', 'deleteData']),
      ...mapActions('resource', ['setCountryList']),
      // Names for "Sell only in"; the same list the Shipping Rules page loads.
      async loadCountryList() {
        if (this.countryList) return

        try {
          this.setCountryList(await this.getById({params: null, id: 'countries', api: 'resource'}))
        } catch (e) {
          // The chips fall back to the codes; nothing else depends on it.
        }
      }
    },
    async mounted() {
      const domain = window.location.hostname

      if(domain.includes('admin.ishop.cholobangla.com') || domain.includes('localhost') ||
        domain.includes("127.0.0.1")){
        this.validLicence = true

      } else if(this.publicKey) {
        this.licence = this.phpDecryption(this.publicKey)

        const licenceObj = JSON.parse(this.licence)
        const validLicenceItem = !licenceObj?.p || (licenceObj?.p && licenceObj?.p === 'ishop')

        if(this.licence.includes(domain) && validLicenceItem) {
          this.validLicence = true
        }
      }

      // Deep link from the Products grid: ?tab=seo opens on SEO & Meta.
      const requestedTab = this.$route.query.tab

      if (requestedTab && this.tabGroups.some(g => g.items.some(t => t.k === requestedTab))) {
        this.activeTab = requestedTab
      }

      this.loadCountryList()

      await this.loadUpsells();

      // Load templates
      this.loadTemplates();

      if(!this.isAdding){
        await this.fetchingData()
      }
      if(!this.allCategories || !this.allTaxRules|| !this.allAttributes ||
        !this.allBrands|| !this.allProductCollections|| !this.allBundleDeals|| !this.allShippingRules){

        this.loading = true
        try {
          await this.getDropdownList()
        }catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      }

      this.searchedCategories = this.allCategories
    }
  }
</script>

<style scoped>
/* ---- category picker: a root and its subcategories ------------------

   A group is an <li>, so it is not one of the `.multiple-wrap > label`
   options multiple-dropdown.styl lays out in two columns; it takes the
   full width and each child sits directly under its own parent.

   Every selector below carries three classes on purpose. The rule further
   down this file,

     .pv2-side-card .multiple-wrap label { padding: 6px 4px }

   is (0,3,1) - the bare `label` in it outranks a two-class selector - so a
   two-class indent is silently flattened back to 4px. That is exactly what
   happened the first time round: the subcategories were listed, but level
   with their parents, so the whole thing still read as one flat list.
   Measured in the running panel, not counted. */

.multiple-wrap .cat-group {
  width: 100%;
  list-style: none;
  margin: 0;
  padding: 0;
}

.multiple-wrap .cat-group .cat-group__parent {
  font-weight: 600;
  color: #111827;
}

.multiple-wrap .cat-group .cat-group__sub {
  position: relative;
  padding-left: 30px;
  color: #4B5563;
}

/* the tree: a rail down the group, and an elbow into each child */

.multiple-wrap .cat-group .cat-group__sub::before {
  content: '';
  position: absolute;
  left: 11px;
  top: 0;
  bottom: 0;
  width: 1px;
  background: #E5E7EB;
}

/* the rail stops at the last child rather than running past it */
.multiple-wrap .cat-group .cat-group__sub:last-child::before { bottom: 50%; }

.multiple-wrap .cat-group .cat-group__sub::after {
  content: '';
  position: absolute;
  left: 11px;
  top: 50%;
  width: 9px;
  height: 1px;
  background: #E5E7EB;
}

/* ---- Checkbox / template / spec styles (preserved) ---- */
.checkbox-container {
  display: flex;
  align-items: center;
  cursor: pointer;
}

.checkbox-input {
  margin-right: 8px;
}

.checkbox-label {
  font-weight: normal;
}

.template-section {
  border: 1px solid #ddd;
  border-radius: 5px;
  padding: 15px;
  margin-bottom: 20px;
}

.template_save_button {
  margin-bottom: 20px;
}

.spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid #f3f3f3;
  border-top: 2px solid #3498db;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

.selected-templates {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 10px 0 20px;
}

.template-pill {
  display: flex;
  align-items: center;
  background: #f3f4f6;
  padding: 6px 14px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 500;
}

.template-name {
  margin-right: 8px;
}

.remove-template {
  cursor: pointer;
  font-weight: bold;
  color: #ef4444;
}

.remove-template:hover {
  opacity: 0.7;
}

.input-wrapper select{
  height:40px;
  width:100%;
  padding:0 12px;
  border:1px solid var(--border-strong, #D1D5DB);
  border-radius:8px;
  background:#fff;
  font-size:14px;
  cursor:pointer;
  transition:all .2s ease;
}

/* invisible label to vertically align checkboxes with adjacent dropdowns */
.pv2-spacer-lbl{ display:block; }

/* A note that stands in for a field, in the same column rhythm. */
.pv2-field-note{ margin:0; font-size:12.5px; line-height:1.5; color:#64748b; }
.pv2-span-2{ grid-column:span 2; }
@media only screen and (max-width:900px){ .pv2-span-2{ grid-column:span 1; } }
@media only screen and (max-width:1200px){ .pv2-spacer-lbl{ display:none; } }

/* "Where this product can be sold" */
.pv2-sell-countries{ margin-top:14px; display:flex; flex-direction:column; align-items:flex-start; gap:10px; }
.pv2-sell-add{ width:280px; max-width:100%; margin:0; }
.pv2-chip-remove{
  display:inline-flex; align-items:center; justify-content:center;
  width:18px; height:18px; line-height:18px; margin-left:2px;
  border-radius:50%; font-size:15px; color:#33319a; text-decoration:none;
}
.pv2-chip-remove:hover, .pv2-chip-remove:focus{ background:rgba(51,49,154,.14); }
.pv2-sell-warn{ color:#b45309; margin:0; }

/* checkbox chips (collections) */
.pv2-chip-group{ display:flex; flex-wrap:wrap; gap:8px; }
.pv2-chk-chip{
  display:inline-flex; align-items:center; gap:7px;
  padding:8px 14px; border:1px solid #e2e8f0; border-radius:8px;
  cursor:pointer; font-size:13px; color:#475569; transition:all .15s; user-select:none;
  margin:0;
}
.pv2-chk-chip input{ accent-color:#33319a; width:15px; height:15px; margin:0; }
.pv2-chk-chip:hover{ border-color:#c7cdd6; }
.pv2-chk-chip.active{ border-color:#33319a; background:rgba(51,49,154,.06); color:#33319a; font-weight:600; }

/* inline field: amount + unit select on one line */
.pv2-inline{ display:flex; gap:6px; }
.pv2-inline > input{ flex:1; min-width:0; }
.pv2-inline > .pv2-inline-sel{ width:auto; flex:0 0 auto; }

/* page-templates: select + add button on one row */
.pv2-tpl-row{ align-items:center; gap:8px; }
.pv2-tpl-row > select{ flex:1; min-width:0; margin:0; }
.pv2-tpl-add{ flex:0 0 auto; width:40px; height:40px; padding:0; font-size:18px; line-height:1; }

.bundle-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  cursor: pointer;
  width: 100%;
}

.bundle-item input {
  margin-top: 3px;
}

.bundle-text {
  display: block;
  white-space: normal;
  word-break: normal;
  overflow-wrap: break-word;
  width: 100%;
}

.spec-group{
  border:1px solid #e5e7eb;
  border-radius:12px;
  padding:20px;
  margin-bottom:20px;
  background:#fff;
}

.spec-header{
  display:flex;
  align-items:end;
  gap:12px;
  margin-bottom:15px;
}

.category-input{
  flex:1;
}

.category-input label{
  display:block;
  margin-bottom:6px;
  font-size:13px;
  font-weight:600;
  color:#6b7280;
}

.category-input input{
  width:100%;
  height:42px;
}

.spec-row{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:10px;
}

.specification-input{
  flex:1;
}

.specification-input input{
  width:100%;
  height:40px;
  border-radius:8px;
}

.icon-btn{
  width:38px;
  height:38px;
  border:none;
  border-radius:8px;
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  transition:0.2s ease;
}

.icon-btn i{
  font-size:13px;
}

.icon-btn.danger{
  background:#fee2e2;
  color:#dc2626;
}

.icon-btn.danger:hover{
  background:#fecaca;
}

.add-link{
  background:none;
  border:none;
  padding:0;
  margin-top:8px;
  color:#33319a;
  font-size:14px;
  font-weight:600;
  display:inline-flex;
  align-items:center;
  gap:6px;
  cursor:pointer;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

/* ======================================================
   Tabbed product editor (pv2) layout
   ====================================================== */
.product-form.pv2{ display:block; }

.pv2-topbar{
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap;
  background:#fff; border:1px solid #eee; border-radius:12px;
  padding:12px 16px; margin-bottom:16px;
  box-shadow:0 1px 2px rgba(0,0,0,.06);
  position:sticky; top:0; z-index:20;
}
/* keep in-card dropdown controls rounded to match inputs */
.pv2-form .custom-dropdown,
.pv2-form .custom-dropdown > span,
.pv2-side-card .custom-dropdown,
.pv2-side-card .custom-dropdown > span{ border-radius:8px; }
.pv2-topbar-left{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.pv2-topbar-right{ display:flex; align-items:center; gap:8px; }
.pv2-back{ padding:0px 12px; }
.pv2-title{ margin:0; font-size:17px; font-weight:700; }
.pv2-divider{ width:1px; height:22px; background:#e5e7eb; }
.pv2-badge{ padding:3px 10px; border-radius:6px; font-size:11px; font-weight:600; text-transform:capitalize; }
.pv2-badge.is-public{ background:rgba(5,150,105,.1); color:#059669; }
.pv2-badge.is-muted{ background:rgba(217,119,6,.1); color:#d97706; }
.pv2-badge.is-type{ background:#f1f5f9; color:#475569; }

.pv2-body{ display:flex; gap:16px; align-items:flex-start; }

.pv2-rail{
  width:190px; flex-shrink:0;
  background:#fff; border:1px solid #eee; border-radius:10px;
  padding:8px 6px; position:sticky; top:76px;
}
.pv2-rail-group{ margin-bottom:6px; }
.pv2-rail-gtitle{
  font-size:10px; font-weight:700; letter-spacing:.08em; color:#94a3b8;
  padding:12px 12px 5px; text-transform:uppercase;
}
.pv2-rail-item{
  display:flex; align-items:center; gap:10px; width:100%;
  padding:8px 12px; border:none; background:transparent; border-radius:8px;
  cursor:pointer; font-size:13px; font-weight:500; color:#475569;
  text-align:left; font-family:inherit;
}
.pv2-rail-item:hover{ background:#f1f5f9; }
.pv2-rail-item.active{ background:rgba(51,49,154,.08); color:#33319a; font-weight:600; }
.pv2-rail-ic{ width:18px; text-align:center; font-size:13px; }
.pv2-rail-item.has-err{ color:#dc2626; }
.pv2-rail-item.has-err.active{ background:rgba(220,38,38,.08); color:#dc2626; }
.pv2-rail-badge{
  margin-left:auto; flex-shrink:0;
  min-width:18px; height:18px; padding:0 5px;
  border-radius:9px; background:#dc2626; color:#fff;
  font-size:10.5px; font-weight:700; line-height:18px; text-align:center;
}

/* client-side validation summary banner */
.pv2-err-banner{
  display:flex; align-items:center; gap:8px;
  padding:11px 14px; margin-bottom:14px;
  border-radius:10px;
  background:#fef2f2; border:1px solid #fecaca; color:#b91c1c;
  font-size:13px;
}
.pv2-err-banner i{ font-size:15px; flex-shrink:0; }

/* field-level errors */
.pv2-form .error{
  display:inline-flex; align-items:center; gap:5px;
  margin-top:6px; font-size:12px; font-weight:500; color:#dc2626;
}
.pv2-form .error::before{ content:'\26A0'; font-size:12px; line-height:1; }
.pv2-form .invalid,
.pv2-form input.invalid,
.pv2-form textarea.invalid{
  border-color:#dc2626 !important;
  background:#fef2f2 !important;
}
.pv2-form .whysigwyg-error{
  border:1px solid #dc2626;
  border-radius:8px;
  padding:2px;
}

.pv2-content{ flex:1; min-width:0; }
.pv2-side{ width:270px; flex-shrink:0; position:sticky; top:76px; display:flex; flex-direction:column; gap:14px; }

.pv2-card{
  background:#fff; border:1px solid #eee; border-radius:12px;
  padding:20px; margin-bottom:16px;
}
.pv2-ctitle{ font-size:15px; font-weight:700; margin:0 0 16px; }
.pv2-ctitle .mt{ color:#94a3b8; font-weight:400; }
.pv2-help{ font-size:13px; color:#94a3b8; margin:-6px 0 14px; }

/* ---- Technical Specifications (single-column) ---- */
.pv2-spec-box{ border:1px solid #e8ecf0; border-radius:12px; padding:16px; margin-bottom:12px; }
.pv2-spec-cat{ margin-bottom:14px; }
.pv2-spec-label{ font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:6px; }
.pv2-spec-row{ display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.pv2-spec-input{
  flex:1; width:100%;
  background:#f1f5f9; border:1px solid #e8ecf0; border-radius:8px;
  padding:10px 14px; font-size:13px; color:#0f172a; outline:none;
  transition:border-color .15s, background .15s;
}
.pv2-spec-input:focus{ border-color:#33319a; background:#fff; }
.pv2-spec-del{
  width:38px; height:38px; flex-shrink:0;
  border:none; border-radius:8px; cursor:pointer;
  background:#fef2f2; color:#dc2626; font-size:13px;
  display:flex; align-items:center; justify-content:center;
  transition:background .15s;
}
.pv2-spec-del:hover{ background:#fee2e2; }
.pv2-spec-add{
  margin-top:4px; padding:0px 14px;
  background:#f1f5f9; border:1px solid #e8ecf0; border-radius:8px;
  color:#475569; font-size:12.5px; font-weight:600; cursor:pointer;
}
.pv2-spec-add:hover{ background:#e8ecf0; }
.pv2-spec-addcat{ margin-top:6px; }
.pv2-note{ display:flex; align-items:center; color:#475569; font-size:13px; background:#f8fafc; }

.pv2-grid{ display:grid; gap:14px; align-items:start; }
.pv2-grid.c2{ grid-template-columns:repeat(2,1fr); }
.pv2-grid.c3{ grid-template-columns:repeat(3,1fr); }
.pv2-grid.c4{ grid-template-columns:repeat(4,1fr); }

.pv2-type-grid{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.pv2-type-grid.two{ grid-template-columns:repeat(2,1fr); }
.pv2-type{
  border:2px solid #e5e7eb; border-radius:12px; padding:16px;
  text-align:center; cursor:pointer; transition:.15s;
}
.pv2-type:hover{ border-color:#c7cdd6; }
.pv2-type.sel{ border-color:#33319a; background:rgba(51,49,154,.06); }
.pv2-type i{ font-size:22px; color:#33319a; }
.pv2-type-l{ font-weight:700; font-size:13px; margin-top:6px; }
.pv2-type-d{ font-size:11px; color:#94a3b8; margin-top:3px; }
.pv2-type-vat{ font-size:11px; font-weight:600; color:#33319a; margin-top:5px; }

.pv2-profit{ background:linear-gradient(135deg,rgba(5,150,105,.05),rgba(51,49,154,.05)); border:1px dashed #e5e7eb; }
/* auto-fit so the optional Ex-VAT tile does not squash the row */
.pv2-profit-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(96px,1fr)); text-align:center; gap:8px; }
.pv2-profit-l{ font-size:11px; text-transform:uppercase; color:#94a3b8; margin-bottom:6px; }
.pv2-profit-v{ font-size:20px; font-weight:800; }
.pv2-profit-note{ display:inline-block; font-size:9px; letter-spacing:.04em; color:#64748b;
  background:#e2e8f0; border-radius:3px; padding:1px 4px; margin-left:3px; vertical-align:1px; }
.pv2-profit-hint{ margin:12px 0 0; font-size:12px; line-height:1.5; color:#64748b; text-align:center; }

.pv2-seo-row{ margin-bottom:0; }
.pv2-seo-check{ display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
.pv2-seo-dot{ width:10px; height:10px; border-radius:5px; flex-shrink:0; }
.pv2-google{ padding:16px; border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; }
.pv2-google-t{ color:#1a0dab; font-size:17px; font-weight:600; line-height:1.3; }
.pv2-google-u{ color:#006621; font-size:13px; margin:4px 0; }
.pv2-google-d{ font-size:13px; color:#545454; line-height:1.5; }
.pv2-count{ display:block; font-size:11px; color:#94a3b8; margin-top:4px; }

.pv2-custom-row{ display:grid; grid-template-columns:1fr 1fr 160px 40px; gap:10px; align-items:end; margin-bottom:12px; }
.pv2-custom-del{ margin-bottom:2px; }

.pv2-side-card{ background:#fff; border:1px solid #eee; border-radius:12px; padding:16px; }
.pv2-side-title{ font-size:13px; font-weight:700; margin:0 0 12px; }
.pv2-side-note{ font-size:11px; color:#94a3b8; margin-top:8px; }
/* full-width, consistent dropdowns inside the sidebar cards */
.pv2-side-card .input-wrapper{ margin-bottom:0; }
.pv2-side-card .input-wrapper label{ margin-bottom:5px; }
.pv2-side-card .custom-dropdown{ width:100%; }
.pv2-side-card .custom-dropdown > span{
  display:flex; align-items:center; justify-content:space-between;
  width:100%; box-sizing:border-box;
}
/* keep the category multiselect popover within the narrow sidebar */
.pv2-side-card .multiple-wrap{
  width:100%; left:0; right:auto; box-sizing:border-box;
  max-height:240px; overflow-y:auto;
}
/* one category per row: checkbox inline with its label */
.pv2-side-card .multiple-wrap label{
  display:flex; align-items:center; gap:8px;
  width:100%; margin:0; padding:6px 4px;
  font-size:13px; font-weight:400; cursor:pointer; white-space:normal;
}
.pv2-side-card .multiple-wrap label input{
  width:15px; height:15px; margin:0; flex-shrink:0; accent-color:#33319a;
}
.pv2-side-card .multiple-wrap > input{ width:100%; margin-bottom:8px; }
.pv2-kv{ display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
.pv2-kv:last-child{ border-bottom:none; }
.pv2-kv .k{ color:#94a3b8; }
.pv2-kv .v{ font-weight:600; text-transform:capitalize; }
.pv2-thumb{
  aspect-ratio:1; border-radius:10px; background:#f8fafc; border:2px dashed #e5e7eb;
  display:flex; align-items:center; justify-content:center; overflow:hidden;
  color:#cbd5e1; font-size:30px;
}
.pv2-thumb img{ width:100%; height:100%; object-fit:cover; }

@media only screen and (max-width:1200px){
  .pv2-body{ flex-direction:column; }
  .pv2-rail{ width:100%; position:static; display:flex; flex-wrap:wrap; gap:4px; }
  .pv2-rail-group{ display:flex; flex-wrap:wrap; align-items:center; gap:4px; margin:0; }
  .pv2-rail-gtitle{ width:100%; padding:6px 8px 2px; }
  .pv2-rail-item{ width:auto; }
  .pv2-side{ width:100%; position:static; }
  .pv2-grid.c3,.pv2-grid.c4,.pv2-type-grid,.pv2-seo-row{ grid-template-columns:repeat(2,1fr); }
  .pv2-profit-grid{ grid-template-columns:repeat(3,1fr); }
  .pv2-custom-row{ grid-template-columns:1fr 1fr; }
}

@media only screen and (max-width:576px){
  .pv2-grid.c2,.pv2-grid.c3,.pv2-grid.c4,.pv2-type-grid,.pv2-seo-row,.pv2-profit-grid,.pv2-custom-row{ grid-template-columns:1fr; }
  .pv2-topbar{ position:static; }
}
</style>
