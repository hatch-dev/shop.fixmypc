<template>
  <list-page
    ref="listPage"
    list-api="getBusinessProducts"
    delete-api="deleteBusinessProduct"
    route-name="business-products"
    :name="$t('pages.businessProducts.businessProduct')"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">

      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('pages.businessProducts.product') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">

        <td>
          <input
            type="checkbox"
            :value="value.product_id"
            v-model="cbList"
          >
        </td>

        <td class="w-50x mx-w-50x">{{ from + index }}</td>

        <td>
          <nuxt-link
            class="link"
            :to="`/products/${value.product_id}`"
          >
            <h5 class="mx-w-300x">
              {{ value.title }}
            </h5>
          </nuxt-link>
        </td>

        <td>

          <button
            @click.prevent="$refs.listPage.editItem(value.product_id)"
            class="lite-btn"
          >
            {{ $t('category.edit') }}
          </button>

          <button
            @click.prevent="$refs.listPage.deleteItem(value.product_id)"
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
import ListPage from "~/components/partials/ListPage"
import util from "~/mixin/util"
import bulkDelete from "~/mixin/bulkDelete"

export default {

  name: "business-products",

  middleware: ['common-middleware', 'auth'],

  components: {
    ListPage
  },

  mixins: [util, bulkDelete],

  data() {
    return {

      orderOptions: {

        created_at: {
          title: this.$t('category.date')
        },

        title: {
          title: this.$t('index.title')
        },

      }

    }
  }

}
</script>

<style scoped>
</style>