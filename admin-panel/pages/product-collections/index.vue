<template>
  <list-page
    ref="listPage"
    list-api="getProductCollections"
    delete-api="deleteProductCollection"
    empty-store-variable="allProductCollections"
    route-name="product-collections"
    :name="$t('brand.prodCol')"
    :order-options="orderOptions"
    gate="product_collection"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.title') }}</th>
          <th>{{ $t('category.slug') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th>&nbsp;</th>
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td class="w-50x mx-w-50x">
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td>
            <nuxt-link
              class="link"
              :to="`/product-collections/${value.id}`"
            >
              <h5 class="mx-w-300x">{{ value.title }}</h5>
            </nuxt-link>
          </td>
          <td>{{ value.slug }}</td>
          <td
            class="status"
            :class="{active: value.status == 1 }"
          >
            <span>{{ getStatus(value.status) }}</span>
          </td>
          <td>{{ value.created }}</td>
          <td>
            <button
              v-if="$can('product_collection', 'edit')"
              @click.prevent="$refs.listPage.editItem(value.id)"
              class="lite-btn"
            >
              {{ $t('category.edit') }}</button>
            <button
              v-if="$can('product_collection', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(value.id)"
              class="delete-btn lite-btn"
            >
              {{ $t('category.delete') }}</button>
          </td>
        </tr>
    </template>
  </list-page>
</template>

<script>
  import util from "~/mixin/util";
  import ListPage from "~/components/partials/ListPage";
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data(){
      return {
        orderOptions:{
          title: {title: this.$t('index.title') },
          created_at: {title: this.$t('category.date') },
          status: {title: this.$t('category.status') }
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
