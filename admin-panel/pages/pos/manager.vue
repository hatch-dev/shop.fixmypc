<template>
  <div v-if="validLicence" class="pos-container">
    <div class="pos-products">

      <div class="pos-heading mb-20">
        <form>
          <div class="icon-input">
            <i
              class="icon search"
            />
            <input
              type="text"
              :placeholder="$t('list.sh')"
              @input="searchData"
              v-model.trim="search"
            >
            <button
              v-if="search"
              @click.prevent="clear"
              class="ml-5 close-btn"
            >
              <i
                class="icon search"
              />
            </button>
          </div>
        </form>

        <search-dropdown
          ref="categorySearch"
          currentId="categorySearch"
          :placeholder="$t('category.catUp')"
          list-api="getAllCategories"
          @clicked="categoryClicked"
        />

        <search-dropdown
          ref="brandSearch"
          currentId="brandSearch"
          :placeholder="$t('prod.brand')"
          list-api="getAllBrands"
          @clicked="brandClicked"
        />

        <button
          type="button"
          name="save"
          class="outline-btn"
          @click.prevent="clearFilter"
        >
          {{ $t('ship.cl') }}
        </button>
      </div>

      <div
        v-if="loading"
        class="spinner-wrapper"
      >
        <spinner
          :radius="60"
          color="primary"
          class="mr-15"
        />
      </div>

      <div v-else class="pos-wrap">
        <product-attribute
          :product="value"
          v-for="(value, index) in products" :key="index"
          class="pos-item"
          @cart-added="$refs.cartEl.fetchingCartData()"
        />
      </div>

      <pagination
        v-if="!loading"
        :page="listParams.page"
        :changing-route="false"
        :total-page="totalPage"
        @fetching-data="pageChanged"
      />
    </div>

    <cart
      ref="cartEl"
    />
  </div>
</template>

<script>
  import routeParamHelper from '~/mixin/routeParamHelper'
  import util from '~/mixin/util'
  import {mapActions, mapGetters} from 'vuex'
  import LazyImage from "~/components/LazyImage";
  import Dropdown from "~/components/Dropdown";
  import SearchDropdown from "~/components/SearchDropdown";
  import {debounce} from "debounce";
  import Pagination from "~/components/partials/Pagination";
  import Spinner from "~/components/Spinner";
  import AjaxButton from "~/components/AjaxButton";
  import ProductAttribute from "~/components/partials/ProductAttribute";
  import Cart from "~/components/partials/Cart";

  export default {
    name: "manager",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        cartError: null,
        brand: null,
        category: null,
        search: '',
        result: null,
        validLicence: false,
        loading: false
      }
    },
    components: {
      Cart,
      ProductAttribute,
      AjaxButton,
      Spinner,
      Pagination,
      SearchDropdown,
      Dropdown,
      LazyImage
    },
    mixins: [util, routeParamHelper],
    computed: {
      totalPage() {
        return this.result?.last_page
      },
      products() {
        return this.result?.data;
      },
      ...mapGetters('admin', ['posPublicKey']),
    },
    methods: {
      pageChanged(evt) {
        this.settingQuery(evt)
      },
      clearFilter() {
        this.category = ""
        this.search = ""
        this.brand = ""
        this.$refs.brandSearch.clearData()
        this.$refs.categorySearch.clearData()
        this.settingQuery()
      },
      settingQuery(query = {page: 1}) {
        this.listParams = {...this.listParams, ...query}
        this.fetchingData(query)
      },
      brandClicked(evt) {
        this.brand = evt?.id
        this.settingQuery()
      },

      categoryClicked(evt) {
        this.category = evt?.id
        this.settingQuery()
      },
      clear() {
        this.search = ""
        this.settingQuery()
      },
      searchData: debounce(function () {
        this.settingQuery()
      }, 700),
      async fetchingData(query = {}) {
        try {
          this.loading = true
          this.result = await this.getRequest({
            params: {
              ...this.listParams,
              ...query,
              ...{
                categories: this.category,
                brands: this.brand,
                q: this.search
              },

              ...{time_zone: this.timeZone}
            },
            api: 'getProducts'
          })
          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      ...mapActions('common', ['getRequest'])
    },
    mounted() {

      const domain = window.location.hostname

      if(domain.includes('admin.ishop.cholobangla.com') || domain.includes('localhost') ||
        domain.includes("127.0.0.1")){

        this.validLicence = true

      } else if(this.posPublicKey){
        let licence = this.phpDecryption(this.posPublicKey)

        const licenceObj = JSON.parse(licence)

        const validLicenceItem = !licenceObj?.p || (licenceObj?.p && licenceObj?.p === 'pos-ishop')

        if(licence.includes(domain) && validLicenceItem){
          this.validLicence = true
        }
      }

      if (this.$can('product', 'view')) {
        this.fetchingData()
      }
    }
  }
</script>

<style scoped>

</style>
