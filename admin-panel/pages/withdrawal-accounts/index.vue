<template>
  <list-page
    ref="listPage"
    list-api="getWithdrawalAccounts"
    delete-api="deleteWithdrawalAccount"
    route-name="withdrawal-accounts"
    :name="$t('admin.wAcc')"
    gate="withdrawal_account"
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
        <th>{{ $t('admin.default') }}</th>
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('admin.accNum') }}</th>
        <th>{{ $t('user.bank') }}</th>
        <th>{{ $t('user.branch') }}</th>
        <th />
      </tr>

      <tr v-for="(value, index) in list" :key="index">
        <td>
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td class="">
          <nuxt-link
            class="link"
            :to="`/withdrawal-accounts/${value.id}`"
          >
            <h5 class="mx-w-300x">{{ value.title }}</h5>
          </nuxt-link>
        </td>
        <td
          class="status"
          :class="{active: value.default === 1 }"
        >
          <span>{{ getFeatured(value.default) }}</span>
        </td>
        <td>{{ value.account_name }}</td>
        <td>{{ value.account_number }}</td>
        <td>{{ value.bank_name }}</td>
        <td>{{ value.branch_name }}</td>
        <td>
          <button
            v-if="$can('withdrawal_account', 'edit')"
            @click.prevent="$refs.listPage.editItem(value.id)"
            class="lite-btn"
          >
            {{ $t('category.edit') }}
          </button>
          <button
            v-if="$can('withdrawal_account', 'delete')"
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
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: 'withdrawal-accounts',
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        orderOptions: {
          title: { title: this.$t('index.title') },
          default: { title: this.$t('admin.default') },
          account_name: { title: this.$t('user.name') },
          account_number: { title: this.$t('admin.accNum') },
          bank_name: { title: this.$t('user.bank')  },
          branch_name: { title: this.$t('user.branch') },
          created_at: { title: this.$t('category.date') }
        }
      }
    },
    props: {},
    mixins: [util, bulkDelete],
    components: {
      ListPage
    },
    computed: {
    },
    methods: {

    },
    created() {

    },
    mounted() {
    }
  }
</script>
<style lang="stylus">
  @import "~/assets/styles/withdrawal.styl"
</style>
