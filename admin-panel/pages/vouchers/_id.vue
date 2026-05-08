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

      <div class="input-wrapper">
        <label class="mr-10" for="">Voucher Type</label>
        <dropdown
          :selectedKey="`${result.apply_type}`"
          :options="applyTypeObj"
          @clicked="dropdownApplyType"
        />
      </div>

      <div
        class="input-wrapper"
        v-if="result.apply_type == 2"
      >
        <label>Select Products</label>
        <div class="dropdown-checkbox" ref="productDropdownWrapper">
           <div
            class="dropdown-head"
            @click.stop="productDropdown = !productDropdown"
          >
            {{
              result.product_ids.length
                ? result.product_ids.length + ' Products Selected'
                : 'Select Products'
            }}
          </div>
          <div
            class="dropdown-body"
            v-if="productDropdown"
          >
            <div
              class="checkbox-item"
              v-for="product in products"
              :key="product.id"
            >
              <label>
                <input
                  type="checkbox"
                  :value="product.id"
                  v-model="result.product_ids"
                >
                {{ product.title }}
              </label>
            </div>
          </div>
        </div>
        <span
          class="error"
          v-if="
            result.apply_type == 2 &&
            !result.product_ids.length &&
            hasError
          "
        >
          Please select products
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
  import Multiselect from 'vue-multiselect'
  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import util from '~/mixin/util'
  import datetime from 'vuejs-datetimepicker'
  import {mapActions, mapGetters} from 'vuex'

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        productDropdown: false,
        products: [],
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
          status: 2,
          apply_type: 1,
          product_ids: []
        },
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown,
      datetime,
      Multiselect
    },
    computed: {
      applyTypeObj() {
        return {
          1: {
            title: 'Global'
          },
          2: {
            title: 'Product Wise'
          }
        }
      },
      dateValidation() {
        return new Date(this.result.end_time) > new Date(this.result.start_time)
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      handleOutsideClick(event) {
        const dropdown =
          this.$refs.productDropdownWrapper
        if (
          dropdown &&
          !dropdown.contains(event.target)
        ) {
          this.productDropdown = false
        }
      },
      dropdownApplyType(data) {
        this.result.apply_type = Number(data.key)

        if (this.result.apply_type === 1) {
          this.result.product_ids = []
        }
      },
      dropdownPriceType(data) {
        this.result.type = data.key
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      async fetchProducts() {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/';
        const response = await this.$axios.get(`${baseUrl}api/admin/product/with-categories`);
        this.products = response.data.products || [];
      },
      ...mapActions('common', ['getRequest'])
    },
    async mounted() {
      await this.fetchProducts();
      document.addEventListener(
        'click',
        this.handleOutsideClick
      )
    },
    beforeDestroy() {
      document.removeEventListener(
        'click',
        this.handleOutsideClick
      )
    }
  }
</script>

<style>
.dropdown-checkbox {
  position: relative;
}

.dropdown-head {
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  padding: 12px 15px;
  cursor: pointer;
  background: var(--bg-color);
  min-height: 45px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  transition: .2s;
}

.dropdown-head:hover {
  border-color: var(--primary-color);
}

.dropdown-head.disabled {
  cursor: not-allowed;
  opacity: .75;
}

.dropdown-body {
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  background: var(--bg-color);
  max-height: 260px;
  overflow-y: auto;
  position: absolute;
  width: 100%;
  z-index: 100;
  margin-top: 5px;
  box-shadow: 0 8px 24px var(--shadow-color);
}

.checkbox-item {
  padding: 10px 15px;
  border-bottom: 1px solid var(--border-color);
}

.checkbox-item:last-child {
  border-bottom: none;
}

.checkbox-item label {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  width: 100%;
}

.checkbox-item:hover {
  background: var(--hover-color);
}

.checkbox-item input[type="checkbox"] {
  width: 16px;
  height: 16px;
}

.error {
  color: var(--danger-text);
  font-size: 12px;
  margin-top: 5px;
  display: block;
}
</style>
