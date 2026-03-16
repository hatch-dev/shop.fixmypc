<template>
  <list-page
    ref="listPage"
    list-api="getPages"
    delete-api="deletePage"
    empty-store-variable="allPages"
    route-name="pages"
    :name="$t('admin.page')"
    :order-options="orderOptions"
    gate="page"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('category.slug') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr
        v-for="(value, index) in list"
        :key="index"
      >
        <td>
          <nuxt-link
            :to="`/pages/${value.id}`"
            class="link"
          >
            <h5 class="mx-w-300x">{{ value.title }}</h5>
          </nuxt-link>
        </td>
        <td>{{ value.slug }}</td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('page', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('page', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          created_at: {title: this.$t('category.date') },
          title: {title: this.$t('index.title')},
          slug: {title: this.$t('category.slug')}
        }
      }
    },
    components: {
      ListPage
    },
    mixins: [util],
    computed: {},
    methods: {},
    mounted() {
    }
  }
</script>

<style scoped>

</style>
