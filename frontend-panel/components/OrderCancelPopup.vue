<template>
  <pop-over
    :title="$t('orderCancelPopup.cancelOrder')"
    @close="$emit('close')"
    elem-id="cancel-order-pop-over"
    :layer="true"
    class="cancel-popup"
  >
    <template v-slot:content>
      <!--
        The form this modal is about to show, drawn at its own size. A popover
        is sized by its content, so a spinner in a 200px box made the panel
        open small and then resize under the pointer once the reasons landed.
      -->
      <div
        v-if="fetchingCancelledData"
        class="mn-h-200x"
      >
        <skeleton-base :height="12" width="90px" :mb="10" />
        <skeleton-base :height="44" width="100%" :radius="8" :mb="22" />
        <skeleton-base :height="12" width="110px" :mb="10" />
        <skeleton-base :height="96" width="100%" :radius="8" />
      </div>
      <div
        v-else
      >
        <div
          class="input-wrap"
          :class="{invalid: !title && hasFormError}"
        >
          <label>
            {{ $t('orderCancelPopup.title') }}
          </label>
          <input
            type="text"
            v-model="title"
            :placeholder="$t('contact.your', { type: $t('orderCancelPopup.titleSmall') })"
          >
          <span
            class="error"
            v-if="!title && hasFormError"
          >
              {{ $t('addressPopup.isRequired', {type: $t('orderCancelPopup.title') }) }}
            </span>
        </div>

        <div
          class="input-wrap mb-0"
          :class="{invalid: !cancellationMessage && hasFormError}"
        >
          <label>{{ $t('addressPopup.message') }}</label>
          <textarea
            v-model="cancellationMessage"
          />
          <span
            class="error"
            v-if="!cancellationMessage && hasFormError"
          >
              {{ $t('addressPopup.isRequired', {type: $t('addressPopup.message') }) }}
            </span>
        </div>
      </div>
    </template>

    <template v-slot:pop-footer>
      <div class="flex j-end gap-10">
        <button
          aria-label="close"
          class="outline-btn plr-30 plr-sm-15"
          @click.prevent="$emit('close')"
        >
          {{ $t('addressPopup.cancel') }}
        </button>
        <ajax-button
          class="primary-btn  plr-30 plr-sm-15"
          type="button"
          :fetching-data="submitting"
          :loading-text="$t('checkoutRight.submitting')"
          :text="$t('orderCancelPopup.SendMessage')"
          :disabled="fetchingCancelledData"
          @clicked="submitForm"
        />
      </div>
    </template>
  </pop-over>
</template>

<script>
  import util from '~/mixin/util'
  import validation from '~/mixin/validation'
  import PopOver from '~/components/PopOver'
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton"

  export default {
    name: 'OrderCancelPopup',
    data() {
      return {
        title: '',
        cancellationMessage: '',
        submitting: false,
        hasFormError: false,
        fetchingCancelledData: false
      }
    },
    watch: {},
    props: {
      orderId: {
        type: Number,
        required: true
      }
    },
    components: {
      AjaxButton,
      PopOver
    },
    computed: {
      ...mapGetters('language', ['langCode']),
    },
    mixins: [util, validation],
    methods: {
      serializing(obj) {
        this.title = obj.title
        this.cancellationMessage = obj.message
      },
      async submitForm() {
        if (this.title && this.cancellationMessage) {
          this.submitting = true
          const data = await this.cancelOrder({
            payload: {
              user_token: await this.getUserToken(),
              order_id: this.orderId,
              message: this.cancellationMessage,
              title: this.title,
            },
            lang: this.langCode
          })
          this.submitting = false
          if (data?.status === 200) {
            this.$emit('success')
            this.setToastMessage(data.message)

          } else if (data?.status === 204) {

            this.setToastError(data.message)

          } else {
            this.setToastError(data.data?.form.join(', '))
          }
        } else {
          this.hasFormError = true
        }
      },
      ...mapActions('user', ['getUserToken']),
      ...mapActions('common', ['setToastMessage', 'setToastError']),
      ...mapActions('order', ['cancelOrder', 'cancellationFind']),
    },
    created() {
    },
    async mounted() {
      this.fetchingCancelledData = true
      const data = await this.cancellationFind({
        payload: {
          id: this.orderId,
          user_token: await this.getUserToken(),
        },
        lang: this.langCode
      })
      if (data?.status === 200) {
        this.serializing(data.data)
      }
      this.fetchingCancelledData = false
    }
  }
</script>

