<template>
  <list-page
    v-if="$can('admin', 'view')"
    ref="listPage"
    list-api="getAdmins"
    delete-api="deleteAdmin"
    route-name="admins-vendors"
    :name="$t('user.admVendUp')"
    :order-options="orderOptions"
    gate="admin"
  >
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('user.uName') }}</th>
        <th>{{ $t('fSale.email') }}</th>
        <th>{{ $t('user.role') }}</th>
        <th>{{ $t('user.verified') }}</th>
        <th>{{ $t('title.ac') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr
        v-for="(value, index) in list"
        :key="index"
        :class="{'new-data': !parseInt(value.viewed)}"
      >
        <td class="">
          <nuxt-link
            class="link"
            :to="`/admins-vendors/${value.id}`"
          >
            <h5 class="mx-w-300x">{{ value.name }}</h5>
          </nuxt-link>
        </td>
        <td>{{ value.username }}</td>
        <td>{{ value.email }}</td>
        <td>
          <span
            v-for="(i, n) in value.roles"
            :key="n"
          >
            {{ i.name }}
          </span>
        </td>

        <td> <span>{{ getVerificationStatus(value.verified) }}</span></td>

        <td>{{ getBoolean(value.active) }}</td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('admin', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)"
            class="lite-btn"
          >
            {{ $t('category.edit') }}
          </button>
          <button
            v-if="$can('admin', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)"
            class="delete-btn lite-btn"
          >
            {{ $t('category.delete') }}
          </button>
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
          email: { title: this.$t('fSale.email') },
          username: { title: this.$t('user.uName') },
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
