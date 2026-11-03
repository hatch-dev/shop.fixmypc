<template>

  <list-page
    ref="listPage"
    list-api="getWalletOverview"
    route-name="wallet-overview"
    table-class="wallet-overview-table"
    empty-store-variable="allWalletOverview"
    :name="$t('pages.walletOverview.listName')"
    gate="wallet_log"
    @list="handleList"
  >

    <template v-slot:table>

      <!--
        One row per customer, every fact in its own column; the customer's
        wallet and Cherry Points transactions open beneath it in the same
        columns. The customer used to be one cell spanning the whole table,
        and a collapsed customer still left an empty row for each of their
        transactions.
      -->
      <tbody>

        <tr class="lite-bold">
          <th class="wo-toggle-col"></th>
          <th>{{ $t('pages.walletOverview.colUser') }}</th>
          <th>{{ $t('pages.walletOverview.colEmail') }}</th>
          <th class="wo-num">{{ $t('pages.walletOverview.colAmount') }}</th>
          <th class="wo-num">{{ $t('pages.walletOverview.colCherryPoints') }}</th>
          <th>{{ $t('pages.walletOverview.colType') }}</th>
          <th>{{ $t('pages.walletOverview.colSource') }}</th>
          <th>{{ $t('pages.walletOverview.colDate') }}</th>
        </tr>

        <template v-for="group in itemList">

          <!-- the customer, with their balances -->
          <tr
            :key="'u-' + group.user_id"
            class="wo-user-row"
            :class="{ 'is-open': isOpen(group.user_id) }"
            @click="toggleUser(group.user_id)"
          >
            <td class="wo-toggle-col">
              <button
                type="button"
                class="wo-toggle"
                :aria-expanded="isOpen(group.user_id) ? 'true' : 'false'"
                :aria-label="$t(isOpen(group.user_id) ? 'pages.walletOverview.hideTransactions' : 'pages.walletOverview.showTransactions', { name: group.user })"
                @click.stop="toggleUser(group.user_id)"
              >{{ isOpen(group.user_id) ? '−' : '+' }}</button>
            </td>
            <td><h5 class="wo-name">{{ group.user }}</h5></td>
            <td class="wo-email">{{ group.email }}</td>
            <td class="wo-num">
              <strong>{{ money(group.wallet_balance) }}</strong>
              <small class="wo-caption">{{ $t('pages.walletOverview.balanceCaption') }}</small>
            </td>
            <td class="wo-num">
              <strong>{{ group.cherry_points }}</strong>
              <small class="wo-caption">{{ $t('pages.walletOverview.balanceCaption') }}</small>
            </td>
            <td class="wo-muted">
              {{ $t('pages.walletOverview.transactionsCount', { count: (group.transactions || []).length }) }}
            </td>
            <td class="wo-muted">&mdash;</td>
            <td>
              {{ group.last_activity || '-' }}
              <small class="wo-caption">{{ $t('pages.walletOverview.lastActivity') }}</small>
            </td>
          </tr>

          <!-- their transactions, newest first -->
          <template v-if="isOpen(group.user_id)">
            <tr
              v-for="txn in group.transactions"
              :key="'t-' + txn.id"
              class="wo-txn-row"
            >
              <td class="wo-toggle-col"></td>
              <td class="wo-muted wo-indent">{{ group.user }}</td>
              <td class="wo-muted wo-email">{{ group.email }}</td>
              <td class="wo-num">
                <span v-if="Number(txn.amount) > 0">{{ signed(txn, money(txn.amount)) }}</span>
                <span v-else class="wo-muted">-</span>
              </td>
              <td class="wo-num">
                <span v-if="Number(txn.points) > 0">{{ signed(txn, txn.points) }}</span>
                <span v-else class="wo-muted">-</span>
              </td>
              <td>
                <span :class="txn.type === 'credit' ? 'badge bg-success' : 'badge bg-danger'">
                  {{ txn.type === 'credit' ? $t('pages.walletOverview.typeCredit') : (txn.type === 'debit' ? $t('pages.walletOverview.typeDebit') : txn.type) }}
                </span>
              </td>
              <td>{{ txn.source || '-' }}</td>
              <td>
                {{ txn.created }}
                <small class="wo-caption">{{ txn.time }}</small>
              </td>
            </tr>
          </template>

        </template>

      </tbody>

    </template>

  </list-page>

</template>

<script>
import ListPage from "~/components/partials/ListPage";
import util from "~/mixin/util";

export default {
  name: "wallet-overview",
  middleware: ['common-middleware', 'auth'],
  data() {
    return {
      // one entry per customer, from the current page
      itemList: [],
      // customers whose transactions are showing; all start folded
      openUsers: {}
    }
  },
  methods: {
    handleList(res) {
      this.itemList = res || []
      this.openUsers = {}
    },
    isOpen(userId) {
      return !!this.openUsers[userId]
    },
    toggleUser(userId) {
      this.$set(this.openUsers, userId, !this.openUsers[userId])
    },
    money(value) {
      return '€' + Number(value || 0).toFixed(2)
    },
    // "+€10.00" for a credit, "−€10.00" for a debit; as recorded otherwise
    signed(txn, text) {
      if (txn.type === 'credit') return '+' + text
      if (txn.type === 'debit') return '−' + text
      return String(text)
    }
  },
  components: {
    ListPage
  },
  mixins: [util],
}
</script>

<style>
/* Scoped to this page's own list: an unqualified `table` selector in an
   unscoped <style> block reaches every table in the admin, and was
   collapsing the product grid's Title column to an equal 1/13th share. */
.wallet-overview-table {
  table-layout: fixed;
  min-width: 760px;
}

.wallet-overview-table .wo-toggle-col {
  width: 44px;
}

.wallet-overview-table .wo-num {
  text-align: right;
  white-space: nowrap;
}

.wallet-overview-table .wo-email {
  overflow-wrap: anywhere;
}

.wallet-overview-table .wo-name {
  margin: 0;
}

.wallet-overview-table .wo-caption {
  display: block;
  font-size: 11px;
  font-weight: 400;
  color: #6b7280;
}

.wallet-overview-table .wo-muted {
  color: #6b7280;
}

.wallet-overview-table .wo-user-row {
  cursor: pointer;
  background: #f9fafb;
}

.wallet-overview-table .wo-user-row:hover,
.wallet-overview-table .wo-user-row.is-open {
  background: #f3f4f6;
}

.wallet-overview-table .wo-txn-row .wo-indent {
  padding-left: 22px;
  border-left: 3px solid #e5e7eb;
}

.wallet-overview-table .wo-toggle {
  width: 26px;
  height: 26px;
  line-height: 24px;
  padding: 0;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  background: #fff;
  font-weight: 700;
  cursor: pointer;
}

.wallet-overview-table .badge {
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
}

.wallet-overview-table .bg-success {
  background: #d1fae5;
  color: #065f46;
}

.wallet-overview-table .bg-danger {
  background: #fee2e2;
  color: #991b1b;
}
</style>
