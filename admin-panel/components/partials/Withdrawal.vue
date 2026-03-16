<template>
  <div>
    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

      <div
        class="withdrawal-section"
      >
        <div class="amount">
          <h6 class="sub-title">{{ $t('list.wa') }}</h6>
          <h3>{{ priceFormatting(withdrawnAmount) }}</h3>
        </div>
        <div class="amount">
          <h6 class="sub-title">{{ $t('list.pw') }}</h6>
          <h3>{{ priceFormatting(pendingWithdrawal) }}</h3>
        </div>
        <div class="amount">
          <h6 class="sub-title">{{ $t('list.ab') }}</h6>
          <h3>{{ priceFormatting(withdrawalAmount) }}</h3>
        </div>
          <button
            class="amount outline-btn"
            :fetching-data="requesting"
            @click.prevent="makeWithdrawalRequest"
          >
            <span class="block sub-title mb-0">
              {{ $t('list.ma') }}
              {{ priceFormatting(withdraw.minAmount) }}
            </span>
            <span class="f-1-2">
              {{ $t('list.wth', {amount: priceFormatting(withdrawalAmount)}) }}
            </span>
          </button>
      </div>
    </div>
</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import FileUpload from "~/components/FileUpload"
  import Dropdown from "~/components/Dropdown"
  import Spinner from '~/components/Spinner'
  import Shimmer from '~/components/Shimmer'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import util from "~/mixin/util"

  export default {
    name: 'Withdrawal',
    data() {
      return {
        requesting: false,
        updating: false,
        loading: false,
        result: {}
      }
    },
    props: {},
    mixins: [util],
    components: {
      AjaxButton,
      FileUpload,
      Spinner,
      ErrorFormatter,
      Shimmer,
      Dropdown
    },
    computed: {
      withdrawnAmount() {
        return this.result?.withdrawn || 0
      },
      pendingWithdrawal() {
        return this.result?.pending_withdrawal || 0
      },
      withdrawalAmount() {
        return this.result?.amount || 0
      },


      currencyPosition() {
        return this.setting?.currency_position
      },
      withdrawalAccounts() {
        return this.result?.withdrawal_accounts || []
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      async fetchingData() {
        this.loading = true
        try {
          const data = await this.getRequest({params: {}, api: 'getWithdrawalRequest'})
          this.result = Object.assign({}, data)
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      async makeWithdrawalRequest(){
        this.loading = true
        try {
          const data = await this.setRequest({params: {}, api: 'setWithdrawalRequest'})
          if(data){
            this.result = Object.assign({}, data)
            this.$emit('withdraw-done')
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      ...mapActions('common', ['getRequest', 'setRequest'])
    },
    created() {

    },
    async mounted() {
      await this.fetchingData()
    }
  }
</script>

