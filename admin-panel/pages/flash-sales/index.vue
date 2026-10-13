<template>
  <list-page
    ref="listPage"
    list-api="getFlashSales"
    delete-api="deleteFlashSale"
    route-name="flash-sales"
    :name="$t('fSale.fSale')"
    :order-options="orderOptions"
    gate="flash_sale"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:extra-filters>
      <!--
        .ups-filters is the shared filter-bar class (cross-sell and
        upsell use it too). It styles the selects to match the
        toolbar's other controls; base.styl gives inputs a height and
        a border but does not touch select, so a bare one renders as
        an unstyled native control beside them.
      -->
      <div class="ups-filters">
        <select v-model="filterWindow" :aria-label="$t('fSale.timeStatus')" @change="applyFilters">
          <option value="">{{ $t('fSale.allWindows') }}</option>
          <option value="upcoming">{{ $t('fSale.upcoming') }}</option>
          <option value="running">{{ $t('fSale.running') }}</option>
          <option value="expired">{{ $t('fSale.expired') }}</option>
        </select>

        <select v-model="filterStatus" :aria-label="$t('category.status')" @change="applyFilters">
          <option value="">{{ $t('fSale.allStatuses') }}</option>
          <option value="1">{{ $t('fSale.active') }}</option>
          <option value="2">{{ $t('fSale.inactive') }}</option>
        </select>
      </div>
    </template>

    <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('category.status') }}</th>
          <!-- New column header for expired/active status -->
          <th>{{ $t('fSale.timeStatus') }}</th>
          <th>{{ $t('prod.sTime') }}</th>
          <th>{{ $t('prod.eTime') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td class="w-50x mx-w-50x">{{ from + index }}</td>
          <td>
            <nuxt-link
              class="link"
              :to="`/flash-sales/${value.id}`"
            >
              <h5 class="mx-w-300x">{{ value.title }}</h5>
            </nuxt-link>
          </td>
          <td
            class="status"
            :class="{active: value.status == 1 }"
          >
            <span>{{ getStatus(value.status) }}</span>
          </td>
          <td
            class="time-status"
            :class="timeStatusClass(value)"
          >
            <span>{{ getTimeStatus(value) }}</span>
          </td>
          <td>{{ formatDate(value.start_time) }}</td>
          <td>{{ formatDate(value.end_time) }}</td>
          <td>{{ formatDate(value.created) }}</td>
          <td>
            <button
              v-if="$can('flash_sale', 'edit')"
              @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
            <button
              v-if="$can('flash_sale', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
          </td>
        </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "flash-sale",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          created_at: { title: this.$t('category.date') },
          title: {title: this.$t('index.title') },
          start_time: { title: this.$t('prod.sTime') },
          end_time: { title: this.$t('prod.eTime') },
          status: { title: this.$t('category.status') }
        },
        filterWindow: this.$route.query.filter_window || '',
        filterStatus: this.$route.query.filter_status || ''
      }
    },
    mixins: [util, bulkDelete],
    components: {
      ListPage
    },
    methods: {
      applyFilters() {
        const query = {...this.$route.query}

        if (this.filterWindow === '') delete query.filter_window
        else query.filter_window = this.filterWindow

        if (this.filterStatus === '') delete query.filter_status
        else query.filter_status = this.filterStatus

        // A filter change starts at page one.
        delete query.page

        this.$router.push({query})
      },

      /*
       * "2026-09-20 10:00:00" is not a format every browser parses -- Safari
       * reads it as Invalid Date, which compares false against everything and
       * read every sale as expired. Parsed by hand instead, as local time,
       * which is the zone the API converted these into.
       */
      parseDate(value) {
        if (!value) return null

        const m = String(value).match(
          /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/
        )

        if (!m) {
          const fallback = new Date(value)
          return isNaN(fallback.getTime()) ? null : fallback
        }

        return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0))
      },

      /*
       * Three states, not two. This used to derive the column from end_time
       * alone, so a sale scheduled for next month read "Active" -- which was
       * also what the storefront did with it until start_time was honoured.
       */
      timeState(sale) {
        const start = this.parseDate(sale.start_time)
        const end = this.parseDate(sale.end_time)
        const now = new Date()

        if (end && end < now) return 'expired'
        if (start && start > now) return 'upcoming'
        return 'running'
      },

      timeStatusClass(sale) {
        const state = this.timeState(sale)
        return {active: state === 'running', upcoming: state === 'upcoming', expired: state === 'expired'}
      },

      getTimeStatus(sale) {
        const state = this.timeState(sale)

        if (state === 'expired') return this.$t('fSale.expired')
        if (state === 'upcoming') return this.$t('fSale.upcoming')
        return this.$t('fSale.active')
      },

      // Format date from YYYY-MM-DD to DD-MM-YYYY
      formatDate(dateString) {
        if (!dateString) return '';
        
        // Check if the date string contains time component
        const hasTime = dateString.includes(':');
        
        if (hasTime) {
          // Handle datetime strings like "2023-12-31 23:59:59"
          const [datePart, timePart] = dateString.split(' ');
          const [year, month, day] = datePart.split('-');
          return `${day}-${month}-${year} ${timePart}`;
        } else {
          // Handle date-only strings like "2023-12-31"
          const [year, month, day] = dateString.split('-');
          return `${day}-${month}-${year}`;
        }
      }
    },
    mounted() {
    }
  }
</script>

<style scoped>
/*
 * Where the sale sits against the clock: Upcoming, Active, Expired.
 *
 * Built as the same soft pill the Status column beside it uses (see
 * table.styl `.status span`), off the shared tokens. The three states
 * used to disagree with each other and with the rest of the admin --
 * Active was bold green text with no pill, while Expired and Upcoming
 * were solid #F44336 / #FF9800 blocks -- so one row carried three
 * different visual languages.
 */
.time-status span {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 11px;
  line-height: 18px;
  font-weight: 650;
}

.time-status.active span {
  color: var(--success-text, #027A48);
  background: var(--success-bg, #ECFDF3);
}

.time-status.upcoming span {
  color: var(--warning-text, #7A5A00);
  background: var(--warning-bg, #FFF7D6);
}

.time-status.expired span {
  color: var(--danger-text, #B42318);
  background: var(--danger-bg, #FEE2E2);
}
</style>