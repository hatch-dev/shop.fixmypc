<template>
  <div v-if="!fetchingReviews" class="modern-review-wrapper">

    <template v-if="currentAllReviews && currentAllReviews.length">

      <div class="glide review-glide">

        <div class="glide__track" data-glide-el="track">
          <ul class="glide__slides">
              <li v-for="(value, index) in currentAllReviews" :key="index" class="glide__slide">
              <div class="modern-review-card">
               <div class="review-top">
                  <div class="review-stars">
                    <div class="review-stars">
                      <svg v-for="n in Number(value.rating)" :key="n" width="16" height="16" viewBox="0 0 16 16"
                        fill="none" xmlns="http://www.w3.org/2000/svg" class="star-icon">
                        <rect width="16" height="16" fill="#05B942" />
                        <path
                          d="M8 2L9.34708 6.1459H13.7063L10.1796 8.7082L11.5267 12.8541L8 10.2918L4.47329 12.8541L5.82037 8.7082L2.29366 6.1459H6.65292L8 2Z"
                          fill="white" />
                      </svg>
                    </div>
                  </div>
                  <div class="verified-badge">
                    <span class="verified-icon">
                      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="7" cy="7" r="7" fill="#72718E" />
                        <path d="M10.3337 4.5L5.75033 9.08333L3.66699 7" stroke="white" stroke-linecap="round"
                          stroke-linejoin="round" />
                      </svg>
                    </span>
                    <span>Verified</span>
                  </div>
                </div>

                <h4 class="review-title">
                  {{ value.name }}
                </h4>

                <p class="review-desc">
                  {{ value.review }}
                </p>

                <div class="review-footer">
                  <div class="review-user">
                    {{ userName(value) || 'Anonymous' }}
                    <span>{{ value.created }}</span>
                  </div>

                  <img v-if="value.source === 'facebook'" src="https://cdn-icons-png.flaticon.com/512/5968/5968764.png"
                    class="review-platform">

                  <img v-else src="https://cdn-icons-png.flaticon.com/512/300/300221.png" class="review-platform">
                </div>

              </div>
            </li>

          </ul>
        </div>

        <div class="review-summary">
        <strong>{{ avgRating }} / 5</strong>
        Rated based on
        <strong>{{ calculateRating.total }} reviews</strong>.
        Showing our 5 star reviews.
      </div>

        <div class="p_detail_review" data-glide-el="controls">

          <button class="review-nav prev" data-glide-dir="<">
            <svg width="6" height="10" viewBox="0 0 6 10" fill="none">
              <path d="M4.75 0.75L0.75 4.75L4.75 8.75" stroke="#69679B" stroke-width="1.5" stroke-linecap="round"
                stroke-linejoin="round" />
            </svg>
          </button>

          <button class="review-nav next" data-glide-dir=">">
            <svg width="6" height="10" viewBox="0 0 6 10" fill="none">
              <path d="M0.75 0.75L4.75 4.75L0.75 8.75" stroke="#69679B" stroke-width="1.5" stroke-linecap="round"
                stroke-linejoin="round" />
            </svg>
          </button>

        </div>

      </div>

    

    </template>
    <p v-else>
      No reviews found
    </p>
  </div>
</template>

<script>
import util from '~/mixin/util'
import routeParamHelper from '~/mixin/routeParamHelper'
import Spinner from '~/components/Spinner'
import LazyImage from '~/components/LazyImage'
import { mapGetters, mapActions } from 'vuex'
import ImagePopup from '~/components/ImagePopup'
import Pagination from '~/components/Pagination'
import RatingStar from "~/components/RatingStar"
import Glide from '@glidejs/glide'
import '@glidejs/glide/dist/css/glide.core.min.css'
export default {
  name: 'ProductReview',
  data() {
    return {
      avgRating: 0,
      totalRating: null,
      fetchingReviews: false,
      fetchingTotal: false,
      imagePopup: false,
      activeImage: 0,
      reviewImages: [],
      paginationParam: null
    }
  },
watch: {

},
  props: {
    id: {
      type: Number,
      required: true
    }
  },
  components: {
    RatingStar,
    Spinner,
    LazyImage,
    ImagePopup,
    Pagination
  },
  computed: {
    isSmallerDevice() {
      return window.innerWidth <= 768
    },
    calculateRating() {
      let result = {
        total: 0,
        rating: 0
      }
      this.currentTotalRating.forEach(obj => {
        this.totalRating = { ...this.totalRating, ...{ [`${obj.rating}star`]: obj.total } }
        result.total += parseInt(obj.total)
        result.rating += obj.total * obj.rating
      })
      this.avgRating = result.total ? this.formatPrice(result.rating / result.total) : 0
      return result
    },
    totalPage() {
      return this.allReviews?.last_page
    },
    pageCount() {
      return this.allReviews?.total
    },
    currentAllReviews() {
      return this.allReviews?.data
    },
    currentTotalRating() {
      return this.totalReviews
    },
    ...mapGetters('review', ['allReviews', 'totalReviews', 'banner']),
  },
  mixins: [util, routeParamHelper],
  methods: {
    // getStars(rating) {
    //   const total = Math.round(Number(rating || 0))
    //   return '★'.repeat(total) + '☆'.repeat(5 - total)
    // },
    truncateText(text, length) {
      if (!text) return ''
      return text.length > length
        ? text.substring(0, length) + '...'
        : text
    },
    userName(review) {
      if (review?.user) {
        return review?.user?.name
      } else if (review?.guest_user) {
        return review?.guest_user?.name
      }
      return ""
    },
    imagePopupOpen(data, index) {
      this.reviewImages = data.review_images
      this.activeImage = index
      this.imagePopup = true
    },
    noReview(review) {
      return !review || review === 'null'
    },
    calculateRatingPercent(total) {
      return parseInt((total / this.calculateRating.total) * 100) || 0
    },
    async paginationFetch(evt) {
      this.paginationParam = evt
      await this.fetchingData()
    },
    async fetchingData() {

      this.settingParam(this.paginationParam)
      this.fetchingReviews = true
      try {
        const data = await this.fetchReviews({
          id: this.id,
          time_zone: this.timeZone,
          order_by: this.orderBy,
          type: this.orderByType,
          get_total: !this.totalReviews.length > 0,
          page: this.page,
          q: this.search
        })
        this.$emit('has-review', this.pageCount)
      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.fetchingReviews = false
    },
    ...mapActions('review', ['fetchReviews', 'emptyReviews']),
  },
  created() {
  },
async mounted() {
  this.fetchingTotal = false
  this.emptyReviews()

  await this.fetchingData()

  this.fetchingTotal = true

  this.totalRating = {
    '5star': 0,
    '4star': 0,
    '3star': 0,
    '2star': 0,
    '1star': 0
  }
  this.$nextTick(() => {
    setTimeout(() => {
      const glideEl = this.$el.querySelector('.review-glide')

      if (!glideEl) return

      this.glide = new Glide(glideEl, {
        type: 'carousel',
        perView: 1,
        gap: 20,
        rewind: true
      })
    this.glide.mount()

    setTimeout(() => {
      this.glide.update({
        perView: 1
      })
    }, 300)

    }, 1500)
  })
},
}
</script>
<style>
.modern-review-wrapper .glide__slide{
  width: 100%;
}
.review-slide-enter-active,
.review-slide-leave-active {
  transition: all .4s ease;
}

.review-slide-enter {
  opacity: 0;
  transform: translateX(40px);
}

.review-slide-leave-to {
  opacity: 0;
  transform: translateX(-40px);
}

.review-slider-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}

/* .review-slider {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
  width: 100%;
} */

.modern-review-card {
    background: #f7f7fb;
    border-radius: 0;
    padding: 16px;
    min-height: 260px;
    max-height: 260px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    border-bottom: 2px solid #DDDDE9;
}

.review-top {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  margin-bottom: 14px;
  gap: 14px;
}

.review-stars {
  background: #16a34a;
  color: #fff;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 10px;
  letter-spacing: 1px;
  font-weight: 700;
  min-width: 72px;
  text-align: center;
}

.verified-badge {
  font-size: 12px;
  color: #4b5563;
  font-weight: 600;
  white-space: nowrap;
}

.review-title {
  font-size: 14px;
  line-height: 1.4;
  font-weight: 700;
  color: #111827;
  margin-bottom: 8px;
   display: -webkit-box;
   line-clamp: 2;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.review-desc {
  font-size: 12px;
  line-height: 1.7;
  color: #130E2B;
  flex: 1;
  display: -webkit-box;
  line-clamp: 3;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow-y: scroll;
}
.review-desc::-webkit-scrollbar {
  width: 2px;
}

.review-desc::-webkit-scrollbar-track {
  background: transparent;
}

.review-desc::-webkit-scrollbar-thumb {
  background: #c7c7d4;
  border-radius: 10px;
}

.review-desc::-webkit-scrollbar-thumb:hover {
  background: #a8a8b8;
}
.review-user img {
    padding: 0;
}
.review-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
}

.review-user {
  font-size: 12px;
  font-weight: 600;
  color: #111827;
}

.review-user span {
  font-size: 11px;
  color: #6b7280;
  margin-top: 2px;
}

.review-platform {
  width: 18px;
  height: 18px;
  object-fit: contain;
}

.review-summary {
  text-align: center;
  margin-top: 20px;
  font-size: 14px;
  color: #374151;
}

.review-stars {
  display: flex;
  gap: 4px;
  align-items: center;
  background: none;
  padding: 0;
}
.star-icon {
  flex-shrink: 0;
}

.review-summary strong {
  color: #111827;
}
.review-platform {
    padding: 0px !important;
}
.glide__slides {
  display: flex !important;
  list-style: none;
  padding: 0;
  margin: 0;
}

/* .glide__slide {
  flex-shrink: 0;
} */
.p_detail_review {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-top: 20px;
}
.p_detail_review .review-nav {
    width: 30px;
    height: 30px;
    border: 1px solid #E1DFF5;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 100%;
}
.p_detail_review .review-nav:hover {
    background: #dddde9;
}
.modern-review-wrapper {
    margin-top: 20px;
}
@media (max-width: 768px) {
  .glide__slide{
     width: auto;
  }
  .review-slider {
    grid-template-columns: 1fr;
  }


  .review-title {
    font-size: 14px;
  }

  .review-desc {
    font-size: 12px;
    padding-right: 10px;
  }
}
</style>
