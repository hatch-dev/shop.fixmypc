<template>
  <list-page
    ref="listPage"
    list-api="getUserMessages"
    delete-api="deleteUserMessage"
    route-name="user-messages"
    :name="$t('profile.umLower')"
    gate="message"
    :add-button="false"
    :order-options="orderOptions"
  >

    <template v-slot:table="{list}">

      <tr class="lite-bold">
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('fSale.email') }}</th>
        <th>{{ $t('setting.replied') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th>&nbsp;</th>
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td><span class="mx-w-300x">{{ value.name }}</span></td>
        <td><span class="mx-w-300x">{{ value.email }}</span></td>
        <td
          class="status"
          :class="{active: parseInt(value.replied) === 1 }"
        >
          <span>{{ getFeatured(value.replied) }}</span>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <button
            v-if="$can('message', 'view')"
            @click.prevent="$refs.listPage.editItem(value.id)" class="lite-btn"
          >
            {{ $t('category.view') }}
          </button>
          <button
            v-if="$can('message', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">
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
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          created_at: {title: this.$t('category.date') },
          name: { title: this.$t('user.name') },
          email: { title: this.$t('fSale.email') },
          replied: { title: this.$t('setting.replied') },
          viewed: { title: this.$t('setting.viewed') }
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
