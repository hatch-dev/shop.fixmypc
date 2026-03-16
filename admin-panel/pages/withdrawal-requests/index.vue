<template>
  <div>
    <error-formatter />

    <withdrawal
      v-if="$can('withdrawal_request', 'create')"
      ref="withdrawal"
      @withdraw-done="reloadList"
    />
    <list-page
      ref="listPage"
      list-api="getWithdrawalRequests"
      delete-api="deleteWithdrawalRequest"
      route-name="withdrawal-requests"
      :name="$t('user.wReq')"
      gate="withdrawal_request"
      :order-options="orderOptions"
      @list="itemList = $event"
    >
      <template
        v-slot:table-top="{orderOptions}"
      >

        <div class="dply-felx gap-10 j-left f-wrap">
          <table-sort
            :order-by-options="orderOptions"
          />
          <inline-pop-over
            :arrow="true"
            class="bulk-action"
            ref="bulkDelete"
          >
            <template
              v-slot:button
            >
              {{ $t('title.act') }}
            </template>
            <template
              v-slot:content
            >
              <button @click.prevent="deleteAll" class="outline-btn">
                {{ $t('category.delete') }}
              </button>
            </template>
          </inline-pop-over>
        </div>


      </template>

      <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('index.amount') }}({{ currencyIcon }})</th>
          <th>{{ $t('user.apprBy') }}</th>
          <th>{{ $t('category.status') }}</th>
          <th>{{ $t('user.bank') }}</th>
          <th>{{ $t('user.branch') }}</th>
          <th>{{ $t('user.aName') }}</th>
          <th>{{ $t('user.acc') }}</th>
          <th>{{ $t('user.msg') }}</th>
          <th>{{ $t('category.created') }}</th>
          <th />
        </tr>

        <tr v-for="(value, index) in list" :key="index">
          <td>
            <input type="checkbox" :value="value.id" v-model="cbList">
          </td>
          <td>{{ value.amount }}</td>
          <td>{{ getDataFromObject(value, 'approved_admin.username', $t('prod.na')) }}</td>
          <td
            class="status"
            :class="value.status === 1 ? 'active' : value.status === 2 ? 'info': ''"
          >
            <span>{{ withdrawalStatus[value.status] }}</span>
          </td>
          <td>{{ value.withdrawal_account.bank_name }}</td>
          <td>{{ value.withdrawal_account.branch_name }}</td>
          <td>{{ value.withdrawal_account.account_name }}</td>
          <td>{{ value.withdrawal_account.account_number }}</td>
          <td><p class="mx-w-150x">{{ value.message }}</p></td>
          <td>{{ value.created }}</td>
          <td>
            <button
              v-if="$can('withdrawal_request', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(value.id)"
              class="delete-btn lite-btn"
            >
              {{ $t('category.delete') }}
            </button>
            <button
              v-if="withdrawalStatusIn.PENDING === parseInt(value.status) && $can('withdrawal_request', 'cancel')"
              class="delete-btn lite-btn"
              @click.prevent="openCancellation(value.id)"
            >
              {{ $t('title.cancel') }}
            </button>
            <button
              v-if="withdrawalStatusIn.PENDING === parseInt(value.status) && $can('withdrawal_request', 'approve')"
              class="edit-btn lite-btn"
              @click.prevent="approveWithdrawal(value.id)"
            >
              {{ $t('title.approve') }}
            </button>

          </td>
        </tr>
        <cancel-withdrawal
          v-if="cancelWithdrawalPopup && cancellationId"
          :id="cancellationId.toString()"
          v-outside-click="closeCancellation"
          @close="closeCancellation"
        />
      </template>
    </list-page>
  </div>
</template>

<script>
  import ListPage from "~/components/partials/ListPage"
  import util from '~/mixin/util'
  import {mapActions, mapGetters} from 'vuex'
  import TableSort from "~/components/partials/TableSort"
  import CancelWithdrawal from "~/components/partials/CancelWithdrawal"
  import outsideClick from '~/directives/outside-click'
  import Withdrawal from "~/components/partials/Withdrawal"
  import ErrorFormatter from "~/components/ErrorFormatter";
  import InlinePopOver from "~/components/InlinePopOver";
  import bulkDelete from "~/mixin/bulkDelete";

  export default {
    name: "withdrawn",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        cancellationId: '',
        cancelWithdrawalPopup: false,
        orderOptions: {
          amount: {title: this.$t('index.amount') },
          status: { title: this.$t('category.status') },
          approved_by: {title: this.$t('user.apprBy') },
          withdrawal_account_id: {title: this.$t('user.wAcc') },
          created_at: { title: this.$t('category.date') },
        }
      }
    },
    components: {
      InlinePopOver,
      ErrorFormatter,
      Withdrawal,
      CancelWithdrawal,
      ListPage,
      TableSort
    },
    directives: {outsideClick},
    mixins: [util, bulkDelete],
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      deleteAll() {
        this.$refs.bulkDelete.closePop()
        this.deleteBulk()
      },
      reloadWithdrawal(){
        this.$refs?.withdrawal?.fetchingData()
      },
      reloadList(){
        this.$refs?.listPage?.fetchingData()
      },
      openCancellation(id){
        this.setErrors(null)
        this.cancellationId = id
        this.cancelWithdrawalPopup = true
      },
      async approveWithdrawal(id){
        try {
          this.loading = true
          const data = await this.setRequest({
            params: {
              id: id,
            },
            api: 'approveWithdrawalRequest'
          })
          if(data){
            this.reloadList()
          }
          this.loading = false
        }catch (e) {
          return this.$nuxt.error(e)
        }
      },
      closeCancellation(evt){
        this.setErrors(null)
        this.cancellationId = ''
        this.cancelWithdrawalPopup = false
        if(evt?.reload){
          this.reloadList()
          this.reloadWithdrawal()
        }
      },
      ...mapActions('ui', ['setErrors']),
      ...mapActions('common', ['setRequest'])
    },
    mounted() {
    }
  }
</script>
<style lang="stylus">
  @import "~/assets/styles/withdrawal.styl"
</style>
