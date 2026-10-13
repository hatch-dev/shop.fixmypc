<template>
  <div class="pu-panel">
    <h5 class="pu-title">{{ $t('upgrade.title') }}</h5>
    <p class="pu-hint">{{ $t('upgrade.intro') }}</p>

    <div v-if="loading" class="pu-empty">{{ $t('upgrade.loading') }}</div>

    <template v-else>
      <!-- A product with one variant has nothing to upgrade between, and
           saying so beats an empty panel with an Add button that can only
           produce a group which never matches anything. -->
      <div v-if="!attributes.length" class="pu-note">
        <p>{{ $t('upgrade.noVariants') }}</p>
      </div>

      <template v-else>
        <!--
          What the engine already does on its own.

          Shown whenever nothing is saved, because upgrades are ON by default:
          an empty panel here would read as "upgrades are off" while the
          storefront is already offering them.
        -->
        <div v-if="usingDefaults" class="pu-note">
          <h6>{{ $t('upgrade.defaultsTitle') }}</h6>
          <p v-if="discovered.length">
            {{ $t('upgrade.defaultsOn', {groups: discoveredNames}) }}
          </p>
          <p v-else>{{ $t('upgrade.defaultsNone') }}</p>
          <p class="pu-small">{{ $t('upgrade.defaultsHint') }}</p>
        </div>

        <div v-for="(group, index) in groups" :key="index" class="pu-group">
          <div class="pu-group-head">
            <strong>{{ group.name || $t('upgrade.untitled') }}</strong>

            <span class="pu-spacer" />

            <label class="pu-switch">
              <input
                type="checkbox"
                :checked="Number(group.status) === 1"
                @change="toggleStatus(group)"
              >
              <span>{{ Number(group.status) === 1 ? $t('upgrade.active') : $t('upgrade.inactive') }}</span>
            </label>

            <button class="lite-btn xs delete-btn" @click.prevent="removeGroup(index)">
              {{ $t('upgrade.remove') }}
            </button>
          </div>

          <div class="pu-grid">
            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.groupName') }} <span class="req">*</span></label>
              <input type="text" maxlength="255" v-model="group.name">
              <p class="pu-small">{{ $t('upgrade.groupNameHint') }}</p>
            </div>

            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.attribute') }} <span class="req">*</span></label>
              <select v-model.number="group.attribute_id" @change="onAttributeChanged(group)">
                <option
                  v-for="attribute in attributes"
                  :key="attribute.id"
                  :value="attribute.id"
                  :disabled="isAttributeTaken(attribute.id, index)"
                >{{ attribute.title }}</option>
              </select>
            </div>

            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.mode') }}</label>
              <select v-model="group.mode">
                <option value="automatic">{{ $t('upgrade.modeAutomatic') }}</option>
                <option value="manual">{{ $t('upgrade.modeManual') }}</option>
              </select>
              <p class="pu-small">
                {{ group.mode === 'manual' ? $t('upgrade.modeManualHint') : $t('upgrade.modeAutomaticHint') }}
              </p>
            </div>

            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.customerType') }}</label>
              <select v-model="group.customer_type">
                <option value="all">{{ $t('upgrade.customerAll') }}</option>
                <option value="personal">{{ $t('upgrade.personal') }}</option>
                <option value="business">{{ $t('upgrade.business') }}</option>
                <option value="trade">{{ $t('upgrade.trade') }}</option>
              </select>
            </div>

            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.outOfStock') }}</label>
              <select v-model="group.out_of_stock">
                <option value="hide">{{ $t('upgrade.outHide') }}</option>
                <option value="disable">{{ $t('upgrade.outDisable') }}</option>
              </select>
            </div>

            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.maxOptions') }}</label>
              <input type="number" min="1" max="50" v-model.number="group.max_options">
              <p class="pu-small">{{ $t('upgrade.maxOptionsHint') }}</p>
            </div>

            <div class="input-wrapper">
              <label class="block">{{ $t('upgrade.displayOrder') }}</label>
              <input type="number" min="1" max="999" v-model.number="group.display_order">
            </div>
          </div>

          <!-- The one thing an admin cannot work out alone: an attribute whose
               values have no order can never produce an upgrade until somebody
               gives them one. Full width, under the fields, because it is
               about the group rather than about the dropdown. -->
          <p v-if="!isRankable(group)" class="pu-warn pu-warn-block">
            {{ $t('upgrade.notRankable') }}
          </p>

          <!--
            The manual override. Attribute VALUES rather than variants, because
            a value survives the compatibility filter: "offer 512GB" holds for
            the black one and the blue one alike.
          -->
          <div v-if="group.mode === 'manual'" class="pu-manual">
            <h6>{{ $t('upgrade.manualTitle') }}</h6>
            <p class="pu-small">{{ $t('upgrade.manualHint') }}</p>

            <div class="pu-checks">
              <label v-for="value in valuesFor(group)" :key="value.id" class="pu-check">
                <input type="checkbox" :value="value.id" v-model="group.option_value_ids">
                <span>{{ value.title }}</span>
              </label>
            </div>

            <p v-if="!group.option_value_ids.length" class="pu-warn">
              {{ $t('upgrade.manualEmpty') }}
            </p>
          </div>
        </div>

        <!--
          What saving this form would STOP.

          Saving replaces the whole configuration, so a group the engine runs
          automatically and this form does not list will simply stop. Said
          here, beside the button that would do it.
        -->
        <div v-if="droppedGroups.length" class="pu-note pu-note-warn">
          <h6>{{ $t('upgrade.willStopTitle') }}</h6>
          <p>{{ $t('upgrade.willStop', {groups: droppedGroups.join(', ')}) }}</p>
        </div>

        <div v-else-if="inertGroups.length" class="pu-note pu-note-warn">
          <h6>{{ $t('upgrade.inertTitle') }}</h6>
          <p>{{ $t('upgrade.inert', {groups: inertGroups.join(', ')}) }}</p>
        </div>

        <div class="pu-actions">
          <button class="lite-btn" @click.prevent="addGroup">{{ $t('upgrade.addGroup') }}</button>

          <span class="pu-spacer" />

          <button class="primary-btn" :disabled="saving" @click.prevent="save">
            {{ saving ? $t('upgrade.saving') : $t('upgrade.save') }}
          </button>
        </div>

        <p v-if="error" class="pu-warn">{{ error }}</p>
      </template>

      <!--
        RANKING.

        The engine parses a rank out of a title where it can ("128GB", "2
        Years"), which covers most attributes with no input at all. This is for
        the ones it cannot: "Excellent" over "Very Good" is a judgement nobody
        can derive.

        Ranks live on the attribute VALUE, so they are global. The panel says
        so rather than letting an admin discover it by changing another
        product.
      -->
      <div v-if="attributes.length" class="pu-ranks">
        <h6>{{ $t('upgrade.ranksTitle') }}</h6>
        <p class="pu-small">{{ $t('upgrade.ranksHint') }}</p>

        <div v-for="attribute in attributes" :key="`r-${attribute.id}`" class="pu-rank-group">
          <div class="pu-rank-head">
            <strong>{{ attribute.title }}</strong>
            <span class="pu-badge" :class="attribute.rankable ? 'ok' : 'warn'">
              {{ attribute.rankable ? $t('upgrade.rankable') : $t('upgrade.notRankableShort') }}
            </span>
          </div>

          <div class="pu-rank-rows">
            <div v-for="value in attribute.values" :key="value.id" class="pu-rank-row">
              <span class="pu-rank-label">{{ value.title }}</span>
              <input
                type="number"
                min="0"
                max="9999"
                :placeholder="value.scale === 'parsed' ? $t('upgrade.auto') : '—'"
                v-model="rankEdits[value.id]"
              >
            </div>
          </div>
        </div>

        <div class="pu-actions">
          <span class="pu-spacer" />
          <button class="lite-btn" :disabled="savingRanks" @click.prevent="saveRanks">
            {{ savingRanks ? $t('upgrade.saving') : $t('upgrade.saveRanks') }}
          </button>
        </div>
      </div>

      <!--
        What a customer would actually be offered.

        Against the SAVED configuration, so it is the engine answering rather
        than a second implementation of it in the browser. The cases worth
        seeing are the ones that produce nothing.
      -->
      <div v-if="preview.length" class="pu-preview">
        <h6>{{ $t('upgrade.previewTitle') }}</h6>
        <p class="pu-small">{{ $t('upgrade.previewHint') }}</p>

        <div v-for="group in preview" :key="`p-${group.attribute_id}`" class="pu-preview-group">
          <strong>{{ group.name }}</strong>
          <div class="pu-preview-row">
            <span class="pu-badge ok">{{ group.current.label }} · {{ $t('upgrade.included') }}</span>
            <span v-for="option in group.options" :key="option.inventory_id" class="pu-badge">
              {{ option.label }} · {{ differenceLabel(option) }}
            </span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
  import {mapActions} from 'vuex'

  /**
   * Product -> Upgrades.
   *
   * Configures how a product upgrades between its OWN variants. It never
   * configures pairings and never configures prices: which variants qualify is
   * derived from the variant matrix, and what an upgrade costs is the pricing
   * engine's answer for whoever is asking.
   *
   * The panel's real job is to make the automatic behaviour visible. Upgrades
   * are on by default, so a screen that looked empty would read as "off" while
   * the storefront was already offering them -- which is why the defaults
   * notice and the live preview are here rather than only the form.
   */
  export default {
    name: 'ProductUpgrades',

    props: {
      productId: {
        type: [Number, String],
        default: null
      }
    },

    data() {
      return {
        loading: true,
        saving: false,
        savingRanks: false,
        error: '',
        groups: [],
        attributes: [],
        discovered: [],
        preview: [],
        usingDefaults: true,
        /* Keyed by attribute value id, so the inputs bind straight to it. */
        rankEdits: {}
      }
    },

    computed: {
      discoveredNames() {
        return this.discovered.map(row => row.name).join(', ')
      },

      /*
       * Groups the engine runs automatically that this form no longer covers.
       *
       * Saving replaces the whole configuration, so anything discovered and
       * not present here STOPS -- and stops silently, which is the one thing
       * an admin cannot be expected to work out from a form. Named before the
       * Save button rather than discovered afterwards on the storefront.
       */
      droppedGroups() {
        const configured = this.groups.map(group => Number(group.attribute_id))

        return this.discovered
          .filter(row => !configured.includes(Number(row.attribute_id)))
          .map(row => row.name)
      },

      /* Groups that are configured but cannot produce anything. */
      inertGroups() {
        return this.groups
          .filter(group => Number(group.status) === 1 && !this.isRankable(group))
          .map(group => group.name || this.$t('upgrade.untitled'))
      }
    },

    methods: {
      async load() {
        if (!this.productId) {
          this.loading = false

          return
        }

        this.loading = true

        try {
          const data = await this.getById({
            id: this.productId,
            api: 'getProductUpgrades'
          })

          this.apply(data)

          await this.loadPreview()

        } catch (e) {
          this.error = e?.message || this.$t('error.err')
        } finally {
          this.loading = false
        }
      },

      apply(data) {
        this.attributes = data?.attributes || []
        this.discovered = data?.discovered || []
        this.usingDefaults = !!data?.is_using_defaults

        /*
         * With nothing saved, the form starts as what is ALREADY RUNNING
         * rather than empty.
         *
         * An empty form invites an admin to add one group and press Save --
         * which replaces the automatic behaviour with that single group. That
         * is how a Condition group with no ranks silently switched off working
         * Storage upgrades: the screen never showed that Storage was in the
         * list it was about to replace.
         *
         * Seeded this way, an untouched Save persists exactly what was live,
         * and removing something is a deliberate act with a row to delete.
         */
        const saved = (data?.groups || []).map(group => ({
          ...group,
          option_value_ids: [...(group.option_value_ids || [])]
        }))

        this.groups = saved.length
          ? saved
          : this.discovered.map(group => ({
              ...group,
              option_value_ids: [...(group.option_value_ids || [])]
            }))

        const ranks = {}

        this.attributes.forEach(attribute => {
          attribute.values.forEach(value => {
            ranks[value.id] = value.rank === null ? '' : value.rank
          })
        })

        this.rankEdits = ranks
      },

      async loadPreview() {
        try {
          const data = await this.setById({
            id: this.productId,
            api: 'previewProductUpgrades',
            params: {}
          })

          this.preview = data?.groups || []
        } catch (e) {
          this.preview = []
        }
      },

      attributeFor(group) {
        return this.attributes.find(row => Number(row.id) === Number(group.attribute_id))
      },

      isRankable(group) {
        const attribute = this.attributeFor(group)

        return !!attribute && attribute.rankable
      },

      valuesFor(group) {
        const attribute = this.attributeFor(group)

        return attribute ? attribute.values : []
      },

      /*
       * One group per attribute: two on Storage would offer the same variant
       * twice, under two headings, with two sets of rules.
       */
      isAttributeTaken(attributeId, exceptIndex) {
        return this.groups.some((group, index) =>
          index !== exceptIndex && Number(group.attribute_id) === Number(attributeId))
      },

      toggleStatus(group) {
        group.status = Number(group.status) === 1 ? 2 : 1
      },

      addGroup() {
        const free = this.attributes.find(attribute => !this.isAttributeTaken(attribute.id, -1))

        if (!free) {
          this.error = this.$t('upgrade.allAttributesUsed')

          return
        }

        this.error = ''

        this.groups.push({
          id: null,
          name: `${free.title} Upgrade`,
          attribute_id: free.id,
          mode: 'automatic',
          status: 1,
          display_order: this.groups.length + 1,
          out_of_stock: 'hide',
          customer_type: 'all',
          max_options: null,
          option_value_ids: []
        })
      },

      /* The chosen values belong to the old attribute, so they go with it. */
      onAttributeChanged(group) {
        group.option_value_ids = []

        const attribute = this.attributeFor(group)

        if (attribute && (!group.name || /Upgrade$/.test(group.name))) {
          group.name = `${attribute.title} Upgrade`
        }
      },

      removeGroup(index) {
        this.groups.splice(index, 1)
      },

      differenceLabel(option) {
        const difference = Number(option.difference || 0)

        if (difference === 0) return '+0.00'

        return (difference > 0 ? '+' : '−') + Math.abs(difference).toFixed(2)
      },

      async save() {
        this.error = ''
        this.saving = true

        try {
          const saved = await this.setById({
            id: this.productId,
            api: 'setProductUpgrades',
            params: {
              groups: this.groups.map((group, index) => ({
                name: String(group.name || '').trim(),
                attribute_id: Number(group.attribute_id),
                mode: group.mode,
                status: Number(group.status),
                display_order: Number(group.display_order) || index + 1,
                out_of_stock: group.out_of_stock,
                customer_type: group.customer_type,
                max_options: group.max_options ? Number(group.max_options) : null,
                option_value_ids: group.mode === 'manual'
                  ? group.option_value_ids.map(Number)
                  : []
              }))
            }
          })

          /*
           * setById resolves with NOTHING when the server refused: a 201 goes
           * to ui/setErrors instead of throwing. Treating that as success
           * would leave the panel showing configuration that was never saved.
           */
          if (!saved) {
            this.error = this.firstError() || this.$t('upgrade.saveFailed')

            return
          }

          this.apply(saved)

          await this.loadPreview()

        } catch (e) {
          this.error = e?.message || this.$t('upgrade.saveFailed')
        } finally {
          this.saving = false
        }
      },

      async saveRanks() {
        this.savingRanks = true
        this.error = ''

        try {
          const ranks = Object.keys(this.rankEdits).map(id => ({
            id: Number(id),
            /*
             * Cleared rather than zeroed: an empty box means "work it out
             * from the title", and 0 is a legitimate lowest rank.
             */
            rank: this.rankEdits[id] === '' || this.rankEdits[id] === null
              ? null
              : Number(this.rankEdits[id])
          }))

          const saved = await this.setRequest({
            params: {ranks},
            api: 'setAttributeValueRanks'
          })

          if (!saved || saved.updated === undefined) {
            this.error = this.firstError() || this.$t('upgrade.saveFailed')

            return
          }

          /*
           * Re-read rather than patched: a rank can make a whole attribute
           * upgradeable, which changes the warnings above and the preview
           * below as well as the number in the box.
           */
          await this.load()

        } catch (e) {
          this.error = e?.message || this.$t('upgrade.saveFailed')
        } finally {
          this.savingRanks = false
        }
      },

      firstError() {
        const errors = this.$store.getters['ui/errors'] || {}

        return Object.keys(errors)
          .reduce((all, key) => all.concat(errors[key]), [])
          .join(' ')
      },

      ...mapActions('common', ['getById', 'setById', 'setRequest'])
    },

    watch: {
      productId: {
        immediate: true,
        handler() {
          this.load()
        }
      }
    }
  }
</script>

<style scoped>
/* Built from the product form's own card, spacing and controls. */
.pu-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.pu-title {
  margin: 0;
}

.pu-hint,
.pu-small {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--text-muted, #6B7280);
}

.pu-small {
  margin-top: 4px;
  font-size: 12px;
}

.pu-empty {
  padding: 22px;
  font-size: 13px;
  text-align: center;
  color: var(--text-muted, #6B7280);
}

.pu-note {
  padding: 12px 14px;
  border-radius: 9px;
  background: rgba(51, 49, 153, .06);
}

.pu-note h6,
.pu-manual h6,
.pu-ranks h6,
.pu-preview h6 {
  margin: 0 0 4px;
}

.pu-note p {
  margin: 0;
  font-size: 13px;
  line-height: 1.55;
}

.pu-warn {
  margin: 6px 0 0;
  font-size: 12.5px;
  color: #B54708;
}

.pu-group {
  padding: 16px;
  border: 1px solid var(--border-color, #E9E9F4);
  border-radius: 10px;
}

.pu-group-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding-bottom: 12px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--border-color, #E9E9F4);
}

.pu-spacer {
  flex: 1;
}

.pu-switch {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  white-space: nowrap;
  cursor: pointer;
}

.pu-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 14px 16px;
}

/*
 * The name is the field an admin actually types in, and it is the one that
 * suffers most from a 210px column. It takes two wherever there is room.
 */
.pu-grid > .input-wrapper:first-child {
  grid-column: span 2;
}

/*
 * The admin stylesheet styles `input` globally and `select` not at all, so
 * these rendered as 19px-tall browser widgets with a grey border beside 40px
 * inputs. Matched to the input so the row reads as one form.
 */
.pu-panel select {
  width: 100%;
  height: 40px;
  padding: 0 34px 0 12px;
  font-size: 13px;
  line-height: 38px;
  color: inherit;
  border: 1px solid var(--border-color, #E9E9F4);
  border-radius: var(--radius-md, 8px);
  background-color: var(--card-bg, #fff);
  /* The chevron a native select loses the moment appearance is cleared. */
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1.5 6 6.5l5-5' stroke='%2357607A' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  appearance: none;
  -webkit-appearance: none;
  cursor: pointer;
}

.pu-panel select:focus {
  outline: 0;
  border-color: #333199;
}

.pu-note-warn {
  background: #FFF4E5;
}

.pu-note-warn h6 {
  color: #A66300;
}

.pu-warn-block {
  margin-top: 12px;
  padding: 9px 12px;
  border-radius: 8px;
  background: #FFF4E5;
}

.pu-manual {
  padding-top: 14px;
  margin-top: 14px;
  border-top: 1px solid var(--border-color, #E9E9F4);
}

.pu-checks {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 10px;
}

.pu-check {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  cursor: pointer;
}

.pu-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

/* ---- ranking ---- */

.pu-ranks,
.pu-preview {
  padding: 16px;
  border: 1px solid var(--border-color, #E9E9F4);
  border-radius: 10px;
}

.pu-rank-group {
  padding-top: 14px;
  margin-top: 14px;
  border-top: 1px solid var(--border-color, #E9E9F4);
}

.pu-rank-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

/* The save button sat against the last row of boxes with nothing between. */
.pu-ranks .pu-actions {
  padding-top: 14px;
  margin-top: 16px;
  border-top: 1px solid var(--border-color, #E9E9F4);
}

/*
 * Each value is a bordered pill rather than a cell in a grid.
 *
 * The grid version put the label at the left of its cell and the box at the
 * right, so the gap from a label to its OWN box was the same as the gap to the
 * next label -- and at a wide viewport it was larger. "Black [ ] Blue [ ]"
 * then reads as though the box belongs to Blue. A border around the pair
 * settles it at any width, which is the only thing that can.
 */
.pu-rank-rows {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.pu-rank-row {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 5px 6px 5px 12px;
  border: 1px solid var(--border-color, #E9E9F4);
  border-radius: 999px;
  background: var(--card-bg, #fff);
}

.pu-rank-label {
  font-size: 12.5px;
  white-space: nowrap;
}

/* Sized to the three or four digits a rank ever holds. */
.pu-rank-row input {
  width: 62px;
  height: 30px;
  padding: 0 8px;
  font-size: 12.5px;
  line-height: 28px;
  text-align: center;
}

.pu-badge {
  display: inline-block;
  padding: 2px 9px;
  font-size: 11.5px;
  font-weight: 600;
  border-radius: 20px;
  background: #EEF0F5;
  color: #57607A;
}

.pu-badge.ok {
  background: #E7F7EE;
  color: #157347;
}

.pu-badge.warn {
  background: #FFF4E5;
  color: #A66300;
}

/* ---- preview ---- */

.pu-preview-group {
  padding-top: 10px;
  margin-top: 10px;
  border-top: 1px solid var(--border-color, #E9E9F4);
}

.pu-preview-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}

@media only screen and (max-width: 767px) {
  /*
   * One column, and the name field stops spanning two.
   *
   * auto-fit gives a single track at this width, but a `span 2` child forces
   * a second implicit one -- which squeezed Mode and When out of stock down to
   * about 60px, showing "A" and "Hi" with their hints wrapping into a column
   * eight lines tall.
   */
  .pu-grid {
    grid-template-columns: 1fr;
  }

  .pu-grid > .input-wrapper:first-child {
    grid-column: auto;
  }

  .pu-group-head {
    align-items: flex-start;
  }

  .pu-spacer {
    flex: 0 0 100%;
  }

  /* The save button goes full width rather than sitting alone on a line. */
  .pu-actions .primary-btn {
    width: 100%;
  }
}
</style>
