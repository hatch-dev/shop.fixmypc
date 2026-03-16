<template>
  <setting-layout
    active-route="currency"
    class="mb-5"
  >
    <template v-slot:rightArea>

      <form
        :class="{'has-error': hasError}"
        @submit.prevent="updateCurrency"
      >

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

        <div class="input-wrapper single-line">
          <label>
            {{ $t('setting.cur') }}
          </label>

          <dropdown
            v-if="currency"
            :selected-key="currency"
            :options="currencyList"
            key-name="name"
            :searching="true"
            @clicked="selectedCurrency"
          />
        </div>


        <div class="input-wrapper single-line">
          <label>
            {{ $t('setting.curPos') }}
          </label>

          <dropdown
            :selectedKey="`${currencyPosition}`"
            :options="currencyPositions"
            @clicked="selectedCurrencyPosition"
          />
        </div>


        <div class="input-wrapper single-line">
          <label>
            {{ $t('dataPage.df') }}
          </label>

          <dropdown
            v-if="decimalFormat"
            :selected-key="decimalFormat"
            :options="decimalFormatList"
            key-name="name"
            :searching="true"
            @clicked="selectedDecimalFormat"
          />

        </div>



        <ajax-button
          v-if="$can('setting', 'edit')"
          class="primary-btn"
          :text="$t('dataPage.uc')"
          :fetching-data="updatingCurrency"
        />
      </form>
    </template>
  </setting-layout>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import SettingLayout from "~/components/partials/SettingLayout";
  import AjaxButton from "~/components/AjaxButton";
  import Dropdown from "~/components/Dropdown";
  import util from "~/mixin/util";
  import Spinner from "~/components/Spinner";
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        updatedCurrency: null,
        decimalFormat: 'en-US',
        currencyPosition: 1,
        loading: false,
        updatingCurrency: false,
        hasError: false,
      }
    },
    props: {},
    mixins: [util],
    components: {
      Spinner,
      Dropdown,
      AjaxButton,
      SettingLayout

    },
    computed: {
      currency() {
        return this.setting?.currency || 'USD'
      },
      ...mapGetters('resource', ['currencyList', 'decimalFormatList']),
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      selectedDecimalFormat(data) {
        this.decimalFormat = data.key
      },

      selectedCurrencyPosition(data) {
        this.currencyPosition = data.key
      },
      selectedCurrency(data) {
        this.updatedCurrency = data.key
        this.currencyIcon = data.value.symbolNative
      },
      async updateCurrency() {
        if (this.updatedCurrency && this.currencyIcon && this.currencyPosition) {
          this.updatingCurrency = true
          try {

            await this.setCurrency({
              currency: this.updatedCurrency || this.currency,
              currency_icon: this.currencyIcon,
              decimal_format: this.decimalFormat,
              currency_position: this.currencyPosition
            })

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.updatingCurrency = false
        }
      },
      ...mapActions('common', ['getById']),
      ...mapActions('resource', ['setCurrencyList', 'setDecimalFormatList']),
      ...mapActions('setting', ['setCurrency'])
    },
    created() {
    },
    async mounted() {
      if (!this.currencyList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'currencies',
          api: 'resource'
        })

        this.setCurrencyList(data)
        this.loading = false
      }

      if (!this.decimalFormatList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'decimalFormats',
          api: 'resource'
        })

        this.setDecimalFormatList(data)
        this.loading = false
      }

      this.updatedCurrency = this.setting?.currency
      this.currencyIcon = this.setting?.currency_icon
      this.currencyPosition = this.setting?.currency_position

      if(this.setting?.decimal_format) {
        this.decimalFormat = this.setting?.decimal_format
      }

    }
  }
</script>


