<template>
  <div>
    <!-- The page says what it is for. "Upsell" alone does not tell an admin
         that taking one REPLACES the item rather than adding to it. -->
    <div class="card ups-intro">
      <h4>{{ $t('upsell.title') }}</h4>
      <p class="ups-hint">{{ $t('upsell.description') }}</p>
    </div>

    <list-page
      ref="listPage"
      list-api="getUpsellCampaigns"
      delete-api="deleteUpsellCampaign"
      route-name="upsell"
      gate="upsell"
      :name="$t('upsell.title')"
      :delete-message="$t('upsell.deleteBody')"
      :order-options="orderOptions"
      @delete-bulk="bulkDeleteOpen = true"
      @list="itemList = $event"
    >
      <!-- =========================== FILTERS =========================== -->
      <template v-slot:extra-filters>
        <div class="ups-filters">
          <select v-model="filters.filter_status" @change="applyFilters">
            <option value="">{{ $t('upsell.allStatuses') }}</option>
            <option value="1">{{ $t('upsell.active') }}</option>
            <option value="2">{{ $t('upsell.inactive') }}</option>
          </select>

          <select v-model="filters.filter_customer_type" @change="applyFilters">
            <option value="">{{ $t('upsell.allCustomers') }}</option>
            <option value="personal">{{ $t('upsell.personal') }}</option>
            <option value="business">{{ $t('upsell.business') }}</option>
            <option value="trade">{{ $t('upsell.trade') }}</option>
          </select>

          <select v-model="filters.filter_location" @change="applyFilters">
            <option value="">{{ $t('upsell.anyLocation') }}</option>
            <option value="product">{{ $t('upsell.productPage') }}</option>
            <option value="cart">{{ $t('upsell.cartPage') }}</option>
            <option value="checkout">{{ $t('upsell.checkoutPage') }}</option>
          </select>

          <!-- Asked of the DATES alone: a switched-on campaign that ended last
               week answers "Expired" here, which is why nothing is showing and
               is exactly what the admin came to find out. -->
          <select v-model="filters.filter_schedule" @change="applyFilters">
            <option value="">{{ $t('upsell.anySchedule') }}</option>
            <option value="active">{{ $t('upsell.activeNow') }}</option>
            <option value="scheduled">{{ $t('upsell.scheduled') }}</option>
            <option value="expired">{{ $t('upsell.expired') }}</option>
            <option value="none">{{ $t('upsell.noDateRestriction') }}</option>
          </select>

          <button v-if="hasFilters" class="lite-btn xs" @click.prevent="resetFilters">
            {{ $t('upsell.clear') }}
          </button>

          <!-- The bulk bar appears only with a selection, so it never competes
               with the filters for room on a narrow screen. -->
          <span v-if="cbList.length" class="ups-bulk">
            <em>{{ $t('upsell.selectedCount', {count: cbList.length}) }}</em>

            <button
              v-if="$can('upsell', 'edit')"
              class="lite-btn xs"
              :disabled="busy"
              @click.prevent="bulkToggle(1)"
            >{{ $t('upsell.activate') }}</button>

            <button
              v-if="$can('upsell', 'edit')"
              class="lite-btn xs"
              :disabled="busy"
              @click.prevent="bulkToggle(2)"
            >{{ $t('upsell.deactivate') }}</button>

            <button
              v-if="$can('upsell', 'delete')"
              class="lite-btn xs delete-btn"
              :disabled="busy"
              @click.prevent="bulkDeleteOpen = true"
            >{{ $t('upsell.delete') }}</button>
          </span>
        </div>
      </template>

      <template v-slot:add-button>
        <nuxt-link
          v-if="$can('upsell', 'create')"
          class="button primary-btn"
          to="/upsell/create"
        >{{ $t('upsell.createButton') }}</nuxt-link>
      </template>

      <!-- ============================ TABLE ============================ -->
      <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th><input type="checkbox" @change="checkAll"></th>
          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('upsell.name') }}</th>
          <th>{{ $t('upsell.sourceProducts') }}</th>
          <th>{{ $t('upsell.upgradeOptions') }}</th>
          <th>{{ $t('upsell.displayOn') }}</th>
          <th>{{ $t('upsell.customerType') }}</th>
          <th>{{ $t('upsell.priority') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('upsell.dateRange') }}</th>
          <th>{{ $t('title.act') }}</th>
        </tr>

        <tr
          v-for="(value, index) in list"
          :key="value.id"
          :class="{muted: Number(value.status) !== 1}"
        >
          <td><input type="checkbox" :value="value.id" v-model="cbList"></td>

          <td class="w-50x mx-w-50x">{{ from + index }}</td>

          <td>
            <nuxt-link class="link" :to="`/upsell/${value.id}`">
              <h5 class="mx-w-300x">{{ value.name }}</h5>
            </nuxt-link>
            <span v-if="value.description" class="ups-desc">{{ value.description }}</span>
          </td>

          <!-- Counts, not names. A campaign routinely covers a dozen products
               and the list has to stay readable; the detail screen is one
               click away and shows every one of them. -->
          <td>
            <nuxt-link class="link" :to="`/upsell/${value.id}`">
              <span class="ups-small">{{ sourceLabel(value) }}</span>
            </nuxt-link>
          </td>

          <td>
            <nuxt-link class="link" :to="`/upsell/${value.id}`">
              <span class="ups-small">{{ optionLabel(value) }}</span>
            </nuxt-link>
          </td>

          <td><span class="ups-small">{{ surfaceLabel(value) }}</span></td>
          <td><span class="ups-small">{{ customerLabel(value.customer_type) }}</span></td>
          <td><span class="ups-chip">{{ value.priority }}</span></td>

          <td>
            <!-- Green means "a customer could see this right now". A campaign
                 switched on but scheduled or expired says so in the date
                 column rather than quietly claiming to be working. -->
            <span class="ups-badge" :class="value.is_live ? 'live' : ''">
              {{ Number(value.status) === 1 ? $t('upsell.active') : $t('upsell.inactive') }}
            </span>
          </td>

          <!-- The badge only appears when it says something the dates do not:
               with no dates set, "No date restriction" would otherwise be
               printed twice in the same cell. -->
          <td>
            <span class="ups-small">{{ dateLabel(value) }}</span>
            <template v-if="value.schedule_state !== 'always'">
              <br>
              <span class="ups-badge" :class="scheduleClass(value)">{{ scheduleLabel(value) }}</span>
            </template>
          </td>

          <td class="ups-actions">
            <div class="ups-menu" v-outside-click="closeMenus">
              <button class="ups-menu-btn" @click.prevent="toggleMenu(value.id)">&hellip;</button>

              <div v-if="openMenu === value.id" class="ups-menu-list">
                <button @click.prevent="go(`/upsell/${value.id}`)">
                  {{ $t('upsell.view') }}
                </button>

                <button
                  v-if="$can('upsell', 'edit')"
                  @click.prevent="go(`/upsell/${value.id}/edit`)"
                >{{ $t('upsell.edit') }}</button>

                <button
                  v-if="$can('upsell', 'create')"
                  @click.prevent="openDuplicate(value)"
                >{{ $t('upsell.duplicate') }}</button>

                <button
                  v-if="$can('upsell', 'edit')"
                  @click.prevent="toggleOne(value)"
                >{{ Number(value.status) === 1 ? $t('upsell.deactivate') : $t('upsell.activate') }}</button>

                <hr>

                <button
                  v-if="$can('upsell', 'delete')"
                  class="danger"
                  @click.prevent="askDelete(value.id)"
                >{{ $t('upsell.delete') }}</button>
              </div>
            </div>
          </td>
        </tr>
      </template>
    </list-page>

    <!-- =========================== MODALS ============================== -->

    <!-- The count is in the question, and the second line says what is NOT
         deleted: "delete 4 upsells" beside a column of product counts reads as
         though the products go too. -->
    <div v-if="bulkDeleteOpen" class="ups-modal-back" @click.self="bulkDeleteOpen = false">
      <div class="ups-modal" role="dialog" aria-modal="true">
        <h5>{{ $t('upsell.bulkDeleteTitle', {count: cbList.length}) }}</h5>
        <p>{{ $t('upsell.bulkDeleteBody') }}</p>

        <div class="ups-modal-actions">
          <button class="lite-btn" @click.prevent="bulkDeleteOpen = false">{{ $t('upsell.cancel') }}</button>
          <button class="primary-btn delete-btn" :disabled="busy" @click.prevent="confirmBulkDelete">
            {{ busy ? $t('upsell.saving') : $t('upsell.delete') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="deleteId" class="ups-modal-back" @click.self="deleteId = null">
      <div class="ups-modal" role="dialog" aria-modal="true">
        <h5>{{ $t('upsell.deleteTitle') }}</h5>
        <p>{{ $t('upsell.deleteBody') }}</p>

        <div class="ups-modal-actions">
          <button class="lite-btn" @click.prevent="deleteId = null">{{ $t('upsell.cancel') }}</button>
          <button class="primary-btn delete-btn" :disabled="busy" @click.prevent="confirmDelete">
            {{ busy ? $t('upsell.saving') : $t('upsell.delete') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="duplicateRow" class="ups-modal-back" @click.self="duplicateRow = null">
      <div class="ups-modal" role="dialog" aria-modal="true">
        <h5>{{ $t('upsell.duplicateTitle') }}</h5>
        <p>{{ $t('upsell.duplicateOriginal', {name: duplicateRow.name}) }}</p>

        <div class="input-wrapper">
          <label class="block">{{ $t('upsell.newName') }}</label>
          <input type="text" maxlength="255" v-model="duplicateName">
        </div>

        <label class="dply-felx gap-10">
          <input type="checkbox" v-model="duplicateActive">
          <span class="ups-small">{{ $t('upsell.duplicateActivate') }}</span>
        </label>

        <div class="ups-modal-actions">
          <button class="lite-btn" @click.prevent="duplicateRow = null">{{ $t('upsell.cancel') }}</button>
          <button class="primary-btn" :disabled="busy" @click.prevent="confirmDuplicate">
            {{ busy ? $t('upsell.saving') : $t('upsell.duplicate') }}
          </button>
        </div>
      </div>
    </div>

    <!-- A bulk activation refuses the rows that cannot fire and names them,
         rather than failing the whole batch over one of them. -->
    <div v-if="blockedRows.length" class="ups-modal-back" @click.self="blockedRows = []">
      <div class="ups-modal" role="dialog" aria-modal="true">
        <h5>{{ $t('upsell.cannotActivate') }}</h5>

        <div v-for="row in blockedRows" :key="row.id" class="ups-note warn">
          <h6>{{ row.name }}</h6>
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
</template>

<script>
  import {mapActions} from 'vuex'
  import ListPage from '~/components/partials/ListPage'
  import outsideClick from '~/directives/outside-click.js'
  import util from '~/mixin/util'
  import bulkDelete from '~/mixin/bulkDelete'

  /**
   * Promotions -> Product Recommendations -> Upsell.
   *
   * A list of CAMPAIGNS, not of pairings: one row covers however many source
   * products and upgrade options it was configured with, which is the point of
   * the campaign shape -- "these three iPhones upgrade to these two models" is
   * one row here and six in a pair table.
   *
   * Every filter and the search box go to the SERVER. The catalogue is far too
   * large to filter in the browser, and "which upsell covers this SKU?" can
   * only be answered by a query.
   */
  export default {
    name: 'upsell-list',

    middleware: ['common-middleware', 'auth'],

    components: {ListPage},

    directives: {outsideClick},

    mixins: [util, bulkDelete],

    data() {
      return {
        openMenu: null,
        busy: false,
        bulkDeleteOpen: false,
        deleteId: null,
        duplicateRow: null,
        duplicateName: '',
        duplicateActive: false,
        blockedRows: [],
        filters: {
          filter_status: this.$route.query.filter_status || '',
          filter_customer_type: this.$route.query.filter_customer_type || '',
          filter_location: this.$route.query.filter_location || '',
          filter_schedule: this.$route.query.filter_schedule || ''
        }
      }
    },

    computed: {
      orderOptions() {
        return {
          // Priority first: this is a set of competing rules, and the one that
          // wins a tie is the interesting one.
          priority: {title: this.$t('upsell.priority')},
          name: {title: this.$t('upsell.name')},
          status: {title: this.$t('category.status')},
          created_at: {title: this.$t('category.date')}
        }
      },

      hasFilters() {
        return Object.keys(this.filters).some(key => this.filters[key] !== '')
      }
    },

    methods: {
      /* ---- filters ---- */

      applyFilters() {
        const query = {...this.$route.query}

        Object.keys(this.filters).forEach(key => {
          if (this.filters[key] === '') {
            delete query[key]
          } else {
            query[key] = this.filters[key]
          }
        })

        // A filter change starts at page one. Keeping the old page shows an
        // empty table and no explanation for it.
        delete query.page

        this.$router.push({query})
      },

      resetFilters() {
        Object.keys(this.filters).forEach(key => { this.filters[key] = '' })
        this.applyFilters()
      },

      /* ---- row menu ---- */

      toggleMenu(id) {
        this.openMenu = this.openMenu === id ? null : id
      },

      closeMenus() {
        this.openMenu = null
      },

      go(path) {
        this.closeMenus()
        this.$router.push(path)
      },

      /* ---- labels ---- */

      /*
       * "1 Product" rather than "1 Products". A count that reads as a bug is
       * one an admin stops trusting.
       */
      sourceLabel(row) {
        const count = Number(row.sources_count) || 0

        return count === 1
          ? this.$t('upsell.productCountOne')
          : this.$t('upsell.productCount', {count})
      },

      optionLabel(row) {
        const count = Number(row.options_count) || 0

        return count === 1
          ? this.$t('upsell.optionCountOne')
          : this.$t('upsell.optionCount', {count})
      },

      surfaceLabel(row) {
        const parts = []

        if (row.show_on_product_page) parts.push(this.$t('upsell.productPage'))
        if (row.show_on_cart_page) parts.push(this.$t('upsell.cartPage'))
        if (row.show_on_checkout_page) parts.push(this.$t('upsell.checkoutPage'))

        // A campaign showing nowhere is configured and invisible, which is
        // worth saying out loud rather than rendering as an empty cell.
        return parts.length ? parts.join(', ') : this.$t('upsell.nowhere')
      },

      customerLabel(type) {
        return {
          all: this.$t('upsell.customerAll'),
          personal: this.$t('upsell.personal'),
          business: this.$t('upsell.business'),
          trade: this.$t('upsell.trade')
        }[type] || type
      },

      dateLabel(row) {
        if (!row.start_date && !row.end_date) {
          return this.$t('upsell.always')
        }

        const from = row.start_date ? this.shortDate(row.start_date) : '…'
        const to = row.end_date ? this.shortDate(row.end_date) : '…'

        return `${from} → ${to}`
      },

      scheduleLabel(row) {
        return {
          always: this.$t('upsell.noDateRestriction'),
          running: this.$t('upsell.activeNow'),
          scheduled: this.$t('upsell.scheduled'),
          expired: this.$t('upsell.expired')
        }[row.schedule_state] || ''
      },

      scheduleClass(row) {
        return {
          always: '',
          running: 'live',
          scheduled: 'scheduled',
          expired: 'expired'
        }[row.schedule_state] || ''
      },

      shortDate(value) {
        return String(value).replace('T', ' ').slice(0, 10)
      },

      /* ---- status ---- */

      toggleOne(row) {
        this.closeMenus()

        return this.applyToggle([row.id], Number(row.status) === 1 ? 2 : 1)
      },

      bulkToggle(status) {
        return this.applyToggle([...this.cbList], status)
      },

      async applyToggle(ids, status) {
        if (!ids.length) return

        this.busy = true

        try {
          const result = await this.setRequest({
            params: {ids, status},
            api: 'toggleUpsellCampaign'
          })

          /*
           * setRequest resolves with the RESPONSE rather than the payload when
           * the server refused (a 201 goes to ui/setErrors instead of
           * throwing), so a refusal has no `changed` key. Treating that as
           * success would leave a switch that appears to do nothing.
           */
          if (!result || !Array.isArray(result.changed)) {
            const errors = this.$store.getters['ui/errors'] || {}

            this.$store.dispatch('ui/setToastError',
              Object.keys(errors).reduce((all, key) => all.concat(errors[key]), []).join(' ')
                || this.$t('upsell.cannotActivate'))

            return
          }

          // Partial success: what went through went through, and the rest is
          // named rather than silently skipped.
          this.blockedRows = Array.isArray(result.blocked) ? result.blocked : []

          this.cbList = []
          await this.$refs.listPage.fetchingData()
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },

      /* ---- delete ---- */

      askDelete(id) {
        this.closeMenus()
        this.deleteId = id
      },

      async confirmDelete() {
        const id = this.deleteId

        this.busy = true

        try {
          await this.deleteData({params: id, api: 'deleteUpsellCampaign'})

          this.deleteId = null
          this.cbList = this.cbList.filter(value => value !== id)
          await this.$refs.listPage.fetchingData()
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },

      async confirmBulkDelete() {
        if (!this.cbList.length) {
          this.bulkDeleteOpen = false

          return
        }

        this.busy = true

        try {
          // The endpoint takes a comma-joined list, so a bulk delete is one
          // request and one transaction rather than N racing each other.
          await this.deleteData({
            params: this.cbList.join(','),
            api: 'deleteUpsellCampaign'
          })

          this.bulkDeleteOpen = false
          this.cbList = []
          await this.$refs.listPage.fetchingData()
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },

      /* ---- duplicate ---- */

      openDuplicate(row) {
        this.closeMenus()
        this.duplicateRow = row
        this.duplicateName = `${row.name} (Copy)`
        // A copy is inactive by default so it never competes with its original
        // for the same source products before anyone has edited it.
        this.duplicateActive = false
      },

      async confirmDuplicate() {
        this.busy = true

        try {
          const copy = await this.setById({
            id: this.duplicateRow.id,
            api: 'duplicateUpsellCampaign',
            params: {
              name: String(this.duplicateName || '').trim(),
              status: this.duplicateActive ? 1 : 2
            }
          })

          if (copy?.id) {
            this.duplicateRow = null
            this.$router.push(`/upsell/${copy.id}`)
          }
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
        } finally {
          this.busy = false
        }
      },

      ...mapActions('common', ['setRequest', 'setById', 'deleteData'])
    },

    watch: {
      // Any filter or search change re-reads the list, server-side.
      '$route.query'() {
        this.$refs.listPage.fetchingData()
      }
    }
  }
</script>
