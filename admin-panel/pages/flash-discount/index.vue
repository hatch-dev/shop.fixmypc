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
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th><input type="checkbox" @change="checkAll"></th>
        <th>Type</th>
        <th>Value</th>
        <th>Min Cart</th>
        <th>Max Discount</th>
        <th>Start</th>
        <th>End</th>
        <th>Status</th>
        <th></th>
      </tr>

      <tr v-for="item in list" :key="item.id">
        <td><input type="checkbox" :value="item.id" v-model="cbList"></td>
        <td>{{ item.type }}</td>
        <td>{{ item.value }}</td>
        <td>{{ item.min_cart_value || '-' }}</td>
        <td>{{ item.max_discount || '-' }}</td>
        <td>{{ formatDate(item.start_time) }}</td>
        <td>{{ formatDate(item.end_time) }}</td>
        <td>
          <span :class="item.is_active ? 'active' : 'inactive'">
            {{ item.is_active ? 'Active' : 'Inactive' }}
          </span>
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
import bulkDelete from "~/mixin/bulkDelete";
import util from "~/mixin/util";
import ListPage from "~/components/partials/ListPage";

export default {
  mixins: [util, bulkDelete],
  components: { ListPage },
  methods: {
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