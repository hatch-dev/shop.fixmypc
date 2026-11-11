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

          <!--
            Side by side: price, stock, brand, rating, the variant options and
            each specification row (CompareListsController::all sends
            product.compare and product.specifications).
          -->
          <section
            v-if="!fetchingComparedData && compareProducts.length"
            class="compare-section"
            :aria-label="$t('ui.comparedPage.heading')"
          >
            <h2 class="compare-heading">{{ $t('ui.comparedPage.heading') }}</h2>
            <div class="compare-scroll">
              <table class="compare-table">
                <thead>
                  <tr>
                    <th scope="col" class="compare-feature">{{ $t('ui.comparedPage.feature') }}</th>
                    <th v-for="product in compareProducts" :key="`head-${product.id}`" scope="col">
                      <nuxt-link :to="productLink(product)">{{ product.title }}</nuxt-link>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <th scope="row">{{ $t('ui.comparedPage.price') }}</th>
                    <td v-for="product in compareProducts" :key="`price-${product.id}`">
                      <price-format :price="product.display_price" />
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">{{ $t('ui.comparedPage.availability') }}</th>
                    <td v-for="product in compareProducts" :key="`stock-${product.id}`">
                      {{ product.in_stock ? $t('ui.comparedPage.inStock') : $t('ui.comparedPage.outOfStock') }}
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">{{ $t('ui.comparedPage.brand') }}</th>
                    <td v-for="product in compareProducts" :key="`brand-${product.id}`">
                      {{ (product.compare && product.compare.brand) || '—' }}
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">{{ $t('ui.comparedPage.rating') }}</th>
                    <td v-for="product in compareProducts" :key="`rating-${product.id}`">
                      <template v-if="product.compare && product.compare.review_count">
                        {{ $t('ui.comparedPage.ratingValue', {rating: product.compare.rating}) }}
                        <span class="compare-muted">
                          {{ $t('ui.comparedPage.reviewCount', {count: product.compare.review_count}) }}
                        </span>
                      </template>
                      <template v-else>{{ $t('ui.comparedPage.noReviews') }}</template>
                    </td>
                  </tr>
                  <tr v-for="option in optionRows" :key="`option-${option}`">
                    <th scope="row">{{ option }}</th>
                    <td v-for="product in compareProducts" :key="`option-${option}-${product.id}`">
                      {{ optionValues(product, option) }}
                    </td>
                  </tr>
                  <template v-for="row in specRows">
                    <tr v-if="row.groupStart && row.group" :key="`group-${row.key}`" class="compare-group">
                      <th scope="rowgroup" :colspan="compareProducts.length + 1">{{ row.group }}</th>
                    </tr>
                    <tr :key="`spec-${row.key}`">
                      <th scope="row">{{ row.label }}</th>
                      <td v-for="product in compareProducts" :key="`spec-${row.key}-${product.id}`">
                        {{ specValue(product, row) }}
                      </td>
                    </tr>
                  </template>
                  <tr v-if="!specRows.length">
                    <th scope="row">{{ $t('ui.comparedPage.specifications') }}</th>
                    <td class="compare-muted" :colspan="compareProducts.length">
                      {{ $t('ui.comparedPage.noSpecs') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
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
  import PriceFormat from '~/components/PriceFormat'
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
      ComparedTile,
      PriceFormat
    },
    mixins: [util, routeParamHelper, global],
    computed: {
      totalPage() {
        return this.allCompared?.last_page
      },
      currentCompared() {
        return this.allCompared?.data
      },
      compareProducts() {
        return (this.currentCompared || []).map(row => row.product).filter(Boolean)
      },
      // every variant option (Storage, Colour...) any of the products has
      optionRows() {
        const rows = []
        this.compareProducts.forEach(product => {
          (product.compare?.options || []).forEach(option => {
            if (!rows.includes(option.attribute)) {
              rows.push(option.attribute)
            }
          })
        })
        return rows
      },
      // specification labels grouped as on the product page; ungrouped rows first
      specRows() {
        const groups = []
        this.compareProducts.forEach(product => {
          (product.specifications || []).forEach(spec => {
            const name = spec.group || ''
            let group = groups.find(g => g.name === name)
            if (!group) {
              group = {name, labels: []}
              groups.push(group)
            }
            if (!group.labels.includes(spec.label)) {
              group.labels.push(spec.label)
            }
          })
        })
        groups.sort((a, b) => (a.name ? 1 : 0) - (b.name ? 1 : 0))

        const rows = []
        groups.forEach(group => {
          group.labels.forEach((label, i) => {
            rows.push({key: rows.length, group: group.name, label, groupStart: i === 0})
          })
        })
        return rows
      },
      ...mapGetters('language', ['langCode']),
    },
    methods: {
      optionValues(product, attribute) {
        const option = (product.compare?.options || []).find(o => o.attribute === attribute)
        return option?.values?.length ? option.values.join(', ') : '—'
      },
      specValue(product, row) {
        const spec = (product.specifications || []).find(s => (s.group || '') === row.group && s.label === row.label)
        return spec && spec.value !== null && spec.value !== '' ? spec.value : '—'
      },
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

<style scoped>
/* Side-by-side comparison (same look as live, 26 Oct build) */
.compare-section {
  background: #fff;
  border: 1px solid #e3e3ef;
  border-radius: 16px;
  margin-top: 32px;
  padding: 20px;
}
.compare-section .compare-heading { color: #130e2b; font-size: 18px; font-weight: 600; margin: 0 0 14px; }
.compare-section .compare-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.compare-section .compare-table { border-collapse: collapse; table-layout: auto; width: 100%; }
.compare-section .compare-table td,
.compare-section .compare-table th {
  border-bottom: 1px solid #f0f0f6;
  color: #130e2b;
  font-size: 14px;
  line-height: 1.4;
  min-width: 160px;
  padding: 11px 12px;
  text-align: left;
  vertical-align: top;
}
.compare-section .compare-table thead th { background: #f5f5ff; font-weight: 600; }
.compare-section .compare-table thead th a { color: #130e2b; }
.compare-section .compare-table tbody th { font-weight: 600; min-width: 140px; width: 180px; }
.compare-section .compare-table .compare-group th {
  background: #fafaff;
  font-size: 13px;
  letter-spacing: .02em;
  text-transform: uppercase;
}
.compare-section .compare-muted { color: #6b6880; font-size: 13px; }
body.rtl .compare-section .compare-table td,
body.rtl .compare-section .compare-table th { text-align: right; }
@media (max-width: 575px) {
  .compare-section { padding: 14px; }
  .compare-section .compare-table td,
  .compare-section .compare-table th { font-size: 13px; min-width: 130px; }
}
</style>
