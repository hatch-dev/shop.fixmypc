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

      <!--<div
        v-else-if="currentAddresses && !currentAddresses.length"
        class="info-msg mb-20"
      >
        {{ $t('userAddress.noAddress') }}
      </div>-->

    </transition>

    <div
      class="col-md-4 save-address"
      v-for="(value, key) in currentAddresses"
      :key="key"
    >
      <label
        class="address-card"
        :class="{ active: selectedAddress === key }"
      >
        <input
          type="radio"
          name="user_address"
          :value="key"
          v-model="selectedAddress"
          hidden
        />

        <div class="check-icon" v-if="selectedAddress === key">✓</div>

        <div class="d-flex justify-content-between">
          <div>
            <strong>{{ value.name }}</strong>
            <span class="badge-custom">
              {{ value.type || 'Home' }}
            </span>
            <span v-if="parseInt(value.default) === 1" class="badge-default">
              DEFAULT
            </span>
          </div>
        </div>
        <div class="my-2">
          <p class="mb-1 small" v-html="formatAddress(value)" />
          <p class="small mb-0">tel: {{ value.phone }}</p>
        </div>
        <div class="d-flex gap-2">
          <button
            class="btn btn-sm btn-outline-secondary edit-btn"
            @click.prevent="editAddress(value)"
          >
            Edit
          </button>
          <button
            class="btn btn-sm btn-outline-danger"
            @click.prevent="deleting(value)"
          >
            Delete
          </button>
        </div>
      </label>
    </div>
    <div class="col-md-4 new-address">
      <button
        class="add-card h-100 d-flex flex-column justify-content-center align-items-center w-100"
        @click.prevent="toggleAddressForm"
      >
        <div class="plus-circle">+</div>
        <div class="mt-2 text-center">
          Add new address
        </div>
      </button>
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
                    class="form-control"
                    v-model="addressData.first_name"
                    :class="{ 'is-invalid': !addressData.first_name && hasAddressErrors }"
                  >
                </div>

                <div class="col-md-6">
                  <label class="form-label">Last Name</label>
                  <input
                    type="text"
                    class="form-control"
                    v-model="addressData.last_name"
                    :class="{ 'is-invalid': !addressData.last_name && hasAddressErrors }"
                  >
                </div>
                <div class="col-md-6">
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
                  <label class="form-label">Address Label</label>
                  <select v-model="addressData.type" :class="{ 'is-invalid': !addressData.type && hasAddressErrors }" class="form-select">
                    <option value="">Select Type</option>
                    <option value="home">Home</option>
                    <option value="office">Office</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Contact Number</label>
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
                  <label class="form-label">Eircode</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Enter code"
                    v-model="addressData.zip"
                    :class="{ 'is-invalid': !addressData.zip && hasAddressErrors }"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">Address 1</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Enter address"
                    v-model="addressData.address_1"
                    :class="{ 'is-invalid': !addressData.address_1 && hasAddressErrors }"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">Address 2</label>
                  <input
                    type="text"
                    class="form-control delivery-filed"
                    placeholder="Enter address"
                    v-model="addressData.address_2"
                  >
                </div>
                <div class="col-md-6">
                  <label class="form-label">Town / City</label>
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
                <div class="col-12">
                  <label class="form-label">Delivery Instructions</label>
                  <textarea
                    class="form-control delivery-filed"
                    rows="3"
                    style="height: 100px;"
                    placeholder="Enter delivery instructions"
                    v-model="addressData.delivery_instruction"
                  ></textarea>
                </div>
                <div class="col-12">
                  <input type="checkbox" :true-value="1" :false-value="0" v-model="addressData.default">
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

    <!-- <div class="flex gap-20 align-initial wrap start" v-if="hasRadio">
      <label
        v-for="(value, key) in currentAddresses"
        :key="key"
        class="card p-15 address-card"
        :class="{active: selectedAddress === key}"
      >
        <input
          type="radio"
          name="user_address"
          :value="key"
          v-model="selectedAddress"
        />
        <span class="flex gap-10 mb-10 align-initial sided address-title">
            <span class="block bold">{{value.name}}</span>
            <span class="flex gap-10">
              <ajax-button
                class="outline-btn plr-10"
                :type="'button'"
                :text="$t('userAddress.edit')"
                color="primary"
                @clicked="$emit('editing', value)"
              />
              <ajax-button
                class="outline-btn plr-10"
                :type="'button'"
                :fetching-data="ajaxDeleting === value.id"
                :loading-text="$t('userAddress.deleting')"
                :text="$t('userAddress.delete')"
                color="primary"
                @clicked="deleting(value)"
              />
            </span>
          </span>

        <span v-html="formatAddress(value)"/>
        <span class="block mt-5">tel: {{value.phone}}</span>
      </label>

      <button
        class="address-btn card" type="button"
        @click.prevent="$emit('add-address')"
      >
          <span class="icon-wrap mb-10">
            <i
              class="icon plus-icon"
            />
          </span>

        {{ $t('addresses.addAddress') }}
      </button>

    </div>

    <div v-else class="flex wrap start align-initial gap-10">
      <div
        class="card p-20  mx-w-400x address-card"
        v-for="(value, index) in currentAddresses"
        :key="index"
      >
        <span class="flex gap-10 sided mb-10 address-title">

           <span class="bold block">{{value.name}}</span>

          <span class="flex gap-10">
            <ajax-button
              class="outline-btn plr-10"
              :type="'button'"
              :text="$t('userAddress.edit')"
              color="primary"
              @clicked="$emit('editing', value)"
            />
            <ajax-button
              class="outline-btn plr-10"
              :type="'button'"
              :fetching-data="ajaxDeleting === value.id"
              :loading-text="$t('userAddress.deleting')"
              :text="$t('userAddress.delete')"
              color="primary"
              @clicked="deleting(value)"
            />
          </span>
        </span>

        <span v-html="formatAddress(value)"/>
        <span class="block mt-5">tel: {{value.phone}}</span>

      </div>
    </div>
    <pagination
      ref="addressPagination"
      :total-page="totalPage"
      @fetching-data="fetchingData"
    /> -->
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
    name: 'UserAddress',
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
    methods: {
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
          country: value.country || '',
          state: value.state || '',
          zip: value.zip || '',
          city: value.city || '',
          address_1: value.address_1 || '',
          address_2: value.address_2 || '',
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
        this.addressData.name =
          (this.addressData.first_name || '') + ' ' +
          (this.addressData.last_name || '')

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
            city: '',
            address_1: '',
            address_2: '',
            delivery_instruction: '',
            default: 0,
            zip: '',
            county: '',
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
            county: ''
          }
          this.settingCountry()
        }

        this.$nextTick(() => {
          if (this.showAddressForm) {
            const el = document.getElementById('address-form')
            if (el) el.scrollIntoView({ behavior: 'smooth' })
          }
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
        county: ''
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
</style>
