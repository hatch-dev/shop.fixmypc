<template>
  <div>

    <sticky-cart-btn
      :product-inventory="selectedInventory"
      :disabled="!statusPublic"
      :product="product"
      @cart-error="hasCartError"
    />
    <div v-if="product">

      <a
        v-if="product.store.whatsapp_btn"
        class="whatsapp-btn-wrap"
        target="_blank"
        :href="`https://wa.me/${product.store.whatsapp_number}?text=${product.store.whatsapp_default_msg}`"
      >
        <i
          class="icon whatsapp-icon"
        />
      </a>


      <div
        class="detail-menu hide-sm"
        v-if="currentCategories && currentCategories.length"
      >
        <div class=" container-fluid">
          <div class="mlr--15">
            <nuxt-link
              v-for="(value, i) in currentCategories"
              :title="value.title"
              :to="categoryLink(value, category)"
              :key="i"
            >
              {{ value.title }}
            </nuxt-link>
          </div>
        </div>
      </div>
      <div class="container-fluid mtb-15 mt-sm-10 mn-h-400x">
        <div>
          <breadcrumb
            class="mb-20 mb-sm-15"
            :slugs="preparedSlug"
            :page="productTitle"
          />

          <div v-if="showCartSuccess" class="cart-success-bar mb-3">
            <div class="success-left">
              <span class="check-icon">✔</span>
              <span>
                "{{ lastAddedProduct }}" has been added to your cart.
              </span>
            </div>

            <button class="view-cart-btn" @click="handleViewCart">
              View Cart
            </button>
          </div>

          <div class="product-detail">
            <div class="detail-left pr-30 pr-sm-0">
              <div class="flex start align-start block-md">
                <div class="product-main">
                  <div class="detail-image-wrapper">
                    <div
                      class="detail-image-inner"
                      :class="{'z-2': imagePopup}"
                    >
                      <product-images
                        v-if="productImage || productImageList"
                        ref="productImagesRef"
                        :title="productTitle"
                        :product="product"
                        :main-image="productImage"
                        :images="productImageList"
                        @image-popup="imagePopup = $event"
                        @add-to-wishlist="$refs.detailRight.wishListAction()"
                      />
                    </div>

                    <div class="col-lg-12">
                      <div class="accordion" id="customAccordion">

                        <div
                          v-if="product.customizations && product.customizations.length"
                          v-for="(customization, index) in product.customizations"
                          :key="customization.id"
                          class="accordion-item mb-3"
                        >

                          <h2 class="accordion-header">
                            <button
                              class="accordion-button"
                              :class="{ collapsed: activeAccordion !== index }"
                              @click="toggleAccordion(index)"
                            >
                              {{ customization.template?.title || `Template #${customization.template_id}` }}
                            </button>
                          </h2>

                          <div
                            class="accordion-collapse collapse"
                            :class="{ show: activeAccordion === index }"
                          >
                            <div
                              class="accordion-body"
                              v-dompurify-html="customization.custom_content"
                            ></div>
                          </div>

                        </div>

                      </div>
                      <div v-if="bulkPricing.length" class="bulk-pricing-box mt-20">
                        <div class="bulk-header">
                          <h4>Business Pricing</h4>
                          <span class="bulk-tag">Bulk Discount</span>
                        </div>
                        <div class="bulk-table">
                          <div
                            v-for="(row, i) in bulkPricing"
                            :key="i"
                            class="bulk-row"
                            :class="{ best: i === bulkPricing.length - 1 }"
                          >
                            <div class="bulk-left">
                              <div class="qty">
                                {{ row.min }} - {{ row.max }}
                              </div>
                              <div class="label">
                                units
                              </div>
                            </div>
                            <div class="bulk-right">
                              € {{ calculateBulkPrice(row) }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                  </div>
                  
                  <div class="pl-30 pl-md grow">
                    <h1 class="f-16">
                      {{ productTitle }}
                    </h1>
                    <div class="mt-10">
                      <rating-star
                        :rating="parseFloat(productRating)"
                      />
                      <span
                        class="f-10 ml-5 semi-bold color-lite">
                        {{ $t('productReview.reviews', {count: reviewCount}) }}
                      </span>
                    </div>

                    <div class="devider w-md-100 mtb-15">&nbsp;</div>

                    <div
                      v-if="endTime"
                      class="flex sided warning-msg ptb-10 plr-15 mb-15 wrap gap-10"
                    >
                      <h5 class="color-inherit">
                        {{ product.flash_sale_title }}
                      </h5>
                      <div class="gap-10 flex">
                        <h5 class="color-inherit">
                          {{ $t('product.endsIn') }}
                        </h5>
                        <b>
                        <div class="countdown-display">
                           <span v-if="countdownData.days > 0">{{ countdownData.days }} {{ $tc('countdown.day', countdownData.days) }}</span>
                           <span v-if="countdownData.hours > 0 || countdownData.days > 0">{{ countdownData.hours }} {{ $tc('countdown.hour',  countdownData.hours) }} </span>
                           <span>{{ countdownData.minutes }} {{ $tc('countdown.minute', countdownData.minutes) }} </span>
                           <span>{{ countdownData.seconds }} {{ $tc('countdown.second', countdownData.seconds) }}</span>
                         </div>
                          
                        </b>
                      </div>
                    </div>
                    <div class="order-badge">
                       <span v-if="isInStock" class="badge-success">In Stock</span>
                       <span v-else class="badge-danger">Out of Stock</span>
                       <span v-if="isBackOrder" class="badge-secondary">Back Order</span>
                    </div>
                    <!-- <div
                      v-if="bundleDeal"
                      class="two-sided mb-15">
                      <h6 class="left">
                        {{ $t('product.bundleDeal') }}
                      </h6>
                      <div class="right bundle-deal">
                        {{ bundleDeal.title }}
                      </div>
                    </div> -->

                    <div
                      v-if="brand"
                      class="two-sided mb-15">
                      <h6 class="left">
                        {{ $t('product.brand') }}
                      </h6>
                      <div class="right">

                        <nuxt-link
                          class="link"
                          :to="brandLink(product.brand)"
                        >

                          <lazy-image
                          :data-src="getImageURL(product.brand.image)"
                          :title="product.brand.title"
                          :alt="product.brand.title"
                          class="brand-image"
                        />
                          <!-- {{ brand }} -->
                        </nuxt-link>
                      </div>
                    </div>

                    <div ref="attrRef"></div>

                    <div
                      v-for="(value, index) in productAttributes"
                      :key="index"
                      class="two-sided mb-15"
                    >
                      <span
                        class="left"
                      >
                        {{value.title}}
                      </span>

                      <div class="start flex wrap gap-10">
                        <label
                          v-for="(av, avIndex) in value.values"
                          :key="`av-${avIndex}`"
                          class="rd-container rd-attr"
                          :class="{ 'disabled-attr': isAttributeDisabled(av.attribute_value_id) }"
                        >
                          <input
                            type="radio"
                            :name="`${value.id}`"
                            v-model="clickedAttributes[value.id]"
                            :value="av.id"
                            :disabled="isAttributeDisabled(av.attribute_value_id)"
                            @change="selectedAttribute({key: avIndex, value: av})"
                          >
                          <span class="rd-checkmark"></span>

                          <span class="input-content">{{ av.title }}</span>
                        </label>
                      </div>
                    </div>

                    <div
                      v-if="cartError.attribute"
                      class="two-sided mb-15 align-start">
                      <h6 class="left">
                      </h6>
                      <div class="right">
                        <p
                          class="error mb-10"
                        >
                          {{cartError.attribute}}
                        </p>
                      </div>
                    </div>

                    <!-- <div
                      class="wrap two-sided mb-15 align-start">
                      <h6 class="left">
                        {{ $t('product.refundWarranty') }}
                      </h6>
                      <div class="right">
                        <div class="mb-5">

                          <template v-if="refundable(product)">
                            <div>{{ $t('productHelper.refundable') }}</div>
                            <div class="mb-10 mt-5 block color-lite">{{ $t('productHelper.mindChange') }}</div>
                          </template>
                          <template v-else>
                            {{ this.$t('productHelper.notRefundable') }}
                          </template>
                        </div>

                        <div v-if="product.warranty">{{ warranty(product) }}</div>
                        <div class="mt-5">
                          {{ $t('product.authentic') }}
                        </div>
                      </div>
                    </div> -->


                    <div
                      v-if="vouchers && vouchers.length"
                      class="two-sided mb-15 ">
                      <h6 class="left">
                        {{ $t('accountLayout.vouchers') }}
                      </h6>
                      <div class="pos-rel ">
                        <div
                          class="right mlr--2-5 cp"
                        >
                          <span
                            v-for="(value, index) in vouchers"
                            :key="index"
                            class="info-msg ptb-5 mlr-2-5 mb-5 f-9"
                            :class="{
                              active: selectedVoucher?.code == value.code
                            }"
                            @click="applyVoucher(value)"
                          >
                            <span
                              v-if="selectedVoucher?.code == value.code"
                              class="voucher-check"
                            >
                              ✓
                            </span>
                            {{
                              selectedVoucher?.code == value.code
                                ? 'Applied'
                                : $t('detailRight.off', {
                                    amount: getPriceType(value)
                                  })
                            }}
                          </span>
                        </div>
                        <!-- <pop-over
                          v-if="voucherPopOver"
                          :title="$t('filter.shop')"
                          @close="closeVoucherPopOver"
                          elem-id="voucher-pop-over"
                          :layer="false"
                        >
                          <template v-slot:content>
                            <vouchers
                              ref="voucherPagination"
                              :changing-route="false"
                            />
                          </template>
                        </pop-over> -->
                      </div>
                    </div>
                    <div
                      v-if="selectedVoucher"
                      class="voucher-applied-box mt-10"
                    >
                      <div class="voucher-applied-top">
                        <div class="voucher-applied-left">
                          <span class="voucher-success-icon">
                            ✓
                          </span>
                          <div>
                            <div class="voucher-applied-title">
                              Voucher Applied
                            </div>
                            <div class="voucher-applied-code">
                              {{ selectedVoucher.code }}
                            </div>
                          </div>
                        </div>
                        <div class="voucher-discount">
                          -{{ currencyIcon }}
                          {{ voucherDiscountAmount }}
                        </div>
                      </div>
                      <div class="voucher-final-price">
                        Final Price:
                        <strong>
                          {{ currencyIcon }}
                          {{ discountedPrice }}
                        </strong>
                      </div>
                    </div>
                    <div
                      class="editor mt-30 mt-sm-15"
                      v-dompurify-html="overview"
                    />
                    <div v-if="productData.main_product" class="card p-3 mt-20" style="background:#f4f5fb;"">
                        <h6 class="bold">Frequently bought together</h6>
                        <div class="row align-items-center">
                          <!-- LEFT: IMAGES -->
                          <div class="col-6">
                              <div class="d-flex align-items-center gap-2 mt-20">
                                <span class="fw-bold"></span>
                                <img :src="getImageURL(productData.main_product.image)" class="img-fluid rounded">
                                <template v-for="item in productData.cross_sell">
                                  <template v-if="selectedItems.includes(Number(item.id))">
                                    <span class="fw-bold">+</span>
                                    <img
                                      :src="getImageURL(item.image)"
                                      class="img-fluid rounded"
                                    >
                                  </template>
                                </template>
                                <!-- <template v-for="service in productData.upsell_services" >
                                  <template v-if="selectedServices.includes(service.id)">
                                    <span class="fw-bold">+</span>
                                    <img :src="fixImage(service.image)" class="img-fluid rounded">
                                  </template>
                                  <template v-if="selectedServiceOptions[service.id]">
                                    <span class="fw-bold">+</span>
                                    <img :src="fixImage(service.image)" class="img-fluid rounded">
                                  </template>
                                </template> -->
                              </div>
                          </div>
                          <!-- RIGHT: PRICE + BUTTON -->
                          <div class="col-6 text-end">
                              <p class="mb-2">
                                Total Price: <strong class="text-danger">€{{ totalPrice }}</strong>
                              </p>
                              <button class="btn btn-cart" @click="handleCartWithExtras">
                                Add to basket
                              </button>
                          </div>
                        </div>
                        <hr>
                        <!-- CHECKBOX LIST -->
                        <div class="small-text">
                          <!-- ITEM 1 -->
                          <div class="d-flex justify-content-between align-items-center mb-2">
                              <div>
                                <input type="checkbox" checked disabled>
                                This item: {{ productData.main_product.name }}
                              </div>
                              <div>
                                <span class="text-muted text-decoration-line-through me-2">€{{ productData.main_product.price }}</span>
                                <strong>€{{ productData.main_product.old_price }}</strong>
                              </div>
                          </div>
                          <!-- ITEM 2 -->
                          <div v-for="item in productData.cross_sell" :key="item.id" class="d-flex justify-content-between align-items-center mb-2">
                              <div>
                                <input type="checkbox" :value="Number(item.id)"  v-model="selectedItems">
                                {{ item.name }}
                              </div>
                              <div>
                                <span class="text-muted text-decoration-line-through me-2">€{{ item.old_price }}</span>
                                 <strong>€{{ item.price }}</strong>
                              </div>
                          </div>
                        </div>
                        <div class="card p-3 border-0 mt-3" v-if="productData.upsell_services.length" style=" border-radius:12px;">
                          <!-- HEADER -->
                          <div class="d-flex justify-content-between align-items-center mb-3">
                              <h6 class="mb-0 bold">Accessories & Upgrades</h6>
                              <span class="badge bg-success">● In Stock</span>
                          </div>
                          <!-- BATTERY UPGRADE -->
                          <div  v-for="service in productData.upsell_services" :key="service.id" class="p-3 mb-3" style="background:#fff; border-radius:10px;">
                              <div class="d-flex align-items-center mb-2">
                                <strong class="ms-2">{{ service.title }}</strong>
                              </div>
                              <p v-if="service.description" class="small-text mb-2">
                                {{ service.description }}
                              </p>
                              <div class="small-text">
                                <!-- <div class="d-flex justify-content-between align-items-center mb-2"> -->
                                  <template v-if="service.type === 'product'">
                                    <div
                                      v-for="opt in service.options"
                                      :key="opt.id"
                                      class="d-flex justify-content-between align-items-center mb-2"
                                    >
                                      <div>
                                        <input
                                          type="checkbox"
                                          :value="opt.id"
                                          v-model="selectedServiceOptions[service.id]"
                                          :checked="Number(opt.price) === 0"
                                          :disabled="Number(opt.price) === 0"
                                          class="me-2"
                                        >
                                        {{ opt.name }}
                                        <span v-if="Number(opt.price) === 0">
                                          (Included)
                                        </span>
                                      </div>
                                      <div>
                                        <strong>(+€{{ opt.price }})</strong>
                                      </div>
                                    </div>
                                  </template>
                                  <template v-else>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                      <div>
                                        <input
                                          type="checkbox"
                                          :value="service.id"
                                          v-model="selectedServices"
                                          :checked="Number(service.price) === 0"
                                          :disabled="Number(service.price) === 0"
                                          class="me-2"
                                        >
                                        {{ service.title }}
                                        <span v-if="Number(service.price) === 0">
                                          (Included)
                                        </span>
                                      </div>
                                      <div>
                                        <strong>(+€{{ service.price }})</strong>
                                      </div>
                                    </div>
                                  </template>
                                <!-- </div> -->
                              </div>
                          </div>
                        </div>
                        <div class="card p-3 border-0 mt-3" style=" border-radius:12px;" v-if="productData.bundle_deal.length">
                          <div class="d-flex justify-content-between align-items-center mb-3">
                              <h6 class="mb-0 bold">Essentials Bundle</h6>
                          </div>
                          <div v-for="(bundle, bIndex) in productData.bundle_deal" :key="bundle.id" class="p-3 mb-3 border rounded">
                              <p class="small-text mb-2" v-if="bundle.description">
                                {{ bundle.description }}
                              </p>
                              <div class="d-flex align-items-center gap-2 mb-2">
                                <template v-for="(item, index) in bundle.items">
                                  <img :src="getImageURL(item.image)" class="img-fluid rounded">
                                  <span v-if="index !== bundle.items.length - 1" class="fw-bold">+</span>
                                </template>
                              </div>
                              <div class="d-flex justify-content-between align-items-center mb-2 small-text">
                                <div>
                                  <input
                                    type="checkbox"
                                    :value="bundle.id"
                                    v-model="selectedBundle"
                                    class="me-2"
                                  >
                                  <span>
                                    {{ bundleItemsText(bundle) }}
                                  </span>
                                </div>
                                <div>
                                  <span class="text-muted text-decoration-line-through me-2">€{{ bundleSellingPrice(bundle) }}</span>
                                  <strong>€{{ bundlePrice(bundle) }}</strong>
                                </div>
                              </div>
                          </div>
                        </div>
                    </div>
                  </div><!-- plr-30 grow -->
                  
                </div><!-- flex -->
              </div>

              
              
              <!-- <client-only>
                <div
                  class="ellipsis-para editor mt-30 mt-sm-15"
                  :class="{'expanded': descriptionExpand}"
                  v-dompurify-html="description"
                />
                <button
                  @click.prevent="descriptionToggle"
                  aria-label="Read less"
                  class="link mt-15 mb-5"
                >
                  {{ descriptionExpand ? $t('product.readLess') : $t('product.readMore') }}
                </button>
              </client-only> -->
            </div>
            <!-- product-detail -->

            <detail-right
              ref="detailRight"
              :product-inventory="selectedInventory"
              :disabled="!statusPublic"
              :product="product"
              :bulk-pricing="bulkPricing"
              :selected-voucher="selectedVoucher"
              :discounted-price="discountedPrice"
              :voucher-discount="voucherDiscountAmount"
              @cart-error="hasCartError"
              @added-to-cart="showSuccessBanner"
            />

          </div><!-- product-detail -->
        </div>

      </div><!-- container-fluid mtb-15 -->

      <client-only>
        <div
          :class="{'mx-h-0': !hasReview, 'review-loaded': !reviewLoaded}"
          class="container-fluid suggested-container mn-h-400x"
        >
          <lazy-area
            v-slot:default="{renderArea}"
          >
            <product-review
              v-if="renderArea"
              :id="product.id"
              class="b-t pt-20 pt-sm-15  "
              @has-review="fetchedReview"
            />
          </lazy-area>
        </div>

        <div
          class="container-fluid suggested-container mn-h-400x"
        >
          <lazy-area
            v-slot:default="{renderArea}"
          >
            <suggested-products
              v-if="renderArea"
              :product-id="productId"
            />
          </lazy-area>
        </div>
      </client-only>

    </div>
    <CartDrawer 
      :isOpen="cartDrawerOpen" 
      @close="cartDrawerOpen = false"
    />
  </div>

</template>
<script>

  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import metaHelper from '~/mixin/metaHelper'
  import productHelper from '~/mixin/productHelper'
  import ProductImages from '~/components/ProductImages'
  import DetailRight from '~/components/DetailRight'
  import LazyArea from '~/components/LazyArea'
  import SuggestedProducts from '~/components/SuggestedProducts'
  import ProductReview from '~/components/ProductReview'
  import moment from 'moment-timezone'
  import DOMPurify from 'dompurify';
  import Vouchers from "~/components/Vouchers";
  import PopOver from "~/components/PopOver";
  import RatingStar from "~/components/RatingStar";
  import Breadcrumb from "~/components/Breadcrumb";
  import global from '~/mixin/global'
  import Dropdown from "~/components/Dropdown";
  import StickyCartBtn from "~/components/StickyCartBtn";
  import CartDrawer from '~/components/CartDrawer'

  export default {
    middleware: ['common-middleware'],
    head() {
      return {
        title: this.product?.meta_title,
        meta: [
          this.generatingMeta('description', this.product?.meta_description),
          this.generatingMeta('keywords', this.product?.meta_keywords),
          this.generatingMeta('og:image', this.imageURL(this.product)),
          this.generatingMeta('og:title', this.product?.meta_title),
          this.generatingMeta('og:description', this.product?.meta_description)
        ],
        link: [
          {
            rel: 'preload',
            as: 'image',
            href: this.getThumbImageURL(this.productImage)
          },
        ],

      }
    },

    data() {
      return {
        productData: {
          main_product: null,
          cross_sell: [],
          upsell_services: [],
          bundle_deal: null
        },
        selectedVoucher: null,
        showCartSuccess: false,
        lastAddedProduct: '',
        successTimeout: null,
        selectedBundle: false,
        selectedServices: [],
        selectedServiceOptions: {},
        selectedItems: [],
        loading: false,
        cartDrawerOpen: false,
        activeAccordion: null,
        bulkPricing: [],
        clickedAttributes: [],
        cartError: {
          attribute: null,
          quantity: null,
        },
        selectedInventory: {},
        currentAttributes: [],
        descriptionExpand: false,
        optionChange: false,
        productInventory: null,
        imagePopup: false,
        hasReview: true,
        reviewLoaded: true,
        activatedPage: false,
        voucherPopOver: false,
		    activeTemplate: null,
        // Initialize countdown data
        countdownData: {
          days: 0,
          hours: 0,
          minutes: 0,
          seconds: 0
        },
        countdownInterval: null
      }
    },
    components: {
      StickyCartBtn,
      Dropdown,
      Breadcrumb,
      RatingStar,
      PopOver,
      Vouchers,
      ProductImages,
      LazyArea,
      SuggestedProducts,
      DetailRight,
      ProductReview,
      CartDrawer
    },
    mixins: [util, metaHelper, productHelper, productPriceHelper, global],
    computed: {
      voucherDiscountAmount() {

        if (!this.selectedVoucher) {
          return '0.00'
        }
        let price = Number(
          this.$refs.detailRight?.finalUnitPrice ||
          this.product?.offered ||
          this.product?.selling ||
          0
        )

        let discount = 0

        if (this.selectedVoucher.type == 2) {
          discount =
            (price * this.selectedVoucher.price) / 100
          if (
            this.selectedVoucher.capped_price &&
            discount > this.selectedVoucher.capped_price
          ) {
            discount =
              Number(this.selectedVoucher.capped_price)
          }

        } else {
          discount = Number(
            this.selectedVoucher.price || 0
          )
        }

        return Math.min(discount, price)
          .toFixed(2)
      },
      discountedPrice() {

        let price = Number(
          this.$refs.detailRight?.finalUnitPrice ||
          this.product?.offered ||
          this.product?.selling ||
          0
        )

        if (!this.selectedVoucher) {
          return price.toFixed(2)
        }

        let discount = 0

        if (this.selectedVoucher.type == 2) {

          discount =
            (price * this.selectedVoucher.price) / 100

          if (
            this.selectedVoucher.capped_price &&
            discount > this.selectedVoucher.capped_price
          ) {
            discount =
              Number(this.selectedVoucher.capped_price)
          }

        } else {
          discount = Number(
            this.selectedVoucher.price || 0
          )
        }

        return Math.max(
          price - discount,
          0
        ).toFixed(2)
      },
      totalPrice() {
        
        let total = 0
        if (this.productData.main_product) {
          total += Number(this.productData.main_product.price)
        }
        this.productData.cross_sell?.forEach(item => {
          if (this.selectedItems.includes(item.id)) {
            total += Number(item.price)
          }
        })
        this.productData.upsell_services?.forEach(service => {

          if (service.type === 'service') {
            if (this.selectedServices.includes(service.id)) {
              total += Number(service.price)
            }
          }
          if (service.type === 'product') {
            const selectedOptIds  = this.selectedServiceOptions[service.id] || []

            service.options.forEach(opt => {
              if (selectedOptIds.includes(opt.id) || selectedOptIds.includes(String(opt.id))) {
                total += Number(opt.price)
              }
            })
          }
        })

        if (this.selectedBundle && this.productData.bundle_deal) {
          total += Number(this.productData.bundle_deal.final_price)
        }
        return total.toFixed(2)
      },
      description() {
        return this.product?.description || null
      },
      overview() {
        return this.product?.overview || null
      },
      reviewCount() {
        return this.product?.review_count || 0
      },
      productRating() {
        return this.product?.rating || 0
      },
      productImage() {
        return this.product?.image || null
      },
      productImageList() {
        return this.product?.images || null
      },
      timeDifference() {
        const len = this.product.id.toString()?.length
        let highest = ''
        for (let i = 1; i <= len; i++) {
          highest += '9'
        }
        return ((this.product.id / highest) * 100).toFixed(2)
      },
      endTime() {
        return this.product?.end_time || null
      },
      productId() {
        return this.$route.params.id
      },
      statusPublic() {
        return parseInt(this.product?.status) === 1
      },
      category() {
        return this.product?.category
      },
      currentCategories() {
        return this.product?.current_categories
      },
      productTitle() {
        return this.product?.title || ''
      },
      preparedSlug() {
        return this.categoryData?.map(i => {
          return { title: i.title, link: this.categoryLink(i) }
        })?.reverse()
      },
      categoryData(){
        return this.product?.category_data
      },
      productSlug() {
        return this.product?.slug
      },
      bundleDeal() {
        return this.product?.bundle_deal
      },
      isInStock() {
        const inventory = this.optionChange ? this.productInventory : null

        if (!inventory) return this.product?.in_stock

        return inventory.is_active == 1 || inventory.quantity > 0
      },
      isBackOrder() {
        const inventory = this.optionChange ? this.productInventory : null;

        if (inventory) {
          return inventory.quantity <= 0 && inventory.is_active == 1;
        }

        return false
      },
      inStock() {
        return this.isInStock ? this.$t('detail.inStock') : this.$t('detail.outOfStock')
      },
      vouchers() {
        return this.product?.vouchers;
      },
      brand() {
        return this.product?.brand?.title || ''
      },
      productAttributeImage() {

        const attrImg = []
          this.product.product_image_names.forEach((i, key) => {

          if(i.attributes.length) {
            attrImg[i.attributes[0]?.attribute_value_id] = {value: i, key: key}
          }
        });


        return attrImg;
      },
      productAttributes() {
        this.product?.attribute.forEach(i=>{
          this.clickedAttributes[i.id] = []
        })

        return this.product?.attribute.map(i => {
          return {
            ...i,
            ...{
              values: i.values.reduce((a, item) => {
                a[`${item.attribute_id}-${item.attribute_value_id}`] = item
                return a;
              }, {})
            }
          }
        })
      },
      ...mapGetters('common', ['currencyIcon', 'currencyPosition', 'setting']),
      ...mapGetters('detail', ['product']),
    },
    methods: {
      ...mapActions('user', ['getUserToken']),
      applyVoucher(voucher) {
        if (
          this.selectedVoucher &&
          this.selectedVoucher.code == voucher.code
        ) {
          this.selectedVoucher = null
          return
        }
        this.selectedVoucher = voucher
      },
      showSuccessBanner(product) {
        this.lastAddedProduct = product?.title || this.productTitle
        this.showCartSuccess = true

        if (this.successTimeout) {
          clearTimeout(this.successTimeout)
        }

        this.successTimeout = setTimeout(() => {
          this.showCartSuccess = false
        }, 5000)
      },
      async handleViewCart() {
        try {
          const user_token = await this.getUserToken()

          await this.$store.dispatch('cart/getCartByUser', {
            lang: this.langCode,
            params: { user_token }
          })

          this.cartDrawerOpen = true
          this.showCartSuccess = false

        } catch (e) {
          console.error("Cart fetch error", e)
        }
      },
      isAttributeDisabled(attributeValueId) {
        if (!this.product?.inventory) return false;

        const temp = { ...this.currentAttributes };

        let attrKey = null;

        for (let key in this.productAttributes) {
          const attr = this.productAttributes[key];
          if (Object.values(attr.values).some(v => v.attribute_value_id == attributeValueId)) {
            attrKey = attr.id;
            break;
          }
        }

        if (!attrKey) return false;

        temp[attrKey] = { attribute_value_id: attributeValueId };

        return !this.isCombinationAvailable(temp);
      },
      isCombinationAvailable(selectedAttributes) {
        if (!this.product?.inventory) return false;

        const selectedIds = Object.values(selectedAttributes)
          .filter(Boolean)
          .map(i => parseInt(i.attribute_value_id));

        return this.product.inventory.some(inv => {
          const invIds = inv.inventory_attributes.map(i =>
            parseInt(i.attribute_value_id)
          );

          const matches = selectedIds.every(id => invIds.includes(id));

          if (!matches) return false;

          return Number(inv.quantity) > 0 || (Number(inv.quantity) <= 0 && Number(inv.is_active) === 1);
        });
      },
      bundleItemsText(bundle) {
        return bundle.items
          .map(item => item.name)
          .join(', ')
      },
      bundlePrice(bundle) {
        return Number(bundle.final_price).toFixed(2)
      },
      bundleSellingPrice(bundle) {
        return Number(bundle.total_price).toFixed(2)
      },
      getCartExtras() {
        const data = {
          cross_sell: [],
          services: [],
          options: [],
          bundle: null
        }

        this.productData.cross_sell.forEach(item => {
          if (this.selectedItems.includes(item.id)) {
            data.cross_sell.push(item)
          }
        })

        this.productData.upsell_services.forEach(service => {
          if (service.type === 'service') {
            if (this.selectedServices.includes(service.id)) {
              data.services.push(service)
            }
          }

          if (service.type === 'product') {
            const selectedOpts = this.selectedServiceOptions[service.id] || []

            service.options.forEach(opt => {
              if (
                selectedOpts.includes(opt.id) ||
                selectedOpts.includes(String(opt.id))
              ) {
                data.options.push(opt)
              }
            })
          }
        })

        if (this.selectedBundle && this.productData.bundle_deal) {
          data.bundle = this.productData.bundle_deal
        }

        return data
      },
      async handleCartWithExtras() {
        try {
          const user_token = await this.getUserToken()
          const extras = this.getCartExtras()

          // if (extras.bundle) {
          //   await this.cartAction({
          //     payload: {
          //       user_token,
          //       apiVal: {
          //         user_token,
          //         bundle_id: extras.bundle.id,
          //         quantity: 1
          //       },
          //       isBundle: true
          //     },
          //     lang: this.langCode
          //   })

          //   this.showSuccessBanner()
          //   return
          // }

          await this.cartAction({
            payload: {
              user_token,
              storeVal: {
                quantity: 1
              },
              apiVal: {
                user_token,
                product_id: this.productData.main_product.id,
                inventory_id: this.productData.main_product.inventory_id,
                price: Number(this.productData.main_product.price),
                quantity: 1,
              }
            },
            lang: this.langCode
          })

          for (const item of extras.cross_sell) {
            await this.cartAction({
              payload: {
                user_token,
                storeVal: {
                  quantity: 1
                },
                apiVal: {
                  user_token,
                  product_id: item.id,
                  inventory_id: item.inventory_id,
                  price: Number(item.price),
                  quantity: 1
                }
              },
              lang: this.langCode
            })
          }

          // for (const service of extras.services) {
          //   await this.cartAction({
          //     payload: {
          //       user_token,
          //       apiVal: {
          //         user_token,
          //         service_id: service.id,
          //         quantity: 1
          //       }
          //     },
          //     lang: this.langCode
          //   })
          // }

          // for (const opt of extras.options) {
          //   await this.cartAction({
          //     payload: {
          //       user_token,
          //       apiVal: {
          //         user_token,
          //         product_option_id: opt.id,
          //         quantity: 1
          //       }
          //     },
          //     lang: this.langCode
          //   })
          // }

          this.showSuccessBanner()

        } catch (e) {
          console.error("Basket error", e)
        }
      },
      fixImage(image) {
        return 'https://shop.fixmypc.ie/' + image
      },
      openCartDrawer() {
        this.cartDrawerOpen = true
      },
      toggleAccordion(key) {
        this.activeAccordion =
          this.activeAccordion === key ? null : key
      },
      calculateBulkPrice(row) {
        let basePrice = parseFloat(this.product?.offered || 0);

        const discountValue = parseFloat(row.discount_value || 0);

        let price = basePrice;

        if (row.discount_type === 'percentage') {
          price -= (basePrice * discountValue / 100);
        } else if (row.discount_type === 'fixed') {
          price -= discountValue;
        }

        return Math.max(price, 0).toFixed(2);
      },
      async fetchBulkPricing(){
        try {
            const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
            const res = await this.$axios.get(
              `${baseUrl}api/v1/business-product/${this.product.id}`
            );
            this.bulkPricing = res?.data?.data?.pricing || [];
        } catch (e) {
          this.bulkPricing = [];
        }
      },
      async fetchUpsellCrossSell(){
        if (!this.product?.id) return
        this.loading = true
        try {
            const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
            const res = await this.$axios.get(
              `${baseUrl}api/v1/product/upsell-crosssell/${this.product.id}`
            );
            this.productData = {
              main_product: res.data.main_product || null,
              cross_sell: res.data.cross_sell || [],
              upsell_services: res.data.upsell_services || [],
              bundle_deal: res.data.bundle_deal || null 
            }
            this.selectedServiceOptions = {}
            this.productData.upsell_services.forEach(service => {
              if (service.type === 'product') {
                this.$set(this.selectedServiceOptions, service.id, [])
              }
            })
            console.log("UPSELL/CROSSSELL DATA", this.productData)
            this.selectedItems = [this.productData.main_product.id]
        } catch (e) {
          this.bulkPricing = [];
        }
        this.loading = false
      },
      startCountdown() {
        if (!this.endTime) return;
        
        // Clear any existing interval
        if (this.countdownInterval) {
          clearInterval(this.countdownInterval);
        }
        
        // Calculate time difference and update countdown
        const updateCountdown = () => {
          const now = moment().tz(this.product.time_zone || 'UTC');
          const end = moment(this.endTime).tz(this.product.time_zone || 'UTC');
          const diff = end.diff(now);
          
          if (diff <= 0) {
            // Countdown has ended
            this.countdownData = {
              days: 0,
              hours: 0,
              minutes: 0,
              seconds: 0
            };
            clearInterval(this.countdownInterval);
            return;
          }
          
          // Calculate time components
          const duration = moment.duration(diff);
          this.countdownData = {
            days: Math.floor(duration.asDays()),
            hours: duration.hours(),
            minutes: duration.minutes(),
            seconds: duration.seconds()
          };
        };
        
        // Initial update
        updateCountdown();
        
        // Set up interval to update every second
        this.countdownInterval = setInterval(updateCountdown, 1000);
      },
	  toggleTemplate(index) {
        this.activeTemplate = this.activeTemplate === index ? null : index;
      },
      handleIntersection(entries) {
        entries.forEach((entry) => {

          if(entry.isIntersecting){
            document.body.classList.remove('show-cart')

          } else {
            document.body.classList.add('show-cart')
          }
        });
      },
      hasCartError(event){
        this.cartError = event
        this.$refs.attrRef.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center'});
      },

      selectedAttribute(data) {

        this.cartError.attribute = null
        const temp = { ...this.currentAttributes };
        temp[data.key.split('-')[0]] = data.value;
        if (!this.isCombinationAvailable(temp)) {
          return;
        }
        this.currentAttributes = temp;
        //this.attrHover(data.value)

        this.currentAttributes[data.key.split('-')[0]] = data.value

        const imageMap = []
        this.product.product_image_names.map(i => {
          imageMap[i.attributes.map(j => {
            return j.attribute_value_id
          }).sort().join('-')] = i.image
        })

        const currentSelected = Object.values(this.currentAttributes)
          .map(i => i?.attribute_value_id)
          .filter(i => i)

        let bestMatch = null;
        let highestScore = -1;

        Object.keys(imageMap).forEach(key => {
          const splitKey = key.split('-').map(Number);
          const score = splitKey.reduce((acc, value) => acc + (currentSelected.includes(value) ? 1 : 0), 0);

          if (score > highestScore) {
            highestScore = score;
            bestMatch = imageMap[key];
          }
        });

        const selectedImage = highestScore > 0 ? bestMatch : null

        const imageIndex = this.productImageList?.findIndex(i => { return i.image === selectedImage})

        this.$refs.productImagesRef.zoomActiveChange(imageIndex > -1 ? imageIndex + 1 : 0)


        if (Object.values(this.currentAttributes).length === this.productAttributes.length) {

          const selected = Object.values(this.currentAttributes).map(i => {
            return i.attribute_value_id
          })

          const selectedAttr = selected.sort().join('-')

          let currentInventory = null
          const inventoryAttr = []


          for (var i of this.product?.inventory) {
            const invAttr = []
            i.inventory_attributes.forEach(j => {
              invAttr.push(parseInt(j.attribute_value_id))
            })

            inventoryAttr[invAttr.sort().join('-')] = i
          }

          if(inventoryAttr[selectedAttr]){
            currentInventory = inventoryAttr[selectedAttr]
          }

          this.selectedInventory = currentInventory
          this.optionChanged(currentInventory)
        } else {
          this.selectedInventory = {}
        }
      },
      descriptionToggle() {
        this.descriptionExpand = !this.descriptionExpand
      },
      closeVoucherPopOver() {
        this.voucherPopOver = false
      },
      fetchedReview(evt) {
        this.hasReview = !!evt
        this.reviewLoaded = !!!evt
      },
      optionChanged(evt) {
        this.optionChange = true
        this.productInventory = evt

      },
      qty(direction) {
        const inventory = this.productInventory || {}
        const isBackOrder = inventory?.is_active == 1
        const stockQty = Number(inventory?.quantity || 0)
        if (this.quantity + direction <= 0) return
        if (!isBackOrder) {
          if (this.quantity + direction > stockQty) {
            return
          }
        }
        this.quantity += direction
      },
      ...mapActions('cart', ['cartAction']),
      ...mapActions('common', ['fetchLocation']),
      ...mapActions('detail', ['emptySuggestedProducts']),
      ...mapActions('user', ['emptyVoucher']),
    },
    beforeDestroy() {
      document.body.classList.remove('detail-page')
      // Clear the interval when component is destroyed
      if (this.countdownInterval) {
        clearInterval(this.countdownInterval);
      }
      if (this.successTimeout) {
        clearTimeout(this.successTimeout)
      }
    },
    watch: {
      // Watch for product data to be loaded
      product: {
        immediate: true,
        handler(newVal) {
          if (newVal && newVal.end_time) {
            this.$nextTick(() => {
              this.startCountdown();
            });
          }
          if (newVal && newVal.id) {
            this.fetchUpsellCrossSell()
          }
        }
      }
    },
    async asyncData({store, route, $auth, error}) {
      try {
        await store.dispatch('detail/fetchProduct', {
          params: {
            id: route.params.id,
            user_id: $auth?.user?.id || ''
          },
          lang: store.state?.language?.langCode,
        })
      } catch (e) {
        error(e)
      }
    },
    async mounted() {
      this.fetchBulkPricing()
      this.emptyVoucher()
      this.emptySuggestedProducts()
      //Checking if the product has no attribute
      if (this.product?.inventory?.length === 1 && this.product?.inventory[0]?.inventory_attributes?.length === 0) {

        this.selectedInventory = this.product?.inventory[0]
      }

      document.body.classList.add('detail-page')

      this.observer = new IntersectionObserver(this.handleIntersection, {
        root: null, // Use the viewport as the root
        rootMargin: '0px', // No margin
        threshold: 0, // Trigger when 50% of the target is visible
      });

      // Start observing the target element

      this.observer.observe(this.$refs.detailRight.$el);

      
      // Start countdown if product data is already available
      if (this.product && this.product.end_time) {
        this.startCountdown();
      }

    }
  }
</script>

<style>
.template-accordion {
  margin-top: 30px;
}

.template-item {
  margin-bottom: 15px;
}

.template-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 15px;
  cursor: pointer;
  background: #f8f9fa;
  border-radius: 4px;
  transition: all 0.2s;
}

.template-header:hover {
  background: #e9ecef;
}

.template-content {
  padding: 15px;
  background: #fff;
  border: 1px solid #dee2e6;
  border-top: none;
  border-radius: 0 0 4px 4px;
}

.arrow-icon {
  transition: transform 0.2s;
}

.arrow-down {
  transform: rotate(0deg);
}

.arrow-up {
  transform: rotate(180deg);
}

.countdown-display {
  display: inline;
  font-weight: bold;
}

.pane-container {
    background: #fff!important;
    box-shadow: none !important;
}

.bulk-pricing-box {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  background: #ffffff;
  box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}

/* HEADER */
.bulk-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.bulk-header h4 {
  font-size: 16px;
  font-weight: 600;
}

.bulk-tag {
  background: #eef2ff;
  color: #4f46e5;
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 6px;
}

/* TABLE */
.bulk-table {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* ROW */
.bulk-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px;
  border-radius: 8px;
  background: #f9fafb;
  transition: 0.2s;
  border: 1px solid transparent;
}

.bulk-row:hover {
  background: #f1f5ff;
}

/* LEFT */
.bulk-left {
  display: flex;
  flex-direction: column;
}

.qty {
  font-weight: 600;
  font-size: 14px;
}

.label {
  font-size: 11px;
  color: #6b7280;
}

/* RIGHT */
.bulk-right {
  font-weight: 700;
  font-size: 16px;
  color: #111827;
}

/* BEST PRICE */
.bulk-row.best {
  background: #eef2ff;
  border-color: #6366f1;
}

.bulk-row.best .bulk-right {
  color: #4f46e5;
}

.preview-box {
    border: 1px solid #E3E3EF;
    border-radius: 20px;
}

.badge-danger {
  border: 1px solid #FF5151;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #FF5151;
  margin-bottom: 25px;
}

.badge-success {
  border: 1px solid #05B942;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #05B942;
  margin-bottom: 25px;
}

.badge-secondary {
  border: 1px solid #605982;
  padding: 4px 13px 5px 13px;
  border-radius: 86px;
  color: #605982;
  margin-bottom: 25px;
}

.brand-image {
  height: 45px;
  width: 100px;
  object-fit: contain;
  border: 1px solid #E3E3EF;
  border-radius: 15px;
}

.btn-cart {
  background: #3f3d9c;
  color: #fff;
  font-size: 12px;
  
}

.btn-cart:hover {
  background: #3f3d9c;
  color: #fff;
}

.small-text {
  font-size: 13px;
  color: #666
}

.card {
  border-radius: 12px
}

.img-fluid{
  width: 80px;
  height: 80px;
  object-fit: contain;
}

.disabled-attr {
  opacity: 0.4;
  background: #bbb;
  border-radius: 8px;
  cursor: not-allowed;
}

.cart-success-bar {
  top: 0;
  left: 0;
  width: 100%;
  background: #e6f4ea;
  border: 1px solid #b7e1c1;
  color: #1e7e34;
  padding: 12px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 9999;
}

.success-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.check-icon {
  background: #28a745;
  color: white;
  border-radius: 50%;
  padding: 4px 8px;
  font-size: 12px;
}

.view-cart-btn {
  border: 1px solid #28a745;
  background: white;
  color: #28a745;
  padding: 0px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
}

.view-cart-btn:hover {
  background: #28a745;
  color: white;
}
.voucher-applied-box {
  background: #f0fdf4;
  border: 1px solid #86efac;
  border-radius: 12px;
  padding: 14px;
  margin-top: 12px;
}

.voucher-applied-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.voucher-applied-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.voucher-success-icon {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #22c55e;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: bold;
}

.voucher-applied-title {
  font-size: 13px;
  font-weight: 600;
  color: #166534;
}

.voucher-applied-code {
  font-size: 12px;
  color: #15803d;
}

.voucher-discount {
  font-size: 18px;
  font-weight: 700;
  color: #16a34a;
}

.voucher-final-price {
  margin-top: 10px;
  font-size: 13px;
  color: #374151;
}
</style>