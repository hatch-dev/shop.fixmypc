<template>
  <data-page
    ref="dataPage"
    set-api="setVoucher"
    get-api="getVoucher"
    route-name="vouchers"
    :name="$t('fSale.voucher')"
    :validation-keys="['title']"
    :result="result"
    gate="voucher"
    @result="result = $event"
  >

    <template v-slot:form="{hasError}">

      <div class="input-wrapper">

        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          name="title"
          v-model="result.title"
          ref="title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

      <div class="dply-felx align-start j-left inputs d-block-sm">
        <div class="input-wrapper">
          <div class="flex-v-centered">
            <span class="mr-15">{{ $t('brand.price') }}</span>
            <div>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.price')"
                v-model="result.price"
                :class="{invalid: !!!result.price && hasError}"
              >
              <span
                class="error"
                v-if="!!!result.price && hasError"
              >
                {{ $t('category.req', { type: $t('brand.price')}) }}
              </span>
            </div>

          </div>
        </div>

        <div class="input-wrapper ">
          <div class="sided f-none-sm f-right">
            <span class="mr-15 text-nowrap">{{ $t('prod.priType') }}</span>

            <dropdown
              :selectedKey="`${result.type}`"
              :options="priceTypeObj"
              @clicked="dropdownPriceType"
            />
          </div>
        </div>
      </div>

      <div class="dply-felx align-start j-left inputs  d-block-sm">
        <div class="input-wrapper ">
          <div class="flex-v-centered">
            <span class="mr-15">{{ $t('prod.capped') }}({{ currencyIcon }})</span>
            <div>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.price')"
                v-model="result.capped_price"
              >
            </div>

          </div>
        </div>

        <div class="input-wrapper ">
          <div class="flex-v-centered">
            <span class="mr-15">{{ $t('prod.spent') }}({{ currencyIcon }})</span>
            <div>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.price')"
                v-model="result.min_spend"
                :class="{invalid: !!!result.min_spend && hasError}"
              >
              <span
                class="error"
                v-if="!!!result.min_spend && hasError"
              >
                {{ $t('category.req', { type: $t('prod.spent')}) }}
              </span>
            </div>

          </div>
        </div>
      </div>

      <div class="dply-felx align-start j-left inputs  d-block-sm">
        <div class="input-wrapper ">
          <div class="flex-v-centered">
            <span class="mr-15">{{ $t('prod.usage') }}</span>
            <div>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.price')"
                v-model="result.usage_limit"
                :class="{invalid: !!!result.usage_limit && hasError}"
              >
              <span
                class="error"
                v-if="!!!result.usage_limit && hasError"
              >
                {{ $t('category.req', { type: $t('prod.usage')}) }}
              </span>
            </div>

          </div>
        </div>

        <div class="input-wrapper ">
          <div class="flex-v-centered">
            <span class="mr-15">{{ $t('prod.limit') }}</span>
            <div>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.price')"
                v-model="result.limit_per_customer"
                :class="{invalid: !!!result.limit_per_customer && hasError}"
              >
              <span
                class="error"
                v-if="!!!result.limit_per_customer && hasError"
              >
                {{ $t('category.req', { type: $t('prod.limit')}) }}
              </span>
            </div>

          </div>
        </div>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('prod.code') }}</label>
        <input
          type="text"
          :placeholder="$t('prod.code')"
          v-model="result.code"
          :class="{invalid: !!!result.code && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.code && hasError"
        >
          {{ $t('category.req', { type: $t('prod.code')}) }}
        </span>
      </div>

      <div
        class="dply-felx align-start j-left inputs d-block-sm"
      >
        <div class="input-wrapper mlr-7-5">
          <div

            :class="{'red-border': !!!result.start_time && hasError}"
            class="flex-v-centered no-border">
            <span
              class="mr-15"
            >
              {{ $t('prod.sTime') }}
            </span>
            <datetime
              class="form-bottom"
              format="YYYY-MM-DD H:i:s"
              width="300px"
              v-model="result.start_time"
              readonly
            />
          </div>

          <span
            class="error"
            v-if="!!!result.start_time && hasError"
          >
              {{ $t('category.req', { type: $t('prod.sTime')}) }}
            </span>
        </div>

        <div class="input-wrapper mlr-7-5">
          <div
            :class="{'red-border': (!!!result.end_time && hasError) || (!dateValidation && hasError)}"
            class="flex-v-centered no-border">
            <span class="mr-15">{{ $t('prod.eTime') }}</span>
            <datetime
              class="form-bottom"
              format="YYYY-MM-DD H:i:s"
              width="300px"
              v-model="result.end_time"
              readonly
            />
          </div>

          <span
            class="error"
            v-if="!!!result.end_time && hasError"
          >
              {{ $t('category.req', { type: $t('prod.eTime')}) }}
            </span>

          <span
            class="error"
            v-else-if="!dateValidation && hasError"
          >
            {{ $t('prod.greater') }}
          </span>

        </div>
      </div>

      <div
        class="input-wrapper"
      >
        <div
          class="dply-felx j-left mb-20 mb-sm-15"
        >
          <span
            class="mr-15"
          >
             {{ $t('category.status') }}
          </span>

          <dropdown
            :selectedKey="`${result.status}`"
            :options="statusObj"
            @clicked="dropdownSelected"
          />
        </div>
      </div>

    </template>
  </data-page>
</template>

<script>

  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import util from '~/mixin/util'
  import datetime from 'vuejs-datetimepicker'
  import {mapGetters} from 'vuex'

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          id: '',
          title: '',
          capped_price: '',
          limit_per_customer: '',
          usage_limit: '',
          min_spend: '',
          code: '',
          start_time: '',
          end_time: '',
          type: 1,
          status: 2
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown,
      datetime
    },
    computed: {
      dateValidation() {
        return new Date(this.result.end_time) > new Date(this.result.start_time)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      dropdownPriceType(data) {
        this.result.type = data.key
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
