<template>
  <data-page
    ref="dataPage"
    set-api="setSliderImage"
    get-api="getSliderImage"
    set-image-api="uploadSliderImage"
    route-name="home-slider"
    :name="$t('profile.hSlid')"
    :validation-keys="['title']"
    :file-keys="['id', 'type', 'source_type']"
    :result="result"
    gate="home_slider"
    @result="onSuccess"
  >
    <template v-slot:form="{hasError}">
      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          name="title"
          @change="slugChange"
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


      <div class="input-wrapper">
        <label>{{ $t('category.slug') }}</label>
        <input
          type="text"
          :placeholder="$t('category.slug')"
          name="slug"
          v-model="result.slug"
          ref="slug"
          :class="{invalid: !!!result.slug && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.slug && hasError"
        >
          {{ $t('category.req', { type: $t('category.slug')}) }}
        </span>
      </div>

      <div class="input-wrapper single-line">
        <label>{{ $t('admin.sType') }}</label>
        <dropdown
          :selectedKey="result.source_type"
          :options="productSourceObj"
          @clicked="dropdownSelected"
        />
      </div>
      <product-source
        v-if="allCategories && parseInt(result.source_type) === productSources.category"
        :title="$t('category.cat')"
        type="category"
        :items="result.source_categories"
        :all-data="allCategories"
        @itemSelected="itemSelected('source_categories', 'category', $event)"
        @removeItem="removeItem('source_categories', $event)"
        @addItem="addItem('source_categories', 'category', allCategories)"
      />
      <product-source
        v-if="allSubCategories && parseInt(result.source_type) === productSources.sub_category"
        :title="$t('category.subCat')"
        type="sub_category"
        :items="sourceSubCategories"
        :all-data="allSubCategories"
        @itemSelected="itemSelected('source_sub_categories', 'sub_category', $event)"
        @removeItem="removeItem('source_sub_categories', $event)"
        @addItem="addItem('source_sub_categories', 'sub_category', allSubCategories)"
      />
      <product-source
        v-if="allBrands && parseInt(result.source_type) === productSources.brand"
        :title="$t('brand.brand')"
        type="brand"
        :items="result.source_brands"
        :all-data="allBrands"
        @itemSelected="itemSelected('source_brands', 'brand', $event)"
        @removeItem="removeItem('source_brands', $event)"
        @addItem="addItem('source_brands', 'brand', allBrands)"
      />
      <div
        v-if="parseInt(result.source_type) === productSources.tag"
        class="input-wrapper mb-20 mb-sm-15"
      >
        <label>{{ $t('admin.tags') }}</label>
        <tag-search
          @add="addTag"
          @delete="deleteTag"
          :tags="result.tags"
        />
      </div>


      <source-type-products
        v-if="parseInt(result.source_type) === productSources.product"
        ref="sourceTypeProducts"
        :source-products="sourceProducts"
        @product-clicked="addProduct"
        @delete-product="deleteProduct"
        @undo-delete="undoDelete"
      />

      <div
        v-if="parseInt(result.source_type) === productSources.url"
        class="input-wrapper mb-20 mb-sm-15"
      >
        <p class="info-msg mb-20 mb-sm-15">{{ $t('admin.srcUrl') }} jet-set-hydratiream/product/88630128</p>


        <label>{{ $t('admin.url') }}</label>
        <input
          :placeholder="$t('admin.url')"
          v-model="result.url"
        >
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
          @clicked="dropdownStatusSelected"
        />
      </div>

    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import Dropdown from "~/components/Dropdown"
  import SliderFormTab from "~/components/partials/SliderFormTab"
  import TagSearch from "~/components/TagSearch"
  import {mapGetters, mapActions} from 'vuex'
  import Spinner from "~/components/Spinner"
  import ProductSource from "~/components/partials/ProductSource"
  import SourceTypeProducts from "~/components/partials/SourceTypeProducts"

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        result: {
          id: '',
          source_brands: [],
          source_categories: [],
          source_sub_categories: [],
          source_products: [],
          image: this.defaultImage,
          source_type: 1,
          tags: '',
          url: '',
          slug: '',
          title: '',
          status: 2,
          type: parseInt(this.$route?.query?.type) || 1
        }
      }
    },
    mixins: [util],
    components: {
      SourceTypeProducts,
      ProductSource,
      Spinner,
      TagSearch,
      SliderFormTab,
      Dropdown,
      DataPage
    },
    computed: {
      sourceProducts() {
        return this.result?.source_products || []
      },
      sourceSubCategories(){
        return this.result?.source_sub_categories
      },
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('common', ['allCategories', 'allSubCategories', 'allBrands'])
    },
    methods: {
      dropdownStatusSelected(data) {
        this.result.status = data.key
      },
      undoDelete(index){
        const v =  {
          ...this.result.source_products[index],
          ...{deleted: false}
        }
        this.result.source_products.splice(index, 1)
        this.result.source_products.splice(index, 0, v)
      },
      deleteProduct(index){
        const v =  {
          ...this.result.source_products[index],
          ...{deleted: true}
        }
        this.result.source_products.splice(index, 1)
        this.result.source_products.splice(index, 0, v)
      },
      addProduct(product){
        if(this.result?.source_products?.findIndex((o) => {
          return o.product.id === product.id
        }) || -1 === -1){

          this.result?.source_products?.push({
            product: {
              id: product.id,
              title: product.title,
              image: product.image,
              offered: product.offered,
              selling: product.selling
            }
          })
        }
        this.$refs.sourceTypeProducts.autoSuggestionClose()
      },


      onSuccess(event){
        this.result = Object.assign({}, event)
      },
      itemSelected(source, type, {index, value}){
        if(this.result[source][index]?.id){
          this.result[source][index] = {...this.result[source][index], ...{updated: true}}
        }
        const v = {...this.result[source][index], ...{[type]: {id: value}}}
        this.result[source].splice(index, 1)
        this.result[source].splice(index, 0, v)
      },
      removeItem(source, {index, deleted}){
        const v =  {
          ...this.result[source][index],
          ...{deleted: deleted}
        }
        this.result[source].splice(index, 1)
        this.result[source].splice(index, 0, v)
      },
      addItem(source, type, allData){
        this.result[source].push({[type]: {id: Object.keys(allData)[0]}})
      },
      addTag(tag){
        if (!this.result.tags){
          this.result.tags = ','
        }
        this.result.tags = `${this.result.tags}${tag},`
      },
      deleteTag(tag){
        this.result.tags = this.result.tags.replace(`${tag},`, '')
      },
      dropdownSelected(data) {
        this.result.source_type = data.key
      },
      ...mapActions('common', ['getDropdownList'])
    },
    async mounted() {
      this.loading = true
      try {
        if (!this.allCategories || !this.allSubCategories || !this.allBrands) {
          await this.getDropdownList()
        }
      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.loading = false
    }
  }
</script>

<style scoped>

</style>
