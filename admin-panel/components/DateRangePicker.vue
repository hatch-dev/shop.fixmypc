<template>
  <div
    class="drp"
    :class="{ 'is-open': open }"
    v-outside-click="onOutside"
  >
    <button
      ref="trigger"
      type="button"
      class="drp__trigger"
      :disabled="disabled"
      :aria-expanded="open ? 'true' : 'false'"
      aria-haspopup="dialog"
      @click="toggle"
      @keydown.down.prevent="openPanel"
    >
      <i class="fa fa-calendar-days drp__icon" aria-hidden="true"></i>
      <span class="drp__label">
        <span class="drp__label-name">{{ presetName }}</span>
        <template v-if="rangeText">
          <span class="drp__label-sep" aria-hidden="true">·</span>
          <span class="drp__label-dates">{{ rangeText }}</span>
        </template>
      </span>
      <i
        class="fa drp__caret"
        :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"
        aria-hidden="true"
      ></i>
    </button>

    <!--
      The panel is a child of the trigger's wrapper - the outside-click
      directive walks the DOM tree - but positions itself with position:fixed
      against the viewport. `.content` is a scroll container (overflow-y:auto,
      height:100vh), so an absolutely positioned panel was clipped the moment it
      reached past the card; fixed escapes that, and place() keeps it glued to
      the trigger through scrolling and resizing.
    -->
    <div
      v-if="open"
      ref="panel"
      class="drp__panel"
      :class="[narrow ? 'is-sheet' : 'is-anchored', `is-${placement}`]"
      :style="narrow ? null : panelStyle"
      role="dialog"
      tabindex="-1"
      :aria-label="$t('index.selectRange')"
      @keydown.esc.stop.prevent="cancel"
    >
      <span v-if="!narrow" class="drp__arrow" :style="arrowStyle" aria-hidden="true"></span>

      <div class="drp__body">
        <!-- The handful of windows people actually reach for, always visible -->
        <div class="drp__rail" role="group" :aria-label="$t('index.selectRange')">
          <button
            v-for="q in quickOptions"
            :key="q.key"
            type="button"
            class="drp__rail-item"
            :class="{ 'is-active': draftPreset === q.key }"
            :aria-pressed="draftPreset === q.key ? 'true' : 'false'"
            @click="chooseQuick(q.key)"
          >
            <span>{{ q.title }}</span>
            <i v-if="draftPreset === q.key" class="fa fa-check" aria-hidden="true"></i>
          </button>
        </div>

        <div class="drp__cal">
          <!--
            Which end the next click sets, spelled out. On a phone this row
            stays put while the calendar scrolls under it.
          -->
          <div class="drp__fields">
            <span class="drp__field" :class="{ 'is-active': editing === 'start' }">
              <em>{{ $t('index.startDate') }}</em>
              <b>{{ draft.start ? formatDay(draft.start) : placeholderDash }}</b>
            </span>
            <i class="fa fa-arrow-right-long drp__field-sep" aria-hidden="true"></i>
            <span class="drp__field" :class="{ 'is-active': editing === 'end' }">
              <em>{{ $t('index.endDate') }}</em>
              <b>{{ draft.end ? formatDay(draft.end) : placeholderDash }}</b>
            </span>
          </div>

          <div class="drp__months">
            <div
              v-for="(m, mi) in visibleMonths"
              :key="`m-${mi}`"
              class="drp__month"
            >
              <div class="drp__month-head">
                <button
                  v-if="mi === 0"
                  type="button"
                  class="drp__nav"
                  :disabled="!canGoBack"
                  :aria-label="$t('index.prevMonth')"
                  :title="$t('index.prevMonth')"
                  @click="shiftMonth(-1)"
                >
                  <i class="fa fa-chevron-left" aria-hidden="true"></i>
                </button>
                <span v-else class="drp__nav is-ghost" aria-hidden="true"></span>

                <span class="drp__month-title">{{ m.title }}</span>

                <button
                  v-if="mi === visibleMonths.length - 1"
                  type="button"
                  class="drp__nav"
                  :disabled="!canGoForward"
                  :aria-label="$t('index.nextMonth')"
                  :title="$t('index.nextMonth')"
                  @click="shiftMonth(1)"
                >
                  <i class="fa fa-chevron-right" aria-hidden="true"></i>
                </button>
                <span v-else class="drp__nav is-ghost" aria-hidden="true"></span>
              </div>

              <!--
                Keyed by index, not by the letter: a narrow weekday name repeats
                (T for Tuesday and Thursday, S for Saturday and Sunday) and Vue
                warns about duplicate keys in the same list.
              -->
              <div class="drp__dow" aria-hidden="true">
                <span v-for="(d, di) in dayNames" :key="`dn-${mi}-${di}`">{{ d }}</span>
              </div>

              <div class="drp__grid" @mouseleave="hover = null">
                <button
                  v-for="(cell, ci) in m.cells"
                  :key="`c-${mi}-${ci}`"
                  type="button"
                  class="drp__day"
                  :class="dayClass(cell)"
                  :data-iso="cell ? cell.iso : null"
                  :disabled="!cell || isDisabled(cell)"
                  :tabindex="cell && cell.iso === tabIso ? 0 : -1"
                  :aria-label="cell ? ariaDay(cell) : null"
                  :aria-current="cell && cell.iso === today ? 'date' : null"
                  @click="pick(cell)"
                  @keydown="onGridKey($event, cell)"
                  @mouseenter="onHover(cell)"
                ><span class="drp__day-in">{{ cell ? cell.d : '' }}</span></button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="drp__foot">
        <div class="drp__summary">
          <em>{{ $t('index.selectedRange') }}</em>
          <b :class="{ 'is-partial': !summaryComplete }">{{ summaryText }}</b>
          <span class="drp__days">{{ summaryHint }}</span>
        </div>

        <div class="drp__actions">
          <button
            type="button"
            class="drp__reset"
            :disabled="isDefaultDraft"
            @click="resetToDefault"
          >
            <i class="fa fa-rotate-left" aria-hidden="true"></i>
            {{ $t('index.resetRange') }}
          </button>
          <button type="button" class="drp__btn" @click="cancel">
            {{ $t('index.cancelBtn') }}
          </button>
          <button
            type="button"
            class="drp__btn drp__btn--primary"
            :disabled="!canApply"
            @click="applyDraft"
          >
            {{ $t('index.apply') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
  import outsideClick from '~/directives/outside-click'

  /**
   * Compact date-range control for the dashboard.
   *
   * One panel, not two screens: the quick windows sit in a rail beside a
   * two-month calendar, so "last 30 days" and "the week of the 12th" are the
   * same gesture. Nothing here touches the dashboard until Apply - the draft
   * lives in this component, so a half-made selection cannot refetch the page.
   *
   * The control emits a preset KEY wherever it can, and Y-m-d strings only for
   * a genuinely custom range. The server resolves both into a half-open UTC
   * window in the viewer's timezone, so the browser never reasons about day
   * boundaries and the control cannot disagree with the query. The preset dates
   * computed here paint the calendar; they are never what gets queried.
   */
  export default {
    name: 'DateRangePicker',
    directives: {
      outsideClick
    },
    props: {
      /** { preset: 'd30' | 'today' | ... | 'custom' | 'all', start, end } */
      value: {
        type: Object,
        default: () => ({ preset: 'd30', start: null, end: null })
      },
      /** The window the API actually applied - what the label should read. */
      applied: {
        type: Object,
        default: null
      },
      /** Latest selectable day; the future holds no orders. */
      maxDate: {
        type: String,
        default: null
      },
      minDate: {
        type: String,
        default: null
      },
      /** Adds "All Time" for widgets that can run unbounded. */
      allowAll: {
        type: Boolean,
        default: false
      },
      /** What Reset goes back to - the owner's own opening window. */
      defaultPreset: {
        type: String,
        default: 'd30'
      },
      monthsShown: {
        type: Number,
        default: 2
      },
      disabled: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        open: false,
        cursor: null,
        draft: { start: null, end: null },
        draftPreset: 'd30',
        hover: null,
        narrow: false,
        focusIso: null,
        placement: 'bottom',
        // Hidden until place() has measured it: the panel has to be in the DOM
        // to be measured, and an unplaced one would flash at the top-left
        // corner of the viewport for a frame first.
        panelStyle: { visibility: 'hidden' },
        arrowStyle: null
      }
    },
    computed: {
      quickOptions() {
        const list = [
          { key: 'today', title: this.$t('index.today') },
          { key: 'yesterday', title: this.$t('index.yesterday') },
          { key: 'week', title: this.$t('index.thisWeek') },
          { key: 'month', title: this.$t('index.thisMonthR') },
          { key: 'd30', title: this.$t('index.last30d') },
          { key: 'year', title: this.$t('index.thisYearR') }
        ]
        if (this.allowAll) {
          list.push({ key: 'all', title: this.$t('index.allTime') })
        }
        return list
      },
      presetName() {
        if (this.value.preset === 'custom') return this.$t('index.customRange')
        const hit = this.quickOptions.find(q => q.key === this.value.preset)
        return hit ? hit.title : this.$t('index.selectRange')
      },
      /**
       * "16 Aug 2026 – 14 Sep 2026". Prefers the window the API applied; before
       * the first response lands it falls back to what the preset resolves to
       * locally, so the default range is on screen from the first paint instead
       * of appearing a request later.
       */
      rangeText() {
        const r = this.applied || this.value
        if (r && r.start && r.end) return this.formatRange(r.start, r.end)

        const preset = this.value.preset
        if (!preset || preset === 'custom' || preset === 'all') return ''
        const local = this.presetRange(preset)
        return local.start && local.end ? this.formatRange(local.start, local.end) : ''
      },
      today() {
        return this.maxDate || this.toIso(new Date())
      },
      monthCount() {
        return this.narrow ? 1 : Math.max(1, this.monthsShown)
      },
      placeholderDash() {
        return '—'
      },
      dayNames() {
        // Monday-first, matching the week buckets the API builds
        const base = new Date(2024, 0, 1) // a Monday
        return Array.from({ length: 7 }, (_, i) => {
          const d = new Date(base.getFullYear(), base.getMonth(), base.getDate() + i)
          return d.toLocaleString(this.$i18n?.locale || 'en', { weekday: 'narrow' })
        })
      },
      visibleMonths() {
        if (!this.cursor) return []
        const out = []
        for (let i = 0; i < this.monthCount; i++) {
          const first = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + i, 1)
          out.push({
            title: first.toLocaleString(this.$i18n?.locale || 'en', { month: 'long', year: 'numeric' }),
            cells: this.monthCells(first)
          })
        }
        return out
      },
      /**
       * Exactly one day cell sits in the tab order and the arrow keys move it.
       * The alternative - every cell tabbable - makes a keyboard user pass
       * through sixty buttons to reach Apply.
       */
      tabIso() {
        const open = []
        this.visibleMonths.forEach(m => {
          m.cells.forEach(c => {
            if (c && !this.isDisabled(c)) open.push(c.iso)
          })
        })
        if (!open.length) return null
        if (this.focusIso && open.indexOf(this.focusIso) !== -1) return this.focusIso

        const preferred = [this.draft.start, this.draft.end, this.today]
        for (const p of preferred) {
          if (p && open.indexOf(p) !== -1) return p
        }
        return open[0]
      },
      canGoForward() {
        if (!this.cursor) return false
        const lastShown = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + this.monthCount, 1)
        const max = this.parseIso(this.today)
        return lastShown <= new Date(max.getFullYear(), max.getMonth(), 1)
      },
      canGoBack() {
        if (!this.cursor) return false
        if (!this.minDate) return true
        const min = this.parseIso(this.minDate)
        return this.cursor > new Date(min.getFullYear(), min.getMonth(), 1)
      },
      canApply() {
        if (this.draftPreset === 'all') return true
        return !!(this.draft.start && this.draft.end && this.draft.end >= this.draft.start)
      },
      /** Which end the next click sets - drives the two field chips. */
      editing() {
        return (!this.draft.start || this.draft.end) ? 'start' : 'end'
      },
      draftDays() {
        if (!this.draft.start || !this.draft.end) return 0
        return Math.round((this.parseIso(this.draft.end) - this.parseIso(this.draft.start)) / 86400000) + 1
      },
      summaryComplete() {
        return this.draftPreset === 'all' || !!(this.draft.start && this.draft.end)
      },
      summaryText() {
        if (this.draftPreset === 'all') return this.$t('index.allTime')
        if (this.draft.start && this.draft.end) return this.formatRange(this.draft.start, this.draft.end)
        if (this.draft.start) return `${this.formatDay(this.draft.start)} – ${this.placeholderDash}`
        return this.placeholderDash
      },
      summaryHint() {
        if (this.draftPreset === 'all') return this.$t('index.lifetime')
        if (this.draft.start && this.draft.end) {
          const n = this.draftDays
          return n === 1 ? this.$t('index.oneDay') : this.$t('index.nDays', { count: n })
        }
        return this.draft.start ? this.$t('index.pickEndDate') : this.$t('index.pickStartDate')
      },
      /** Reset has nothing to undo once the draft already is the default. */
      isDefaultDraft() {
        if (this.draftPreset !== this.defaultPreset) return false
        const d = this.presetRange(this.defaultPreset)
        return d.start === this.draft.start && d.end === this.draft.end
      },
      /** The end a hover is proposing, while only a start has been picked. */
      previewEnd() {
        if (this.draft.start && !this.draft.end && this.hover) {
          return this.hover >= this.draft.start ? this.hover : null
        }
        return null
      }
    },
    methods: {
      // Local Y-m-d. toISOString() would shift by the UTC offset and hand back
      // the wrong day for anyone east or west of Greenwich.
      toIso(d) {
        const p = n => String(n).padStart(2, '0')
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
      },
      parseIso(iso) {
        const [y, m, d] = String(iso).split('-').map(Number)
        return new Date(y, (m || 1) - 1, d || 1)
      },
      addDays(iso, n) {
        const d = this.parseIso(iso)
        return this.toIso(new Date(d.getFullYear(), d.getMonth(), d.getDate() + n))
      },
      addMonths(iso, n) {
        const d = this.parseIso(iso)
        const target = new Date(d.getFullYear(), d.getMonth() + n, 1)
        const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()
        return this.toIso(new Date(target.getFullYear(), target.getMonth(), Math.min(d.getDate(), lastDay)))
      },
      formatDay(iso) {
        const d = this.parseIso(iso)
        if (isNaN(d)) return iso
        const p = n => String(n).padStart(2, '0')
        const month = d.toLocaleString(this.$i18n?.locale || 'en', { month: 'short' })
        return `${p(d.getDate())} ${month} ${d.getFullYear()}`
      },
      formatRange(start, end) {
        return start === end
          ? this.formatDay(start)
          : `${this.formatDay(start)} – ${this.formatDay(end)}`
      },
      ariaDay(cell) {
        const d = this.parseIso(cell.iso)
        return d.toLocaleDateString(this.$i18n?.locale || 'en', {
          weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        })
      },
      /**
       * What a quick option means in dates - for painting the calendar and the
       * summary only. The request still travels as a preset key, so the server
       * resolves the real boundaries in the viewer's timezone.
       */
      presetRange(key) {
        const t = this.parseIso(this.today)
        const at = (y, m, d) => this.toIso(new Date(y, m, d))
        const shift = n => at(t.getFullYear(), t.getMonth(), t.getDate() + n)
        const end = this.toIso(t)

        let start = null
        let stop = end
        switch (key) {
          case 'today':
            start = end
            break
          case 'yesterday':
            start = shift(-1)
            stop = start
            break
          case 'week':
            start = shift(-((t.getDay() + 6) % 7))
            break
          case 'month':
            start = at(t.getFullYear(), t.getMonth(), 1)
            break
          case 'd30':
            start = shift(-29)
            break
          case 'year':
            start = at(t.getFullYear(), 0, 1)
            break
          default:
            return { start: null, end: null }
        }

        if (this.minDate && start < this.minDate) start = this.minDate
        return { start, end: stop }
      },
      monthCells(first) {
        const y = first.getFullYear()
        const m = first.getMonth()
        // getDay() is 0 on Sunday; shift so Monday starts the week
        const lead = (new Date(y, m, 1).getDay() + 6) % 7
        const days = new Date(y, m + 1, 0).getDate()

        const cells = []
        for (let i = 0; i < lead; i++) cells.push(null)
        for (let d = 1; d <= days; d++) cells.push({ d, iso: this.toIso(new Date(y, m, d)) })
        while (cells.length % 7 !== 0) cells.push(null)
        return cells
      },
      isDisabled(cell) {
        if (!cell) return true
        if (this.maxDate && cell.iso > this.maxDate) return true
        if (this.minDate && cell.iso < this.minDate) return true
        return false
      },
      /**
       * Start, end, the days between, today, the hovered end and the unusable
       * days each get their own state. `has-range` is what lets the band paint
       * half a cell, so the selection reads as one continuous strip rather than
       * a row of separate chips.
       */
      dayClass(cell) {
        if (!cell) return 'is-blank'

        const iso = cell.iso
        const start = this.draft.start
        const preview = this.previewEnd
        const to = this.draft.end || preview
        const spans = !!start && !!to && to !== start

        // A hovered end is a proposal, not a choice: it closes the band so the
        // length of the range is readable, but it is never painted as a picked
        // end - otherwise the calendar shows two committed ends at once.
        const committedEnd = spans && !!this.draft.end && iso === to
        const proposedEnd = spans && !this.draft.end && iso === to

        return {
          'is-today': iso === this.today,
          'is-start': !!start && iso === start,
          'is-end': committedEnd,
          'is-single': !!start && iso === start && !spans,
          'is-in-range': spans && iso > start && iso < to,
          'is-preview-end': proposedEnd,
          'has-range': spans && iso >= start && iso <= to,
          'is-disabled': this.isDisabled(cell)
        }
      },
      onHover(cell) {
        if (!cell || this.isDisabled(cell)) return
        this.hover = cell.iso
      },
      /**
       * First click sets the start and clears the end; the second closes the
       * range. Clicking before the current start restarts from there rather
       * than refusing, which is what every calendar of this shape does.
       */
      pick(cell) {
        if (!cell || this.isDisabled(cell)) return

        this.focusIso = cell.iso
        this.draftPreset = 'custom'
        this.hover = null

        if (!this.draft.start || this.draft.end || cell.iso < this.draft.start) {
          this.draft = { start: cell.iso, end: null }
          return
        }

        this.draft = { start: this.draft.start, end: cell.iso }
      },
      chooseQuick(key) {
        this.draftPreset = key
        this.draft = this.presetRange(key)
        this.hover = null
        this.focusIso = this.draft.end || this.draft.start || this.today
        this.centerOn(this.draft.end || this.today)
      },
      shiftMonth(delta) {
        if (delta > 0 && !this.canGoForward) return
        if (delta < 0 && !this.canGoBack) return
        this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + delta, 1)
      },
      /** Put `iso` in the last visible month, so the calendar opens on it. */
      centerOn(iso) {
        const anchor = this.parseIso(iso || this.today)
        const offset = Math.max(this.monthCount - 1, 0)
        this.cursor = new Date(anchor.getFullYear(), anchor.getMonth() - offset, 1)
      },
      ensureVisible(iso) {
        if (!this.cursor) return this.centerOn(iso)
        const d = this.parseIso(iso)
        const firstVisible = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1)
        const lastVisible = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + this.monthCount, 0)

        if (d < firstVisible) {
          this.cursor = new Date(d.getFullYear(), d.getMonth(), 1)
        } else if (d > lastVisible) {
          this.centerOn(iso)
        }
      },
      focusDay(iso) {
        let next = iso
        if (this.maxDate && next > this.maxDate) next = this.maxDate
        if (this.minDate && next < this.minDate) next = this.minDate

        this.focusIso = next
        this.ensureVisible(next)
        this.$nextTick(() => {
          const el = this.$refs.panel && this.$refs.panel.querySelector(`[data-iso="${next}"]`)
          if (el) el.focus()
        })
      },
      onGridKey(e, cell) {
        if (!cell) return
        const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }
        const weekday = (this.parseIso(cell.iso).getDay() + 6) % 7

        let next = null
        if (steps[e.key] !== undefined) next = this.addDays(cell.iso, steps[e.key])
        else if (e.key === 'Home') next = this.addDays(cell.iso, -weekday)
        else if (e.key === 'End') next = this.addDays(cell.iso, 6 - weekday)
        else if (e.key === 'PageUp') next = this.addMonths(cell.iso, -1)
        else if (e.key === 'PageDown') next = this.addMonths(cell.iso, 1)
        else return

        e.preventDefault()
        this.focusDay(next)
      },
      /** Seed the draft from the window currently on the dashboard. */
      seed() {
        const r = this.applied || this.value
        const preset = this.value.preset || this.defaultPreset
        const hasDates = !!(r && r.start && r.end)

        if (preset !== 'custom' && preset !== 'all' && !hasDates) {
          this.draft = this.presetRange(preset)
        } else {
          this.draft = { start: (r && r.start) || null, end: (r && r.end) || null }
        }

        this.draftPreset = preset
        this.hover = null
        this.focusIso = this.draft.end || this.draft.start || this.today
        this.centerOn(this.draft.end || this.today)
      },
      measure() {
        this.narrow = typeof window !== 'undefined' && window.innerWidth < 720
      },
      /**
       * Glue the fixed panel to the trigger: right aligned, flipped above when
       * the space below runs out, and always inside the viewport with an 8px
       * margin. The arrow follows the trigger's centre, so the panel still
       * reads as belonging to the button it came from.
       */
      place() {
        const trigger = this.$refs.trigger
        const panel = this.$refs.panel
        if (!trigger || !panel) return

        const r = trigger.getBoundingClientRect()
        const pw = panel.offsetWidth
        const ph = panel.offsetHeight
        const vw = window.innerWidth
        const vh = window.innerHeight
        const gap = 8
        const edge = 8

        let left = r.right - pw
        if (left + pw > vw - edge) left = vw - edge - pw
        if (left < edge) left = edge

        let top = r.bottom + gap
        let placement = 'bottom'
        if (top + ph > vh - edge) {
          const above = r.top - gap - ph
          if (above >= edge) {
            top = above
            placement = 'top'
          } else {
            top = Math.max(edge, vh - edge - ph)
          }
        }

        const centre = r.left + r.width / 2
        const arrow = Math.min(Math.max(centre - left, 18), Math.max(pw - 18, 18))

        this.placement = placement
        this.panelStyle = { top: `${Math.round(top)}px`, left: `${Math.round(left)}px` }
        this.arrowStyle = { left: `${Math.round(arrow)}px` }
      },
      /**
       * Scroll fires far faster than the screen repaints, and place() reads
       * layout. Coalesce to one measurement per frame.
       */
      onViewportChange() {
        if (this._placing) return
        this._placing = requestAnimationFrame(() => {
          this._placing = null
          this.measure()
          if (this.open && !this.narrow) this.place()
        })
      },
      toggle() {
        if (this.disabled) return
        if (this.open) return this.cancel()
        this.openPanel()
      },
      openPanel() {
        if (this.disabled || this.open) return
        this.measure()
        this.seed()
        this.open = true
        this.$nextTick(() => {
          if (!this.narrow) this.place()
          if (this.$refs.panel) this.$refs.panel.focus()
        })
      },
      close(refocus) {
        if (!this.open) return
        this.open = false
        this.hover = null
        this.panelStyle = { visibility: 'hidden' }
        if (refocus && this.$refs.trigger) this.$refs.trigger.focus()
      },
      /**
       * Cancel drops the draft. Nothing was emitted while it was being built,
       * so the applied window is already the one the dashboard is showing and
       * the next open re-seeds from it.
       */
      cancel() {
        this.close(true)
      },
      onOutside() {
        this.close(false)
      },
      applyDraft() {
        if (!this.canApply) return

        // A preset stays a preset: only the server knows where "this week" ends
        // in the viewer's timezone, and it echoes the resolved dates back.
        const payload = this.draftPreset === 'custom'
          ? { preset: 'custom', start: this.draft.start, end: this.draft.end }
          : { preset: this.draftPreset, start: null, end: null }

        this.$emit('change', payload)
        this.close(true)
      },
      resetToDefault() {
        this.chooseQuick(this.defaultPreset)
      }
    },
    mounted() {
      this.measure()
      window.addEventListener('resize', this.onViewportChange)
      window.addEventListener('scroll', this.onViewportChange, true)
    },
    beforeDestroy() {
      window.removeEventListener('resize', this.onViewportChange)
      window.removeEventListener('scroll', this.onViewportChange, true)
      if (this._placing) cancelAnimationFrame(this._placing)
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/date-range-picker.styl"
</style>
