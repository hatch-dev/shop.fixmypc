<template>
  <list-page
    ref="listPage"
    list-api="getSubscribers"
    delete-api="deleteSubscriber"
    route-name="subscribers"
    :name="$t('user.subs')"
    gate="subscriber"
    :add-button="false"
    :order-options="userObj"
    @list="itemList = $event"
  >

    <template
      v-slot:table-top="{orderOptions}"
    >
      <table-top
        :order-by-options="orderOptions"
        class="mb-20 mb-sm-15"
        @delete-bulk="deleteBulk"
      >
        <template
          v-slot:add-button
        >
          <nuxt-link
            :to="sendEmailRoute"
            class="button primary-btn"
          >
            {{ $t('user.sEmail') }}
          </nuxt-link>
        </template>
      </table-top>
    </template>

    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th>{{ $t('fSale.email') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th />
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td>
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td><span class="mx-w-300x">{{ value.email }}</span></td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('subscriber', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import TableTop from "~/components/partials/TableTop";
  import bulkDelete from "~/mixin/bulkDelete";


  export default {
    name: "rating-reviews",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        userObj: {
          created_at: { title: this.$t('category.date') },
          email: { title: this.$t('fSale.email') },
        },
      }
    },
    components: {
      TableTop,
      ListPage
    },
    mixins: [util, bulkDelete],
    computed: {
      sendEmailRoute(){
        // Removing the trailing slash
        return `${this.$route.path.replace(/\/$/, "")}/send-email`
      }
    },
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>

</style>
