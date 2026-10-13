<template>
  <div>
    <div class="card ups-intro">
      <nuxt-link class="link ups-small" to="/cross-sell">&larr; {{ $t('crossSell.backToList') }}</nuxt-link>
      <h4>{{ $t('crossSell.editTitle') }}</h4>
      <p class="ups-hint">{{ crossSell ? crossSell.name : $t('crossSell.description') }}</p>
    </div>

    <!-- Rendered once the promotion has arrived: the form hydrates from the
         prop, and mounting it empty first loses whatever the admin types
         before the data lands. -->
    <cross-sell-form v-if="crossSell" :cross-sell="crossSell" @saved="onSaved" />
    <shimmer v-else />
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import Shimmer from '~/components/Shimmer'
  import CrossSellForm from '~/components/partials/CrossSellForm'

  /** Edit Cross-sell. The same form as create, handed an existing promotion. */
  export default {
    name: 'cross-sell-edit',
    middleware: ['common-middleware', 'auth'],
    components: {Shimmer, CrossSellForm},
    data() {
      return {crossSell: null}
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
      onSaved(crossSell) {
        this.$router.push(`/cross-sell/${crossSell.id}`)
      },
      ...mapActions('common', ['getById'])
    },
    mounted() {
      if (this.$can('crosssell', 'edit')) this.fetch()
    }
  }
</script>
