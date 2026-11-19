<template>
  <div class="dialog-host">
    <div
      v-if="current"
      class="dlg-overlay"
      @click.self="answer(false)"
      @keydown.esc.stop="answer(false)"
      @keydown.tab="keepFocusInside"
    >
      <div
        ref="dialog"
        class="dlg-box"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="dlg-title"
        aria-describedby="dlg-message"
      >
        <div class="dlg-head">
          <div class="dlg-icon" :class="tone">
            <i :class="icon" aria-hidden="true"></i>
          </div>
          <div class="dlg-head__text">
            <h3 id="dlg-title">{{ title }}</h3>
            <p id="dlg-message">{{ current.message }}</p>
          </div>
          <button
            type="button"
            class="dlg-close"
            :title="cancelLabel"
            :aria-label="cancelLabel"
            @click="answer(false)"
          ><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>

        <form
          v-if="current.kind === 'prompt'"
          class="dlg-body"
          @submit.prevent="!promptBlocked && answer(true)"
        >
          <input
            ref="promptInput"
            v-model="promptValue"
            class="dlg-input"
            :type="current.options.inputType || 'text'"
            :placeholder="current.options.placeholder || ''"
            :aria-label="current.message"
          >
        </form>

        <div class="dlg-actions">
          <button
            v-if="current.kind !== 'alert'"
            ref="cancelButton"
            type="button"
            class="dlg-btn dlg-btn--quiet"
            @click="answer(false)"
          >{{ cancelLabel }}</button>
          <button
            ref="confirmButton"
            type="button"
            class="dlg-btn"
            :class="tone === 'danger' ? 'dlg-btn--danger' : 'dlg-btn--primary'"
            :disabled="promptBlocked"
            @click="answer(true)"
          >{{ confirmLabel }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
  /**
   * Renders whatever plugins/dialog.js is asking, one question at a time.
   *
   * Mounted once, on <body>, by the plugin - never placed in a page. Takes the
   * keyboard with it while open: focus starts on the safe answer (Cancel),
   * Tab stays inside, Escape and a click on the backdrop mean "no", and focus
   * goes back where it was when the dialog closes.
   */
  export default {
    name: 'DialogHost',
    props: {
      state: {type: Object, required: true}
    },
    data() {
      return {promptValue: '', focusBefore: null}
    },
    computed: {
      current() {
        return this.state.queue[0] || null
      },
      tone() {
        if (!this.current) return 'primary'
        return this.current.options.tone || (this.current.kind === 'confirm' ? 'danger' : 'primary')
      },
      icon() {
        if (!this.current) return ''
        if (this.current.options.icon) return this.current.options.icon
        if (this.current.kind === 'alert') return 'fa-solid fa-circle-info'
        return this.tone === 'danger' ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-question'
      },
      title() {
        if (!this.current) return ''
        return this.current.options.title ||
          (this.current.kind === 'alert' ? this.$t('dialog.noticeTitle') : this.$t('dialog.confirmTitle'))
      },
      confirmLabel() {
        if (!this.current) return ''
        return this.current.options.confirmLabel ||
          (this.current.kind === 'alert' ? this.$t('dialog.ok') : this.$t('dialog.confirm'))
      },
      cancelLabel() {
        return (this.current && this.current.options.cancelLabel) || this.$t('dialog.cancel')
      },
      promptBlocked() {
        return !!this.current && this.current.kind === 'prompt' &&
          !!this.current.options.required && !String(this.promptValue).trim()
      }
    },
    watch: {
      current(next, previous) {
        if (next && !previous) {
          this.focusBefore = document.activeElement
          document.body.style.overflow = 'hidden'
        }
        if (!next) {
          this.release()
          return
        }
        if (next.kind === 'prompt') {
          this.promptValue = next.options.value == null ? '' : String(next.options.value)
        }
        this.$nextTick(() => {
          const target = this.$refs.promptInput || this.$refs.cancelButton || this.$refs.confirmButton
          if (target) target.focus()
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
      },
      release() {
        document.body.style.overflow = ''
        if (this.focusBefore && this.focusBefore.focus && document.contains(this.focusBefore)) {
          this.focusBefore.focus()
        }
        this.focusBefore = null
      },
      keepFocusInside(event) {
        const dialog = this.$refs.dialog
        if (!dialog) return
        const focusable = Array.prototype.filter.call(
          dialog.querySelectorAll('button, input'),
          node => !node.disabled && node.offsetParent !== null
        )
        if (!focusable.length) return
        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first.focus()
        }
      }
    }
  }
</script>

<style scoped>
.dlg-overlay {
  position: fixed;
  inset: 0;
  background: rgba(17, 24, 39, .55);
  backdrop-filter: blur(3px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100000;
  padding: 16px;
  animation: dlg-fade .16s ease-out;
}
.dlg-box {
  background: #fff;
  border-radius: 18px;
  width: 100%;
  max-width: 440px;
  text-align: left;
  box-shadow: 0 24px 48px -12px rgba(17, 24, 39, .3), 0 0 0 1px rgba(17, 24, 39, .04);
  max-height: 90vh;
  overflow-y: auto;
  animation: dlg-rise .18s cubic-bezier(.2, .8, .3, 1);
}
@keyframes dlg-fade { from { opacity: 0; } }
@keyframes dlg-rise { from { opacity: 0; transform: translateY(10px) scale(.985); } }
.dlg-head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: start;
  gap: 14px;
  padding: 22px 22px 18px;
}
.dlg-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  background: #EEF2FF;
  color: var(--primary-color, #33319a);
}
.dlg-icon.danger { background: #FEF2F2; color: #DC2626; }
.dlg-head__text { min-width: 0; }
.dlg-head__text h3 {
  font-size: 17px;
  font-weight: 700;
  color: #111827;
  margin: 2px 0 4px;
  line-height: 1.3;
}
.dlg-head__text p {
  font-size: 14px;
  color: #4B5563;
  line-height: 1.55;
  margin: 0;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.dlg-close {
  width: 30px;
  height: 30px;
  padding: 0;
  border: 0;
  border-radius: 8px;
  background: transparent !important;
  color: #9CA3AF;
  font-size: 15px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
.dlg-close:hover { background: #F3F4F6 !important; color: #374151; }
.dlg-body { padding: 0 22px 18px; }
.dlg-input {
  width: 100%;
  height: 44px;
  padding: 0 12px;
  border: 1px solid #D1D5DB;
  border-radius: 10px;
  font-size: 14px;
}
.dlg-input:focus {
  outline: 0;
  border-color: var(--primary-color, #33319a);
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .18);
}
.dlg-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  flex-wrap: wrap;
  padding: 16px 22px;
  border-top: 1px solid #F1F3F5;
  background: #FCFCFD;
  border-radius: 0 0 18px 18px;
}
/* Backgrounds are !important: the storefront's base styles colour every
   button on hover, which would grey out the red one behind its label. */
.dlg-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 96px;
  height: 42px;
  padding: 0 18px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
}
.dlg-btn:focus-visible { outline: 0; box-shadow: 0 0 0 3px rgba(51, 49, 154, .25); }
.dlg-btn:disabled { opacity: .55; cursor: not-allowed; }
.dlg-btn--quiet { border: 1px solid #E5E7EB; background: #fff !important; color: #374151; }
.dlg-btn--quiet:hover { background: #F3F4F6 !important; }
.dlg-btn--danger { border: 1px solid #DC2626; background: #DC2626 !important; color: #fff; }
.dlg-btn--danger:hover:not(:disabled) { background: #B91C1C !important; border-color: #B91C1C; }
.dlg-btn--primary {
  border: 1px solid var(--primary-color, #33319a);
  background: var(--primary-color, #33319a) !important;
  color: #fff;
}
.dlg-btn--primary:hover:not(:disabled) { filter: brightness(.92); }
@media (max-width: 480px) {
  .dlg-actions { flex-direction: column-reverse; }
  .dlg-btn { width: 100%; }
}
</style>
