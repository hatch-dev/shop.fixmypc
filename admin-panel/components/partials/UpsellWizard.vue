<template>
  <div class="ups-form">

    <!--
      PROGRESS.

      Three states, not two: done, where you are, and not yet. A single
      highlight for "current" leaves an admin five steps in unable to tell how
      much of the wizard is behind them.

      A finished step is clickable so a correction on step 2 does not mean
      walking forward through 3 and 4 again.
    -->
    <div class="card ups-steps">
      <button
        v-for="(label, index) in stepLabels"
        :key="index"
        type="button"
        class="ups-step"
        :class="{
          'is-current': step === index,
          'is-done': index < furthest && step !== index,
          'is-clickable': index <= furthest
        }"
        :disabled="index > furthest"
        @click.prevent="goTo(index)"
      >
        <span class="ups-step-no">{{ index < furthest && step !== index ? '✓' : index + 1 }}</span>
        <span class="ups-step-label">{{ label }}</span>
      </button>
    </div>

    <p class="ups-step-count">
      {{ $t('upsell.stepOf', {current: step + 1, total: stepLabels.length}) }}
    </p>


    <!-- ===================== 1 - BASIC INFORMATION ===================== -->
    <div v-show="step === 0" class="card ups-section">
      <h5 class="ups-section-title">{{ $t('upsell.stepBasic') }}</h5>

      <div class="input-wrapper">
        <label class="block">
          {{ $t('upsell.nameLabel') }} <span class="req">*</span>
        </label>
        <input
          type="text"
          maxlength="255"
          v-model="form.name"
          :placeholder="$t('upsell.namePlaceholder')"
        >
        <p class="ups-hint">{{ $t('upsell.nameHint') }}</p>
      </div>

      <div class="input-wrapper">
        <label class="block">{{ $t('upsell.descriptionLabel') }}</label>
        <textarea
          rows="3"
          maxlength="2000"
          v-model="form.description"
          :placeholder="$t('upsell.descriptionPlaceholder')"
        />
        <p class="ups-hint">{{ $t('upsell.descriptionHint') }}</p>
      </div>

      <!--
        Status.

        Inactive is the default and stays the default. A module that publishes
        what it creates is one an admin cannot draft in, so switching it on is
        always a deliberate act.
      -->
      <div class="input-wrapper">
        <label class="block">{{ $t('upsell.statusLabel') }}</label>

        <div class="ups-radios">
          <label :class="{on: Number(form.status) === 2}">
            <input type="radio" :value="2" v-model.number="form.status">
            <span>
              <b>{{ $t('upsell.inactive') }}</b>
              <em>{{ $t('upsell.statusHint') }}</em>
            </span>
          </label>

          <label :class="{on: Number(form.status) === 1}">
            <input type="radio" :value="1" v-model.number="form.status">
            <span>
              <b>{{ $t('upsell.active') }}</b>
              <em>{{ $t('upsell.reviewWillBeActive') }}</em>
            </span>
          </label>
        </div>
      </div>
    </div>


    <!-- ====================== 2 - SOURCE PRODUCTS ====================== -->
    <div v-show="step === 1" class="card ups-section">
      <h5 class="ups-section-title">{{ $t('upsell.sourcesTitle') }}</h5>
      <p class="ups-section-hint">{{ $t('upsell.sourcesHint') }}</p>

      <upsell-product-picker
        v-model="form.sources"
        :excluded="form.options"
        :selected-title="$t('upsell.selectedSources', {count: form.sources.length})"
      />
    </div>


    <!-- ====================== 3 - UPGRADE OPTIONS ====================== -->
    <div v-show="step === 2" class="card ups-section">
      <h5 class="ups-section-title">{{ $t('upsell.optionsTitle') }}</h5>
      <p class="ups-section-hint">{{ $t('upsell.optionsHint') }}</p>

      <upsell-product-picker
        v-model="form.options"
        :excluded="form.sources"
        :selected-title="$t('upsell.selectedOptions', {count: form.options.length})"
      />

      <!--
        The order they are offered in.

        Stored as a priority rather than left to insertion order: re-saving a
        campaign would otherwise reshuffle what the customer is shown first,
        which is the sort of change nobody makes on purpose and nobody can
        explain afterwards.
      -->
      <div v-if="form.options.length > 1" class="ups-selected">
        <h6 class="ups-selected-title">{{ $t('upsell.upgradeOptions') }}</h6>
        <p class="ups-hint">{{ $t('upsell.optionOrderHint') }}</p>

        <div class="ups-order-list">
          <div
            v-for="(item, index) in form.options"
            :key="`ord-${item.product_id}-${item.inventory_id || 'all'}`"
            class="ups-order-row"
          >
            <span class="ups-chip">{{ index + 1 }}</span>
            <img :src="getImageURL(item.image)" :alt="item.title">

            <span class="ups-order-body">
              <strong>{{ item.title }}</strong>
              <small v-if="item.variant">{{ item.variant }}</small>
            </span>

            <span class="ups-order-btns">
              <button
                type="button"
                :disabled="index === 0"
                :aria-label="$t('upsell.moveUp')"
                @click.prevent="move(index, -1)"
              >&uarr;</button>
              <button
                type="button"
                :disabled="index === form.options.length - 1"
                :aria-label="$t('upsell.moveDown')"
                @click.prevent="move(index, 1)"
              >&darr;</button>
            </span>
          </div>
        </div>
      </div>
    </div>


    <!-- ====================== 4 - DISPLAY & RULES ====================== -->
    <div v-show="step === 3" class="card ups-section">
      <h5 class="ups-section-title">{{ $t('upsell.rulesTitle') }}</h5>
      <p class="ups-section-hint">{{ $t('upsell.rulesHint') }}</p>

      <div class="input-wrapper">
        <label class="block">
          {{ $t('upsell.displayLabel') }} <span class="req">*</span>
        </label>

        <div class="ups-checks">
          <label>
            <input type="checkbox" v-model="form.show_on_product_page">
            <span>{{ $t('upsell.productPage') }}</span>
          </label>
          <label>
            <input type="checkbox" v-model="form.show_on_cart_page">
            <span>{{ $t('upsell.cartPage') }}</span>
          </label>
          <label>
            <input type="checkbox" v-model="form.show_on_checkout_page">
            <span>{{ $t('upsell.checkoutPage') }}</span>
          </label>
        </div>

        <p class="ups-hint">{{ $t('upsell.displayHint') }}</p>
      </div>

      <div class="input-wrapper">
        <label class="block">{{ $t('upsell.customerLabel') }}</label>

        <div class="ups-radios">
          <label
            v-for="type in customerTypes"
            :key="type.key"
            :class="{on: form.customer_type === type.key}"
          >
            <input type="radio" :value="type.key" v-model="form.customer_type">
            <span><b>{{ type.title }}</b></span>
          </label>
        </div>

        <p class="ups-hint">{{ $t('upsell.customerHint') }}</p>
      </div>

      <div class="ups-grid">
        <div class="input-wrapper">
          <label class="block">{{ $t('upsell.minimumCartLabel') }}</label>
          <input type="number" min="0" step="0.01" v-model="form.minimum_cart_value">
          <p class="ups-hint">{{ $t('upsell.minimumCartHint') }}</p>
        </div>

        <div class="input-wrapper">
          <label class="block">{{ $t('upsell.priorityLabel') }}</label>
          <input type="number" min="1" max="999" v-model.number="form.priority">
          <p class="ups-hint">{{ $t('upsell.priorityHint') }}</p>
        </div>

        <div class="input-wrapper">
          <label class="block">{{ $t('upsell.startLabel') }}</label>
          <input type="datetime-local" v-model="form.start_date">
        </div>

        <div class="input-wrapper">
          <label class="block">{{ $t('upsell.endLabel') }}</label>
          <input type="datetime-local" v-model="form.end_date">
          <p class="ups-hint">{{ $t('upsell.dateHint') }}</p>
        </div>
      </div>
    </div>


    <!-- ======================= 5 - REVIEW & SAVE ======================= -->
    <div v-show="step === 4" class="card ups-section">
      <h5 class="ups-section-title">{{ $t('upsell.reviewTitle') }}</h5>
      <p class="ups-section-hint">{{ $t('upsell.reviewHint') }}</p>

      <!--
        The upgrade path, drawn as a path.

        This is the one screen where an admin can see what they have actually
        built: these products become those products. A pair of counts does not
        catch "I put the 512GB on the wrong side".
      -->
      <h6 class="ups-selected-title">{{ $t('upsell.reviewUpgradePath') }}</h6>

      <div class="ups-review-path">
        <div class="ups-review-col">
          <h6>{{ $t('upsell.sourceProducts') }}</h6>
          <span v-for="item in form.sources" :key="`rs-${item.product_id}-${item.inventory_id || 'all'}`" class="ups-pill">
            <img :src="getImageURL(item.image)" :alt="item.title">
            <b>{{ item.title }}</b>
            <small v-if="item.variant">{{ item.variant }}</small>
          </span>
        </div>

        <span class="ups-review-arrow" aria-hidden="true">&rarr;</span>

        <div class="ups-review-col">
          <h6>{{ $t('upsell.upgradeOptions') }}</h6>
          <span v-for="(item, index) in form.options" :key="`ro-${item.product_id}-${item.inventory_id || 'all'}`" class="ups-pill">
            <span class="ups-chip">{{ index + 1 }}</span>
            <img :src="getImageURL(item.image)" :alt="item.title">
            <b>{{ item.title }}</b>
            <small v-if="item.variant">{{ item.variant }}</small>
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
          <dd>{{ form.priority }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.minimumCartLabel') }}</dt>
          <dd>{{ form.minimum_cart_value ? form.minimum_cart_value : '—' }}</dd>
        </div>
        <div>
          <dt>{{ $t('upsell.statusLabel') }}</dt>
          <dd>{{ Number(form.status) === 1 ? $t('upsell.active') : $t('upsell.inactive') }}</dd>
        </div>
      </dl>

      <!--
        Said out loud, because it is the question this module gets asked most:
        no price lives on an upsell. Every upgrade is priced by the catalogue
        and the customer's own rates at the moment it is offered.
      -->
      <div class="ups-note">
        <p class="ups-small">{{ $t('upsell.reviewNoMoney') }}</p>
      </div>

      <p class="ups-hint">
        {{ Number(form.status) === 1
          ? $t('upsell.reviewWillBeActive')
          : $t('upsell.reviewWillBeInactive') }}
      </p>
    </div>


    <!-- ============================ FOOTER ============================= -->
    <div class="card ups-wizard-foot">
      <button class="lite-btn" :disabled="step === 0 || saving" @click.prevent="back">
        {{ $t('upsell.back') }}
      </button>

      <div class="ups-wizard-foot-right">
        <p v-if="error" class="ups-error">{{ error }}</p>

        <button
          v-if="step < stepLabels.length - 1"
          class="primary-btn"
          @click.prevent="next"
        >{{ $t('upsell.next') }}</button>

        <button
          v-else
          class="primary-btn"
          :disabled="saving"
          @click.prevent="save"
        >{{ saving ? $t('upsell.saving') : $t('upsell.save') }}</button>
      </div>
    </div>
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import util from '~/mixin/util'
  import UpsellProductPicker from '~/components/partials/UpsellProductPicker'

  /**
   * Create / edit an upsell campaign.
   *
   * Full screen and multi-step rather than a modal, because the two product
   * steps are tables the admin has to read: a picker squeezed into a dialog is
   * where "which variant is this?" stops being answerable.
   *
   * Every step is validated as the admin LEAVES it, not all at once at the
   * end. A wizard that accepts four steps and then refuses the whole thing on
   * save makes the admin hunt for which one was wrong.
   *
   * The server re-checks all of it. Nothing here is a security boundary --
   * these checks exist so a refusal arrives while the field is still on screen.
   */
  export default {
    name: 'UpsellWizard',

    components: {UpsellProductPicker},

    mixins: [util],

    props: {
      /* An existing campaign, in the shape find() returns. Null when creating. */
      campaign: {
        type: Object,
        default: null
      }
    },

    data() {
      return {
        step: 0,
        /*
         * The furthest step reached, so a finished step stays clickable and a
         * correction on step 2 is one click rather than walking forward again.
         */
        furthest: 0,
        saving: false,
        error: '',
        form: this.blankForm()
      }
    },

    computed: {
      stepLabels() {
        return [
          this.$t('upsell.stepBasic'),
          this.$t('upsell.stepSources'),
          this.$t('upsell.stepOptions'),
          this.$t('upsell.stepRules'),
          this.$t('upsell.stepReview')
        ]
      },

      customerTypes() {
        return [
          {key: 'all', title: this.$t('upsell.customerAll')},
          {key: 'personal', title: this.$t('upsell.personal')},
          {key: 'business', title: this.$t('upsell.business')},
          {key: 'trade', title: this.$t('upsell.trade')}
        ]
      },

      surfaceLabel() {
        const parts = []

        if (this.form.show_on_product_page) parts.push(this.$t('upsell.productPage'))
        if (this.form.show_on_cart_page) parts.push(this.$t('upsell.cartPage'))
        if (this.form.show_on_checkout_page) parts.push(this.$t('upsell.checkoutPage'))

        return parts.length ? parts.join(', ') : this.$t('upsell.nowhere')
      },

      customerLabel() {
        return (this.customerTypes.find(type => type.key === this.form.customer_type) || {}).title
      },

      dateLabel() {
        if (!this.form.start_date && !this.form.end_date) {
          return this.$t('upsell.always')
        }

        return `${this.form.start_date || '…'} → ${this.form.end_date || '…'}`
      }
    },

    methods: {
      blankForm() {
        return {
          name: '',
          description: '',
          // Inactive unless the admin says otherwise (brief, step 1).
          status: 2,
          customer_type: 'all',
          sources: [],
          options: [],
          show_on_product_page: true,
          show_on_cart_page: true,
          show_on_checkout_page: false,
          minimum_cart_value: '',
          start_date: '',
          end_date: '',
          priority: 10
        }
      },

      /*
       * Load an existing campaign into the form.
       *
       * The stored "Y-m-d H:i:s" has to become the "Y-m-dTH:i" a
       * datetime-local input understands, or the field renders empty and the
       * admin saves the dates away without noticing.
       */
      hydrate(campaign) {
        if (!campaign) return

        this.form = {
          name: campaign.name || '',
          description: campaign.description || '',
          status: Number(campaign.status) || 2,
          customer_type: campaign.customer_type || 'all',
          sources: (campaign.sources || []).map(this.toSelection),
          options: (campaign.options || []).map(this.toSelection),
          show_on_product_page: !!campaign.show_on_product_page,
          show_on_cart_page: !!campaign.show_on_cart_page,
          show_on_checkout_page: !!campaign.show_on_checkout_page,
          minimum_cart_value: campaign.minimum_cart_value === null
            || campaign.minimum_cart_value === undefined
            ? ''
            : campaign.minimum_cart_value,
          start_date: this.toInputDate(campaign.start_date),
          end_date: this.toInputDate(campaign.end_date),
          priority: Number(campaign.priority) || 10
        }

        /*
         * Editing opens on step one, but every step is already reachable: the
         * campaign is complete, so nothing is being skipped past.
         */
        this.furthest = this.stepLabels.length - 1
      },

      toSelection(row) {
        return {
          product_id: Number(row.product_id),
          inventory_id: row.inventory_id ? Number(row.inventory_id) : null,
          title: row.title,
          image: row.image,
          sku: row.sku,
          variant: row.variant,
          selling: row.selling,
          offered: row.offered,
          in_stock: row.in_stock,
          status: row.status
        }
      },

      toInputDate(value) {
        if (!value) return ''

        return String(value).replace(' ', 'T').substring(0, 16)
      },

      move(index, direction) {
        const target = index + direction

        if (target < 0 || target >= this.form.options.length) return

        const next = [...this.form.options]
        const [row] = next.splice(index, 1)

        next.splice(target, 0, row)

        this.form.options = next
      },

      goTo(index) {
        if (index > this.furthest) return

        this.error = ''
        this.step = index
      },

      back() {
        this.error = ''
        this.step = Math.max(0, this.step - 1)
      },

      next() {
        const problem = this.stepError(this.step)

        if (problem) {
          this.error = problem

          return
        }

        this.error = ''
        this.step += 1
        this.furthest = Math.max(this.furthest, this.step)
      },

      /** What is wrong with the step being left, or '' if nothing is. */
      stepError(step) {
        if (step === 0 && !String(this.form.name || '').trim()) {
          return this.$t('upsell.errNameRequired')
        }

        if (step === 1 && !this.form.sources.length) {
          return this.$t('upsell.errSourcesRequired')
        }

        if (step === 2) {
          if (!this.form.options.length) {
            return this.$t('upsell.errOptionsRequired')
          }

          if (this.overlaps()) {
            return this.$t('upsell.errOverlap')
          }
        }

        if (step === 3) {
          if (!this.form.show_on_product_page
            && !this.form.show_on_cart_page
            && !this.form.show_on_checkout_page) {
            return this.$t('upsell.errDisplayRequired')
          }

          if (this.form.start_date && this.form.end_date
            && this.form.end_date < this.form.start_date) {
            return this.$t('upsell.errDates')
          }
        }

        return ''
      },

      /*
       * The same choice on both sides.
       *
       * Compared on the product AND the variant, because 128GB upgrading to
       * 256GB is the whole point of an upsell and both of those are the same
       * catalogue product.
       */
      overlaps() {
        const sources = new Set(this.form.sources.map(this.rowKey))

        return this.form.options.some(row => sources.has(this.rowKey(row)))
      },

      rowKey(row) {
        return `${row.product_id}:${row.inventory_id || 'all'}`
      },

      async save() {
        /*
         * Every step, not only the last one. A step reached and then edited
         * backwards can be left invalid, and the review screen shows the
         * result rather than re-asking the questions.
         */
        for (let index = 0; index < this.stepLabels.length - 1; index += 1) {
          const problem = this.stepError(index)

          if (problem) {
            this.error = problem
            this.step = index

            return
          }
        }

        this.saving = true
        this.error = ''

        try {
          const saved = await this.setById({
            id: this.campaign ? this.campaign.id : '',
            api: 'setUpsellCampaign',
            params: this.payload()
          })

          /*
           * setById resolves with NOTHING when the server refused: a 201 goes
           * to ui/setErrors instead of throwing. Treating that as success
           * would navigate away from a campaign that was never written.
           */
          if (!saved) {
            this.error = this.firstError() || this.$t('upsell.errSaveFailed')

            return
          }

          this.$emit('saved', saved)

        } catch (e) {
          this.error = e && e.message ? e.message : this.$t('upsell.errSaveFailed')
        } finally {
          this.saving = false
        }
      },

      firstError() {
        const errors = this.$store.getters['ui/errors'] || {}

        return Object.keys(errors)
          .reduce((all, key) => all.concat(errors[key]), [])
          .join(' ')
      },

      payload() {
        return {
          name: String(this.form.name || '').trim(),
          description: this.form.description || null,
          status: Number(this.form.status),
          customer_type: this.form.customer_type,

          source_products: this.form.sources.map(row => ({
            product_id: row.product_id,
            inventory_id: row.inventory_id
          })),

          /*
           * The position in the list IS the priority, so what the admin
           * arranged on screen is the order the storefront will offer.
           */
          upgrade_options: this.form.options.map((row, index) => ({
            product_id: row.product_id,
            inventory_id: row.inventory_id,
            priority: index + 1
          })),

          show_on_product_page: this.form.show_on_product_page,
          show_on_cart_page: this.form.show_on_cart_page,
          show_on_checkout_page: this.form.show_on_checkout_page,

          minimum_cart_value: this.form.minimum_cart_value === ''
            ? null
            : Number(this.form.minimum_cart_value),

          start_date: this.form.start_date || null,
          end_date: this.form.end_date || null,
          priority: Number(this.form.priority) || 10
        }
      },

      ...mapActions('common', ['setById'])
    },

    watch: {
      campaign: {
        immediate: true,
        handler(value) {
          this.hydrate(value)
        }
      }
    }
  }
</script>
