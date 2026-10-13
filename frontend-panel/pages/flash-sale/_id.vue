<template>
  <client-only>
    <div class="container-fluid mtb-20 mtb-sm-15">
      <div class="flash-list">
        <!--
          First load: the grid the tiles are about to fill, as direct children
          of it so they land on the real columns.
        -->
        <div class="tile-container" v-if="!currentItems">
          <skeleton-product
            v-for="index in shimmerCount.FLASH_SALE"
            :key="`sk-first-${index}`"
          />
        </div>
        <div class="pos-rel" v-else>

          <!--
            Paging through the sale is the same wait, so it gets the same
            answer. The white overlay spinner that used to sit here covered
            the tiles it was replacing, and the page height still jumped
            between a full page of results and a short last one.
          -->
          <div v-if="fetchingProductData" class="tile-container d-flex">
            <skeleton-product
              v-for="index in shimmerCount.FLASH_SALE"
              :key="`sk-page-${index}`"
            />
          </div>

          <div
            v-else-if="currentItems && !currentItems.length"
            class="info-msg"
          >
            {{ $t('listingLayout.noProductFound') }}
          </div>

          <div v-else class="tile-container d-flex">
            <flash-product-tile
              v-for="(value, index) in currentItems"
              :key="value.id"
              :product="value"
              class="p-tile"
            />
          </div>

          <pagination
            :total-page="totalPage"
            @fetching-data="fetchingData"
          />
        </div>
      </div>

    </div>
  </client-only>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import metaHelper from '~/mixin/metaHelper'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import FlashProductTile from "~/components/FlashProductTile";
  import Pagination from "~/components/Pagination";
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware'],
    components: {Pagination, FlashProductTile},
    head() {
      return {}
    },
    data() {
      return {
        activate: false,
        fetchingProductData: false
      }
    },
    mixins: [util, metaHelper, routeParamHelper, global],
    computed: {
      currentItems() {
        return this.products?.data || null
      },
      totalPage() {
        return this.products?.last_page
      },
      flashId() {
        return this.$route?.params?.id
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('flashSale', ['products']),
    },
    methods: {
      async fetchingData() {
        this.fetchingProductData = true
        await setTimeout(async () => {
          try {
            this.settingRouteParam()
            await this.fetchFlashProducts({
                lang: this.langCode,
                params: {
                  id: this.flashId,
                  page: this.page,
                }
              })
            this.fetchingProductData = false
          } catch (e) {
            return this.$nuxt.error(e)
          }
        }, 100)
      },
      ...mapActions('flashSale', ['fetchFlashProducts', 'emptyFlashProducts']),
    },
    async mounted() {
      this.emptyFlashProducts();
      await this.fetchingData()
    }
  }
</script>
