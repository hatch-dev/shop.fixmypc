<template>
  <list-page
    ref="listPage"
    list-api="getBusinessProducts"
    delete-api="deleteBusinessProduct"
    route-name="business-products"
    :name="'Business Product'"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
      
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th>Product</th>
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
            Edit
          </button>

          <button
            @click.prevent="$refs.listPage.deleteItem(value.product_id)"
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
          title: 'Date'
        },

        title: {
          title: 'Title'
        },

      }

    }
  }

}
</script>

<style scoped>
</style>