<template>
  <div class="detail-image shimmer-wrapper">
 <template v-if="key">
        <client-only>
          <div
            class="hide-md mx-h-100"
            @mouseleave="showSliderMessage = false"
            @mouseover="showSliderMessage = true"
            @mouseenter="sizeZoomPane"
            @click.prevent="imagePopupOpen"
          >
            <ProductZoomer
              ref="productZoomer"
              :base-images="zoomImageList"
              :base-zoomer-options="zoomerOptions"
              class="mx-h-100"
              :class="{'arrow-hide': !isArrowVisible}"
            />
          </div>

            <image-popup
              ref="imagePopup"
              v-if="imagePopup"
              :title="title"
              :product="product"
              :active-id="activeId"
              :no-scroll="!isSmallerDevice"
              :image-list="imageList"
              :stacked-on-responsive="true"
              @close-popup="closePopup"
              @add-to-wishlist="$emit('add-to-wishlist')"
            />
          <p
            v-if="loaded"
            class="mt-5 pb-15 center-text lh-30 hide-md"
          >
            <span
              v-if="!showSliderMessage"
            >
              {{ $t('productImage.rollOver') }}
            </span>
            <span v-else>
              {{ $t('productImage.extendedView') }}
            </span>
          </p>
     </client-only>
      </template>


    <template
      v-if="!key"
    >

    <div class="pleceholder-zoomer-base-container center-text">
      <img

        class="preload-img"
        :src="getThumbImageURL(this.mainImage)"
        :alt="title"
        height="100"
        width="100"
      >

    </div>
    <div class="pleceholder-thumb-list">
      <div class="responsive-image shimmer hide-sm"/>
      <div class="responsive-image shimmer hide-sm"/>
      <div class="responsive-image shimmer hide-sm"/>
    </div>
    </template>


  </div>

</template>

<script>
  import ImagePopup from '~/components/ImagePopup'
  import util from '~/mixin/util'

  export default {
    name: 'ProductImages',
    data() {
      return {
        key: 0,
        loaded: false,
        noScroll: true,
        showSliderMessage: false,
        activeId: 0,
        imagePopup: false,
        imageList: [],
        zoomImageList: {
          thumbs: [],
          normal_size: [],
          large_size: []
        },
        zoomerOptions: {
          // 3 rather than 2.5: the lens is the pane divided by this, and with
          // the pane now as wide as the info column, 2.5 made a lens that
          // covered most of the photo.
          zoomFactor: 3, // scale for zoomer
          pane: 'pane', // three type of pane ['pane', 'container-round', 'container']
          hoverDelay: 300, // how long after the zoomer take effect
          namespace: 'zoomer', // add a namespace for zoomer component, useful when on page have mutiple zoomer
          move_by_click: false, // move image by click thumb image or by mouseover
          scroll_items: 4, // thumbs for scroll
          choosed_thumb_border_color: "#bbdefb", // choosed thumb border color
          scroller_button_style: "line",
          scroller_position: "left",
          zoomer_pane_position: "right"
        }
      }
    },
    components: {
      ImagePopup
    },
    mixins: [util],
    props: {
      product: {
        type: Object
      },
      images: {
        type: Array,
        default() {
          return []
        }
      },
      mainImage: {
        type: String,
        default: ''
      },
      title: {
        type: String,
        default: ''
      }
    },
    directives: {},
    computed: {
      videoThumb() {
        return this.product?.video_thumb || ''
      },
      isSmallerDevice() {
        return window.innerWidth <= 992
      },
      isArrowVisible() {
        return this.zoomerOptions.scroll_items < this.images?.length + 1
      }
    },
    methods: {
      /*
       * Size the zoom preview to the space it opens over.
       *
       * The pane was a fixed 500 x 500 (detail-images.styl) laid over a
       * product-info column that is wider and much taller, so the text beside
       * and under it showed through and the preview looked broken. It now
       * spans the whole column, from the image to the column's far edge,
       * measured on each hover so a resized window is followed. Drift sizes
       * the lens from the pane when the zoom opens, so it follows too.
       * Mirrored for right-to-left (Arabic).
       */
      sizeZoomPane() {
        const pane = this.$el && this.$el.querySelector('.pane-container')
        const column = this.$el && this.$el.closest('.product-main')

        if (!pane || !column) return

        const gap = 15
        const image = this.$el.getBoundingClientRect()
        const row = column.getBoundingClientRect()
        const rtl = document.body.classList.contains('rtl')
        const room = Math.floor(rtl ? image.left - row.left - gap : row.right - image.right - gap)

        // Too narrow to be worth it: keep the stylesheet's default square.
        if (room < 300) return

        pane.style.setProperty('width', room + 'px', 'important')
      },
      zoomActiveChange(index) {
        if(this.zoomImageList?.thumbs && this.zoomImageList.thumbs[index]){
          this.$refs?.imagePopup?.clickOnThumb(index)
          this.$refs.productZoomer.chooseThumb(this.zoomImageList.thumbs[index],{ type: null})
        }
      },
      closePopup() {
        if (!this.isSmallerDevice) {
          this.imagePopup = false
        }
        this.$emit('image-popup', this.imagePopup)
      },
      imagePopupOpen(evt) {
        if (evt.target.classList.contains("zoomer-control") || evt.target.classList.contains("video-thumb")) {
          return false
        }
        const childList = [...this.$el.querySelectorAll('.thumb-list')[0].children]

        childList.forEach((obj, index) => {
          if (obj.className.includes('choosed-thumb')) {
            this.activeId = index - 1
          }
        })
        this.imagePopup = true
        this.$emit('image-popup', this.imagePopup)
      },
      generateImageObj(id, imageLink) {
        return {
          id: id,
          url: imageLink
        }
      },
    },
    async mounted() {
      if (this.isSmallerDevice) {
        this.imagePopup = true
      }
      if (this.images) {
        this.imageList = [{image: this.mainImage}].concat(this.images)
      } else {
        this.imageList = [{image: this.mainImage}]
      }

      let thumbs = []
      let normals = []
      let larges = []
      let imageId = 0
      const self = this

      const thumbPromiseAll = []
      const fullPromiseAll = []

      this.imageList.forEach((obj) => {

        if (obj) {
          imageId++

         /* thumbs.push(this.generateImageObj(imageId, this.getThumbImageURL(obj.image)))
          normals.push(this.generateImageObj(imageId, this.getImageURL(obj.image)))
          larges.push(this.generateImageObj(imageId, this.getImageURL(obj.image)))*/


          thumbPromiseAll.push(new Promise((resolve, reject) => {
            const thumbImg = document.createElement('img')

            thumbImg.onload = function () {

              resolve(self.generateImageObj(this.dataset.index, this.src))
            }

            thumbImg.onerror = function () {
              resolve(self.generateImageObj(this.dataset.index, self.getThumbImageURL()))
            }

            thumbImg.src = this.getThumbImageURL(obj.image);
            thumbImg.setAttribute('data-index', imageId)
          }))

          fullPromiseAll.push(new Promise((resolve, reject) => {
            const thumbImg = document.createElement('img')

            thumbImg.onload = function () {
              resolve(self.generateImageObj(this.dataset.index, this.src))
            }

            thumbImg.onerror = function () {
              resolve(self.generateImageObj(this.dataset.index, self.getImageURL()))
            }

            thumbImg.src = this.getImageURL(obj.image);
            thumbImg.setAttribute('data-index', imageId)
          }))


        }
      })

      /*this.zoomImageList.thumbs = thumbs
      this.zoomImageList.normal_size = normals
      this.zoomImageList.large_size = larges*/


      await Promise.all(thumbPromiseAll)
        .then(res => {
          thumbs = res
          this.zoomImageList.thumbs = thumbs
        })

      await Promise.all(fullPromiseAll)
        .then(res => {
          normals = res
          larges = res
          this.zoomImageList.normal_size = normals
          this.zoomImageList.large_size = larges
        })

      this.key++


      this.$nextTick(() => {
        setTimeout(() => {

          this.loaded = true

          if (this.videoThumb) {

            const imgWrapper = document.querySelector('.thumb-list')
            const div = document.createElement('div')
            div.classList.add('video-thumb')
            const span = document.createElement('span')
            span.classList.add('flex')
            const i = document.createElement('i')
            i.classList.add('icon')
            i.classList.add('play-icon')
            const img = document.createElement('img')


            img.onerror = function () {
              img.src = self.getThumbImageURL()
            }

            img.src = this.getImageURL(this.videoThumb)
            div.appendChild(img)
            span.appendChild(i)
            div.appendChild(span)
            imgWrapper.appendChild(div)

            div.addEventListener('click', function () {
              self.activeId = normals?.length
              self.imagePopup = true
              self.$emit('image-popup', self.imagePopup)
            })
          }
        }, 1000)
      })
    }

  }
</script>
<style>
.drift-zoom-pane {
    background: #fff !important;
}

/*
 * The lens over the photo: a light tint with an edge, not a dark block.
 * Drift appends it to <body>, not inside the zoomer, so it cannot be
 * reached through .zoomer-base-container; and its own colour is set in
 * Drift's injected styles, hence !important.
 */
.drift-bounding-box {
    background: rgba(51, 49, 154, 0.1) !important;
    border: 1px solid rgba(51, 49, 154, 0.45) !important;
}

</style>

