<template>
  <div class="dialog-host">
    <confirm-dialog
      :open="!!current"
      :title="title"
      :tone="tone"
      :icon="icon"
      :warning="current ? current.options.warning || '' : ''"
      :confirm-label="confirmLabel"
      :cancel-label="current && current.options.cancelLabel || $t('dialog.cancel')"
      :hide-cancel="!!current && current.kind === 'alert'"
      :confirm-disabled="promptBlocked"
      @confirm="answer(true)"
      @cancel="answer(false)"
    >
      <template #subject>{{ current ? current.message : '' }}</template>
      <template v-if="current && current.kind === 'prompt'">
        <form class="dialog-prompt" @submit.prevent="!promptBlocked && answer(true)">
          <input
            ref="promptInput"
            v-model="promptValue"
            class="dialog-prompt__input"
            :type="current.options.inputType || 'text'"
            :placeholder="current.options.placeholder || ''"
            :aria-label="current.message"
          >
        </form>
      </template>
    </confirm-dialog>
  </div>
</template>

<script>
  import ConfirmDialog from '~/components/ConfirmDialog.vue'

  /**
   * Renders whatever plugins/dialog.js is asking, one question at a time.
   *
   * Mounted once, on <body>, by the plugin - never placed in a page - so every
   * screen asks through this.$dialog and gets the same dialog.
   */
  export default {
    name: 'DialogHost',
    components: {ConfirmDialog},
    props: {
      state: {type: Object, required: true}
    },
    data() {
      return {promptValue: ''}
    },
    computed: {
      current() {
        return this.state.queue[0] || null
      },
      danger() {
        return !!this.current && (this.current.options.tone || (this.current.kind === 'confirm' ? 'danger' : 'primary')) === 'danger'
      },
      tone() {
        return this.danger ? 'danger' : 'primary'
      },
      icon() {
        if (!this.current) return ''
        if (this.current.options.icon) return this.current.options.icon
        if (this.current.kind === 'alert') {
          return this.current.options.tone === 'danger' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-info'
        }
        return this.danger ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-question'
      },
      title() {
        if (!this.current) return ''
        if (this.current.options.title) return this.current.options.title
        return this.current.kind === 'alert' ? this.$t('dialog.noticeTitle') : this.$t('dialog.confirmTitle')
      },
      confirmLabel() {
        if (!this.current) return ''
        if (this.current.options.confirmLabel) return this.current.options.confirmLabel
        return this.current.kind === 'alert' ? this.$t('dialog.ok') : this.$t('dialog.confirm')
      },
      promptBlocked() {
        return !!this.current && this.current.kind === 'prompt' &&
          !!this.current.options.required && !String(this.promptValue).trim()
      }
    },
    watch: {
      current(next) {
        if (!next || next.kind !== 'prompt') return
        this.promptValue = next.options.value == null ? '' : String(next.options.value)
        this.$nextTick(() => {
          if (this.$refs.promptInput) this.$refs.promptInput.focus()
        })
      }
    },
    methods: {
      answer(accepted) {
        const asked = this.state.queue.shift()
        if (!asked) return
        if (asked.kind === 'prompt') {
          asked.resolve(accepted ? this.promptValue : null)
        } else {
          asked.resolve(asked.kind === 'alert' ? undefined : accepted)
        }
      }
    }
  }
</script>

<style scoped>
.dialog-prompt { padding-bottom: 16px; }
.dialog-prompt__input {
  width: 100%;
  height: 42px;
  padding: 0 12px;
  border: 1px solid #D1D5DB;
  border-radius: 10px;
  font-size: 14px;
  color: #111827;
  background: #fff;
}
.dialog-prompt__input:focus {
  outline: 0;
  border-color: #33319a;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .18);
}
</style>
