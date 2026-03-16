<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setFlashSale"
    get-api="getFlashSale"
    route-name="flash-sales"
    :name="$t('profile.fSale')"
    :validation-keys="['title', 'start_time', 'end_time']"
    :result="result"
    gate="flash_sale"
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
        class="dply-felx block-xs mlr--7-5 inputs align-start j-left"
        :class="{'red-border': !!!result.start_time && hasError}"
      >
        <div
          class="input-wrapper mlr-7-5"
        >
          <label>{{ $t('prod.sTime') }}</label>
          <datetime
            format="DD-MM-YYYY H:i:s"
            width="200px"
            v-model="result.start_time"
            readonly
          />
          <span
            class="error"
            v-if="!!!result.start_time && hasError"
          >
            {{ $t('category.req', { type: $t('prod.sTime')}) }}
          </span>
        </div>

        <div
          class="input-wrapper mlr-7-5"
          :class="{'red-border': !!!result.end_time && hasError}"
        >
          <label>{{ $t('prod.eTime') }}</label>

          <datetime
            format="DD-MM-YYYY H:i:s"
            width="200px"
            v-model="result.end_time"
            readonly
          />
          <span
            class="error"
            v-if="!!!result.end_time && hasError"
          >
            {{ $t('category.req', { type: $t('prod.eTime')}) }}
          </span>
          <span
            class="error"
            v-else-if="!dateValidation && hasError"
          >
            {{ $t('prod.greater') }}
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
      </div><!--dply-felx inputs-->

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

  export default {
    name: "flash-sale",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

        result: {
          id: '',
          title: '',
          status: 2,
          start_time: '',
          end_time: '',
          time_zone: this.timeZone,
          products: []
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
      Spinner
    },
    watch: {
      searchedString: debounce(function () {
        this.fetchingData()
      }, 700)
    },
    computed: {
      dateValidation() {
        return new Date(this.result.end_time) > new Date(this.result.start_time)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      settingResult(evt){
        evt['start_time'] = moment(moment.utc(evt['start_time'])).local().format('DD-MM-YYYY HH:mm:ss')
        evt['end_time'] = moment(moment.utc(evt['end_time'])).local().format('DD-MM-YYYY HH:mm:ss')

        this.result = {...evt, time_zone: this.timeZone}
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
    }
  }
</script>

<style lang="stylus">

</style>
