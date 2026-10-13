<template>
  <div class="ups-picker">

    <!-- Search and the two narrowing filters. All three go to the SERVER:
         the catalogue is far too large to filter in the browser, and an admin
         typing a SKU expects the whole catalogue searched, not the page in
         front of them. -->
    <div class="ups-picker-bar">
      <input
        type="text"
        v-model="term"
        :placeholder="$t('upsell.searchProducts')"
        @input="onTermChanged"
      >

      <select v-model="categoryId" @change="reload">
        <option value="">{{ $t('upsell.allCategories') }}</option>
        <option v-for="(item, key) in categoryOptions" :key="key" :value="key">
          {{ item.title }}
        </option>
      </select>

      <select v-model="brandId" @change="reload">
        <option value="">{{ $t('upsell.allBrands') }}</option>
        <option v-for="(item, key) in brandOptions" :key="key" :value="key">
          {{ item.title }}
        </option>
      </select>
    </div>

    <div class="ups-picker-table">
      <table>
        <tr>
          <th class="w-50x"></th>
          <th>{{ $t('upsell.product') }}</th>
          <th>{{ $t('upsell.sku') }}</th>
          <th>{{ $t('upsell.category') }}</th>
          <th>{{ $t('upsell.price') }}</th>
          <th>{{ $t('upsell.stock') }}</th>
          <th>{{ $t('upsell.status') }}</th>
          <th>{{ $t('upsell.variantLabel') }}</th>
        </tr>

        <tr
          v-for="row in rows"
          :key="row.id"
          :class="{'is-picked': isPicked(row)}"
        >
          <td>
            <input
              type="checkbox"
              :checked="isPicked(row)"
              @change="toggle(row)"
            >
          </td>

          <td>
            <div class="ups-picker-name">
              <img class="ups-picker-thumb" :src="getImageURL(row.image)" :alt="row.title">
              <strong>{{ row.title }}</strong>
              <small v-if="row.brand">{{ row.brand }}</small>
            </div>
          </td>

          <td><span class="ups-small">{{ row.sku || '—' }}</span></td>
          <td><span class="ups-small">{{ row.category || '—' }}</span></td>

          <td>
            <price-format :price="displayPrice(row)" />
          </td>

          <td>
            <span class="ups-badge" :class="row.in_stock ? 'live' : 'expired'">
              {{ row.in_stock ? $t('upsell.inStock') : $t('upsell.outOfStock') }}
            </span>
          </td>

          <td>
            <span class="ups-badge" :class="Number(row.status) === 1 ? 'live' : ''">
              {{ Number(row.status) === 1 ? $t('upsell.published') : $t('upsell.unpublished') }}
            </span>
          </td>

          <!--
            Which variant, when the product has more than one.

            "Any variant" is the default and covers every variant of the
            product. Naming one is what makes "iPhone 15 Pro 128GB" expressible
            without a second variant system alongside updated_inventories.
          -->
          <td>
            <select
              v-if="row.variants && row.variants.length > 1"
              class="ups-picker-variant"
              :value="pickedVariant(row)"
              @change="setVariant(row, $event.target.value)"
            >
              <option value="">{{ $t('upsell.wholeProduct') }}</option>
              <option
                v-for="variant in row.variants"
                :key="variant.id"
                :value="variant.id"
              >{{ variant.label || variant.sku }}</option>
            </select>
            <span v-else class="ups-small">—</span>
          </td>
        </tr>
      </table>

      <div v-if="loading" class="ups-picker-empty">{{ $t('upsell.loadingProducts') }}</div>
      <div v-else-if="!rows.length" class="ups-picker-empty">{{ $t('upsell.noProducts') }}</div>
    </div>

    <!-- Paged rather than infinite: the catalogue runs to tens of thousands
         of rows and an admin picking three of them should never be made to
         scroll through it. -->
    <div v-if="hasMore && !loading" class="ups-picker-more">
      <button class="lite-btn xs" @click.prevent="loadMore">{{ $t('util.more') || 'Load more' }}</button>
    </div>

    <!-- What has been chosen so far, always in view. A picker that scrolls the
         selection off the top is one where the same product gets added twice. -->
    <div class="ups-selected">
      <h6 class="ups-selected-title">{{ selectedTitle }}</h6>

      <div v-if="!value.length" class="ups-small">{{ $t('upsell.noneSelected') }}</div>

      <div v-else class="ups-selected-list">
        <span
          v-for="item in value"
          :key="`${item.product_id}:${item.inventory_id || 'all'}`"
          class="ups-pill"
        >
          <img :src="getImageURL(item.image)" :alt="item.title">
          <b>{{ item.title }}</b>
          <small v-if="item.variant">{{ item.variant }}</small>
          <button
            type="button"
            :aria-label="`${$t('upsell.remove')} ${item.title}`"
            @click.prevent="removeAt(item)"
          >&times;</button>
        </span>
      </div>
    </div>
  </div>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import util from '~/mixin/util'
  import PriceFormat from '~/components/PriceFormat'

  /**
   * The product picker both selection steps use.
   *
   * One component for sources and for upgrade options, because the two steps
   * ask the same question of the catalogue and only differ in what they call
   * the answer. Two near-identical pickers is how the columns drift apart and
   * one of them quietly stops showing stock.
   *
   * v-model is a list of rows, each `{product_id, inventory_id, title, image,
   * variant, sku}`. inventory_id null means the whole product -- every variant
   * of it -- which is what the server stores and what the storefront will read.
   */
  export default {
    name: 'UpsellProductPicker',

    components: {PriceFormat},

    mixins: [util],

    props: {
      value: {
        type: Array,
        default: () => []
      },
      selectedTitle: {
        type: String,
        default: ''
      },
      /*
       * Products the OTHER step has taken.
       *
       * Shown greyed rather than hidden: an admin who cannot find a product
       * they know is published assumes the search is broken. Picking one is
       * refused by the server too, so this is a courtesy, not the guard.
       */
      excluded: {
        type: Array,
        default: () => []
      }
    },

    data() {
      return {
        term: '',
        categoryId: '',
        brandId: '',
        rows: [],
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

      /* Keyed for O(1) lookup: this is asked once per row per render. */
      pickedKeys() {
        return (this.value || []).reduce((all, item) => {
          all[Number(item.product_id)] = item
          return all
        }, {})
      },

      excludedKeys() {
        return (this.excluded || []).reduce((all, item) => {
          all[`${item.product_id}:${item.inventory_id || 'all'}`] = true
          return all
        }, {})
      },

      /*
       * The category and brand lists are already in the store, loaded once by
       * common-middleware for the whole panel. Fetching them again here would
       * be two more requests every time a wizard step opens.
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
              page: this.page
            },
            api: 'searchUpsellProducts'
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

      pickedVariant(row) {
        return this.pickedKeys[Number(row.id)]?.inventory_id || ''
      },

      isExcluded(row, inventoryId) {
        return !!this.excludedKeys[`${row.id}:${inventoryId || 'all'}`]
      },

      displayPrice(row) {
        const offered = Number(row.offered)

        return offered > 0 ? offered : Number(row.selling || 0)
      },

      toggle(row) {
        if (this.isPicked(row)) {
          return this.removeAt({product_id: row.id})
        }

        this.add(row, null)
      },

      add(row, inventoryId) {
        if (this.isExcluded(row, inventoryId)) {
          this.$store.dispatch('ui/setToastError', this.$t('upsell.errOverlap'))

          return
        }

        this.$emit('input', [...this.value, this.rowToSelection(row, inventoryId)])
      },

      /*
       * Changing the variant edits the existing pick rather than adding a
       * second one. Ticking a product and then choosing its 256GB means "that
       * one", not "the product AND the 256GB".
       */
      setVariant(row, rawId) {
        const inventoryId = rawId ? Number(rawId) : null

        if (this.isExcluded(row, inventoryId)) {
          this.$store.dispatch('ui/setToastError', this.$t('upsell.errOverlap'))

          return
        }

        const next = this.value.filter(item => Number(item.product_id) !== Number(row.id))

        this.$emit('input', [...next, this.rowToSelection(row, inventoryId)])
      },

      rowToSelection(row, inventoryId) {
        const variant = inventoryId
          ? (row.variants || []).find(item => Number(item.id) === Number(inventoryId))
          : null

        return {
          product_id: Number(row.id),
          inventory_id: inventoryId ? Number(inventoryId) : null,
          title: row.title,
          image: row.image,
          sku: variant?.sku || row.sku,
          variant: variant ? (variant.label || variant.sku) : null,
          selling: row.selling,
          offered: row.offered,
          in_stock: variant ? variant.in_stock : row.in_stock,
          status: row.status
        }
      },

      removeAt(item) {
        this.$emit(
          'input',
          this.value.filter(row => Number(row.product_id) !== Number(item.product_id))
        )
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
