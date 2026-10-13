<template>
  <div>
    <div class="card ups-intro">
      <nuxt-link class="link ups-small" to="/upsell">
        &larr; {{ $t('upsell.backToUpsell') }}
      </nuxt-link>

      <h4>{{ $t('upsell.createTitle') }}</h4>
      <p class="ups-hint">{{ $t('upsell.description') }}</p>
    </div>

    <upsell-wizard @saved="onSaved" />
  </div>
</template>

<script>
  import UpsellWizard from '~/components/partials/UpsellWizard'

  /**
   * Create Upsell.
   *
   * A full screen rather than a modal: two of the five steps are product
   * tables with seven columns each, and a picker squeezed into a dialog is
   * where "which variant is this?" stops being answerable.
   */
  export default {
    name: 'upsell-create',

    middleware: ['common-middleware', 'auth'],

    components: {UpsellWizard},

    methods: {
      /*
       * Straight to the new campaign rather than back to the list.
       *
       * What the admin wants to know next is whether it will actually go live,
       * and the detail screen answers that -- including the reasons it cannot,
       * when there are any.
       */
      onSaved(campaign) {
        this.$router.push(`/upsell/${campaign.id}`)
      }
    }
  }
</script>
