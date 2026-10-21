<template>
  <span class="ip" :class="`ip-${variant}`">
    <button
      ref="btn"
      type="button"
      class="ip-btn"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="open ? key : null"
      :aria-label="label || title"
      aria-haspopup="dialog"
      @click.stop.prevent="toggle"
    >i</button>

    <template v-if="open">
      <!--
        The sheet needs something to dim and to catch a tap behind it. The
        desktop popover has no scrim: it is a light disclosure, not a modal,
        and the page behind it stays readable while it is open.
      -->
      <div v-if="sheet" class="ip-scrim" @click="close(false)"></div>

      <div
        :id="key"
        ref="panel"
        class="ip-panel"
        :class="[`is-${placement}`, { 'is-sheet': sheet, 'is-ready': ready }]"
        :style="panelStyle"
        role="dialog"
        :aria-label="title"
        tabindex="-1"
        @click.stop
      >
        <span v-if="!sheet" class="ip-arrow" :style="{ left: `${arrowLeft}px` }"></span>

        <div class="ip-head">
          <h3 class="ip-title">{{ title }}</h3>
          <button type="button" class="ip-close" :aria-label="$t('ui.infoPopoverUi.aria.close')" @click.stop="close(true)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>

        <p v-if="lede" class="ip-lede">{{ lede }}</p>

        <!--
          The per-product working first, for a figure that is a sum of several
          savings on different VAT bases and cannot be checked by eye — then
          the rows, so a total in `rows` lands underneath what makes it up.
        -->
        <div v-if="groups.length" class="ip-groups">
          <div v-for="(group, i) in groups" :key="`ip-group-${i}`" class="ip-group">
            <p class="ip-group-name">{{ group.name }}</p>
            <p v-for="(line, j) in group.lines" :key="`ip-line-${j}`" class="ip-group-line">{{ line }}</p>
            <p class="ip-group-total">{{ group.total }}</p>
          </div>
        </div>

        <!-- the figures behind the number the customer is looking at -->
        <dl v-if="rows.length" class="ip-rows">
          <div
            v-for="(row, i) in rows"
            :key="`ip-row-${i}`"
            class="ip-row"
            :class="{ 'is-strong': row.strong }"
          >
            <dt>{{ row.label }}</dt>
            <dd>{{ row.value }}</dd>
          </div>
        </dl>

        <p v-if="note" class="ip-note">{{ note }}</p>
      </div>
    </template>
  </span>
</template>

<script>
import Vue from 'vue'

/*
 * One open popover per page.
 *
 * Every instance reads the same observable, so opening one closes whichever
 * was open without any of the parents having to know about each other — the
 * basket line and the order summary never talk, and both hold these icons.
 */
const openState = Vue.observable({ id: null })

let uid = 0

// Below this the popover becomes a bottom sheet: there is no room beside an
// icon on a phone, and a 280px panel anchored to one would be clamped to the
// screen edge anyway.
const SHEET_MAX = 560

export default {
  name: 'InfoPopover',
  props: {
    // The heading. Also the panel's accessible name.
    title: { type: String, required: true },
    // One sentence saying what the figure is, in the customer's terms.
    lede: { type: String, default: '' },
    // label / value pairs: the calculation behind the figure.
    rows: { type: Array, default: () => [] },
    // { name, lines: [], total } per product, for a summed figure.
    groups: { type: Array, default: () => [] },
    // A closing caveat, where one is needed.
    note: { type: String, default: '' },
    // What the trigger announces; falls back to the title.
    label: { type: String, default: '' },
    // 'badge' takes its colour from the badge around it, 'summary' is grey.
    variant: { type: String, default: 'summary' },
  },
  data() {
    return {
      key: `info-popover-${++uid}`,
      placement: 'bottom',
      panelStyle: {},
      arrowLeft: 24,
      sheet: false,
      ready: false,
    }
  },
  computed: {
    open() {
      return openState.id === this.key
    },
  },
  watch: {
    open(isOpen) {
      if (isOpen) {
        this.bind()
        // the panel has to exist before it can be measured
        this.$nextTick(() => {
          this.position()
          this.ready = true
          const panel = this.$refs.panel
          if (panel) panel.focus()
        })
      } else {
        this.unbind()
        this.ready = false
      }
    },
  },
  methods: {
    toggle() {
      if (this.open) {
        this.close(true)
      } else {
        // assigning the shared id is what closes any other open panel
        openState.id = this.key
      }
    },

    close(refocus) {
      if (!this.open) return

      openState.id = null

      if (refocus) {
        this.$nextTick(() => {
          const btn = this.$refs.btn
          if (btn) btn.focus()
        })
      }
    },

    onDocPointer(e) {
      if (this.$el && this.$el.contains(e.target)) return
      this.close(false)
    },

    onKeydown(e) {
      if (e.key === 'Escape' || e.key === 'Esc') {
        e.stopPropagation()
        this.close(true)
      }
    },

    onReflow() {
      if (this.frame) return
      this.frame = window.requestAnimationFrame(() => {
        this.frame = null
        this.position()
      })
    },

    bind() {
      // pointerdown rather than click: a press that starts outside should
      // dismiss, and click alone lets a drag-select re-open the same panel
      document.addEventListener('pointerdown', this.onDocPointer, true)
      document.addEventListener('keydown', this.onKeydown, true)
      window.addEventListener('resize', this.onReflow, { passive: true })
      // capture, so it follows the page and any scrolling ancestor
      window.addEventListener('scroll', this.onReflow, { passive: true, capture: true })
    },

    unbind() {
      document.removeEventListener('pointerdown', this.onDocPointer, true)
      document.removeEventListener('keydown', this.onKeydown, true)
      window.removeEventListener('resize', this.onReflow)
      window.removeEventListener('scroll', this.onReflow, true)

      if (this.frame) {
        window.cancelAnimationFrame(this.frame)
        this.frame = null
      }
    },

    /*
     * Fixed to the viewport rather than positioned inside the card.
     *
     * The basket line sets overflow:hidden and the summary is sticky, so a
     * panel in normal flow would either be clipped or would push the card
     * taller the moment it opened. Fixed keeps it out of the layout entirely —
     * nothing on the page moves when it appears.
     */
    position() {
      const btn = this.$refs.btn
      const panel = this.$refs.panel

      if (!btn || !panel) return

      const vw = document.documentElement.clientWidth
      const vh = document.documentElement.clientHeight

      if (vw <= SHEET_MAX) {
        this.sheet = true
        this.panelStyle = {}
        return
      }

      this.sheet = false

      const rect = btn.getBoundingClientRect()
      const pw = panel.offsetWidth
      const ph = panel.offsetHeight
      const gap = 9
      const edge = 12

      // below the icon by default, above it when the bottom of the window is
      // closer than the panel is tall
      let top = rect.bottom + gap
      let placement = 'bottom'

      if (top + ph > vh - edge && rect.top - gap - ph > edge) {
        top = rect.top - gap - ph
        placement = 'top'
      }

      const centre = rect.left + rect.width / 2
      const left = Math.max(edge, Math.min(centre - pw / 2, vw - pw - edge))

      // the arrow stays under the icon even once the panel has been clamped
      this.arrowLeft = Math.round(Math.max(14, Math.min(centre - left, pw - 14)))
      this.placement = placement
      this.panelStyle = { top: `${Math.round(top)}px`, left: `${Math.round(left)}px` }
    },
  },
  beforeDestroy() {
    this.unbind()
    // a panel left open in the shared slot would block every other icon
    if (this.open) openState.id = null
  },
}
</script>

<style scoped>
/* ===========================================================================
   Contextual info popover.

   Opens on click, one at a time, anchored to the icon it belongs to. Nothing
   it renders takes part in the page's layout: the panel is fixed, so a card
   is exactly as tall open as it is shut.
   =========================================================================== */
.ip {
  display: inline-flex;
  align-items: center;
  vertical-align: middle;
}

.ip-summary {
  margin-left: 5px;
  vertical-align: 1px;
}

/* --- trigger -------------------------------------------------------------- */
.ip-btn {
  display: inline-grid;
  place-items: center;
  width: 14px;
  height: 14px;
  padding: 0;
  border: 1.3px solid currentColor;
  border-radius: 50%;
  background: none;
  font-family: inherit;
  font-size: 9.5px;
  font-weight: 700;
  font-style: normal;
  line-height: 1;
  cursor: pointer;
  transition: color .15s, border-color .15s, background .15s;
}

/* takes the badge's own colour, so one rule serves both tints */
.ip-badge .ip-btn {
  color: inherit;
  opacity: .8;
}

.ip-badge .ip-btn:hover,
.ip-badge .ip-btn[aria-expanded="true"] {
  opacity: 1;
}

.ip-summary .ip-btn {
  border-color: #8C9AB0;
  color: #6B7C93;
}

.ip-summary .ip-btn:hover,
.ip-summary .ip-btn[aria-expanded="true"] {
  border-color: #1A56DB;
  color: #1A56DB;
}

.ip-btn:focus-visible {
  outline: 2px solid rgba(26, 86, 219, .5);
  outline-offset: 2px;
}

/* --- panel ---------------------------------------------------------------- */
.ip-panel {
  position: fixed;
  z-index: 2000;
  width: 284px;
  max-width: calc(100vw - 24px);
  padding: 11px 12px 12px;
  border: 1px solid #E3E8F0;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 10px 28px rgba(15, 37, 71, .14), 0 2px 6px rgba(15, 37, 71, .07);
  text-align: left;
  font-size: 12.5px;
  line-height: 1.45;
  color: #0F2547;
  /* measured before it is placed; showing it first would flash at 0,0 */
  visibility: hidden;
}

.ip-panel.is-ready {
  visibility: visible;
}

.ip-panel:focus {
  outline: none;
}

.ip-arrow {
  position: absolute;
  width: 10px;
  height: 10px;
  margin-left: -5px;
  border: 1px solid #E3E8F0;
  background: #fff;
  transform: rotate(45deg);
}

.is-bottom > .ip-arrow {
  top: -6px;
  border-right: 0;
  border-bottom: 0;
}

.is-top > .ip-arrow {
  bottom: -6px;
  border-left: 0;
  border-top: 0;
}

/* --- head ----------------------------------------------------------------- */
.ip-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 5px;
}

.ip-title {
  margin: 0;
  font-size: 13.5px;
  font-weight: 700;
  letter-spacing: -.01em;
  line-height: 1.3;
  color: #0F2547;
}

.ip-close {
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  width: 20px;
  height: 20px;
  margin: -2px -3px 0 auto;
  padding: 0;
  border: 0;
  border-radius: 5px;
  background: none;
  color: #8C9AB0;
  cursor: pointer;
}

.ip-close:hover {
  background: #F1F5FA;
  color: #0F2547;
}

.ip-close:focus-visible {
  outline: 2px solid rgba(26, 86, 219, .5);
  outline-offset: 1px;
}

.ip-lede {
  margin: 0;
  color: #6B7C93;
}

/* --- figures -------------------------------------------------------------- */
.ip-rows {
  margin: 8px 0 0;
  padding: 8px 0 0;
  border-top: 1px solid #E3E8F0;
  font-variant-numeric: tabular-nums;
}

.ip-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.ip-row + .ip-row {
  margin-top: 4px;
}

.ip-row dt {
  min-width: 0;
  font-weight: 400;
  color: #6B7C93;
}

.ip-row dd {
  margin: 0;
  flex: 0 0 auto;
  font-weight: 700;
  white-space: nowrap;
  color: #0F2547;
}

/* after the working, the rows carry no rule of their own — a total row brings
   its own, and two stacked would read as an empty band */
.ip-groups + .ip-rows {
  margin-top: 0;
  padding-top: 0;
  border-top: 0;
}

.ip-row.is-strong {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid #E3E8F0;
}

.ip-row.is-strong dt {
  font-weight: 700;
  color: #0F2547;
}

.ip-row.is-strong dd {
  color: #15803D;
}

/* --- per-product working -------------------------------------------------- */
.ip-groups {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid #E3E8F0;
  font-variant-numeric: tabular-nums;
}

.ip-group + .ip-group {
  margin-top: 8px;
}

.ip-group p {
  margin: 0;
}

.ip-group-name {
  font-weight: 600;
  color: #0F2547;
}

.ip-group-line {
  color: #6B7C93;
}

.ip-group-total {
  font-weight: 700;
  color: #0F2547;
}

.ip-note {
  margin: 8px 0 0;
  padding-top: 8px;
  border-top: 1px solid #E3E8F0;
  color: #6B7C93;
}

/* --- phone: a sheet at the foot of the screen ----------------------------- */
.ip-scrim {
  position: fixed;
  inset: 0;
  z-index: 1990;
  background: rgba(15, 37, 71, .38);
}

.ip-panel.is-sheet {
  top: auto;
  right: 0;
  bottom: 0;
  left: 0;
  width: auto;
  max-width: none;
  padding: 14px 16px calc(18px + env(safe-area-inset-bottom, 0px));
  border: 0;
  border-radius: 14px 14px 0 0;
  box-shadow: 0 -8px 30px rgba(15, 37, 71, .22);
  font-size: 13px;
}

.ip-panel.is-sheet .ip-title {
  font-size: 15px;
}

.ip-panel.is-sheet .ip-close {
  width: 26px;
  height: 26px;
}

@media (prefers-reduced-motion: reduce) {
  .ip-btn {
    transition: none;
  }
}
</style>
