<template>
  <div class="area home-section">
    <template v-if="brands && brands.length">

      <div class="flex sided title">
        <h4 class="slider-collection-heading">{{ $t('filter.featured', {title: title}) }}</h4>
        <nuxt-link
          class="link"
          to="/brands"
        >
          {{ $t('featured.showAll') }}
        </nuxt-link>
      </div>
      <div class="area-content">
          <image-slider
            :image-count="brands.length"
            :per-view="sliderOptions.perView"
            :gap="15"
            :responsive="sliderOptions.responsive"
            @glide="onGlide"
          >
            <template v-slot:content>
              <li
                v-for="(value, index) in brands"
                :key="index"
                class="center-text"
              >
                <brand-tile
                  v-for="(v, i) in value"
                  :key="i"
                  :brand="v"
                />
              </li>
            </template>
          </image-slider>

      </div>

      <div
        v-if="pageCount > 1"
        class="m-dots"
      >
        <button
          v-for="i in pageCount"
          :key="i"
          type="button"
          class="m-dot"
          :class="{on: i - 1 === activePage}"
          :aria-label="String(i)"
          :aria-current="i - 1 === activePage ? 'true' : 'false'"
          @click="goToPage(i - 1)"
        />
      </div>
    </template>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import ImageSlider from '~/components/ImageSlider'
  import LazyImage from '~/components/LazyImage'
  import CategoryTile from '~/components/CategoryTile'
  import BrandTile from "./BrandTile";
  import glideDots from '~/mixin/glideDots'
    export default {
        name: 'FeaturedBrands',
        data() {
            return {
              brands: []
            }
        },
        watch: {
        },
        props: {
          type: {
            type: String,
            default: 'brand'
          },
          hasFeaturedBanner: {
            type: Boolean,
            default: false
          },
          title: {
            type: String,
            default: ''
          },
          itemList: {
            type: Array,
            default(){
              return []
            }
          }
        },
        components: {
          BrandTile,
          ImageSlider,
          LazyImage,
          CategoryTile
        },
        computed: {
          sliderOptions(){
            if(this.hasFeaturedBanner){
              return {
                perView: 3,
                responsive: [5, 5, 4, 3, 2]
              }
            }
            return {
              perView: 6,
              responsive: [6, 5, 4, 3, 2]
            }
          },
          isBrand(){
            return this.type === 'brand'
          },
          // one slide per pair of brands, not per brand
          glideItemCount(){
            return this.brands.length
          }
        },
        mixins: [util, glideDots],
        methods: {
        },
        created() {
        },
        mounted() {
          // Two brands to a slide. The previous loop reset its accumulator on
          // index 0 - before that first brand had been paired with anything -
          // so the first brand was silently dropped and every pair was off by
          // one. That also cost a whole slide, which is why the rail often had
          // nothing left to page through.
          const list = this.itemList || []
          const pairs = []
          for (let i = 0; i < list.length; i += 2) {
            pairs.push(list.slice(i, i + 2))
          }
          this.brands = pairs
        }
    }
</script>

