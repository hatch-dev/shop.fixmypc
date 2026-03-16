<template>
  <div
    v-if="$can('header_link', 'view')"
    class="tab-sidebar"
  >
    <h4 class="title">{{ $t('dataPage.head') }}</h4>


    <div
      ref="formWrapper"
      class=""
    >


      <div class="form-wrapper">
        <error-formatter/>


        <p class="info-msg mb-15 mb-sm-15">
          {{ $t('setting.linkMsg') }}
        </p>

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


        <header-link
          v-if="result"
          :title="$t('util.left')"
          :result-data="result"
          @change="result = $event"
        />


        <header-link
          v-if="result"
          :title="$t('util.right')"
          type="right"
          :result-data="result"
          @change="result = $event"
        />



        <div
          v-if="!gate || $can(gate, 'edit') || $can(gate, 'create')"
          class="dply-felx j-right single-btn"
        >
          <ajax-button
            type="button"
            class="primary-btn"
            :fetching-data="formSubmitting"
            :text="$t('setting.sv')"
            @clicked="saveHeader"
          />
        </div>

      </div>
    </div>


  </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import Spinner from "~/components/Spinner"
  import HeaderLink from "~/components/partials/HeaderLink";
  import ErrorFormatter from "~/components/ErrorFormatter";
  import AjaxButton from "~/components/AjaxButton";

  export default {
    name: "header-links",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        gate: 'header_link',
        result: null,
        formSubmitting: false,
        loading: false,
      }
    },
    components: {
      AjaxButton,
      ErrorFormatter,
      HeaderLink,
      Spinner,
    },
    mixins: [],
    computed: {},
    methods: {
      async saveHeader(){

        this.formSubmitting = true
        try {

          const data = await this.setRequest({params: this.result, api: 'setHeaderLink'})
          this.result = Object.assign({}, data)


        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },

      async fetchingData() {
        this.loading = true
        try {
          const data  = await this.getRequest({params: {}, api: 'getHeaderLinks'})
          this.result = Object.assign({}, data)

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      ...mapActions('common', ['getRequest', 'setRequest'])
    },
    async mounted() {
     await this.fetchingData()
    }
  }
</script>
