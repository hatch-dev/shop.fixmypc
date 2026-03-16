<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setUpsell"
    get-api="getUpsell"
    route-name="crosssell"
    :name="$t('index.crosssell')"
    :validation-keys="['title']"
    :result="result"
    @result="settingResult"
  >
    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          v-model="result.title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

        <div
          class="input-wrapper mlr-7-5"
        >
            <label class="block">
              {{ $t('category.status') }}
            </label>

            <dropdown
              :selectedKey="`${result.status}`"
              :options="statusObj"
              @clicked="dropdownSelected"
            />
        </div>
        <!-- MULTIPLE CATEGORIES -->
        <div class="input-wrapper category-field">
          <label>Categories</label>

          <multiselect
            v-model="result.selectedCategories"
            :options="categories"
            :multiple="true"
            :searchable="true"
            track-by="id"
            label="title"
            placeholder="Select categories"
            class="category-multiselect"
          />
        </div>

        <!-- MULTIPLE SUBCATEGORIES -->
        <div
          class="input-wrapper category-field"
          v-if="availableSubcategories.length"
        >
          <label>Subcategories</label>

          <multiselect
            v-model="result.selectedSubcategories"
            :options="availableSubcategories"
            :multiple="true"
            :searchable="true"
            track-by="id"
            label="title"
            placeholder="Select subcategories"
            class="category-multiselect"
          />
        </div>

      <product-search
        ref="productSearch"
        @product-clicked="addFlashProduct"
      />

      <h4>{{ $t('fSale.sProd') }}</h4>
      <div class="table-wrapper mb-20 mb-sm-15">
        <table class="mn-w-600x">
          <tr class="lite-bold">
            <th>{{ $t('index.title') }}</th>
            <th>{{ $t('brand.price') }}({{ currencyIcon }})</th>
            <th>{{ $t('prod.offered') }}({{ currencyIcon }})</th>
            <th>{{ $t('fSale.sPrice') }}({{ currencyIcon }})</th>
            <th/>
          </tr>

          <tr
            v-for="(item, index) in result.products"
            :key="index"
            class="deletable"
            :class="{deleted: item.deleted}"
          >
            <td>
              <div>
                <nuxt-link
                  :to="`/products/${item.product.id}`"
                  class="dply-felx j-left"
                >
                <lazy-image
                  class="mr-20"
                  :data-src="getThumbImageURL(item.product.image)"
                  :alt="item.product.title"
                />

                  <h5 class="mx-w-400x">{{ item.product.title }}</h5>
                </nuxt-link>
              </div>

            </td>
            <td>
              <price-format
                :price="item.product.selling"
              />
            </td>
            <td>
              <price-format
                :price="item.product.offered"
              />
            </td>
            <td class="mx-w-130x">
              <input
                :disabled="item.deleted"
                type="number"
                step="any"
                v-model="item.price"
                placeholder="Offered"
                @change="valueChanged(index)"
              />
            </td>
            <td
              class="undo-container"
            >
              <button
                v-if="item.deleted"
                @click.prevent="undoDelete(index)"
                class="lite-btn"
              >
                {{ $t('fSale.undo') }}
              </button>
              <button
                v-else
                @click.prevent="deleteProduct(index)"
                class="lite-btn delete-btn"
              >
                {{ $t('category.delete') }}
              </button>
            </td>
          </tr>
        </table>
      </div>

    </template>
  </data-page>
</template>

<script>

  import datetime from 'vuejs-datetimepicker'
  import {mapGetters, mapActions} from 'vuex'
  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import Spinner from '~/components/Spinner'
  import util from '~/mixin/util'
  import {debounce} from 'debounce'
  import moment from 'moment-timezone'
  import ProductInventory from "../../components/partials/ProductInventory";
  import ProductSearch from "../../components/partials/ProductSearch";
  import LazyImage from "../../components/LazyImage";
  import PriceFormat from "../../components/partials/PriceFormat";
  import Multiselect from 'vue-multiselect'
  import 'vue-multiselect/dist/vue-multiselect.min.css'

  export default {
    name: "crosssell",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        categories: [],
        result: {
          id: '',
          title: '',
          status: 2,
          time_zone: this.timeZone,
          products: [],
          selectedCategories: [], 
          selectedSubcategories: []
        }
      }
    },
    mixins: [util],
    components: {
      PriceFormat,
      LazyImage,
      ProductSearch,
      ProductInventory,
      DataPage,
      Dropdown,
      datetime,
      Spinner,
      Multiselect
    },
    watch: {
      searchedString: debounce(function () {
        this.fetchingData()
      }, 700),
      'result.selectedCategories'(newVal) {
        const validSubIds = []

        newVal.forEach(cat => {
          if (cat.child) {
            cat.child.forEach(sub => validSubIds.push(sub.id))
          }
        })

        this.result.selectedSubcategories =
          this.result.selectedSubcategories.filter(sub =>
            validSubIds.includes(sub.id)
          )
      }
    },
    computed: {
      dateValidation() {
       // return new Date(this.result.end_time) > new Date(this.result.start_time)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      availableSubcategories() {

        if (!Array.isArray(this.result.selectedCategories)) return [];

        return this.result.selectedCategories.flatMap(selectedCat => {

          const fullCategory = this.categories.find(
            cat => cat.id === selectedCat.id
          );

          return fullCategory && Array.isArray(fullCategory.child)
            ? fullCategory.child
            : [];

        });
      },
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      preparePayload() {
        return {
          ...this.result,
          category_ids: this.result.selectedCategories.map(c => c.id),
          subcategory_ids: this.result.selectedSubcategories.map(s => s.id)
        }
      },
      settingResult(evt){
        this.result = {
          ...evt,
          time_zone: this.timeZone,
          selectedCategories: evt.categories || [],
          selectedSubcategories: evt.subcategories || []
        }
      },
      valueChanged(index){
        this.result.products[index] = {
          ...this.result.products[index],
          ...{updated: true}
        }
      },
      addFlashProduct(product){
        if(this.result.products.findIndex((o) => {
          return o.product.id === product.id
        }) === -1){
          this.result.products.push({
            price: 0,
            product: {
              id: product.id,
              title: product.title,
              image: product.image,
              offered: product.offered,
              selling: product.selling
            }
          })
        }
        this.$refs.productSearch.autoSuggestionClose()
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      deleteProduct(index){
        this.result.products[index] = {
          ...this.result.products[index],
          ...{deleted: true}
        }
        this.result = {...this.result, ...{products: this.result.products}}
      },
      undoDelete(index){
        this.result.products[index] = {
          ...this.result.products[index],
          ...{deleted: false}
        }
        this.result = {...this.result, ...{products: this.result.products}}
      },
      ...mapActions('common', ['getById'] )
    },
    async mounted() {
      const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
      const response = await this.$axios.get(
        `${baseUrl}api/admin/product/with-categories`
      )

      this.categories = response.data.categories
    }
  }
</script>

<style lang="stylus">

</style>
