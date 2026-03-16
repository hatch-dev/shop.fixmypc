<template>
  <list-page
    ref="listPage"
    list-api="getRoles"
    delete-api="deleteRole"
    route-name="roles-permissions"
    :name="$t('profile.rp')"
    empty-store-variable="allRoles"
    :order-options="orderOptions"
    gate="role"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td class="">
          <nuxt-link
            class="link"
            :to="`/roles-permissions/${value.id}`"
          >
            <h5 class="mx-w-300x">{{ value.name }}</h5>
          </nuxt-link>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('role', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn">{{ $t('category.edit') }}</button>
          <button
            v-if="$can('role', 'delete')"
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
    name: "roles-permissions",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          name: { title: this.$t('user.name') },
          created_at: { title: this.$t('category.date') },
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
