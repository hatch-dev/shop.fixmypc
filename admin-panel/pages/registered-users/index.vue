<template>
  <list-page
    ref="listPage"
    list-api="getUsers"
    delete-api="deleteUser"
    route-name="users"
    :name="$t('user.users')"
    gate="user"
    :add-button="false"
    :order-options="userObj"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('fSale.email') }}</th>
        <th>{{ $t('user.verified') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th />
      </tr>

      <tr
        v-for="(value, index) in list"
        :key="index"
        :class="{'new-data': !parseInt(value.viewed)}"
      >
        <td>
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td><span class="mx-w-300x">{{ getDataFromObject(value, 'name', 'n/a') }}</span></td>
        <td><span class="mx-w-300x">{{ getDataFromObject(value, 'email', 'n/a') }}</span></td>
        <td
          class="status"
          :class="{active: value.verified == 1 }"
        >
          <span>{{ getVerificationStatus(value.verified) }}</span>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('user', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "rating-reviews",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        userObj: {
          created_at: { title: this.$t('category.date') },
          name: { title: this.$t('user.name') },
          email: { title: this.$t('fSale.email') },
          verified: { title: this.$t('user.verified') }
        },
      }
    },
    components: {
      ListPage
    },
    mixins: [util, bulkDelete],
    computed: {},
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>

</style>
