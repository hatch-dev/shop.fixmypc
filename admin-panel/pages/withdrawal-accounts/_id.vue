<template>
  <data-page
    ref="dataPage"
    set-api="setWithdrawalAccount"
    get-api="getWithdrawalAccount"
    route-name="withdrawal-accounts"
    :name="$t('user.wAcc')"
    :validation-keys="['title', 'account_number', 'account_name', 'bank_name', 'branch_name']"
    :result="result"
    gate="withdrawal_account"
    @result="result = $event"
  >

    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          v-model="result.title"
          :class="{invalid: !result.title && hasError}"
        >
        <span
          class="error"
          v-if="!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('admin.accNum') }}
        </label>
        <input
          type="text"
          placeholder="eg. 3454245344554"
          v-model="result.account_number"
          :class="{invalid: !result.account_number && hasError}"
        >
        <span
          class="error"
          v-if="!result.account_number && hasError"
        >
          {{ $t('category.req', { type: $t('admin.accNum')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('user.name') }}
        </label>
        <input
          type="text"
          :placeholder="$t('user.name')"
          v-model="result.account_name"
          :class="{invalid: !result.account_name && hasError}"
        >
        <span
          class="error"
          v-if="!result.account_name && hasError"
        >
          {{ $t('category.req', { type: $t('user.name')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('admin.bnkName') }}
        </label>
        <input
          type="text"
          :placeholder="$t('admin.bnkName')"
          v-model="result.bank_name"
          :class="{invalid: !result.bank_name && hasError}"
        >
        <span
          class="error"
          v-if="!result.bank_name && hasError"
        >
          {{ $t('category.req', { type: $t('admin.bnkName')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>
          {{ $t('admin.bName') }}
        </label>
        <input
          type="text"
          :placeholder="$t('admin.bName')"
          v-model="result.branch_name"
          :class="{invalid: !result.branch_name && hasError}"
        >
        <span
          class="error"
          v-if="!result.branch_name && hasError"
        >
          {{ $t('category.req', { type: $t('admin.bName')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <div class="dply-felx j-left mb-20 mb-sm-15" >
          <span class="mr-15">
            {{ $t('admin.default') }}
          </span>
          <dropdown
            :selectedKey="`${result.default}`"
            :options="featuredObj"
            @clicked="defaultSelected"
          />
        </div>
      </div>

    </template>
  </data-page>
</template>

<script>

  import Dropdown from "~/components/Dropdown"
  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"

  export default {
    name: "withdraw-request",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          id: '',
          title: '',
          account_number: '',
          account_name: '',
          branch_name: '',
          bank_name: '',
          default: 2
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown
    },
    computed: {},
    methods: {
      defaultSelected(data) {
        this.result.default = data?.key || 2
      },
    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
