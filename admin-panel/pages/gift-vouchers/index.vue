<template>
  <list-page
    ref="listPage"
    list-api="getGiftVouchers"
    delete-api="deleteGiftVoucher"
    route-name="gift-vouchers"
    :name="'Gift Vouchers'"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
    gate="gift_voucher"
  >
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>

          <th>Image</th>
          <th>Title</th>
          <th>Amounts</th>
          <th>Min Qty</th>
          <th>Max Qty</th>
          <th>Created</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(item, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="item.id" v-model="cbList">
          </td>

          <!-- Image -->
          <td>
            <img
              :src="getImageURL(item.image)"
              width="50"
              height="50"
              style="object-fit: cover; border-radius: 6px;"
            />
          </td>

          <!-- Title -->
          <td>
            <h5 class="mx-w-200x">{{ item.title }}</h5>
          </td>

          <!-- Amounts -->
          <td>
            <span v-if="item.amounts && item.amounts.length">
              <span
                v-for="(amt, i) in item.amounts"
                :key="i"
                class="amount-badge"
              >
                {{ currencyIcon }}{{ amt }}
              </span>
            </span>
            <span v-else>N/A</span>
          </td>

          <!-- Min Quantity -->
          <td>{{ item.min_quantity }}</td>

          <!-- Max Quantity -->
          <td>{{ item.max_quantity }}</td>

          <!-- Created -->
          <td>{{ item.created }}</td>

          <!-- Actions -->
          <td>
            <button
              @click.prevent="$refs.listPage.editItem(item.id)"
              class="lite-btn"
            >
              Edit
            </button>

            <button
              @click.prevent="$refs.listPage.deleteItem(item.id)"
              class="delete-btn lite-btn"
            >
              Delete
            </button>
          </td>
        </tr>
    </template>
  </list-page>
</template>

<script>
import util from '~/mixin/util'
import ListPage from "~/components/partials/ListPage"
import {mapGetters} from 'vuex'
import bulkDelete from "~/mixin/bulkDelete";

export default {
  name: "gift-vouchers",
  middleware: ['common-middleware', 'auth'],

  data(){
    return {
      orderOptions:{
        title: { title: 'Title' },
        created_at: { title: 'Date' }
      }
    }
  },

  mixins: [util, bulkDelete],

  components: {
    ListPage
  },

  computed: {
    currencyIcon() {
      return this.setting?.currency_icon || '$'
    },
    ...mapGetters('setting', ['setting'])
  }
}
</script>

<style scoped>
.amount-badge {
  display: inline-block;
  background: #f1f1f1;
  padding: 3px 8px;
  margin: 2px;
  border-radius: 4px;
  font-size: 12px;
}
</style>