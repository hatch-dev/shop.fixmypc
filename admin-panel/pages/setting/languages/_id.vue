<template>
  <data-page
    ref="dataPage"
    set-api="setLanguage"
    get-api="getLanguage"
    route-name="setting/languages"
    :name="$t('dataPage.lang')"
    :validation-keys="['name']"
    :result="result"
    gate="language"
    @result="result=$event"
    @on-success="onSuccess"
  >
    <template v-slot:form="{hasError}">

      <div
        class="input-wrapper single-line"
      >
        <label class="mb-0">
          {{ $t('profile.lang') }}
        </label>

        <dropdown
          :selected-key="result.code"
          :options="languageList"
          key-name="name"
          :searching="true"
          @clicked="selectedLanguage"
        />
      </div>


      <div class="input-wrapper dply-felx start gap-10">
        <label class="mb-0">{{ $t('setting.code') }}</label>
        <input
          type="text"
          :placeholder="$t('setting.code')"
          v-model="result.code"
          disabled
          :class="{invalid: !!!result.code && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.code && hasError"
        >
          {{ $t('category.req', { type: $t('setting.code')}) }}
        </span>
      </div>


      <div class="input-wrapper dply-felx start gap-10">
        <label class="mb-0">{{ $t('setting.dir') }}</label>
        <dropdown
          :selectedKey="`${result.direction}`"
          :options="directionObj"
          @clicked="directionSelected"
        />
      </div>

      <div class="input-wrapper dply-felx start gap-10">
        <label
          for="default"
          class="mb-0 dply-felx start gap-10">
          <span class="label">{{ $t('admin.default') }}</span>
          <input
            type="checkbox"
            id="default"
            v-model="result.default"
          />

        </label>
      </div>

      <div class="input-wrapper dply-felx start gap-10">
        <label class="mb-0">{{ $t('category.status') }}</label>
        <dropdown
          :selectedKey="`${result.status}`"
          :options="statusObj"
          @clicked="dropdownSelected"
        />
      </div>

    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import Dropdown from "~/components/Dropdown"
  import {mapGetters, mapActions} from 'vuex'

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

        directionObj: {
          'ltr': {title: 'LTR'},
          'rtl': {title: 'RTL'},

        },
        result: {
          id: '',
          name: 'English',
          code: 'en',
          direction: '',
          default: false,
          status: 2,
        }
      }
    },
    mixins: [util],
    components: {
      Dropdown,
      DataPage
    },
    computed: {
      ...mapGetters('resource', ['languageList']),
    },
    methods: {
      onSuccess(){
        setTimeout(() => {
          window.location.reload()
        }, 500)
      },
      selectedLanguage(data) {
        this.result.code = data.key
        this.result.name = data.value?.name
      },
      directionSelected(data) {
        this.result.direction = data.key
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      ...mapActions('resource', ['setLanguageList']),
      ...mapActions('common', ['getById']),
    },
    async mounted() {
      if (!this.languageList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'languages',
          api: 'resource'
        })

        this.setLanguageList(data)
        this.loading = false
      }

    }
  }
</script>
