<template>
  <pop-over
    :title="$t('crossSell.pickerTitle')"
    elem-id="cross-sell-picker"
    :layer="true"
    :outside-click-on="false"
    class="xs-picker-pop"
    @close="$emit('close')"
  >
    <template v-slot:content>
      <p class="xs-hint">{{ $t('crossSell.pickerHint') }}</p>

      <!-- Search and the narrowing filters. All of them go to the SERVER: the
           catalogue runs to tens of thousands of rows, and an admin typing a
           SKU expects the whole catalogue searched rather than the page in
           front of them. -->
      <div class="xs-picker-bar">
        <input
          type="text"
          v-model="term"
          :placeholder="$t('crossSell.searchProducts')"
          :aria-label="$t('crossSell.searchProducts')"
          @input="onTermChanged"
        >

        <select v-model="categoryId" :aria-label="$t('crossSell.allCategories')" @change="reload">
          <option value="">{{ $t('crossSell.allCategories') }}</option>
          <option v-for="(item, key) in categoryOptions" :key="key" :value="key">
            {{ item.title }}
          </option>
        </select>

        <select v-model="brandId" :aria-label="$t('crossSell.allBrands')" @change="reload">
          <option value="">{{ $t('crossSell.allBrands') }}</option>
          <option v-for="(item, key) in brandOptions" :key="key" :value="key">
            {{ item.title }}
          </option>
        </select>

        <select v-model="statusFilter" :aria-label="$t('crossSell.status')" @change="reload">
          <option value="">{{ $t('crossSell.publishedOnly') }}</option>
          <option value="2">{{ $t('crossSell.unpublishedOnly') }}</option>
        </select>
      </div>

      <div class="xs-picker-table">
        <table>
          <thead>
            <tr>
              <th class="w-50x"></th>
              <th>{{ $t('crossSell.product') }}</th>
              <th>{{ $t('crossSell.sku') }}</th>
              <th>{{ $t('crossSell.category') }}</th>
              <th>{{ $t('crossSell.price') }}</th>
              <th>{{ $t('crossSell.stock') }}</th>
              <th>{{ $t('crossSell.status') }}</th>
            </tr>
          </thead>

          <tbody>
            <!-- The skeleton stands in for the rows rather than replacing the
                 whole panel, so the search box and filters never jump. -->
            <tr v-if="loading && !rows.length" v-for="n in 5" :key="`sk-${n}`" class="xs-sk-row">
              <td colspan="7"><skeleton-block height="42px" /></td>
            </tr>

            <tr
              v-for="row in rows"
              :key="row.id"
              :class="{'is-picked': isPicked(row), 'is-taken': isTaken(row)}"
            >
              <td>
                <!-- Already configured rows are disabled rather than hidden:
                     an admin who cannot find a product they know they added
                     assumes the search is broken. The server refuses the
                     duplicate too, so this is a courtesy, not the guard. -->
                <input
                  type="checkbox"
                  :checked="isPicked(row)"
                  :disabled="isTaken(row)"
                  :aria-label="row.title"
                  @change="toggle(row)"
                >
              </td>

              <td>
                <div class="xs-picker-name">
                  <img class="xs-thumb" :src="getImageURL(row.image)" :alt="row.title">
                  <span>
                    <strong>{{ row.title }}</strong>
                    <small v-if="row.brand">{{ row.brand }}</small>
                  </span>
                </div>
              </td>

              <td><span class="xs-small">{{ row.sku || '—' }}</span></td>
              <td><span class="xs-small">{{ row.category || '—' }}</span></td>

              <td><price-format :price="displayPrice(row)" /></td>

              <td>
                <span class="xs-badge" :class="row.in_stock ? 'live' : 'off'">
                  {{ row.in_stock ? $t('crossSell.inStock') : $t('crossSell.outOfStock') }}
                </span>
              </td>

              <td>
                <span v-if="isTaken(row)" class="xs-badge taken">
                  {{ $t('crossSell.alreadyAdded') }}
                </span>
                <span v-else class="xs-badge" :class="Number(row.status) === 1 ? 'live' : 'off'">
                  {{ Number(row.status) === 1 ? $t('crossSell.published') : $t('crossSell.unpublished') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="!loading && !rows.length" class="xs-empty">
          {{ $t('crossSell.noProducts') }}
        </div>
      </div>

      <!-- Paged rather than infinite: an admin picking three products should
           never be made to scroll the whole catalogue to reach the button. -->
      <div v-if="hasMore" class="xs-picker-more">
        <button class="lite-btn xs" :disabled="loading" @click.prevent="loadMore">
          {{ loading ? $t('crossSell.loading') : $t('crossSell.loadMore') }}
        </button>
      </div>
    </template>

    <template v-slot:pop-footer>
      <div class="xs-picker-foot">
        <!-- What has been chosen so far, always in view. A picker that scrolls
             its selection out of sight is one where the same product gets
             ticked twice. -->
        <span class="xs-picker-count">
          {{ $t('crossSell.selectedCount', {count: picked.length}) }}
        </span>

        <span class="xs-spacer" />

        <button class="lite-btn" @click.prevent="$emit('close')">
          {{ $t('crossSell.cancel') }}
        </button>

        <button
          class="button primary-btn"
          :disabled="!picked.length || saving"
          @click.prevent="confirm"
        >
          {{ saving ? $t('crossSell.adding') : $t('crossSell.addProducts') }}
        </button>
      </div>
    </template>
  </pop-over>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import util from '~/mixin/util'
  import PopOver from '~/components/PopOver'
  import PriceFormat from '~/components/PriceFormat'
  import SkeletonBlock from '~/components/SkeletonBlock'

  /**
   * "Add Products" -- the catalogue selector for a cross-sell promotion.
   *
   * Multi-select and product-level, with no variant column. A cross-sell is a
   * relationship between PRODUCTS; which colour of the laptop bag a customer
   * wants is the customer's choice, made on the storefront where they can see
   * the colours. That is the one place this differs from UpsellProductPicker,
   * and it is why the two are separate components rather than one with a flag:
   * the upsell picker's whole reason for existing is naming a variant.
   *
   * The component picks; it does not save. It emits the chosen rows and lets
   * the form own them, so there is one place that knows what a promotion
   * holds.
   */
  export default {
    name: 'CrossSellPicker',

    components: {PopOver, PriceFormat, SkeletonBlock},

    mixins: [util],

    props: {
      /*
       * What the promotion already holds. Marked as taken rather than hidden:
       * an admin who cannot find a product they know they added assumes the
       * search is broken. The save deduplicates too, so this is a courtesy.
       */
      excluded: {
        type: Array,
        default: () => []
      },
      /* Set while the parent's save is in flight, so the button can say so. */
      saving: {
        type: Boolean,
        default: false
      }
    },

    data() {
      return {
        term: '',
        categoryId: '',
        brandId: '',
        statusFilter: '',
        rows: [],
        picked: [],
        // The picked rows themselves, keyed by id. The selection survives a
        // new search, and by then the row is no longer in `rows`.
        pickedRows: {},
        page: 1,
        lastPage: 1,
        loading: false,
        // Set by onTermChanged, cleared on destroy: without the clear a search
        // in flight fires against a component that has gone.
        searchTimer: null
      }
    },

    computed: {
      hasMore() {
        return this.page < this.lastPage
      },

      categoryOptions() {
        return this.allCategories || {}
      },

      brandOptions() {
        return this.allBrands || {}
      },

      excludedKeys() {
        return (this.excluded || []).reduce((all, id) => {
          all[Number(id)] = true

          return all
        }, {})
      },

      /* Keyed for O(1) lookup: this is asked once per row per render. */
      pickedKeys() {
        return this.picked.reduce((all, id) => {
          all[Number(id)] = true

          return all
        }, {})
      },

      /*
       * The category and brand lists are already in the store, loaded once by
       * common-middleware for the whole panel. Fetching them again here would
       * be two more requests every time the picker opens.
       */
      ...mapGetters('common', ['allCategories', 'allBrands'])
    },

    methods: {
      /*
       * Debounced, because this goes to the server on every keystroke and the
       * catalogue search is a LIKE over two tables. 350ms is long enough that
       * typing a SKU is one query rather than eight.
       */
      onTermChanged() {
        clearTimeout(this.searchTimer)
        this.searchTimer = setTimeout(() => this.reload(), 350)
      },

      reload() {
        this.page = 1
        this.rows = []

        return this.fetch()
      },

      loadMore() {
        this.page += 1

        return this.fetch()
      },

      async fetch() {
        this.loading = true

        try {
          const result = await this.getRequest({
            params: {
              q: this.term || undefined,
              category_id: this.categoryId || undefined,
              brand_id: this.brandId || undefined,
              filter_status: this.statusFilter || undefined,
              page: this.page
            },
            api: 'searchCrossSellProducts'
          })

          const incoming = result?.data || []

          // Appended on "load more", replaced on a new search -- reload()
          // having already emptied the list.
          this.rows = this.page > 1 ? this.rows.concat(incoming) : incoming
          this.lastPage = result?.last_page || 1

        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.loading = false
        }
      },

      isPicked(row) {
        return !!this.pickedKeys[Number(row.id)]
      },

      displayPrice(row) {
        const offered = Number(row.offered)

        return offered > 0 ? offered : Number(row.selling || 0)
      },

      isTaken(row) {
        return this.excludedKeys[Number(row.id)] || !!row.already_added
      },

      toggle(row) {
        if (this.isTaken(row)) {
          return
        }

        const id = Number(row.id)

        if (this.isPicked(row)) {
          this.picked = this.picked.filter(item => Number(item) !== id)

          return
        }

        this.picked = [...this.picked, id]
        this.pickedRows = {...this.pickedRows, [id]: row}
      },

      /*
       * The selection SURVIVES a page change and a new search, because it is
       * held here rather than derived from the rows on screen. Searching for
       * "mouse", ticking one, then searching for "cable" must not quietly drop
       * the mouse.
       */
      confirm() {
        if (!this.picked.length) {
          return
        }

        const rows = this.picked
          .map(id => this.pickedRows[Number(id)])
          .filter(Boolean)

        this.$emit('picked', rows)
      },

      ...mapActions('common', ['getRequest'])
    },

    mounted() {
      this.fetch()
    },

    beforeDestroy() {
      clearTimeout(this.searchTimer)
    }
  }
</script>
