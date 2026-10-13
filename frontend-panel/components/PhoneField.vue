<template>

  <div
    class="pf"
    v-outside-click="closePicker"
  >

    <div
      class="pf-control"
      :class="{ invalid: isInvalid, open: pickerOpen }"
    >

      <button
        type="button"
        class="pf-country"
        :aria-label="`Country: ${selected.name}. Change`"
        aria-haspopup="listbox"
        :aria-expanded="pickerOpen ? 'true' : 'false'"
        @click="togglePicker"
      >
        <span
          class="fi"
          :class="`fi-${selected.code.toLowerCase()}`"
        />
        <span class="pf-dial">{{ selected.dial }}</span>
        <svg
          class="pf-caret"
          width="12"
          height="12"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
        ><path d="M4 6l4 4 4-4"/></svg>
      </button>

      <input
        :id="inputId"
        ref="input"
        class="pf-input"
        type="tel"
        inputmode="tel"
        autocomplete="tel-national"
        :placeholder="placeholder"
        :value="national"
        @input="onInput"
        @blur="$emit('blur')"
      >

    </div>

    <!-- country picker -->
    <div
      v-if="pickerOpen"
      class="pf-picker"
      role="listbox"
    >

      <div class="pf-search">
        <input
          ref="search"
          v-model.trim="query"
          type="text"
          placeholder="Search country or code"
          @keydown.esc="closePicker"
          @keydown.enter.prevent="pickFirst"
        >
      </div>

      <div class="pf-list">

        <button
          v-for="country in filtered"
          :key="country.code"
          type="button"
          class="pf-option"
          :class="{ on: country.code === selected.code }"
          role="option"
          :aria-selected="country.code === selected.code ? 'true' : 'false'"
          @click="pick(country)"
        >
          <span
            class="fi"
            :class="`fi-${country.code.toLowerCase()}`"
          />
          <span class="pf-option-name">{{ country.name }}</span>
          <span class="pf-option-dial">{{ country.dial }}</span>
        </button>

        <p
          v-if="!filtered.length"
          class="pf-empty"
        >
          No country matches “{{ query }}”.
        </p>

      </div>

    </div>

  </div>

</template>

<script>

import {
  AsYouType,
  parsePhoneNumberFromString,
  getExampleNumber,
  getCountries,
  getCountryCallingCode
} from 'libphonenumber-js'
import examples from 'libphonenumber-js/examples.mobile.json'
import outsideClick from '~/directives/outside-click'
import 'flag-icons/css/flag-icons.min.css'

export default {

  name: "PhoneField",

  directives: {outsideClick},

  props: {

    /* E.164 value, e.g. +353871234567 */
    value: {
      type: String,
      default: ''
    },

    /* Country display names keyed by ISO code, from the countries resource. */
    names: {
      type: Object,
      default: null
    },

    defaultCountry: {
      type: String,
      default: 'IE'
    },

    isInvalid: {
      type: Boolean,
      default: false
    },

    /*
     * Put on the input, so a <label for> reaches something focusable rather
     * than the wrapper div.
     */
    inputId: {
      type: String,
      default: null
    }
  },

  data() {
    return {
      country: this.defaultCountry,
      national: '',
      pickerOpen: false,
      query: ''
    }
  },

  computed: {

    /*
     * Built from libphonenumber's own country list rather than the phones.json
     * resource, which is missing 23 entries — the United Kingdom among them.
     * This way every option in the picker is one we can actually validate.
     */
    countries() {

      return getCountries()
        .map(code => ({
          code,
          dial: '+' + getCountryCallingCode(code),
          name: (this.names && this.names[code] && this.names[code].name) || code
        }))
        .sort((a, b) => a.name.localeCompare(b.name))
    },

    selected() {
      return this.countries.find(i => i.code === this.country)
        || {code: this.defaultCountry, dial: '+353', name: 'Republic of Ireland'}
    },

    filtered() {

      const q = this.query.toLowerCase()

      if (!q) return this.countries

      return this.countries.filter(i =>
        i.name.toLowerCase().includes(q)
        || i.dial.includes(q)
        || i.code.toLowerCase() === q
      )
    },

    /* A real mobile number for this country, so the hint matches the flag. */
    placeholder() {

      try {
        const example = getExampleNumber(this.country, examples)
        return example ? example.formatNational() : '087 123 4567'

      } catch (e) {
        return '087 123 4567'
      }
    }
  },

  watch: {

    value(next) {
      if (next !== this.currentE164()) {
        this.hydrate(next)
      }
    },

    country() {
      // Re-run validation and formatting against the new country's rules.
      this.emit()
    }
  },

  methods: {

    togglePicker() {

      this.pickerOpen = !this.pickerOpen
      this.query = ''

      if (this.pickerOpen) {
        this.$nextTick(() => this.$refs.search && this.$refs.search.focus())
      }
    },

    closePicker() {
      this.pickerOpen = false
    },

    pick(country) {
      this.country = country.code
      this.closePicker()
      this.$nextTick(() => this.$refs.input && this.$refs.input.focus())
    },

    pickFirst() {
      if (this.filtered.length) {
        this.pick(this.filtered[0])
      }
    },

    onInput(event) {

      // Format as they type, in the selected country's own convention.
      const raw = event.target.value
      const formatted = new AsYouType(this.country).input(raw)

      // Backspacing into a separator would otherwise re-add it and trap them.
      this.national = raw.length < this.national.length ? raw : formatted

      event.target.value = this.national

      this.emit()
    },

    currentE164() {

      const parsed = parsePhoneNumberFromString(this.national, this.country)

      return parsed ? parsed.number : ''
    },

    emit() {

      const parsed = parsePhoneNumberFromString(this.national, this.country)

      this.$emit('change', {
        // E.164 is what Twilio and the API want; keep the display separate.
        e164: parsed ? parsed.number : '',
        national: this.national,
        country: this.country,
        valid: !!(parsed && parsed.isValid())
      })
    },

    hydrate(value) {

      if (!value) {
        this.national = ''
        return
      }

      const parsed = parsePhoneNumberFromString(value)

      if (parsed) {
        this.country = parsed.country || this.country
        this.national = parsed.formatNational()
      } else {
        this.national = value
      }
    }
  },

  mounted() {
    this.hydrate(this.value)
  }
}
</script>

<style scoped>

.pf {
    position: relative;
}

.pf-control {
    display: flex;
    align-items: stretch;
    width: 100%;
    background: #fff;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: var(--auth-radius, 14px);
    transition: border-color .15s, box-shadow .15s;
}

.pf-control:focus-within {
    border-color: var(--auth-brand, #2D2DA0);
    box-shadow: 0 0 0 4px rgba(45, 45, 160, .10);
}

.pf-control.invalid {
    border-color: var(--auth-danger, #C8324B);
    box-shadow: 0 0 0 4px rgba(200, 50, 75, .09);
}

.pf-country {
    display: flex;
    align-items: center;
    gap: 7px;
    flex: 0 0 auto;
    /* 48px + the control's 1px borders = the 50px every other input renders at. */
    height: 48px;
    line-height: 1.4;
    padding: 0 12px 0 14px;
    margin: 0;
    background: none;
    border: 0;
    border-right: 1px solid var(--auth-line, #E4E5F3);
    border-radius: var(--auth-radius, 14px) 0 0 var(--auth-radius, 14px);
    cursor: pointer;
    font-family: inherit;
    color: var(--auth-ink, #16174A);
}

.pf-country:hover {
    background: var(--auth-wash, #F7F7FD);
}

.pf-country .fi {
    width: 20px;
    height: 15px;
    border-radius: 2px;
    flex: 0 0 auto;
}

.pf-dial {
    font-size: 14.5px;
    font-weight: 600;
    white-space: nowrap;
}

.pf-caret {
    color: var(--auth-muted, #6B7191);
    flex: 0 0 auto;
}

.pf-input {
    flex: 1;
    min-width: 0;
    /*
     * base.styl sets line-height:48px on every input. A scoped `height: auto`
     * outranks its height rule but not that line-height, so the field grew to
     * 76px. Pin both instead of leaving it to compute.
     */
    height: 48px;
    line-height: 1.4;
    border: 0;
    outline: 0;
    background: none;
    padding: 0 16px 0 12px;
    font-size: 15px;
    font-family: inherit;
    color: var(--auth-ink, #16174A);
    border-radius: 0 var(--auth-radius, 14px) var(--auth-radius, 14px) 0;
}

.pf-input::placeholder {
    color: #9AA0BC;
}

/* ── picker ── */
.pf-picker {
    position: absolute;
    z-index: 30;
    top: calc(100% + 6px);
    left: 0;
    width: 100%;
    min-width: 260px;
    background: #fff;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: var(--auth-radius, 14px);
    box-shadow: 0 18px 44px rgba(22, 23, 74, .16);
    overflow: hidden;
}

.pf-search {
    padding: 10px;
    border-bottom: 1px solid var(--auth-line, #E4E5F3);
}

.pf-search input {
    width: 100%;
    height: auto;
    line-height: 1.5;
    padding: 9px 12px;
    font-size: 14px;
    font-family: inherit;
    border: 1px solid var(--auth-line-strong, #CFD1E8);
    border-radius: 10px;
    outline: 0;
}

.pf-search input:focus {
    border-color: var(--auth-brand, #2D2DA0);
}

.pf-list {
    max-height: 240px;
    overflow-y: auto;
    scrollbar-width: thin;
}

.pf-option {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    height: auto;
    line-height: 1.4;
    padding: 9px 12px;
    background: none;
    border: 0;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    font-size: 14px;
    color: var(--auth-ink, #16174A);
}

.pf-option:hover,
.pf-option.on {
    background: var(--auth-brand-50, #F1F1FC);
}

.pf-option .fi {
    width: 20px;
    height: 15px;
    border-radius: 2px;
    flex: 0 0 auto;
}

.pf-option-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pf-option-dial {
    color: var(--auth-muted, #6B7191);
    font-variant-numeric: tabular-nums;
    flex: 0 0 auto;
}

.pf-empty {
    margin: 0;
    padding: 16px 12px;
    font-size: 13.5px;
    color: var(--auth-muted, #6B7191);
    text-align: center;
}

@media (max-width: 640px) {

    /* Matches the 16px rule on the other inputs — below it iOS zooms on focus. */
    .pf-input,
    .pf-search input {
        font-size: 16px;
    }
}

</style>
