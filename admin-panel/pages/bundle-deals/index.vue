<template>
  <list-page
    ref="listPage"
    list-api="getBundleDeals"
    delete-api="deleteBundleDeal"
    route-name="bundle-deals"
    table-class="bundle-deals-table"
    empty-store-variable="allBundleDeals"
    :name="$t('brand.dleDeal')"
    :order-options="orderOptions"
    gate="bundle_deal"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">
            <input type="checkbox" @change="checkAll">
          </th>
          <th class="w-50x mx-w-50x">#</th>
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
          <td class="w-50x mx-w-50x">{{ from + index }}</td>
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
                v-for="product in value.products.slice(0, 2)"
                :key="product.id"
                class="product-badge"
                :title="product.title"
              >
                {{ product.title }}
              </span>
              <span
                v-if="value.products.length > 2"
                class="product-badge product-badge--more"
                :title="value.products.slice(2).map(p => p.title).join(', ')"
              >
                +{{ value.products.length - 2 }} more
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
            <!--
              A bundle is all or nothing, so one unpublished or sold-out item
              hides the whole thing from the storefront. Worth saying here,
              rather than leaving an administrator to wonder why a live-looking
              deal never appears. The prices beside it are then what it WOULD
              cost, not what anyone can pay today.
            -->
            <span
              v-if="value.sellable === false"
              class="bundle-not-live"
              title="An item in this bundle is unpublished or out of stock, so the bundle is not shown on the storefront."
            >
              not showing
            </span>
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
  justify-content: flex-start;
  max-width: 320px;
}

.product-badge {
  display: inline-flex;
  align-items: center;
  max-width: 160px;
  background: var(--primary-soft);
  color: var(--primary-color);
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  line-height: 18px;
  border: 1px solid transparent;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.product-badge--more {
  background: var(--hover-color);
  color: var(--text-muted);
  cursor: default;
}

/* Scoped to this page's table for the reason given below: an unqualified
   selector in an unscoped block reaches every list in the admin. */
.bundle-deals-table .bundle-not-live {
  display: inline-block;
  margin-left: 6px;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--hover-color);
  color: var(--text-muted);
  font-size: 11px;
  font-weight: 600;
  line-height: 16px;
  white-space: nowrap;
  cursor: default;
}
/* Scoped to this page's own list: an unqualified `table` selector in an
   unscoped <style> block reaches every table in the admin, and was
   collapsing the product grid's Title column to an equal 1/13th share. */
.bundle-deals-table {
  table-layout: fixed;
}
</style>
