<template>
  <list-page
    ref="listPage"
    list-api="getUpdatedUpsells"
    delete-api="deleteUpdatedUpsell"
    route-name="upsell"
    :name="$t('index.upsell')"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td>
            <nuxt-link
              class="link"
              :to="`/upsell/${value.id}`"
            >
              <h5 class="mx-w-300x">{{ value.title }}</h5>
            </nuxt-link>
          </td>
          <td
            class="status"
            :class="{active: value.status == 1 }"
          >
            <span>{{ getStatus(value.status) }}</span>
          </td>
          <td>
            <button
              @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
            <button          
              @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
          </td>
        </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "crosssell-flash-sales",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          created_at: { title: this.$t('category.date') },
          title: {title: this.$t('index.title') },
          status: { title: this.$t('category.status') }
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

<style scoped>

</style>
