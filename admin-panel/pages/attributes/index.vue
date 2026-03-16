<template>
  <list-page
    ref="listPage"
    list-api="getAttributes"
    delete-api="deleteAttribute"
    route-name="attributes"
    empty-store-variable="allAttributes"
    :name="$t('brand.attr')"
    :order-options="orderOptions"
    gate="attribute"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th class="w-50x mx-w-50x">
          <input type="checkbox" @change="checkAll">
        </th>
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('brand.aVal') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="w-50x mx-w-50x">
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td class="">
          <nuxt-link
            :to="`/attributes/${value.id}`"
            class="link"
          >
            <h5 class="mx-w-300x">{{ value.title }}</h5>
          </nuxt-link>
        </td>
        <td>
          <button
            v-for="(a, i) in value.values"
            :key="i"
            class="lite-btn"
            @click.prevent="$router.push(`/attributes/${value.id}`)"
          >
            {{ a.title }}
          </button>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('attribute', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('attribute', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import bulkDelete from "~/mixin/bulkDelete";
  export default {
    name: "attributes",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          title: { title: this.$t('index.title') },
          created_at: { title: this.$t('category.date') }
        }
      }
    },
    components: {
      ListPage
    },
    mixins: [bulkDelete],
    computed: {},
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>

</style>
