<template>
  <div>
    <div class="card ups-intro">
      <nuxt-link class="link ups-small" to="/upsell">
        &larr; {{ $t('upsell.backToUpsell') }}
      </nuxt-link>

      <h4>{{ $t('upsell.editTitle') }}</h4>
      <p class="ups-hint">{{ campaign ? campaign.name : $t('upsell.description') }}</p>
    </div>

    <!--
      The wizard is only rendered once the campaign has arrived.

      It hydrates its form from the prop, so mounting it against null and
      filling it in afterwards would show an empty step 1 first -- and an admin
      who starts typing into that loses it the moment the data lands.
    -->
    <upsell-wizard v-if="campaign" :campaign="campaign" @saved="onSaved" />

    <shimmer v-else />
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import Shimmer from '~/components/Shimmer'
  import UpsellWizard from '~/components/partials/UpsellWizard'

  /** Edit Upsell. The same wizard as create, handed an existing campaign. */
  export default {
    name: 'upsell-edit',

    middleware: ['common-middleware', 'auth'],

    components: {Shimmer, UpsellWizard},

    data() {
      return {
        campaign: null
      }
    },

    methods: {
      async fetch() {
        try {
          this.campaign = await this.getById({
            id: this.$route.params.id,
            api: 'getUpsellCampaign'
          })
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
          this.$router.push('/upsell')
        }
      },

      onSaved(campaign) {
        this.$router.push(`/upsell/${campaign.id}`)
      },

      ...mapActions('common', ['getById'])
    },

    mounted() {
      if (this.$can('upsell', 'edit')) {
        this.fetch()
      }
    }
  }
</script>
