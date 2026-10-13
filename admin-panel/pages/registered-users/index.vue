<template>
  <list-page
    ref="listPage"
    list-api="getUsers"
    delete-api="deleteUser"
    route-name="users"
    :name="$t('user.users')"
    gate="user"
    :add-button="false"
    :order-options="userObj"
    @delete-bulk="deleteBulk"
    @list="itemList = $event"
  >
    <template v-slot:table="{list, from}">
      <tr class="lite-bold">
        <th>
          <input type="checkbox" @change="checkAll">
        </th>
        <th class="w-50x mx-w-50x">#</th>
        <th>{{ $t('user.name') }}</th>
        <th>{{ $t('fSale.email') }}</th>
        <th>Account</th>
        <th>Status</th>
        <th>{{ $t('user.verified') }}</th>
        <th>{{ $t('category.created') }}</th>
        <th />
      </tr>

      <tr
        v-for="(value, index) in list"
        :key="index"
        :class="{'new-data': !parseInt(value.viewed)}"
      >
        <td>
          <input type="checkbox" :value="value.id" v-model="cbList">
        </td>
        <td class="w-50x mx-w-50x">{{ from + index }}</td>
        <td><span class="mx-w-300x">{{ getDataFromObject(value, 'name', 'n/a') }}</span></td>
        <td><span class="mx-w-300x">{{ getDataFromObject(value, 'email', 'n/a') }}</span></td>

        <!--
          Account type, and underneath it the company the account registered.
          The list showed neither, so the only way to tell a business customer
          from a personal one was to know already.
        -->
        <td>
          <span class="acct-type">{{ typeLabel(value) }}</span>
          <small v-if="companyOf(value)" class="acct-company">
            {{ companyOf(value) }}
          </small>
        </td>

        <!--
          Account status and, for business and trade accounts, where their
          details and application stand. Three separate facts, shown as three,
          because an admin filtering for "suspended trade accounts" has to be
          able to see both halves of that.
        -->
        <td>
          <span class="acct-pill" :class="'acct-pill-' + toneOf(value)">
            {{ statusLabel(value) }}
          </span>
          <small v-if="secondaryLabel(value)" class="acct-second">
            {{ secondaryLabel(value) }}
          </small>
        </td>

        <td
          class="status"
          :class="{active: value.verified == 1 }"
        >
          <span>{{ getVerificationStatus(value.verified) }}</span>
        </td>
        <td>{{ value.created }}</td>
        <td>
          <!--
            The whole registration - company, VAT, sector, trade answers,
            preferences - lives on the detail screen. This row can only ever
            show a summary, so the way through to the rest has to be obvious.
          -->
          <nuxt-link
            :to="'/registered-users/' + value.id"
            class="lite-btn view-btn"
          >
            View
          </nuxt-link>

          <!--
            Suspend and reactivate sit behind user.edit, the permission that
            already governs changing a customer. The backend checks the same
            permission and the same transition, so hiding the button is a
            courtesy, not the control.
          -->
          <button
            v-if="$can('user', 'edit') && isActive(value)"
            class="lite-btn suspend-btn"
            :disabled="saving === value.id"
            @click.prevent="changeStatus(value, 'suspended')"
          >
            Suspend
          </button>

          <button
            v-if="$can('user', 'edit') && !isActive(value) && canReactivate(value)"
            class="lite-btn"
            :disabled="saving === value.id"
            @click.prevent="changeStatus(value, 'active')"
          >
            Reactivate
          </button>

          <button
            v-if="$can('user', 'delete')"
            @click.prevent="$refs.listPage.deleteItem(value.id)" class="delete-btn lite-btn">{{ $t('category.delete') }}</button>
        </td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import {mapActions} from 'vuex'
  import ListPage from "~/components/partials/ListPage";
  import util from '~/mixin/util'
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "registered-users",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        saving: null,
        userObj: {
          created_at: { title: this.$t('category.date') },
          name: { title: this.$t('user.name') },
          email: { title: this.$t('fSale.email') },
          verified: { title: this.$t('user.verified') }
        },
      }
    },
    components: {
      ListPage
    },
    mixins: [util, bulkDelete],
    computed: {},
    methods: {

      /*
       * Every read goes through value.account, the object the backend builds
       * in User::accountSummary - the same one the customer's own dashboard
       * reads. Reading the raw columns here instead is how the two screens end
       * up describing the same account differently.
       */
      accountOf(value) {
        return value?.account || {}
      },

      companyOf(value) {
        return value?.business_profile?.company_name || ''
      },

      typeLabel(value) {
        return {
          personal: 'Personal',
          business: 'Business',
          trade: 'Trade'
        }[this.accountOf(value).account_type] || 'Personal'
      },

      isActive(value) {
        const status = this.accountOf(value).status
        return !status || status === 'active'
      },

      // Rejected accounts are terminal; offering a button that always fails
      // is worse than not offering one.
      canReactivate(value) {
        return this.accountOf(value).status === 'suspended'
      },

      statusLabel(value) {
        return {
          active: 'Active',
          suspended: 'Suspended',
          pending: 'Pending',
          rejected: 'Rejected'
        }[this.accountOf(value).status] || 'Active'
      },

      // The trade decision, or the state of the company-details check, for the
      // account types where that means something.
      secondaryLabel(value) {
        const account = this.accountOf(value)

        if (account.account_type === 'trade') {
          return {
            approved: 'Trade approved',
            rejected: 'Trade rejected',
            suspended: 'Trade suspended',
            pending: 'Trade pending'
          }[account.trade_status] || 'Trade pending'
        }

        if (account.account_type === 'business') {
          return {
            pending: 'Details unchecked',
            verified: 'Details verified',
            rejected: 'Details rejected'
          }[account.verification_status] || ''
        }

        return ''
      },

      toneOf(value) {
        const status = this.accountOf(value).status

        if (status === 'suspended' || status === 'rejected') return 'bad'
        if (status === 'pending') return 'wait'
        return 'good'
      },

      async changeStatus(value, status) {

        const wording = status === 'suspended'
          ? 'Suspend this account? They will be able to sign in and see past orders, but not place new ones.'
          : 'Reactivate this account?'

        if (!confirm(wording)) {
          return
        }

        try {
          this.saving = value.id

          const updated = await this.setById({
            id: value.id,
            api: 'setUserStatus',
            params: {
              status,
              status_note: null
            }
          })

          /*
           * Repaint from the row the server sent back rather than assuming the
           * change landed: the transition can be refused, and the reviewer has
           * to see the state the account is actually in.
           */
          if (updated) {
            this.$set(value, 'account', updated.account)
            this.$set(value, 'status', updated.status)
          }

        } catch (e) {
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err') || 'Unable to update this account')
        } finally {
          this.saving = null
        }
      },

      ...mapActions('common', ['setById'])
    },
    mounted() {
    }
  }
</script>

<style scoped>

/*
 * Two facts stacked in one cell, the second muted. The table is already dense,
 * and giving account type and company their own columns pushed the actions off
 * the side of a laptop screen.
 */
.acct-type {
  display: block;
  font-weight: 600;
}

.acct-company,
.acct-second {
  display: block;
  margin-top: 2px;
  font-size: 11px;
  color: #6B7280;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.acct-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}

.acct-pill-good { background: #ECFDF5; color: #047857; }
.acct-pill-wait { background: #FFFBEB; color: #B45309; }
.acct-pill-bad  { background: #FEF2F2; color: #B91C1C; }

.suspend-btn {
  background: #FEF2F2;
  color: #B91C1C;
}

.view-btn {
  display: inline-block;
  background: #EFF6FF;
  color: #1D4ED8;
}

</style>
