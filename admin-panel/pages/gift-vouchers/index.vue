<template>
  <list-page
    ref="listPage"
    list-api="getGiftVouchers"
    delete-api="deleteGiftVoucher"
    route-name="gift-vouchers"
    :name="$t('pages.giftVouchers.listName')"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
    gate="gift_voucher"
  >
    <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>

          <th class="w-50x mx-w-50x">#</th>
          <th>{{ $t('pages.giftVouchers.colImage') }}</th>
          <th>{{ $t('pages.giftVouchers.colTitle') }}</th>
          <th>{{ $t('pages.giftVouchers.colAmounts') }}</th>
          <th>{{ $t('pages.giftVouchers.colMinQty') }}</th>
          <th>{{ $t('pages.giftVouchers.colMaxQty') }}</th>
          <th>{{ $t('pages.giftVouchers.colStatus') }}</th>
          <th>{{ $t('pages.giftVouchers.colSold') }}</th>
          <th>{{ $t('pages.giftVouchers.colCreated') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(item, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="item.id" v-model="cbList">
          </td>

          <td class="w-50x mx-w-50x">{{ from + index }}</td>

          <!-- Image -->
          <td>
            <span class="voucher-thumb">
              <img
                :src="getImageURL(item.image)"
                alt=""
              />
            </span>
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
            <span v-else>{{ $t('pages.giftVouchers.notApplicable') }}</span>
          </td>

          <!-- Min Quantity -->
          <td>{{ item.min_quantity }}</td>

          <!-- Max Quantity -->
          <td>{{ item.max_quantity }}</td>

          <!-- On sale or withdrawn -->
          <td>
            <span class="gv-status" :class="item.is_active ? 'on' : 'off'">
              {{ item.is_active ? $t('pages.giftVouchers.onSale') : $t('pages.giftVouchers.withdrawn') }}
            </span>
          </td>

          <!-- Cards sold -->
          <td>{{ item.sold || 0 }}</td>

          <!-- Created -->
          <td>{{ item.created }}</td>

          <!-- Actions -->
          <td>
            <button
              @click.prevent="$refs.listPage.editItem(item.id)"
              class="lite-btn"
            >
              {{ $t('pages.giftVouchers.edit') }}
            </button>

            <button
              @click.prevent="$refs.listPage.deleteItem(item.id)"
              class="delete-btn lite-btn"
            >
              {{ $t('pages.giftVouchers.delete') }}
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
        title: { title: this.$t('pages.giftVouchers.orderTitle') },
        created_at: { title: this.$t('pages.giftVouchers.orderDate') }
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
.gv-status {
  display: inline-flex;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.gv-status.on { background: #E6F6EC; color: #1E7E34; }
.gv-status.off { background: #F3F4F6; color: #6B7280; }

.voucher-thumb {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  padding: 6px;
  border-radius: 10px;
  border: 1px solid var(--border-color);
  background: var(--hover-color);
}

.voucher-thumb img {
  max-width: 100%;
  max-height: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
}

.amount-badge {
  display: inline-flex;
  align-items: center;
  background: var(--primary-soft);
  color: var(--primary-color);
  padding: 3px 10px;
  margin: 2px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
</style>