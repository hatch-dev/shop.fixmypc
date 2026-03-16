<template>
  <data-page
    v-if="countries"
    ref="dataPage"
    set-api="setShippingRule"
    get-api="getShippingRule"
    route-name="shipping-rules"
    empty-store-variable="allShippingRules"
    :name="$t('dataPage.shipRule')"
    :validation-keys="['title']"
    :result="result"
    gate="shipping_rule"
    @result="resultUpdated"
  >
    <template v-slot:form="{hasError}">

      <div
        v-if="loading"
        class="spinner-wrapper"
      >
        <spinner
          :radius="60"
          color="primary"
          class="mr-15"
        />
      </div>


      <div class="input-wrapper">

        <div>
          <label>{{ $t('index.title') }}</label>
          <input
            type="text"
            :placeholder="$t('index.title')"
            name="title"
            v-model="result.title"
            ref="title"
            :class="{invalid: !!!result.title && hasError}"
          >
          <span
            class="error"
            v-if="!!!result.title && hasError"
          >
            {{ $t('category.req', { type: $t('index.title')}) }}
          </span>

        </div>




      </div>

      <label for="single-price" class="">
        <input
          type="checkbox"
          id="single-price"
          v-model="result.single_price"
        />
        {{ $t('title.sso') }}
      </label>



      <div class="dply-felx mtb-20 mtb-sm-15">
        <h5>{{ $t('brand.shipRules') }}</h5>
        <button
          class="lite-btn mlr-7-5"
          @click.prevent="addShippingPlace"
        >
          {{ $t('brand.addPlace') }}
        </button>
      </div>


      <div
        v-for="(item, index) in result.shipping_places"
        :key="index"
        :class="{'z-10': dropdownOpened[index]}"
        class="shipping-rule mb-20 mb-sm-15"
      >

        <div
          class="shipping-rule-inner p-20 p-sm-15"
          :class="{deleted: item.deleted}"
        >
          <button
            v-if="item.deleted"
            class="outline-btn undo-btn"
            @click.prevent="undoShippingPlace(index)"
          >
            {{ $t('brand.undoShip') }}
          </button>

          <div
            class="dply-felx inputs f-wrap mlr--7-5"
          >
            <div class="mlr-7-5">
              <div class="dply-felx inputs grow f-wrap ">
                <div
                  class="input-wrapper grow "
                >
                  <label class="block"> {{ $t('brand.country') }}</label>
                  <dropdown
                    class="grow full-width"
                    :selected-key="result.shipping_places[index].country"
                    :searching="true"
                    :options="countries"
                    key-name="name"
                    @value="dropdownVisible(index, $event)"
                    @clicked="selectedCountry(index, $event)"
                  />
                </div>

                <div
                  v-if="stateList && getDataFromObject(stateList, `${item.country}.states`)"
                  class="input-wrapper grow "
                >
                  <label class="block">{{ $t('category.status') }}</label>
                  <dropdown
                    class="grow full-width"
                    :selected-key="result.shipping_places[index].state"
                    :searching="true"
                    :options="getDataFromObject(stateList, `${item.country}.states`)"
                    key-name="name"
                    @value="dropdownVisible(index, $event)"
                    @clicked="selectedState(index, $event)"
                  />
                </div>
              </div>
            </div>


            <div class="input-wrapper mx-w-150x ">
              <label>{{ $t('brand.price') }}({{ currencyIcon }})</label>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.price')"
                v-model="result.shipping_places[index].price"
              >
            </div>


            <div class="input-wrapper mx-w-150x ">
              <label>{{ $t('brand.dayNeeded') }}</label>
              <input
                type="number"
                step="any"
                :placeholder="$t('brand.dayNeeded')"
                v-model="result.shipping_places[index].day_needed"
              >
            </div>
          </div>


          <div class="dply-felx gap-10 align-start">
            <pickup-address
              @result="pickupUpdated(index, $event)"
              :shipping-place="result.shipping_places[index]"
            />

            <button
              v-if="activeRules > 1"
              class="lite-btn"
              @click.prevent="deleteShippingPlace(index)"
            >
              {{ $t('brand.delPlace') }}
            </button>
          </div>


        </div>
      </div>

    </template>
  </data-page>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import Dropdown from '~/components/Dropdown'
  import Spinner from "~/components/Spinner"
  import PickupAddress from "~/components/partials/PickupAddress";

  export default {
    name: "categories",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        dropdownOpened: {},
        countries: null,
        stateList: null,
        result: {
          id: '',
          title: '',
          single_price: false,
          shipping_places: [{
            country: 'ALL',
            day_needed: 0,
            id: '',
            pickup_point: 0,
            pickup_price: 0,
            price: 0,
            shipping_rule_id: '',
            deleted: false,
            state: '',

          }],
        }
      }
    },
    mixins: [util],
    components: {
      PickupAddress,
      Spinner,
      DataPage,
      Dropdown
    },
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      activeRules() {
        return this.result.shipping_places.filter((i) => {
          return !i.deleted
        }).length
      },
      ...mapGetters('resource', ['countryList']),
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      pickupUpdated(index, evt) {
        this.result.shipping_places[index] = {...this.result.shipping_places[index], ...evt}
      },
      dropdownVisible(index, value) {
        this.dropdownOpened = {...this.dropdownOpened, ...{[index]: value}}
      },
      undoShippingPlace(index) {
        this.result.shipping_places[index] = {
          ...this.result.shipping_places[index],
          ...{deleted: false}
        }
        this.result = {...this.result, ...{shipping_places: this.result.shipping_places}}
      },
      deleteShippingPlace(index) {
        if (this.activeRules < 2) {
          return false
        }

        this.result.shipping_places[index] = {
          ...this.result.shipping_places[index],
          ...{deleted: true}
        }
        this.result = {...this.result, ...{shipping_places: this.result.shipping_places}}

      },
      addShippingPlace() {
        this.result.shipping_places.push({
          country: 'ALL',
          day_needed: 0,
          id: '',
          pickup_point: 0,
          pickup_price: 0,
          pickup_phone: '',
          pickup_address_line_1: '',
          pickup_address_line_2: '',
          pickup_zip: '',
          pickup_state: '',
          pickup_city: '',
          pickup_country: '',
          price: 0,
          shipping_rule_id: '',
          state: ''
        })
      },
      resultUpdated(result) {
        this.result = result
        this.generateStateList()
      },
      generateStateList() {
        let countryState = {}
        this.result.shipping_places.forEach((i) => {
          const currentStates = {
            ...{
              ALL: {
                code: "ALL",
                name: this.$t('brand.allStat')
              }
            },
            ...this.countries[i.country].states
          }
          const currentCountry = {...this.countries[i.country].states, ...{states: currentStates}}
          countryState = {...countryState, ...{[i.country]: currentCountry}}
        })
        this.stateList = countryState
      },
      selectedState(index, evt) {
        this.result.shipping_places[index].state = evt.key
      },
      selectedCountry(index, evt) {
        this.result.shipping_places[index].country = evt.key
        this.result.shipping_places[index].state = evt.value?.states && Object.keys(evt.value?.states)[0] || ''
        this.generateStateList()
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('common', ['getById']),
    },
    async mounted() {
      if (!this.countryList) {
        this.loading = true

        const data = await this.getById({
          params: null,
          id: 'countries',
          api: 'resource'
        })

        this.setCountryList(data)
        this.loading = false
      }

      this.countries = {
        ...{ALL: {code2: "ALL", name: this.$t('brand.allCou')}},
        ...this.countryList
      }

    }
  }
</script>

<style scoped lang="stylus">
  .shipping-rule
    border-radius 7px
    position relative
    border 1px solid #ddd

    .shipping-rule-inner
      z-index 1
      position relative

      &:after
        content ''
        position absolute
        top 0
        bottom 0
        left 0
        right 0
        z-index -2
        opacity 0
        background #eee

      &.deleted
        &:after
          opacity .6
          z-index 0

    .undo-btn
      transition none
      position absolute
      top 50%
      left 50%
      z-index 2
      transform translate(-50%, -50%)
</style>
