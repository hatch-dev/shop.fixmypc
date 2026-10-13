<template>
  <div class="xs-manager">
    <div class="xs-head">
      <div>
        <h5 class="xs-title">{{ $t('crossSell.assignTitle') }}</h5>
        <p class="xs-hint">{{ $t('crossSell.assignBlurb') }}</p>
      </div>

      <!-- Assign only. There is deliberately no "create" here: a promotion is
           written once under Promotions -> Cross-sell and reaches every
           product it is assigned to. -->
      <button
        v-if="canEdit"
        type="button"
        class="button primary-btn xs-add-btn"
        :disabled="!productId"
        @click.prevent="pickerOpen = true"
      >+ {{ $t('crossSell.assignButton') }}</button>
    </div>

    <div v-if="!productId" class="xs-notice">{{ $t('crossSell.saveFirst') }}</div>

    <template v-else>
      <div v-if="loading && !loaded">
        <skeleton-block v-for="n in 2" :key="`sk-${n}`" height="64px" class="mb-10" />
      </div>

      <div v-else-if="!rows.length" class="xs-empty-state">
        <p>{{ $t('crossSell.noneAssigned') }}</p>
        <small>{{ $t('crossSell.noneAssignedHint') }}</small>
      </div>

      <draggable v-else :list="rows" handle=".drag-handle" :disabled="!canEdit" @end="persistOrder">
        <div v-for="row in rows" :key="row.id" class="xs-assignment" :class="{'is-off': Number(row.status) !== 1}">
          <span v-if="canEdit" class="drag-handle" :title="$t('crossSell.dragHint')">☰</span>

          <div class="xs-assignment-main">
            <strong>{{ row.name }}</strong>
            <small>
              {{ countLabel(row.products_count) }}
              · <span class="xs-badge" :class="statusClass(row.cross_sell_status)">{{ statusLabel(row.cross_sell_status) }}</span>
            </small>
          </div>

          <div class="xs-assignment-actions">
            <!-- Three states, not two: on here but not showing needs its
                 reason, and it is the state an admin comes asking about. -->
            <button
              type="button"
              class="xs-badge is-toggle"
              :class="row.live ? 'live' : (Number(row.status) !== 1 ? 'off' : 'blocked')"
              :disabled="!canEdit || busy"
              :title="liveHint(row)"
              @click.prevent="toggle(row)"
            >{{ row.live ? $t('crossSell.live') : $t('crossSell.notLive') }}</button>

            <nuxt-link v-if="$can('crosssell', 'view')" class="lite-btn xs" :to="`/cross-sell/${row.cross_sell_id}`">
              {{ $t('crossSell.manageUnderPromotions') }}
            </nuxt-link>

            <button v-if="canEdit" type="button" class="lite-btn xs danger" :disabled="busy" @click.prevent="remove(row)">
              {{ $t('crossSell.remove') }}
            </button>
          </div>
        </div>
      </draggable>
    </template>

    <cross-sell-assign-picker
      v-if="pickerOpen"
      :product-id="productId"
      :saving="busy"
      @picked="assign"
      @close="pickerOpen = false"
    />
  </div>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import draggable from 'vuedraggable'
  import util from '~/mixin/util'
  import crossSellStatus from '~/mixin/crossSellStatus'
  import SkeletonBlock from '~/components/SkeletonBlock'
  import CrossSellAssignPicker from '~/components/partials/CrossSellAssignPicker'

  /**
   * Products -> Product -> Cross-sell: the promotions assigned to this
   * product.
   *
   * ASSIGNS existing promotions; never creates one. Every write reloads from
   * the server response rather than mutating local state, because the server
   * decides the order and whether an assignment is actually showing, and a
   * screen that guessed would disagree with it the first time two tabs were
   * open. Saves on every action rather than with the product form, because
   * an assignment is a row of its own and the form around it is a long
   * multi-tab affair an admin may abandon.
   */
  export default {
    name: 'CrossSellAssignments',
    components: {draggable, SkeletonBlock, CrossSellAssignPicker},
    mixins: [util, crossSellStatus],

    props: {
      // Null while a brand-new product is unsaved; said plainly rather than
      // offering an assign that cannot work.
      productId: {type: [Number, String], default: null}
    },

    data() {
      return {rows: [], loading: false, loaded: false, busy: false, pickerOpen: false}
    },

    computed: {
      // An edit of the product, gated like one.
      canEdit() {
        return this.$can('product', 'edit')
      },
      ...mapGetters('ui', ['errors'])
    },

    watch: {
      // A product saved for the first time gains an id without a remount.
      productId(value) {
        if (value) this.load()
      }
    },

    methods: {
      async load() {
        if (!this.productId) return

        this.loading = true

        try {
          this.apply(await this.getById({id: this.productId, params: {}, api: 'getCrossSellAssignments'}))
          this.loaded = true
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.loading = false
        }
      },

      apply(result) {
        if (result) this.rows = result.rows || []
      },

      countLabel(count) {
        const n = Number(count) || 0

        return n === 1 ? this.$t('crossSell.productsCountOne') : this.$t('crossSell.productsCount', {count: n})
      },

      liveHint(row) {
        if (row.live) return this.$t('crossSell.liveHint')
        if (Number(row.status) !== 1) return this.$t('crossSell.offHereHint')
        if (Number(row.cross_sell_status) !== 1) return this.$t('crossSell.promotionOffHint')

        return this.$t('crossSell.emptyPromotionHint')
      },

      async assign(crossSellId) {
        const result = await this.write({params: {cross_sell_id: crossSellId}, api: 'assignCrossSell'})

        // Only a success closes the picker; a refusal leaves it open with the
        // reason shown, so the admin can pick another.
        if (result) this.pickerOpen = false
      },

      toggle(row) {
        return this.write({
          params: {id: row.id, status: Number(row.status) === 1 ? 2 : 1},
          api: 'updateCrossSellAssignments'
        })
      },

      persistOrder() {
        if (!this.canEdit) return

        return this.write({params: {order: this.rows.map(row => row.id)}, api: 'updateCrossSellAssignments'})
      },

      async remove(row) {
        if (!window.confirm(this.$t('crossSell.confirmUnassign', {name: row.name}))) return

        this.busy = true

        try {
          this.apply(await this.deleteData({params: `${this.productId}/${row.id}`, api: 'unassignCrossSell'}))
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },

      /*
       * setById resolves with UNDEFINED when the server refuses. Treating
       * that as success is how a screen shows a saved state that was never
       * saved; so the undefined is turned into a toast and the rows are
       * reloaded to whatever is actually stored.
       */
      async write({params, api}) {
        this.busy = true

        try {
          const result = await this.setById({id: this.productId, params, api})

          if (!result) {
            this.$store.dispatch('ui/setToastError', this.refusalMessage())
            await this.load()

            return null
          }

          this.apply(result)

          return result
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
          await this.load()

          return null
        } finally {
          this.busy = false
        }
      },

      refusalMessage() {
        const errors = this.errors || {}
        const first = Object.keys(errors)
          .map(key => (Array.isArray(errors[key]) ? errors[key][0] : errors[key]))
          .find(Boolean)

        return first || this.$t('error.err')
      },

      ...mapActions('common', ['getById', 'setById', 'deleteData'])
    },

    mounted() {
      this.load()
    }
  }
</script>
