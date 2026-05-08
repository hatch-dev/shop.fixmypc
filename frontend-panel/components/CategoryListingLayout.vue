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
        <div class="mt-2 mt-md-0">
          <button class="sort-btn">
            <i class="fa-solid fa-arrow-down-wide-short"></i><span class="sort-name">Sort by: Name</span>
          </button>
        </div>
      </div>
      <div class="row my-5">
        <div v-for="(value, index) in categories" :key="index" class="col-lg-3 col-md-6 mb-4">
          <nuxt-link
            :to="categoryLink(value)"
            :title="value.title"
          >
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
              <div class="sub-title">Top Category</div>
              <div>
                <span class="tag">Laptops</span>
                <span class="tag">Laptop Accessories</span>
                <span class="tag">Laptop Repair</span>
                <span class="tag">Desktop PCs</span>
              </div>
              <div class="card-footer-custom">
                <span>Browse {{ value.title }}</span>
                <span class="arrow">→</span>
              </div>
            </div>
          </nuxt-link>
        </div>
      </div>
      <div class="text-center my-5" v-if="categoryPage < categoryLastPage">
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
      <div class="row g-4">
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
      <div class="text-center my-5" v-if="brandPage < brandLastPage">
        <a @click="loadMoreBrands" class="load-more-btn">
          LOAD MORE BRANDS
          <span class="arrow">→</span>
        </a>
      </div>

      <!-- Category Insight -->
      <div class="insight-section">
        <div class="section-title">
          <h3>Category Insights</h3>
          <p>Discover trending categories and popular brands</p>
        </div>

        <div class="row g-4">
          <div class="col-lg-4">
            <div class="insight-card">
              <div class="insight-header">
                <div class="icon-box"><i class="fa-solid fa-fire"></i></div>
                <div class="insight-title">Trending Now</div>
              </div>
              <hr>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="insight-card">
              <div class="insight-header">
                <div class="icon-box"><i class="fa-regular fa-star"></i></div>
                <div class="insight-title">Top Brands</div>
              </div>
              <hr>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
            </div>
          </div>

        <div class="col-lg-4">
            <div class="insight-card">
              <div class="insight-header">
                <div class="icon-box"><i class="fa-solid fa-fire"></i></div>
                <div class="insight-title">New Arrivals</div>
              </div>
              <hr>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="insight-item">
                <div class="item-left">
                  <div class="item-icon"><img src="~assets/images/laptop.png" width="50"></div>
                  <div class="item-text">Laptop <small>+24% this week</small></div>
                </div>
                <span class="arrow"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Browse by price range -->
      <div class="price-section">
        <h3>Browse by Price Range</h3>
        <p>Find products that fit your budget</p>
      </div>

      <div class="row g-4">

        <!-- Card 1 -->
        <div class="col-lg-3 col-md-6">
          <div class="price-card">
            <div class="price-icon"><i class="fa-solid fa-euro-sign"></i></div>
            <div class="price-title">Under €25</div>
            <div class="price-sub">Budget-friendly options</div>
            <div class="price-badge">3,247 products</div>
          </div>
        </div>

        <!-- Card 2 -->
        <div class="col-lg-3 col-md-6">
          <div class="price-card">
            <div class="price-icon"><i class="fa-solid fa-tags"></i></div>
            <div class="price-title">Under €25</div>
            <div class="price-sub">Budget-friendly options</div>
            <div class="price-badge">3,247 products</div>
          </div>
        </div>

        <!-- Card 3 -->
        <div class="col-lg-3 col-md-6">
          <div class="price-card">
            <div class="price-icon"><i class="fa-solid fa-gem"></i></div>
            <div class="price-title">Under €25</div>
            <div class="price-sub">Budget-friendly options</div>
            <div class="price-badge">3,247 products</div>
          </div>
        </div>

        <!-- Card 4 -->
        <div class="col-lg-3 col-md-6">
          <div class="price-card">
            <div class="price-icon"><i class="fa-solid fa-crown"></i></div>
            <div class="price-title">Under €25</div>
            <div class="price-sub">Budget-friendly options</div>
            <div class="price-badge">3,247 products</div>
          </div>
        </div>

      </div>
    </div>
  </client-only>
</template>
<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
  import metaHelper from '~/mixin/metaHelper'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import LazyImage from "../components/LazyImage";
  import TileShimmer from "./TileShimmer";
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
      TileShimmer,
      LazyImage
    },
    data() {
      return {
        categoryResult: { data: [] },
        brandResult: { data: [] },
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
          params: { page: this.categoryPage, per_page: 12 },
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
    async mounted() {
      this.emptyCategories()
      await this.fetchingData()
      await this.fetchBrandData()
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

.category-card {
  border-radius: 20px;
  border: 1px solid #CFCEE3;
  padding: 20px;
  position: relative;
  background: #fff;
  transition: all 0.3s ease;
  overflow: hidden;
  width: auto;
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

.sub-title {
  font-size: 18px;
  margin-bottom: 10px;
  color: #130E2B;
  font-weight: 500;
}

/* Tags */
.tag {
  display: inline-block;
  border: 1px solid #E3E3EF;
  border-radius: 10px;
  padding: 6px 12px;
  font-size: 14px;
  margin: 4px 5px 4px 0;
  background: #FFFFFF;
  color: #130E2B;
  font-weight: 400;
}

/* Footer */
.card-footer-custom {
  background: #ECECF9;
  margin: 20px -20px -20px;
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
  font-size: 17;
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
  padding: 10px 0;
}

.item-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.item-icon {
  width: 35px;
  height: 35px;
  background: #f1f2f8;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}

.item-text {
  font-size: 16px;
  color: #130E2B;
  font-weight: 500;
  margin-left: 20px;
}

.item-text small {
  display: block;
  color: #6c757d;
  font-size: 12px;
}

.arrow {
  font-size: 14px;
  color: #fff;
  transition: 0.3s;
}

.insight-item:hover .arrow {
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
</style>
