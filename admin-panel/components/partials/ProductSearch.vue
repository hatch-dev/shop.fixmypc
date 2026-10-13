<template>
  <div
    class="dropdown-wrapper input-wrapper product-search"
    v-outside-click="autoSuggestionClose"
  >
    <label>{{ $t('list.sp') }}</label>

    <div class="pos-rel">
      <input
        type="text"
        v-model="searchedString"
        @click.prevent="openSuggestions"
        :placeholder="$t('list.sh')"
      >

      <div
        class="search-dropdown"
        v-if="autoSuggestionOpen"
      >
        <div class="suggestion-body">
          <div
            v-if="fetchingProductList"
            class="spinner-wrapper"
          >
            <spinner
              :radius="60"
              color="primary"
            />
          </div>
          <ul
            v-else-if="productList.length"
            class="sb sb-2"
          >
            <li
              v-for="(item, index) in productList"
              :key="index"
            >
              <a
                href="#"
                class="dply-felx"
                :class="{'ps-blocked': isBlocked(item)}"
                @click.prevent="pick(item)"
              >
                <div class="dply-felx j-left ">
                  <lazy-image
                    class="mr-15 img-40x"
                    :data-src="getThumbImageURL(item.image)"
                    :alt="item.title"
                  />
                  <span class="ps-name">
                    <h5 v-if="item.title">{{item.title}}</h5>
                    <h5 v-else>{{ $t('list.nt') }}</h5>
                    <!--
                      Optional, and only rendered when a parent supplies
                      the notes. The other pages using this picker pass
                      nothing and render exactly as before.
                    -->
                    <em v-if="noteFor(item)" class="ps-note">
                      {{ noteFor(item) }}
                    </em>
                  </span>
                </div>
                <i
                  v-if="!isBlocked(item)"
                  class="mn-w-24x icon plus"
                />
                <span v-else class="ps-taken">{{ blockedLabel(item) }}</span>
              </a>
            </li>
          </ul>
          <p
            v-else
            class="p-15"
          >
            {{ $t('list.noData', { data: $t('product.product') }) }}
          </p>
        </div>
        <div
          class="ptb-10 plr-15 dply-felx suggestion-footer"
          :class="{'ignore-click disabled': fetchingProductList || !productList.length}"
        >
          <p class="mx-w-50">{{ resultText }}</p>
          <div class="text-right dply-felx mlr--2-5">
            <button
              class="btn outline-btn mlr-2-5 dply-felx"
              @click.prevent="goNext(-1)"
            >
              <i
                class="icon arrow-left"
              />
            </button>
            <button
              class="btn outline-btn mlr-2-5 dply-felx"
              @click.prevent="goNext(1)"
            >
              <i
                class="icon arrow-right"
              />
            </button>
          </div>

        </div>
      </div>
    </div>
  </div>

</template>

<script>

  import util from "~/mixin/util";
  import {mapActions} from "vuex";
  import Spinner from "~/components/Spinner";
  import outsideClick from '~/directives/outside-click'
  import {debounce} from 'debounce'
  import LazyImage from "../LazyImage";

  export default {
    name: 'ProductSearch',
    data() {
      return {
        productPage: 1,
        productData: null,
        autoSuggestionOpen: false,
        searchedString: '',
        fetchingProductList: true
      }
    },
    watch: {
      searchedString: debounce(function () {
        this.productPage = 1
        this.fetchingData()
      }, 700)
    },
    directives: {
      outsideClick
    },
    props: {
      /*
       * Per-product notes, keyed by product id:
       *   { note: 'string', blocked: bool, blockedLabel: 'string' }
       *
       * Optional. Empty by default, so bundle-deals, clearances and
       * SourceTypeProducts -- the other three users of this picker --
       * behave exactly as they did.
       */
      notes: {
        type: Object,
        default() {
          return {}
        }
      }
    },
    mixins: [util],
    components: {LazyImage, Spinner},
    computed: {
      productList(){
        return this.productData?.data || []
      },
      resultText() {
        if (this.productData) {
          if(this.productData?.total > 0){
            return this.$t('list.show', { from: this.productData?.from, to: this.productData?.to, total: this.productData?.total })
          }
        }
      },
      totalPage() {
        return this.productData?.last_page
      },
      orderId() {
        return this.$route?.params?.id
      }
    },
    methods: {
      noteOf(item) {
        return this.notes[String(item?.id)] || null
      },
      noteFor(item) {
        return this.noteOf(item)?.note || ''
      },
      isBlocked(item) {
        return !!this.noteOf(item)?.blocked
      },
      blockedLabel(item) {
        return this.noteOf(item)?.blockedLabel || ''
      },
      /* A blocked row is inert; the save would refuse it anyway. */
      pick(item) {
        if (this.isBlocked(item)) return

        this.$emit('product-clicked', item)
      },
      autoSuggestionClose() {
        this.autoSuggestionOpen = false
      },
      async openSuggestions() {
        if(!this.autoSuggestionOpen){
          this.autoSuggestionOpen = true
        }

        if (this.productList.length === 0){
          await this.fetchingData()
        }
      },
      goNext(data){

        const nextPage = this.productPage + data
        if(nextPage <= this.totalPage && nextPage > 0){
          this.productPage = nextPage
          this.fetchingData()
        }
      },
      async fetchingData() {
        this.fetchingProductList = true
        try {
          this.productData = await this.getRequest({
            params: {
              page: this.productPage,
              orderby: 'created_at',
              type: 'DESC',
              q: this.searchedString
            },
            api: 'getProducts'
          })

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.fetchingProductList = false

        // So a parent can look this page of results up in bulk.
        this.$emit('list', this.productList.map(p => p.id))
      },
      ...mapActions('common', ['getRequest'] )
    },
    mounted() {
    }
  }
</script>
<style lang="stylus">
  @import '~/assets/styles/product-search.styl'
</style>
