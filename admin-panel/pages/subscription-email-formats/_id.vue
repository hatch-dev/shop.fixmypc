<template>
  <data-page
    ref="dataPage"
    set-api="setSubscriptionEmailFormat"
    get-api="getSubscriptionEmailFormat"
    route-name="subscription-email-formats"
    :name="$t('dataPage.sefUp')"
    :validation-keys="['title', 'subject', 'body']"
    :result="result"
    :emit-before-submit="true"
    gate="subscription_email_format"
    empty-store-variable="allSubscriptionEmailFormats"
    @result="result = $event"
    @before-submit="submitForm"
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

        <label>{{ $t('util.sub') }}</label>
        <input
          type="text"
          :placeholder="$t('util.sub')"
          name="subject"
          v-model="result.subject"
          :class="{invalid: !!!result.subject && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.subject && hasError"
        >
          {{ $t('category.req', { type: $t('util.sub')}) }}
        </span>
      </div>

      <!--<p
        v-if="viewAsHtml"
        class="info-msg mb-20 mb-sm-15"
      >
        Supported tags: h1, h2...h6, p, strong, em, u, s, blockquote, pre, ul, li, ol, br, a.
      </p>-->

      <div class="input-wrapper ">
        <label>
          <span class="mr-10">{{ $t('profile.body') }}</span>
          <button
            type="button"
            class="btn-clear"
            @click.prevent="viewHtml"
          >
            <b v-if="viewAsHtml">{{ $t('profile.vrh') }}</b>
            <b v-else>{{ $t('profile.vah') }}</b>
          </button>
        </label>

        <div
          v-if="!viewAsHtml"
          v-dompurify-html="result.body"
          class="textarea"
        />
        <textarea
          v-else
          :placeholder="$t('profile.ebah')"
          v-model="result.body"
          :class="{invalid: !!!result.body && hasError}"
        />
        <span
          class="error"
          v-if="!!!result.body && hasError"
        >
          {{ $t('category.req', { type: $t('profile.body')}) }}
        </span>
      </div>

    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import DOMPurify from 'dompurify'

  export default {
    name: "subscription-email-format",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        viewAsHtml: true,
        loading: false,
        result: {
          id: '',
          title: '',
          subject: '',
          body: ''
        }
      }
    },
    mixins: [util],
    components: {
      DataPage
    },
    computed: {
    },
    methods: {
      submitForm() {
        this.result.body = DOMPurify.sanitize(this.result.body)
        this.$refs.dataPage.checkForm()
      },
      viewHtml(){
        this.viewAsHtml = !this.viewAsHtml
      }
    },
    async mounted() {

    }
  }
</script>

<style scoped>

</style>
