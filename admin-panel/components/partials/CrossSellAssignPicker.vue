<template>
  <pop-over
    :title="$t('crossSell.assignPickerTitle')"
    elem-id="cross-sell-assign-picker"
    :layer="true"
    :outside-click-on="false"
    class="xs-picker-pop"
    @close="$emit('close')"
  >
    <template v-slot:content>
      <p class="xs-hint">{{ $t('crossSell.assignPickerHint') }}</p>

      <div class="xs-picker-bar">
        <input type="text" v-model="term" :placeholder="$t('crossSell.searchCrossSells')" :aria-label="$t('crossSell.searchCrossSells')" @input="onTermChanged">
      </div>

      <div v-if="loading && !rows.length">
        <skeleton-block v-for="n in 3" :key="`sk-${n}`" height="58px" class="mb-10" />
      </div>

      <div v-else-if="!rows.length" class="xs-empty">{{ $t('crossSell.noOptions') }}</div>

      <label
        v-for="row in rows"
        :key="row.id"
        class="xs-option"
        :class="{'is-picked': Number(picked) === Number(row.id), 'is-taken': isTaken(row)}"
      >
        <!-- One at a time. Assigning is a deliberate choice per promotion,
             and a radio makes that plain in a way a tick list does not. -->
        <input type="radio" name="cross-sell-option" :value="row.id" :disabled="isTaken(row)" v-model="picked">
        <span class="xs-assignment-main">
          <strong>{{ row.name }}</strong>
          <small>
            {{ countLabel(row.products_count) }}
            · <span class="xs-badge" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span>
            <span v-if="row.already_assigned" class="xs-badge taken">{{ $t('crossSell.alreadyAssigned') }}</span>
            <span v-else-if="row.offers_this_product" class="xs-badge taken">{{ $t('crossSell.offersThisProduct') }}</span>
          </small>
          <small v-if="row.description">{{ row.description }}</small>
        </span>
      </label>

      <div v-if="hasMore" class="xs-picker-more">
        <button class="lite-btn xs" :disabled="loading" @click.prevent="loadMore">
          {{ loading ? $t('crossSell.loading') : $t('crossSell.loadMore') }}
        </button>
      </div>
    </template>

    <template v-slot:pop-footer>
      <div class="xs-picker-foot">
        <span class="xs-spacer" />
        <button class="lite-btn" @click.prevent="$emit('close')">{{ $t('crossSell.cancel') }}</button>
        <button class="button primary-btn" :disabled="!picked || saving" @click.prevent="$emit('picked', Number(picked))">
          {{ saving ? $t('crossSell.assigning') : $t('crossSell.assign') }}
        </button>
      </div>
    </template>
  </pop-over>
</template>

<script>
  import {mapActions} from 'vuex'
  import PopOver from '~/components/PopOver'
  import SkeletonBlock from '~/components/SkeletonBlock'
  import crossSellStatus from '~/mixin/crossSellStatus'

  /**
   * "Assign Cross-sell" -- the selector of EXISTING promotions.
   *
   * It lists promotions, not products, and it offers no way to make one:
   * that is the whole point of the promotion shape. A promotion already
   * under this product, or one that offers this very product (rule 12), is
   * shown and labelled rather than hidden -- an admin who cannot find a
   * promotion they know exists assumes the search is broken -- but cannot be
   * picked. The server refuses both as well.
   */
  export default {
    name: 'CrossSellAssignPicker',
    components: {PopOver, SkeletonBlock},
    mixins: [crossSellStatus],

    props: {
      productId: {type: [Number, String], required: true},
      saving: {type: Boolean, default: false}
    },

    data() {
      return {term: '', rows: [], picked: null, page: 1, lastPage: 1, loading: false, searchTimer: null}
    },

    computed: {
      hasMore() {
        return this.page < this.lastPage
      }
    },

    methods: {
      isTaken(row) {
        return !!row.already_assigned || !!row.offers_this_product
      },

      countLabel(count) {
        const n = Number(count) || 0

        return n === 1 ? this.$t('crossSell.productsCountOne') : this.$t('crossSell.productsCount', {count: n})
      },

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
            params: {product_id: this.productId, q: this.term || undefined, page: this.page},
            api: 'getCrossSellOptions'
          })

          const incoming = result?.data || []

          this.rows = this.page > 1 ? this.rows.concat(incoming) : incoming
          this.lastPage = result?.last_page || 1
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.loading = false
        }
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
