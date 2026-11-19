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
          {{ $t('setting.headerPlacementMsg') }}
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

        <!-- The Hot Deals and Clearance buttons of the storefront's navigation row -->
        <div v-if="result">
          <div class="dply-felx mb-15">
            <h4>{{ $t('setting.headerButtons') }}</h4>
          </div>
          <p class="info-msg mb-15 mb-sm-15">{{ $t('setting.headerButtonsMsg') }}</p>
          <div class="table-wrapper mb-20 mb-sm-15">
            <table class="mn-w-600x">
              <tr class="lite-bold">
                <th>{{ $t('setting.headerButton') }}</th>
                <th>{{ $t('index.title') }}</th>
                <th>{{ $t('admin.url') }}</th>
              </tr>
              <tr
                v-for="button in headerButtons"
                :key="button.key"
              >
                <td class="lite-bold">{{ button.label }}</td>
                <td>
                  <input
                    v-model="result[button.key].title"
                    type="text"
                    :placeholder="button.label"
                  >
                </td>
                <td>
                  <input
                    v-model="result[button.key].url"
                    type="text"
                    :placeholder="button.url"
                  >
                </td>
              </tr>
            </table>
          </div>
        </div>

        <p
          v-if="result"
          class="info-msg mb-15 mb-sm-15"
        >
          {{ $t('setting.headerContactMsg') }}
          <nuxt-link to="/setting/address">{{ $t('setting.headerContactLink') }}</nuxt-link>
        </p>



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
    computed: {
      // The storefront's own label and address, used while a field is empty
      headerButtons() {
        return [
          {key: 'hot_deals', label: this.$t('setting.hotDealsButton'), url: '/_main/products?badge=hot'},
          {key: 'clearance', label: this.$t('setting.clearanceButton'), url: '/clearance'}
        ]
      }
    },
    methods: {
      /** Each button as an editable {title, url}, empty when not set yet. */
      withButtons(data) {
        const result = Object.assign({}, data)
        for (const key of ['hot_deals', 'clearance']) {
          const saved = result[key] || {}
          result[key] = {title: saved.title || '', url: saved.url || ''}
        }
        return result
      },
      async saveHeader(){

        this.formSubmitting = true
        try {

          const data = await this.setRequest({params: this.result, api: 'setHeaderLink'})
          this.result = this.withButtons(data)


        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },

      async fetchingData() {
        this.loading = true
        try {
          const data  = await this.getRequest({params: {}, api: 'getHeaderLinks'})
          this.result = this.withButtons(data)

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
