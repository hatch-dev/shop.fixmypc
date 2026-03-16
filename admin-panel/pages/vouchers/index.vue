<template>
  <list-page
    ref="listPage"
    list-api="getVouchers"
    delete-api="deleteVoucher"
    route-name="vouchers"
    :name="$t('prod.voucher')"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
    gate="voucher"
  >
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('prod.code') }}</th>
          <th>{{ $t('brand.price') }}({{ currencyIcon }})</th>
          <th>{{ $t('prod.capped') }}({{ currencyIcon }})</th>
          <th>{{ $t('prod.spent') }}({{ currencyIcon }})</th>
          <th>{{ $t('prod.usage') }}</th>
          <th>{{ $t('prod.limit') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(item, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="item.id" v-model="cbList">
          </td>
          <td><h5 class="mx-w-300x">{{ item.title }}</h5></td>
          <td>{{ item.code }}</td>
          <td>{{ priceFormat({type: item.type, price: item.price, icon: currencyIcon}) }}</td>
          <td>
            <span v-if="getDataFromObject(item, 'capped_price')">
              {{ getDataFromObject(item, 'capped_price') }}
            </span>
            <span v-else>
              N/A
            </span>
          </td>
          <td>
            <span v-if="getDataFromObject(item, 'min_spend')">
             {{ getDataFromObject(item, 'min_spend') }}
            </span>
            <span v-else>
              N/A
            </span>
          </td>
          <td>{{ item.usage_limit }}</td>
          <td>{{ item.limit_per_customer }}</td>
          <td
            class="status"
            :class="{active: item.status == 1 }"
          >
            <span>{{ getStatus(item.status) }}</span>
          </td>
          <td>{{ item.created }}</td>
          <td>
            <button
              v-if="$can('voucher', 'edit')"
              @click.prevent="$refs.listPage.editItem(item.id)"
              class="lite-btn"
            >
              {{ $t('category.edit') }}
            </button>
            <button
              v-if="$can('voucher', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(item.id)"
              class="delete-btn lite-btn"
            >
              {{ $t('category.delete') }}
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
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          title: { title: this.$t('index.title') },
          created_at: { title: this.$t('category.date') },
          status: { title: this.$t('category.status') }
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
    },
    methods:{
    },
    mounted() {
    }
  }
</script>

<style scoped>

</style>
