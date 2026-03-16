<template>
  <div
    v-if="$can('message', 'view')"
    class="detail-width"
  >
    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
      />
    </div>

    <div
      v-if="result"
      class="order-wrapper tab-sidebar"
    >
      <div
        class="title"
      >
       <div
         class="dply-felx d-block-sm"
       >
         <h4
          class="mb-sm-10"
         >
           {{ $t('profile.um') }}
         </h4>
         <div
           class="dply-felx j-left f-wrap mlr--5 mtb-sm--5"
         >
           <dropdown
             class="mlr-5 mtb-sm-5"
             :selected-key="result.replied"
             :options="messageReply"
             @clicked="dropdownSelected"
           />
           <ajax-button
             name="save-edit"
             class="primary-btn mlr-5 mtb-sm-5"
             :text="$t('profile.us')"
             :loading-text="$t('profile.updatn')"
             :fetching-data="formSubmitting"
             type="button"
             @clicked="updateSReplied"
           />
         </div>
       </div>
      </div>

      <form class="form-wrapper">
        <div class="dply-felx d-block-sm">
          <div class="input-wrapper">
            <label>{{ $t('user.name') }}</label>
            <p>{{ result.name }}</p>
          </div>

          <div class="input-wrapper">
            <label>{{ $t('fSale.email') }}</label>
            <p>{{ result.email }}</p>
          </div>
        </div>

        <div class="input-wrapper">
          <label>{{ $t('util.sub') }}</label>
          <p>{{ result.subject }}</p>
        </div>

        <div class="input-wrapper">
          <label> {{ $t('user.msg') }}</label>
          <p>{{ result.message }}</p>
        </div>

      </form>
    </div>
  </div>
</template>

<script>

  import util from "~/mixin/util"
  import AjaxButton from "~/components/AjaxButton"
  import Spinner from "~/components/Spinner"
  import Dropdown from "~/components/Dropdown"
  import {mapActions} from 'vuex'
  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        formSubmitting: false,
        statusChanged: false,
        loading: false,
        result: {
          id: '',
          name: '',
          email: '',
          subject: '',
          replied: ''
        }
      }
    },
    mixins: [util],
    components: {
      Dropdown,
      Spinner,
      AjaxButton
    },
    computed: {
      id() {
        return this.$route?.params?.id
      },
    },
    methods: {
      async updateSReplied() {
        if(!this.statusChanged){
          return false
        }
        this.statusChanged = false
        this.formSubmitting = true
        try {
          await this.setById({id: this.id, params: this.result, api: 'setUserMessage'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.formSubmitting = false
      },
      dropdownSelected(data) {
        this.statusChanged = true
        this.result.replied = data.key
      },
      async fetchingData() {
        try {
          this.loading = true
          this.result= Object.assign({}, await this.getById({id: this.id, params: {}, api: 'getUserMessage'}))
          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      ...mapActions('common', ['getById', 'setById'])
    },
    async mounted() {
      if(this.$can('message', 'view')){
        await this.fetchingData()
      }
    }
  }
</script>

<style scoped>

</style>
