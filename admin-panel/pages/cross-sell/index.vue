<template>
  <div>
    <div class="card ups-intro">
      <h4>{{ $t('crossSell.title') }}</h4>
      <p class="ups-hint">{{ $t('crossSell.description') }}</p>
    </div>

    <list-page
      ref="listPage"
      list-api="getCrossSells"
      delete-api="deleteCrossSell"
      route-name="cross-sell"
      gate="crosssell"
      :name="$t('crossSell.title')"
      :delete-message="$t('crossSell.deleteBody')"
      :order-options="orderOptions"
      @delete-bulk="deleteBulk"
      @list="itemList = $event"
    >
      <template v-slot:extra-filters>
        <div class="ups-filters">
          <select v-model="filterStatus" :aria-label="$t('crossSell.status')" @change="applyFilters">
            <option value="">{{ $t('crossSell.allStatuses') }}</option>
            <option value="1">{{ $t('crossSell.active') }}</option>
            <option value="2">{{ $t('crossSell.inactive') }}</option>
            <option value="3">{{ $t('crossSell.draft') }}</option>
          </select>

          <span v-if="cbList.length" class="ups-bulk">
            <em>{{ $t('crossSell.selectedCount', {count: cbList.length}) }}</em>
            <button v-if="$can('crosssell', 'edit')" class="lite-btn xs" :disabled="busy" @click.prevent="bulkToggle(1)">{{ $t('crossSell.activate') }}</button>
            <button v-if="$can('crosssell', 'edit')" class="lite-btn xs" :disabled="busy" @click.prevent="bulkToggle(2)">{{ $t('crossSell.deactivate') }}</button>
            <button v-if="$can('crosssell', 'delete')" class="lite-btn xs delete-btn" :disabled="busy" @click.prevent="deleteBulk">{{ $t('crossSell.delete') }}</button>
          </span>
        </div>
      </template>

      <template v-slot:add-button>
        <nuxt-link v-if="$can('crosssell', 'create')" class="button primary-btn" to="/cross-sell/create">
          {{ $t('crossSell.createButton') }}
        </nuxt-link>
      </template>

      <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th><input type="checkbox" @change="checkAll"></th>
          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('crossSell.name') }}</th>
          <th>{{ $t('crossSell.products') }}</th>
          <th>{{ $t('crossSell.assigned') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>{{ $t('title.act') }}</th>
        </tr>

        <tr v-for="(value, index) in list" :key="value.id" :class="{muted: Number(value.status) !== 1}">
          <td><input type="checkbox" :value="value.id" v-model="cbList"></td>
          <td class="w-50x mx-w-50x">{{ from + index }}</td>
          <td>
            <nuxt-link class="link" :to="`/cross-sell/${value.id}`">
              <span class="xs-list-name">{{ value.name }}</span>
            </nuxt-link>
            <small v-if="value.description" class="xs-list-desc">{{ value.description }}</small>
          </td>
          <td>{{ countLabel(value.products_count, 'productsCount') }}</td>
          <td>{{ countLabel(value.assignments_count, 'assignedCount') }}</td>
          <td>
            <span class="xs-badge" :class="statusClass(value.status)">{{ statusLabel(value.status) }}</span>
          </td>
          <td>{{ value.created }}</td>
          <!--
            A menu, not four buttons in a row.

            Four of them pushed the column past the table's width, so the last
            one sat behind a horizontal scrollbar and the row had to be
            scrolled to be acted on. This is the same control the Upsell list
            uses, down to the stylesheet.
          -->
          <!--
            Four plain buttons, wrapping.

            They were a "..." menu for a while, because four of them in a row
            pushed the column past the table's width. The menu solved the
            overflow and brought its own problems -- it had to be positioned
            against the viewport to escape the wrapper's `overflow: auto`, and
            it still had to out-rank the rows below it. Letting the buttons
            WRAP solves the same overflow with none of that: on a narrow
            screen they stack onto a second line inside the cell instead of
            widening the table.
          -->
          <td>
            <div class="xs-actions-row">
              <nuxt-link class="lite-btn xs" :to="`/cross-sell/${value.id}`">
                {{ $t('crossSell.view') }}
              </nuxt-link>

              <nuxt-link
                v-if="$can('crosssell', 'edit')"
                class="lite-btn xs"
                :to="`/cross-sell/${value.id}/edit`"
              >{{ $t('crossSell.edit') }}</nuxt-link>

              <button
                v-if="$can('crosssell', 'edit')"
                type="button"
                class="lite-btn xs"
                :disabled="busy"
                @click.prevent="toggle(value)"
              >{{ Number(value.status) === 1 ? $t('crossSell.deactivate') : $t('crossSell.activate') }}</button>

              <button
                v-if="$can('crosssell', 'delete')"
                type="button"
                class="lite-btn xs delete-btn"
                @click.prevent="$refs.listPage.deleteItem(value.id)"
              >{{ $t('crossSell.delete') }}</button>
            </div>
          </td>
        </tr>
      </template>
    </list-page>

    <!-- A switch-on that could not happen says which promotions and why,
         rather than failing the whole batch over one of them. -->
    <div v-if="blockedRows.length" class="ups-modal-back" @click.self="blockedRows = []">
      <div class="ups-modal" role="dialog" aria-modal="true">
        <h5>{{ $t('crossSell.cannotActivate') }}</h5>
        <div v-for="row in blockedRows" :key="row.id" class="ups-note warn">
          <h6>{{ row.name }}</h6>
          <ul><li v-for="(reason, i) in row.reasons" :key="i">{{ reason }}</li></ul>
        </div>
        <div class="ups-modal-actions">
          <button class="primary-btn" @click.prevent="blockedRows = []">{{ $t('crossSell.close') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import ListPage from '~/components/partials/ListPage'
  import util from '~/mixin/util'
  import bulkDelete from '~/mixin/bulkDelete'
  import crossSellStatus from '~/mixin/crossSellStatus'

  /**
   * Promotions -> Cross-sell.
   *
   * A list of PROMOTIONS: one row is "Laptop Accessories", however many
   * products it offers and however many laptops it sits under. Search, the
   * status filter and the sort all go to the server; the catalogue is far too
   * large to filter in the browser.
   */
  export default {
    name: 'cross-sell-list',
    middleware: ['common-middleware', 'auth'],
    components: {ListPage},
    mixins: [util, bulkDelete, crossSellStatus],

    data() {
      return {
        busy: false,
        blockedRows: [],
        filterStatus: this.$route.query.filter_status || ''
      }
    },

    computed: {
      orderOptions() {
        return {
          name: {title: this.$t('crossSell.name')},
          status: {title: this.$t('category.status')},
          products_count: {title: this.$t('crossSell.products')},
          assignments_count: {title: this.$t('crossSell.assigned')},
          created_at: {title: this.$t('category.date')}
        }
      }
    },

    methods: {
      applyFilters() {
        const query = {...this.$route.query}

        if (this.filterStatus === '') delete query.filter_status
        else query.filter_status = this.filterStatus

        // A filter change starts at page one.
        delete query.page

        this.$router.push({query})
      },

      /* "1 Product" rather than "1 Products". */
      countLabel(count, key) {
        const n = Number(count) || 0

        return n === 1 ? this.$t(`crossSell.${key}One`) : this.$t(`crossSell.${key}`, {count: n})
      },

      toggle(row) {
        return this.setStatus([row.id], Number(row.status) === 1 ? 2 : 1)
      },

      bulkToggle(status) {
        return this.setStatus(this.cbList, status)
      },

      /*
       * setRequest resolves with the envelope when the server refuses, so a
       * refusal is told apart by its shape rather than by being falsy.
       */
      async setStatus(ids, status) {
        this.busy = true

        try {
          const result = await this.setRequest({params: {ids, status}, api: 'toggleCrossSell'})

          if (result && Array.isArray(result.blocked) && result.blocked.length) {
            this.blockedRows = result.blocked
          }

          this.cbList = []
          await this.$refs.listPage.fetchingData()
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },

      ...mapActions('common', ['setRequest'])
    }
  }
</script>

<style scoped>
/*
 * The row's action buttons, kept beside the markup that needs them.
 *
 * Deliberately here rather than in assets/styles/cross-sell.styl. A rule in
 * the global stylus bundle and the template that depends on it reload
 * independently in dev -- the markup arrives, the layout rule does not, and
 * the table spends the gap overflowing its container with a scrollbar under
 * it. Scoped to this component, the two travel together.
 *
 * The class is `xs-actions-row` and not the `xs-row-actions` an earlier
 * revision used, because that earlier rule set `white-space: nowrap` -- which
 * is what forced the table wide in the first place. A stale copy of it in a
 * bundle that has not rebuilt would still match the old name and still
 * overflow; it cannot match this one.
 *
 * `flex-wrap` is the whole point: on a narrow screen the buttons stack onto a
 * second line INSIDE the cell rather than widening the table, so the wrapper
 * never has anything to scroll. Centred rather than right-aligned, because
 * hard against the table's edge puts them under the vertical scrollbar.
 */
.xs-actions-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.xs-actions-row > * {
  flex: 0 0 auto;
}
</style>
