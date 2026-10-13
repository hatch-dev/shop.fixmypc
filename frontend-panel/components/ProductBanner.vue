<template>
  <div
    v-if="banners.length"
    class="product-banner-carousel"
  >
    <div
      ref="track"
      class="product-banner mb-4"
      @scroll.passive="onScroll"
    >
      <banner
        v-for="(value, index) in banners"
        :key="index"
        :banner="value"
      />
    </div>

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
</template>

<script>

  import Banner from "~/components/Banner";
  import util from "~/mixin/util";
  import snapCarousel from "~/mixin/snapCarousel";
  export default {
    name: 'ProductBanner',
    data() {
      return {}
    },
    props: {
      bannerData: {
        type: Object,
        default() {
          return null
        },
      },
    },
    components: {
      Banner
    },
    computed: {
      // Banner renders nothing for a non-public banner, so filter on the same
      // check here - otherwise the carousel would carry a dot for a slide that
      // never appears.
      banners() {
        return [
          this.bannerData?.banner2,
          this.bannerData?.banner3,
          this.bannerData?.banner4
        ].filter(b => b && parseInt(b.status) === this.status.PUBLIC)
      },
      carouselCount() {
        return this.banners.length
      },
      carouselAutoplay() {
        return 6000
      }
    },
    mixins: [util, snapCarousel],
    methods: {}
  }
</script>

<style scoped>
/* Phone and small tablet only - the desktop row in product-banner.styl is
   untouched. One banner per screen on a snapping track, the way the hero
   works in reference/shop_mobile_flow_v2.html. */
@media only screen and (max-width: 991px) {
  .product-banner {
    display: flex;
    flex-direction: row;
    gap: 10px;
    margin: 0;
    overflow-x: auto;
    overscroll-behavior-x: contain;
    position: relative;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
  }

  .product-banner::-webkit-scrollbar {
    display: none;
  }

  /* the child component root carries this scope id, so it is reachable here */
  .product-banner > .banner-wrapper {
    flex: 0 0 100%;
    margin: 0;
    scroll-snap-align: start;
  }

}

@media (prefers-reduced-motion: reduce) {
  .product-banner {
    scroll-behavior: auto;
  }

}
</style>
