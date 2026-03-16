<template>
  <form @submit.prevent="savingAddressData">

    <transition
      name="fade"
      mode="out-in"
    >
      <div
        class="spinner-wrapper flex layer-white"
        v-if="loading"
      >
        <spinner
          :radius="100"
        />
      </div>
    </transition>

    <pop-over
      v-if="addressData"
      :title="$t('ship.ua')"
      @close="$emit('close')"
      elem-id="user-address-pop-over"
      :layer="true"
      class="address-popup popup-top-auto"
    >
      <template
        v-slot:content
      >
        <div class="sb">
          <error-formatter />

          <div class="dply-felx gap-10 start">
            <div
              v-if="countryList"
              class="input-wrap"
            >
              <label>
                {{ $t('brand.country') }}
              </label>
              <dropdown
                :selected-key="addressData.country"
                :options="countryList"
                key-name="name"
                :position-fixed="false"
                :searching="true"
                @clicked="selectedCountry"
              />
            </div>

            <div
              v-if="Object.keys(states).length"
              class="input-wrap"
            >
              <label>
                {{ $t('setting.state') }}
              </label>
              <dropdown
                :selected-key="addressData.state"
                :options="states"
                key-name="name"
                :position-fixed="false"
                @clicked="selectedState"
              />
            </div>
          </div>

          <div
            class="input-wrap"
            :class="{invalid: !emailValid && hasAddressErrors}"
          >
            <label>
              {{ $t('fSale.email') }}
            </label>
            <div class="icon-input">
              <i
                class="icon email-icon"
              />
              <input
                type="text"
                :placeholder="$t('ship.your', { type: $t('ship.email') })"
                v-model.trim="addressData.email"
              >
            </div>

            <span
              class="error"
              v-if="!addressData.email && hasAddressErrors"
            >
               {{ $t('category.req', {type: $t('fSale.email') }) }}
            </span>
            <span
              class="error"
              v-else-if="invalidEmail && hasAddressErrors"
            >
                {{ $t('ship.ie') }}
              </span>
          </div>

          <div class="dply-felx gap-10 start">
            <div
              class="input-wrap"
              :class="{invalid: !addressData.name && hasAddressErrors}"
            >
              <label>
                {{ $t('user.name') }}
              </label>
              <input
                type="text"
                v-model="addressData.name"
              />
              <span
                class="error"
                v-if="!addressData.name && hasAddressErrors"
              >
                {{ $t('category.req', {type: $t('user.name') }) }}
              </span>
            </div>

            <div
              class="input-wrap"
              :class="{invalid: !addressData.phone && hasAddressErrors}"
            >
              <label>
                {{ $t('fSale.phone') }}
              </label>
              <div class="input-text">
                <span>
                  {{ phoneCode }}
                </span>
                <input
                  type="text"
                  v-model="addressData.phone"
                />
              </div>

              <span
                class="error"
                v-if="!addressData.phone && hasAddressErrors"
              >
                {{ $t('category.req', {type: $t('fSale.phone') }) }}
              </span>
            </div>
          </div>

          <div
            class="input-wrap"
            :class="{invalid: !addressData.address_1 && hasAddressErrors}"
          >
            <label>
              {{ $t('list.addr') }}
            </label>
            <input
              class="mb-10"
              type="text"
              v-model="addressData.address_1"
              :placeholder="$t('ship.sap')"
            />
            <input
              type="text"
              v-model="addressData.address_2"
              :placeholder="$t('ship.sap')"
            />
            <span
              class="error"
              v-if="!addressData.address_1 && hasAddressErrors"
            >
              {{ $t('category.req', {type: $t('list.addr') }) }}
            </span>
          </div>

          <div class="dply-felx gap-10 start">
            <div
              class="input-wrap "
              :class="{invalid: !addressData.city && hasAddressErrors}"
            >
              <label>
                {{ $t('setting.city') }}
              </label>
              <input
                type="text"
                v-model="addressData.city"
              />
              <span
                class="error"
                v-if="!addressData.city && hasAddressErrors"
              >
                {{ $t('category.req', {type: $t('setting.city') }) }}
              </span>
            </div>
            <div
              class="input-wrap "
              :class="{invalid: !addressData.zip && hasAddressErrors}"
            >
              <label>
                {{ $t('setting.zip') }}
              </label>
              <input
                type="text"
                v-model="addressData.zip"
              />
              <span
                class="error"
                v-if="!addressData.zip && hasAddressErrors"
              >
                {{ $t('category.req', {type: $t('setting.zip') }) }}
              </span>
            </div>
          </div>
        </div>
      </template>

      <template v-slot:pop-footer>
        <div class="dply-felx j-end gap-10">
          <button
            class="outline-btn plr-30 plr-sm-15"
            @click.prevent="$emit('close')"
          >
            {{ $t('title.cancel') }}
          </button>
          <ajax-button
            class="primary-btn  plr-30 plr-sm-15"
            :fetching-data="submittingAddressData"
            :loading-text="$t('ship.saving')"
            :text=" $t('ship.ta', {type: editing > 0 ? $t('ship.up') : $t('setting.sv')})"
          />
        </div>
      </template>
    </pop-over>
  </form>

</template>

<script>
  import util from '~/mixin/util'
  import validation from '~/mixin/validation'
  import PopOver from '~/components/PopOver'
  import Dropdown from '~/components/Dropdown'
  import {mapGetters, mapActions} from 'vuex'
  import addressHelper from '~/mixin/addressHelper'
  import AjaxButton from "~/components/AjaxButton"
  import Spinner from "~/components/Spinner";
  import ErrorFormatter from "../ErrorFormatter";

  export default {
    name: 'AddressPopup',
    data() {
      return {
        states: {},
        addressData: null,
        loading: false,
        hasAddressErrors: false,
        dropdownOpen: false,
        submittingAddressData: false
      }
    },
    watch: {
      location() {
        this.settingCountry()
      },
    },
    props: {
      selectedUser: {
        type: Object,
        default() {
          return null
        }
      },
      address: {
        type: Object,
        default() {
          return null
        }
      }
    },
    components: {
      ErrorFormatter,
      Spinner,
      AjaxButton,
      PopOver,
      Dropdown
    },
    computed: {
      location(){
        return {
          countryCode: this.setting?.default_country,
          region: this?.setting?.default_state
        }
      },
      invalidEmail() {
        return !this.isValidEmail(this.addressData?.email)
      },
      emailValid() {
        return this.addressData.email && !this.invalidEmail
      },
      phoneCode() {
        return this.phoneList[this.addressData?.country]
      },
      editing() {
        return this.addressData && this.addressData.id
      },
      ...mapGetters('language', ['langCode']),
      ...mapGetters('resource', ['countryList', 'phoneList']),
      ...mapGetters('setting', ['setting']),
    },
    mixins: [util, validation, addressHelper],
    methods: {
      async savingAddressData() {
        try {
          this.submittingAddressData = true
          const data = await this.setRequest({
            params: {
              ...this.addressData,
              ...{ user_id: this.selectedUser?.id || ''}
              },
            api: 'setUserAddress'
          })
          this.submittingAddressData = false

          if(data.status !== 201){
            this.$emit('close', this.addressData ? data : null)
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      settingCountry() {
        this.addressData.country = this.addressData.country.trim() !== '' ? this.addressData.country.trim() : this.location.countryCode

        this.states = this.addressData?.country ? this.countryList[this.addressData.country].states : ''
        this.addressData.state = this.addressData?.state?.trim() !== '' ? this.addressData?.state?.trim() : this.location.region
      },
      selectedCountry(evt) {
        this.addressData = {...this.addressData, ...{country: evt.value.code2}}
        this.states = evt.value.states
        this.addressData.state = Object.keys(evt.value.states).length ? Object.values(evt.value.states)[0]?.code : ''
      },
      selectedState(evt) {
        this.addressData.state = evt.value.code
      },
      ...mapActions('resource', ['setCountryList', 'setPhoneList']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'getRequest', 'setRequest']),
    },
    created() {
    },
    async mounted() {

      if (!this.countryList || !this.phoneList) {
        this.loading = true

        const {data} = await this.getRequest({
          params: null,
          lang: this.langCode,
          api: 'countriesPhones'
        })

        this.setCountryList(data?.countries)
        this.setPhoneList(data?.phones)
        this.loading = false
      }


      if(this.address){
        this.addressData = {...this.addressData, ...this.address}
      } else {

        this.addressData = {
          id: '',
          city: '',
          email: '',
          name: '',
          phone: '',
          country: '',
          state: '',
          zip: '',
          address_1: '',
          address_2: ''
        }

        this.$nextTick(() => {
          if (this.selectedUser) {
            this.addressData.name = this.selectedUser.name
            this.addressData.email = this.selectedUser.email
          }
        })
      }

      if (!this.addressData.country) {
        this.settingCountry()
      } else {
        this.states = this.addressData?.country ? this.countryList[this.addressData.country].states : ''
        this.addressData.state = this.addressData?.state?.trim() !== '' ? this.addressData?.state?.trim() : this.location.region
      }
    }


  }
</script>

