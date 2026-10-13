<template>
  <div class="past-deal-card">
    <div class="sold-out-overlay">
      <div class="sold-out-stamp">
        {{ deal.is_expired ? $t('clearance.expired') : $t('clearance.soldOut') }}
      </div>
    </div>

    <!-- Product image frame -->
    <div class="past-deal-image-frame">
      <img
        :src="getImageURL(dealImage)"
        :alt="dealTitle"
        class="past-deal-svg"
        @error="onImgError"
      >

      <span class="past-deal-badge" v-if="deal.product && deal.product.badge">
        {{ deal.product.badge }}
      </span>
    </div>

    <!-- Product Info -->
    <div class="past-deal-info">
      <h3 class="past-deal-title-text">{{ dealTitle }}</h3>

      <div class="past-deal-rating">
        <rating-star :rating="dealRating" />
        <span class="reviews-count-text">({{ reviewCount }})</span>
      </div>

      <div class="past-deal-footer">
        <div class="past-deal-pricing">
          <span class="past-deal-price"><price-format :price="currentPricingCalc(deal.product)" :decimals="2" /></span>
          <span class="past-deal-old-price" v-if="prevPriceCalc(deal.product) > 0">
            <price-format :price="prevPriceCalc(deal.product)" :decimals="2" />
          </span>
        </div>
        <span class="past-deal-off-badge" v-if="offPercent > 0">
          {{ offPercent }}% {{ $t('clearance.off') }}
        </span>
      </div>
    </div>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import PriceFormat from '~/components/PriceFormat'
  import RatingStar from '~/components/RatingStar'

  export default {
    name: 'PastDealCard',
    components: { PriceFormat, RatingStar },
    mixins: [util, productPriceHelper],
    props: {
      deal: {
        type: Object,
        required: true
      }
    },
    computed: {
      dealTitle() {
        return this.deal?.title || this.deal?.product?.title || ''
      },
      dealImage() {
        return this.deal?.product?.image || this.defaultImage
      },
      // The product's own rating. The card used to print five hard-coded stars
      // beside a real review count, so a product rated 3.1 from 40 reviews
      // showed as five stars out of 40.
      dealRating() {
        return parseFloat(this.deal?.product?.rating || 0)
      },
      reviewCount() {
        return parseInt(this.deal?.product?.review_count || 0)
      },
      offPercent() {
        return this.discountPercent(
          this.prevPriceCalc(this.deal?.product),
          this.currentPricingCalc(this.deal?.product)
        )
      }
    },
    methods: {
      onImgError(e) {
        const fallback = this.getImageURL(this.defaultImage)
        if (e.target.src !== fallback) {
          e.target.src = fallback
        }
      }
    }
  }
</script>

<style scoped>
.past-deal-card {
  position: relative;
  background-color: #fff;
  border-radius: 1rem;
  border: 1px solid #f3f4f6;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: all 0.3s;
  text-decoration: none;
  color: inherit
}

.past-deal-card:hover {
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / .05), 0 2px 4px -1px rgb(0 0 0 / .06)
}

.past-deal-image-frame {
  position: relative;
  aspect-ratio: 1 / 1;
  background-color: #F4F4F9;
  border-radius: .75rem;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  margin-bottom: 1rem;
  overflow: hidden
}

.past-deal-svg {
  max-width: 75%;
  max-height: 75%;
  object-fit: contain;
  opacity: .8
}

.sold-out-overlay {
  position: absolute;
  inset: 0;
  background-color: #F4F4F4;
  backdrop-filter: blur(1px);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 90%;
  z-index: 10
}

.sold-out-stamp {
  border: 4px solid #130E2B;
  color: #130E2B;
  font-weight: 900;
  letter-spacing: .1em;
  font-size: 1.125rem;
  text-transform: uppercase;
  padding: .375rem 1rem;
  transform: rotate(-12deg);
  border-radius: .125rem;
  user-select: none;
  box-shadow: 0 1px 3px rgb(0 0 0 / .1)
}

.past-deal-badge {
  position: absolute;
  top: .75rem;
  left: .75rem;
  background-color: #e5e7eb;
  color: #374151;
  font-size: 10px;
  font-weight: 900;
  padding: .125rem .5rem;
  border-radius: .25rem;
  z-index: 11
}

.past-deal-info {
  display: flex;
  flex-direction: column;
  gap: .5rem
}

.past-deal-title-text {
  font-weight: 700;
  font-size: .875rem;
  color: #130E2B;
  line-height: 1.25;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden
}

.past-deal-rating {
  display: flex;
  align-items: center;
  gap: .25rem;
  font-size: .75rem
}

.reviews-count-text {
  color: #6F6980
}

.past-deal-footer {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding-top: .5rem
}

.past-deal-pricing {
  display: flex;
  gap: .5rem;
  align-items: center
}

.past-deal-price {
  font-weight: 800;
  color: #130E2B
}

.past-deal-old-price,
.past-deal-old-price >>> span {
  font-size: .75rem;
  color: #6F6980;
  text-decoration: line-through
}

.past-deal-off-badge {
  font-size: 10px;
  font-weight: 800;
  color: #05B942;
  background-color: rgb(5 185 66 / .1);
  padding: .125rem .5rem;
  border-radius: 9999px;
  white-space: nowrap
}

/* ==========================================================================
   Phone and small tablet type scale, matching pages/clearance.vue and
   assets/styles/home-mobile.styl. The card sits four-up on a desktop grid
   and one-up on a phone, so the type that suits the small tile is the type
   the phone should use -- not the larger step the wide grid grew into.
   ========================================================================== */
@media only screen and (max-width: 991px) {
  .sold-out-stamp {
    font-size: 14px
  }

  .past-deal-title-text {
    font-size: 12.8px
  }

  .past-deal-price {
    font-size: 15px
  }

  .past-deal-rating,
  .reviews-count-text {
    font-size: 11.5px
  }

  .past-deal-old-price >>> span {
    font-size: 11.5px
  }

  .past-deal-badge,
  .past-deal-off-badge {
    font-size: 9.5px
  }
}
</style>
