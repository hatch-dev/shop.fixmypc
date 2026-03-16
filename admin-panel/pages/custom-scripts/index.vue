<template>
  <list-page
    v-if="$can('header_link', 'view')"
    ref="listPage"
    list-api="getCustomScripts"
    delete-api="deleteCustomScript"
    route-name="custom-scripts"
    :name="$t('title.cScript')"
    gate="header_link"
    :order-options="orderOptions"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th class="w-50x mx-w-50x">
          <input type="checkbox" @change="checkAll">
        </th>
        <th>{{ $t('title.rParam') }}</th>
        <th>{{ $t('category.status') }}</th>
        <th/>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="w-50x mx-w-50x">
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>


        <td>
          <span>{{ value.route_pattern }}</span>
        </td>

        <td
          class="status"
          :class="{active: value.status == 1 }"
        >
          <span>{{ getStatus(value.status) }}</span>
        </td>

        <td>
          <button
            v-if="$can('header_link', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('header_link', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>

      </tr>
    </template>
  </list-page>

</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import outsideClick from "~/directives/outside-click"
  import LazyImage from "~/components/LazyImage";
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "banners",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          created_at: {title: this.$t('category.date') },
          status: { title: this.$t('category.status') },
          route_pattern: {title: this.$t('title.rParam')}
        },
        listApi: 'getCustomScripts',
        deleteApi: 'deleteCustomScript',
        routeName: 'custom-scripts',
        loading: false,
        result:[]
      }
    },
    directives: {outsideClick},
    components: {
      LazyImage,
      ListPage
    },
    mixins: [util, bulkDelete],
    computed: {
    },
    methods: {
    },
    async mounted() {
    }
  }
</script>

