<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setSupplier"
    get-api="getSupplier"
    route-name="procurement/suppliers"
    name="Supplier"
    :validation-keys="['name','email','country','phone','address','categories']"
    :validation-labels="validationLabels"
    :result="result"
    @result="settingResult"
  >
    <template v-slot:form="{ fieldErrors }">

      <section class="form-section">
        <header class="section-head">
          <h3>Contact</h3>
          <p>How we reach this supplier when an order needs sourcing.</p>
        </header>

        <div class="field-grid">

          <!-- Name -->
          <div class="field" data-field="name">
            <label for="sup-name">Name <span class="req">*</span></label>
            <input
              id="sup-name"
              type="text"
              v-model.trim="result.name"
              maxlength="255"
              placeholder="Company or contact name"
              :class="{ invalid: problem('name', fieldErrors) }"
              @blur="touch('name')"
            >
            <span class="error" v-if="problem('name', fieldErrors)">
              {{ problem('name', fieldErrors) }}
            </span>
          </div>

          <!-- Email -->
          <div class="field" data-field="email">
            <label for="sup-email">Email <span class="req">*</span></label>
            <input
              id="sup-email"
              type="email"
              v-model.trim="result.email"
              maxlength="255"
              placeholder="name@company.com"
              :class="{ invalid: problem('email', fieldErrors) }"
              @blur="touch('email')"
            >
            <span class="error" v-if="problem('email', fieldErrors)">
              {{ problem('email', fieldErrors) }}
            </span>
          </div>

          <!-- Country -->
          <div class="field" data-field="country">
            <label>Country <span class="req">*</span></label>
            <multiselect
              v-model="selectedCountry"
              :options="countryOptions"
              :searchable="true"
              :allow-empty="false"
              :loading="loadingCountries"
              :options-limit="60"
              :max-height="280"
              track-by="iso"
              label="search"
              placeholder="Search for a country"
              select-label=""
              selected-label=""
              deselect-label=""
              class="country-multiselect"
              :class="{ invalid: problem('country', fieldErrors) }"
              @close="touch('country')"
            >
              <template #singleLabel="{ option }">
                <img
                  v-if="!flagErrors[option.iso]"
                  class="flag"
                  :src="option.flagSrc"
                  :alt="option.iso"
                  @error="flagFailed(option.iso)"
                >
                <span v-else class="flag flag--empty"></span>
                <span class="country-name">{{ option.name }}</span>
                <span class="dial">{{ option.dial }}</span>
              </template>
              <template #option="{ option }">
                <img
                  v-if="!flagErrors[option.iso]"
                  class="flag"
                  :src="option.flagSrc"
                  :alt="option.iso"
                  @error="flagFailed(option.iso)"
                >
                <span v-else class="flag flag--empty"></span>
                <span class="country-name">{{ option.name }}</span>
                <span class="dial">{{ option.dial }}</span>
              </template>
              <template #noResult>No country matches that.</template>
            </multiselect>
            <span class="error" v-if="problem('country', fieldErrors)">
              {{ problem('country', fieldErrors) }}
            </span>
          </div>

          <!-- Phone -->
          <div class="field" data-field="phone">
            <label for="sup-phone">Phone <span class="req">*</span></label>
            <div class="phone-row" :class="{ invalid: problem('phone', fieldErrors), disabled: !selectedCountry }">
              <span class="phone-prefix">
                <img
                  v-if="selectedCountry && !flagErrors[selectedCountry.iso]"
                  class="flag"
                  :src="selectedCountry.flagSrc"
                  :alt="selectedCountry.iso"
                  @error="flagFailed(selectedCountry.iso)"
                >
                <span v-else class="flag flag--empty"></span>
                <span class="dial-code">{{ selectedCountry ? selectedCountry.dial : '—' }}</span>
              </span>
              <input
                id="sup-phone"
                type="tel"
                v-model="result.phone"
                maxlength="20"
                :placeholder="phonePlaceholder"
                :disabled="!selectedCountry"
                @blur="touch('phone')"
              >
            </div>
            <span class="error" v-if="problem('phone', fieldErrors)">
              {{ problem('phone', fieldErrors) }}
            </span>
            <span class="hint" v-else>
              {{ selectedCountry ? phoneHint : 'Choose a country first.' }}
            </span>
          </div>

          <!-- Address -->
          <div class="field field--wide" data-field="address">
            <label for="sup-address">Address <span class="req">*</span></label>
            <textarea
              id="sup-address"
              v-model.trim="result.address"
              maxlength="2000"
              rows="3"
              placeholder="Street, town, country"
              :class="{ invalid: problem('address', fieldErrors) }"
              @blur="touch('address')"
            ></textarea>
            <div class="field-foot">
              <span class="error" v-if="problem('address', fieldErrors)">
                {{ problem('address', fieldErrors) }}
              </span>
              <span v-else></span>
              <span class="counter" :class="{ near: addressNearLimit }">
                {{ (result.address || '').length }} / 2000
              </span>
            </div>
          </div>

        </div>
      </section>

      <section class="form-section">
        <header class="section-head">
          <h3>Coverage</h3>
          <p>
            Which categories this supplier can source. An order line is offered
            to every supplier covering one of its categories.
          </p>
        </header>

        <div class="field-grid">
          <div class="field field--wide" data-field="categories">
            <label>
              Categories <span class="req">*</span>
              <span class="count-badge" v-if="selectedCategories.length">
                {{ selectedCategories.length }} selected
              </span>
            </label>
            <multiselect
              v-model="selectedCategories"
              :options="categoryList"
              :multiple="true"
              :searchable="true"
              :close-on-select="false"
              :clear-on-select="false"
              :loading="loadingCategories"
              track-by="id"
              label="title"
              placeholder="Search and select categories"
              select-label=""
              selected-label=""
              deselect-label="Remove"
              class="category-multiselect"
              :class="{ invalid: problem('categories', fieldErrors) }"
              @close="touch('categories')"
            >
              <template #noResult>No category matches that.</template>
            </multiselect>
            <span class="error" v-if="problem('categories', fieldErrors)">
              {{ problem('categories', fieldErrors) }}
            </span>
            <span class="hint" v-else>
              Top-level categories only — a supplier covering one is asked about
              everything under it.
            </span>
          </div>
        </div>
      </section>

    </template>
  </data-page>
</template>

<script>
import DataPage from "~/components/partials/DataPage"
import util from "~/mixin/util"
import Multiselect from 'vue-multiselect'
import 'vue-multiselect/dist/vue-multiselect.min.css'
import { mapActions, mapGetters } from "vuex"

/*
 * The generic E.164 envelope, used for any country the server has no exact
 * rule for. Replaced by whatever suppliers/categories sends.
 */
const DEFAULT_PHONE_RANGE = [4, 14]

export default {
  name: "suppliers",
  middleware: ['common-middleware', 'auth'],

  components: {
    DataPage,
    Multiselect
  },

  mixins: [util],

  data() {
    return {
      categoryList: [],
      selectedCategories: [],
      selectedCountry: null,

      phoneRules: {},
      isoAliases: { UK: 'GB' },
      extraNames: {},
      retiredCodes: [],
      dialCodes: {},
      // ISO codes whose flag file could not be loaded.
      flagErrors: {},
      phoneDefault: DEFAULT_PHONE_RANGE,

      loadingCategories: false,
      loadingCountries: false,

      // Fields the admin has finished with, so a message only appears once
      // they have had their go at the box rather than while they type in it.
      touched: {},

      validationLabels: {
        name: 'Name',
        email: 'Email',
        country: 'Country',
        phone: 'Phone',
        address: 'Address',
        categories: 'Categories'
      },

      result: {
        id: '',
        name: '',
        email: '',
        country: '',
        country_code: '',
        phone: '',
        address: '',
        categories: []
      }
    }
  },

  async mounted() {
    await Promise.all([this.fetchCategories(), this.fetchCountries()])
    this.syncCountryFromResult()
  },

  computed: {
    ...mapGetters('language', ['langCode']),
    ...mapGetters('resource', ['countryList', 'phoneList']),

    /*
     * One entry per country that has a dialling code. `search` is what
     * vue-multiselect filters on, so typing "ire", "IE" or "353" all find
     * Ireland.
     */
    countryOptions() {
      const phones = this.phoneList || {}
      const countries = this.countryList || {}

      return Object.keys(phones)
        .map(key => {
          /*
           * phones.json is not keyed by ISO throughout -- it calls the
           * United Kingdom "UK" where countries.json, the flag files and the
           * length rules all use "GB". Normalising here is what makes those
           * agree; the server does the same with PhoneRules::ISO_ALIASES.
           */
          const iso = this.isoAliases[key] || key

          /*
           * The dialling code comes from the server already stripped of the
           * bracketed area hints phones.json carries ("+1(268)", "+62(8)"),
           * falling back to the raw value only if that payload is missing.
           */
          const dial = this.dialCodes[iso] || phones[key]

          const name = (countries[iso] && countries[iso].name)
            || this.extraNames[iso]
            || iso

          return {
            iso,
            name,
            dial,
            flagSrc: this.flagSrc(iso),
            search: `${name} ${iso} ${key} ${dial}`
          }
        })
        /*
         * AN is the Netherlands Antilles, dissolved in 2010, and its entry is
         * really Curacao's number -- which is on the list already as CW.
         */
        .filter(c => this.retiredCodes.indexOf(c.iso) === -1)
        .sort((a, b) => a.name.localeCompare(b.name))
    },

    phoneRange() {
      if (!this.selectedCountry) {
        return this.phoneDefault
      }

      return this.phoneRules[this.selectedCountry.iso] || this.phoneDefault
    },

    phoneHint() {
      const [min, max] = this.phoneRange

      return min === max
        ? `${min} digits, without the leading zero.`
        : `${min} to ${max} digits, without the leading zero.`
    },

    // Warn before the box stops accepting characters, not after.
    addressNearLimit() {
      return (this.result.address || '').length > 1800
    },

    phonePlaceholder() {
      const [min] = this.phoneRange

      return '0'.repeat(Math.min(min, 10)).replace(/0/g, 'X')
    }
  },

  watch: {
    selectedCategories(val) {
      this.result.categories = (val || []).map(c => c.id)
    },

    /*
     * The dialling code is derived, never typed. The server derives it again
     * from the country for the same reason: two fields on one picker that
     * can disagree is a row nobody can explain later.
     */
    selectedCountry(val) {
      this.result.country = val ? val.iso : ''
      this.result.country_code = val ? val.dial : ''
    }
  },

  methods: {
    ...mapActions('common', ['getRequest']),
    ...mapActions('resource', ['setCountryList', 'setPhoneList']),
    ...mapActions('admin', ['getSupplierCategories']),

    /*
     * A real flag, not an emoji one.
     *
     * Windows has no colour flag glyphs -- Segoe UI Emoji renders a regional
     * indicator pair as the two letters, by design -- so the emoji approach
     * showed "IE" to most of this panel's audience. These are SVGs in
     * static/flags, and the router base is prepended because the admin is
     * served under /admin/.
     */
    flagSrc(iso) {
      if (!iso || iso.length !== 2) {
        return ''
      }

      const base = (this.$router && this.$router.options && this.$router.options.base) || '/'

      return `${base.endsWith('/') ? base : base + '/'}flags/${iso.toLowerCase()}.svg`
    },

    /*
     * Remember the failure in state and let the template swap in a blank
     * badge, rather than reaching into the DOM.
     *
     * Editing the <img> directly does not survive: Vue owns that element's
     * class and re-applies it on the next patch, but leaves the removed src
     * alone -- so the image came back as a permanently broken icon with no
     * fallback styling on it.
     */
    flagFailed(iso) {
      this.$set(this.flagErrors, iso, true)
    },

    settingResult(evt) {
      this.result = {
        ...this.result,
        ...evt,
        categories: evt.categories ? evt.categories.map(c => c.id) : []
      }

      if (evt.categories) {
        this.selectedCategories = evt.categories
      }

      this.syncCountryFromResult()
    },

    /*
     * Put the picker back on the country the record was saved with. Rows
     * saved before the country column existed have only a dialling code, so
     * fall back to matching on that -- and where a code is shared (+1), leave
     * it unchosen rather than guess, which is what the backfill did too.
     */
    syncCountryFromResult() {
      if (!this.countryOptions.length) {
        return
      }

      if (this.result.country) {
        this.selectedCountry = this.countryOptions
          .find(c => c.iso === this.result.country) || null

        if (this.selectedCountry) {
          return
        }
      }

      if (this.result.country_code) {
        const matches = this.countryOptions.filter(c => c.dial === this.result.country_code)

        if (matches.length === 1) {
          this.selectedCountry = matches[0]
        }
      }
    },

    touch(field) {
      this.$set(this.touched, field, true)
    },

    /*
     * What to show under a control: whatever the server said about it, and
     * otherwise this form's own check once the admin has left the box.
     *
     * The server's message wins because it knows things the browser cannot
     * -- that an email is already taken, that a category has since been made
     * a subcategory.
     */
    problem(field, fieldErrors) {
      const fromServer = fieldErrors && fieldErrors[field]

      if (fromServer && fromServer.length) {
        return fromServer[0]
      }

      return this.touched[field] ? this.localProblem(field) : ''
    },

    /*
     * The same rules the server applies, so a save is refused here rather
     * than after a round trip. The server still applies them: this is a
     * courtesy, not the gate.
     */
    localProblem(field) {
      const value = this.result[field]
      const text = typeof value === 'string' ? value.trim() : value

      switch (field) {
        case 'name':
          if (!text) return 'Enter a name.'
          if (text.length < 2) return 'Give the supplier a name of at least 2 characters.'
          return ''

        case 'email':
          if (!text) return 'Enter an email address.'
          // Deliberately loose: the server decides deliverability, and a
          // strict pattern here only rejects addresses that are in fact real.
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(text)) {
            return 'That does not look like an email address.'
          }
          return ''

        case 'country':
          return this.selectedCountry ? '' : 'Choose a country.'

        case 'phone':
          return this.phoneProblem()

        case 'address':
          if (!text) return 'Enter an address.'
          if (text.length < 5) return 'Give an address of at least 5 characters.'
          return ''

        case 'categories':
          return (this.result.categories || []).length
            ? ''
            : 'Choose at least one category.'

        default:
          return ''
      }
    },

    /*
     * Mirrors PhoneRules::problem(). The leading zero is a trunk prefix and
     * is not part of the number, so it comes off before the length is
     * measured -- exactly as the server does before storing.
     */
    phoneProblem() {
      if (!this.selectedCountry) {
        return 'Choose a country first.'
      }

      const national = String(this.result.phone || '')
        .replace(/[^0-9]/g, '')
        .replace(/^0+/, '')

      if (!national) {
        return 'Enter a phone number.'
      }

      const [min, max] = this.phoneRange

      if (national.length < min || national.length > max) {
        const expected = min === max ? `${min} digits` : `${min} to ${max} digits`

        return `That is ${national.length} digits. A number in `
          + `${this.selectedCountry.name} is ${expected}, without the leading zero.`
      }

      const dialDigits = this.selectedCountry.dial.replace(/[^0-9]/g, '').length

      // E.164 allows 15 digits in total, country code included.
      if (dialDigits + national.length > 15) {
        return 'That number is too long to dial internationally.'
      }

      return ''
    },

    async fetchCategories() {
      this.loadingCategories = true

      try {
        /*
         * suppliers/categories, not product/with-categories: the latter
         * serialises every product in the shop -- with two appended
         * attributes that each cost a query -- to fill a list of category
         * names, gates on category.view, and was reached through a
         * hard-coded https://shop.fixmypc.ie/. It also returns TOP-LEVEL
         * categories only, which is what a supplier is mapped to.
         */
        const body = await this.getSupplierCategories()

        /*
         * The admin store actions hand back the whole envelope
         * ({data, status, message}), not the payload -- unlike
         * common/getRequest, which unwraps. Reading `body.categories` here
         * silently gave undefined: the picker had no options to offer and
         * every country fell back to the generic 4-14 digit rule.
         */
        const data = (body && body.data) || {}

        this.categoryList = data.categories || []
        this.phoneRules = data.phone_rules || {}
        this.phoneDefault = data.phone_default || DEFAULT_PHONE_RANGE
        this.isoAliases = data.iso_aliases || this.isoAliases
        this.extraNames = data.extra_names || {}
        this.retiredCodes = data.retired || []
        this.dialCodes = data.dial_codes || {}

      } catch (e) {
        this.categoryList = []
      } finally {
        this.loadingCategories = false
      }
    },

    async fetchCountries() {
      if (this.countryList && this.phoneList) {
        return
      }

      this.loadingCountries = true

      try {
        /*
         * getRequest resolves to the payload itself. Destructuring `data`
         * off it -- as one other caller in this panel still does -- yields
         * undefined, and the picker comes up empty with no error.
         */
        const data = await this.getRequest({
          params: null,
          lang: this.langCode,
          api: 'countriesPhones'
        })

        this.setCountryList(data && data.countries)
        this.setPhoneList(data && data.phones)

      } finally {
        this.loadingCountries = false
      }
    }
  }
}
</script>

<style scoped>
/*
 * Built on the tokens in assets/styles/variables.styl rather than on literals.
 * The first version of this form set its own 4px radius, #ddd borders and
 * 10px padding, which put every control a few pixels out of step with the
 * rest of the admin -- and base.styl loads last, so half of it lost anyway.
 */

.form-section {
  padding-bottom: 24px;
  margin-bottom: 24px;
  border-bottom: 1px solid var(--border-color);
}

.form-section:last-child {
  padding-bottom: 0;
  margin-bottom: 0;
  border-bottom: 0;
}

.section-head {
  margin-bottom: 18px;
}

.section-head h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--text-strong);
}

.section-head p {
  margin: 4px 0 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--text-muted);
  max-width: 62ch;
}

/*
 * Two columns for the short fields. Name/Email and Country/Phone are read as
 * pairs, and a single column of full-width boxes made a six-field form scroll.
 */
.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px 20px;
}

.field--wide {
  grid-column: 1 / -1;
}

@media (max-width: 760px) {
  .field-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

.field {
  min-width: 0;
}

.field label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-color);
}

.req {
  color: var(--danger-text);
  font-weight: 600;
}

.count-badge {
  margin-left: auto;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary-color);
  font-size: 11px;
  font-weight: 600;
}

/*
 * Height, radius and padding come from base.styl so these match every other
 * input in the admin; only the states below are set here.
 */
.field textarea {
  min-height: 84px;
  padding: 12px 15px;
  line-height: 1.5;
  resize: vertical;
}

.field input.invalid,
.field textarea.invalid {
  border-color: var(--danger-text);
}

.field input.invalid:focus,
.field textarea.invalid:focus {
  border-color: var(--danger-text);
  box-shadow: 0 0 0 3px var(--danger-bg);
}

.field input:focus,
.field textarea:focus {
  box-shadow: 0 0 0 3px var(--primary-soft);
}

.error,
.hint {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.45;
}

.error {
  color: var(--danger-text);
}

.hint {
  color: var(--text-muted);
}

.field-foot {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.counter {
  margin-top: 6px;
  font-size: 12px;
  color: var(--text-soft);
  white-space: nowrap;
}

.counter.near {
  color: var(--warning-text);
  font-weight: 600;
}

/* Country badge and phone -------------------------------------------- */

/*
 * A real SVG from static/flags rather than an emoji: Windows has no colour
 * flag glyphs and renders a regional indicator pair as the two letters, so
 * the emoji version showed "IE" to most of this panel's audience.
 *
 * The hairline border keeps flags that are white at the edge (Japan, Poland,
 * Switzerland) from dissolving into the row.
 */
.flag {
  flex: none;
  width: 22px;
  height: 16px;
  border-radius: 2px;
  object-fit: cover;
  box-shadow: 0 0 0 1px rgba(17, 24, 39, .10);
  background: var(--hover-color);
}

/* No flag file for this code, or it failed to load. */
.flag--empty {
  display: inline-block;
  box-shadow: inset 0 0 0 1px var(--border-color);
}

.country-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dial {
  margin-left: auto;
  padding-left: 12px;
  color: var(--text-soft);
  font-variant-numeric: tabular-nums;
}

/*
 * The dialling code is derived from the country, so it is shown as a fixed
 * prefix rather than as a second input that could contradict the first.
 */
.phone-row {
  display: flex;
  align-items: stretch;
  height: 40px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--bg-color);
  overflow: hidden;
  transition: border-color .15s, box-shadow .15s;
}

.phone-row:focus-within {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px var(--primary-soft);
}

.phone-row.invalid {
  border-color: var(--danger-text);
}

.phone-row.invalid:focus-within {
  box-shadow: 0 0 0 3px var(--danger-bg);
}

.phone-row.disabled {
  background: var(--hover-color);
}

.phone-prefix {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
  padding: 0 12px;
  background: var(--page-bg);
  border-right: 1px solid var(--border-color);
  font-size: 13px;
  color: var(--text-color);
}

.dial-code {
  font-variant-numeric: tabular-nums;
}

/*
 * The inner input gives up its own border and radius to the row, which is
 * what draws the box -- otherwise base.styl's input border shows through as
 * a second outline inside the prefix.
 */
.phone-row input {
  flex: 1;
  min-width: 0;
  height: 100%;
  border: 0;
  border-radius: 0;
  background: transparent;
}

.phone-row input:focus {
  border: 0;
  box-shadow: none;
}

.phone-row input:disabled {
  cursor: not-allowed;
}

/* vue-multiselect ----------------------------------------------------- *
 *
 * ::v-deep because the control's internals belong to the child component's
 * scope. The injected [data-v-hash] counts toward specificity, so each rule
 * is written one level deeper than the library's own -- base.styl loads
 * after this file and a tie there loses.
 */

.field ::v-deep .multiselect {
  min-height: 40px;
  color: var(--text-color);
}

.field ::v-deep .multiselect__tags {
  min-height: 40px;
  padding: 7px 40px 3px 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--bg-color);
  font-size: 14px;
  transition: border-color .15s, box-shadow .15s;
}

.field ::v-deep .multiselect--active .multiselect__tags {
  border-color: var(--primary-color);
  box-shadow: 0 0 0 3px var(--primary-soft);
}

.field .invalid ::v-deep .multiselect__tags {
  border-color: var(--danger-text);
}

.field .invalid.multiselect--active ::v-deep .multiselect__tags {
  box-shadow: 0 0 0 3px var(--danger-bg);
}

.field ::v-deep .multiselect__placeholder {
  margin: 0 0 2px;
  padding: 2px 0;
  color: var(--text-soft);
  font-size: 14px;
}

/*
 * base.styl styles every input by element name -- border, 40px height,
 * radius, padding -- and the search box inside a multiselect is an input.
 * Left alone it draws a second bordered box inside the control. These have
 * to be reset explicitly: a rule that merely omits `border` inherits it.
 */
.field ::v-deep .multiselect__input,
.field ::v-deep .multiselect__single {
  min-height: 22px;
  height: auto;
  margin: 0;
  padding: 2px 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  font-size: 14px;
  line-height: 22px;
}

.field ::v-deep .multiselect__input:focus {
  border: 0;
  box-shadow: none;
}

.field ::v-deep .multiselect__select:before {
  border-top-color: var(--text-soft);
}

/*
 * The chips. This area was replaced wholesale by a "Choose (3)" count
 * through the #selection slot, so the one thing the control is for -- seeing
 * what is selected and taking one off -- was hidden behind a number.
 */
.field ::v-deep .multiselect__tag {
  display: inline-flex;
  align-items: center;
  margin: 0 6px 4px 0;
  padding: 4px 26px 4px 10px;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary-color);
  font-size: 12.5px;
  font-weight: 600;
  line-height: 18px;
}

.field ::v-deep .multiselect__tag-icon {
  width: 20px;
  border-radius: 999px;
  line-height: 20px;
  transition: background .15s;
}

.field ::v-deep .multiselect__tag-icon:after {
  color: var(--primary-color);
  font-size: 16px;
}

.field ::v-deep .multiselect__tag-icon:hover {
  background: var(--primary-color);
}

.field ::v-deep .multiselect__tag-icon:hover:after {
  color: var(--primary-foreground);
}

.field ::v-deep .multiselect__content-wrapper {
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  box-shadow: 0 10px 28px rgba(17, 24, 39, .12);
  margin-top: 4px;
  overflow-x: hidden;
}

/* A thin scrollbar instead of the OS default slab down the side. */
.field ::v-deep .multiselect__content-wrapper {
  scrollbar-width: thin;
  scrollbar-color: var(--border-strong) transparent;
}

.field ::v-deep .multiselect__content-wrapper::-webkit-scrollbar {
  width: 10px;
}

.field ::v-deep .multiselect__content-wrapper::-webkit-scrollbar-thumb {
  border: 3px solid transparent;
  border-radius: 999px;
  background: var(--border-strong);
  background-clip: content-box;
}

.field ::v-deep .multiselect__content-wrapper::-webkit-scrollbar-thumb:hover {
  background: var(--text-soft);
  background-clip: content-box;
}

.field ::v-deep .multiselect__option {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 0;
  padding: 8px 12px;
  font-size: 14px;
  line-height: 20px;
}

/* Separates the rows without drawing a line under the last one. */
.field ::v-deep .multiselect__element + .multiselect__element .multiselect__option {
  border-top: 1px solid var(--border-color);
}

.field ::v-deep .multiselect__option--highlight,
.field ::v-deep .multiselect__option--highlight:after {
  background: var(--primary-soft);
  color: var(--primary-color);
  font-weight: 600;
}

.field ::v-deep .multiselect__option--highlight {
  box-shadow: inset 3px 0 0 var(--primary-color);
}

.field ::v-deep .multiselect__option--highlight .dial {
  color: var(--primary-color);
}

.field ::v-deep .multiselect__option--selected {
  background: var(--primary-soft);
  color: var(--primary-color);
  font-weight: 600;
}

/*
 * Hovering something already chosen means "click to take this off", so it is
 * tinted rather than filled: the library's solid red reads as a failure on a
 * row that is merely selected.
 */
.field ::v-deep .multiselect__option--selected.multiselect__option--highlight,
.field ::v-deep .multiselect__option--selected.multiselect__option--highlight:after {
  background: var(--danger-bg);
  color: var(--danger-text);
  font-weight: 600;
}

/* One value, so no chip row to make space for. */
.country-multiselect ::v-deep .multiselect__tags {
  padding: 0 34px 0 12px;
  display: flex;
  align-items: center;
}

.country-multiselect ::v-deep .multiselect__single {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin: 0;
  padding: 0;
}
</style>
