<template>
  <data-page
    v-if="allSubscriptionEmailFormats && $can('subscriber', 'view')"
    ref="dataPage"
    route-name="subscribers"
    :name="$t('dataPage.eFor')"
    gate="subscriber"
    :validation-keys="['subject']"
    :result="result"
    @result="result = $event"
  >
    <template v-slot:form="{hasError}">

      <div
        v-for="(item, index) in allSubscriptionEmailFormats"
        :key="index"
      >
        <label class="cp mtb-5">
          <input
            type="radio"
            :value="item.id"
            v-model="selectedFormat"
          >
          {{ item.title }}
        </label>
      </div>


      <div class="dply-felx j-right">
        <ajax-button
          class="primary-btn"
          type="button"
          text="Send Email"
          :fetching-data="sendingEmail"
          @clicked="sendSubscriptionEmail"
        />
      </div>

    </template>
  </data-page>
</template>
<script>

  import DataPage from '~/components/partials/DataPage'
  import util from '~/mixin/util'
  import Dropdown from '~/components/Dropdown'
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton"

  export default {
    name: "send-email",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        sendingEmail: false,
        selectedFormat: '',
        result:{
          format: ''
        }
      }
    },
    mixins: [util],
    components: {
      AjaxButton,
      DataPage,
      Dropdown
    },
    computed: {
      ...mapGetters('common', ['allSubscriptionEmailFormats'])
    },
    methods: {
      async sendSubscriptionEmail(id){
        this.sendingEmail = true
        try {
          const data = await this.setRequest({
            params: { id: this.selectedFormat},
            api: 'sendSubscriptionEmail'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.sendingEmail = false

      },
      ...mapActions('common', ['getRequest', 'setRequest', 'setAllSubscriptionEmailFormats'])
    },
    async mounted() {
      if(!this.allSubscriptionEmailFormats){
        try {
          const data = await this.getRequest({params: { }, api: 'getAllSubscriptionEmailFormats' })
          this.setAllSubscriptionEmailFormats(data)
          if(this.allSubscriptionEmailFormats.length){
            this.selectedFormat = this.allSubscriptionEmailFormats[0].id
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
      } else {
        if(this.allSubscriptionEmailFormats.length){
          this.selectedFormat = this.allSubscriptionEmailFormats[0].id
        }
      }
    }
  }
</script>

<style scoped>

</style>
