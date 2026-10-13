<template>
  <div
    v-if="siteFeatures && siteFeatures.length"
    class="static-section"
  >
    <div
      ref="track"
      class="row g-4 mt-4 mb-4 static-features"
      @scroll.passive="onScroll"
    >

      <site-feature
        v-for="(value, i) in siteFeatures"
        :key="i"
        :site-feature="value"
        :index="i"
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
  import SiteFeature from "./SiteFeature";
  import snapCarousel from "~/mixin/snapCarousel";
  export default {
    name: 'StaticSection',
    data() {
      return {

      }
    },
    watch: {


    },
    props: {
      siteFeatures: {
        type: Array,
        default: []
      }
    },
    components: {
      SiteFeature

    },
    computed: {
      carouselCount() {
        return this.siteFeatures?.length || 0
      }
      // no carouselAutoplay: these are reference cards, and the page already
      // has one track advancing itself
    },
    mixins: [snapCarousel],
    methods: {

    },
    created() {
    },
    async mounted() {
    }


  }
</script>
