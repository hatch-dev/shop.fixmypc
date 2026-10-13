<template>
  <list-page
    ref="listPage"
    list-api="getFlashDiscounts"
    delete-api="deleteFlashDiscount"
    route-name="flash-discount"
    empty-store-variable="allFlashDiscounts"
    name="Flash Discount"
    gate="flash_discount"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">
      <tr class="lite-bold">
        <th><input type="checkbox" @change="checkAll"></th>
        <th class="w-50x mx-w-50x">#</th>
        <th>Type</th>
        <th>Value</th>
        <th>Min Cart</th>
        <th>Max Discount</th>
        <th>Start</th>
        <th>End</th>
        <th>Status</th>
        <th></th>
      </tr>

      <tr v-for="(item, index) in list" :key="item.id">
        <td><input type="checkbox" :value="item.id" v-model="cbList"></td>
        <td class="w-50x mx-w-50x">{{ from + index }}</td>
        <td>{{ item.type === 'percentage' ? 'Percentage' : 'Fixed' }}</td>
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
          <button class="lite-btn" @click="$refs.listPage.editItem(item.id)">Edit</button>
          <button class="delete-btn lite-btn" @click="$refs.listPage.deleteItem(item.id)">Delete</button>
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
    statusLabel(item) {
      if (!item.is_active) return 'Inactive'
      if (item.live) return 'Live'

      return new Date(item.start_time) > new Date() ? 'Scheduled' : 'Ended'
    },
    statusClass(item) {
      return this.statusLabel(item) === 'Live' ? 'active' : 'inactive'
    },
    formatDate(date) {
      if (!date) return '-'

      return new Date(date).toLocaleString('en-IN', {
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
