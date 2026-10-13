<template>
  <div class="input-wrapper pos-rel">
    <label class="block">{{ $t('crossSell.assignTitle') }}</label>

    <div
      class="custom-dropdown cp"
      @click="toggleOpen"
    >
      <span>
        {{ summary }}
        <i
          class="icon black"
          :class="open ? 'arrow-up' : 'arrow-down'"
        />
      </span>
    </div>

    <!-- A product being created has no id to hang an assignment off yet, so
         the ticks are held here and written once it has one. Said out loud,
         because every other control on this card saves on the tick. -->
    <p v-if="!productId" class="pv2-help xs-select-note">{{ $t('crossSell.pendingNote') }}</p>

    <div
      v-if="open"
      class="multiple-wrap xs-select-wrap"
      v-outside-click="close"
    >
      <p v-if="loading" class="xs-select-msg">{{ $t('crossSell.loading') }}</p>

      <p v-else-if="!options.length" class="xs-select-msg">{{ $t('crossSell.noOptions') }}</p>

      <label
        v-for="option in options"
        v-else
        :key="option.id"
        class="bundle-item"
        :class="{'is-disabled': isBlocked(option)}"
        :title="hintFor(option)"
      >
        <input
          type="checkbox"
          :checked="isAssigned(option.id)"
          :disabled="busy || isBlocked(option)"
          @change="change(option, $event)"
        >
        <span class="bundle-text">
          {{ option.name }}
          <small class="xs-select-meta">{{ metaFor(option) }}</small>
        </span>
      </label>
    </div>
  </div>
</template>
<script>
  import {mapActions, mapGetters} from 'vuex'
  import outsideClick from '~/directives/outside-click.js'

  /**
   * Products -> Product -> Organisation -> Cross-sell.
   *
   * ASSIGNS existing cross-sell promotions to this product; it never creates
   * one. A promotion is written once under Promotions -> Cross-sell and
   * reaches every product it is assigned to, which is the point of the shape.
   *
   * On a product that exists, saves on every tick rather than with the form:
   * an assignment is a row of its own, and the form around it is a long
   * multi-tab affair an admin may abandon. Every write reloads from the
   * server's own response instead of mutating local state, because the server
   * decides what is actually assigned and a screen that guessed would
   * disagree with it the first time two tabs were open.
   *
   * On a product being CREATED there is no id to write against, so the ticks
   * are held in `pending` and flushed by the form once the product exists --
   * see flushPending(), which products/_id.vue awaits after its save. The
   * control used to say "save the product first" and offer nothing, which
   * meant a new product could not be put into a cross-sell without saving it,
   * navigating back into it and finding the tab again.
   */
  export default {
    name: 'CrossSellSelect',
    directives: {outsideClick},

    props: {
      // Null while a brand-new product is unsaved.
      productId: {type: [Number, String], default: null}
    },

    data() {
      return {
        open: false,
        options: [],
        rows: [],
        // Ids ticked before the product existed, awaiting flushPending().
        pending: [],
        loading: false,
        busy: false,
        loaded: false
      }
    },

    computed: {
      // An edit of the product, gated like one.
      canEdit() {
        return this.$can('product', 'edit')
      },

      /*
       * What is ticked, whether or not it is written yet: on a new product
       * every one of them is pending, and a control reading "Select
       * Cross-sells" over three ticked boxes is just wrong.
       */
      selectedNames() {
        const saved = this.rows.map(row => row.name).filter(Boolean)

        const pending = this.pending
          .map(id => (this.options.find(option => Number(option.id) === Number(id)) || {}).name)
          .filter(Boolean)

        return saved.concat(pending)
      },

      summary() {
        const count = this.rows.length + this.pending.length

        if (!count) return this.$t('crossSell.selectPlaceholder')

        /*
         * Names rather than a bare count while they fit: the whole reason
         * this control sits on this card is so the answer can be read
         * without opening anything.
         */
        if (count <= 2) return this.selectedNames.join(', ')

        return this.$t('crossSell.selectedCount', {count})
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
      toggleOpen() {
        if (this.busy) return

        this.open = !this.open

        // The options list is only worth fetching once the control is opened.
        if (this.open && !this.loaded) this.loadOptions()
      },

      close() {
        this.open = false
      },

      isAssigned(crossSellId) {
        const id = Number(crossSellId)

        return this.rows.some(row => Number(row.cross_sell_id) === id) ||
          this.pending.some(pendingId => Number(pendingId) === id)
      },

      /*
       * Rule 12: a promotion that OFFERS this very product cannot also be
       * assigned to it -- "buy this laptop, and may we suggest this laptop".
       * The server refuses it; the box is disabled so nobody finds that out
       * by clicking.
       *
       * Only on the way IN. One already assigned that way is older data or
       * a promotion edited since, and the server is happy to unassign it --
       * disabling it outright is how a bad row becomes permanent.
       */
      isBlocked(option) {
        if (!this.canEdit) return true

        return !!option.offers_this_product && !this.isAssigned(option.id)
      },

      hintFor(option) {
        if (option.offers_this_product) return this.$t('crossSell.offersThisProduct')

        if (Number(option.status) !== 1) return this.$t('crossSell.promotionOffHint')

        return option.name
      },

      /*
       * One short line. Why a promotion is not showing is the long half and
       * lives in the tooltip: spelled out here it wrapped to three lines and
       * pushed the tick box onto a line of its own.
       */
      metaFor(option) {
        const count = Number(option.products_count) || 0
        const products = count === 1
          ? this.$t('crossSell.productsCountOne')
          : this.$t('crossSell.productsCount', {count})

        if (Number(option.status) !== 1) {
          return products + ' - ' + this.$t('crossSell.notLive')
        }

        return products
      },

      change(option, event) {
        const assigned = this.isAssigned(option.id)

        /*
         * No product to write against yet: the tick is the record, so it is
         * kept rather than reverted, and flushPending() writes it later.
         */
        if (!this.productId) {
          this.pending = assigned
            ? this.pending.filter(id => Number(id) !== Number(option.id))
            : this.pending.concat(option.id)

          return
        }

        // Redrawn from the server's answer, not from the click: until the row
        // is actually written the box must not show a state nothing saved.
        event.target.checked = assigned

        return assigned ? this.unassign(option) : this.assign(option)
      },

      async load() {
        if (!this.productId) return

        this.loading = true

        try {
          this.apply(await this.getById({
            id: this.productId, params: {}, api: 'getCrossSellAssignments'
          }))
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.loading = false
        }
      },

      /*
       * Every page of them. There are a handful in practice, but "the one I
       * wanted was not in the list" is not something anybody reports as a
       * pagination bug. Bounded so a broken pager cannot spin forever.
       */
      async loadOptions() {
        this.loading = true

        try {
          const collected = []
          let page = 1
          let lastPage = 1

          do {
            /*
             * product_id only decides which options are marked as already
             * assigned or as offering this product; the endpoint lists them
             * all without it, which is what a product being created needs.
             */
            const result = await this.getRequest({
              params: this.productId ? {product_id: this.productId, page} : {page},
              api: 'getCrossSellOptions'
            })

            collected.push(...(result?.data || []))
            lastPage = Number(result?.last_page) || 1
            page += 1
          } while (page <= lastPage && page <= 20)

          this.options = collected
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

      assign(option) {
        return this.write(() => this.setById({
          id: this.productId, params: {cross_sell_id: option.id}, api: 'assignCrossSell'
        }))
      },

      /*
       * Write the ticks that were made before the product existed.
       *
       * Called by products/_id.vue once its save has returned an id, and
       * AWAITED there: the form navigates away immediately afterwards, so a
       * fire-and-forget write would be cut off partway through.
       *
       * Assign is one row per call and each one can be refused on its own
       * merits -- rule 12 self-reference, a promotion deleted since the list
       * was loaded -- so they are written one at a time and the refusals are
       * counted rather than thrown. A cross-sell that could not be attached
       * must not read as a product that failed to save, because the product
       * did save.
       *
       * Returns the number written and the number refused, for the caller to
       * report; it raises no toast of its own beyond the refusal summary.
       */
      async flushPending() {
        const ids = this.pending.slice()

        if (!this.productId || !ids.length) return {saved: 0, failed: 0}

        this.busy = true

        let saved = 0
        let failed = 0

        try {
          for (const id of ids) {
            try {
              const result = await this.setById({
                id: this.productId, params: {cross_sell_id: id}, api: 'assignCrossSell'
              })

              // setById resolves with UNDEFINED when the server refuses.
              if (result) {
                this.apply(result)
                saved += 1
              } else {
                failed += 1
              }
            } catch (e) {
              failed += 1
            }
          }

          // Written or refused, none of them is still waiting to be tried.
          this.pending = []

          // The server decides what is assigned; read it back rather than
          // trusting the running count above.
          await this.load()

          if (failed) {
            this.$store.dispatch('ui/setToastError', failed === 1
              ? this.$t('crossSell.pendingFailedOne')
              : this.$t('crossSell.pendingFailed', {count: failed}))
          }
        } finally {
          this.busy = false
        }

        return {saved, failed}
      },

      unassign(option) {
        const row = this.rows.find(item => Number(item.cross_sell_id) === Number(option.id))

        if (!row) return

        return this.write(() => this.deleteData({
          params: this.productId + '/' + row.id, api: 'unassignCrossSell'
        }))
      },

      /*
       * setById resolves with UNDEFINED when the server refuses. Treating
       * that as success is how a screen shows a saved state that was never
       * saved; so the undefined becomes a toast and the rows are reloaded to
       * whatever is actually stored.
       */
      async write(request) {
        this.busy = true

        try {
          const result = await request()

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

      ...mapActions('common', ['getById', 'getRequest', 'setById', 'deleteData'])
    },

    mounted() {
      this.load()
    }
  }
</script>

<style>
  .xs-select-note {
    margin: 0;
  }

  .xs-select-wrap {
    flex-direction: column;
    flex-wrap: nowrap;
    gap: 12px;
  }

  .xs-select-wrap > label {
    width: 100%;
  }

  /* A row, never a wrapped paragraph: the tick box stays beside its name. */
  .xs-select-wrap .bundle-item {
    flex-wrap: nowrap;
  }

  .xs-select-wrap .bundle-item input {
    flex: 0 0 auto;
  }

  .xs-select-wrap .bundle-item.is-disabled {
    opacity: .55;
    cursor: not-allowed;
  }

  .xs-select-meta {
    display: block;
    font-size: 11px;
    color: #6b7280;
  }

  .xs-select-msg {
    margin: 0;
    font-size: 13px;
    color: #6b7280;
  }
</style>
