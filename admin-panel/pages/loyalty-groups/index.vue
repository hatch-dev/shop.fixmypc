<template>
  <list-page
    ref="listPage"
    list-api="getLoyaltyGroups"
    delete-api="deleteLoyaltyGroup"
    route-name="loyalty-groups"
    :name="'Loyalty Groups'"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
      
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>

        <th>Title</th>
        <th>Discount Type</th>
        <th>Discount Value</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">

        <td>
          <input
            type="checkbox"
            :value="value.id"
            v-model="cbList"
          >
        </td>

        <td>
          <nuxt-link
            class="link"
            :to="`/loyalty-groups/${value.id}`"
          >
            <h5 class="mx-w-300x">
              {{ value.title }}
            </h5>
          </nuxt-link>
        </td>

        <td>
          {{ value.discount_type }}
        </td>

        <td>
          {{ value.discount_value }}
        </td>

        <td>

          <button
            @click.prevent="$refs.listPage.editItem(value.id)"
            class="lite-btn"
          >
            Edit
          </button>

          <button
            @click.prevent="$refs.listPage.deleteItem(value.id)"
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

  name: "loyalty-groups",

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

        discount_type: {
          title: 'Discount Type'
        },

        discount_value: {
          title: 'Discount Value'
        }

      }

    }
  }

}
</script>

<style scoped>
</style>