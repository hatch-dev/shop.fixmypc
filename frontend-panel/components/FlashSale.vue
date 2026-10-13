<template>
  <div
    v-if="flashSales && flashSales.length"
    class="flash-sale-box p-4 mt-4 mb-4"
  >
    <div
      v-for="(flashSale, flashIndex) in flashSales"
      :key="flashIndex"
      class="mb-5"
    >
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="slider-collection-heading mb-0">{{ $t('flashSale.heading') }}</h5>

        <nuxt-link
          :to="`/flash-sale/${flashSale.id}`"
          class="link"
        >
          {{ $t('flashSale.showAll') }}
        </nuxt-link>
      </div>

      <div class="row g-3">
        <!-- LEFT SIDE -->
        <div class="col-lg-6">
          <!--
            flash_sales has no image column and never has, so this read
            undefined on every sale and always fell through to the default.
            Naming the default is honest about what is rendered.
          -->
          <div
            class="flash-banner"
            :style="{ backgroundImage: `url(${defaultBanner})` }"
          >
            <h2>
              {{ flashSale.title }}
            </h2>

            <!-- COUNTDOWN -->
            <div class="countdown text-center">
              <div class="count-text">
                {{ $t('flashSale.endsIn') }}
              </div>
               <div class="flash_sale_time_slots_grid" >
                <!-- DAYS -->
                <div class="time-group text-center">
                  <div class="d-flex gap-2 justify-content-center">
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).days)[0] }}
                    </span>
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).days)[1] }}
                    </span>
                  </div>
                  <div class="time-label">{{ $t('flashSale.days') }}</div>
                </div>
                <!-- HOURS -->
                <div class="time-group text-center">
                  <div class="d-flex gap-2 justify-content-center">
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).hours)[0] }}
                    </span>
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).hours)[1] }}
                    </span>
                  </div>
                  <div class="time-label">{{ $t('flashSale.hours') }}</div>
                </div>
                <!-- MINUTES -->
                <div class="time-group text-center">
                  <div class="d-flex gap-2 justify-content-center">
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).minutes)[0] }}
                    </span>
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).minutes)[1] }}
                    </span>
                  </div>
                  <div class="time-label">{{ $t('flashSale.minutes') }}</div>
                </div>
                <!-- SECONDS -->
                <div class="time-group text-center">
                  <div class="d-flex gap-2 justify-content-center">
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).seconds)[0] }}
                    </span>
                    <span class="time-box">
                      {{ formatNumber(getTimeRemaining(flashSale.end_time).seconds)[1] }}
                    </span>
                  </div>
                  <div class="time-label">{{ $t('flashSale.seconds') }}</div>
                </div>
              </div>
            </div>
            <div class="buy-container text-center">
              <!--
                This was <a href="">, which reloads the page the shopper is
                already on. It goes to the sale, which is what every other
                route out of this banner does.
              -->
              <nuxt-link :to="`/flash-sale/${flashSale.id}`" class="btn btn-buy">
                <span class="buy_btn_text">{{ $t('flashSale.shopNow') }}</span>
                 <span class="buy_btn_svg">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3.75 12H20.25" stroke="white" stroke-width="1.8" stroke-linecap="round"
                      stroke-linejoin="round" />
                    <path d="M13.5 5.25L20.25 12L13.5 18.75" stroke="white" stroke-width="1.8" stroke-linecap="round"
                      stroke-linejoin="round" />
                  </svg>
                </span>
              </nuxt-link>
            </div>
          </div>
        </div>

        <!-- RIGHT SIDE PRODUCTS -->
        <div class="col-lg-6">
          <client-only>
            <flash-sale-rail
              :products="flashSale.public_products"
              @loaded="glideLoaded"
            />
          </client-only>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import FlashSaleRail from '~/components/FlashSaleRail'

export default {
  name: "FlashSale",
  props: {
    flashSales: {
      type: Array,
      default() {
        return []
      }
    }
  },
  components: {
    FlashSaleRail
  },
  data() {
    return {
      sliderLoaded: false,
      defaultBanner: require('~/assets/images/super-laptop-bg.png'),
      now: Date.now(),
      interval: null
    }
  },
  methods: {
    glideLoaded() {
      this.sliderLoaded = true
    },
    getTimeRemaining(endTime) {
      if (!endTime) {
        return {
          days: 0,
          hours: 0,
          minutes: 0,
          seconds: 0
        }
      }

      const endDate = new Date(endTime).getTime()
      const difference = endDate - this.now

      if (difference <= 0) {
        return {
          days: 0,
          hours: 0,
          minutes: 0,
          seconds: 0
        }
      }

      const days = Math.floor(
        difference / (1000 * 60 * 60 * 24)
      )

      const hours = Math.floor(
        (difference % (1000 * 60 * 60 * 24)) /
        (1000 * 60 * 60)
      )

      const minutes = Math.floor(
        (difference % (1000 * 60 * 60)) /
        (1000 * 60)
      )

      const seconds = Math.floor(
        (difference % (1000 * 60)) / 1000
      )

      return {
        days,
        hours,
        minutes,
        seconds
      }
    },

    formatNumber(value) {
      return String(value)
        .padStart(2, '0')
        .split('')
    }
  },

  created() {
    this.interval = setInterval(() => {
      this.now = Date.now()
    }, 1000)
  },

  beforeDestroy() {
    if (this.interval) {
      clearInterval(this.interval)
    }
  }
}
</script>

<style scoped>

.product-card {
  background: #F3F5FC;
  border-radius: 18px;
  padding: 20px;
  height: 100%;
  transition: 0.3s;
  overflow: hidden;
}

.product-card:hover {
  box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}


/* :deep(.glide__track) {
  padding: 5px;
} */

.flash-sale-box {
  background: #fff;
  border-radius: 30px;
  border: 1px solid #E3E2F1;
}

/* LEFT BANNER */
.flash-banner {
  background-size: cover;
  background-position: center;
  border-radius: 12px;
  padding: 25px;
  height: 100%;
  position: relative;
  overflow: hidden;
}

.flash-banner::before {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: 12px;
}

.flash-banner * {
  position: relative;
  z-index: 2;
}

.flash-banner h2 {
  font-size: 50px;
  font-weight: 500;
  color:rgba(19, 14, 43, 1);
}

/* COUNTDOWN */
.countdown {
  background: #ff4d4d;
  border-radius: 12px;
  padding: 20px;
  color: #fff;
  margin: 20px 0;
}

.count-text {
  font-size: 35px;
  font-weight: 500;
  color: #fff;
  text-align: center;
}

.time-box {
  background: #ffeb3b;
  color: #000;
  font-weight: 700;
  font-size: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  width: 55px;
  height: 84px;
}

.colon {
  font-size: 20px;
  font-weight: 600;
}

.time-label {
  font-size: 16px;
  margin-top: 12px;
  font-weight: 600;
}

/* BUTTON */
.btn-buy {
  background: #00c853;
  color: #fff;
  border-radius: 100px;
  padding: 16px 70px;
  font-weight: 700;
  border: none;
  font-size: 24px;
  letter-spacing: 1.2%;
}

.btn-buy:hover {
  background: #00b248;
}

/* PRODUCT CARD */
.product-card {
  background: #F3F5FC;
  border-radius: 12px;
  padding: 40px 20px;
  position: relative;
  transition: 0.3s;
}

.product-card:hover {
  box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.product-card img {
  width: 100%;
  height: 180px;
  object-fit: contain;
}

.product-title {
  font-size: 14px;
  font-weight: 500;
}

.price {
  font-weight: 600;
}

.old-price {
  text-decoration: line-through;
  color: #999;
  font-size: 12px;
}

.discount {
  border: 1px solid #6c8cff;
  color: #6c8cff;
  border-radius: 20px;
  font-size: 11px;
  padding: 2px 8px;
}

.heart {
  position: absolute;
  top: 10px;
  right: 12px;
  font-size: 14px;
  color: #aaa;
  cursor: pointer;
}



.buy-container {
  margin-top: 100px;
} 
.flash_sale_time_slots_grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 29px;
    margin-top: 23px;
}
.flash_sale_time_slots_grid .time-group {
    position: relative;
}
.flash_sale_time_slots_grid > .time-group:not(:last-child)::before {
    content: ":";
    position: absolute;
    width: 20px;
    height: 100%;
    color: #fff;
    right: -24px;
    top: 13px;
    z-index: 999;
    font-size: 34px;
    font-weight: 800;
    display: flex;
    justify-content: center;
}
@media (max-width:1034px) {
.flash_sale_time_slots_grid {
    grid-template-columns: 1fr 1fr;
}
.flash_sale_time_slots_grid > .time-group:nth-child(2)::before,
.flash_sale_time_slots_grid > .time-group:nth-child(4)::before{
  display: none;
}
}
@media (max-width: 991px) {
  .flash-banner {
    height: auto;
  }

  .count-text {
    font-size: 24px;
  }
  .flash-banner h2{
    font-size: 25px;
  }
  .time-box{
    font-size: 25px;
    width: 45px;
    height: 50px;
  }
  .flash_sale_time_slots_grid > .time-group:not(:last-child)::before{
    right: -16px;
    top: 0px;
  }
  .flash_sale_time_slots_grid{
        margin-top: 23px;
        gap: 10px;
        row-gap: 25px;
  }
  .btn-buy{
    padding: 10px 40px;
    font-weight: 700;
    border: none;
    font-size: 16px;
}
}
@media (max-width: 369px) {
.flash_sale_time_slots_grid{
   grid-template-columns: 1fr;
}
.flash_sale_time_slots_grid > .time-group:not(:last-child)::before{
   display: none;
}

}
</style>