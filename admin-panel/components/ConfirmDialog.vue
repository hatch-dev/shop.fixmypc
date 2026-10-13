<template>
  <div
    v-if="open"
    class="confirm-overlay"
    @click.self="$emit('cancel')"
    @keydown.esc.stop="$emit('cancel')"
    @keydown.tab="keepFocusInside"
  >
    <div
      ref="dialog"
      class="confirm-box"
      role="alertdialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      :aria-describedby="subjectId"
    >
      <!--
         A div, never a <header>. layouts/partials/Header.vue imports
         header.styl in an UNSCOPED style block, and it styles the bare
         element: `header { position: fixed; top: 0; left: 0; right: 0 }`.
         Using the semantic tag here tore the dialog in half - its heading
         was pinned across the top of the window as though it were the admin
         panel's own header bar.
      -->
      <div class="confirm-head">
        <div class="confirm-icon" :class="tone">
          <i :class="icon" aria-hidden="true"></i>
        </div>
        <div class="confirm-head__text">
          <h3 :id="titleId">{{ title }}</h3>
          <p :id="subjectId"><slot name="subject" /></p>
        </div>
        <button
          type="button"
          class="confirm-close"
          :title="cancelLabel"
          :aria-label="cancelLabel"
          :disabled="busy"
          @click="$emit('cancel')"
        ><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>

      <div v-if="hasBody" class="confirm-body">
        <slot />
      </div>

      <p v-if="warning" class="confirm-warning">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        {{ warning }}
      </p>

      <div class="confirm-actions">
        <button
          ref="cancelButton"
          type="button"
          class="confirm-btn confirm-btn--quiet"
          :disabled="busy"
          @click="$emit('cancel')"
        >{{ cancelLabel }}</button>
        <button
          type="button"
          class="confirm-btn"
          :class="tone === 'danger' ? 'confirm-btn--danger' : 'confirm-btn--primary'"
          :disabled="busy || confirmDisabled"
          @click="$emit('confirm')"
        >
          <i v-if="busy" class="fa fa-spinner fa-spin" aria-hidden="true"></i>
          <i v-else-if="confirmIcon" :class="confirmIcon" aria-hidden="true"></i>
          {{ busy ? busyLabel : confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
  /**
   * The one confirmation dialog.
   *
   * There were two hand-written copies of this - one on the category list and
   * one on the category form - and they had already drifted: different
   * headings, different scrims, different corner radii, one of them with a
   * grey "this cannot be undone" that read as the least important line in the
   * box. Neither took the keyboard with it, so Tab walked off into the page
   * underneath and Escape did nothing.
   *
   * The body is a slot, because what a delete affects differs per screen; the
   * shell, the wording of the actions and the behaviour are not that screen's
   * business and live here.
   */
  let dialogSeq = 0

  export default {
    name: 'ConfirmDialog',
    props: {
      open: {type: Boolean, default: false},
      title: {type: String, required: true},
      /** Shown in its own strip; the sentence the reader must not miss. */
      warning: {type: String, default: ''},
      /** 'danger' for a destructive act, anything else for an ordinary one. */
      tone: {type: String, default: 'danger'},
      icon: {type: String, default: 'fa-solid fa-triangle-exclamation'},
      confirmLabel: {type: String, default: 'Confirm'},
      confirmIcon: {type: String, default: ''},
      /** Replaces confirmLabel while `busy`. */
      busyLabel: {type: String, default: 'Working…'},
      cancelLabel: {type: String, default: 'Cancel'},
      /** The action is running: both buttons lock and the spinner shows. */
      busy: {type: Boolean, default: false},
      /** Not ready to confirm yet - still fetching what the act would affect. */
      confirmDisabled: {type: Boolean, default: false}
    },
    data() {
      dialogSeq += 1

      return {
        uid: dialogSeq,
        focusBeforeDialog: null
      }
    },
    computed: {
      titleId() { return `confirm-title-${this.uid}` },
      subjectId() { return `confirm-subject-${this.uid}` },
      hasBody() { return !!this.$slots.default }
    },
    watch: {
      /**
       * A modal dialog has to take the keyboard with it. Focus lands on
       * Cancel rather than on the destructive button, and goes back where it
       * came from on close.
       */
      open(isOpen) {
        if (typeof document === 'undefined') return

        if (isOpen) {
          this.focusBeforeDialog = document.activeElement
          document.body.style.overflow = 'hidden'

          this.$nextTick(() => {
            if (this.$refs.cancelButton) this.$refs.cancelButton.focus()
          })

          return
        }

        this.release()
      }
    },
    methods: {
      release() {
        if (typeof document === 'undefined') return

        document.body.style.overflow = ''

        if (this.focusBeforeDialog && this.focusBeforeDialog.focus) {
          this.focusBeforeDialog.focus()
        }

        this.focusBeforeDialog = null
      },
      /** Tab and Shift+Tab stay inside the dialog while it is open. */
      keepFocusInside(event) {
        const dialog = this.$refs.dialog

        if (!dialog) return

        const focusable = Array.prototype.filter.call(
          dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
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
    },
    beforeDestroy() {
      // Leaving the page with the dialog open would otherwise leave the whole
      // admin panel unable to scroll.
      this.release()
    }
  }
</script>

<style scoped>
.confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(17, 24, 39, .6);
  backdrop-filter: blur(3px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 20px;
  animation: confirm-fade .16s ease-out;
}

.confirm-box {
  background: #fff;
  border-radius: 18px;
  width: 100%;
  max-width: 460px;
  text-align: left;
  box-shadow: 0 24px 48px -12px rgba(17, 24, 39, .3), 0 0 0 1px rgba(17, 24, 39, .04);
  max-height: 90vh;
  overflow-y: auto;
  animation: confirm-rise .18s cubic-bezier(.2, .8, .3, 1);
}

@keyframes confirm-fade { from { opacity: 0; } }

@keyframes confirm-rise {
  from { opacity: 0; transform: translateY(10px) scale(.985); }
}

/* head: icon, title and subject on one line, with the way out top right */

.confirm-head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: start;
  gap: 14px;
  padding: 22px 22px 16px;
}

.confirm-head__text { min-width: 0; }

.confirm-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  flex: 0 0 auto;
  background: #EEF2FF;
  color: #33319a;
}

.confirm-icon.danger { background: #FEF2F2; color: #DC2626; }

.confirm-box h3 {
  font-size: 17px;
  font-weight: 700;
  color: #111827;
  margin: 2px 0 4px;
  line-height: 1.3;
}

.confirm-head__text p {
  font-size: 13px;
  color: #4B5563;
  line-height: 1.55;
  margin: 0;
  overflow-wrap: anywhere;
}

.confirm-head__text >>> strong { color: #111827; font-weight: 600; }

.confirm-close {
  width: 30px;
  height: 30px;
  padding: 0;
  border: 0;
  border-radius: 8px;
  background: transparent !important;
  color: #9CA3AF;
  font-size: 14px;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .15s ease, color .15s ease;
}

.confirm-close:hover:not(:disabled) { background: #F3F4F6 !important; color: #374151; }

/* The slot's own content is styled by the page that passes it - Vue scopes
   slot content to the parent, not to this component - so this only supplies
   the gutters. */

.confirm-body { padding: 0 22px; }

.confirm-warning {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 22px 18px;
  padding: 10px 12px;
  border-radius: 10px;
  background: #FEF2F2;
  border: 1px solid #FECACA;
  font-size: 13px;
  font-weight: 600;
  color: #B91C1C;
  line-height: 1.45;
}

.confirm-warning i { font-size: 12px; flex: 0 0 auto; }

/* footer: the actions on their own band, the destructive one last */

.confirm-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  flex-wrap: wrap;
  padding: 16px 22px;
  border-top: 1px solid #F1F3F5;
  background: #FCFCFD;
  border-radius: 0 0 18px 18px;
}

/* Height AND line-height on both: base.styl puts 50px/48px on every button
   in the panel, so a label sits off-centre otherwise. */

.confirm-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: auto;
  height: 40px;
  line-height: 38px;
  padding: 0 18px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
}

.confirm-btn:focus-visible {
  outline: 0;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .25);
}

.confirm-btn i { font-size: 12px; }

/*
 * Every background below is !important, and that is not laziness.
 *
 * base.styl carries
 *
 *   button:hover:not(.mfp-arrow, .mfp-close, .primary-btn, .lite-btn,
 *                    .nav-l, .nav-r, .delete-btn) { background: hover-color }
 *
 * and stylus emits that `:not()` list as a chain of seven separate `:not()`
 * pseudo-classes, each contributing a class to the score. The rule lands
 * around (0,8,1) - far above anything a component can reasonably write. It
 * was measured in the running panel, not counted: hovering Delete, the border
 * came from this file (#B91C1C) while the background came from base.styl
 * (#F3F4F6), so the red button turned grey behind its own white label and the
 * text disappeared.
 *
 * The only ways out are to claim one of the seven excluded class names, which
 * drag their own colours along, or this. The dialog this replaced used
 * `background: #DC2626 !important` for exactly the same reason; dropping it
 * during the rewrite is what brought the bug back.
 *
 * If a hover here ever looks grey again, this is why.
 */

.confirm-btn--quiet {
  border: 1px solid #E5E7EB;
  background: #fff !important;
  color: #374151;
}

.confirm-btn--quiet:hover:not(:disabled) {
  background: #F3F4F6 !important;
  border-color: #D1D5DB;
}

.confirm-btn--danger {
  border: 1px solid #DC2626;
  background: #DC2626 !important;
  color: #fff;
}

.confirm-btn--danger:hover:not(:disabled) {
  background: #B91C1C !important;
  border-color: #B91C1C;
}

.confirm-btn--danger:focus-visible { box-shadow: 0 0 0 3px rgba(220, 38, 38, .3); }

.confirm-btn--primary {
  border: 1px solid #33319a;
  background: #33319a !important;
  color: #fff;
}

.confirm-btn--primary:hover:not(:disabled) {
  background: #2a2880 !important;
  border-color: #2a2880;
}

/*
 * base.styl paints any button grey on hover:
 *
 *   button:hover:not(.mfp-arrow, .mfp-close, .primary-btn, .lite-btn,
 *                    .nav-l, .nav-r, .delete-btn) { background: hover-color }
 *
 * That exclusion list cannot know about this component, and - the part that
 * actually bit - it does not exclude *disabled* buttons. The hover rules
 * above are correctly written `:not(:disabled)`, so they stood aside while a
 * greyed-out Delete still took the global grey on hover and kept its white
 * label: the button read as blank.
 *
 * Stating the disabled colours explicitly is what settles it. Each of these
 * scores (0,4,0) once the scoped attribute is added, against the global
 * rule's (0,2,1).
 */
.confirm-btn:disabled { opacity: .55; cursor: not-allowed; }

.confirm-btn--danger:disabled,
.confirm-btn--danger:disabled:hover {
  background: #DC2626 !important;
  border-color: #DC2626;
  color: #fff;
}

.confirm-btn--primary:disabled,
.confirm-btn--primary:disabled:hover {
  background: #33319a !important;
  border-color: #33319a;
  color: #fff;
}

.confirm-btn--quiet:disabled,
.confirm-btn--quiet:disabled:hover {
  background: #fff !important;
  border-color: #E5E7EB;
  color: #374151;
}

.confirm-close:disabled,
.confirm-close:disabled:hover {
  background: transparent !important;
  color: #9CA3AF;
  cursor: not-allowed;
}

@media (max-width: 760px) {
  .confirm-box { border-radius: 16px; }
  .confirm-head { padding: 18px 18px 14px; gap: 12px; }
  .confirm-body { padding: 0 18px; }
  .confirm-warning { margin-left: 18px; margin-right: 18px; }
  .confirm-actions {
    flex-direction: column-reverse;
    padding: 14px 18px;
    border-radius: 0 0 16px 16px;
  }
  .confirm-btn { width: 100%; height: 44px; line-height: 42px; }
}

@media (prefers-reduced-motion: reduce) {
  .confirm-overlay,
  .confirm-box { animation: none; }
}
</style>
