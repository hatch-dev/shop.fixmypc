<template>
  <account-layout
    active-route="compared"
    @clicked-compared="loadData"
    class="flow-hidden"
  >
    <template v-slot:rightArea>

      <!--
        No overlay spinner. The tiles are about to land in a known grid, so
        the grid itself is the loading state: it holds the page's height,
        gives the reader the shape of what is coming, and is replaced in
        place rather than cutting from grey to content.

        The empty-state test is also guarded by the fetch now - an empty
        `currentCompared` during the first load is "not arrived yet", not
        "nothing to compare", and it used to announce the latter.
      -->
      <div
        v-if="!fetchingComparedData && currentCompared && !currentCompared.length"
        class="info-msg"
      >
        {{ $t('compared.noData') }}
      </div>

      <div
        v-else
        class="area"
      >
        <div class="area-content">
          <div class="tile-container d-flex">
            <template v-if="fetchingComparedData">
              <skeleton-product
                v-for="index in 8"
                :key="`sk-${index}`"
              />
            </template>
            <template v-else>
              <compared-tile
                v-for="(value, index) in currentCompared"
                :key="index"
                :product="value.product"
                @removed="removedSuccessfully"
              />
            </template>
          </div>
        </div>
      </div>

      <pagination
        class="mt-20 mt-sm-15"
        ref="comparedPagination"
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
  import ComparedTile from '~/components/ComparedTile'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import Pagination from '~/components/Pagination'
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: 'Compared',
        meta: []
      }
    },
    data() {
      return {
        fetchingComparedData: false,
        allCompared: null,
      }
    },
    watch: {},
    components: {
      LazyImage,
      RatePopup,
      AccountLayout,
      Pagination,
      ComparedTile
    },
    mixins: [util, routeParamHelper, global],
    computed: {
      totalPage() {
        return this.allCompared?.last_page
      },
      currentCompared() {
        return this.allCompared?.data
      },
      ...mapGetters('language', ['langCode']),
    },
    methods: {
      async removedSuccessfully() {
        await this.fetchingData()
      },
      async loadData() {
        this.$refs.comparedPagination.routeParam()
      },
      async fetchingData() {
        this.fetchingComparedData = true
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
              api: 'compareListAll',
              requiredToken: true,
              lang: this.langCode,
            })

            if (data?.status !== 200) {
              this.hasError(data)
            } else if (data?.status === 200) {
              this.allCompared = data.data
            }
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.fetchingComparedData = false
        }, 100)

      },
      ...mapActions('common', ['getRequest']),
    },
    async mounted() {
      await this.fetchingData()
    },
  }
</script>

<style>

</style>
