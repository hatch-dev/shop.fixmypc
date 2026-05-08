<template>
  <account-layout
    active-route="recently-viewed"
    @clicked-wishlists="loadData"
    class="flow-hidden"
  >
    <template v-slot:rightArea>

      <transition name="fade" mode="out-in">
        <div class="spinner-wrapper flex" v-if="fetchingRecentlyViewedData">
          <spinner :radius="100" />
        </div>
      </transition>

      <div class="card-box p-4 mb-4 d-flex justify-content-between align-items-center flex-wrap my-orders-box">
          <div>
              <h5 class="mb-1">Recently Viewed</h5>
              <small class="text-muted">View and manage your recently viewed items.</small>
          </div>
          <div class="d-flex gap-3">
            <div class="date-filter-wrapper d-flex align-items-center">
              <i class="fa-solid fa-calendar-days me-2 calendar-icon"></i>
              <select 
                v-model="selectedRange" 
                @change="fetchingData" 
                class="date-filter-select"
              >
                <option value="7_days">Last 7 Days</option>
                <option value="30_days">Last 30 Days</option>
                <option value="90_days">Last 3 Months</option>
                <option value="all">All Time</option>
              </select>

            </div>
            <a href="#" @click.prevent="clearHistory" class="share-link d-flex align-items-center share-whishlist" >
                <i class="fa-solid fa-trash-can" style="color: rgb(82, 90, 107);"></i><span class="ms-2 ">Clear History</span>
            </a>
          </div>
      </div>
      <div class="Frequently-card-box p-0 mb-4">
        <div class="section-header d-flex justify-content-between align-items-center">
            <span>Active Recently Viewed</span>
            <small class="text-muted">🛡️ Account is secure</small>
        </div>
        <div class="p-3">
            <div v-if="currentRecentlyViewed && !currentRecentlyViewed.length" class="info-msg">
               No recently viewed products found.
            </div>
            <div v-else>
              <product-view-list
                v-for="(value, index) in currentRecentlyViewed"
                  :key="index"
                  :product="value.product"
                  :index="index"
                  type="recently_viewed"
                  @removed="fetchingData"
                />
            </div>
        </div>
      </div>

      <pagination
        class="mt-20 mt-sm-15"
        ref="recentlyViewedPagination"
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
        title: 'Recently Viewed',
        meta: []
      }
    },
    data() {
      return {
        allRecentlyViewed: null,
        fetchingRecentlyViewedData: false,
        selectedRange: '30_days'
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
        return this.allRecentlyViewed?.last_page
      },
      currentRecentlyViewed() {
        return this.allRecentlyViewed?.data
      },
      ...mapGetters('language', ['langCode']),
    },
    methods: {
      async removeItem() {
        await this.$store.dispatch('common/deleteRequest', {
          api: `deleteRecentlyViewed/${this.product.id}`,
          requiredToken: true
        })

        this.$emit('removed')
      },
      async clearHistory() {
        this.fetchingRecentlyViewedData = true
        await this.deleteRequest({
          api: 'clearRecentlyViewed',
          requiredToken: true
        })
        this.allRecentlyViewed = null
        this.fetchingData()
      },
      async loadData() {
        this.$refs.recentlyViewedPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingRecentlyViewedData = true
        setTimeout(async () => {

          try {
            this.settingRouteParam()
            const data = await this.getRequest({
              params: {
                page: this.page,
                range: this.selectedRange
              },
              api: 'userRecentlyViewed',
              requiredToken: true,
            })

            if (data?.status !== 200) {
              this.hasError(data)
            } else if (data?.status === 200) {

              this.allRecentlyViewed = data.data

            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.fetchingRecentlyViewedData = false
        }, 100)

      },
      ...mapActions('common', ['getRequest', 'deleteRequest']),
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
.date-filter-wrapper {
  padding: 6px 12px;
  border-radius: 10px;
  border: 1px solid #E3E3EF;
  background: #F9F9FB;
}

.calendar-icon {
  color: #525A6B;
  font-size: 14px;
}

.date-filter-select {
  border: none;
  background: transparent;
  outline: none;
  font-size: 14px;
  color: #525A6B;
  cursor: pointer;
}
</style>
