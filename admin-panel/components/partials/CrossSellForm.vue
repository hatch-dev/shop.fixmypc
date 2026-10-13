<template>
  <form class="card xs-form" @submit.prevent="save">
    <error-formatter />

    <div class="xs-form-grid">
      <div class="input-wrapper">
        <label>{{ $t('crossSell.name') }} *</label>
        <input type="text" v-model="form.name" :placeholder="$t('crossSell.namePlaceholder')" :class="{invalid: nameError}">
        <span v-if="nameError" class="error">{{ $t('crossSell.errName') }}</span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('crossSell.status') }}</label>
        <select v-model.number="form.status">
          <option :value="3">{{ $t('crossSell.draft') }}</option>
          <option :value="1">{{ $t('crossSell.active') }}</option>
          <option :value="2">{{ $t('crossSell.inactive') }}</option>
        </select>
      </div>
    </div>

    <div class="input-wrapper">
      <label>{{ $t('crossSell.descriptionLabel') }}</label>
      <textarea v-model="form.description" :placeholder="$t('crossSell.descriptionPlaceholder')" rows="2" />
    </div>

    <div class="input-wrapper">
      <label>{{ $t('crossSell.maxProducts') }}</label>
      <input type="number" min="1" max="12" v-model.number="form.max_products" class="w-100x">
      <span class="xs-hint">{{ $t('crossSell.maxProductsHint') }}</span>
    </div>

    <div class="xs-head">
      <div>
        <h5 class="xs-title">{{ $t('crossSell.products') }}</h5>
        <p class="xs-hint">{{ $t('crossSell.pickerHint') }}</p>
      </div>
      <button type="button" class="button primary-btn" :disabled="form.products.length >= 50" @click.prevent="pickerOpen = true">
        + {{ $t('crossSell.addProducts') }}
      </button>
    </div>

    <div v-if="!form.products.length" class="xs-empty-state">
      <p>{{ $t('crossSell.noProductsYet') }}</p>
    </div>

    <div v-else class="xs-table">
      <table>
        <thead>
          <tr>
            <th class="w-50x"></th>
            <th class="w-50x">#</th>
            <th>{{ $t('crossSell.product') }}</th>
            <th>{{ $t('crossSell.price') }}</th>
            <th>{{ $t('crossSell.stock') }}</th>
            <th>&nbsp;</th>
          </tr>
        </thead>

        <!-- Order is the order a shopper sees them in, so it is dragged here
             rather than typed as a number nobody can picture. -->
        <draggable tag="tbody" :list="form.products" handle=".drag-handle">
          <tr v-for="(row, index) in form.products" :key="row.product_id">
            <td><span class="drag-handle" :title="$t('crossSell.dragHint')">☰</span></td>
            <td><span class="xs-small">{{ index + 1 }}</span></td>
            <td>
              <div class="xs-picker-name">
                <img class="xs-thumb" :src="getImageURL(row.image)" :alt="row.title">
                <span><strong>{{ row.title }}</strong><small v-if="row.sku">{{ row.sku }}</small></span>
              </div>
            </td>
            <td><price-format :price="Number(row.offered) > 0 ? Number(row.offered) : Number(row.selling || 0)" /></td>
            <td><span class="xs-badge" :class="row.in_stock ? 'live' : 'off'">{{ row.in_stock ? $t('crossSell.inStock') : $t('crossSell.outOfStock') }}</span></td>
            <td>
              <button type="button" class="lite-btn xs danger" @click.prevent="remove(row)">{{ $t('crossSell.remove') }}</button>
            </td>
          </tr>
        </draggable>
      </table>
    </div>

    <div class="xs-form-actions">
      <nuxt-link class="lite-btn" to="/cross-sell">{{ $t('crossSell.cancel') }}</nuxt-link>
      <button type="submit" class="button primary-btn" :disabled="saving">
        {{ saving ? $t('crossSell.saving') : $t('crossSell.save') }}
      </button>
    </div>

    <cross-sell-picker
      v-if="pickerOpen"
      :excluded="form.products.map(row => row.product_id)"
      @picked="addPicked"
      @close="pickerOpen = false"
    />
  </form>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import draggable from 'vuedraggable'
  import util from '~/mixin/util'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import PriceFormat from '~/components/PriceFormat'
  import CrossSellPicker from '~/components/partials/CrossSellPicker'

  /**
   * The Create / Edit Cross-sell form.
   *
   * One form for both, hydrated from the prop when there is one. It submits
   * the COMPLETE picture -- name, settings, and the full ordered product list
   * -- so the server replaces the products wholesale inside one transaction
   * rather than diffing; a promotion carrying its new name against its old
   * products is a rule nobody configured.
   */
  export default {
    name: 'CrossSellForm',
    components: {draggable, ErrorFormatter, PriceFormat, CrossSellPicker},
    mixins: [util],

    props: {
      crossSell: {type: Object, default: null}
    },

    data() {
      return {
        form: this.hydrate(this.crossSell),
        pickerOpen: false,
        saving: false,
        submitted: false
      }
    },

    computed: {
      nameError() {
        return this.submitted && !String(this.form.name || '').trim()
      },
      ...mapGetters('ui', ['errors'])
    },

    methods: {
      hydrate(crossSell) {
        return {
          name: crossSell?.name || '',
          description: crossSell?.description || '',
          status: Number(crossSell?.status) || 3,
          max_products: Number(crossSell?.max_products) || 4,
          products: (crossSell?.products || []).map(row => ({
            product_id: Number(row.product_id),
            title: row.title,
            image: row.image,
            sku: row.sku,
            selling: row.selling,
            offered: row.offered,
            in_stock: row.in_stock
          }))
        }
      },

      /* Rule 12: the same product twice is dropped here, not left to the save. */
      addPicked(rows) {
        const held = new Set(this.form.products.map(row => Number(row.product_id)))

        const fresh = (rows || [])
          .filter(row => !held.has(Number(row.id)))
          .map(row => ({
            product_id: Number(row.id),
            title: row.title,
            image: row.image,
            sku: row.sku,
            selling: row.selling,
            offered: row.offered,
            in_stock: row.in_stock
          }))

        this.form.products = [...this.form.products, ...fresh]
        this.pickerOpen = false
      },

      remove(row) {
        this.form.products = this.form.products.filter(item => Number(item.product_id) !== Number(row.product_id))
      },

      /*
       * setById resolves with UNDEFINED when the server refuses -- the message
       * goes into the ui store. Treating that as success is how a screen
       * shows a saved state that was never saved.
       */
      async save() {
        this.submitted = true

        if (this.nameError) return

        this.saving = true

        try {
          const result = await this.setById({
            id: this.crossSell?.id || null,
            params: {
              name: this.form.name.trim(),
              description: this.form.description || null,
              status: this.form.status,
              max_products: this.form.max_products,
              products: this.form.products.map(row => row.product_id)
            },
            api: 'setCrossSell'
          })

          if (!result) {
            this.$store.dispatch('ui/setToastError', this.refusalMessage())

            return
          }

          this.$emit('saved', result)
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.saving = false
        }
      },

      /* The refusal, from whichever field the server keyed it under. */
      refusalMessage() {
        const errors = this.errors || {}
        const first = Object.keys(errors)
          .map(key => (Array.isArray(errors[key]) ? errors[key][0] : errors[key]))
          .find(Boolean)

        return first || this.$t('error.err')
      },

      ...mapActions('common', ['setById'])
    }
  }
</script>
