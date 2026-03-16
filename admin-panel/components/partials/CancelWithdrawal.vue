<template>
  <pop-over
    :title="$t('setting.cr')"
    @close="$emit('close')"
    elem-id="withdraw-cancellation-pop-over"
    :layer="true"
    class="rating-popup popup-top-auto"
  >
    <template v-slot:content>
      <error-formatter
      />

      <form
        :class="{'has-error': hasError}"
      >
        <div class="input-wrapper mb-0">
          <label>
            {{ $t('user.msg') }}
          </label>
          <textarea
            :class="{invalid: !message && hasError}"
            v-model="message"
          />
          <span
            class="error"
            v-if="!message && hasError"
          >
          {{ $t('category.req', { type: $t('user.msg')}) }}
        </span>
        </div>
      </form>

    </template>
    <template
      v-slot:pop-footer
    >
      <div class="dply-felx j-right">
        <ajax-button
          type="button"
          :text="$t('setting.cw')"
          :loading-text="$t('setting.cncl')"
          :fetching-data="cancelling"
          class="primary-btn"
          @clicked="cancelWithdrawal"
        />
      </div>

    </template>
  </pop-over>

</template>

<script>

  import Spinner from "~/components/Spinner";
  import PopOver from "~/components/PopOver";
  import {mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton";
  import ErrorFormatter from "../ErrorFormatter";

  export default {
    name: 'CancelWithdrawal',
    data() {
      return {
        cancelling: false,
        hasError: false,
        message: ''
      }
    },
    props: {
      id: {
        type: String
      },
    },
    mixins: [],
    components: {ErrorFormatter, AjaxButton, Spinner, PopOver},
    computed: {
    },
    methods: {
      async cancelWithdrawal(){
        this.hasError = false
        if(this.message){
          try {
            this.cancelling = true
            const data = await this.setRequest({
              params: {
                id: this.id,
                message: this.message,
              },
              api: 'cancelWithdrawalRequest'
            })
            if(data){
              this.$emit('close', { reload: true })
            }
            this.cancelling = false
          }catch (e) {
            return this.$nuxt.error(e)
          }
        } else {
          this.hasError = true
        }
      },
      ...mapActions('common', ['setRequest'])
    },
    mounted() {
    }
  }
</script>

