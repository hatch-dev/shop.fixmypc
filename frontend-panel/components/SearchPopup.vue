<template>
  <div class="search-popup" @mousedown.prevent>
    <div class="sb popup-inner sp-panel">
      <div class="pop-over-content sp-body">

        <!--
          Search results are a list, so the waiting state is a list too. The
          popup is a fixed panel: a spinner centred in it made the whole thing
          flash grey between keystrokes, where rows that stay put read as the
          same list being refined.
        -->
        <div
          v-if="fetchingData"
          class="sp-results sp-results-loading"
        >
          <skeleton-list
            :rows="6"
            :avatar-size="48"
            :lines="2"
          />
        </div>

        <div
          v-else-if="matchedResult"
          class="sp-results"
        >
          <section
            v-if="visibleSuggested.length"
            class="sp-section"
          >
            <div class="sp-section-head">
              <h4 class="sp-section-title">
                {{ $t('searchPopup.suggestedSearch') }}
              </h4>
            </div>
            <div
              class="sp-carousel"
              :class="{ 'has-prev': carousel.suggested.prev, 'has-next': carousel.suggested.next }"
            >
              <button
                v-show="carousel.suggested.prev"
                class="sp-nav sp-nav--prev"
                type="button"
                aria-label="previous"
                @click.prevent="scrollCarousel('suggested', -1)"
              >
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="M9 2 4 7l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
                </svg>
              </button>

              <div
                class="sp-track"
                ref="suggested"
                @scroll.passive="updateCarousel('suggested')"
              >
                <button
                  v-for="(value, index) in visibleSuggested"
                  :key="value.id || `sug-${index}`"
                  @click.prevent="makeSearch(value.title)"
                  class="sp-chip"
                  type="button"
                  aria-label="search"
                >
                  <svg class="sp-chip-icon" width="12" height="12" viewBox="0 0 17 17" fill="none" aria-hidden="true">
                    <path
                      d="M15.75 15.75L12.125 12.125M14.0833 7.41667C14.0833 11.0986 11.0986 14.0833 7.41667 14.0833C3.73477 14.0833 0.75 11.0986 0.75 7.41667C0.75 3.73477 3.73477 0.75 7.41667 0.75C11.0986 0.75 14.0833 3.73477 14.0833 7.41667Z"
                      stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <span class="sp-chip-text">{{ value.title }}</span>
                </button>
              </div>

              <button
                v-show="carousel.suggested.next"
                class="sp-nav sp-nav--next"
                type="button"
                aria-label="next"
                @click.prevent="scrollCarousel('suggested', 1)"
              >
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="M5 2l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
                </svg>
              </button>
            </div>
          </section>

          <section
            v-if="categories.length || subCategories.length"
            class="sp-section"
          >
            <div class="sp-section-head">
              <h4 class="sp-section-title">
                {{ $t('searchPopup.categories') }}
              </h4>
            </div>
            <div
              class="sp-carousel"
              :class="{ 'has-prev': carousel.categories.prev, 'has-next': carousel.categories.next }"
            >
              <button
                v-show="carousel.categories.prev"
                class="sp-nav sp-nav--prev"
                type="button"
                aria-label="previous"
                @click.prevent="scrollCarousel('categories', -1)"
              >
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="M9 2 4 7l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
                </svg>
              </button>

              <div
                class="sp-track"
                ref="categories"
                @scroll.passive="updateCarousel('categories')"
              >
                <router-link
                  v-for="(value, index) in categories"
                  :key="value.id || `c-${index}`"
                  :to="categoryLink(value)"
                  @click.native="$emit('close')"
                  class="sp-chip sp-chip--category"
                >
                  <span class="sp-chip-thumb">
                    <lazy-image
                      :data-src="imageURL(value)"
                      :title="value.title"
                      :alt="value.title"
                      height="28"
                      width="28"
                    />
                  </span>
                  <span class="sp-chip-text">{{ value.title }}</span>
                </router-link>

                <router-link
                  v-for="(value, index) in subCategories"
                  :key="value.id || `sc-${index}`"
                  :to="subCategoryLink(value, value.category)"
                  @click.native="$emit('close')"
                  class="sp-chip sp-chip--category"
                >
                  <span class="sp-chip-thumb">
                    <lazy-image
                      :data-src="imageURL(value)"
                      :title="value.title"
                      :alt="value.title"
                      height="28"
                      width="28"
                    />
                  </span>
                  <span class="sp-chip-text">{{ value.title }}</span>
                </router-link>
              </div>

              <button
                v-show="carousel.categories.next"
                class="sp-nav sp-nav--next"
                type="button"
                aria-label="next"
                @click.prevent="scrollCarousel('categories', 1)"
              >
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="M5 2l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
                </svg>
              </button>
            </div>
          </section>

          <section
            v-if="products.length"
            class="sp-section sp-section--products"
          >
            <div class="sp-section-head">
              <h4 class="sp-section-title">
                {{ $t('searchPopup.products') }}
              </h4>
              <span class="sp-section-count">{{ products.length }}</span>
            </div>
            <div
              class="sp-carousel"
              :class="{ 'has-prev': carousel.products.prev, 'has-next': carousel.products.next }"
            >
              <button
                v-show="carousel.products.prev"
                class="sp-nav sp-nav--prev"
                type="button"
                aria-label="previous"
                @click.prevent="scrollCarousel('products', -1)"
              >
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="M9 2 4 7l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
                </svg>
              </button>

              <div
                class="sp-track sp-track--products"
                ref="products"
                @scroll.passive="updateCarousel('products')"
              >
                <searched-popup-product-tile
                  v-for="(value, index) in products"
                  :key="value.id || `prod-${index}`"
                  :product="value"
                  class="sp-slide"
                  :style="productSlideStyle"
                  @click.native="$emit('close')"
                />
              </div>

              <button
                v-show="carousel.products.next"
                class="sp-nav sp-nav--next"
                type="button"
                aria-label="next"
                @click.prevent="scrollCarousel('products', 1)"
              >
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                  <path d="M5 2l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
                </svg>
              </button>
            </div>
          </section>
        </div>

        <div
          v-else
          class="sp-empty"
        >
          <svg class="sp-empty-icon" width="34" height="34" viewBox="0 0 17 17" fill="none" aria-hidden="true">
            <path
              d="M15.75 15.75L12.125 12.125M14.0833 7.41667C14.0833 11.0986 11.0986 14.0833 7.41667 14.0833C3.73477 14.0833 0.75 11.0986 0.75 7.41667C0.75 3.73477 3.73477 0.75 7.41667 0.75C11.0986 0.75 14.0833 3.73477 14.0833 7.41667Z"
              stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <p class="sp-empty-text">
            {{ $t('searchPopup.nothingFound') }}
            <span class="sp-empty-term">"{{ searchedText }}"</span>
          </p>
        </div>

      </div>

      <button
        v-if="!fetchingData && matchedResult && searchedText"
        class="sp-footer"
        type="button"
        @click.prevent="makeSearch(searchedText)"
      >
        <span class="sp-footer-text">
          {{ $t('featured.showAll') }}
          <span class="sp-footer-term">"{{ searchedText }}"</span>
        </span>
        <svg class="sp-footer-arrow" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <path d="M2 7h10m0 0L8 3m4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
            stroke-linejoin="round" />
        </svg>
      </button>
    </div>
  </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import {debounce} from "debounce";
  import SearchedPopupProductTile from './SearchedPopupProductTile.vue';
  import LazyImage from "./LazyImage";

  export default {
    name: 'SearchPopup',
    components: {LazyImage, SearchedPopupProductTile},
    directives: {},
    props: {
      searchedText: {
        type: String,
        default: '',
      }
    },
    mixins: [util],
    watch: {
      searchedText: debounce(function (value) {
        if(value){
          this.fetchData()
        }else{
          this.$emit('close')
        }
      }, 700),
      searchedSuggestion() {
        this.refreshCarousels()
      }
    },
    computed: {
      matchedResult(){
        return this.products.length || this.suggested.length ||this.subCategories.length || this.categories.length
      },
      products(){
        return this.searchedSuggestion?.product || []
      },
      suggested(){
        return this.searchedSuggestion?.suggested || []
      },
      visibleSuggested(){
        return this.suggested.slice(0, 12)
      },
      productSlideStyle(){
        if(!this.productSlideWidth){
          return {}
        }

        return {
          flex: `0 0 ${this.productSlideWidth}px`,
          maxWidth: `${this.productSlideWidth}px`,
        }
      },
      subCategories(){
        return this.searchedSuggestion?.sub_category || []
      },
      categories(){
        return this.searchedSuggestion?.category || []
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('listing', ['searchedSuggestion', 'searched']),
    },
    data() {
      return {
        fetchingData: false,
        productSlideWidth: 0,
        carousel: {
          suggested: {prev: false, next: false},
          categories: {prev: false, next: false},
          products: {prev: false, next: false},
        }
      }
    },
    methods: {
      makeSearch(searched){
        this.$emit('close')
        // if(searched !== this.searched){
          this.$router.push({ path: '/search', query: { q: searched }})
          this.updateSearch(searched)
        // }
      },
      currentPricing(value){
        return value?.offered ? value?.offered : value?.selling
      },
      carouselTrack(name){
        const track = this.$refs[name]

        return Array.isArray(track) ? track[0] : track
      },
      updateCarousel(name){
        const track = this.carouselTrack(name)
        const state = this.carousel[name]

        if(!track){
          state.prev = false
          state.next = false

          return
        }

        // abs() keeps this working in rtl, where scrollLeft counts down from 0
        const maxScroll = track.scrollWidth - track.clientWidth
        const position = Math.abs(track.scrollLeft)

        state.prev = position > 4
        state.next = position < maxScroll - 4
      },
      isRtl(track){
        return typeof window !== 'undefined'
          && window.getComputedStyle(track).direction === 'rtl'
      },
      scrollCarousel(name, direction){
        const track = this.carouselTrack(name)

        if(!track){
          return
        }

        // one full page per arrow click
        const step = track.clientWidth
        const offset = (this.isRtl(track) ? -direction : direction) * step

        if(track.scrollBy){
          track.scrollBy({left: offset, behavior: 'smooth'})
        }else{
          track.scrollLeft += offset
        }
      },
      // The popup is only as wide as the search field, so a hard-coded
      // "4 per view" shrinks cards until the titles are unreadable. Fit as
      // many whole cards as the track can hold at a readable card width.
      measureProductSlide(){
        const track = this.carouselTrack('products')

        if(!track || !track.clientWidth){
          return
        }

        const gap = 10
        const minCardWidth = 230
        const available = track.clientWidth - 2
        const perView = Math.max(1, Math.floor((available + gap) / (minCardWidth + gap)))

        this.productSlideWidth = Math.floor((available - ((perView - 1) * gap)) / perView)
      },
      refreshCarousels(){
        this.$nextTick(() => {
          this.measureProductSlide()

          this.$nextTick(() => {
            this.updateCarousel('suggested')
            this.updateCarousel('categories')
            this.updateCarousel('products')
          })
        })
      },
      async fetchData() {
        this.fetchingData = true
        try {
          await this.fetchSearchedSuggestion({
            params: { q: this.searchedText },
            lang: this.langCode
          })

          this.fetchingData = false
          this.refreshCarousels()
        } catch (e) {
          this.fetchingData = false

          return this.$nuxt.error(e)
        }
      },
      ...mapActions('listing', ['fetchSearchedSuggestion', 'updateSearch']),
    },
    async mounted() {
      window.addEventListener('resize', this.refreshCarousels)

      if(!this.searchedSuggestion){
        await this.fetchData()
      }

      this.refreshCarousels()
    },
    destroyed() {
      window.removeEventListener('resize', this.refreshCarousels)
    }
  }
</script>

<style scoped>
  /* Scoped to this component only - no global stylesheet is modified. */
  .sp-panel {
    border: 1px solid #E8E8F3;
    border-radius: 14px;
    box-shadow: 0 18px 44px rgba(19, 14, 43, .14);
    /* breathing room between the search field and the dropdown */
    margin-top: 10px;
    max-height: 72vh;
    max-width: 1000px;
    min-width: 320px;
    overflow-x: hidden;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #CFCFE4 transparent;
    width: 100%;
  }

  .sp-panel::-webkit-scrollbar {
    width: 6px;
  }

  .sp-panel::-webkit-scrollbar-thumb {
    background: #CFCFE4;
    border-radius: 10px;
  }

  .sp-body {
    min-height: 140px;
    padding: 6px 0 0;
    position: relative;
  }

  .sp-section {
    padding: 14px 18px;
  }

  .sp-section + .sp-section {
    border-top: 1px solid #F0F0F7;
  }

  .sp-section-head {
    align-items: center;
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
  }

  .sp-section-title {
    color: #75748F;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    line-height: 1;
    margin: 0;
    text-transform: uppercase;
  }

  .sp-section-count {
    background: #F1F1FA;
    border-radius: 20px;
    color: #4A4A6A;
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
    padding: 4px 8px;
  }

  /* Products keep their own tinted card so the block looks the same on
     every page (the old `trending-box` class came from pages/index.vue). */
  .sp-section--products {
    background: #F7F8FD;
    border-radius: 16px;
    margin: 0 14px 14px;
    padding: 14px;
  }

  .sp-section + .sp-section--products {
    border-top: none;
  }

  /* Carousel: a fixed number of slides per view, one page per arrow click. */
  .sp-carousel {
    position: relative;
  }

  .sp-carousel::before,
  .sp-carousel::after {
    bottom: 0;
    content: '';
    opacity: 0;
    pointer-events: none;
    position: absolute;
    top: 0;
    transition: opacity .18s ease;
    width: 34px;
    z-index: 1;
  }

  .sp-carousel::before {
    background: linear-gradient(90deg, #fff 25%, rgba(255, 255, 255, 0));
    left: 0;
  }

  .sp-carousel::after {
    background: linear-gradient(270deg, #fff 25%, rgba(255, 255, 255, 0));
    right: 0;
  }

  .sp-section--products .sp-carousel::before {
    background: linear-gradient(90deg, #F7F8FD 25%, rgba(247, 248, 253, 0));
  }

  .sp-section--products .sp-carousel::after {
    background: linear-gradient(270deg, #F7F8FD 25%, rgba(247, 248, 253, 0));
  }

  .sp-carousel.has-prev::before,
  .sp-carousel.has-next::after {
    opacity: 1;
  }

  .sp-track {
    -ms-overflow-style: none;
    display: flex;
    flex-wrap: nowrap;
    gap: 10px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 2px 1px;
    scroll-behavior: smooth;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
  }

  .sp-track::-webkit-scrollbar {
    display: none;
  }

  /* Product cards get their width from measureProductSlide() so a whole
     number of readable cards fits the track. Chips keep their natural size. */
  .sp-slide {
    flex: 0 0 240px;
    min-width: 0;
    scroll-snap-align: start;
  }

  .sp-track > .sp-chip {
    flex: 0 0 auto;
    max-width: 240px;
    scroll-snap-align: start;
  }

  .sp-nav {
    align-items: center;
    background: #fff;
    border: 1px solid #E4E4F0;
    border-radius: 50%;
    box-shadow: 0 2px 8px rgba(19, 14, 43, .14);
    color: #333199;
    cursor: pointer;
    display: flex;
    height: 28px;
    justify-content: center;
    padding: 0;
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    transition: background .18s ease, border-color .18s ease;
    width: 28px;
    z-index: 2;
  }

  /* invisible padding so the small arrow is still a comfortable tap target */
  .sp-nav::after {
    bottom: -9px;
    content: '';
    left: -9px;
    position: absolute;
    right: -9px;
    top: -9px;
  }

  .sp-nav:hover,
  .sp-nav:active {
    background: #F4F4FD;
    border-color: #333199;
  }

  .sp-nav--prev {
    left: -14px;
  }

  .sp-nav--next {
    right: -14px;
  }

  .sp-chip {
    align-items: center;
    background: #fff;
    border: 1px solid #E4E4F0;
    border-radius: 40px;
    color: #232159;
    cursor: pointer;
    display: inline-flex;
    font-size: 13px;
    font-weight: 500;
    gap: 7px;
    line-height: 1.2;
    padding: 7px 13px;
    text-align: left;
    text-decoration: none;
    transition: background .18s ease, border-color .18s ease, color .18s ease;
  }

  .sp-chip:hover {
    background: #F4F4FD;
    border-color: #333199;
    color: #333199;
  }

  .sp-chip:focus-visible {
    background: #F4F4FD;
    border-color: #333199;
    color: #333199;
  }

  .sp-chip-icon {
    color: #9C9CBA;
    flex-shrink: 0;
  }

  .sp-chip:hover .sp-chip-icon {
    color: #333199;
  }

  .sp-chip-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .sp-chip--category {
    padding: 5px 14px 5px 5px;
  }

  .sp-chip-thumb {
    align-items: center;
    background: #F5F5FC;
    border-radius: 50%;
    display: flex;
    flex-shrink: 0;
    height: 30px;
    justify-content: center;
    overflow: hidden;
    width: 30px;
  }

  .sp-chip-thumb >>> img {
    height: 22px;
    object-fit: contain;
    width: 22px;
  }

  .sp-empty {
    align-items: center;
    display: flex;
    flex-direction: column;
    gap: 10px;
    justify-content: center;
    padding: 34px 20px;
    text-align: center;
  }

  .sp-empty-icon {
    color: #C4C4DA;
  }

  .sp-empty-text {
    color: #4A4A6A;
    font-size: 14px;
    margin: 0;
  }

  .sp-empty-term {
    color: #333199;
    font-weight: 600;
  }

  .sp-footer {
    align-items: center;
    background: #fff;
    border: none;
    border-top: 1px solid #F0F0F7;
    bottom: 0;
    color: #333199;
    cursor: pointer;
    display: flex;
    font-size: 13.5px;
    font-weight: 600;
    gap: 8px;
    justify-content: center;
    padding: 13px 18px;
    position: sticky;
    transition: background .18s ease;
    width: 100%;
  }

  .sp-footer:hover {
    background: #F4F4FD;
  }

  .sp-footer-term {
    color: #130E2B;
  }

  .sp-footer-arrow {
    transition: transform .18s ease;
  }

  .sp-footer:hover .sp-footer-arrow {
    transform: translateX(3px);
  }

  @media only screen and (max-width: 820px) {
    .sp-section {
      padding: 12px 14px;
    }

    .sp-chip {
      font-size: 12.5px;
      padding: 6px 11px;
    }

    /* Arrows stay on touch too, just smaller and tucked inside the padding
       so they never hang off the panel edge. */
    .sp-nav {
      height: 26px;
      width: 26px;
    }

    .sp-nav--prev {
      left: -10px;
    }

    .sp-nav--next {
      right: -10px;
    }

    .sp-carousel::before,
    .sp-carousel::after {
      width: 28px;
    }
  }
</style>
