<template>
  <div :key="$route.fullPath" class="category-listing">
    <div class="container-fluid">

      <div class="crumb" v-if="hasBreadcrumb">
        <breadcrumb :page="resultTitle" :slugs="slugs" />
      </div>

      <div class="page-head">
        <div>
          <h1>{{ resultTitle }}</h1>
          <p v-if="pageSubtitle">{{ pageSubtitle }}</p>
        </div>
      </div>

      <div class="rail-wrap" v-if="subCategories.length" :class="{ 'has-prev': railPrev, 'has-next': railNext }">
        <button
          v-show="railPrev"
          type="button"
          class="rail-arrow rail-arrow--prev"
          :aria-label="$t('ui.productListUi.aria.previous')"
          @click.prevent="scrollRail(-1)"
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
        </button>

        <div class="rail" ref="rail" @scroll.passive="updateRail">
        <nuxt-link :to="categoryLink(category)" :title="$t('ui.productListUi.allIn', { name: category.title })" class="sub-chip on">
          <span class="ico">
            <lazy-image
              :data-src="getImageURL(category.image)"
              :title="$t('ui.productListUi.allIn', { name: category.title })"
              :alt="$t('ui.productListUi.allIn', { name: category.title })"
            />
          </span>
          {{ $t('ui.productListUi.allIn', { name: (category.title || '').toLowerCase() }) }}
          <span class="n">{{ category.products_count || 0 }}</span>
        </nuxt-link>

        <nuxt-link
          v-for="(cat, index) in subCategories"
          :key="index"
          :to="categoryLink(cat)"
          :title="cat.title"
          class="sub-chip"
        >
          <span class="ico">
            <lazy-image :data-src="getImageURL(cat.image)" :title="cat.title" :alt="cat.title" />
          </span>
          {{ cat.title }}
          <span class="n">{{ cat.products_count || 0 }}</span>
        </nuxt-link>
        </div>

        <button
          v-show="railNext"
          type="button"
          class="rail-arrow rail-arrow--next"
          :aria-label="$t('ui.productListUi.aria.next')"
          @click.prevent="scrollRail(1)"
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6" /></svg>
        </button>
      </div>

      <div class="mob-bar">
        <button type="button" class="btn btn-ghost" @click.prevent="openDrawer('cats')">
          {{ $t('searchPopup.categories') }}
        </button>
        <button type="button" class="btn btn-ghost" @click.prevent="openDrawer('filters')">
          {{ $t('listingLayout.filter') }}
          <span v-if="filteredCount">({{ filteredCount }})</span>
        </button>
      </div>

      <div class="body">

        <aside class="side" v-if="!isMobileLayout">
          <filter-category ref="filterCategory" :categories="treeCategories" :back-btn="backBtn" @go-back="goingBack" @going-next="goingNext" />
          <filter-price ref="filterPrice" @reset-route="changeRoute" />
          <filter-rating ref="filterRating" @reset-route="changeRoute" />
          <filter-brand ref="filterBrand" :brands="brands" @reset-route="changeRoute" />
          <filter-availability ref="filterAvailability" @reset-route="changeRoute" />
          <filter-collection ref="filterCollection" :collections="collections" @reset-route="changeRoute" />
          <!-- No "Shipping" filter: products are not assigned a shipping
               rule any more, delivery comes from the address (2026-09-28). -->
        </aside>

        <section>
          <div class="res-bar">
            <span class="count">{{ pageHeading }}</span>

            <div class="res-tools">
              <client-only>
                <dropdown class="sort-dropdown" :options="sortingOptions" :selected-key="sortby" @clicked="selectedSorting" />
              </client-only>

              <div class="view-tog">
                <button type="button" :class="{ on: view === 'grid' }" :aria-label="$t('ui.productListUi.gridView')" @click.prevent="view = 'grid'">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" />
                    <rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />
                  </svg>
                </button>
                <button type="button" :class="{ on: view === 'list' }" :aria-label="$t('ui.productListUi.listView')" @click.prevent="view = 'list'">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <div class="chips" v-if="activeFilters.length">
            <button
              v-for="(chip, index) in activeFilters"
              :key="index"
              type="button"
              class="chip-x"
              @click.prevent="removeFilter(chip)"
            >
              {{ chip.label }}
              <span class="x" aria-hidden="true">&times;</span>
            </button>
            <button v-if="activeFilters.length > 1" type="button" class="chip-clear" @click.prevent="clearAllFilters">
              {{ $t('listingLayout.clear') }}
            </button>
          </div>

          <div v-if="fetchingProductData" class="tile-container" :class="`view-${view}`">
            <skeleton-product v-for="index in 20" :key="`sk-${index}`" />
          </div>

          <div class="pos-rel" v-else>
            <div class="tile-container" :class="`view-${view}`" v-if="currentItems.length">
              <product-tile v-for="(value, index) in currentItems" :key="value.id || index" :product="value" />
            </div>

            <div v-else class="empty">
              <div class="ico">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                  <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
                </svg>
              </div>
              <b>{{ $t('listingLayout.noProductFound') }}</b>
              <p>{{ $t('ui.productListUi.emptyHint') }}</p>
              <div class="empty-acts" v-if="activeFilters.length">
                <button type="button" class="btn btn-ink btn-sm" @click.prevent="clearAllFilters">
                  {{ $t('listingLayout.clear') }}
                </button>
              </div>
            </div>

            <pagination class="mt-30" ref="productPagination" :total-page="totalPage" @fetching-data="fetchingData" />
          </div>
        </section>
      </div>
    </div>

    <transition name="scrim-fade">
      <div v-if="drawerOpen" class="scrim" @click="closeDrawer" />
    </transition>

    <transition name="drawer-slide">
      <aside v-if="drawerOpen" class="drawer">
        <div class="drawer-head">
          <b>{{ drawerMode === 'cats' ? $t('searchPopup.categories') : $t('listingLayout.filter') }}</b>
          <button type="button" class="x" :aria-label="$t('listingLayout.close')" @click.prevent="closeDrawer">&times;</button>
        </div>

        <div class="drawer-body">
          <div class="side">
            <filter-category :categories="treeCategories" :back-btn="backBtn" @go-back="goingBack" @going-next="goingNext" />
            <template v-if="drawerMode !== 'cats'">
              <filter-price @reset-route="changeRoute" />
              <filter-rating @reset-route="changeRoute" />
              <filter-brand :brands="brands" @reset-route="changeRoute" />
              <filter-availability @reset-route="changeRoute" />
              <filter-collection :collections="collections" @reset-route="changeRoute" />
            </template>
          </div>
        </div>

        <div class="drawer-foot">
          <button type="button" class="btn btn-ghost" @click.prevent="clearAllFilters">
            {{ $t('listingLayout.clear') }}
          </button>
          <button type="button" class="btn btn-go" @click.prevent="closeDrawer">
            {{ $t('checkout.apply') }}
          </button>
        </div>
      </aside>
    </transition>
  </div>
</template>

<script>
import Pagination from '~/components/Pagination'
import { mapGetters, mapActions } from 'vuex'
import util from '~/mixin/util'
import routeParamHelper from '~/mixin/routeParamHelper'
import listingParams from '~/mixin/listingParams'
import Dropdown from '~/components/Dropdown'
import LazyImage from '~/components/LazyImage'
import FilterPrice from "./FilterPrice";
import FilterCategory from "./FilterCategory";
import Spinner from "./Spinner";
import ProductTile from "./ProductTile";
import FilterRating from "./FilterRating";
import FilterBrand from "./FilterBrand";
import FilterAvailability from "./FilterAvailability";
import FilterCollection from "./FilterCollection";
import FilterShipping from "./FilterShipping";
import Breadcrumb from "./Breadcrumb";

// Matches the .body grid breakpoint in assets/styles/category-listing.styl
const MOBILE_BREAKPOINT = 900

export default {
  name: 'ProductList',
  data() {
    return {
      loaded: false,
      view: 'grid',
      drawerOpen: false,
      drawerMode: 'filters',
      windowWidth: 0,
      railPrev: false,
      railNext: false,
      sortingOptions: {
        featured: { title: this.$t('featured.featured') },
        price_low_to_high: { title: this.$t('listingLayout.priceLowToHigh') },
        price_high_to_low: { title: this.$t('listingLayout.priceHighToLow') },
        avg_customer_review: { title: this.$t('listingLayout.avgCustomerReview') },
      },
      sortby: this.$route.query.sortby || '',
    }
  },
  mixins: [util, routeParamHelper, listingParams],
  watch: {
    subCategories: {
      handler() {
        this.$nextTick(this.resetRail)
      },
    },
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
      default: 8
    },
    perViewResponsive: {
      type: Array,
      default() {
        return [6, 5, 4, 3, 3]
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
    FilterAvailability,
    FilterRating,
    ProductTile,
    Spinner,
    FilterCategory,
    FilterPrice,
    Pagination,
    Dropdown,
    LazyImage
  },
  computed: {
    isMobileLayout() {
      return this.windowWidth > 0 && this.windowWidth <= MOBILE_BREAKPOINT
    },
    // The sidebar tree lists every top level category (same source the header
    // nav uses). The category being browsed is merged with the listing payload
    // so that branch keeps its children and product counts.
    treeCategories() {
      const nav = this.navCategories
      const all = (nav && nav.length) ? nav : (this.homeCategories || [])
      if (!all.length) {
        return this.categories || []
      }

      const current = (this.categories || [])[0]

      return all.map((item) => {
        if (current && item.slug === current.slug) {
          return { ...item, ...current }
        }
        return item
      })
    },
    subCategories() {
      return this.category?.child || []
    },
    pageSubtitle() {
      if (this.category?.meta_description) {
        return this.category.meta_description
      }
      if (this.products?.total) {
        return `${this.products.total} lines stocked`
      }
      return ''
    },
    // Applied filters rendered as removable chips above the results grid.
    activeFilters() {
      const query = this.$route.query || {}
      const chips = []

      if (query.rating) {
        chips.push({ key: 'rating', label: `${query.rating}★ & ${this.$t('listingLayout.up')}` })
      }

      if (query.min || query.max) {
        const min = query.min ? `${this.currencyIcon}${query.min}` : ''
        const max = query.max ? `${this.currencyIcon}${query.max}` : ''
        let label = `${min} – ${max}`
        if (min && !max) {
          label = `${min}+`
        } else if (!min && max) {
          label = `${this.$t('listingLayout.max')} ${max}`
        }
        chips.push({ key: 'price', label })
      }

      if (query.stock === 'true') {
        chips.push({ key: 'stock', label: this.$t('listingLayout.inStock') })
      }

      if (query.offer === 'true') {
        chips.push({ key: 'offer', label: this.$t('listingLayout.onOffer') })
      }

      return chips
        .concat(this.chipsFromList('brand', this.brands))
        .concat(this.chipsFromList('collection', this.collections))
    },
    filteredCount() {
      let count = 0
      if (this.ratingFromRoute) {
        count++
      }
      if (this.minPriceFromRoute) {
        count++
      }
      if (this.maxPriceFromRoute) {
        count++
      }
      if (this.stockFromRoute) {
        count++
      }
      if (this.offerFromRoute) {
        count++
      }
      return count
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
    ...mapGetters('home', { homeCategories: 'categories' }),
    ...mapGetters('common', ['navCategories']),
    ...mapGetters('listing', ['products', 'brands', 'shippingRules', 'collections']),
  },
  methods: {
    // Turns a comma separated id list in the route into one chip per id.
    chipsFromList(key, list) {
      const raw = this.$route.query?.[key]
      if (!raw) {
        return []
      }
      return String(raw).split(',').filter(Boolean).map((id) => {
        const item = (list || []).find(value => `${value.id}` === `${id}`)
        return { key, value: id, label: item?.title || id }
      })
    },
    removeFilter(chip) {
      const filtered = Object.assign({}, this.$route.query)

      if (chip.key === 'price') {
        delete filtered.min
        delete filtered.max
      } else if (chip.value !== undefined) {
        const rest = String(filtered[chip.key] || '').split(',').filter(value => value && value !== `${chip.value}`)
        if (rest.length) {
          filtered[chip.key] = rest.join(',')
        } else {
          delete filtered[chip.key]
        }
      } else {
        delete filtered[chip.key]
      }

      this.changeRoute(filtered)
    },
    clearAllFilters() {
      const filtered = {}
      if (this.$route.query?.sortby) {
        filtered.sortby = this.$route.query.sortby
      }

      this.$refs.filterPrice?.clearPrice()
      this.$refs.filterShipping?.clearShipping()
      this.$refs.filterRating?.clearRating()
      this.$refs.filterAvailability?.clearAvailability()

      if (this.drawerOpen) {
        this.closeDrawer()
      }

      this.changeRoute(filtered)
    },
    // the sub category rail is a swipe carousel; the arrows appear only when
    // there is something either side to scroll to
    updateRail() {
      const track = this.$refs.rail
      if (!track) {
        this.railPrev = false
        this.railNext = false
        return
      }
      const max = track.scrollWidth - track.clientWidth
      const at = Math.abs(track.scrollLeft)
      // a few px of tolerance: sub-pixel layout can leave scrollLeft at
      // something like 0.5 when the track is genuinely at the start
      this.railPrev = at > 8
      this.railNext = max > 8 && at < max - 8
    },
    resetRail() {
      const track = this.$refs.rail
      if (track) {
        track.scrollLeft = 0
      }
      this.updateRail()
    },
    isRailRtl(track) {
      return typeof window !== 'undefined'
        && window.getComputedStyle(track).direction === 'rtl'
    },
    scrollRail(direction) {
      const track = this.$refs.rail
      if (!track) {
        return
      }
      // one full page per arrow click, as in the search popup carousel
      const step = track.clientWidth
      const offset = (this.isRailRtl(track) ? -direction : direction) * step
      if (track.scrollBy) {
        track.scrollBy({ left: offset, behavior: 'smooth' })
      } else {
        track.scrollLeft += offset
      }
    },
    onResize() {
      this.windowWidth = window.innerWidth
      this.$nextTick(this.updateRail)
      if (this.windowWidth > MOBILE_BREAKPOINT && this.drawerOpen) {
        this.closeDrawer()
      }
    },
    openDrawer(mode) {
      this.drawerMode = mode
      this.drawerOpen = true
      if (process.client) {
        this.scrollY = window.scrollY || window.pageYOffset
        document.documentElement.style.setProperty('--scroll-y', `-${this.scrollY}px`)
        document.body.classList.add('no-scroll')
      }
    },
    closeDrawer() {
      this.drawerOpen = false
      if (process.client) {
        document.body.classList.remove('no-scroll')
      }
    },
    async goingBack() {
      await this.$router.go(-1)

      this.$nextTick(() => {
        this.fetchingData()
      })
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
      this.$refs.filterAvailability?.clearAvailability()
      if (this.drawerOpen) {
        this.closeDrawer()
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
    if (process.client) {
      window.removeEventListener('resize', this.onResize)
      document.body.classList.remove('no-scroll')
    }
  },
  async mounted() {
    this.onResize()
    window.addEventListener('resize', this.onResize)
    this.$nextTick(this.resetRail)
    // the chip icons load late and change the track width
    setTimeout(this.updateRail, 300)
  }
}
</script>
