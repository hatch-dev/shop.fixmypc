<template>
  <data-page
    v-if="$can('tax_rule', 'view')"
    ref="dataPage"
    set-api="setTaxRule"
    get-api="getTaxRule"
    route-name="tax-rules"
    empty-store-variable="allTaxRules"
    :name="$t('prod.tRule')"
    gate="tax_rule"
    :validation-keys="['title', 'price', 'type']"
    :result="result"
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

      <div class="dply-felx align-start j-left mlr--7-5 inputs d-block-sm">
        <div class="input-wrapper mlr-7-5">
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

        <div class="input-wrapper mlr-7-5">
          <div class="sided f-none-sm f-right">
            <span class="mr-15 text-nowrap">{{ $t('brand.pType') }}</span>

            <dropdown
              :selectedKey="`${result.type}`"
              :options="priceTypeObj"
              @clicked="dropdownSelected"/>
          </div>
        </div>
      </div>
    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage";
  import Dropdown from "~/components/Dropdown";
  import util from "~/mixin/util";

  export default {
    name: "tax-rule",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result:{
          id: '',
          title: '',
          price: '',
          type: 1,
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
      dropdownSelected(data) {
        this.result.type = data.key
      },
    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
