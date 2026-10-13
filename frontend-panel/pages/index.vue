<template>
  <div class="home-page">
    <div class="container-fluid">

      <home-hero
        :slider="slider"
        class="home-section"
      />

      <category-section :featured_categories="featuredCategories"/>

      <product-banner
        :banner-data="bannerData"
      />
       <!-- <products-slider
        v-for="(value, index) in productCollection"
        :key="index"
        :collection="value"
      /> -->
      <products-slider
        v-if="topCollection"
        :collection="topCollection"
        :per-view-responsive="railPerView"
      />
      <div
        class="brands-wrapper"
        :class="{'full-screen': !featuredBanner}"
      >
        <div
          class="brands-inner"
        >
          <featured-brands
            class="featured-brands"
            :title="$t('listingLayout.brands')"
            :has-featured-banner="!!featuredBanner"
            :item-list="featuredBrands"
          />
        </div>

        <div class="brand-banner">
          <banner
            v-if="featuredBanner"
            :banner="featuredBanner"
          />
        </div>
      </div>

      <lazy-area v-if="hasSiteFeatures" :root-margin="400" v-slot:default="{ renderArea }">
        <static-section
          v-if="renderArea"
          :site-features="siteFeatures"
        />
        <skeleton-card v-else :image="false" :lines="2" />
      </lazy-area>

      <lazy-area
        v-for="(value, index) in remainingCollections"
        :key="index"
        :root-margin="400"
        v-slot:default="{ renderArea }"
      >
        <products-slider
          v-if="renderArea"
          :collection="value"
          :per-view-responsive="railPerView"
        />
        <div v-else class="home-section">
          <skeleton-base :height="22" width="240px" :mb="20" />
          <skeleton-product-grid :count="4" rail label="" />
        </div>
      </lazy-area>

      <lazy-area v-if="productGrid" :root-margin="400" v-slot:default="{ renderArea }">
      <div
        v-if="renderArea"
        class="trending-box">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="slider-collection-heading mb-0">{{ productGrid.title }}</h5>
          <nuxt-link
            class="link"
            :to="collectionLink(collectionLinkObj)"
          >
            {{ $t('featured.showAll') }}
          </nuxt-link>
        </div>
        <client-only>
          <div
            ref="track"
            class="row g-3 trending-grid"
            @scroll.passive="onScroll"
          >
            <searched-product-tile
              v-for="(value, index) in productGrid.product_collections"
              :key="`prod-${index}`"
              :product="value"
            />
          </div>
        </client-only>
        <div
          v-if="showDots"
          class="m-dots"
        >
          <button
            v-for="i in pageCount"
            :key="i"
            type="button"
            class="m-dot"
            :class="{on: i - 1 === active}"
            :aria-label="String(i)"
            :aria-current="i - 1 === active ? 'true' : 'false'"
            @click="goTo(i - 1)"
          />
        </div>
      </div>
      <div v-else class="trending-box">
        <skeleton-base :height="22" width="220px" :mb="20" />
        <div class="row g-3">
          <div v-for="n in 4" :key="n" class="col-6 col-md-3">
            <skeleton-card ratio="1:1" :lines="2" />
          </div>
        </div>
      </div>
      </lazy-area>

      <lazy-area :root-margin="400" v-slot:default="{ renderArea }">
        <template v-if="renderArea">
          <banner
            v-if="banner5"
            class="home-section mb-0 br-primary flow-hidden"
            :banner="banner5"
          />

          <flashSale
            :flash-sales="flashSales"
          />

          <banner
            v-if="banner6"
            class="home-section mt-0 br-primary flow-hidden mt-4"
            :banner="banner6"
          />
        </template>

        <div v-else class="home-section">
          <skeleton-image height="220px" :radius="10" />
          <skeleton-base class="home-tail-gap" :height="22" width="200px" :mb="20" />
          <skeleton-product-grid :count="4" rail label="" />
        </div>
      </lazy-area>
    </div>


  </div>

</template>

<script>

  import LazyArea from '~/components/LazyArea'
  import HomeHero from '~/components/HomeHero'
  import {mapGetters} from 'vuex'
  import util from '~/mixin/util'
  import global from '~/mixin/global'
  import snapCarousel from '~/mixin/snapCarousel'

  import ProductBanner from "../components/ProductBanner";
  import CategorySection from '../components/CategorySection.vue'

  export default {
    middleware: ['common-middleware'],
    head() {
      return {
        link: [
          {
            rel: 'preload',
            as: 'image',
            href: this.imageURL(this.heroMain)
          },
          {
            rel: 'preload',
            as: 'image',
            href: this.imageURL(this.heroRightTop)
          },
          {
            rel: 'preload',
            as: 'image',
            href: this.imageURL(this.heroRightBottom)
          },
        ],
      }
    },
    data() {
      return {}
    },
    /*
     * Split by what the first screen actually needs.
     *
     * The hero, the category strip and the top banner are what a visitor sees
     * on arrival, so they stay in the page's own bundle. Everything below is
     * registered as a dynamic import: webpack gives each its own chunk, and
     * the chunk is only fetched when the LazyArea around it says the reader is
     * getting close. The home route therefore ships the top of the page, not
     * the whole of it.
     *
     * ImageSlider, Featured and Discover were imported here but never used in
     * the template, so they were pure weight and are gone.
     */
    components: {
      // above the fold
      ProductBanner,
      CategorySection,
      HomeHero,
      LazyArea,

      // below it
      Banner: () => import('~/components/Banner'),
      SearchedProductTile: () => import('~/components/SearchedPorductTile'),
      StaticSection: () => import('~/components/StaticSection'),
      FlashSale: () => import('~/components/FlashSale'),
      ProductsSlider: () => import('~/components/ProductsSlider'),
      FeaturedBrands: () => import('~/components/FeaturedBrands')
    },
    mixins: [util, global, snapCarousel],
    computed: {
      // glide breakpoints are max-width 1200 / 992 / 768 / 576 / 360. Below 768
      // the reference shows a peeking two-up rail, not one card per screen.
      railPerView() {
        return [4, 3, 2, 2, 2]
      },
      // the trending grid becomes a snapping track on mobile: two tiles to a
      // page, matching the product rails above it
      carouselCount() {
        return this.productGrid?.product_collections?.length || 0
      },
      carouselStep() {
        return 2
      },
      hasSiteFeatures(){
        return this.siteFeatures?.length
      },
      heroMain() {
        return this.slider.main[0]
      },
      heroRightTop() {
        return this.slider.right_top
      },
      heroRightBottom() {
        return this.slider.right_bottom
      },
      banner5() {
        return this.bannerData?.banner5
      },
      banner6() {
        return this.bannerData?.banner6
      },
      featuredBanner() {
        return this.bannerData?.banner1
      },
      // productCollection() {
      //   const col = [...this.collections]
      //   col.pop()
      //   return col
      // },
      topCollection() {
        return this.collections.length
          ? this.collections[0]
          : null
      },
      remainingCollections() {
        return this.collections.slice(1, -1)
      },
      collectionLinkObj() {
        return this.productGrid
      },
      productGrid() {
        return this.collections.slice(-1).pop()
      },
      bannerData() {
        let banner = {
          banner1: null,
          banner2: null,
          banner3: null,
          banner4: null,
          banner5: null,
          banner6: null
        }
        this.banners.forEach(i => {
          banner['banner' + this.bannerType['BANNER_' + i.type]] = i
        })
        return banner
      },
      ...mapGetters('home', ['categories','featuredCategories', 'flashSales', 'collections',
        'featuredBrands', 'slider', 'banners', 'siteFeatures'])
    },
    methods: {},
    async beforeCreate() {
    },
    async asyncData({store, error}) {
      try {
        await store.dispatch('home/fetchHome', {
          payload: null,
          lang: store.state?.language?.langCode
        })
      } catch (e) {
        error(e)
      }
    },
  }
</script>
<style>
a.block.banner-wrapper.home-section.mt-0.br-primary.flow-hidden.mt-4{
  margin-top: 20px !important
}
.area.home-section.featured-brands {
    border: 1px solid #E3E2F1;
}
.trending-box {
  background: #F3F5FC;
  border-radius: 30px;
  border: 1px solid #E3E2F1;
  padding: 20px;
}
/* Spacing inside the placeholder that stands in for the closing banners. */
.home-tail-gap {
  margin-top: 30px;
}
</style>

