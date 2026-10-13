<template>
  <div
    ref="main-slider"
    class="flow-hidden"
  >
    <ul
        class="c_slider__container shimmer-wrapper"
        :style="[parentWidthStyle, { transform: translateXInPx }]"
      >


          <li
            v-for="(value, index) in itemList"
            :key="index"
            style="max-width: 100px"
            :style="itemWidthStyle"
          >
            <!-- plain tiles, as the home page rails render them -->
            <product-tile
              v-if="value"
              :product="value"
              :show-collection="false"
              class="mb-20 mb-sm-15"
            />
            <skeleton-product
              v-else
              class="mb-20 mb-sm-15"
            />
          </li>

      </ul>

  </div>
</template>

<script>

  import ProductTile from '~/components/ProductTile'
  import { Draggable } from 'draggable-vue-directive'

  export default {
    name: 'SuggestedAjaxSlider',
    data() {
      return {
        sliderContainerWidth: 0,
      }
    },
    watch: {
    },
    props: {
      itemList: {
        type: Array,
        default() {
          return []
        }
      },
      currentPagination: {
        type: Number
      },
      totalPage: {
        type: Number
      },
      currentPage: {
        type: Number
      },
      draggedWidth: {
        type: Number
      }
    },
    components: {
      ProductTile
    },
    computed: {
      parentWidthStyle(){
        return {
          'flex-basis': `${this.totalPage * this.sliderContainerWidth}px`,
          'width': `${this.totalPage * this.sliderContainerWidth}px`,
          'max-width': `${this.totalPage * this.sliderContainerWidth}px`,
          'min-width': `${this.totalPage * this.sliderContainerWidth}px`
        }
      },
      translateXInPx(){
        return `translateX(${(((this.currentPage - 1) * this.sliderContainerWidth) + this.draggedWidth) * -1}px)`
      },
      itemWidthStyle(){
        return {
         'flex-basis': `${this.itemWidthInPx}px`,
          'width': `${this.itemWidthInPx}px`,
          'max-width': `${this.itemWidthInPx}px`,
          'min-width': `${this.itemWidthInPx}px`
        }
      },
      itemWidthInPx(){
        return this.sliderContainerWidth / this.currentPagination
      },
    },
    mixins: [],
    methods: {
    },
    created() {
    },
    mounted() {
      this.$nextTick(()=> {
        this.sliderContainerWidth = this.$refs['main-slider'].clientWidth
      })
    }
  }
</script>
