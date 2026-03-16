<template>
  <data-page
    ref="dataPage"
    set-api="setFooterImageLink"
    get-api="getFooterImageLink"
    set-image-api="setFooterImageLinkImage"
    route-name="footer-links"
    hash="payment-social"
    :name="$t('dataPage.fImgs')"
    :validation-keys="['title', 'link']"
    :file-keys="['id', 'type']"
    :result="result"
    gate="footer_link"
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
        <label>{{ $t('admin.link') }}</label>
        <input
          type="text"
          :placeholder="$t('admin.link')"
          name="link"
          v-model="result.link"
          ref="link"
          :class="{invalid: !!!result.link && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.link && hasError"
        >
          {{ $t('category.req', { type: $t('admin.link')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label class="block">{{ $t('category.status') }}</label>
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

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          id: '',
          title: '',
          link: '',
          type: this.$route?.query?.type,
          status: 2,
          image: ''
        }
      }
    },
    mixins: [util],
    components: {
      Dropdown,
      DataPage
    },
    computed: {},
    methods: {
      dropdownSelected(data) {
        this.result.status = data.key
      }
    },
    async mounted() {
    }
  }
</script>
