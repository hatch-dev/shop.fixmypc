<template>
  <div class="row g-3 mb-3">

    <!--
      The old spinner rendered in its own block *above* the page rather than in
      place of it, so the address cards and the "add address" link were on
      screen throughout the load. fetchingAddressData also started false (it is
      declared in the addressHelper mixin), so the very first paint showed an
      empty address book before anything had been requested.
    -->
    <!-- col-12: the root here is a Bootstrap .row, so a bare child becomes an
         auto-width flex item and sits inside the row's negative margins. -->
    <account-skeleton
      v-if="fetchingAddressData"
      class="col-12"
      :panels="2"
      heading
      label="Loading your addresses"
    />

    <template v-else>

    <!--
      A list that will not load is reported here rather than through
      $nuxt.error(), which replaced the whole account page with the error
      screen - see addressError in the addressHelper mixin.
    -->
    <p
      v-if="addressError"
      class="col-12 address-load-error"
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

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-0 ab-title">Address Book</h5>
            <small class="text-muted">Manage your saved addresses for faster checkout.</small>
        </div>
    </div>

    <!-- ADDRESS CARDS -->
    <div class="row mb-3">

      <!-- HOME (DEFAULT) -->
      <div class="col-md-6" v-for="(address, index) in currentAddresses">
          <div class="shipping-address-card position-relative">
              <span v-if="address.default == 1" class="badge-default">
                DEFAULT
              </span> 
              <div class="d-flex align-items-center mb-2">
                  <div class="icon-circle me-2">
                    <i :class="address.type === 'office' ? 'fa-solid fa-building' : 'fa-solid fa-house'" style="color: rgb(19, 14, 43);"></i>
                  </div>
                  <div class="ms-2">
                      <strong>{{ capitalizeFirst(address.type) }}</strong><br>
                      <small class="text-muted">{{ address.name }}</small>
                  </div>
              </div>
              <p class="default-address" v-html="formatAddress(address)"></p>
              <a href="#" class="default-mob">
                📞 {{ address.phone }}
              </a>
              <hr class="dash-hr">
              <div class="d-flex justify-content-between">
                <div>
                  <a
                    href="#"
                    class="address-edit"
                    @click.prevent="editAddress(address)"
                  >
                    <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                  </a>
                  <a
                    href="#"
                    class="address-delete"
                    @click.prevent="userAddressDelete(address.id)"
                  >
                    <i class="fa-regular fa-trash-can me-1"></i> Delete
                  </a>
                </div>
                <a
                  href="#"
                  class="Set-Default"
                  v-if="address.default != 1"
                  @click.prevent="setDefaultAddress(address)"
                >
                  Set as Default
                </a>
              </div>
          </div>
      </div>
    </div>

    <div>
        <a href="#" class="add-address" @click.prevent="toggleAddressForm">
            <span class="plus-icon"><i class="fa-solid fa-plus" style="color: rgb(255, 255, 255);"></i></span> Add New Address
        </a>
    </div>

    <transition name="slide-fade">
      <div
        v-if="showAddressForm"
        id="address-form"
        class="col-12 mt-4"
      >
        <div class="form-container card p-3">
          <h5 class="mb-4">Complete your address below</h5>
            <form @submit.prevent="savingAddressData">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">First Name</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    :class="{ 'is-invalid': !addressData.first_name && hasAddressErrors }"
                    placeholder="Enter First name"
                    v-model="addressData.first_name"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">Last Name</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    :class="{ 'is-invalid': !addressData.last_name && hasAddressErrors }"
                    placeholder="Enter Last name"
                    v-model="addressData.last_name"
                  >
                </div>
                <div class="col-12">
                  <label class="form-label">Email Address</label>
                  <input
                    type="email"
                    class="form-control delivery-filed"
                    placeholder="Enter email"
                    v-model="addressData.email"
                    :class="{ 'is-invalid': (!addressData.email || !isValidEmail(addressData.email)) && hasAddressErrors }"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone Number</label>
                  <div class="input-group">
                    <span class="input-group-text">
                      {{ phoneList[addressData.country] }}
                    </span>
                    <input
                      type="text"
                      class="form-control delivery-filed"
                      placeholder="1234567890"
                      v-model="addressData.phone"
                      :class="{ 'is-invalid': !addressData.phone && hasAddressErrors }"
                    >
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Address Label</label>
                  <select
                    class="form-select delivery-filed"
                    v-model="addressData.type"
                    :class="{ 'is-invalid': !addressData.type && hasAddressErrors }"
                  >
                    <option value="">Select Address Type</option>
                    <option value="home">Home</option>
                    <option value="office">Office</option>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Street Address</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Street address or P.O. Box"
                    v-model="addressData.address_1"
                    :class="{ 'is-invalid': !addressData.address_1 && hasAddressErrors }"
                  >
                </div>
                <div class="col-12">
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Apt, suite, unit, building, floor, etc. (optional)"
                    v-model="addressData.address_2"
                  >
                </div>
                <!--
                  Country, county, town and postcode read in the order they
                  depend on each other. This select was labelled "County"
                  while listing countries, so the two controls under it read
                  as duplicates of each other rather than as its children.
                -->
                <div class="col-md-6">
                  <label class="form-label">Country</label>
                  <select
                    class="form-select delivery-filed"
                    v-model="addressData.country"
                    @change="onCountryChange"
                    :class="{ 'is-invalid': !addressData.country && hasAddressErrors }"
                  >
                    <option value="">Select Country</option>

                    <option
                      v-for="(country, key) in countryList"
                      :key="key"
                      :value="key"
                    >
                      {{ country.name }}
                    </option>
                  </select>
                </div>
                <div class="col-md-6">
                   <label class="form-label">{{ regionLabel }}</label>
                   <select
                      class="form-select delivery-filed"
                      v-model="addressData.state"
                      :disabled="!addressData.country || !hasRegions"
                      :class="{ 'is-invalid': hasRegions && !addressData.state && hasAddressErrors }"
                      @change="onRegionSelect"
                    >
                      <option value="">{{ regionPlaceholder }}</option>
                      <option
                        v-for="(state, key) in states"
                        :key="key"
                        :value="state.code"
                      >
                        {{ state.name }}
                      </option>
                    </select>
                </div>
                <!--
                  Picked from the county's towns where we hold a list. No list
                  of Irish towns is complete, so the last option always opens a
                  text field rather than leaving anyone unable to give their
                  address.
                -->
                <div class="col-md-6">
                  <label class="form-label">Town / City</label>

                  <select
                    v-if="hasTownOptions"
                    class="form-select delivery-filed"
                    v-model="townChoice"
                    :disabled="!townReady"
                    :class="{ 'is-invalid': townReady && !addressData.city && hasAddressErrors }"
                  >
                    <option value="">{{ townPlaceholder }}</option>
                    <option
                      v-for="town in townOptions"
                      :key="town"
                      :value="town"
                    >
                      {{ town }}
                    </option>
                    <option :value="townOther">Other - not on this list</option>
                  </select>

                  <input
                    v-if="!hasTownOptions || townChoice === townOther"
                    type="text"
                    class="form-control delivery-filed"
                    :class="{ 'mt-2': hasTownOptions, 'is-invalid': townReady && !addressData.city && hasAddressErrors }"
                    :disabled="!townReady"
                    :placeholder="hasTownOptions ? 'Type your town or city' : townPlaceholder"
                    v-model="addressData.city"
                    @input="townTyped = true"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">{{ postcodeLabel }}</label>
                  <input
                    type="text"
                    class="form-control delivery-filed text-uppercase"
                    :maxlength="postcodeMaxLength"
                    :disabled="!postcodeRequired"
                    :placeholder="postcodePlaceholder"
                    :value="addressData.zip"
                    :class="{ 'is-invalid': postcodeReady && postcodeRequired && !isValidPostcode(addressData.zip) && hasAddressErrors }"
                    @input="onPostcodeInput"
                  >
                  <!--
                    An Eircode carries its county in the routing key, so a
                    disagreement is worth saying - as a warning, because the
                    key list moves and a wrong warning must not cost a
                    delivery.
                  -->
                  <small v-if="postcodeWarning" class="form-text text-warning">
                    {{ postcodeWarning }}
                  </small>
                  <small v-else-if="postcodeHint" class="form-text text-muted">
                    {{ postcodeHint }}
                  </small>
                </div>

                <div class="col-12">
                  <label class="form-label">Delivery Instructions (Optional)</label>
                  <textarea
                    class="form-control delivery-filed"
                    rows="3"
                    style="height: 100px;"
                    placeholder="Enter delivery instructions"
                    v-model="addressData.delivery_instruction"
                  ></textarea>
                </div>
                <div class="col-12 d-flex align-items-center">
                  <input
                    type="checkbox"
                    v-model="addressData.default"
                    class="me-2"
                  >
                  <label>Set as default address</label>
                </div>
                <div class="col-12 mt-3">
                  <button type="submit" class="btn btn-purple me-2">
                    {{ editing ? 'Update' : 'Save' }}
                  </button>

                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    @click="cancelForm"
                  >
                    Cancel
                  </button>
                </div>
              </div>
            </form>
        </div>
      </div>
    </transition>

    </template>

  </div>
</template>

<script>
  import util from '~/mixin/util'
  import Pagination from '~/components/Pagination'
  import addressHelper from '~/mixin/addressHelper'
  import routeParamHelper from '~/mixin/routeParamHelper'
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton"
  import Spinner from "~/components/Spinner"
  import AccountSkeleton from "~/components/AccountSkeleton"
  import validation from '~/mixin/validation'
  /*
   * Country -> county -> town -> postcode, from the same module the other
   * two delivery forms use, so the address book cannot drift from them.
   */
  import addressRules, { TOWN_OTHER } from '~/mixin/addressRules'

  export default {
    name: 'UserAccountAddress',
    data() {
      return {
        ajaxDeleting: 0,
        /*
         * Overrides the addressHelper mixin's `false`. Component data wins over
         * mixin data, and starting true is what stops the first paint showing
         * an empty address book before the request has gone out.
         */
        fetchingAddressData: true,
        selectedAddress: -1,
        selectedAddressObj: null,
        showAddressForm: false,
        // the town was typed rather than picked, so the text field stays open
        townTyped: false,
        addressData: null,
        states: {},
        hasAddressErrors: false,
        submittingAddressData: false
      }
    },
    props: {
      hasRadio: {
        type: Boolean,
        default: false
      }
    },
    watch: {
      location() {
        this.settingCountry()
      },
      profile(value) {
        if (value && this.addressData) {
          this.addressData.name = value.name || ''
          this.addressData.email = value.email || ''
        }
      },
      selectedAddressObj(value) {
        if (this.currentAddresses.length) {

          const countryName = this.countryList[value.country]?.name
          const stateName = value.state ? this.countryList[value.country].states[value.state]?.name : ''
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
    directives: {},
    components: {Spinner, AccountSkeleton, AjaxButton, Pagination},
    mixins: [util, addressHelper, routeParamHelper, validation, addressRules],
    computed: {
      editing() {
        return this.addressData && this.addressData.id
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
       * the list - one saved before the list existed, or one it never had -
       * shows as "Other" with the text field open on it.
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
      totalPage() {
        return this.allAddress?.last_page
      },
      currentAddresses() {
        return this.allAddress?.data || []
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('user', ['allAddress', 'profile']),
      ...mapGetters('common', ['location'])
    },
    methods: {
      capitalizeFirst(val) {
        if (!val) return 'Home'
        return val.charAt(0).toUpperCase() + val.slice(1)
      },
      async setDefaultAddress(address) {
        try {
          await this.userAddressAction({
            ...address,
            default: 1
          })

          await this.fetchingData()
          this.setToastMessage('Default address updated')
        } catch (e) {
          this.setToastError('Failed to set default')
        }
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
          first_name: firstName,
          last_name: lastName,
          email: value.email || '',
          phone: value.phone || '',
          type: value.type || '',
          address_1: value.address_1 || '',
          address_2: value.address_2 || '',
          city: value.city || '',
          state: value.state || '',
          country: value.country || '',
          zip: value.zip || '',
          delivery_instruction: value.delivery_instruction || '',
          default: value.default == 1,
          county: value.county || '',
        }

        // load states based on country
        this.states = this.addressData.country
          ? this.countryList[this.addressData.country].states
          : {}

        this.$nextTick(() => {
          const el = document.getElementById('address-form')
          if (el) el.scrollIntoView({ behavior: 'smooth' })
        })
      },
      /*
       * The country is the root of the form: changing it invalidates the
       * county below it, the town below that and the postcode below that, so
       * all three are cleared rather than left holding another country's
       * answer.
       */
      onCountryChange() {
        this.states = this.addressData?.country
          ? this.countryList?.[this.addressData.country]?.states || {}
          : {}

        this.addressData.state = ''
        this.addressData.county = ''
        this.addressData.city = ''
        this.addressData.zip = ''
        this.townTyped = false
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
        this.addressData.county = this.states?.[this.addressData.state]?.name || ''
      },
      /* Re-spaced as it is typed, in whatever way the country prints it. */
      onPostcodeInput(event) {
        if (!this.addressData) return

        const value = this.formatPostcode(event.target.value)

        this.addressData.zip = value
        event.target.value = value
      },
      async savingAddressData() {
        this.hasAddressErrors = true

        /*
         * Caught here rather than on the round trip, so the county and the
         * postcode under it are answered before the server is asked. The
         * server checks the same two things regardless.
         */
        if (this.hasRegions && !this.addressData.state) {
          this.setToastError(`Choose a ${this.regionLabel.toLowerCase()}.`)
          return
        }

        if (this.postcodeRequired && !this.isValidPostcode(this.addressData.zip)) {
          const label = this.postcodeLabel.toLowerCase()

          this.setToastError(
            this.postcodeExample
              ? `Enter a valid ${label}, for example ${this.postcodeExample}.`
              : `Enter a valid ${label}.`
          )
          return
        }

        this.addressData.name = (this.addressData.first_name || '') + ' ' + (this.addressData.last_name || '')
        this.addressData.default = this.addressData.default ? 1 : 0
        await this.addressAction()

        if (!this.hasAddressErrors) {
          this.showAddressForm = false
          await this.fetchingData()

          this.addressData = {
            id: '',
            first_name: '',
            last_name: '',
            email: this.profile?.email || '',
            phone: '',
            type: '',
            country: '',
            state: '',
            zip: '',
            city: '',
            address_1: '',
            address_2: '',
            delivery_instruction: '',
            county: '',
            default: 0
          }
        }
      },
      settingCountry() {
        this.addressData.country =
          this.addressData.country?.trim()
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
          /*
           * Left empty rather than falling back to whichever county happens
           * to sort first - that put a real county on the address of anyone
           * who never opened the list.
           */
          this.addressData.state = ''
        }

        this.addressData.county = this.states?.[this.addressData.state]?.name || ''
      },
      selectedCountry(evt) {
        this.addressData = {...this.addressData, ...{country: evt.value.code2}}
        this.states = evt.value.states
        this.addressData.state = Object.keys(evt.value.states).length ? Object.values(evt.value.states)[0]?.code : ''
      },
      selectedState(evt) {
        this.addressData.state = evt.value.code
      },
      cancelForm() {
        this.showAddressForm = false
      },
      toggleAddressForm() {
        this.showAddressForm = true
        let firstName = ''
        let lastName = ''
        if (this.profile?.name) {
          const parts = this.profile.name.split(' ')
          firstName = parts[0] || ''
          lastName = parts.slice(1).join(' ') || ''
        }
        this.addressData = {
          id: '',
          first_name: firstName,
          last_name: lastName,
          email: this.profile?.email || '',
          phone: '',
          type: '',
          country: '',
          state: '',
          zip: '',
          city: '',
          address_1: '',
          address_2: '',
          delivery_instruction: '',
          default: 0
        }

        this.settingCountry()

        this.$nextTick(() => {
          const el = document.getElementById('address-form')
          if (el) el.scrollIntoView({ behavior: 'smooth' })
        })
      },
      /*
       * Called when the sidebar's "Address Book" link is clicked.
       *
       * It used to call this.$refs.addressPagination.routeParam(), but there
       * is no <pagination ref="addressPagination"> anywhere in this template --
       * so the ref was always undefined and clicking the link threw a
       * TypeError instead of reloading anything.
       *
       * Reloading through fetchingData() is what was meant, and it raises the
       * loading flag for the duration, so the click now shows the skeleton
       * rather than silently failing.
       */
      async loadData() {
        await this.fetchingData()
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('user', ['userAddressAll', 'userAddressDelete', 'userAddressAction', 'getUserToken']),
    },
    destroyed() {
    },
    async mounted() {
      /*
       * The flag is cleared once, at the end, rather than inside the country
       * list branch below. It used to be set false only when those lists had
       * to be fetched, so on every visit where they were already cached it was
       * never cleared at all -- which did not show while the flag was
       * undeclared and inert, but would now leave the skeleton up for good.
       */
      try {
        if (!this.countryList || !this.phoneList) {
          const {data} = await this.getRequest({
            params: null,
            lang: this.langCode,
            api: 'countriesPhones'
          })

          this.setCountryList(data?.countries)
          this.setPhoneList(data?.phones)
        }

        await this.fetchingData()

      } catch (e) {
        console.error('Address book load failed:', e)
      } finally {
        this.fetchingAddressData = false
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
        first_name: firstName,
        last_name: lastName,
        email: this.profile?.email || '',
        phone: '',
        type: '',
        country: '',
        state: '',
        zip: '',
        city: '',
        address_1: '',
        address_2: '',
        delivery_instruction: '',
        default: 0,
        county: '',
      }

      this.$nextTick(() => {
        if (this.showAddressForm) {
          const el = document.getElementById('address-form')
          if (el) el.scrollIntoView({ behavior: 'smooth' })
        }
      })

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

.btn-purple {
  background: #33319A;
  color: #fff;
}

.ab-title {
  font-size: 26px;
  font-weight: 700;
  color: #130E2B;
}

button.btn-save-chnages {
  background-color: #33319A;
  color: #fff;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  padding: 9px 18px;
  border: none;
  height: auto;
  line-height: 1.2;
}
button.btn-save-chnages:hover {
  background-color: #05B942;
  color: #fff;
}

.shipping-address-card {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 18px;
    height: 100%;
}

.badge-default {
    position: absolute;
    top: 0px;
    right: 0px;
    background: #05B942;
    color: #fff;
    font-size: 12px;
    padding: 6px 10px;
    border-radius: 0px 14px 0 14px;
    font-weight: 400;
}

p.default-address {
    font-size: 14px;
    font-weight: 400;
    line-height: 20px;
    color: #130E2B;
}

a.default-mob {
    font-size: 14px;
    color: #6B7280;
    font-weight: 400;
    text-decoration: none;
}

a.address-edit {
    font-size: 12px;
    font-weight: 400;
    color: #33319A;
    text-decoration: none;
}

a.address-delete {
    font-size: 12px;
    font-weight: 400;
    color: #D32F2F;
    text-decoration: none;
    margin-left: 14px;
}

a.Set-Default {
    font-size: 12px;
    font-weight: 400;
    color: #737373;
    text-decoration: none;
}

hr.dash-hr {
    color: #F5F5F5;
}

.add-address {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #130E2B;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
}

.plus-icon {
    width: 24px;
    height: 24px;
    background: #33319A;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.shipping-address-card { border-radius: 16px; }

/* Form fields */
.form-container.card {
    border: 1px solid #E3E3EF;
    border-radius: 16px;
}
.form-container .form-label {
    font-size: 14px;
    font-weight: 500;
    color: #130E2B;
    margin-bottom: 6px;
}
.form-container .form-control,
.form-container .delivery-filed,
.form-container select.form-control {
    border: 1px solid #E3E3EF;
    border-radius: 10px;
    padding: 11px 14px;
    font-size: 14px;
    color: #130E2B;
    background: #fff;
}
.form-container .form-control:focus {
    border-color: #33319A;
    box-shadow: 0 0 0 2px rgba(51, 49, 154, .15);
    outline: none;
}
.form-container .input-group-text {
    border: 1px solid #E3E3EF;
    border-right: none;
    border-radius: 10px 0 0 10px;
    background: #F7F7FA;
}

/* Responsive */
@media (max-width: 767px) {
    .ab-title { font-size: 22px; }
    .shipping-address-card { padding: 16px; }
    button.btn-save-chnages { width: 100%; }
}
</style>
