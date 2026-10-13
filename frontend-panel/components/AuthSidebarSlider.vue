<template>

  <aside
    class="auth-stage"
    aria-label="Offers"
  >

    <!-- Branded placeholder: shown until an admin adds a banner, and behind
         any banner that has copy but no artwork. -->
    <div
      v-if="!slides.length"
      class="auth-stage-ph"
    />

    <div
      v-for="(slide, index) in slides"
      :key="slide.key"
      class="auth-slide"
      :class="{ on: currentSlide === index }"
    >

      <div class="auth-slide-media">

        <!-- Backdrop under the artwork, so a banner whose image does not fill
             the stage shows the colours the admin chose around it. -->
        <div
          class="auth-stage-ph"
          :style="placeholderStyle(slide)"
        />

        <img
          v-if="slide.image"
          :src="getImageURL(slide.image)"
          :style="slide.imageStyle"
          alt=""
        >

      </div>

      <div class="auth-slide-scrim" />

      <div
        v-if="slide.title || slide.eyebrow || slide.sub || slide.cta"
        class="auth-slide-body"
        :style="{ color: slide.textColor }"
      >

        <span
          v-if="slide.eyebrow"
          class="auth-slide-eyebrow"
        >
          {{ slide.eyebrow }}
        </span>

        <h2
          v-if="slide.title"
          class="auth-slide-title"
        >
          {{ slide.title }}
        </h2>

        <p
          v-if="slide.sub"
          class="auth-slide-sub"
        >
          {{ slide.sub }}
        </p>

        <a
          v-if="slide.cta && slide.href"
          class="auth-slide-cta"
          :href="slide.href"
          :target="slide.newTab ? '_blank' : null"
          :rel="slide.newTab ? 'noopener noreferrer' : null"
        >
          {{ slide.cta }}
        </a>

        <span
          v-else-if="slide.cta"
          class="auth-slide-cta"
        >
          {{ slide.cta }}
        </span>

      </div>

    </div>

    <div
      v-if="slides.length > 1"
      class="auth-stage-nav"
    >

      <button
        v-for="(slide, index) in slides"
        :key="`tick-${slide.key}`"
        type="button"
        class="auth-stage-tick"
        :class="{ on: currentSlide === index }"
        :aria-label="`Slide ${index + 1}`"
        @click="switchToSlide(index)"
      />

    </div>

  </aside>

</template>

<script>

import util from '~/mixin/util'

/* A banner saved before image placement existed sends these as null. */
const num = (value, fallback) =>
  (value === null || value === undefined || value === '') ? fallback : Number(value)

export default {

  name: "AuthSidebarSlider",

  mixins: [util],

  props: {

    autoPlay: {
      type: Boolean,
      default: true
    },

    interval: {
      type: Number,
      default: 7000
    }
  },

  data() {
    return {
      slides: [],
      currentSlide: 0,
      sliderInterval: null
    }
  },

  computed: {

    /**
     * Which auth screen is asking, so the admin can target a banner at one
     * of them. Anything unrecognised gets the shared set.
     */
    placement() {

      const name = this.$route?.name?.split('___')[0] || ''

      const byRoute = {
        'login': 'login',
        'register': 'signup',
        'forgot-password': 'forgot',
        'reset-password': 'reset'
      }

      return byRoute[name] || 'auth'
    }
  },

  methods: {

    placeholderStyle(slide) {

      if (!slide.bgFrom && !slide.bgTo) {
        return {}
      }

      const from = slide.bgFrom || '#2D2DA0'
      const to = slide.bgTo || '#7A7AE0'

      return {backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)`}
    },

    switchToSlide(index) {
      this.currentSlide = index
      this.restartSlider()
    },

    nextSlide() {

      if (this.currentSlide >= this.slides.length - 1) {
        this.currentSlide = 0
      } else {
        this.currentSlide++
      }
    },

    startSlider() {

      if (!this.autoPlay || this.slides.length < 2) return

      this.sliderInterval = setInterval(() => {
        this.nextSlide()
      }, this.interval)
    },

    stopSlider() {

      if (this.sliderInterval) {
        clearInterval(this.sliderInterval)
        this.sliderInterval = null
      }
    },

    restartSlider() {
      this.stopSlider()
      this.startSlider()
    },

    baseUrl() {
      return process.env.apiBase || 'https://shop.fixmypc.ie/'
    },

    async getSlides() {

      try {

        const {data} = await this.$axios.get(
          `${this.baseUrl()}api/v1/auth-banners`,
          {params: {placement: this.placement}}
        )

        this.slides = (data?.data || []).map(banner => ({
          key: `banner-${banner.id}`,
          image: banner.image,
          imageStyle: {
            left: `${num(banner.image_x, 50)}%`,
            top: `${num(banner.image_y, 50)}%`,
            width: `${num(banner.image_w, 100)}%`,
            height: `${num(banner.image_h, 100)}%`
          },
          bgFrom: banner.bg_from,
          bgTo: banner.bg_to,
          eyebrow: banner.eyebrow,
          title: banner.title,
          sub: banner.sub,
          cta: banner.cta,
          href: banner.href,
          newTab: banner.href_new_tab === 1,
          textColor: banner.text_color || '#FFFFFF'
        }))

      } catch (e) {
        this.slides = []
      }
    }
  },

  async mounted() {
    await this.getSlides()
    this.startSlider()
  },

  beforeDestroy() {
    this.stopSlider()
  }
}
</script>

<style>

.auth-stage {
    position: relative;
    overflow: hidden;
    background: #F7F7FD;
    isolation: isolate;
}

/* ── slides ── */
.auth-slide {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: flex-start;
    text-align: left;
    padding: 44px;
    opacity: 0;
    transform: scale(1.03);
    transition: opacity .7s ease, transform 1.2s ease;
    pointer-events: none;
}

.auth-slide.on {
    opacity: 1;
    transform: scale(1);
    pointer-events: auto;
}

.auth-slide-media {
    position: absolute;
    inset: 0;
    z-index: -2;
    overflow: hidden;
}

/* The box's centre and size come inline from the placement set in the admin.
 * `fill` rather than `cover`, so the artwork is never cropped — it fills
 * exactly the frame the admin stretched it to. */
.auth-slide-media img {
    position: absolute;
    transform: translate(-50%, -50%);
    object-fit: fill;
    display: block;
}

.auth-slide-scrim {
    position: absolute;
    inset: 0;
    z-index: -1;
    background: linear-gradient(180deg, rgba(12, 13, 45, .10) 0%, rgba(12, 13, 45, .30) 45%, rgba(12, 13, 45, .82) 100%);
}

/* The colour comes inline from the banner's own text_color. */
.auth-slide-body {
    color: #fff;
    max-width: 520px;
}

.auth-slide-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: inherit;
    margin-bottom: 14px;
    background: rgba(255, 255, 255, .16);
    border: 1px solid currentColor;
    padding: 6px 12px;
    border-radius: 999px;
    backdrop-filter: blur(6px);
}

.auth-slide-title {
    font-size: 38px;
    line-height: 1.1;
    font-weight: 800;
    letter-spacing: -.02em;
    margin: 0 0 12px;
    /* base.styl paints every h1-h6 with heading-color (#333), which would
     * otherwise block the banner's own text colour from inheriting. */
    color: inherit;
}

.auth-slide-sub {
    font-size: 16px;
    color: inherit;
    opacity: .85;
    margin: 0 0 22px;
    max-width: 44ch;
}

.auth-slide-cta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #fff;
    color: #16174A;
    font-weight: 700;
    font-size: 14px;
    padding: 12px 20px;
    border-radius: 999px;
    border: 0;
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 8px 28px rgba(22, 23, 74, .10);
    transition: transform .15s ease;
}

.auth-slide-cta:hover {
    transform: translateY(-1px);
}

/* ── branded placeholder ── */
.auth-stage-ph {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #2D2DA0 0%, #4A4ACD 48%, #7A7AE0 100%);
}

/* ── dots ── */
.auth-stage-nav {
    position: absolute;
    left: 44px;
    bottom: 36px;
    z-index: 3;
    display: flex;
    gap: 8px;
}

.auth-stage-tick {
    width: 34px;
    height: 4px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .34);
    border: 0;
    cursor: pointer;
    padding: 0;
    transition: background .2s ease;
}

.auth-stage-tick.on {
    background: #fff;
}

/*
 * Stacked layout. The slides are absolutely positioned, so they cannot push
 * the stage taller — the min-height has to leave room for the eyebrow, a
 * two-line headline, the button and the dots beneath it, or the copy
 * overflows and the eyebrow gets clipped off the top.
 */
@media (max-width: 1024px) {

    .auth-stage {
        min-height: 280px;
    }

    /* The extra bottom padding is the gap the dots sit in. */
    .auth-slide {
        padding: 24px 22px 56px;
    }

    .auth-slide-eyebrow {
        font-size: 10px;
        padding: 5px 10px;
        margin-bottom: 10px;
    }

    .auth-slide-title {
        font-size: 22px;
        margin: 0 0 10px;
    }

    .auth-slide-sub {
        display: none;
    }

    .auth-slide-cta {
        padding: 10px 16px;
        font-size: 13px;
    }

    .auth-stage-nav {
        left: 22px;
        bottom: 20px;
    }
}

@media (max-width: 480px) {

    .auth-stage {
        min-height: 268px;
    }

    .auth-slide {
        padding: 20px 18px 52px;
    }

    .auth-slide-title {
        font-size: 20px;
    }

    .auth-stage-nav {
        left: 18px;
        bottom: 18px;
    }

    .auth-stage-tick {
        width: 26px;
    }
}

@media (prefers-reduced-motion: reduce) {

    .auth-slide,
    .auth-slide-cta {
        transition: none;
    }
}

</style>
