<template>
  <div
    v-if="$can('home_slider', 'view')"
    class="glide"
  >
    <ul
      class="glide-bullets"
      data-glide-el="controls[nav]"
    >
      <li
        v-for="(value, index) in Object.keys(imageList)"
        :key="index"
        :data-glide-dir="`=${index}`"
      >
        {{index}}
      </li>
    </ul>

    <div
      data-glide-el="controls"
      class="glide-nav"
    >
      <button
        class="prev-btn"
        data-glide-dir="<"
      >
        <i
          class="icon arrow-left"
        />
      </button>
      <button
        class="next-btn"
        data-glide-dir=">"
      >
        <i
          class="icon arrow-right"
        />
      </button>
    </div>

    <div
      data-glide-el="track"
      class="glide__track"
    >
      <ul
        class="glide__slides"
      >
        <li
          v-for="(value, index) in imageList"
          :key="index"
        >
          <div
            class="slider-content"
          >
            <div
              class="slider-content-inner img-wrap"
            >
              <img
                class="full-dimen"
                :src="imageURL(value)"
                title="" alt=""
              >
              <div
                class="btn-wrapper"
              >
                <nuxt-link
                  v-if="$can('home_slider', 'create')"
                  to="/home-slider/add?type=1"
                  class="primary-btn button"
                >
                  {{ $t('admin.addImg') }}
                </nuxt-link>
                <nuxt-link
                  v-if="$can('home_slider', 'edit')"
                  class="primary-btn button"
                  :to="`/home-slider/${value.id}`"
                >
                  {{ $t('category.edit') }}
                </nuxt-link>
                <button
                  v-if="$can('home_slider', 'delete')"
                  class="primary-btn"
                  @click.prevent="$emit('delete', value.id)"
                >
                  {{ $t('category.delete') }}
                </button>
              </div>

            </div>
          </div>

        </li>
      </ul>
    </div>
  </div>
</template>

<script>

  import Glide from '@glidejs/glide'
  import '@glidejs/glide/dist/css/glide.core.min.css'
  import util from "../../mixin/util";

  export default {
    name: 'ImageSlider',
    data() {
      return {
        glide: null
      }
    },
    watch: {
      imageList() {
        this.$nextTick(() => {
          this.sliderInit()
        })
      }
    },
    props: {
      activeSlide: {
        type: Number,
        default: 0
      },
      imageList: {
        type: Array,
        default: null

      }
    },
    components: {},
    computed: {},
    mixins: [util],
    methods: {
      imageURL(obj) {
        return this.getImageURL(obj.image ? obj.image : this.defaultImage)
      },
      sliderInit() {
        this.glide = new Glide('.glide', {
          startAt: this.activeSlide,
          perView: 1,
          gap: 0,
          dragThreshold: false,
        }).mount()
      }
    },
    created() {
    },
    mounted() {

      this.sliderInit()

    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/glider-slider.styl"
</style>

