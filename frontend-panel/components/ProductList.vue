<template>
  <div :key="$route.fullPath">
    <div class="container-fluid my-5" v-if="category?.child && category?.child.length">
      <breadcrumb v-if="hasBreadcrumb" class="mb-15 mt-0" :page="resultTitle" :slugs="slugs" />
      <h4 class="category-title">{{ category?.title }}</h4>
      <div class="mobile-list">
        <client-only>
          <ImageSlider class="sub_cat-gl_slider" :per-view="perView" :gap="20" :responsive="perViewResponsive">
            <template v-slot:content>
              <li class="product-listing">
                <nuxt-link :to="categoryLink(category)" :title="`All ${category?.title}`">
                  <div class="category-box">
                    <span class="category-count">
                      {{ category?.products_count || 0 }}
                    </span>
                    <lazy-image
                      :data-src="getImageURL(category.image)"
                      :title="`All ${category?.title}`"
                      :ALT="`All ${category?.title}`"
                      class="category-icon-image"
                    />
                    <p>All {{ category?.title }}</p>
                  </div>
                </nuxt-link>
              </li>
              <li v-for="(cat, index) in category?.child" :key="index" class="product-listing">
                <nuxt-link :to="categoryLink(cat)" :title="cat.title">
                  <div class="category-box">
                    <span class="category-count">
                      {{ cat?.products_count || 0 }}
                    </span>
                    <lazy-image :data-src="getImageURL(cat.image)" :title="cat.title" :alt="cat.title" class="category-icon-image" />
                    <p>{{ cat.title }}</p>
                  </div>
                </nuxt-link>
              </li>
            </template>
          </ImageSlider>
        </client-only>
      </div>
    </div>

    <div class="detail-menu">
      <div class="container-fluid">
        <div class="list-heading flex sided">
          <p class="hide-sm">
            {{ pageHeading }}
            <span v-if="resultTitle">
              {{ $t('listingLayout.for') }} <span class="bold">"{{ resultTitle }}"</span>
            </span>
          </p>
          <div class="flex gap-3">
            <span class="hide-sm">{{ $t('listingLayout.sortBy') }}</span>
            <client-only>
              <dropdown class="sort-dropdown" :options="sortingOptions" :selected-key="sortby" @clicked="selectedSorting" />
              <button v-show="isXsDevice" class="filter-btn flex outline-btn plr-20" @click.prevent="openFilter">
                {{ $t('listingLayout.filter') }}
                <span v-if="filteredCount">({{ filteredCount }})</span>
                <i class="icon black" :class="[{ 'arrow-up': filterPopup }, { 'arrow-down': !filterPopup }]" />
              </button>
            </client-only>
          </div>
        </div>
      </div>
    </div>

    <div class="container-fluid mtb-20 mtb-sm-15">
      <div class="product-list">
        
        <aside v-if="!isXsDevice" class="left-area">
          <div class="sidebar">
             <button v-if="backBtn" @click.prevent="goingBack" class="flex start mb-15 clear-btn">
               <span class="flex"> 
                 <i class="dimen-16x icon double-arrow-left-icon mr-5 opacity-6"></i>
                 {{ $t('date.gb') }}
                </span>
               </button>
            <filter-category ref="filterCategory" :categories="categories" @going-next="goingNext" />
            <filter-price ref="filterPrice" @reset-route="changeRoute" />
            <filter-rating ref="filterRating" @reset-route="changeRoute" />
            <filter-brand ref="filterBrand" :brands="brands" @reset-route="changeRoute" />
            <filter-collection ref="filterCollection" :collections="collections" @reset-route="changeRoute" />
            <filter-shipping ref="filterShipping" :shipping-rules="shippingRules" @reset-route="changeRoute" />
          </div>
        </aside>

        <div v-if="isXsDevice && filterPopup" class="new-mobile-overlay" @click.self="closeFilter">
          <div class="new-bottom-sheet">
            <div class="sheet-header">
              <button type="button" class="close-icon-btn" @click="closeFilter">
                {{ $t('listingLayout.close') }}
              </button>
            </div>

            <div class="sheet-body">
              <div class="sidebar">
                <button v-if="backBtn" @click.prevent="goingBack" class="flex start mb-15 clear-btn">
                  <i class="dimen-16x icon double-arrow-left-icon mr-5 opacity-6"></i>
                  {{ $t('date.gb') }}
                </button>
                <filter-category :categories="categories" @going-next="goingNext" />
                <filter-price @reset-route="changeRoute" />
                <filter-rating @reset-route="changeRoute" />
                <filter-brand :brands="brands" @reset-route="changeRoute" />
                <filter-collection :collections="collections" @reset-route="changeRoute" />
                <filter-shipping :shipping-rules="shippingRules" @reset-route="changeRoute" />
              </div>
            </div>
          </div>
        </div>

        <div class="main-content">
          <breadcrumb v-if="hasBreadcrumb" class="mb-15 mt-0" :page="resultTitle" :slugs="slugs" />
          <div v-if="fetchingProductData" class="tile-container">
             <tile-shimmer v-for="index in 20" :key="index" />
          </div>
          <div class="pos-rel" v-else>
            <div class="tile-container" v-if="currentItems.length">
              <product-tile v-for="(value, index) in currentItems" :key="value.id || index" :product="value" />
            </div>
            <div v-else class="empty-product">
              No products found
            </div>
            <pagination class="mt-30" ref="productPagination" :total-page="totalPage" @fetching-data="fetchingData" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import Pagination from '~/components/Pagination'
import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'
import routeParamHelper from '~/mixin/routeParamHelper'
import Dropdown from '~/components/Dropdown'
import FilterPrice from "./FilterPrice";
import FilterCategory from "./FilterCategory";
import TileShimmer from "./TileShimmer";
import Spinner from "./Spinner";
import ProductTile from "./ProductTile";
import FilterRating from "./FilterRating";
import FilterBrand from "./FilterBrand";
import FilterCollection from "./FilterCollection";
import FilterShipping from "./FilterShipping";
import Breadcrumb from "./Breadcrumb";

export default {
  name: 'ProductList',
  data() {
    return {
      loaded: false,
      filterPopup: true,
      sortingOptions: {
        featured: { title: this.$t('featured.featured') },
        price_low_to_high: { title: this.$t('listingLayout.priceLowToHigh') },
        price_high_to_low: { title: this.$t('listingLayout.priceHighToLow') },
        avg_customer_review: { title: this.$t('listingLayout.avgCustomerReview') },
      },
      sortby: this.$route.query.sortby || '',
    }
  },
  mixins: [util, routeParamHelper],
  watch: {
    '$route.fullPath': {
      immediate: true,
      handler() {

        window.scrollTo({
          top: 0,
          behavior: 'smooth'
        })

        this.fetchingData()

        this.$nextTick(() => {
          this.$forceUpdate()
        })
      }
    }
  },
  props: {
    perView: {
      type: Number,
      default: 6
    },
    perViewResponsive: {
      type: Array,
      default() {
        return [6, 3, 3, 3, 3]
      }
    },
    category: {
      type: Object,
      default: () => ({})
    },
    categories: {
      type: Array,
      default: []
    },
    backBtn: {
      type: Boolean,
      default: true
    },

    hasBreadcrumb: {
      type: Boolean,
      default: false
    },
    slugs: {
      type: Array,
      default() {
        return []
      }
    },
    fetchingProductData: {
      type: Boolean,
      default: false
    },
    productParams: {
      type: Object,
      default() {
        return {}
      }
    },
    resultTitle: {
      type: String,
      default: ''
    },
  },
  components: {
    Breadcrumb,
    FilterShipping,
    FilterCollection,
    FilterBrand,
    FilterRating,
    ProductTile,
    Spinner,
    TileShimmer,
    FilterCategory,
    FilterPrice,
    Pagination,
    Dropdown
  },
  computed: {
    filteredCount() {
      let count = 0
      if (this.shippingFromRoute) {
        count++
      }
      if (this.ratingFromRoute) {
        count++
      }
      if (this.minPriceFromRoute) {
        count++
      }
      if (this.maxPriceFromRoute) {
        count++
      }
      return count
    },
    isXsDevice() {
      if (process?.browser) {
        return window.innerWidth <= 576
      }
      return false
    },
    pageHeading() {
      if (this.products) {
        if (this.products?.total > 0) {
          return this.$t('listingLayout.paginationResult', {
            from: this.products?.from,
            to: this.products?.to,
            total: this.products?.total
          })
        }

        //return this.$t('listingLayout.noProductFound')
      }
      return this.$t('listingLayout.showingResult')
      //return `${this.$t('listingLayout.loading')}...`
    },
    currentItems() {
      return this.products?.data || []
    },
    totalPage() {
      return this.products?.last_page
    },
    ...mapGetters('common', ['currencyIcon', 'setting']),
    ...mapGetters('listing', ['products', 'brands', 'shippingRules', 'collections']),
  },
  methods: {
    async goingBack() {
      await this.$router.go(-1)

      this.$nextTick(() => {
        this.fetchingData()
      })
    },
    // openFilter() {
    //   this.filterPopup = true
    //   document.body.classList.add('no-scroll')
    // },
      openFilter() {
        this.filterPopup = true
        if (process.client) {
          this.scrollY = window.scrollY || window.pageYOffset
          document.documentElement.style.setProperty('--scroll-y', `-${this.scrollY}px`)
          document.body.classList.add('no-scroll')
        }
      },
       closeFilter() {
        this.filterPopup = false
        if (process.client) {
          document.body.classList.remove('no-scroll')
          window.scrollTo(0, this.scrollY || 0)
        }
      },
    selectedSorting(data) {
      if (this.sortby) {
        let filtered = Object.assign({}, this.$route.query)
        filtered = { ...filtered, ...{ sortby: data.key } }

        this.$refs.productPagination?.resettingRoute(filtered)
        //this.fetchingData()

      }
      this.sortby = data.key
    },
    clearSortby() {
      this.sortby = 'featured'
    },
    clearQuery() {
      this.$refs.filterPrice?.clearPrice()
      this.$refs.filterShipping?.clearShipping()
      this.clearSortby()
      this.$refs.filterRating?.clearRating()
      if (this.isXsDevice) {
        this.closeFilter()
      }
    },
    changeRoute(evt) {
      this.$refs.productPagination?.resettingRoute(evt)
      //this.$emit('fetch-data')
    },
    goingNext(url) {
      this.clearQuery()
      if (url === this.$route.path) {

        this.$router.push({
          query: {}
        })
        this.$emit('fetch-data')
      } else {
        this.$router.push({ path: url })
      }
    },
    fetchingData() {
      // this.settingRouteParam()
      this.$emit('fetch-data')
    },
  },
  destroyed() {
    if (this.isXsDevice && this.filterPopup) {
      this.closeFilter()
    }
  },
  async mounted() {
    this.$nextTick(function () {
      if (this.isXsDevice && this.filterPopup) {
        this.closeFilter()
      }
    })
  }
}
</script>
<style scoped>
.category-icon-image {
  width: 80px;
  height: 80px;
  border-radius: 100%;
  overflow: hidden;
}

.category-title {
  font-weight: 500;
  margin-bottom: 30px;
  font-size: 34px;
  color: #130E2B;
}

.category-box {
  background: #F3F5FC;
  border-radius: 21px;
  padding: 30px 10px;
  text-align: center;
  font-size: 20px;
  font-weight: 500;
  color: #130E2B;
  transition: 0.3s;
  border: 1px solid #E3E3EF;
  width: 100%;

}

.category-box p {
  margin-top: 10px;
  color: #130E2B;
  font-size: 20px;
  font-weight: 500;
}

.category-box:hover {
  border-color: #4a3aff;
  transform: translateY(-4px);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
  background: #fff;
}

.product-listing a {
  display: block;
}

.glide__slides {
  padding: 20px 0px !important;
}

.category-box {
  position: relative;
}

.category-count {
    position: absolute;
    background: red;
    color: #fff;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    z-index: 2;
    right: -15px;
    top: -15px;
}

@media(max-width:768px) {
  .category-box p {
      font-size: 12px;
      white-space: normal;
      height: 39px;
      overflow: hidden;
  }

  .category-box {
    padding: 15px;
  }
}
.sidebar .star-filter button {
    display: block !important;
}

@media (max-width: 576px) {
  .new-mobile-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999999;
    display: flex;
    align-items: flex-end; 
  }
  .new-bottom-sheet {
    width: 100%;
    height: 60vh;
    background: #ffffff !important;
    border-radius: 20px 20px 0 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.1);
    animation: slideUp 0.3s ease-out;
  }
  .sheet-header {
    padding: 15px;
    border-bottom: 1px solid #f0f0f0;
    text-align: right;
    position: relative;
    flex-shrink: 0;
  }
    .new-bottom-sheet .sheet-header .close-icon-btn {
      padding: 15px 20px;
      height: unset;
      line-height: 0;
      font-size: 16px;
      color: #232159;
      font-weight: 700;
    }
  .sheet-body {
    flex: 1;
    overflow-y: auto !important; 
    padding: 15px;
    -webkit-overflow-scrolling: touch;
  }
  .sheet-body .sidebar {
    display: block !important;
    background: #fff !important;
  }

  .close-icon {
    position: absolute;
    right: 15px;
    top: 10px;
    background: #eee;
    border: none;
    border-radius: 50%;
    width: 35px;
    height: 35px;
    cursor: pointer;
    touch-action: manipulation;
  }
  @keyframes slideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
  }
}
</style>
