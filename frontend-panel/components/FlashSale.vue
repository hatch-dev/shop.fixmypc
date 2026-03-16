<template>
  <div
    v-if="(flashSales && flashSales.length) || !flashSales"
  >
      <div
        v-if="!sliderLoaded"
        class="shimmer-wrapper"
      >
        <div
          class="shimmer"
          style="height: 281px"
        />
      </div>

    <div
      class="opacity-0 flash-slider"
      :class="{'opacity-1': sliderLoaded}"
    >
      <div
        v-if="flashSales && flashSales.length"
      >
        <div
          class="area home-section"
          v-for="(flashSale, index) in flashSales"
          :key="index"
        >
          <div class="flex sided title ">
            <div class="flex gap-10">
              <h4>{{ flashSale.title }}</h4>
              <div class="countdown-display">
                <span v-if="getTimeRemaining(flashSale.end_time).days > 0">{{ getTimeRemaining(flashSale.end_time).days }} {{ $tc('countdown.day', getTimeRemaining(flashSale.end_time).days) }}</span>
                <span v-if="getTimeRemaining(flashSale.end_time).hours > 0 || getTimeRemaining(flashSale.end_time).days > 0">{{ getTimeRemaining(flashSale.end_time).hours }} {{ $tc('countdown.hour', getTimeRemaining(flashSale.end_time).hours) }} </span>
                <span>{{ getTimeRemaining(flashSale.end_time).minutes }} {{ $tc('countdown.minute', getTimeRemaining(flashSale.end_time).minutes) }} </span>
                <span>{{ getTimeRemaining(flashSale.end_time).seconds }} {{ $tc('countdown.second', getTimeRemaining(flashSale.end_time).seconds) }}</span>
              </div>
            </div>
            <NuxtLink
              class="link"
              :to="`/flash-sale/${flashSale.id}`"
            >
              {{ $t('featured.showAll') }}
            </NuxtLink>
          </div>
          <div
            class="area-content"
          >
            <client-only>
              <image-slider
                v-if="flashSale.public_products && flashSale.public_products.length"
                :image-count="flashSale.public_products.length"
                :per-view="7"
                :gap="20"
                :responsive="[5, 4, 3, 2, 1]"
                @loaded="glideLoaded"
              >
                <template v-slot:content>
                  <li
                    v-for="(value, index) in flashSale.public_products"
                    :key="index"
                    class="center-text"
                  >
                    <flash-product-tile
                      :product="value"
                    />
                  </li>
                </template>
              </image-slider>
            </client-only>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import ImageSlider from '~/components/ImageSlider'
  import LazyImage from '~/components/LazyImage'
  import FlashProductTile from "./FlashProductTile";
  import moment from 'moment-timezone'

  export default {
    name: 'FlashSale',
    data() {
      return {
        sliderLoaded: false,
        now: Date.now()
      }
    },
    watch: {},
    props: {
      deactivate: {
        type: Boolean,
        default: true,
      },
      flashSales: {
        type: Array,
        default() {
          return []
        }
      }
    },
    components: {
      FlashProductTile,
      ImageSlider,
      LazyImage
    },
    computed: {
    },
    mixins: [util],
    methods: {
      glideLoaded(){
        this.sliderLoaded = true
      },
      getTimeRemaining(endTime) {
        if (!endTime) {
          return {
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0
          };
        }
        
        const endDate = new Date(endTime).getTime();
        const difference = endDate - this.now;
        
        if (difference <= 0) {
          return {
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0
          };
        }
        
        const days = Math.floor(difference / (1000 * 60 * 60 * 24));
        const hours = Math.floor((difference % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((difference % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((difference % (1000 * 60)) / 1000);
        
        return {
          days,
          hours,
          minutes,
          seconds
        };
      }
    },
    created() {
      // Update time every second for the countdown
      this.interval = setInterval(() => {
        this.now = Date.now();
      }, 1000);
    },
    beforeDestroy() {
      if (this.interval) {
        clearInterval(this.interval);
      }
    },
    mounted() {
    }
  }
</script>

<style scoped>
.countdown-display {
  display: inline;
  font-weight: bold;
  color: #e53935;
}

.flex.gap-10 {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.title {
  margin-bottom: 15px;
  padding: 0 10px;
}

.home-section {
  margin-bottom: 30px;
}

.area-content {
  padding: 10px 0;
}

.center-text {
  text-align: center;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .flex.gap-10 {
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
  }
  
  .flex.sided.title {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  
  .countdown-display {
    font-size: 14px;
  }
}
</style>