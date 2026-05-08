<template>
  <div class="row g-3 mb-3">
    <transition name="fade" mode="out-in">
      <div
        class="spinner-wrapper flex"
        v-if="fetchingAddressData"
      >
        <spinner
          :radius="100"
        />
      </div>

    </transition>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-0">Address Book</h5>
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
                <div class="col-md-6">
                  <label class="form-label">City</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Enter city"
                    v-model="addressData.city"
                    :class="{ 'is-invalid': !addressData.city && hasAddressErrors }"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">County</label>
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
                   <label class="form-label">State</label>
                   <select
                      class="form-select delivery-filed"
                      v-model="addressData.state"
                      :class="{ 'is-invalid': !addressData.state && hasAddressErrors }"
                    >
                      <option value="">Select State</option>
                      <option
                        v-for="(state, key) in states"
                        :key="key"
                        :value="state.code"
                      >
                        {{ state.name }}
                      </option>
                    </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Eircode / Zip Code</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Enter Eircode / Zip Code"
                    v-model="addressData.zip"
                    :class="{ 'is-invalid': !addressData.zip && hasAddressErrors }"
                  >
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
  import validation from '~/mixin/validation'

  export default {
    name: 'UserAccountAddress',
    data() {
      return {
        ajaxDeleting: 0,
        selectedAddress: -1,
        selectedAddressObj: null,
        showAddressForm: false,
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
    components: {Spinner, AjaxButton, Pagination},
    mixins: [util, addressHelper, routeParamHelper, validation],
    computed: {
      editing() {
        return this.addressData && this.addressData.id
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
      onCountryChange() {
        this.states = this.addressData?.country
          ? this.countryList[this.addressData.country].states
          : {}

        this.addressData.state = ''
      },
      async savingAddressData() {
        this.hasAddressErrors = true

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
            : this.location.countryCode

        this.states = this.addressData?.country
          ? this.countryList[this.addressData.country].states
          : {}

        if (this.addressData.state && this.states[this.addressData.state]) {
          this.addressData.state = this.addressData.state
        } else if (this.location.region && this.states[this.location.region]) {
          this.addressData.state = this.location.region
        } else {
          this.addressData.state = Object.keys(this.states).length
            ? Object.values(this.states)[0].code
            : ''
        }
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
      async loadData() {
        this.$refs.addressPagination.routeParam()
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'getRequest']),
      ...mapActions('user', ['userAddressAll', 'userAddressDelete', 'userAddressAction', 'getUserToken']),
    },
    destroyed() {
    },
    async mounted() {
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
.btn-purple {
  background: #33319A;
  color: #fff;
}

button.btn-save-chnages {
  background-color: #33319A;
  color: #fff;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 400;
  padding: 0px 10px;
  border: none;
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
    background: #4b49ac;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

</style>
