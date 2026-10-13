<template>
  <div class="container shared-wishlist mtb-20 pb-30">

    <!--
      Someone's shared wishlist, opened from the link on their My Wishlist
      page. It shows the products and the owner's first name only; prices
      are the viewer's own, the same as the product page would show them.
    -->
    <div v-if="loading" class="sw-grid" aria-busy="true" aria-label="Loading wishlist">
      <skeleton-product
        v-for="index in 8"
        :key="`sk-${index}`"
      />
    </div>

    <div v-else-if="notFound" class="sw-empty">
      <i class="fa-regular fa-heart" aria-hidden="true"></i>
      <h1 class="sw-title">This wishlist is no longer shared</h1>
      <p class="sw-sub">The link may have been switched off by its owner, or it was copied incorrectly.</p>
      <nuxt-link to="/" class="sw-btn">Continue shopping</nuxt-link>
    </div>

    <template v-else>
      <header class="sw-head">
        <div>
          <p class="sw-kicker">Shared wishlist</p>
          <h1 class="sw-title">{{ owner ? `${owner}'s wishlist` : 'A shared wishlist' }}</h1>
          <p class="sw-sub">
            {{ total }} {{ total === 1 ? 'item' : 'items' }} picked out on FixMyPC.
            Prices and stock are live, so they may differ from when these were saved.
          </p>
        </div>
      </header>

      <div v-if="!items.length" class="sw-empty sw-empty-inline">
        <p class="sw-sub">There is nothing on this wishlist right now.</p>
        <nuxt-link to="/" class="sw-btn">Browse the shop</nuxt-link>
      </div>

      <!--
        Inside the shop listing's own wrapper, so the tile is dressed exactly as
        it is in a category (the card styles live under .category-listing
        .body .tile-container). .body is forced to one column: there is no
        filter sidebar here.
      -->
      <div v-else class="category-listing sw-listing">
        <div class="body sw-body">
          <div class="tile-container">
            <product-tile
              v-for="product in items"
              :key="product.id"
              :product="product"
            />
          </div>
        </div>
      </div>

      <div v-if="lastPage > 1" class="sw-pager">
        <button
          type="button"
          class="sw-btn sw-btn-ghost"
          :disabled="page <= 1 || loading"
          @click="load(page - 1)"
        >
          Previous
        </button>
        <span class="sw-sub">Page {{ page }} of {{ lastPage }}</span>
        <button
          type="button"
          class="sw-btn sw-btn-ghost"
          :disabled="page >= lastPage || loading"
          @click="load(page + 1)"
        >
          Next
        </button>
      </div>
    </template>
  </div>
</template>

<script>
  import ProductTile from '~/components/ProductTile'
  import util from '~/mixin/util'
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware'],
    components: {ProductTile},
    mixins: [util, global],
    head() {
      return {
        title: this.owner ? `${this.owner}'s wishlist` : 'Shared wishlist',
        // A private link: keep it out of search results.
        meta: [{hid: 'robots', name: 'robots', content: 'noindex, nofollow'}]
      }
    },
    data() {
      return {
        loading: true,
        notFound: false,
        owner: null,
        items: [],
        total: 0,
        page: 1,
        lastPage: 1
      }
    },
    methods: {
      async load(page = 1) {
        this.loading = true

        try {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const token = encodeURIComponent(this.$route.params.token || '')

          const {data} = await this.$axios.get(`${baseUrl}api/v1/wishlist/shared/${token}`, {
            params: {page},
            headers: {language: this.$store.getters['language/langCode'] || ''}
          })

          if (data?.status !== 200) {
            this.notFound = true
            return
          }

          this.owner = data.data?.owner || null
          this.items = data.data?.data || []
          this.total = data.data?.total || 0
          this.page = data.data?.current_page || 1
          this.lastPage = data.data?.last_page || 1

          if (process.client && page > 1) {
            window.scrollTo({top: 0, behavior: 'smooth'})
          }
        } catch (e) {
          this.notFound = true
        } finally {
          this.loading = false
        }
      }
    },
    mounted() {
      this.load(1)
    }
  }
</script>

<style scoped>
.shared-wishlist {
  color: #130E2B;
}

.sw-head {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 16px;
  padding: 22px 24px;
  margin-bottom: 20px;
}

.sw-kicker {
  font-size: 13px;
  color: #6B7280;
  margin: 0 0 4px;
}

.sw-title {
  font-size: 26px;
  font-weight: 700;
  margin: 0 0 6px;
  overflow-wrap: anywhere;
}

.sw-sub {
  font-size: 14px;
  color: #6B7280;
  margin: 0;
  line-height: 1.55;
}

/*
 * Its own grid rather than .tile-container, which pins tiles to 20% of the
 * row and does not reflow on a phone.
 */
.sw-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 16px;
}

.sw-grid > * {
  min-width: 0;
  width: 100% !important;
  max-width: 100% !important;
  margin: 0 !important;
}

.sw-empty {
  text-align: center;
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 16px;
  padding: 48px 20px;
}

.sw-empty i {
  font-size: 34px;
  color: #C7C7D9;
  margin-bottom: 12px;
  display: block;
}

.sw-empty .sw-sub { margin-bottom: 18px; }
.sw-empty-inline { padding: 32px 20px; }

/* links and buttons alike; own metrics so base.styl's button sizing stays out */
.sw-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 42px;
  line-height: 1;
  padding: 0 18px;
  border: 1px solid #33319A;
  border-radius: 8px;
  background: #33319A;
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  cursor: pointer;
}

.sw-btn:hover {
  background: #28267d !important;
  color: #fff;
}

.sw-btn-ghost {
  background: #fff;
  color: #130E2B;
  border-color: #D7DAE9;
}

.sw-btn-ghost:hover:enabled {
  background: #F5F5FF !important;
  color: #33319A;
  border-color: #33319A;
}

.sw-btn:disabled {
  opacity: .5;
  cursor: not-allowed;
}

.sw-pager {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 16px;
  margin-top: 24px;
}

/* global CSS wins specificity ties here, hence !important */
.sw-listing .sw-body {
  grid-template-columns: minmax(0, 1fr) !important;
  padding-bottom: 0 !important;
}

@media (max-width: 767px) {
  .sw-head { padding: 16px; }
  .sw-title { font-size: 21px; }
  .sw-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
}
</style>
