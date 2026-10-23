<template>
  <list-page
    ref="listPage"
    list-api="getFlashDiscounts"
    delete-api="deleteFlashDiscount"
    route-name="flash-discount"
    empty-store-variable="allFlashDiscounts"
    :name="$t('pages.flashDiscount.listName')"
    gate="flash_discount"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">
      <tr class="lite-bold">
        <th><input type="checkbox" @change="checkAll"></th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('pages.flashDiscount.type') }}</th>
        <th>{{ $t('pages.flashDiscount.value') }}</th>
        <th>{{ $t('pages.flashDiscount.minCart') }}</th>
        <th>{{ $t('pages.flashDiscount.maxDiscount') }}</th>
        <th>{{ $t('pages.flashDiscount.start') }}</th>
        <th>{{ $t('pages.flashDiscount.end') }}</th>
        <th>{{ $t('pages.flashDiscount.status') }}</th>
        <th></th>
      </tr>

      <tr v-for="(item, index) in list" :key="item.id">
        <td><input type="checkbox" :value="item.id" v-model="cbList"></td>
        <td class="w-50x mx-w-50x">{{ from + index }}</td>
        <td>{{ item.type === 'percentage' ? $t('pages.flashDiscount.percentage') : $t('pages.flashDiscount.fixed') }}</td>
        <td>{{ displayValue(item) }}</td>

        <!--
          A nullable money column is blank when it is not set, and 0 is a real
          answer to a different question. `item.min_cart_value || '-'` printed
          a dash for both.
        -->
        <td>{{ money(item.min_cart_value) }}</td>
        <td>{{ money(item.max_discount) }}</td>

        <td>{{ formatDate(item.start_time) }}</td>
        <td>{{ formatDate(item.end_time) }}</td>
        <td>
          <!--
            Active is not the same as running. A switched-on promotion whose
            window has not opened, or has closed, read "Active" while taking
            nothing off any basket.
          -->
          <span :class="statusClass(item)">{{ statusLabel(item) }}</span>
        </td>
        <td>
          <button class="lite-btn" @click="$refs.listPage.editItem(item.id)">{{ $t('pages.flashDiscount.edit') }}</button>
          <button class="delete-btn lite-btn" @click="$refs.listPage.deleteItem(item.id)">{{ $t('pages.flashDiscount.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
import { mapGetters } from "vuex";
import bulkDelete from "~/mixin/bulkDelete";
import util from "~/mixin/util";
import ListPage from "~/components/partials/ListPage";

export default {
  middleware: ['common-middleware', 'auth'],
  mixins: [util, bulkDelete],
  components: { ListPage },
  computed: {
    currencyIcon() {
      return this.setting?.currency_icon || '$'
    },
    ...mapGetters('setting', ['setting'])
  },
  methods: {
    /** Blank, not a dash, when the column is genuinely unset. */
    money(value) {
      if (value === null || value === undefined || value === '') return '-'

      return this.priceFormatting(value, 2)
    },
    displayValue(item) {
      return item.type === 'percentage'
        // The column is decimal(10,2), so a plain 15 arrives as '15.00'.
        ? Number(item.value) + '%'
        : this.priceFormatting(item.value, 2)
    },
    /** Machine state; the label is translated from it, never compared. */
    statusKey(item) {
      if (!item.is_active) return 'inactive'
      if (item.live) return 'live'

      return new Date(item.start_time) > new Date() ? 'scheduled' : 'ended'
    },
    statusLabel(item) {
      return this.$t('pages.flashDiscount.' + this.statusKey(item))
    },
    statusClass(item) {
      return this.statusKey(item) === 'live' ? 'active' : 'inactive'
    },
    formatDate(date) {
      if (!date) return '-'

      return new Date(date).toLocaleString(this.$i18n?.locale || 'en', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      })
    }
  }
}
</script>
