<template>
  <client-only>
    <div class="deal-page">

      <!--
        The deal page is a gallery beside a buy box, so it can be drawn before
        the deal arrives instead of parking a spinner in the middle of an empty
        viewport. Same layout, same height, filled in place.
      -->
      <skeleton-page
        v-if="loading"
        variant="detail"
      />

      <template v-else>

        <!-- Empty state: nothing configured at all -->
        <div v-if="!hero && !pastDeals.length" class="clearance-empty container-fluid">
          <h1 class="empty-title">{{ $t('clearance.noDealTitle') }}</h1>
          <p class="empty-text">{{ $t('clearance.noDealText') }}</p>
          <nuxt-link to="/all-products/products" class="empty-cta">
            {{ $t('clearance.browseAll') }}
          </nuxt-link>
        </div>

        <!-- Main Content Container (hero deal) -->
        <main v-if="hero" class="main-content container-fluid">
        <div class="main-grid">

          <!-- Left Column: Image Gallery (same component as product detail) -->
          <div class="col-gallery">
            <div class="gallery-wrap detail-image-inner" :class="{ 'z-2': imagePopup }">
              <div v-if="reducedPercent > 0" class="discount-badge">
                -{{ reducedPercent }}%
              </div>
              <product-images
                v-if="product.image || galleryImages.length"
                ref="productImagesRef"
                :title="title"
                :product="product"
                :main-image="product.image"
                :images="galleryImages"
                @image-popup="imagePopup = $event"
              />
            </div>
          </div>

          <!-- Right Column: Deal Details -->
          <div class="col-details">

            <!-- Rating & Reviews (same component as product detail page) -->
            <div class="rating-row">
              <rating-star :rating="ratingValue" />
              <span class="rating-score">
                {{ ratingValue }} • {{ reviewCount }} {{ $t('clearance.reviews') }}
              </span>
            </div>

            <!-- Product Title Block -->
            <div class="title-block">
              <h1 class="product-title">
                <nuxt-link :to="productUrl" class="title-link">{{ title }}</nuxt-link>
              </h1>
              <p class="product-subtitle" v-if="subtitle">{{ subtitle }}</p>
            </div>

            <!-- Price Promotion Box -->
            <div class="price-box">
              <div class="price-row">
                <span class="price-from-label" v-if="priceFrom">{{ $t('clearance.priceFrom') }}</span>
                <span class="current-price"><price-format :price="currentPricing" :decimals="2" /></span>
                <span class="old-price" v-if="prevPrice > 0"><price-format :price="prevPrice" :decimals="2" /></span>
              </div>
              <div class="save-badge" v-if="savedAmount > 0">
                {{ $t('clearance.youSave') }} <price-format :price="savedAmount" :decimals="2" />
              </div>
            </div>

            <!-- Stock + claimed indicator -->
            <div class="stock-claim-section" v-if="unitsLeft > 0 || claimedPercent > 0">
              <div class="stock-row">
                <div class="units-left">
                  <span class="pulse-dot"></span>
                  <span>{{ unitsLeft }} {{ $t('clearance.unitsLeft') }}</span>
                </div>
                <div class="selling-fast">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="selling-fast-icon">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941" />
                  </svg>
                  <span>{{ $t('clearance.sellingFast') }}</span>
                </div>
              </div>

              <div class="progress-container">
                <div class="progress-bar" :style="{ width: `${claimedPercent}%` }"></div>
              </div>
              <div class="claim-status-text">
                {{ claimedPercent }}% {{ $t('clearance.claimed') }} — {{ unitsLeft }} {{ $t('clearance.remainingAtPrice') }}
              </div>
            </div>

            <!-- Description / overview -->
            <div class="quote-box" v-if="description" v-html="description"></div>

            <!-- Highlights -->
            <ul class="usp-list" v-if="highlights.length">
              <li v-for="(point, idx) in highlights" :key="idx" class="usp-item">
                <span class="check-icon">✔</span>
                <span>{{ point }}</span>
              </li>
            </ul>

            <!-- Big Buy Button and Info -->
            <div class="buy-section">
              <button @click="heroBuyNow" class="buy-btn" :disabled="buyingNow">
                <span v-if="buyingNow">{{ $t('clearance.processing') }}</span>
                <!--
                  A product the shopper has a choice to make on is not bought
                  from here, so the button says what it will do rather than
                  promising a purchase it does not complete.
                -->
                <span v-else-if="requiresOption">{{ $t('clearance.chooseOptions') }}</span>
                <span v-else>{{ $t('clearance.buyNow') }} — <price-format :price="currentPricing" :decimals="2" /></span>
              </button>
              <div class="shipping-highlights">
                <span>✈ {{ $t('clearance.freeShipping') }}</span>
                <span class="bullet-separator">•</span>
                <span>↺ {{ $t('clearance.returns') }}</span>
                <span class="bullet-separator">•</span>
                <span>⚡ {{ $t('clearance.priceMatch') }}</span>
              </div>
            </div>

            <!-- Urgent Countdown Box -->
            <div class="countdown-box" v-if="secondsLeft > 0">
              <div class="timer-icon-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="timer-icon">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div class="timer-container">
                <div class="timer-label">{{ $t('clearance.dealExpires') }}</div>
                <div class="timer-value">{{ formattedTime }}</div>
                <div class="timer-desc" v-if="prevPrice > 0">
                  {{ $t('clearance.priceReverts') }} <price-format :price="prevPrice" :decimals="2" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>

      <!-- Past Deals Grid -->
      <section class="past-deals-section container-fluid" v-if="pastDeals.length">
        <div class="divider-section">
          <div class="divider-line"></div>
          <span class="divider-label">{{ $t('clearance.pastDeals') }}</span>
          <div class="divider-line"></div>
        </div>

        <div class="past-deals-header">
          <h2 class="past-deals-title">{{ $t('clearance.soldOutHeading') }}</h2>
          <nuxt-link to="/all-products/products" class="past-deals-link">
            {{ $t('clearance.missedThese') }}
          </nuxt-link>
        </div>

        <!-- Sold-out deals (shown by default) -->
        <div class="past-deals-grid" v-if="soldOutDeals.length">
          <past-deal-card
            v-for="(deal, index) in soldOutDeals"
            :key="`sold-${index}`"
            :deal="deal"
          />
        </div>

        <p v-else class="past-deals-empty">
          {{ $t('clearance.noSoldOut') }}
        </p>

        <!-- Toggle to reveal expired deals -->
        <div class="past-deals-toggle" v-if="expiredDeals.length">
          <button class="expired-toggle-btn" @click="showExpired = !showExpired">
            {{ showExpired
              ? $t('clearance.hideExpired')
              : $t('clearance.showExpired', { count: expiredDeals.length }) }}
          </button>
        </div>

        <!-- Expired deals (revealed below the toggle) -->
        <div class="past-deals-grid mt-24" v-if="showExpired && expiredDeals.length">
          <past-deal-card
            v-for="(deal, index) in expiredDeals"
            :key="`expired-${index}`"
            :deal="deal"
          />
        </div>
      </section>

      <!-- Email Notification / Lead Capture Box -->
      <section class="lead-capture container-fluid">
        <div class="newsletter-box">
          <div class="newsletter-grid">
            <!-- Text left -->
            <div class="newsletter-left">
              <div class="newsletter-tag">
                <span class="bell-emoji">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10.2676 21C10.4431 21.304 10.6956 21.5565 10.9996 21.732C11.3037 21.9075 11.6485 21.9999 11.9996 21.9999C12.3506 21.9999 12.6955 21.9075 12.9995 21.732C13.3036 21.5565 13.556 21.304 13.7316 21" stroke="#05B942" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3.26225 15.326C3.13161 15.4692 3.0454 15.6472 3.0141 15.8385C2.9828 16.0298 3.00777 16.226 3.08595 16.4034C3.16414 16.5807 3.29218 16.7316 3.4545 16.8375C3.61682 16.9434 3.80642 16.9999 4.00025 17H20.0002C20.194 17.0001 20.3837 16.9438 20.5461 16.8381C20.7085 16.7324 20.8367 16.5817 20.9151 16.4045C20.9935 16.2273 21.0187 16.0311 20.9877 15.8398C20.9566 15.6485 20.8707 15.4703 20.7402 15.327C19.4102 13.956 18.0002 12.499 18.0002 8C18.0002 6.4087 17.3681 4.88258 16.2429 3.75736C15.1177 2.63214 13.5915 2 12.0002 2C10.4089 2 8.88282 2.63214 7.75761 3.75736C6.63239 4.88258 6.00025 6.4087 6.00025 8C6.00025 12.499 4.58925 13.956 3.26225 15.326Z" stroke="#05B942" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </span>
                <span>{{ $t('clearance.neverMiss') }}</span>
              </div>
              <h2 class="newsletter-heading">{{ $t('clearance.getNotified') }}</h2>
              <p class="newsletter-description">{{ $t('clearance.newsletterDesc') }}</p>
              <!--
                Shown only once the number is real and worth showing. This was
                a fixed "Thousands of shoppers already signed up", printed to
                every visitor against an empty list.
              -->
              <div class="newsletter-stats" v-if="showSubscriberCount">
                <span class="check-green">✔</span>
                <span>{{ $t('clearance.subscribersSignedUp', { count: subscriberCount }) }}</span>
              </div>
            </div>

            <!-- Subscription Input right -->
            <div class="newsletter-right">
              <form @submit.prevent="submitEmail" class="newsletter-form">
                <div class="newsletter-input-group">
                  <input
                    v-model="emailInput"
                    type="email"
                    required
                    placeholder="your@email.com"
                    class="newsletter-input"
                  />
                  <button type="submit" class="newsletter-btn" :disabled="subscribing">
                    {{ subscribing ? $t('clearance.processing') : $t('clearance.notifyMe') }}
                  </button>
                </div>
                <p v-if="subscribeMessage" class="newsletter-success">{{ subscribeMessage }}</p>
                <p class="newsletter-disclaimer">{{ $t('clearance.newsletterDisclaimer') }}</p>
              </form>
            </div>
          </div>
        </div>
      </section>

      <!-- Live Floating Bar -->
      <div v-if="hero" class="sticky-bottom-bar" :class="{ show: showStickyBar }">
        <div class="sticky-container container-fluid">
          <div class="sticky-product-info">
            <div class="sticky-img-preview">
              <img :src="getThumbImageURL(images[0])" :alt="title" class="sticky-thumb-img" @error="onImgError">
            </div>
            <div class="sticky-text">
              <div class="sticky-title">{{ title }}</div>
              <div class="sticky-pricing">
                <span class="sticky-price-now"><price-format :price="currentPricing" :decimals="2" /></span>
                <span class="sticky-price-old" v-if="prevPrice > 0"><price-format :price="prevPrice" :decimals="2" /></span>
              </div>
            </div>
            <div class="sticky-left-items" v-if="unitsLeft > 0">
              <span class="left-dot"></span>
              <span class="let-title">
                {{ unitsLeft }} {{ $t('clearance.remaining') }}<template v-if="secondsLeft > 0"> · {{ formattedTime }}</template>
              </span>
            </div>
          </div>
          <div class="sticky-actions">
            <button @click="heroBuyNow" class="sticky-btn-buy" :disabled="buyingNow">
              {{ requiresOption ? $t('clearance.chooseOptions') : $t('clearance.buyNow') }}
            </button>
          </div>
        </div>
      </div>
      </template>
    </div>
  </client-only>
</template>

<script>
  import { mapGetters, mapActions } from 'vuex'
  import util from '~/mixin/util'
  import productPriceHelper from '~/mixin/productPriceHelper'
  import cartHelper from '~/mixin/cartHelper'
  import metaHelper from '~/mixin/metaHelper'
  import global from '~/mixin/global'
  import PriceFormat from '~/components/PriceFormat'
  import ProductImages from '~/components/ProductImages'
  import RatingStar from '~/components/RatingStar'
  import PastDealCard from '~/components/PastDealCard'

  export default {
    name: 'clearance',
    middleware: ['common-middleware'],
    mixins: [util, productPriceHelper, cartHelper, metaHelper, global],
    components: { PriceFormat, ProductImages, RatingStar, PastDealCard },
    head() {
      return {
        title: this.$t('clearance.pageTitle')
      }
    },
    data() {
      return {
        loading: true,

        // Inventory bound for the cart mixin (chosen client-side on mount).
        productInventory: null,
        quantity: 1,

        // cartHelper reads these three to decide what a line is worth. Held at
        // "no voucher" on purpose: this page has no voucher picker, and the
        // mixin must not be left reading undefined off the component.
        selectedVoucher: null,
        discountedPrice: 0,
        voucherDiscountAmount: 0,

        imagePopup: false,
        secondsLeft: 0,
        showStickyBar: false,
        showExpired: false,
        timerInterval: null,

        // Newsletter / lead capture
        emailInput: '',
        subscribing: false,
        subscribeMessage: ''
      }
    },
    computed: {
      ...mapGetters('clearance', ['hero', 'pastDeals', 'subscriberTotal']),
      // Sold-out = deactivated deals; expired = deals whose timer ran out.
      soldOutDeals() {
        return (this.pastDeals || []).filter(d => !d.is_expired)
      },
      expiredDeals() {
        return (this.pastDeals || []).filter(d => d.is_expired)
      },
      // The price + cart mixins read `this.product`; derive it from the store
      // so SSR and client render identical markup (no hydration mismatch).
      product() {
        return this.hero?.product || {}
      },
      title() {
        return this.hero?.title || this.product?.title || ''
      },
      subtitle() {
        return this.hero?.subtitle || this.product?.short_heading || ''
      },
      description() {
        return this.hero?.description || this.product?.overview || this.product?.description || ''
      },
      highlights() {
        return Array.isArray(this.hero?.highlights) ? this.hero.highlights : []
      },
      /*
       * How many people have asked to hear about these deals.
       *
       * Held back below a threshold: a truthful "3 shoppers already signed
       * up" is worse for the shop than saying nothing, and a made-up figure
       * is worse than either.
       */
      subscriberCount() {
        return parseInt(this.subscriberTotal || 0)
      },
      showSubscriberCount() {
        return this.subscriberCount >= 50
      },
      /*
       * Can this be bought from here at all, or does the shopper have a
       * choice to make first? Decided server-side by the same test cross-sell
       * uses -- more than one buyable variation.
       */
      requiresOption() {
        return !!this.hero?.requires_option
      },
      // The headline stands for a range, not a price.
      priceFrom() {
        return !!this.hero?.price_from
      },
      claimedPercent() {
        const p = parseInt(this.hero?.claimed_percent || 0)
        return Math.min(Math.max(p, 0), 100)
      },
      ratingValue() {
        return parseFloat(this.product?.rating || 0)
      },
      reviewCount() {
        return parseInt(this.product?.review_count || 0)
      },
      productUrl() {
        return this.productLink(this.product) || '#'
      },
      // Gallery images for the shared ProductImages component (array of {image}).
      galleryImages() {
        return Array.isArray(this.product?.product_images) ? this.product.product_images : []
      },
      images() {
        const imgs = []
        if (this.product?.image) imgs.push(this.product.image)
        if (Array.isArray(this.product?.product_images)) {
          this.product.product_images.forEach(pi => {
            if (pi?.image && !imgs.includes(pi.image)) imgs.push(pi.image)
          })
        }
        return imgs.length ? imgs : [this.defaultImage]
      },
      unitsLeft() {
        if (this.hero?.units_left != null && this.hero.units_left !== '') {
          return parseInt(this.hero.units_left)
        }
        return parseInt(this.product?.stock || 0)
      },
      savedAmount() {
        const saved = this.prevPrice - this.currentPricing
        return saved > 0 ? saved : 0
      },
      formattedTime() {
        const s = Math.max(this.secondsLeft, 0)
        const h = Math.floor(s / 3600).toString().padStart(2, '0')
        const m = Math.floor((s % 3600) / 60).toString().padStart(2, '0')
        const sec = (s % 60).toString().padStart(2, '0')
        return `${h}:${m}:${sec}`
      }
    },
    methods: {
      // Choose the highest-stock inventory row, mirroring the product page.
      pickDefaultInventory() {
        const inv = this.product?.inventory
        if (Array.isArray(inv) && inv.length) {
          const def = [...inv]
            .filter(i => Number(i.quantity) > 0)
            .sort((a, b) => Number(b.quantity || 0) - Number(a.quantity || 0))[0]
          this.productInventory = def || null
        }
      },
      /*
       * Buy Now takes the shopper to the product's own page, and does nothing
       * else.
       *
       * This page is the shop window: it names the deal, shows what it saves
       * and how long it lasts. The buying happens on the product page, which
       * already applies the same deal price, carries the variation picker and
       * describes the rest of what is being bought.
       *
       * It used to call addToCart(true) -- the buy-now path -- which posted to
       * cart/buy-now and pushed straight into the checkout, so one click here
       * carried somebody into a payment flow with no basket step in between.
       * Adding the line from here also meant choosing a variation on the
       * shopper's behalf whenever the product had more than one, which on
       * ex-display stock is the difference between "Excellent, Grey" and
       * "Acceptable, Black".
       *
       * There is nothing to branch on now: a product needing a choice, a
       * product with nothing in stock and an ordinary one all want the same
       * page.
       */
      heroBuyNow() {
        this.$router.push(this.productUrl)
      },
      dealUrl(deal) {
        return this.productLink(deal?.product) || '#'
      },
      dealTitle(deal) {
        return deal?.title || deal?.product?.title || ''
      },
      dealImage(deal) {
        return deal?.product?.image || this.defaultImage
      },
      // Graceful fallback to the default image when a file is missing
      // (mirrors the product detail page / LazyImage behaviour).
      onImgError(e) {
        const fallback = this.getImageURL(this.defaultImage)
        if (e.target.src !== fallback) {
          e.target.src = fallback
        }
      },
      dealOffPercent(deal) {
        return this.discountPercent(
          this.prevPriceCalc(deal?.product),
          this.currentPricingCalc(deal?.product)
        )
      },
      /*
       * A timestamp from the server, in milliseconds.
       *
       * The server keeps its clock in UTC and sends "Y-m-d H:i:s" with no
       * zone marker on it. new Date("2026-09-24T05:55:00") reads that as the
       * VISITOR's local time, so the countdown on this page ran out five and
       * a half hours early for a shopper in IST -- the page said the deal had
       * ended while the basket was still selling it at the deal price.
       *
       * Parsed as UTC, which is what Countdown.vue does for the flash-sale
       * timer with moment.utc(). A value that already carries a zone is left
       * alone.
       */
      serverTime(value) {
        if (!value) return null

        const text = String(value).trim().replace(' ', 'T')
        const hasZone = /(?:[zZ]|[+-][0-9]{2}:?[0-9]{2})$/.test(text)
        const ms = new Date(hasZone ? text : text + 'Z').getTime()

        return Number.isNaN(ms) ? null : ms
      },
      startTimer() {
        const end = this.serverTime(this.hero?.end_time)
        if (end === null) return
        const tick = () => {
          this.secondsLeft = Math.max(0, Math.floor((end - Date.now()) / 1000))
          if (this.secondsLeft <= 0 && this.timerInterval) {
            clearInterval(this.timerInterval)
            this.timerInterval = null
          }
        }
        tick()
        this.timerInterval = setInterval(tick, 1000)
      },
      handleScroll() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0
        this.showStickyBar = scrollTop > 300
      },
      // Fetch the clearance payload, then wire up the hero interactions.
      async fetchingData() {
        this.loading = true
        try {
          await this.fetchClearance({ lang: this.langCode })
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false

        // Wait for the hero markup to render before binding scroll/timer.
        this.$nextTick(() => {
          if (this.hero && this.hero.product) {
            this.pickDefaultInventory()
            this.startTimer()
            window.addEventListener('scroll', this.handleScroll, { passive: true })
          }
        })
      },
      // Newsletter signup — reuses the site-wide email subscription endpoint.
      async submitEmail() {
        const email = (this.emailInput || '').trim()
        const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
        if (!email || !validEmail) {
          this.setToastError(this.$t('contact.invalidEmail'))
          return
        }
        this.subscribing = true
        this.subscribeMessage = ''
        try {
          const data = await this.postRequest({
            // The surface is recorded with the address: this box promises
            // clearance deals, and the footer's box promises the monthly
            // letter. They are different consents and the list has to say so.
            params: { email, source: 'clearance' },
            api: 'emailSubscription'
          })
          if (data && data.status === 200) {
            this.subscribeMessage = this.$t('home.subscribeSuccessMsg')
            this.setToastMessage(this.$t('home.subscribeSuccessMsg'))
            this.emailInput = ''
          } else {
            const err = data && data.data && data.data.form
              ? Object.values(data.data.form)[0]
              : this.$t('contact.invalidEmail')
            this.setToastError(err)
          }
        } catch (e) {
          this.setToastError((e && e.message) || this.$t('contact.invalidEmail'))
        } finally {
          this.subscribing = false
        }
      },
      ...mapActions('clearance', ['fetchClearance', 'emptyClearance']),
      ...mapActions('common', ['postRequest', 'setToastMessage', 'setToastError'])
    },
    async mounted() {
      this.emptyClearance()
      await this.fetchingData()
    },
    beforeDestroy() {
      if (this.timerInterval) clearInterval(this.timerInterval)
      window.removeEventListener('scroll', this.handleScroll)
    }
  }
</script>

<style scoped>
.deal-page {
  font-family: 'Inter', sans-serif;
  color: #130E2B;
  background-color: #fff;
  min-height: 100vh;
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  -webkit-font-smoothing: antialiased
}

.container-fluid {
  max-width: 80rem;
  margin-left: auto;
  margin-right: auto;
  padding-left: 1rem;
  padding-right: 1rem
}


/* Gallery wrapper (hosts the shared ProductImages zoomer + discount badge) */
.gallery-wrap {
  position: relative
}
.gallery-wrap >>> .detail-image {
  margin: 0
}
.gallery-wrap .discount-badge {
  z-index: 6
}

.clearance-empty {
  text-align: center;
  padding: 6rem 1rem
}

.empty-title {
  font-size: 1.875rem;
  font-weight: 800;
  color: #130E2B;
  margin-bottom: .75rem
}

.empty-text {
  color: #6F6980;
  margin-bottom: 1.5rem
}

.empty-cta {
  display: inline-block;
  background-color: #33319A;
  color: #fff;
  font-weight: 700;
  padding: .875rem 1.5rem;
  border-radius: .75rem;
  text-decoration: none
}

.main-content {
  padding-top: 2rem;
  padding-bottom: 2rem
}

@media (min-width:768px) {
  .main-content {
    padding-top: 3rem;
    padding-bottom: 3rem
  }
}

/*
 * minmax(0, 1fr), not 1fr.
 *
 * A '1fr' track is minmax(auto, 1fr), and that 'auto' floor means the column
 * can never be narrower than its content's min-content width. The gallery
 * holds ProductZoomer, which reports a min-content of 400px, so on a 390px
 * phone the column stayed 400px wide: the image card was cut off at the right
 * and the whole page scrolled sideways by 26px. The desktop rule below
 * already used minmax(0, 1fr) for the same reason.
 */
.main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 2rem
}

/* And nothing the zoomer renders may outgrow the column it sits in. */
.col-gallery,
.col-details {
  min-width: 0
}

.gallery-wrap >>> img {
  max-width: 100%
}

@media (min-width:1024px) {
  .main-grid {
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 3rem
  }
}

.col-gallery {
  display: flex;
  flex-direction: column;
  gap: 1rem
}

@media (min-width:1024px) {
  .col-gallery {
    grid-column: span 6
  }
}

.main-display {
  position: relative;
  aspect-ratio: 1 / 1;
  border-radius: 1.5rem;
  overflow: hidden;
  background: #F4F4F9;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  transition: all 0.3s;
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / .1), 0 2px 4px -1px rgb(0 0 0 / .06)
}

.main-image-link {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center
}

.main-image {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  transition: transform 0.5s
}

.main-image:hover {
  transform: scale(1.05)
}

.discount-badge {
  position: absolute;
  top: 1rem;
  left: 1rem;
  background-color: #FF4F4F;
  color: #fff;
  font-weight: 900;
  font-size: .875rem;
  padding: .25rem .75rem;
  border-radius: .375rem;
  box-shadow: 0 1px 3px rgb(0 0 0 / .2);
  z-index: 2
}

.nav-arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 9999px;
  background-color: rgb(255 255 255 / .9);
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / .1);
  cursor: pointer;
  transition: all 0.2s;
  color: #130E2B;
  z-index: 2
}

.nav-arrow:hover {
  background-color: #fff
}

.left-arrow {
  left: 1rem
}

.right-arrow {
  right: 1rem
}

.arrow-icon {
  width: 1.25rem;
  height: 1.25rem
}

.thumbnail-strip {
  display: flex;
  gap: .75rem
}

.thumb-btn {
  width: 5rem;
  aspect-ratio: 1 / 1;
  border-radius: .75rem;
  border: 2px solid #e5e7eb;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: .5rem;
  background-color: #F4F4F9;
  cursor: pointer;
  transition: all 0.2s
}

.thumb-btn:hover {
  border-color: #d1d5db
}

.thumb-btn.active {
  border-color: #33319A;
  box-shadow: 0 0 0 2px rgb(51 49 154 / .2);
  background-color: #f9fafb
}

.thumb-wrapper {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center
}

.thumb-wrapper img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain
}

.col-details {
  display: flex;
  flex-direction: column;
  gap: 1.5rem
}

@media (min-width:1024px) {
  .col-details {
    grid-column: span 6
  }
}

.rating-row {
  display: flex;
  align-items: center;
  gap: .5rem
}

.stars {
  display: flex;
  color: #f59e0b;
  gap: 2px
}

.star-icon {
  font-size: 1.125rem
}

.rating-score {
  font-size: 14px;
  font-weight: 500;
  color: #130E2B
}

.bullet-separator {
  color: #6F6980
}

.title-block {
  display: flex;
  flex-direction: column;
  gap: .25rem
}

.product-title {
  font-size: 2.25rem;
  font-weight: 700;
  color: #130E2B;
  line-height: 1.1;
  letter-spacing: -.02em;
  margin: 0
}

.title-link {
  color: inherit;
  text-decoration: none
}

@media (min-width:768px) {
  .product-title {
    font-size: 48px
  }
}

.product-subtitle {
  font-size: .875rem;
  font-weight: 500;
  letter-spacing: .1em;
  color: #6F6980;
  text-transform: uppercase;
  margin: 0
}

.price-box {
  background-color: #F4F4F9;
  border-radius: 1rem;
  padding: 1.5rem;
  border: 1px solid #E3E3EC;
  display: flex;
  flex-direction: column;
  gap: .5rem
}

.price-row {
  display: flex;
  align-items: baseline;
  gap: 1rem
}

/* Sits against the figure, not competing with it: the headline is still
   the price, "From" only says the price is the lowest of several. */
.price-from-label {
  align-self: center;
  color: #6F6980;
  font-size: .875rem;
  font-weight: 600;
  margin-right: .375rem;
  text-transform: uppercase;
  letter-spacing: .04em
}

.current-price {
  font-size: 2.5rem;
  font-weight: 800;
  color: #33319A;
  letter-spacing: -.02em
}

@media (min-width:768px) {
  .current-price {
    font-size: 48px
  }
}

.old-price,
.old-price >>> span {
  font-size: 1.125rem;
  font-weight: 500;
  color: #6F6980;
  text-decoration: line-through
}

@media (min-width:768px) {
  .old-price {
    font-size: 20px
  }
}

.save-badge {
  color: #05B942;
  font-weight: 700;
  font-size: .875rem;
  letter-spacing: .05em;
  text-transform: uppercase
}

.stock-claim-section {
  display: flex;
  flex-direction: column;
  gap: .5rem
}

.stock-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: .875rem
}

.units-left {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-weight: 800;
  color: #130E2B;
  letter-spacing: .02em;
  text-transform: uppercase
}

.pulse-dot {
  width: .625rem;
  height: .625rem;
  border-radius: 9999px;
  background-color: #05B942;
  animation: pulse-animation 2s cubic-bezier(.4, 0, .6, 1) infinite
}

@keyframes pulse-animation {
  0%, 100% { opacity: 1 }
  50% { opacity: .5 }
}

.selling-fast {
  display: flex;
  align-items: center;
  gap: .25rem;
  font-weight: 700;
  color: #05B942;
  font-size: .75rem;
  text-transform: uppercase
}

.selling-fast-icon {
  width: .875rem;
  height: .875rem
}

.progress-container {
  width: 100%;
  background-color: #ECECF3;
  border-radius: 9999px;
  height: .625rem;
  overflow: hidden
}

.progress-bar {
  background-color: #33319A;
  height: 100%;
  border-radius: 9999px;
  transition: all 1s ease-in-out
}

.claim-status-text {
  font-size: .75rem;
  font-weight: 600;
  color: #6F6980
}

.usp-list {
  list-style-type: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: .75rem;
  font-weight: 600;
  font-size: .875rem
}

.usp-item {
  display: flex;
  align-items: center;
  gap: .625rem
}

.check-icon {
  color: #05B942
}

.quote-box {
  border-left: 4px solid #05B942;
  padding-left: 1rem;
  padding-top: .375rem;
  padding-bottom: .375rem;
  font-size: 1rem;
  color: #130E2B;
  line-height: 1.6
}

.buy-section {
  display: flex;
  flex-direction: column;
  gap: .75rem
}

.buy-btn {
  width: 100%;
  background-color: #33319A;
  color: #fff;
  font-weight: 800;
  font-size: 1.125rem;
  padding: 1rem 1.5rem;
  border: none;
  border-radius: .75rem;
  box-shadow: 0 10px 15px -3px rgb(51 49 154 / .3);
  cursor: pointer;
  transition: all 0.3s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .5rem
}

.buy-btn:hover {
  background-color: #252373;
  box-shadow: 0 20px 25px -5px rgb(51 49 154 / .4)
}

.buy-btn:active {
  transform: scale(.99)
}

.buy-btn:disabled {
  opacity: .7;
  cursor: not-allowed
}

.shipping-highlights {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 1rem;
  font-size: 10px;
  font-weight: 700;
  color: #6F6980;
  text-transform: uppercase;
  letter-spacing: .05em;
  text-align: center
}

.countdown-box {
  background-color: #EAFBF0;
  border: 1px solid #C5F3D1;
  border-radius: .75rem;
  padding: 1rem;
  display: flex;
  align-items: flex-start;
  gap: .75rem
}

.timer-icon-wrapper {
  color: #05B942;
  margin-top: .125rem
}

.timer-icon {
  width: 1.5rem;
  height: 1.5rem
}

.timer-container {
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  gap: .25rem
}

.timer-label {
  font-size: .75rem;
  font-weight: 700;
  color: #05B942;
  letter-spacing: .05em;
  text-transform: uppercase
}

.timer-value {
  font-family: monospace;
  font-size: 1.875rem;
  font-weight: 900;
  color: #130E2B
}

.timer-desc {
  font-size: .75rem;
  color: #6F6980
}

.past-deals-section {
  padding-top: 2rem;
  padding-bottom: 2rem;
  margin-top: 3rem;
  border-top: 1px solid #f3f4f6
}

.divider-section {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-top: 2rem;
  margin-bottom: 2rem
}

.divider-line {
  flex-grow: 1;
  height: 1px;
  background-color: #e5e7eb
}

.divider-label {
  font-size: .75rem;
  font-weight: 700;
  letter-spacing: .1em;
  color: #6F6980;
  text-transform: uppercase;
  background-color: #fff;
  padding-left: 1rem;
  padding-right: 1rem
}

/*
 * Heading and link side by side, until they are fighting for the width.
 * On a 360px phone "Recently sold out" was wrapping to two lines so that
 * "Browse all products" could sit beside it, and both ended up cramped.
 * They take a line each instead.
 */
.past-deals-header {
  display: flex;
  flex-wrap: wrap;
  gap: .25rem 1rem;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 1.5rem
}

@media (max-width:559px) {
  .past-deals-header {
    flex-direction: column;
    align-items: flex-start
  }
}

.past-deals-title {
  font-size: 1.5rem;
  font-weight: 900;
  color: #130E2B;
  margin: 0
}

@media (min-width:768px) {
  .past-deals-title {
    font-size: 1.875rem
  }
}

.past-deals-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.5rem
}

.past-deals-empty {
  color: #6F6980;
  font-size: .875rem;
  padding: 1rem 0
}

.past-deals-grid.mt-24 {
  margin-top: 1.75rem
}

.past-deals-toggle {
  display: flex;
  justify-content: center;
  margin-top: 1.75rem
}

.expired-toggle-btn {
  background: #fff;
  border: 1px solid #d1d5db;
  color: #33319A;
  font-weight: 700;
  font-size: .8125rem;
  letter-spacing: .03em;
  text-transform: uppercase;
  padding: 0 1.5rem;
  border-radius: 9999px;
  cursor: pointer;
  transition: all .2s
}

.expired-toggle-btn:hover {
  background: #F4F4FA;
  border-color: #33319A
}

@media (min-width:640px) {
  .past-deals-grid {
    grid-template-columns: repeat(2, 1fr)
  }
}

@media (min-width:1024px) {
  .past-deals-grid {
    grid-template-columns: repeat(4, 1fr)
  }
}

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

/*
 * Padded to a thumb-sized target rather than left as an 18px line of text.
 * The negative margin keeps it sitting where it did against the heading, so
 * the hit area grows without the layout moving.
 */
.past-deals-link {
  font-size: .75rem;
  font-weight: 700;
  color: #33319A;
  letter-spacing: .05em;
  text-transform: uppercase;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  padding: 0 .5rem;
  margin: 0 -.5rem
}

.past-deals-link:hover {
  text-decoration: underline
}

.wishlist-btn {
  position: absolute;
  top: .75rem;
  right: .75rem;
  color: #9ca3af;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1rem;
  z-index: 11;
  transition: color 0.2s
}

.wishlist-btn:hover {
  color: #ef4444
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

.stars-gold {
  color: #f59e0b
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

/* ---- Never miss a deal / lead capture ---- */
.lead-capture {
  padding-top: 3rem;
  padding-bottom: 3rem
}

.newsletter-box {
  background-color: #F4F4FA;
  border-radius: 1.5rem;
  /*
   * 2rem each side took 64px out of a 360px screen and left this block inset
   * 49px while every other section on the page starts at 16px.
   */
  padding: 1.25rem;
  border: 1px solid #E3E3EC;
  position: relative;
  overflow: hidden;
  box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05)
}

@media (min-width:480px) {
  .newsletter-box {
    padding: 2rem
  }
}

@media (min-width:768px) {
  .newsletter-box {
    padding: 3rem
  }
}

.newsletter-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 2rem;
  align-items: center
}

@media (min-width:1024px) {
  .newsletter-grid {
    grid-template-columns: repeat(12, minmax(0, 1fr))
  }
}

.newsletter-left {
  display: flex;
  flex-direction: column;
  gap: 1rem
}

@media (min-width:1024px) {
  .newsletter-left {
    grid-column: span 7
  }
}

.newsletter-tag {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  color: #33319A;
  font-weight: 700;
  font-size: .75rem;
  text-transform: uppercase;
  background-color: rgb(51 49 154 / .1);
  padding: .25rem .75rem;
  border-radius: 9999px;
  align-self: flex-start
}

.bell-emoji {
  display: inline-flex;
  font-size: .875rem
}

.newsletter-heading {
  font-size: 1.875rem;
  font-weight: 800;
  color: #130E2B;
  margin: 0
}

@media (min-width:768px) {
  .newsletter-heading {
    font-size: 2.25rem
  }
}

.newsletter-description {
  font-size: .875rem;
  color: #6F6980;
  max-width: 36rem;
  line-height: 1.5;
  margin: 0
}

.newsletter-stats {
  display: flex;
  align-items: center;
  gap: .5rem;
  color: #05B942;
  font-weight: 600;
  font-size: .75rem
}

.check-green {
  font-size: 1rem
}

.newsletter-right {
  width: 100%
}

@media (min-width:1024px) {
  .newsletter-right {
    grid-column: span 5
  }
}

.newsletter-form {
  display: flex;
  flex-direction: column;
  gap: .75rem
}

.newsletter-input-group {
  display: flex;
  flex-direction: column;
  gap: .75rem
}

@media (min-width:640px) {
  .newsletter-input-group {
    flex-direction: row
  }
}

.newsletter-input {
  flex-grow: 1;
  padding: .875rem 1rem;
  border-radius: .75rem;
  border: 1px solid #d1d5db;
  font-size: .875rem;
  color: #130E2B
}

.newsletter-input:focus {
  outline: none;
  border-color: #33319A;
  box-shadow: 0 0 0 2px rgb(51 49 154 / .2)
}

.newsletter-btn {
  background-color: #33319A;
  color: #fff;
  font-weight: 800;
  font-size: .875rem;
  padding: 0 1.5rem;
  border: none;
  border-radius: .75rem;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 1px 2px rgb(0 0 0 / .05);
  white-space: nowrap
}

.newsletter-btn:hover {
  background-color: #252373
}

.newsletter-btn:disabled {
  opacity: .7;
  cursor: not-allowed
}

.newsletter-success {
  font-size: .75rem;
  font-weight: 600;
  color: #05B942;
  margin: 0
}

.newsletter-disclaimer {
  font-size: 10px;
  color: #6F6980;
  text-align: center;
  margin: 0
}

@media (min-width:640px) {
  .newsletter-disclaimer {
    text-align: left
  }
}

.sticky-bottom-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background-color: #fff;
  border-top: 1px solid #e5e7eb;
  padding: .75rem 1rem;
  box-shadow: 0 -10px 15px -3px rgb(0 0 0 / .05);
  z-index: 9999;
  transition: transform 0.3s ease-in-out;
  transform: translateY(100%)
}

.sticky-bottom-bar.show {
  transform: translateY(0)
}

.sticky-container {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem
}

/*
 * The bar is a row in which nothing could shrink: a 48px thumbnail, the
 * product name at its full length, the price, and "91 remaining - 62:46:48"
 * with 1.5rem of margin in front of it. On a 360px phone that came to more
 * than the screen and pushed Buy Now 52px past the right edge, where it
 * cannot be tapped and cannot be scrolled to -- the bar is fixed, so the page
 * scrolling sideways does not bring it back.
 *
 * The name is the part that gives: it can be read in full further up the
 * page, and the button cannot be.
 */
.sticky-product-info {
  display: flex;
  align-items: center;
  gap: .75rem;
  flex: 1 1 auto;
  min-width: 0
}

.sticky-text {
  min-width: 0
}

.sticky-actions {
  flex: 0 0 auto
}

.sticky-img-preview {
  width: 3rem;
  height: 3rem;
  background-color: #F4F4F9;
  border-radius: .5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden
}

.sticky-thumb-img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain
}

.sticky-title {
  font-weight: 400;
  font-size: 12px;
  color: #6F6980;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap
}

.sticky-pricing {
  display: flex;
  align-items: center;
  gap: .5rem
}

.sticky-price-now {
  color: #333199;
  font-weight: 700;
  font-size: 20px
}

.sticky-price-old,
.sticky-price-old >>> span {
  color: #6F6980;
  text-decoration: line-through;
  font-size: 14px
}

.sticky-left-items {
  display: flex;
  align-items: center;
  gap: .375rem;
  margin-left: .75rem;
  flex: 0 0 auto;
  white-space: nowrap
}

/*
 * Below this the bar has room for the product and the button and nothing
 * else. The stock and the timer are both already on screen further up.
 */
@media (max-width:559px) {
  .sticky-left-items {
    display: none
  }
}

@media (min-width:640px) {
  .sticky-left-items {
    margin-left: 1.5rem
  }
}

.left-dot {
  width: .5rem;
  height: .5rem;
  border-radius: 9999px;
  background-color: #05B942
}

.let-title {
  font-size: 12px;
  font-weight: 400;
  color: #05B942
}

.sticky-actions {
  display: flex;
  align-items: center;
  gap: 1rem
}

.sticky-btn-buy {
  white-space: nowrap;
  background-color: #05B942;
  color: #fff;
  font-weight: 800;
  font-size: .75rem;
  padding: 0 1.25rem;
  border: none;
  border-radius: .5rem;
  cursor: pointer;
  transition: background-color 0.2s;
  text-transform: uppercase
}

@media (min-width:640px) {
  .sticky-btn-buy {
    font-size: .875rem
  }
}

.sticky-btn-buy:hover {
  background-color: #049a37
}

.sticky-btn-buy:disabled {
  opacity: .7;
  cursor: not-allowed
}

/* ==========================================================================
   Phone and small tablet type scale.

   The page was built desktop-down: a 36px product title and a 40px price are
   right on a 1440px screen and shout on a 360px one, where the title alone
   took four lines. These bring it onto the same footing as the home page
   (assets/styles/home-mobile.styl) -- 17px section headings, a price in the
   low twenties, body copy at 12.5-13.5px -- so the two read as one shop.

   Last in the file and written as max-width, so it overrides the mobile-first
   rules above at the widths they overlap on, and leaves the desktop page
   exactly as it was.
   ========================================================================== */
@media only screen and (max-width: 991px) {

  /* ── the hero ─────────────────────────────────────────────────────── */
  .product-title,
  .title-link {
    font-size: 22px;
    line-height: 1.25
  }

  .product-subtitle {
    font-size: 12.5px
  }

  .rating-score {
    font-size: 12px
  }

  .star-icon {
    font-size: 15px
  }

  .discount-badge {
    font-size: 12px
  }

  /* ── the money ────────────────────────────────────────────────────── */
  .current-price {
    font-size: 26px
  }

  .old-price,
  .price-reverts {
    font-size: 14px
  }

  .save-badge {
    font-size: 12px
  }

  /* ── stock, urgency and the timer ─────────────────────────────────── */
  .stock-row {
    font-size: 12.5px
  }

  .selling-fast,
  .claim-status-text {
    font-size: 11px
  }

  .timer-label,
  .timer-desc {
    font-size: 11px
  }

  .timer-value {
    font-size: 22px
  }

  /* ── copy and the buy box ─────────────────────────────────────────── */
  .quote-box {
    font-size: 13.5px
  }

  .usp-list {
    font-size: 13px
  }

  .buy-btn {
    font-size: 15px
  }

  .shipping-highlights {
    font-size: 9.5px
  }

  /* ── past deals ───────────────────────────────────────────────────── */
  .divider-label,
  .past-deals-link {
    font-size: 11px
  }

  .past-deals-title {
    font-size: 17px
  }

  .past-deals-empty,
  .expired-toggle-btn {
    font-size: 12.5px
  }

  .past-deal-title-text {
    font-size: 12.8px
  }

  .past-deal-price {
    font-size: 15px
  }

  .past-deal-old-price,
  .past-deal-rating {
    font-size: 11.5px
  }

  .past-deal-badge,
  .past-deal-off-badge {
    font-size: 9.5px
  }

  .sold-out-stamp {
    font-size: 14px
  }

  /* ── notify block ─────────────────────────────────────────────────── */
  .newsletter-tag,
  .newsletter-stats,
  .newsletter-success,
  .newsletter-disclaimer {
    font-size: 11px
  }

  .newsletter-heading {
    font-size: 20px
  }

  .newsletter-description {
    font-size: 12.5px
  }

  .newsletter-input,
  .newsletter-btn {
    font-size: 13px
  }

  /* ── empty state ──────────────────────────────────────────────────── */
  .empty-title {
    font-size: 20px
  }

  .empty-text {
    font-size: 12.5px
  }

  /* ── the fixed buy bar ────────────────────────────────────────────── */
  .sticky-title {
    font-size: 11px
  }

  .sticky-price-now {
    font-size: 16px
  }

  .sticky-price-old,
  .let-title {
    font-size: 11.5px
  }
}

/* The smallest phones still in use, as home-mobile.styl does at the same
   width: one more step down for the two figures that dominate the screen. */
@media only screen and (max-width: 400px) {

  .product-title,
  .title-link {
    font-size: 20px
  }

  .current-price {
    font-size: 23px
  }

  .newsletter-heading {
    font-size: 18px
  }

  .timer-value {
    font-size: 20px
  }
}

/* ==========================================================================
   The gallery thumbnails, on a phone.

   detail-images.styl sizes every thumbnail 70x70 with 10px of padding and 5px
   of margin -- 80px each -- and lays them in a nowrap row with a prev/next
   control on either side. Four of those is 380px, which does not fit the
   column on any phone: the strip was cut off at the right, the arrow hung 16px
   off the left, and the page scrolled sideways to reach it.

   Smaller, and allowed to wrap, so every thumbnail is reachable without a
   scroll and nothing leaves the column. Once they all fit there is nothing for
   the prev/next controls to do, so they go.
   ========================================================================== */
@media only screen and (max-width: 991px) {

  .gallery-wrap >>> .thumb-list,
  .gallery-wrap >>> .pleceholder-thumb-list {
    flex-wrap: wrap;
    justify-content: center;
    margin-left: 0;
    margin-right: 0
  }

  .gallery-wrap >>> .thumb-list .responsive-image,
  .gallery-wrap >>> .pleceholder-thumb-list .responsive-image {
    width: 56px !important;
    height: 56px !important;
    margin: 4px;
    padding: 6px
  }

  /* The prev/next controls, which only exist because the row could not wrap. */
  .gallery-wrap >>> .thumb-list > .zoomer-control,
  .gallery-wrap >>> .zoomer-control {
    display: none
  }
}

@media only screen and (max-width: 400px) {

  .gallery-wrap >>> .thumb-list .responsive-image,
  .gallery-wrap >>> .pleceholder-thumb-list .responsive-image {
    width: 48px !important;
    height: 48px !important;
    margin: 3px;
    padding: 5px
  }
}
</style>
