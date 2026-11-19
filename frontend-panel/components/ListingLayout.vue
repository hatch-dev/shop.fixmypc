<template>
  <product-list
    ref="productList"
    :categories="allCategories"
    :result-title="sourceTitle || resultTitle"
    :product-params="productParams"
    :back-btn="false"
    :fetching-product-data="fetchingProductData"
    @fetch-data="fetchingData"
  />

</template>

<script>
  import listingParams from '~/mixin/listingParams'
  import {mapGetters, mapActions} from 'vuex'

  import ProductList from "./ProductList";

  export default {
    name: 'ListingLayout',
    data() {
      return {
        sourceTitle: "",
        products: null,
        fetchingProductData: false,
      }
    },
    mixins: [listingParams],
    watch: {},
    props: {
      productParams: {
        type: Object,
        default() {
          return {}
        }
      },
      resultTitle: {
        type: String,
        default: ''
      },
    },
    components: {
      ProductList
    },
    computed: {
      ...mapGetters('language', ['langCode']),
      ...mapGetters('listing', ['brands', 'shippingRules', 'collections', 'allCategories']),
    },
    methods: {
      clearQuery() {
        this.$refs.productList.clearQuery()
      },
      async fetchingData() {
        this.fetchingProductData = true

          setTimeout(async () => {
            const params = {
              ...this.productParams,
              ...{
                sortby: this.sortByData,
                shipping: this.shippingFromRoute,
                brand: this.brandFromRoute,
                collection: this.collectionFromRoute,
                rating: this.ratingFromRoute,
                max: this.maxPriceFromRoute,
                min: this.minPriceFromRoute,
                q: this.searchedKeyword,
                category: this.$route.query.category,
                page: this.pageData,
                all_categories: !this.allCategories,
                sidebar_data: !this.brands || !this.shippingRules || !this.collections
              }
            }

            /*
             * One page load asked three times for the same listing (the
             * product list's immediate watcher, this mounted() and the search
             * page's watcher), each a full products query. A request identical
             * to one still in flight is dropped: that one fills the page. Any
             * change of query, filter, sort or page is a different request.
             */
            const key = JSON.stringify(params) + '|' + this.langCode
            if (this.inflightKey === key) return
            this.inflightKey = key

            try {
              const data = await this.getRequest({
                params,
                lang: this.langCode,
                api: 'products'
              })

              this.sourceTitle = data.data?.source?.title



              this.setProducts(data)

              this.products = data.data.result
              this.fetchingProductData = false


            } catch (e) {

              return this.$nuxt.error(e)
            } finally {
              if (this.inflightKey === key) this.inflightKey = null
            }

          }, 100)


      },
      ...mapActions('listing', ['setProducts', 'emptyProducts']),
      ...mapActions('common', ['getRequest']),
    },
    created() {
      // not reactive: only fetchingData() reads it
      this.inflightKey = null
    },
    async mounted() {
      this.emptyProducts()
      await this.fetchingData()
    }
  }
</script>
