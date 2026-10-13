<template>
  <div v-if="campaign">
    <div class="card ups-intro">
      <nuxt-link class="link ups-small" to="/upsell">
        &larr; {{ $t('upsell.backToUpsell') }}
      </nuxt-link>

      <h4>{{ campaign.name }}</h4>
      <p v-if="campaign.description" class="ups-hint">{{ campaign.description }}</p>
    </div>

    <!--
      Why this cannot go live, said BEFORE the admin reaches for the switch.
      Finding out by flipping it and watching nothing happen is how a support
      ticket starts.
    -->
    <div v-if="campaign.blockers && campaign.blockers.length" class="card ups-section">
      <div class="ups-note warn">
        <h6>{{ $t('upsell.blockersTitle') }}</h6>
        <ul>
          <li v-for="(reason, index) in campaign.blockers" :key="index">{{ reason }}</li>
        </ul>
      </div>
    </div>

    <!-- The upgrade path, drawn as a path: these products become those
         products. A pair of counts does not catch "the 512GB is on the wrong
         side". -->
    <div class="card ups-section">
      <h5 class="ups-section-title">{{ $t('upsell.reviewUpgradePath') }}</h5>

      <div class="ups-review-path">
        <div class="ups-review-col">
          <h6>{{ $t('upsell.sourceProducts') }}</h6>
          <span
            v-for="item in campaign.sources"
            :key="`s-${item.id}`"
            class="ups-pill"
          >
            <img :src="getImageURL(item.image)" :alt="item.title">
            <b>{{ item.title }}</b>
            <small v-if="item.variant">{{ item.variant }}</small>
            <small v-if="!item.in_stock">· {{ $t('upsell.outOfStock') }}</small>
          </span>
        </div>

        <span class="ups-review-arrow" aria-hidden="true">&rarr;</span>

        <div class="ups-review-col">
          <h6>{{ $t('upsell.upgradeOptions') }}</h6>
          <span
            v-for="(item, index) in campaign.options"
            :key="`o-${item.id}`"
            class="ups-pill"
          >
            <span class="ups-chip">{{ index + 1 }}</span>
            <img :src="getImageURL(item.image)" :alt="item.title">
            <b>{{ item.title }}</b>
            <small v-if="item.variant">{{ item.variant }}</small>
            <small v-if="!item.in_stock">· {{ $t('upsell.outOfStock') }}</small>
          </span>
        </div>
      </div>

      <dl class="ups-review-summary">
        <div>
          <dt>{{ $t('upsell.displayOn') }}</dt>
          <dd>{{ surfaceLabel }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.customerType') }}</dt>
          <dd>{{ customerLabel }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.dateRange') }}</dt>
          <dd>{{ dateLabel }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.priority') }}</dt>
          <dd>{{ campaign.priority }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.minimumCartLabel') }}</dt>
          <dd>{{ campaign.minimum_cart_value ? campaign.minimum_cart_value : '—' }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.statusLabel') }}</dt>
          <dd>
            <span class="ups-badge" :class="campaign.is_live ? 'live' : ''">
              {{ Number(campaign.status) === 1 ? $t('upsell.active') : $t('upsell.inactive') }}
            </span>
          </dd>
        </div>
      </dl>

      <div class="ups-note">
        <p class="ups-small">{{ $t('upsell.reviewNoMoney') }}</p>
      </div>
    </div>

    <div class="card ups-wizard-foot">
      <nuxt-link class="lite-btn" to="/upsell">
        {{ $t('upsell.backToUpsell') }}
      </nuxt-link>

      <div class="ups-wizard-foot-right">
        <button
          v-if="$can('upsell', 'edit')"
          class="lite-btn"
          :disabled="busy"
          @click.prevent="toggleStatus"
        >
          {{ Number(campaign.status) === 1 ? $t('upsell.deactivate') : $t('upsell.activate') }}
        </button>

        <nuxt-link
          v-if="$can('upsell', 'edit')"
          class="button primary-btn"
          :to="`/upsell/${campaign.id}/edit`"
        >{{ $t('upsell.edit') }}</nuxt-link>
      </div>
    </div>

    <!-- The activation refusal, named. Same list the switch would have shown
         in the bulk bar on the list page. -->
    <div v-if="blockedRows.length" class="ups-modal-back" @click.self="blockedRows = []">
      <div class="ups-modal" role="dialog" aria-modal="true">
        <h5>{{ $t('upsell.cannotActivate') }}</h5>

        <div v-for="row in blockedRows" :key="row.id" class="ups-note warn">
          <ul>
            <li v-for="(reason, i) in row.reasons" :key="i">{{ reason }}</li>
          </ul>
        </div>

        <div class="ups-modal-actions">
          <button class="primary-btn" @click.prevent="blockedRows = []">{{ $t('upsell.close') }}</button>
        </div>
      </div>
    </div>
  </div>

  <shimmer v-else />
</template>

<script>
  import {mapActions} from 'vuex'
  import Shimmer from '~/components/Shimmer'
  import util from '~/mixin/util'

  /**
   * One upsell campaign, read-only.
   *
   * Its job is to answer "will this actually fire?", which the list cannot: it
   * names every product on both sides, flags the ones that are out of stock,
   * and says up front what is stopping the campaign going live.
   */
  export default {
    name: 'upsell-view',

    middleware: ['common-middleware', 'auth'],

    components: {Shimmer},

    mixins: [util],

    data() {
      return {
        campaign: null,
        busy: false,
        blockedRows: []
      }
    },

    computed: {
      surfaceLabel() {
        const parts = []

        if (this.campaign.show_on_product_page) parts.push(this.$t('upsell.productPage'))
        if (this.campaign.show_on_cart_page) parts.push(this.$t('upsell.cartPage'))
        if (this.campaign.show_on_checkout_page) parts.push(this.$t('upsell.checkoutPage'))

        return parts.length ? parts.join(', ') : this.$t('upsell.nowhere')
      },

      customerLabel() {
        return {
          all: this.$t('upsell.customerAll'),
          personal: this.$t('upsell.personal'),
          business: this.$t('upsell.business'),
          trade: this.$t('upsell.trade')
        }[this.campaign.customer_type] || this.campaign.customer_type
      },

      dateLabel() {
        if (!this.campaign.start_date && !this.campaign.end_date) {
          return this.$t('upsell.always')
        }

        const from = this.campaign.start_date ? this.shortDate(this.campaign.start_date) : '…'
        const to = this.campaign.end_date ? this.shortDate(this.campaign.end_date) : '…'

        return `${from} → ${to}`
      }
    },

    methods: {
      shortDate(value) {
        return String(value).replace('T', ' ').slice(0, 16)
      },

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

      async toggleStatus() {
        const status = Number(this.campaign.status) === 1 ? 2 : 1

        this.busy = true

        try {
          const result = await this.setRequest({
            params: {ids: [this.campaign.id], status},
            api: 'toggleUpsellCampaign'
          })

          /*
           * setRequest hands back the whole response rather than the payload
           * when the server refused, so a refusal has no `changed` key.
           * Treating that as success would leave a switch that reads as
           * working and is not.
           */
          if (!result || !Array.isArray(result.changed)) {
            this.$store.dispatch('ui/setToastError', this.$t('upsell.cannotActivate'))

            return
          }

          this.blockedRows = Array.isArray(result.blocked) ? result.blocked : []

          // Re-read rather than patched: is_live and the blocker list are both
          // the server's answers, not something this page can work out.
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
      if (this.$can('upsell', 'view')) {
        this.fetch()
      }
    }
  }
</script>
