<template>

  <list-page
    ref="listPage"
    list-api="getWalletOverview"
    route-name="wallet-overview"
    empty-store-variable="allWalletOverview"
    name="Wallet Overview"
    gate="wallet_log"
    @list="handleList"
  >

    <template v-slot:table="{ list }">

      <tbody>

        <!-- HEADER -->
        <tr class="lite-bold">
          <th class="w-50x mx-w-50x">
            <input type="checkbox" @change="checkAll">
          </th>
          <th>User</th>
          <th>Email</th>
          <th>Amount</th>
          <th>Cherry Points</th>
          <th>Type</th>
          <th>Source</th>
          <th>Date</th>
        </tr>

        <!-- ROWS -->
        <tr v-for="row in flatRows" :key="row._key">

          <!-- USER HEADER -->
          <template v-if="row._type === 'header'">
            <td colspan="8" class="user-header" @click="toggleUser(row.user_id)" style="cursor:pointer">
                <strong>{{ row.user }}</strong>
                <span class="text-muted">({{ row.email }})</span>
                <div class="user-summary">
                    Wallet: €{{ row.wallet_balance }}
                    | Points: {{ row.cherry_points }}
                </div>
                <span style="float:right">
                    {{ collapsedUsers[row.user_id] ? '+' : '-' }}
                </span>
            </td>
          </template>

          <!-- TRANSACTION -->
          <template v-else>
            <template v-if="!collapsedUsers[row.user_id]">
            <td class="w-50x mx-w-50x">
              <input type="checkbox" :value="row.id" v-model="cbList">
            </td>

            <td><h5>{{ row.user }}</h5></td>
            <td>{{ row.email }}</td>

            <td>
              <span v-if="row.amount > 0">
                €{{ Number(row.amount).toFixed(2) }}
              </span>
              <span v-else>-</span>
            </td>

            <td>
              <span v-if="row.points > 0">{{ row.points }}</span>
              <span v-else>-</span>
            </td>

            <td>
              <span :class="row.type === 'credit' ? 'badge bg-success' : 'badge bg-danger'">
                {{ row.type }}
              </span>
            </td>

            <td>{{ row.source || '-' }}</td>
            <td>{{ row.created }}</td>
            </template>
          </template>

        </tr>

      </tbody>

    </template>

  </list-page>

</template>

<script>
import ListPage from "~/components/partials/ListPage";
import bulkDelete from "~/mixin/bulkDelete";
import util from "~/mixin/util";

export default {
  name: "wallet-overview",
  middleware: ['common-middleware', 'auth'],
  data() {
    return {
        itemList: [],
        collapsedUsers: {},
        users: [],
        selectedUser: null
    }
    },
    computed: {
  flatRows() {
    const rows = []

    // IMPORTANT: list comes from ListPage via @list
    const groups = this.itemList || []

    groups.forEach(group => {

      // 👇 USER HEADER
      rows.push({
        _type: 'header',
        _key: 'h-' + group.user_id,
        user_id: group.user_id,
        user: group.user,
        email: group.email,
        wallet_balance: group.wallet_balance || 0,
        cherry_points: group.cherry_points || 0
      })

      // 👇 TRANSACTIONS
      group.transactions.forEach(txn => {
        rows.push({
          _type: 'txn',
          _key: txn.id,
          user_id: group.user_id,
          ...txn
        })
      })

    })

    return rows
  }
},
    methods: {
    handleList(res) {
        this.itemList = res
        this.collapsedUsers = {}
        res.forEach(user => {
            this.$set(this.collapsedUsers, user.user_id, true)
        })
    },
    toggleUser(userId) {
        this.$set(
        this.collapsedUsers,
        userId,
        !this.collapsedUsers[userId]
        )
    },
    viewItem(item) {
        console.log('Wallet item:', item)
    },

    filterUser(userId) {
        this.selectedUser = userId

        this.$refs.listPage.fetch({
        user_id: userId
        })
    },

    resetUser() {
        this.selectedUser = null
        this.$refs.listPage.fetch()
    }
    },
  components: {
    ListPage
  },

  mixins: [util, bulkDelete],
}
</script>

<style>
table {
  table-layout: fixed;
}

.badge {
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
}

.bg-success {
  background: #d1fae5;
  color: #065f46;
}

.bg-danger {
  background: #fee2e2;
  color: #991b1b;
}

.user-filter {
  margin-bottom: 15px;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}


.user-header {
  background: #f3f4f6;
}

.user-header td {
  padding: 10px;
  font-size: 14px;
}

.user-header {
  background: #f3f4f6;
  font-weight: 600;
  transition: 0.2s;
}

.user-header:hover {
  background: #e5e7eb;
}
</style>