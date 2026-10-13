<template>
  <div>
    <div class="card ups-intro">
      <nuxt-link class="link ups-small" to="/cross-sell">&larr; {{ $t('crossSell.backToList') }}</nuxt-link>
      <h4 v-if="crossSell">{{ crossSell.name }}</h4>
      <p v-if="crossSell && crossSell.description" class="ups-hint">{{ crossSell.description }}</p>
    </div>

    <shimmer v-if="!crossSell" />

    <div v-else class="card xs-form">
      <ul class="xs-view-meta">
        <li>{{ $t('crossSell.status') }}
          <strong><span class="xs-badge" :class="statusClass(crossSell.status)">{{ statusLabel(crossSell.status) }}</span></strong>
        </li>
        <li>{{ $t('crossSell.products') }} <strong>{{ crossSell.products_count }}</strong></li>
        <li>{{ $t('crossSell.assigned') }} <strong>{{ crossSell.assignments_count }}</strong></li>
        <li>{{ $t('crossSell.maxProducts') }} <strong>{{ crossSell.max_products }}</strong></li>
      </ul>

      <!-- Why it cannot go live, before the admin reaches for the switch. -->
      <div v-if="crossSell.blockers && crossSell.blockers.length" class="ups-note warn">
        <h6>{{ $t('crossSell.cannotActivate') }}</h6>
        <ul><li v-for="(reason, i) in crossSell.blockers" :key="i">{{ reason }}</li></ul>
      </div>

      <div v-if="!crossSell.products.length" class="xs-empty-state">
        <p>{{ $t('crossSell.noProductsYet') }}</p>
      </div>

      <div v-else class="xs-table">
        <table>
          <thead>
            <tr>
              <th class="w-50x">#</th>
              <th>{{ $t('crossSell.product') }}</th>
              <th>{{ $t('crossSell.price') }}</th>
              <th>{{ $t('crossSell.stock') }}</th>
              <th>{{ $t('crossSell.status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in crossSell.products" :key="row.id" :class="{'is-blocked': !row.eligible}">
              <td><span class="xs-small">{{ index + 1 }}</span></td>
              <td>
                <div class="xs-picker-name">
                  <img class="xs-thumb" :src="getImageURL(row.image)" :alt="row.title">
                  <span><strong>{{ row.title }}</strong><small v-if="row.sku">{{ row.sku }}</small></span>
                </div>
              </td>
              <td><price-format :price="Number(row.offered) > 0 ? Number(row.offered) : Number(row.selling || 0)" /></td>
              <td><span class="xs-badge" :class="row.in_stock ? 'live' : 'off'">{{ row.in_stock ? row.stock : $t('crossSell.outOfStock') }}</span></td>
              <td>
                <span class="xs-badge" :class="row.eligible ? 'live' : 'blocked'">
                  {{ row.eligible ? $t('crossSell.published') : (Number(row.product_status) === 1 ? $t('crossSell.outOfStock') : $t('crossSell.unpublished')) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="xs-form-actions">
        <button v-if="$can('crosssell', 'edit')" class="lite-btn" :disabled="busy" @click.prevent="toggle">
          {{ Number(crossSell.status) === 1 ? $t('crossSell.deactivate') : $t('crossSell.activate') }}
        </button>
        <nuxt-link v-if="$can('crosssell', 'edit')" class="button primary-btn" :to="`/cross-sell/${crossSell.id}/edit`">
          {{ $t('crossSell.edit') }}
        </nuxt-link>
      </div>
    </div>
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import Shimmer from '~/components/Shimmer'
  import PriceFormat from '~/components/PriceFormat'
  import util from '~/mixin/util'
  import crossSellStatus from '~/mixin/crossSellStatus'

  /** View Cross-sell: what it offers, where it is, and whether it can go live. */
  export default {
    name: 'cross-sell-view',
    middleware: ['common-middleware', 'auth'],
    components: {Shimmer, PriceFormat},
    mixins: [util, crossSellStatus],
    data() {
      return {crossSell: null, busy: false}
    },
    methods: {
      async fetch() {
        try {
          this.crossSell = await this.getById({id: this.$route.params.id, api: 'getCrossSell'})
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
          this.$router.push('/cross-sell')
        }
      },
      async toggle() {
        this.busy = true

        try {
          const status = Number(this.crossSell.status) === 1 ? 2 : 1
          const result = await this.setRequest({params: {ids: [this.crossSell.id], status}, api: 'toggleCrossSell'})

          if (result && result.blocked && result.blocked.length) {
            this.$store.dispatch('ui/setToastError', result.blocked[0].reasons.join(' '))
          }

          await this.fetch()
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },
      ...mapActions('common', ['getById', 'setRequest'])
    },
    mounted() {
      if (this.$can('crosssell', 'view')) this.fetch()
    }
  }
</script>
