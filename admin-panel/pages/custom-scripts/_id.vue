<template>
  <data-page
    ref="dataPage"
    set-api="setCustomScript"
    get-api="getCustomScript"
    route-name="custom-scripts"
    :name="$t('title.cs')"
    :validation-keys="['route_pattern']"
    :result="result"
    gate="header_link"
    @result="result = $event"
  >
    <template v-slot:form="{hasError}">

      <p class="info-msg mb-20 mb-sm-15" v-html="$t('title.up')"/>

      <div class="input-wrapper">
        <label>{{ $t('title.rParam') }}</label>
        <input
          type="text"
          :placeholder="$t('title.rParam')"
          name="title"
          v-model="result.route_pattern"
          :class="{invalid: !!!result.route_pattern && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.route_pattern && hasError"
        >
          {{ $t('category.req', { type: $t('title.rParam')}) }}
        </span>
      </div>



      <p class="info-msg mb-20 mb-sm-15" v-html="$t('title.wj')"/>

      <div class="input-wrapper dply-felx start">
        <label for="headerCode" class="mb-0">
          <input
            type="checkbox"
            id="headerCode"
            v-model="result.header_script"
            @change="cbHeaderChanged"
          />
          {{ $t('title.hsc') }}
        </label>
        <button
          type="button"
          class="toggle-arrow"
          @click="showHeaderForm = !showHeaderForm"
        >
          <i class="icon black arrow-down"/>
        </button>
      </div>

      <div v-if="showHeaderForm" class="input-wrapper">
        <label>{{ $t('title.hsc') }}</label>

        <textarea
          :placeholder="$t('title.hsc')"
          v-model="result.header_script_code"
        />
      </div>



      <div class="input-wrapper dply-felx start">
        <label for="bodyCode" class="mb-0">
          <input
            type="checkbox"
            id="bodyCode"
            v-model="result.body_script"
            @change="cbBodyChanged"
          />
          {{ $t('title.bs') }}
        </label>
        <button
          type="button"
          class="toggle-arrow"
          @click="showBodyForm = !showBodyForm"
        >
          <i class="icon black arrow-down"/>
        </button>
      </div>

      <div v-if="showBodyForm" class="input-wrapper">
        <label>{{ $t('title.bsc') }}</label>

        <textarea
          :placeholder="$t('title.bsc')"
          v-model="result.body_script_code"
        />
      </div>



      <div class="input-wrapper">
        <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{ $t('category.status') }}
          </span>

          <dropdown
            :selectedKey="`${result.status}`"
            :options="statusObj"
            @clicked="statusSelected"
          />
        </div>
      </div>

    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import Dropdown from "~/components/Dropdown"
  import SliderFormTab from "~/components/partials/SliderFormTab"
  import TagSearch from "~/components/TagSearch"
  import {mapGetters, mapActions} from 'vuex'
  import Spinner from "~/components/Spinner"
  import SourceTypeProducts from "~/components/partials/SourceTypeProducts"
  import ProductSource from "~/components/partials/ProductSource"

  export default {
    name: "banner",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        showHeaderForm: false,
        showBodyForm: false,
        result: {
          id: '',
          route_pattern: '',
          header_script: '',
          header_script_code: '',
          body_script: '',
          body_script_code: '',
          status: '',
        }
      }
    },
    mixins: [util],
    components: {
      ProductSource,
      SourceTypeProducts,
      Spinner,
      TagSearch,
      SliderFormTab,
      Dropdown,
      DataPage
    },
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('setting', ['setting']),
      ...mapGetters('common', ['allCategories', 'allSubCategories', 'allBrands'])
    },
    methods: {
      cbHeaderChanged(evt) {
        this.showHeaderForm = evt.target.checked
      },
      cbBodyChanged(evt) {
        this.showHeaderForm = evt.target.checked
      },
      statusSelected(data) {
        this.result.status = data.key
      },

      ...mapActions('common', ['getDropdownList'])
    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
