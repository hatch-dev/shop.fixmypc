<template>
  <div
  >
    <product-search
      ref="productSearch"
      @product-clicked="$emit('product-clicked', $event)"
    />
    <h4>{{ $t('fSale.sProd') }}</h4>
    <div class="table-wrapper mb-20 mb-sm-15">
      <table class="mn-w-600x">
        <tr class="lite-bold">
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('brand.price') }}({{currencyIcon}})</th>
          <th>{{ $t('prod.offered') }}({{currencyIcon}})</th>
          <th/>
        </tr>

        <tr
          v-for="(item, index) in sourceProducts"
          :key="index"
          class="deletable"
          :class="{deleted: item.deleted}"
        >
          <td>
            <div class="dply-felx j-left">
              <img
                class="mr-20"
                :src="getThumbImageURL(item.product.image)"
                :alt="item.product.title"
              >
              <nuxt-link
                :to="`/products/${item.product.id}`"
              >
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
          <td
            class="undo-container"
          >
            <button
              v-if="item.deleted"
              @click.prevent="$emit('undo-delete', index)"
              class="lite-btn"
            >
              {{ $t('fSale.undo') }}
            </button>
            <button
              v-else
              @click.prevent="$emit('delete-product', index)"
              class="lite-btn"
            >
              {{ $t('category.delete') }}
            </button>
          </td>
        </tr>
      </table>
    </div>

  </div>
</template>

<script>
  import ProductSearch from "~/components/partials/ProductSearch"
  import {mapGetters, mapActions} from 'vuex'
  import util from "~/mixin/util"
  import PriceFormat from "./PriceFormat";

  export default {
    name: 'SourceTypeProducts',
    data() {
      return {}
    },
    props: {
      sourceProducts: {
        type: Array,
        default() {
          return []
        }
      },
    },
    watch: {},
    directives: {},
    components: {PriceFormat, ProductSearch},
    mixins: [util],
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting']),
    },

    methods: {
      autoSuggestionClose() {
        this.$refs.productSearch.autoSuggestionClose()
      }
    },
    destroyed() {

    },
    mounted() {
    }
  }
</script>
