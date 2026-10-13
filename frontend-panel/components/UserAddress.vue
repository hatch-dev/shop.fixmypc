<template>
  <div class="address-wrapper" :class="{ 'address-wrapper-invalid': invalid }">
    <!--
      The list failing to load used to take the whole route with it, which on
      the basket page meant the cart disappeared behind the error screen. It
      is reported here instead, beside the "Add new address" card that is
      still a perfectly good way forward.
    -->
    <p
      v-if="addressError && !fetchingAddressData"
      class="address-load-error"
      role="alert"
    >
      <span>{{ addressError }}</span>

      <button
        type="button"
        class="address-load-retry"
        @click.prevent="fetchingData"
      >
        Try again
      </button>
    </p>

    <div
      class="address-list"
      :role="hasRadio ? 'radiogroup' : null"
      aria-label="Delivery addresses"
    >
      <!--
        Placeholder cards inside the list rather than a spinner above it. This
        component sits in the middle of checkout, where a spinner on top of a
        collapsed list makes the pay button jump up the page and then back
        down again as the addresses land. Cards that are already the right
        size do not move anything.

        Only when there is nothing to show yet. The list lives in the store,
        so coming back to the page (or reloading after a save) already has
        the real cards on screen; adding two blank ones beside them for the
        seconds the refresh takes looked like two empty addresses.

        As many as this shopper had last time, not a fixed two: an account
        with no addresses flashed two empty cards on every reload that then
        vanished. None when the last count was zero, one when it is unknown.
      -->
      <template v-if="fetchingAddressData && !currentAddresses.length && placeholderCount > 0">
        <div
          v-for="index in placeholderCount"
          :key="`sk-address-${index}`"
          class="address-item"
        >
          <div class="address-card">
            <div class="address-card-body">
              <skeleton-base :height="15" width="45%" :mb="14" />
              <skeleton-text :lines="3" :line-height="12" :gap="9" last-width="55%" />
            </div>
          </div>
        </div>
      </template>

      <div
        v-for="(value, key) in currentAddresses"
        :key="key"
        class="address-item"
      >
        <div
          class="address-card"
          :class="{ 'is-selected': selectedAddress === key, 'is-selectable': hasRadio }"
          :role="hasRadio ? 'radio' : null"
          :aria-checked="hasRadio ? String(selectedAddress === key) : null"
          :tabindex="hasRadio ? 0 : null"
          @click="selectAddress(key)"
          @keydown.enter.prevent="selectAddress(key)"
          @keydown.space.prevent="selectAddress(key)"
        >
          <!--
            The selection marker sits inside the card, on the reading edge.
            It used to be a badge hung off the top-right corner on negative
            offsets, which clipped against the card border and told you nothing
            until after you had already picked something.
          -->
          <span
            v-if="hasRadio"
            class="address-marker"
            aria-hidden="true"
          >
            <svg viewBox="0 0 24 24" focusable="false">
              <path d="M6 12.4 10.2 16.5 18 8.5" />
            </svg>
          </span>

          <div class="address-card-body">
            <div class="address-card-top">
              <div class="address-name-line">
                <strong class="address-name">{{ value.name }}</strong>

                <span class="address-chips">
                  <span class="address-type-pill">
                    {{ addressTypeLabel(value.type) }}
                  </span>

                  <span
                    v-if="parseInt(value.default) === 1"
                    class="address-default-pill"
                  >
                    Default
                  </span>
                </span>
              </div>

              <div class="address-card-actions">
                <button
                  type="button"
                  class="address-action-button"
                  aria-label="Edit address"
                  title="Edit"
                  @click.stop.prevent="editAddress(value)"
                >
                  <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                    <path d="M4 20h4.2l10-10a2 2 0 0 0 0-2.8l-1.4-1.4a2 2 0 0 0-2.8 0l-10 10V20Z" />
                  </svg>
                  <span class="address-action-label">Edit</span>
                </button>

                <button
                  type="button"
                  class="address-action-button is-danger"
                  :disabled="ajaxDeleting === value.id"
                  aria-label="Delete address"
                  title="Delete"
                  @click.stop.prevent="deleting(value)"
                >
                  <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                    <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 7h2v8h-2v-8Zm4 0h2v8h-2v-8ZM7 8h10l-.7 12H7.7L7 8Z" />
                  </svg>
                </button>
              </div>
            </div>

            <div class="address-copy">
              <p class="address-lines" v-html="formatAddress(value)" />

              <p v-if="value.phone" class="address-phone">
                <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                  <path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.4 11.4 0 0 0 .57 3.6 1 1 0 0 1-.25 1l-2.22 2.2Z" />
                </svg>
                <span>{{ value.phone }}</span>
              </p>

              <p v-if="manage && value.delivery_instruction" class="address-note">
                {{ value.delivery_instruction }}
              </p>
            </div>

            <!-- Address book only: at checkout, picking the card is the choice. -->
            <button
              v-if="manage && parseInt(value.default) !== 1"
              type="button"
              class="address-make-default"
              :disabled="ajaxDefaulting === value.id"
              @click.stop.prevent="setAsDefault(value)"
            >
              {{ ajaxDefaulting === value.id ? 'Saving…' : 'Set as default' }}
            </button>
          </div>
        </div>
      </div>

      <div
        class="address-item address-add-item"
        :class="{ 'address-add-pinned': currentAddresses.length >= 2 }"
      >
        <button
          type="button"
          class="address-add-card"
          @click.prevent="toggleAddressForm"
        >
          <span class="address-add-icon" aria-hidden="true">+</span>
          <span>
            Add new address
          </span>
        </button>
      </div>
    </div>

    <transition name="slide-fade">
      <section
        v-if="showAddressForm"
        id="address-form"
        class="address-form-panel"
      >
        <h3 class="address-form-title">
          Complete your address below
        </h3>

        <form class="address-form" @submit.prevent="savingAddressData">
          <div class="address-form-grid">
            <div class="address-field">
              <label for="address-full-name">Full Name <span class="req">*</span></label>
              <input
                id="address-full-name"
                v-model="fullName"
                type="text"
                class="address-input"
                autocomplete="name"
                placeholder="Enter name"
                :class="{ 'field-invalid': showError('name') }"
              >
              <p v-if="showError('name')" class="address-error">{{ fieldErrors.name }}</p>
            </div>

            <div class="address-field">
              <label for="address-email">Email Address <span class="req">*</span></label>
              <input
                id="address-email"
                v-model.trim="addressData.email"
                type="email"
                class="address-input"
                autocomplete="email"
                inputmode="email"
                placeholder="Enter email"
                :class="{ 'field-invalid': showError('email') }"
              >
              <p v-if="showError('email')" class="address-error">{{ fieldErrors.email }}</p>
            </div>

            <!-- the flag picker is the country control: choosing one sets the
                 delivery country below and reloads its counties -->
            <div class="address-field">
              <label for="address-phone">Contact Number <span class="req">*</span></label>
              <phone-field
                :value="addressData.phone"
                :names="countryList"
                :default-country="addressData.country || 'IE'"
                :is-invalid="showError('phone')"
                @change="onPhoneChange"
                @blur="phoneTouched = true"
              />
              <p v-if="showError('phone')" class="address-error">{{ fieldErrors.phone }}</p>
            </div>

            <div class="address-field">
              <label for="address-country">Country <span class="req">*</span></label>
              <!--
                Follows the flag on the contact number until it is picked
                here; either way it goes through applyCountry, which clears
                the county, town and postcode under a real change.
              -->
              <div class="address-select-wrap">
                <select
                  id="address-country"
                  :value="addressData.country"
                  class="address-select"
                  @change="applyCountry($event.target.value)"
                  :class="{ placeholder: !addressData.country, 'field-invalid': showError('country') }"
                >
                  <option value="">
                    Select country
                  </option>
                  <option
                    v-for="country in countryOptions"
                    :key="country.code2"
                    :value="country.code2"
                  >
                    {{ country.name }}
                  </option>
                </select>
              </div>
              <p v-if="showError('country')" class="address-error">{{ fieldErrors.country }}</p>
              <p v-else class="address-hint">Follows the flag on your contact number unless you pick one here.</p>
            </div>

            <div class="address-field">
              <label for="address-line-1">Address 1 <span class="req">*</span></label>
              <input
                id="address-line-1"
                v-model="addressData.address_1"
                type="text"
                class="address-input"
                autocomplete="address-line1"
                placeholder="Enter address"
                :class="{ 'field-invalid': showError('address_1') }"
              >
              <p v-if="showError('address_1')" class="address-error">{{ fieldErrors.address_1 }}</p>
            </div>

            <div class="address-field">
              <label for="address-line-2">Address 2</label>
              <input
                id="address-line-2"
                v-model="addressData.address_2"
                type="text"
                class="address-input"
                autocomplete="address-line2"
                placeholder="Apartment, unit, floor (optional)"
              >
            </div>

            <!--
              County, town and postcode read down in the order they depend on
              each other. One control, not two: the free-text "County"
              duplicated this list, which for Ireland already is the 26
              counties, and the name of whatever is picked is what gets
              stored as county.
            -->
            <div class="address-field">
              <label for="address-state">
                {{ regionLabel }}
                <span v-if="hasRegions" class="req">*</span>
              </label>
              <div class="address-select-wrap">
                <select
                  id="address-state"
                  v-model="addressData.state"
                  class="address-select"
                  :class="{ placeholder: !addressData.state, 'field-invalid': showError('state') }"
                  :disabled="!addressData.country || !hasRegions"
                  @change="onRegionSelect"
                >
                  <option value="">
                    {{ regionPlaceholder }}
                  </option>

                  <option
                    v-for="(state, key) in states"
                    :key="key"
                    :value="state.code"
                  >
                    {{ state.name }}
                  </option>
                </select>
              </div>
              <p v-if="showError('state')" class="address-error">{{ fieldErrors.state }}</p>
            </div>

            <!--
              Picked from the county's towns where we hold a list. No list of
              Irish towns is complete, so the last option always opens a text
              field rather than leaving anyone unable to give their address.
            -->
            <div class="address-field">
              <label :for="hasTownOptions ? 'address-town' : 'address-city'">
                Town / City <span class="req">*</span>
              </label>

              <div v-if="hasTownOptions" class="address-select-wrap">
                <select
                  id="address-town"
                  v-model="townChoice"
                  class="address-select"
                  :class="{ placeholder: !townChoice, 'field-invalid': showError('city') }"
                  :disabled="!townReady"
                >
                  <option value="">
                    {{ townPlaceholder }}
                  </option>
                  <option
                    v-for="town in townOptions"
                    :key="town"
                    :value="town"
                  >
                    {{ town }}
                  </option>
                  <option :value="townOther">
                    Other - not on this list
                  </option>
                </select>
              </div>

              <input
                v-if="!hasTownOptions || townChoice === townOther"
                id="address-city"
                v-model="addressData.city"
                type="text"
                class="address-input"
                :class="{ 'address-town-typed': hasTownOptions, 'field-invalid': showError('city') }"
                autocomplete="address-level2"
                :disabled="!townReady"
                :placeholder="hasTownOptions ? 'Type your town or city' : townPlaceholder"
                @input="townTyped = true"
              >

              <p v-if="showError('city')" class="address-error">{{ fieldErrors.city }}</p>
            </div>

            <div class="address-field">
              <label for="address-eircode">
                {{ postcodeLabel }}
                <span v-if="postcodeRequired" class="req">*</span>
              </label>
              <input
                id="address-eircode"
                v-model="addressData.zip"
                type="text"
                class="address-input eircode-input"
                autocomplete="postal-code"
                :maxlength="postcodeMaxLength"
                :disabled="!postcodeRequired"
                :placeholder="postcodePlaceholder"
                :class="{ 'field-invalid': showError('zip') }"
                @input="onPostcodeInput"
              >
              <p v-if="showError('zip')" class="address-error">{{ fieldErrors.zip }}</p>
              <!--
                An Eircode carries its county in the routing key, so a
                disagreement is worth saying - as a warning, because the key
                list moves and a wrong warning must not cost a delivery.
              -->
              <p v-else-if="postcodeWarning" class="address-warning">{{ postcodeWarning }}</p>
              <p v-else-if="postcodeHint" class="address-hint">{{ postcodeHint }}</p>
            </div>

            <div class="address-field">
              <label for="address-type">Address Type</label>
              <div class="address-select-wrap">
                <select
                  id="address-type"
                  v-model="addressData.type"
                  class="address-select"
                >
                  <option value="home">
                    Home
                  </option>
                  <option value="office">
                    Work
                  </option>
                </select>
              </div>
            </div>

            <!--
              Address book only. The record has always had this column, and the
              old address book form was the one place a customer could fill it.
              The basket keeps the shorter form.
            -->
            <div v-if="manage" class="address-field address-field-wide">
              <label for="address-instructions">Delivery Instructions (optional)</label>
              <textarea
                id="address-instructions"
                v-model="addressData.delivery_instruction"
                class="address-textarea"
                rows="3"
                maxlength="500"
                placeholder="For example: leave with the neighbour at no. 12"
              />
            </div>

            <label class="address-default-check">
              <input
                v-model="addressData.default"
                type="checkbox"
                :true-value="1"
                :false-value="0"
              >
              <span>Set a default delivery address</span>
            </label>

            <div class="address-form-actions">
              <button
                type="submit"
                class="address-save-button"
              >
                {{ editing ? 'Update' : 'Save' }}
              </button>

              <button
                type="button"
                class="address-cancel-button"
                @click="cancelForm"
              >
                Cancel
              </button>
            </div>
          </div>
        </form>
      </section>
    </transition>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import addressHelper from '~/mixin/addressHelper'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import {mapGetters, mapActions} from 'vuex'
  import PhoneField from '~/components/PhoneField'
  import validation from '~/mixin/validation'
  import { parsePhoneNumberFromString } from 'libphonenumber-js'
  /*
   * Country -> county -> town -> postcode. Every rule below that follows from
   * the country lives in the mixin, shared with guest checkout so the two
   * forms cannot disagree about what a valid address is.
   */
  import addressRules, { TOWN_OTHER } from '~/mixin/addressRules'

  export default {
    name: 'UserAddress',
    components: {PhoneField},
    directives: {},
    mixins: [util, addressHelper, routeParamHelper, validation, addressRules],
    props: {
      hasRadio: {
        type: Boolean,
        default: false
      },
      // Set by the page when it has asked for an address and not been given
      // one, so the list itself shows where the answer is wanted.
      invalid: {
        type: Boolean,
        default: false
      },
      /*
       * The account's Address Book rather than checkout: adds "Set as
       * default" on each card and the delivery-instructions field. Same
       * list, same form and same rules otherwise, so the two cannot drift.
       */
      manage: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        knownAddressCount: null,
        addressListLoaded: false,
        ajaxDeleting: 0,
        ajaxDefaulting: 0,
        selectedAddress: -1,
        selectedAddressObj: null,
        showAddressForm: false,
        // a scroll waiting on the list reload to finish - see scrollToAddressList
        pendingListScroll: false,
        addressData: null,
        states: {},
        hasAddressErrors: false,
        submittingAddressData: false,
        phoneValid: false,
        phoneTouched: false,
        // true while a saved address is being loaded into the form, so the
        // country cascade does not clear fields that came off the record
        hydrating: false,
        // the town was typed rather than picked, so the text field stays open
        townTyped: false,
        // the flag emits on every keystroke; this is how we tell an actual
        // country change from the same country arriving again
        lastPhoneCountry: ''
      }
    },
    computed: {
      editing() {
        return this.addressData && this.addressData.id
      },
      fullName: {
        get() {
          if (!this.addressData) {
            return ''
          }

          return this.addressData.name || [this.addressData.first_name, this.addressData.last_name]
            .filter(Boolean)
            .join(' ')
            .trim()
        },
        set(value) {
          if (!this.addressData) {
            return
          }

          this.addressData.name = value
        }
      },
      countryOptions() {
        return Object.values(this.countryList || {})
          .filter(c => c && c.code2 && c.name)
          .sort((a, b) => a.name.localeCompare(b.name))
      },
      /* what the shared rules read to work out everything below the country */
      addressCountry() {
        return (this.addressData?.country || '').toUpperCase()
      },
      addressState() {
        return this.addressData?.state || ''
      },
      addressCity() {
        return this.addressData?.city || ''
      },
      townOther() {
        return TOWN_OTHER
      },
      /*
       * The select and the record hold the same town. A town that is not on
       * the list - one typed here before, or one the list never had - shows
       * as "Other" with the text field open on it, so an address loaded for
       * editing comes back exactly as it was saved.
       */
      townChoice: {
        get() {
          if (this.townTyped) {
            return TOWN_OTHER
          }

          if (!this.addressCity) {
            return ''
          }

          return this.townOptions.includes(this.addressCity) ? this.addressCity : TOWN_OTHER
        },
        set(value) {
          if (!this.addressData) {
            return
          }

          const previous = this.addressCity

          this.townTyped = value === TOWN_OTHER
          this.addressData.city = this.townTyped ? '' : value

          // a different town means a different Eircode - but one typed in
          // before any town was picked is left alone
          if (previous && previous !== this.addressData.city) {
            this.addressData.zip = ''
          }
        }
      },
      postcodeWarning() {
        return this.postcodeRegionWarning(this.addressData?.zip, this.addressState)
      },
      fieldErrors() {
        const data = this.addressData || {}
        const errors = {}
        const name = (this.fullName || '').trim()

        if (!name) {
          errors.name = 'Enter the name for this delivery.'
        } else if (name.length < 2) {
          errors.name = 'That name looks too short.'
        }

        if (!data.email) {
          errors.email = 'Enter an email address.'
        } else if (!this.isValidEmail(data.email)) {
          errors.email = 'That email address is not valid.'
        }

        if (!data.phone) {
          errors.phone = 'Enter a contact number.'
        } else if (!this.phoneValid) {
          errors.phone = 'That number is not valid for the country on the flag.'
        }

        if (!data.country) {
          errors.country = 'Choose a country.'
        }

        if (!(data.address_1 || '').trim()) {
          errors.address_1 = 'Enter the first line of the address.'
        }

        /*
         * From here down each check is only worth making once the level above
         * it has been answered, so a customer is never shown three complaints
         * for the one thing they have not got to yet.
         */
        if (this.hasRegions && !data.state) {
          errors.state = `Choose a ${this.regionLabel.toLowerCase()}.`
        }

        if (this.townReady) {
          if (!(data.city || '').trim()) {
            errors.city = 'Enter a town or city.'
          } else if (!this.isValidTown(data.city)) {
            errors.city = 'That does not look like a town name.'
          }
        }

        // countries with no postal system are never asked for one
        if (this.postcodeReady && this.postcodeRequired) {
          const label = this.postcodeLabel.toLowerCase()

          if (!(data.zip || '').trim()) {
            errors.zip = `Enter ${/^[aeiou]/.test(label) ? 'an' : 'a'} ${label}.`
          } else if (!this.isValidPostcode(data.zip)) {
            errors.zip = this.postcodeExample
              ? `Enter a valid ${label}, for example ${this.postcodeExample}.`
              : `Enter a valid ${label}.`
          }
        }

        return errors
      },
      hasFieldErrors() {
        return Object.keys(this.fieldErrors).length > 0
      },
      totalPage() {
        return this.allAddress?.last_page
      },
      placeholderCount() {
        return this.knownAddressCount === null ? 1 : Math.min(this.knownAddressCount, 3)
      },
      addressCountKey() {
        return `address-count:${this.$auth?.user?.id || 'guest'}`
      },
      currentAddresses() {
        const list = this.allAddress?.data || []
        return [...list].sort((a, b) => {
          return parseInt(b.default) - parseInt(a.default)
        })
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('user', ['allAddress', 'profile']),
      ...mapGetters('common', ['location'])
    },
    watch: {
      // Remember the size of the list once it has really loaded, for the
      // placeholders on the next visit.
      currentAddresses(list) {
        if (this.addressListLoaded && !this.fetchingAddressData && !this.addressError) {
          this.rememberAddressCount(list.length)
        }
      },
      // The list has finished reloading and the placeholder cards have been
      // swapped for real ones, so the layout has stopped moving and a
      // deferred scroll can run.
      fetchingAddressData(now) {
        if (!now && this.pendingListScroll) {
          this.pendingListScroll = false
          this.scrollToAddressList()
        }
      },
      location() {
        this.settingCountry()
      },
      profile(value) {
        if (value && this.addressData) {
          this.addressData.email = value.email || ''
        }
      },
      selectedAddressObj(value) {
        if (this.currentAddresses.length && value) {

          const country = this.countryList?.[value.country]
          const countryName = country?.name
          const stateName = value.state ? country?.states?.[value.state]?.name : ''
          this.$emit('selected-address', {...value, ...{countryTitle: countryName, stateTitle: stateName}})

        } else {

          this.$emit('selected-address', null)
        }
      },
      currentAddresses(value) {
        if (value.length) {
          if (this.hasRadio) {
            this.selectedAddress = 0
            this.selectedAddressObj = value[this.selectedAddress]
          }
        } else {

          this.selectedAddress = -1
          this.selectedAddressObj = null
        }
      },
      selectedAddress(value) {
        this.selectedAddressObj = this.currentAddresses[value]
      }
    },
    methods: {
      // errors stay quiet until the form has been submitted once, except the
      // phone, which reports as soon as the field has been left
      showError(field) {
        if (field === 'phone') {
          return !!this.fieldErrors.phone && (this.hasAddressErrors || this.phoneTouched)
        }
        return !!this.fieldErrors[field] && this.hasAddressErrors
      },
      isPhoneValid(value, country) {
        if (!value) return false
        const parsed = parsePhoneNumberFromString(value, country || 'IE')
        return !!(parsed && parsed.isValid())
      },
      onPhoneChange({ e164, national, country, valid }) {
        if (!this.addressData) return

        this.addressData.phone = e164 || national
        this.phoneValid = valid
        this.phoneTouched = this.phoneTouched || !!national

        // picking a flag sets the delivery country and swaps in its counties.
        // Only on a real change, so a country chosen by hand below is not
        // overwritten on the next keystroke.
        if (country && country !== this.lastPhoneCountry) {
          this.lastPhoneCountry = country
          this.applyCountry(country)
        }
      },
      /*
       * The country is the root of the form: changing it invalidates the
       * county below it, the town below that and the postcode below that, so
       * all three are cleared rather than left holding another country's
       * answer. A county that survives the change - the same country picked
       * again - keeps everything.
       */
      applyCountry(code) {
        if (!this.addressData) return

        /*
         * Loading a saved address is not a change of mind: the flag reading
         * the stored number can report a country of its own, and that must
         * not wipe the town and Eircode already on the record.
         */
        const changed = !this.hydrating && (this.addressData.country || '') !== (code || '')

        this.addressData.country = code || ''
        this.states = code ? (this.countryList?.[code]?.states || {}) : {}

        if (!this.states[this.addressData.state]) {
          this.addressData.state = ''
        }

        if (changed) {
          this.addressData.city = ''
          this.addressData.zip = ''
          this.townTyped = false
        }

        this.syncCounty()
      },
      onRegionSelect() {
        if (!this.addressData) return

        /*
         * A town picked from the old county's list is stale, and its
         * Eircode with it. A town typed in before a county was chosen, or an
         * Eircode given ahead of the town, is the customer's own answer and
         * is kept.
         */
        if (!this.townTyped) {
          if (this.addressData.city) {
            this.addressData.zip = ''
          }
          this.addressData.city = ''
        }

        this.syncCounty()
      },
      // county is a plain name on the record; the select carries the code
      syncCounty() {
        if (!this.addressData) return
        this.addressData.county = this.states?.[this.addressData.state]?.name || ''
      },
      /* Re-spaced as it is typed, in whatever way the country prints it. */
      onPostcodeInput(event) {
        if (!this.addressData) return

        const value = this.formatPostcode(event.target.value)

        this.addressData.zip = value
        event.target.value = value
      },
      // a list shown without radios (the basket) is for reading, not choosing
      selectAddress(key) {
        if (!this.hasRadio) return
        this.selectedAddress = key
      },
      addressTypeLabel(type) {
        const label = (type || 'Home').toString().trim()

        if (!label) {
          return 'Home'
        }

        if (label.toLowerCase() === 'office') {
          return 'Work'
        }

        return label.charAt(0).toUpperCase() + label.slice(1)
      },
      editAddress(value) {
        this.showAddressForm = true

        let firstName = ''
        let lastName = ''

        if (value.name) {
          const parts = value.name.split(' ')
          firstName = parts[0] || ''
          lastName = parts.slice(1).join(' ') || ''
        }

        this.addressData = {
          id: value.id,
          name: value.name || '',
          first_name: firstName,
          last_name: lastName,
          email: value.email || '',
          phone: value.phone || '',
          type: value.type || 'home',
          country: value.country || '',
          state: value.state || '',
          zip: value.zip || '',
          city: value.city || '',
          address_1: value.address_1 || '',
          address_2: value.address_2 || '',
          delivery_instruction: value.delivery_instruction || '',
          // 1/0, not true/false: the checkbox's true-value is 1, and true never matches it
          default: parseInt(value.default) === 1 ? 1 : 0,
          county: value.county || '',
        }

        this.states = this.addressData.country
          ? this.countryList?.[this.addressData.country]?.states || {}
          : {}

        this.lastPhoneCountry = this.addressData.country
        this.phoneTouched = false
        this.phoneValid = this.isPhoneValid(this.addressData.phone, this.addressData.country)
        this.hasAddressErrors = false
        this.hydrating = true
        /*
         * Cleared, not set: townChoice works out on its own that a town off
         * the record is not on the list and opens the text field on it.
         */
        this.townTyped = false
        this.syncCounty()

        this.$nextTick(() => {
          // the flag has reported by now, so the cascade can arm again
          this.hydrating = false
        })

        this.scrollToAddressForm()
      },
      async savingAddressData() {
        this.hasAddressErrors = true
        this.phoneTouched = true

        if (this.hasFieldErrors) {
          this.setToastError('Please correct the highlighted fields.')

          this.$nextTick(() => {
            const el = this.$el.querySelector('.field-invalid')
            if (el) {
              el.scrollIntoView({ behavior: 'smooth', block: 'center' })
              el.focus({ preventScroll: true })
            }
          })

          return
        }

        const nameParts = (this.fullName || '').trim().split(/\s+/).filter(Boolean)
        this.addressData.first_name = nameParts.shift() || ''
        this.addressData.last_name = nameParts.join(' ')
        this.addressData.name = [this.addressData.first_name, this.addressData.last_name]
          .filter(Boolean)
          .join(' ')
        this.addressData.type = this.addressData.type || 'home'

        this.addressData.default = this.addressData.default ? 1 : 0
        await this.addressAction()

        if (!this.hasAddressErrors) {
          this.showAddressForm = false
          this.phoneValid = false
          this.phoneTouched = false
          this.townTyped = false
          this.lastPhoneCountry = ''
          await this.fetchingData()

          this.addressData = {
            id: '',
            name: '',
            first_name: '',
            last_name: '',
            email: this.profile?.email || '',
            phone: '',
            type: 'home',
            country: '',
            state: '',
            city: '',
            address_1: '',
            address_2: '',
            delivery_instruction: '',
            default: 0,
            zip: '',
            county: '',
          }

          this.scrollToAddressList()
        }
      },
      settingCountry() {
        if (!this.addressData) {
          return
        }

        this.addressData.country = this.addressData.country?.trim()
          ? this.addressData.country.trim()
          : this.location?.countryCode || 'IE'

        this.states = this.addressData?.country
          ? this.countryList?.[this.addressData.country]?.states || {}
          : {}

        if (this.addressData.state && this.states[this.addressData.state]) {
          this.addressData.state = this.addressData.state
        } else if (this.location?.region && this.states[this.location.region]) {
          this.addressData.state = this.location.region
        } else {
          this.addressData.state = ''
        }

        this.lastPhoneCountry = this.addressData.country
        this.syncCounty()
      },
      selectedCountry(evt) {
        this.addressData = {...this.addressData, ...{country: evt.value.code2}}
        this.states = evt.value.states
        this.addressData.state = Object.keys(evt.value.states).length ? Object.values(evt.value.states)[0]?.code : ''
      },
      selectedState(evt) {
        this.addressData.state = evt.value.code
      },
      /*
       * Bring the form into view without dropping its top edge under the
       * sticky header - how much room to leave above it is the
       * scroll-margin-top on .address-form-panel. On a fresh form the caret
       * goes into the first field so there is nothing to hunt for; preventScroll
       * keeps that focus from fighting the animation that is still running.
       */
      scrollToAddressForm(focusFirst = false) {
        this.$nextTick(() => {
          const el = document.getElementById('address-form')

          if (!el) {
            return
          }

          this.scrollBelowHeader(el)

          if (!focusFirst) {
            return
          }

          const first = el.querySelector('input, select, textarea')

          if (first && first.focus) {
            first.focus({preventScroll: true})
          }
        })
      },
      /*
       * The form sits below the list, so closing it leaves the viewport parked
       * at the bottom of the section - past the address that was just saved.
       * Send them back to the top of the section, which is where the result is.
       *
       * The section root is the anchor rather than the list, whose own height
       * changes as the placeholder cards give way to the real ones. How much
       * room to leave for the sticky header is CSS's business - see the
       * scroll-margin-top on .address-wrapper.
       */
      scrollToAddressList() {
        /*
         * fetchingData() does its work behind a timer, so right after a save
         * the list is still showing its two placeholder cards. Scrolling now
         * would aim at a layout that is about to be replaced by however many
         * addresses actually came back, and grow or shrink out from under the
         * animation - which is what left the new address tucked up behind the
         * header. Wait for the reload to settle and let the watcher start it.
         */
        if (this.fetchingAddressData) {
          this.pendingListScroll = true
          return
        }

        this.$nextTick(() => {
          const el = this.$el

          if (el) {
            this.scrollBelowHeader(el)
          }
        })
      },
      /*
       * Scroll so el sits just under the sticky site header. The header's
       * height is measured: it carries the promo banner, so it is ~200px on a
       * desktop with the banner and ~160px on a phone, and the fixed
       * scroll-margin-top (150px / 90px) left the form's title under it.
       */
      scrollBelowHeader(el) {
        const header = document.querySelector('header')
        const headerHeight = header && getComputedStyle(header).position === 'sticky'
          ? header.getBoundingClientRect().height
          : 0

        window.scrollTo({
          top: el.getBoundingClientRect().top + window.pageYOffset - headerHeight - 16,
          behavior: 'smooth'
        })
      },
      cancelForm() {
        this.showAddressForm = false
        this.scrollToAddressList()
      },
      toggleAddressForm() {
        this.showAddressForm = !this.showAddressForm
        let firstName = ''
        let lastName = ''
        if (this.profile?.name) {
          const parts = this.profile.name.split(' ')
          firstName = parts[0] || ''
          lastName = parts.slice(1).join(' ') || ''
        }
        if (this.showAddressForm) {
          this.addressData = {
            id: '',
            name: [firstName, lastName].filter(Boolean).join(' '),
            first_name: firstName,
            last_name: lastName,
            email: this.profile?.email || '',
            phone: '',
            type: 'home',
            country: '',
            state: '',
            zip: '',
            city: '',
            address_1: '',
            address_2: '',
            delivery_instruction: '',
            default: 0,
            county: ''
          }
          this.hasAddressErrors = false
          this.phoneValid = false
          this.phoneTouched = false
          this.townTyped = false
          this.lastPhoneCountry = ''
          this.settingCountry()
        }

        if (this.showAddressForm) {
          this.scrollToAddressForm(true)
        }
      },
      rememberAddressCount(count) {
        this.knownAddressCount = count
        try {
          window.localStorage.setItem(this.addressCountKey, String(count))
        } catch (e) {
          // private mode or blocked storage: the placeholders just fall back to one
        }
      },
      async loadData() {
        // Was $refs.addressPagination.routeParam(); there is no such ref here.
        await this.fetchingData()
      },
      /*
       * The whole record goes back with default = 1: the endpoint is the same
       * save the form uses. A refusal arrives as a status in the body, not a
       * rejected promise, so it is checked rather than caught.
       */
      async setAsDefault(address) {
        this.ajaxDefaulting = address.id

        try {
          const data = await this.userAddressAction({
            ...address,
            default: 1,
            user_token: await this.getUserToken()
          })

          if (data?.status === 200) {
            this.setToastMessage('Default address updated')
            await this.fetchingData()
          } else {
            this.setToastError(this.addressFailureMessage(data, 'We could not change your default address.'))
          }
        } catch (e) {
          this.setToastError('We could not change your default address.')
        } finally {
          this.ajaxDefaulting = 0
        }
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('user', ['userAddressAll', 'userAddressDelete', 'userAddressAction', 'getUserToken']),
    },
    destroyed() {
    },
    async mounted() {
      try {
        const saved = window.localStorage.getItem(this.addressCountKey)
        this.knownAddressCount = saved === null ? null : Math.max(0, parseInt(saved, 10) || 0)
      } catch (e) {
        this.knownAddressCount = null
      }

      if (!this.countryList || !this.phoneList) {
        this.fetchingAddressData = true

        const {data} = await this.getRequest({
          params: null,
          lang: this.langCode,
          api: 'countriesPhones'
        })

        this.setCountryList(data?.countries)
        this.setPhoneList(data?.phones)
        this.fetchingAddressData = false
      }

      await this.fetchingData()

      this.addressListLoaded = true
      if (!this.addressError) {
        this.rememberAddressCount(this.currentAddresses.length)
      }

      let firstName = ''
      let lastName = ''

      if (this.profile?.name) {
        const parts = this.profile.name.split(' ')
        firstName = parts[0] || ''
        lastName = parts.slice(1).join(' ') || ''
      }

      this.addressData = {
        id: '',
        name: [firstName, lastName].filter(Boolean).join(' '),
        first_name: firstName,
        last_name: lastName,
        email: this.profile?.email || '',
        phone: '',
        type: 'home',
        country: '',
        state: '',
        zip: '',
        city: '',
        address_1: '',
        address_2: '',
        delivery_instruction: '',
        default: 0,
        county: ''
      }

      if (this.showAddressForm) {
        this.scrollToAddressForm()
      }

      this.settingCountry()
    }
  }
</script>

<style scoped>
.address-load-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin: 0 0 14px;
  padding: 12px 14px;
  border: 1px solid #f0c7c2;
  border-radius: 10px;
  background: #fdf3f2;
  color: #8a2a1d;
  font-size: 14px;
  line-height: 1.4;
}
.address-load-retry {
  border: 1px solid currentColor;
  border-radius: 8px;
  background: transparent;
  color: inherit;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  padding: 5px 12px;
}
.address-load-retry:hover {
  background: rgba(138, 42, 29, .08);
}

.address-wrapper {
  width: 100%;
  max-width: none;
  padding-right: 0 !important;
  padding-left: 0 !important;
  color: #151438;
  /* the sticky site header is about 125px on desktop; land clear of it rather
     than behind it */
  scroll-margin-top: 150px;
}
.address-wrapper-invalid .address-list {
  border-radius: 12px;
  box-shadow: 0 0 0 2px #f2c4c4;
}

.address-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
  margin-top: 20px;
}

.address-item {
  min-height: 0;
}

.address-card,
.address-add-card {
  width: 100%;
  height: 100%;
  max-width: 100%;
  border: 1px solid #e3e6f2;
  border-radius: 14px;
  background: #fff;
}

.address-card {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  cursor: default;
  outline: none;
  transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
}

.address-card.is-selectable {
  cursor: pointer;
}

.address-card.is-selectable:hover {
  border-color: #b9bce4;
  box-shadow: 0 2px 10px rgba(21, 20, 56, .06);
}

.address-card:focus-visible {
  border-color: #35329c;
  box-shadow: 0 0 0 3px rgba(53, 50, 156, .16);
}

.address-card.is-selected {
  border-color: #35329c;
  background: #f7f8ff;
  box-shadow: 0 0 0 1px #35329c;
}

.address-card-body {
  flex: 1 1 auto;
  min-width: 0;
}

/* The radio-style marker: an empty ring until the card is chosen. */
.address-marker {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  margin-top: 1px;
  border: 1.5px solid #c7cbe4;
  border-radius: 50%;
  background: #fff;
  transition: border-color .18s ease, background-color .18s ease;
}

.address-marker svg {
  width: 12px;
  height: 12px;
  fill: none;
  stroke: #fff;
  stroke-width: 2.6;
  stroke-linecap: round;
  stroke-linejoin: round;
  opacity: 0;
  transition: opacity .18s ease;
}

.address-card.is-selectable:hover .address-marker {
  border-color: #35329c;
}

.address-card.is-selected .address-marker {
  border-color: #35329c;
  background: #35329c;
}

.address-card.is-selected .address-marker svg {
  opacity: 1;
}

.address-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.address-name-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 10px;
  min-width: 0;
}

.address-name {
  overflow: hidden;
  min-width: 0;
  max-width: 100%;
  color: #10102d;
  font-size: 15px;
  font-weight: 600;
  letter-spacing: -.01em;
  line-height: 1.25;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.address-chips {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 6px;
}

/* Chips label the address; they should not outshout the name it belongs to. */
.address-type-pill,
.address-default-pill {
  display: inline-flex;
  align-items: center;
  height: 21px;
  padding: 0 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .01em;
  line-height: 1;
  white-space: nowrap;
}

.address-type-pill {
  background: #eceefb;
  color: #35329c;
}

.address-default-pill {
  border: 1px solid #d7dae9;
  background: #fff;
  color: #63678a;
}

.address-card-actions {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 6px;
}

.address-action-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  height: 30px;
  padding: 0 10px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: #5c6084;
  font-size: 12.5px;
  font-weight: 500;
  line-height: 1;
  cursor: pointer;
  transition: border-color .18s ease, background-color .18s ease, color .18s ease;
}

.address-action-button svg {
  width: 15px;
  height: 15px;
  fill: currentColor;
}

.address-action-button.is-danger {
  width: 30px;
  padding: 0;
}

.address-action-button:hover,
.address-action-button:focus-visible {
  border-color: #d7dae9;
  background: #fff;
  color: #35329c;
  outline: none;
}

.address-action-button.is-danger:hover,
.address-action-button.is-danger:focus-visible {
  border-color: #f0c8cc;
  background: #fdf5f6;
  color: #d13f4a;
}

.address-action-button:disabled {
  cursor: wait;
  opacity: .5;
}

.address-copy {
  margin-top: 8px;
  color: #545879;
  font-size: 13.5px;
  line-height: 1.5;
}

.address-copy p {
  margin: 0;
}

.address-lines {
  overflow-wrap: anywhere;
}

.address-phone {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  color: #6a6e8f;
  font-size: 13px;
}

.address-phone svg {
  flex: 0 0 auto;
  width: 13px;
  height: 13px;
  fill: currentColor;
  opacity: .75;
}

.address-add-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 128px;
  padding: 20px 14px;
  border-style: dashed;
  border-color: #d6dae9;
  background: #fcfcff;
  color: #4a4e73;
  font-size: 13.5px;
  font-weight: 500;
  line-height: 1.35;
  text-align: center;
  cursor: pointer;
  transition: border-color .18s ease, background-color .18s ease, color .18s ease;
}

.address-add-card:hover,
.address-add-card:focus-visible {
  border-color: #35329c;
  background: #f7f8ff;
  color: #35329c;
  outline: none;
}
.address-add-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border: 1px solid #dde0ed;
  border-radius: 50%;
  background: #fff;
  color: inherit;
  font-size: 26px;
  font-weight: 300;
  line-height: 0;
  transition: border-color .18s ease;
}

.address-add-card:hover .address-add-icon,
.address-add-card:focus-visible .address-add-icon {
  border-color: #35329c;
}

.address-form-panel {
  width: 100%;
  margin-top: 7px;
  padding: 24px 26px;
  border: 1px solid #dfe3ef;
  border-radius: 12px;
  background: #fff;
  margin-top: 20px;
  /* the site header is sticky, so scrolling the panel flush to the top of the
     viewport would tuck its title and first field underneath it */
  scroll-margin-top: 150px;
}

.address-form-title {
  margin: 0 0 22px;
  color: #1d1d57;
  font-size: 18px;
  font-weight: 500;
  line-height: 1.3;
}

.address-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  column-gap: 18px;
  row-gap: 13px;
}

.address-field {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.address-field label {
  margin-bottom: 8px;
  color: rgba(19, 14, 43, 1);
  font-size: 14px;
  font-weight: 500;
  line-height: 1.2;
}

.address-input,
.address-select {
  width: 100%;
  height:55px !important;
  border: 1px solid #d8dce8;
  border-radius: 8px;
  background: #fff;
  color: #000;
  font-size: 14px;
  outline: none;
  transition: border-color .2s ease, box-shadow .2s ease;
}

.address-input,
.address-select {
  padding: 0 16px;
}

.address-input::placeholder {
  color: #8d91a6;
  opacity: 1;
}

.address-input:focus,
.address-select:focus {
  border-color: #35329c;
}

.field-invalid {
  border-color: #d83d4a;
}

.req {
  color: #d83d4a;
  font-weight: 600;
}

.address-error,
.address-warning,
.address-hint {
  margin: 6px 0 0;
  font-size: 12.5px;
  line-height: 1.35;
}

.address-error {
  color: #d83d4a;
}

/* the Eircode disagrees with the county, but the order is not being refused */
.address-warning {
  color: #a16207;
}

/* an Eircode is printed in caps and read character by character */
.eircode-input {
  text-transform: uppercase;
  letter-spacing: .04em;
}

.address-hint {
  color: #8d91a6;
}

/* the text field that opens under the town select on "Other" */
.address-town-typed {
  margin-top: 8px;
}

/* a level of the hierarchy that its parent has not opened yet */
.address-select:disabled,
.address-input:disabled {
  background: #f4f5fa;
  color: #8d91a6;
  cursor: not-allowed;
}

/*
 * PhoneField is a shared component with its own scoped styles, sized for the
 * auth pages. It reads its palette from these custom properties, which
 * inherit straight through, and the two heights it pins are matched here so
 * the control lines up with the inputs either side of it.
 */
.address-form-panel {
  --auth-radius: 8px;
  --auth-line: #edf0f7;
  --auth-line-strong: #d8dce8;
  --auth-brand: #35329c;
  --auth-brand-50: #f1f1fc;
  --auth-danger: #d83d4a;
  --auth-ink: #151438;
  --auth-muted: #8d91a6;
  --auth-wash: #f7f7fd;
}

.address-form-panel >>> .pf-control {
  height: 55px;
}

.address-form-panel >>> .pf-country,
.address-form-panel >>> .pf-input {
  height: 53px;
}

.address-form-panel >>> .pf-input {
  font-size: 14px;
}

.address-select-wrap {
  position: relative;
}

.address-select-wrap::after {
  position: absolute;
  top: 50%;
  right: 18px;
  width: 8px;
  height: 8px;
  border-right: 1.5px solid #767b9a;
  border-bottom: 1.5px solid #767b9a;
  content: "";
  pointer-events: none;
  transform: translateY(-65%) rotate(45deg);
}

.address-select {
  appearance: none;
  padding-right: 42px;
}

/* ---------- address book extras (manage) ---------- */

.address-field-wide {
  grid-column: 1 / -1;
}

.address-textarea {
  width: 100%;
  min-height: 88px;
  padding: 12px 16px;
  border: 1px solid #e3e6f2;
  border-radius: 10px;
  font-size: 14px;
  color: #130e2b;
  resize: vertical;
}

.address-textarea:focus {
  outline: none;
  border-color: #35329c;
  box-shadow: 0 0 0 3px rgba(53, 50, 156, .12);
}

.address-note {
  margin: 8px 0 0;
  font-size: 12.5px;
  color: #63678a;
  font-style: italic;
  overflow-wrap: anywhere;
}

/*
 * Sits under the address copy, not in the icon row: it is a text action and
 * the corner is already Edit and Delete. Scoped to the card so the global
 * button metrics (50px / line-height 48px) are overridden here.
 */
.address-card .address-make-default {
  display: inline-flex;
  align-items: center;
  height: 30px;
  line-height: 1;
  margin-top: 12px;
  padding: 0 12px;
  border: 1px solid #d7dae9;
  border-radius: 8px;
  background: #fff;
  color: #35329c;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}

.address-card .address-make-default:hover:enabled {
  border-color: #35329c;
  background: #f5f5ff;
}

.address-card .address-make-default:disabled {
  opacity: .6;
  cursor: wait;
}

.address-default-check {
  grid-column: 1 / -1;
  display: inline-flex;
  align-items: center;
  gap: 9px;
  width: fit-content;
  margin-top: -1px;
  color: #10102d;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.2;
  cursor: pointer;
}

.address-default-check input {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: #35329c;
}

.address-form-actions {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}

.address-save-button,
.address-cancel-button {
  min-width: 76px;
  height: 30px;
  padding: 0 18px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 28px;
  cursor: pointer;
  transition: border-color .2s ease, background-color .2s ease, color .2s ease;
}

.address-save-button {
  border: 1px solid rgba(51, 49, 153, 1);
  background: rgba(51, 49, 153, 1);
  color: #fff;
}

.address-save-button:hover,
.address-save-button:focus-visible {
  border-color: #35329c;
  background: #35329c;
  outline: none;
}

.address-cancel-button {
  border: 1px solid #35329c;
  background: #fff;
  color: #35329c;
}

.address-cancel-button:hover,
.address-cancel-button:focus-visible {
  background: #f2f3ff;
  outline: none;
}

.fade-enter-active,
.fade-leave-active,
.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: opacity .2s ease, transform .2s ease;
}

.fade-enter,
.fade-leave-to,
.slide-fade-enter,
.slide-fade-leave-to {
  opacity: 0;
}

.slide-fade-enter,
.slide-fade-leave-to {
  transform: translateY(8px);
}

@media (max-width: 768px) {
 .address-form-panel {
    padding: 15px;
    /* the header collapses to a single row on a phone */
    scroll-margin-top: 90px;
  }

  .address-wrapper {
    scroll-margin-top: 90px;
  }

  .address-form-grid {
    grid-template-columns: 1fr;
    row-gap: 11px;
  }
  .address-list{
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  /* The whole form steps down a size on a phone. */
  .address-form-title {
    margin-bottom: 14px;
    font-size: 15px;
  }
  .address-field label {
    margin-bottom: 5px;
    font-size: 12px;
  }
  .address-input,
  .address-select {
    height: 44px !important;
    padding: 0 12px;
    font-size: 14px;
  }
  .address-select {
    padding-right: 36px;
  }
  .address-select-wrap::after {
    right: 14px;
  }
  .address-error,
  .address-warning,
  .address-hint {
    margin-top: 4px;
    font-size: 11px;
  }
  .address-default-check {
    font-size: 11.5px;
  }
  .address-add-card {
    font-size: 14px;
  }
  .address-save-button,
  .address-cancel-button {
    flex: 1 1 0;
    height: 42px;
    font-size: 13px;
    line-height: 40px;
  }
  .address-form-actions {
    gap: 10px;
  }

  .address-form-panel >>> .pf-control {
    height: 44px;
  }
  .address-form-panel >>> .pf-country,
  .address-form-panel >>> .pf-input {
    height: 42px;
  }
  .address-form-panel >>> .pf-country {
    padding: 0 8px 0 10px;
    gap: 5px;
  }
  .address-form-panel >>> .pf-dial {
    font-size: 12.5px;
  }
  .address-form-panel >>> .pf-input {
    font-size: 14px;
    padding: 0 12px 0 10px;
  }
  /* the picker is wider than the field it hangs off on a narrow screen */
  .address-form-panel >>> .pf-picker {
    min-width: 0;
  }
}
/*
 * iOS Safari zooms the whole page the moment a field with text under 16px
 * takes focus, and it will not zoom back out. That is the only reason these
 * fields were 16px, so the exemption is now scoped to the browsers that
 * actually do it: -webkit-touch-callout is WebKit-on-iOS only, so every other
 * phone keeps the smaller face.
 *
 * The alternative fix - maximum-scale=1 on the viewport - would also stop
 * pinch-zoom for everyone, which is worse than a slightly larger field.
 */
@supports (-webkit-touch-callout: none) {
  @media (max-width: 768px) {
    .address-input,
    .address-select {
      font-size: 16px;
    }

    .address-form-panel >>> .pf-input {
      font-size: 16px;
    }
  }
}
@media (max-width: 600px) {
.address-list{
    display: grid;
    grid-template-columns: repeat(1, minmax(0, 1fr));
}
  .address-form-panel {
    padding: 14px 12px;
  }
}
@media (max-width: 420px) {
  .address-card {
    gap: 10px;
    padding: 14px;
  }

  .address-name {
    font-size: 14.5px;
  }

  /* The Edit label goes; the pencil alone is unambiguous next to a bin, and
     it keeps the name, its chips and both actions on a single row. */
  .address-action-label {
    display: none;
  }

  .address-action-button {
    width: 30px;
    padding: 0;
  }

  .address-form-actions {
    flex-wrap: wrap;
  }
}
</style>
