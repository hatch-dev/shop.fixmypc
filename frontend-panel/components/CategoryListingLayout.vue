<template>
  <client-only>
    <div class="container-fluid ptb-20 ptb-sm-15 flow-hidden my-4">
      <div class="category-banner">
        <div class="category-content">
          <h2 class="top-banner-heading">Browse Our Categories</h2>
          <p class="top-banner-description">
            Explore thousands of products organized by category and brand.
            Find exactly what you're looking for.
          </p>
        </div>
      </div>
      <div class="category-header d-flex justify-content-between align-items-center flex-wrap my-5">
        <div>
          <h4>All Categories</h4>
          <p>Browse by category or explore brands within each section</p>
        </div>
        <div class="mt-2 mt-md-0 sort-col">
          <!-- a button with an anchored menu, the same shape as the scope
               picker in the masthead -->
          <div class="sort-scope">
            <button
              type="button"
              class="sort-scope-btn"
              aria-haspopup="listbox"
              :aria-expanded="sortMenu ? 'true' : 'false'"
              @click.prevent.stop="sortMenu = !sortMenu"
            >
              <i class="fa-solid fa-arrow-down-wide-short sort-icon"></i>
              <span class="sort-scope-label">{{ selectedSortLabel }}</span>
              <svg width="11" height="11" viewBox="0 0 12 7" fill="none" stroke="#6B7191"
                stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M.75.75 5.75 5.75 10.75.75" />
              </svg>
            </button>

            <div v-if="sortMenu" class="sort-scope-menu" role="listbox" @click.stop>
              <button
                v-for="(option, index) in sortOptions"
                :key="index"
                type="button"
                role="option"
                class="sort-scope-row"
                :class="{ on: sortBy === option.value }"
                :aria-selected="sortBy === option.value ? 'true' : 'false'"
                @click.prevent="pickSort(option.value)"
              >
                {{ option.label }}
                <svg v-if="sortBy === option.value" class="sort-scope-tick" width="16" height="16"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                  stroke-linecap="round" stroke-linejoin="round">
                  <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
              </button>
            </div>
          </div>

          <!-- on a phone a native select opens as a raw OS list, so this opens
               the same bottom sheet the header uses for its choosers -->
          <button
            type="button"
            class="sort-sheet-btn"
            aria-haspopup="dialog"
            :aria-expanded="sortSheet ? 'true' : 'false'"
            @click="openSortSheet"
          >
            <i class="fa-solid fa-arrow-down-wide-short sort-icon"></i>
            <span class="sort-sheet-label">{{ selectedSortLabel }}</span>
            <svg class="sort-sheet-caret" width="12" height="8" viewBox="0 0 12 8" fill="none" aria-hidden="true">
              <path d="M1 1.5 6 6.5l5-5" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        </div>
      </div>
      <div class="row my-5 category-grid">
        <div v-for="(value, index) in categories" :key="index" class="col-6 col-md-6 col-lg-3 mb-4">
          <div class="category-card">
            <div class="item-badge">
              {{ value.products_count || 0 }} Items
            </div>
            <div class="category-icon">
              <lazy-image
                :data-src="getImageURL(value.image)"
                :title="value.title"
                :alt="value.title"
                class="category-icon-image"
              />
            </div>
            <div class="category-title">
              {{ value.title }}
            </div>

            <hr>

            <!-- every card carries the toggle, so the grid stays even whether
                 or not the category has subcategories. What it opens is
                 whatever the API returned, which for most is nothing. -->
            <button
              type="button"
              class="subcat-toggle"
              :aria-expanded="isSubsOpen(value.id) ? 'true' : 'false'"
              @click="toggleSubs(value.id)"
            >
              <span>Top Subcategories</span>
              <svg
                class="subcat-caret"
                :class="{ open: isSubsOpen(value.id) }"
                width="11" height="7" viewBox="0 0 12 7" fill="none"
                stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true"
              >
                <path d="M.75.75 5.75 5.75 10.75.75" />
              </svg>
            </button>

            <div
              v-if="isSubsOpen(value.id) && value.children && value.children.length"
              class="cat-tags"
            >
              <span
                v-for="sub in value.children"
                :key="sub.id"
                class="tag"
              >{{ sub.title }}</span>
            </div>

            <!-- the footer link stretches over the whole card, so the card is
                 still one click target while the toggle keeps its own. A
                 button nested inside the link would have been invalid markup
                 and would have navigated on every press. -->
            <nuxt-link
              class="card-footer-custom"
              :to="categoryLink(value)"
              :title="value.title"
            >
              <span>Browse {{ value.title }}</span>
              <span class="arrow">→</span>
            </nuxt-link>
          </div>
        </div>
      </div>
      <div class="text-center my-5 load-more-wrap" v-if="categoryPage < categoryLastPage">
        <a @click="loadMoreCategories" class="load-more-btn">
          LOAD MORE CATEGORIES
          <span class="arrow">→</span>
        </a>
      </div>

      <!-- Brands -->

      <div class="brand-section my-5">
        <div class="section-title">
          <h3>Featured Brands</h3>
          <p>Shop from the world’s most trusted brands</p>
        </div>
      </div>
      <div class="row g-4 brand-grid">
        <div v-for="(brand, index) in brands" :key="index" class="col-lg-2 col-md-3 col-6">
          <nuxt-link
            class="block page-link"
            :to="brandLink(brand)"
            :title="brand.title"
          >
            <div class="brand-card">
              <lazy-image
                :data-src="getImageURL(brand.image)"
                :title="brand.title"
                :alt="brand.title"
                class="brand-icon-image"
              />
            </div>
          </nuxt-link>
        </div>
      </div>
      <div class="text-center my-5 load-more-wrap" v-if="brandPage < brandLastPage">
        <a @click="loadMoreBrands" class="load-more-btn">
          LOAD MORE BRANDS
          <span class="arrow">→</span>
        </a>
      </div>

      <!-- Category Insight -->
      <div
        class="insight-section"
        v-if="categoryInsights.length"
      >
        <div class="section-title">
          <h3>Category Insights</h3>
          <p>
            Discover trending categories and popular brands
          </p>
        </div>
        <div class="row g-4">
          <div
            class="col-lg-4"
            v-for="(section, index) in categoryInsights"
            :key="index"
          >
            <div class="insight-card">
              <div class="insight-header">
                <div class="icon-box">
                  <i :class="section.icon"></i>
                </div>
                <div class="insight-title">
                  {{ section.title }}
                </div>
              </div>
              <hr>
              <nuxt-link
                class="insight-item"
                v-for="(item, itemIndex) in section.items"
                :key="itemIndex"
                :to="getInsightLink(section, item)"
              >
                <div class="item-left">
                  <div class="item-icon">
                    <lazy-image
                      v-if="item.image"
                      :data-src="getImageURL(item.image)"
                      :alt="item.title"
                      width="35"
                      height="35"
                    />
                    <i
                      v-else
                      class="fa-solid fa-box"
                    ></i>
                  </div>
                  <div class="item-text">
                    <span class="ellipsis ellipsis-1">{{ item.title }}</span>
                    <small>
                      {{ item.subtitle }}
                    </small>
                  </div>
                </div>
                <span class="insight-arrow">
                  <i :class="item.icon"></i>
                </span>
              </nuxt-link>
            </div>
          </div>
        </div>
      </div>

      <!-- Browse by price range -->
      <div class="price-section">
        <h3>Browse by Price Range</h3>
        <p>Find products that fit your budget</p>
      </div>

      <div class="row g-4 price-grid">
        <div
          class="col-6 col-md-6 col-lg-3"
          v-for="(range, index) in priceRanges"
          :key="index"
        >
          <div
            class="price-card"
            @click="navigatePriceRange(range)"
          >
            <!-- <div class="price-card"> -->
              <div class="price-icon">
                <i :class="range.icon"></i>
              </div>
              <div class="price-title">
                {{ range.title }}
              </div>
              <div class="price-sub">
                {{ range.description }}
              </div>
              <div class="price-badge">
                {{ range.products_count }} products
              </div>
            <!-- </div> -->
          </div>
        </div>
      </div>

      <transition name="m-fade">
        <div v-if="sortSheet" class="m-sheet-scrim" @click="closeSortSheet" />
      </transition>

      <transition name="m-sheet">
        <div
          v-if="sortSheet"
          class="m-sheet"
          role="dialog"
          aria-modal="true"
          aria-label="Sort categories"
        >
          <div class="m-sheet-h">
            <span class="m-sheet-grab" aria-hidden="true" />
            <b>Sort by</b>
            <button
              type="button"
              class="m-sheet-close"
              aria-label="Close"
              @click="closeSortSheet"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.4" stroke-linecap="round">
                <path d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
          </div>

          <div class="m-sheet-b">
            <button
              v-for="(option, index) in sortOptions"
              :key="index"
              type="button"
              class="m-scope-row"
              :class="{ on: sortBy === option.value }"
              @click="pickSort(option.value)"
            >
              {{ option.label }}
              <svg v-if="sortBy === option.value" class="m-tick" width="17" height="17"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="m5 12.5 4.5 4.5L19 7.5" />
              </svg>
            </button>
          </div>
        </div>
      </transition>
    </div>
  </client-only>
</template>
<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
  import metaHelper from '~/mixin/metaHelper'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import LazyImage from "../components/LazyImage";
  import CategoryTile from "./CategoryTile";
  import Pagination from "./Pagination";
  import Spinner from "./Spinner";
  import BrandTile from "./BrandTile";
  import SubCategoryTile from "./SubCategoryTile";
  export default {
    components: {
      SubCategoryTile,
      BrandTile,
      Spinner,
      Pagination,
      CategoryTile,
      LazyImage
    },
    data() {
      return {
        sortBy: 'name_asc',
        sortSheet: false,
        sortMenu: false,
        openSubs: [],
        sortOptions: [
          {
            label: 'Name A-Z',
            value: 'name_asc'
          },
          {
            label: 'Name Z-A',
            value: 'name_desc'
          }
        ],
        categoryResult: { data: [] },
        brandResult: { data: [] },
        categoryInsights: [],
        priceRanges: [],
        fetchingCategoryData: true,
        fetchingBrandData: true,
        categoryPage: 1,
        categoryLastPage: 1,
        brandPage: 1,
        brandLastPage: 1,
      }
    },
    props: {
      subCategoriesMap: {
        type: Object,
        default: null
      },
    },
    mixins: [util, metaHelper, routeParamHelper],
    computed: {
      categories() {
        return this.categoryResult?.data || []
      },
      selectedSortLabel() {
        const option = this.sortOptions.find(o => o.value === this.sortBy)
        return option?.label || this.sortOptions[0]?.label
      },
      brands() {
        return this.brandResult?.data || []
      },
      isBrandPage(){
        return this.$route?.name?.includes('brands')
      },
      currentItems() {
        return this.result?.data || []
      },
      totalPage() {
        return this.result?.last_page
      },
      ...mapGetters('language', ['langCode']),
    },
    methods: {
      async onSortChange() {
        this.categoryPage = 1
        await this.fetchingData()
      },
      isSubsOpen(id) {
        return this.openSubs.includes(id)
      },
      toggleSubs(id) {
        const at = this.openSubs.indexOf(id)

        if (at === -1) {
          this.openSubs.push(id)
        } else {
          this.openSubs.splice(at, 1)
        }
      },
      openSortSheet() {
        this.sortSheet = true
        this.lockScroll(true)
      },
      closeSortSheet() {
        this.sortSheet = false
        this.lockScroll(false)
      },
      async pickSort(value) {
        this.sortBy = value
        this.sortMenu = false
        this.closeSortSheet()
        await this.onSortChange()
      },
      // the trigger stops propagation, so this only ever fires for clicks
      // landing outside the menu - same arrangement as the header
      closeSortMenu() {
        this.sortMenu = false
      },
      // nothing else on the page locks the body, so the sheet owns this
      lockScroll(on) {
        if (typeof document === 'undefined') return
        document.body.style.overflow = on ? 'hidden' : ''
      },
      // a rotation to tablet width hides the trigger; the sheet should not
      // stay behind on its own
      onWindowResize() {
        if (this.sortSheet && window.innerWidth > 767) {
          this.closeSortSheet()
        }
      },
      navigatePriceRange(range) {

        let query = {}

        if (range.min !== null) {
          query.min = range.min
        }

        if (range.max !== null) {
          query.max = range.max
        }

        this.$router.push({
          path: '/_main/products',
          query
        })
      },
      async fetchPriceRanges() {

        const data = await this.getRequest({
          api: 'price-ranges',
          lang: this.langCode
        })

        this.priceRanges = data?.data || []
      },
      getInsightLink(section, item) {

        if (section.title === 'Trending Now') {
          return this.productLink({
            id: item.id,
            title: item.title,
            slug: item.slug,
          })
        }

        if (section.title === 'Top Brands') {

          return this.brandLink({
            id: item.id,
            title: item.title,
            slug: item.slug
          })
        }

        if (section.title === 'New Arrivals') {

          return this.productLink({
            id: item.id,
            title: item.title,
            slug: item.slug
          })
        }

        return '#'
      },
      async fetchCategoryInsights() {
        const data = await this.getRequest({
          api: 'category-insights',
          lang: this.langCode
        })
        console.log("data", data);
        this.categoryInsights = data?.data || []
      },
      loadMoreBrands() {
        if (this.fetchingBrandData) return
        this.brandPage++
        this.fetchBrandData(true)
      },
      loadMoreCategories() {
        if (this.fetchingCategoryData) return
        this.categoryPage++
        this.fetchingData(true)
      },
      async fetchingData(loadMore = false) {
        if (!loadMore) {
          this.categoryPage = 1
          this.categoryResult = { data: [] }
        }
        this.fetchingCategoryData = true

        const data = await this.getRequest({
          params: {
            page: this.categoryPage,
            per_page: 12,
            sort: this.sortBy
          },
          lang: this.langCode,
          api: 'categories'
        })

        const response = data?.data
        this.categoryLastPage = response.last_page

        if (loadMore) {
          this.categoryResult.data = [
            ...this.categoryResult.data,
            ...response.data
          ]
        } else {
          this.categoryResult = response
        }

        this.fetchingCategoryData = false
      },
      async fetchBrandData(loadMore = false) {
        if (!loadMore) {
          this.brandPage = 1
          this.brandResult = { data: [] }
        }
        this.fetchingBrandData = true

        const data = await this.getRequest({
          params: { page: this.brandPage, per_page: 12 },
          lang: this.langCode,
          api: 'brands'
        })

        const response = data?.data
        this.brandLastPage = response.last_page
        if (loadMore) {
          this.brandResult.data = [
            ...this.brandResult.data,
            ...response.data
          ]
        } else {
          this.brandResult = response
        }

        this.fetchingBrandData = false
      },
      ...mapActions('common', ['getRequest']),
      ...mapActions('category', ['emptyCategories'])
    },
    beforeDestroy() {
      window.removeEventListener('resize', this.onWindowResize)
      document.removeEventListener('click', this.closeSortMenu)
      this.lockScroll(false)
    },
    async mounted() {
      window.addEventListener('resize', this.onWindowResize)
      document.addEventListener('click', this.closeSortMenu)
      this.emptyCategories()
      await Promise.all([
        this.fetchingData(),
        this.fetchBrandData(),
        this.fetchCategoryInsights(),
        this.fetchPriceRanges()
      ])
    }
  }
</script>
<style scoped>
.category-banner {
  background: url('~assets/images/Categrey_Top_Header.png') no-repeat center center/cover;
  border-radius: 15px;
  position: relative;
  overflow: hidden;
  padding: 60px 20px;
  color: #fff;
}

.category-banner::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.category-content {
  position: relative;
  z-index: 2;
  text-align: center;
}

.category-content h2 {
  font-size: 50px;
  font-weight: 500;
  color: #FFFFFF;
  margin-bottom: 10px;
}

.category-content p {
  font-size: 20px;
  max-width: 728px;
  margin: 0 auto;
  font-weight: 400;
  color: #FFFFFF;
}

.category-header h4 {
  margin: 0;
  font-weight: 500;
  color: #130E2B;
  font-size: 34px;
}

.category-header p {
  margin: 5px 0 0;
  font-size: 16px;
  color: #232159;
  font-weight: 400;
}

.sort-btn {
  font-size: 14px;
  border-radius: 6px;
  padding: 0px 15px;
  background: #fff;
  border: 1px solid #E3E3EF;
}
span.sort-name {
  font-size: 14px;
  font-weight: 400;
  color: #232159;
  margin-left: 5px;
}

button.sort-btn:hover {
  background-color: aqua;
}

/* ---------------------------------------------------------------------------
   Sort picker, desktop.

   Same shape as the scope dropdown in the masthead: a quiet #F7F7FD button
   that opens a bordered, shadowed menu of rows with a tick on the active one.
   Colours, radii and type sizes are taken from .fx-scope-* in Header.vue so
   the two controls read as one family. The menu aligns to the right edge
   because this control sits at the end of its row, not the start.
   --------------------------------------------------------------------------- */
.sort-scope {
  position: relative;
  display: inline-flex;
}

/* base.styl gives every button a fixed 50px height and a 48px line-height;
   these size from their own content instead */
.sort-scope-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  height: auto;
  line-height: 1.4;
  padding: 10px 14px;
  border: 1px solid #E4E5F3;
  border-radius: 10px;
  background: #F7F7FD;
  color: #3A3C6E;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  transition: background .15s, color .15s, border-color .15s;
}

.sort-scope-btn:hover {
  background: #F1F1FC;
  border-color: #CFCFF5;
  color: #33319A;
}

.sort-scope-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sort-scope-menu {
  position: absolute;
  top: 100%;
  right: 0;
  z-index: 41;
  min-width: 220px;
  margin-top: 8px;
  padding: 6px;
  border: 1px solid #E4E5F3;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 16px 40px rgba(22, 23, 74, .16);
}

.sort-scope-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  height: auto;
  padding: 9px 10px;
  border: 0;
  border-radius: 9px;
  background: none;
  color: #3A3C6E;
  font-size: 13.5px;
  line-height: 1.4;
  text-align: left;
  cursor: pointer;
}

.sort-scope-row:hover {
  background: #F7F7FD;
  color: #130E2B;
}

.sort-scope-row.on {
  background: #F1F1FC;
  color: #33319A;
  font-weight: 700;
}

.sort-scope-tick {
  flex: 0 0 auto;
  margin-left: auto;
  color: #33319A;
}

.sort-icon {
  color: #6967A0;
  font-size: 14px;
  pointer-events: none;
}

/* the sheet trigger replaces the select below 768px; see the mobile block */
.sort-sheet-btn {
  display: none;
}

/* ---------------------------------------------------------------------------
   Sort sheet.

   Same chooser the header uses for language and search scope: a native
   <select> opens as a raw OS list on a phone, which is why the header already
   moved its own pickers to a bottom sheet. The scrim, radius, grab handle and
   row treatment are copied from it deliberately, so the two read as one
   control. The header's version is absolute inside a full-screen overlay;
   this one has no such wrapper, so it fixes to the viewport instead, above
   the sticky header (z-index 50) and the mobile tab bar (45).
   --------------------------------------------------------------------------- */
.m-sheet-scrim {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  background: rgba(22, 23, 74, .42);
  z-index: 1200;
}

.m-sheet {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 1201;
  display: flex;
  flex-direction: column;
  max-height: 78%;
  background: #fff;
  border-radius: 22px 22px 0 0;
  box-shadow: 0 -8px 30px rgba(22, 23, 74, .18);
}

.m-sheet-h {
  position: relative;
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 12px;
  padding: 18px 16px 12px;
  border-bottom: 1px solid #E8E9F5;
}

.m-sheet-h b {
  flex: 1;
  font-size: 15px;
  color: #130E2B;
}

.m-sheet-grab {
  position: absolute;
  top: 7px;
  left: 50%;
  transform: translateX(-50%);
  width: 38px;
  height: 4px;
  border-radius: 999px;
  background: #CFD1E8;
}

.m-sheet-close {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 32px;
  height: 32px;
  line-height: 1;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: #F7F7FD;
  color: #3A3C6E;
  cursor: pointer;
}

.m-sheet-b {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 6px 10px calc(14px + env(safe-area-inset-bottom));
}

.m-scope-row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  height: auto;
  padding: 14px 12px;
  border: 0;
  border-radius: 12px;
  background: none;
  color: #130E2B;
  font-size: 14.5px;
  font-weight: 500;
  line-height: 1.4;
  text-align: left;
  cursor: pointer;
}

.m-scope-row:active {
  background: #F7F7FD;
}

.m-scope-row.on {
  background: #F1F1FC;
  color: #33319A;
  font-weight: 700;
}

.m-tick {
  flex: 0 0 auto;
  margin-left: auto;
  color: #33319A;
}

.m-sheet-enter-active,
.m-sheet-leave-active {
  transition: transform .26s ease;
}

.m-sheet-enter,
.m-sheet-leave-to {
  transform: translateY(100%);
}

.m-fade-enter-active,
.m-fade-leave-active {
  transition: opacity .22s ease;
}

.m-fade-enter,
.m-fade-leave-to {
  opacity: 0;
}

/* every card in a row ends level: the column stretches, the card fills it and
   the footer is pushed down by an auto top margin */
.category-card {
  border-radius: 20px;
  border: 1px solid #CFCEE3;
  padding: 20px;
  position: relative;
  background: #fff;
  transition: all 0.3s ease;
  overflow: hidden;
  width: auto;
  height: 100%;
  display: flex;
  flex-direction: column;
}

/* Hover main effect */
.category-card:hover {
  border-color: #333199;
  background-color: #F2F2FF;
}

/* Badge */
.item-badge {
  position: absolute;
  top: 20px;
  right: 20px;
  background: #E2F9E1;
  color: #130E2B;
  font-size: 14px;
  padding: 4px 13px;
  border-radius: 20px;
  font-weight: 400;
}

/* Icon */
.category-icon {
  font-size: 40px;
  color: #4a3aff;
  margin-bottom: 15px;
  transition: 0.3s;
}

/* Title */
.category-title {
  font-size: 30px;
  font-weight: 600;
  margin-bottom: 15px;
  color: #130E2B;
}

/* the toggle stands where the "Top Category" heading used to, and reads the
   same; z-index lifts it clear of the footer link's card-wide overlay */
.subcat-toggle {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  height: auto;
  line-height: 1.4;
  padding: 0;
  margin-bottom: 10px;
  border: 0;
  background: none;
  color: #130E2B;
  font-size: 18px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
}

.subcat-toggle:hover {
  color: #333199;
}

.subcat-caret {
  flex: 0 0 auto;
  color: #6B7191;
  transition: transform .2s ease;
}

.subcat-caret.open {
  transform: rotate(180deg);
}

.cat-tags {
  position: relative;
  z-index: 2;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}

/* Tags */
.tag {
  display: inline-block;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  padding: 6px 12px;
  font-size: 14px;
  background: #FFFFFF;
  color: #130E2B;
  font-weight: 400;
}

/* Footer */
.card-footer-custom {
  background: #ECECF9;
  text-decoration: none;
  margin: auto -20px -20px;
  padding: 15px 20px;
  border-radius: 0 0 20px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 500;
  transition: all 0.3s ease;
  color: #130E2B;
  font-size: 16px;
}

/* the footer link covers the card, so a click anywhere but the toggle opens
   the category - what wrapping the card in <nuxt-link> used to do */
.card-footer-custom::after {
  content: "";
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 1;
}

/* Hover footer */
.category-card:hover .card-footer-custom {
  background: #333199;
  color: #fff;
}

/* Arrow */
.arrow {
  font-size: 23px;
  transition: 0.3s;
}

.category-card:hover .arrow {
  color: #fff;
  transform: translateX(5px);
}

/* Divider */
hr {
  opacity: 0.1;
}

.category-icon-image{
  width: 100px;
  height: 100px;
}

.load-more-btn {
  background: #05B942;
  color: #fff;
  padding: 14px 30px;
  border-radius: 100px;
  font-weight: 600;
  letter-spacing: 0.5px;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  border: none;
  transition: all 0.3s ease;
  text-decoration: none;
  font-size: 17px;
  cursor: pointer;
}

.load-more-btn:hover {
  background: #15803d;
  color: #fff;
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(22, 163, 74, 0.3);
}

.arrow {
  font-size: 18px;
  transition: 0.3s;
}

.load-more-btn:hover .arrow {
  transform: translateX(5px);
}

.section-title {
  text-align: center;
  margin-bottom: 30px;
}

.section-title h3 {
  font-weight: 500;
  margin-bottom: 5px;
  font-size: 34px;
  color: #130E2B;
}

.section-title p {
  font-size: 16px;
  color: #232159;
  font-weight: 400;
}

.brand-card {
  background: #fff;
  border: 1px solid #E3E2F1;
  border-radius: 30px;
  height: 175px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
}

.brand-card img {
  max-width: 155px;
  max-height: 180px;
  object-fit: contain;
  transition: 0.3s;
}

.brand-card:hover {
  border-color: #333199;
  box-shadow: 0 8px 20px rgba(0,0,0,0.08);
  transform: translateY(-3px);
}

.brand-card:hover img {
  transform: scale(1.1);
}

.insight-section {
  background: #F9FAFC;
  padding: 40px 20px 60px 20px;
  border-radius: 24px;
}

.section-title {
  text-align: center;
  margin-bottom: 30px;
}

.section-title h3 {
  font-weight: 500;
  font-size: 34px;
  color: #130E2B;
}

.section-title p {
  font-size: 16px;
  color: #232159;
  font-weight: 400;
}

.insight-card {
  background: #fff;
  border-radius: 26px;
  padding: 30px;
  border: 1px solid #E3E2F1;
  transition: 0.3s;
}

.insight-card:hover {
  box-shadow: 0 8px 25px rgba(0,0,0,0.08);
  transform: translateY(-4px);
}

.insight-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 15px;
}

.icon-box {
  width: 40px;
  height: 40px;
  background: #333199;
  color: #fff;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.insight-title {
  font-weight: 500;
  font-size: 28px;
  color: #130E2B;
  margin-left: 10px;
}

hr {
  opacity: 0.1;
}

.insight-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
}

/* without min-width: 0 a flex item refuses to shrink below its content, so a
   long product title pushed straight through the icon beside it instead of
   letting .ellipsis-1 clamp */
.item-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1 1 auto;
  min-width: 0;
}

.item-icon {
  flex: 0 0 auto;
  width: 35px;
  height: 35px;
  padding: 5px;
  background: #f1f2f8;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}

/* the markup asks for a 35px image; in a box that is 30px on a phone it
   spilled over the title */
.item-icon img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

/* spacing comes from .item-left's gap alone - the margin here stacked on top
   of it for an uneven 30px indent */
.item-text {
  flex: 1 1 auto;
  min-width: 0;
  font-size: 16px;
  color: #130E2B;
  font-weight: 500;
}

.item-text .ellipsis-1 {
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.item-text small {
  display: block;
  color: #6c757d;
  font-size: 12px;
}

.insight-arrow {
  flex: 0 0 auto;
  font-size: 14px;
  color: #333199;
  transition: 0.3s;
}

.insight-item:hover .insight-arrow {
  color: #333199;
  transform: translateX(4px);
}

.price-section {
  text-align: center;
  margin-bottom: 40px;
}

.price-section h3 {
  font-weight: 500;
  font-size: 34px;
  color: #130E2B;
}

.price-section p {
  font-size: 16px;
  color: #232159;
  font-weight: 400;
}

    /* Card */
.price-card {
  background: #F3F5FC;
  border-radius: 16px;
  padding: 25px 15px;
  text-align: center;
  transition: 0.3s;
  border: 1px solid #E3E2F1;
  cursor: pointer;
}

.price-card:hover {
  transform: translateY(-5px);
  border-color: #333199;
  box-shadow: 0 8px 25px rgba(0,0,0,0.08);
}

.price-icon {
  width: 60px;
  height: 60px;
  background: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 15px;
  font-size: 22px;
  color: #130E2B;
}

.price-title {
  font-weight: 500;
  font-size: 24px;
  margin-bottom: 5px;
  color: #130E2B;
}

.price-sub {
  font-size: 14px;
  color: #525252;
  margin-bottom: 12px;
  font-weight: 400;
}

 .price-badge {
  display: inline-block;
  background: #24227C;
  color: #fff;
  font-size: 14px;
  padding: 8px 14px;
  border-radius: 8px;
  font-weight: 400;
}

/* ---------------------------------------------------------------------------
   Categories page, small screens.

   The component carried no media query at all: every size here was written
   for a desktop column — a 50px banner heading, 34px section headings, 30px
   category titles, 175px brand tiles and 30px card padding. Bootstrap's
   column classes already collapse the grid on a phone, but the type and the
   card chrome did not follow, so headings ran wider than the viewport and the
   padding outweighed the content. The blocks below scale those down.
   --------------------------------------------------------------------------- */

@media (max-width: 991.98px) {
  .category-banner {
    padding: 44px 20px;
  }
  .category-content h2 {
    font-size: 34px;
  }
  .category-content p {
    font-size: 17px;
  }
  .category-header h4 {
    font-size: 28px;
  }
  .section-title h3,
  .price-section h3 {
    font-size: 28px;
  }
  .category-title {
    font-size: 24px;
  }
  .insight-title {
    font-size: 22px;
  }
  .brand-card {
    height: 140px;
  }
  .brand-card img {
    max-width: 120px;
  }
}

@media (max-width: 767.98px) {
  .category-banner {
    padding: 28px 16px;
    border-radius: 12px;
  }
  .category-content h2 {
    font-size: 26px;
    line-height: 1.25;
    margin-bottom: 8px;
  }
  .category-content p {
    font-size: 14px;
  }

  /* the sort field drops onto its own full-width line: a comfortable tap
     target instead of a narrow control pinned to the right edge */
  .category-header {
    margin-top: 28px !important;
    margin-bottom: 24px !important;
    gap: 12px;
  }
  .category-header h4 {
    font-size: 22px;
  }
  .category-header p {
    font-size: 14px;
  }
  .category-header > div:last-child {
    width: 100%;
  }
  .sort-col {
    width: 100%;
  }
  .sort-scope {
    display: none;
  }
  /* the trigger keeps the field's look, so the swap is invisible until it is
     tapped. 46px clears the minimum comfortable target. */
  .sort-sheet-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    height: auto;
    min-height: 46px;
    line-height: 1.4;
    padding: 0 14px;
    border: 1px solid #E3E3EF;
    border-radius: 12px;
    background: #fff;
    color: #232159;
    font-size: 15px;
    font-weight: 500;
    text-align: left;
    cursor: pointer;
  }
  .sort-sheet-btn:active {
    border-color: #33319A;
    background: #F7F7FD;
  }
  .sort-sheet-btn .sort-icon {
    font-size: 15px;
  }
  .sort-sheet-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .sort-sheet-caret {
    flex: 0 0 auto;
    color: #6967A0;
  }

  /* two cards per row, so each one is ~165px wide at 375px: padding, icon,
     type and the footer's negative margin all step down to match */
  .category-grid {
    margin-top: 24px !important;
    margin-bottom: 24px !important;
    --bs-gutter-x: 0.75rem;
  }
  .category-grid [class*="col-"] {
    margin-bottom: 12px !important;
  }
  .category-card {
    height: 100%;
    display: flex;
    flex-direction: column;
    padding: 12px;
    border-radius: 14px;
  }
  .category-icon {
    margin-bottom: 8px;
  }
  .category-icon-image {
    width: 48px;
    height: 48px;
  }
  /* clamped, so a long category name cannot push its neighbour's footer out
     of line */
  .category-title {
    font-size: 15px;
    line-height: 1.3;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .item-badge {
    top: 10px;
    right: 10px;
    font-size: 10px;
    padding: 2px 7px;
  }
  .subcat-toggle {
    font-size: 12px;
    margin-bottom: 6px;
  }
  .tag {
    font-size: 10px;
    padding: 3px 7px;
    border-radius: 6px;
  }
  .cat-tags {
    gap: 6px;
    margin-bottom: 12px;
  }
  /* auto top margin pins the footer to the bottom of the stretched card */
  .card-footer-custom {
    margin: auto -12px -12px;
    padding: 10px 12px;
    border-radius: 0 0 14px 14px;
    font-size: 12px;
  }
  .card-footer-custom > span:first-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .card-footer-custom .arrow {
    font-size: 15px;
  }

  .load-more-wrap {
    margin-top: 24px !important;
    margin-bottom: 24px !important;
  }
  .load-more-btn {
    padding: 12px 22px;
    font-size: 14px;
  }

  .brand-section {
    margin-top: 32px !important;
    margin-bottom: 24px !important;
  }
  .section-title {
    margin-bottom: 20px;
  }
  .section-title h3,
  .price-section h3 {
    font-size: 22px;
  }
  .section-title p,
  .price-section p {
    font-size: 14px;
  }

  /* two logos per row already; a 175px tile just left them swimming */
  .brand-grid {
    --bs-gutter-x: 0.75rem;
    --bs-gutter-y: 0.75rem;
  }
  .brand-card {
    height: 96px;
    border-radius: 16px;
  }
  .brand-card img {
    max-width: 90px;
    max-height: 70px;
  }

  .insight-section {
    padding: 24px 14px 28px;
    border-radius: 16px;
  }
  .insight-section .row {
    --bs-gutter-x: 0.75rem;
    --bs-gutter-y: 0.75rem;
  }
  .insight-card {
    padding: 18px;
    border-radius: 16px;
  }
  .insight-header {
    margin-bottom: 10px;
  }
  .icon-box {
    width: 34px;
    height: 34px;
    border-radius: 8px;
  }
  .insight-title {
    font-size: 18px;
    margin-left: 4px;
  }
  .insight-item {
    gap: 10px;
  }
  .item-left {
    gap: 10px;
  }
  .item-icon {
    width: 30px;
    height: 30px;
    padding: 4px;
  }
  .item-text {
    font-size: 14px;
  }

  /* two price bands per row, so all four stay within one screen */
  .price-section {
    margin-bottom: 24px;
  }
  .price-grid {
    --bs-gutter-x: 0.75rem;
    --bs-gutter-y: 0.75rem;
  }
  .price-card {
    padding: 18px 12px;
    border-radius: 14px;
  }
  .price-icon {
    width: 46px;
    height: 46px;
    margin-bottom: 10px;
    font-size: 18px;
  }
  .price-title {
    font-size: 17px;
  }
  .price-sub {
    font-size: 12px;
    margin-bottom: 10px;
  }
  .price-badge {
    font-size: 12px;
    padding: 6px 10px;
  }

  /* a lift on :hover is noise on touch, where there is no hover to leave */
  .category-card:hover,
  .brand-card:hover,
  .insight-card:hover,
  .price-card:hover,
  .load-more-btn:hover {
    transform: none;
    box-shadow: none;
  }
}

@media (max-width: 400px) {
  .category-content h2 {
    font-size: 22px;
  }
  .category-card {
    padding: 10px;
  }
  .category-icon-image {
    width: 42px;
    height: 42px;
  }
  .category-title {
    font-size: 14px;
  }
  .card-footer-custom {
    margin: auto -10px -10px;
    padding: 9px 10px;
    font-size: 11px;
  }
  .price-title {
    font-size: 15px;
  }
  .price-badge {
    padding: 5px 8px;
  }
}
</style>
