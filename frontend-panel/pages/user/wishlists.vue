<template>
  <account-layout
    active-route="wishlists"
    @clicked-wishlists="loadData"
    class="flow-hidden"
  >
    <template v-slot:rightArea>

      <transition name="fade" mode="out-in">
        <div class="spinner-wrapper flex" v-if="fetchingWishlistData">
          <spinner :radius="100" />
        </div>
      </transition>

      <div class="card-box p-4 mb-4 d-flex justify-content-between align-items-center flex-wrap my-orders-box">
          <div>
              <h5 class="mb-1">My Wishlist</h5>
              <small class="text-muted">View and manage your wishlist items.</small>
          </div>
          <a href="#" class="share-link d-flex align-items-center share-whishlist" >
              <i class="fa-solid fa-share-nodes" style="color: rgb(107, 114, 128);"></i><span class="ms-2 ">Share Wishlist</span>
          </a>
      </div>
      <div class="Frequently-card-box p-0 mb-4">
        <div class="section-header d-flex justify-content-between align-items-center">
            <span>Active Wishlist</span>
            <small class="text-muted">🛡️ Account is secure</small>
        </div>
        <div class="p-3">
            <div v-if="currentWishLists && !currentWishLists.length" class="info-msg">
              {{ $t('wishlist.noWishlist') }}
            </div>
            <div v-else>
              <product-view-list
                v-for="(value, index) in currentWishLists"
                  :key="index"
                  :product="value.product"
                  :index="index"
                  type="wishlist"
                  @removed="fetchingData"
                />
            </div>
        </div>
      </div>

      

      

      <!-- <div
        v-else
        class="area"
      >
        <div class="area-content">
          <div class="tile-container">
            <div class="shimmer-wrapper pt-15" v-if="fetchingWishlistData">
              <tile-shimmer
                v-for="index in 8"
                :key="index"
              />
            </div>
            <template v-else>
              <product-tile
                v-for="(value, index) in currentWishLists"
                :key="index"
                :product="value.product"
              />
            </template>
          </div>
        </div>
      </div> -->

      <pagination
        class="mt-20 mt-sm-15"
        ref="wishlistPagination"
        :total-page="totalPage"
        @fetching-data="fetchingData"
      />

    </template>
  </account-layout>
</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import RatePopup from '~/components/RatePopup'
  import AccountLayout from '~/components/AccountLayout'
  import ProductTile from '~/components/ProductTile'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import Pagination from '~/components/Pagination'
  import productHelper from '~/mixin/productHelper'
  import Spinner from "~/components/Spinner";
  import TileShimmer from "~/components/TileShimmer";
  import global from '~/mixin/global'
import ProductViewList from '../../components/ProductViewList.vue'

  export default {

    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: 'Wishlists',
        meta: []
      }
    },
    data() {
      return {
        allWishlist: null,
        fetchingWishlistData: false
      }
    },
    watch: {},
    components: {
      TileShimmer,
      Spinner,
      LazyImage,
      RatePopup,
      AccountLayout,
      Pagination,
      ProductTile
    },
    mixins: [util, routeParamHelper, productHelper, global],
    computed: {
      totalPage() {
        return this.allWishlist?.last_page
      },
      currentWishLists() {
        return this.allWishlist?.data
      },
      ...mapGetters('language', ['langCode']),
    },
    methods: {
      async loadData() {
        this.$refs.wishlistPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingWishlistData = true
        setTimeout(async () => {

          try {
            this.settingRouteParam()


            const data = await this.getRequest({
              params: {
                time_zone: this.timeZone,
                order_by: this.orderBy,
                type: this.orderByType,
                page: this.page,
                q: this.search
              },
              api: 'userWishlistAll',
              requiredToken: true,
              lang: this.langCode,
            })

            if (data?.status !== 200) {
              this.hasError(data)
            } else if (data?.status === 200) {

              this.allWishlist = data.data

            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.fetchingWishlistData = false
        }, 100)

      },
      ...mapActions('common', ['getRequest']),
    },

    async mounted() {
      await this.fetchingData()
    },
  }
</script>

<style scoped>
.section-header {
    background: #F3F3FA;
    padding: 12px 20px;
    border-top-left-radius: 15px;
    border-top-right-radius: 15px;
    font-weight: 400;
    font-size: 18px;
    color: #130E2B;
}

.Frequently-card-box {
    background-color: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
}

.my-orders-box {
    background-color: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
}

a.share-whishlist {
    font-size: 14px;
    font-weight: 400;
    color: #6B7280;
    text-decoration: none;
}   
</style>
