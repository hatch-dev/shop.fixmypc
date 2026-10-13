<template>
  <div>
    <div class="card ups-intro">
      <nuxt-link class="link ups-small" to="/cross-sell">&larr; {{ $t('crossSell.backToList') }}</nuxt-link>
      <h4>{{ $t('crossSell.createTitle') }}</h4>
      <p class="ups-hint">{{ $t('crossSell.description') }}</p>
    </div>

    <cross-sell-form @saved="onSaved" />
  </div>
</template>

<script>
  import CrossSellForm from '~/components/partials/CrossSellForm'

  /** Create Cross-sell. The only place a promotion is created. */
  export default {
    name: 'cross-sell-create',
    middleware: ['common-middleware', 'auth'],
    components: {CrossSellForm},
    methods: {
      // Straight to the new promotion: what the admin wants next is to see
      // it whole, and to assign it, which the view screen points them to.
      onSaved(crossSell) {
        this.$router.push(`/cross-sell/${crossSell.id}`)
      }
    }
  }
</script>
