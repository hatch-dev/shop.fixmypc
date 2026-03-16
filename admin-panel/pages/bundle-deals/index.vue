<template>
  <list-page
    ref="listPage"
    list-api="getBundleDeals"
    delete-api="deleteBundleDeal"
    route-name="bundle-deals"
    empty-store-variable="allBundleDeals"
    :name="$t('brand.dleDeal')"
    :order-options="orderOptions"
    gate="bundle_deal"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.title') }}</th>
          <th>Products</th>
          <th>Total Price</th>
          <th>Discount</th>
          <th>Final Price</th>
          <th>{{ $t('category.created') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td class="w-50x mx-w-50x">
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td>
            <nuxt-link
              :to="`/bundle-deals/${value.id}`"
              class="dply-felx j-left link"
            >
              <h5 class="mx-w-300x">{{ value.title }}</h5>
            </nuxt-link>
          </td>
          <td>
            <div class="product-list" v-if="value.products && value.products.length">
              <span
                v-for="product in value.products"
                :key="product.id"
                class="product-badge"
              >
                {{ product.title }}
              </span>
            </div>
            <span v-else>-</span>
          </td>
          <td>
            €{{ Number(value.total_price).toFixed(2) }}
          </td>
          <td>
            €{{ Number(value.discount_amount).toFixed(2) }}
          </td>
          <td>
            €{{ Number(value.final_price).toFixed(2) }}
          </td>
          <td>{{ value.created }}</td>
          <td>
            <button
              v-if="$can('bundle_deal', 'edit')"
              @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
            <button
              v-if="$can('bundle_deal', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
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
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          title: { title: this.$t('index.title') },
          created_at: { title: this.$t('category.date') }
        }
      }
    },
    mixins: [util, bulkDelete],
    components: {
      ListPage
    },
    computed: {
    },
    methods:{
    },
    mounted() {
    }
  }
</script>

<style>
.product-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.product-badge {
  background: #eef2ff;
  color: #4338ca;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 500;
  border: 1px solid #c7d2fe;
}
table {
  table-layout: fixed;
}
</style>
