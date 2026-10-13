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
    <template v-slot:form="{hasError, fieldErrors}">

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

      <!--
        One destination per rule (2026-09-28). Rules are not assigned to
        products: at checkout the rule covering the customer's address is
        found across all of them and charged once per order. A rule saved
        before this with several rows still lists them all, with delete
        buttons, so the extras can be removed - the save refuses more than one.
      -->
      <div class="sr-layout">
        <div class="sr-main">

          <section class="sr-card">
            <div class="sr-card-head">
              <h5 class="sr-card-title">Rule name</h5>
              <p class="sr-help">Only shown in the admin panel, to tell your rules apart.</p>
            </div>

            <div class="input-wrapper sr-field mb-0">
              <label for="sr-title">{{ $t('index.title') }}</label>
              <input
                id="sr-title"
                type="text"
                placeholder="e.g. Ireland, United Kingdom, Dublin"
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
          </section>

          <template v-for="(item, index) in result.shipping_places">
            <div
              :key="`place-${index}`"
              class="sr-place"
              :class="{'z-10': dropdownOpened[index], 'is-deleted': item.deleted}"
            >
              <div v-if="item.deleted" class="sr-deleted">
                <span>This destination will be removed when you save.</span>
                <button type="button" class="outline-btn" @click.prevent="undoShippingPlace(index)">
                  {{ $t('brand.undoShip') }}
                </button>
              </div>

              <section class="sr-card">
                <div class="sr-card-head sr-card-head-row">
                  <div>
                    <h5 class="sr-card-title">Delivery destination</h5>
                    <p class="sr-help">Customers whose delivery address is here pay this price, once per order.</p>
                  </div>
                  <button
                    v-if="activeRules > 1 && !item.deleted"
                    type="button"
                    class="lite-btn sr-remove"
                    @click.prevent="deleteShippingPlace(index)"
                  >
                    Remove this destination
                  </button>
                </div>

                <div class="sr-grid sr-grid-4">
                  <div class="input-wrapper sr-field place-field" data-field="country" :class="{ 'sr-invalid': fieldErrors.country }">
                    <label class="block">{{ $t('brand.country') }}</label>
                    <dropdown
                      class="grow full-width"
                      :selected-key="item.country"
                      :default-null="!item.country"
                      :searching="true"
                      :options="countryOptionsFor(index)"
                      key-name="name"
                      @value="dropdownVisible(index, $event)"
                      @clicked="selectedCountry(index, $event)"
                    />
                    <field-error :messages="fieldErrors.country" />
                  </div>

                  <div
                    v-if="item.country && item.country !== 'ALL' && stateList && getDataFromObject(stateList, `${item.country}.states`)"
                    class="input-wrapper sr-field place-field"
                  >
                    <label class="block">State / County</label>
                    <dropdown
                      class="grow full-width"
                      :selected-key="item.state"
                      :searching="true"
                      :options="stateOptionsFor(index)"
                      key-name="name"
                      @value="dropdownVisible(index, $event)"
                      @clicked="selectedState(index, $event)"
                    />
                  </div>
                  <div v-else class="input-wrapper sr-field">
                    <label class="block">State / County</label>
                    <div class="sr-static">{{ item.country ? 'Whole area' : 'Choose a country first' }}</div>
                  </div>

                  <div class="input-wrapper sr-field" data-field="price" :class="{ 'sr-invalid': fieldErrors.price }">
                    <label :for="`sr-price-${index}`">Delivery price</label>
                    <div class="sr-affix has-pre">
                      <span class="sr-affix-pre">{{ currencyIcon }}</span>
                      <input
                        :id="`sr-price-${index}`"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        v-model="item.price"
                      >
                    </div>
                    <field-error :messages="fieldErrors.price" />
                  </div>

                  <div class="input-wrapper sr-field" data-field="day_needed" :class="{ 'sr-invalid': fieldErrors.day_needed }">
                    <label :for="`sr-days-${index}`">Delivery time</label>
                    <div class="sr-affix has-post">
                      <input
                        :id="`sr-days-${index}`"
                        type="number"
                        step="1"
                        min="0"
                        placeholder="0"
                        v-model="item.day_needed"
                      >
                      <span class="sr-affix-post">working days</span>
                    </div>
                    <field-error :messages="fieldErrors.day_needed" />
                  </div>
                </div>

                <p v-if="item.country" class="sr-summary">
                  {{ destinationSummary(item) }}
                </p>
              </section>

              <section class="sr-card">
                <div class="sr-card-head sr-card-head-row">
                  <div>
                    <h5 class="sr-card-title">Collection point</h5>
                    <p class="sr-help">Let customers pick their order up at this address instead of having it posted.</p>
                  </div>
                  <label class="sr-switch">
                    <input
                      type="checkbox"
                      :checked="isPickup(item)"
                      @change="togglePickup(index, $event.target.checked)"
                    >
                    <span class="sr-switch-track" aria-hidden="true"><span class="sr-switch-thumb" /></span>
                    <span class="sr-switch-label">{{ isPickup(item) ? 'On' : 'Off' }}</span>
                  </label>
                </div>

                <p v-if="isPickup(item) && item.country === 'ALL'" class="sr-warn">
                  A collection point needs a specific country. Choose one under Delivery destination.
                </p>

                <div v-if="isPickup(item)" class="sr-grid sr-grid-2">
                  <div class="input-wrapper sr-field" data-field="pickup_price" :class="{ 'sr-invalid': fieldErrors.pickup_price }">
                    <label :for="`sr-pprice-${index}`">Collection price <span class="sr-req" aria-hidden="true">*</span></label>
                    <div class="sr-affix has-pre">
                      <span class="sr-affix-pre">{{ currencyIcon }}</span>
                      <input
                        :id="`sr-pprice-${index}`"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00 = free"
                        required
                        v-model="item.pickup_price"
                      >
                    </div>
                    <field-error :messages="fieldErrors.pickup_price" />
                  </div>

                  <div class="input-wrapper sr-field" data-field="pickup_phone" :class="{ 'sr-invalid': fieldErrors.pickup_phone }">
                    <label :for="`sr-pphone-${index}`">Store phone <span class="sr-req" aria-hidden="true">*</span></label>
                    <input :id="`sr-pphone-${index}`" type="text" required placeholder="e.g. 01 504 7000" v-model="item.pickup_phone">
                    <field-error :messages="fieldErrors.pickup_phone" />
                  </div>

                  <div class="input-wrapper sr-field sr-span-2" data-field="pickup_address_line_1" :class="{ 'sr-invalid': fieldErrors.pickup_address_line_1 }">
                    <label :for="`sr-pa1-${index}`">Address line 1 <span class="sr-req" aria-hidden="true">*</span></label>
                    <input :id="`sr-pa1-${index}`" type="text" required placeholder="Street and number" v-model="item.pickup_address_line_1">
                    <field-error :messages="fieldErrors.pickup_address_line_1" />
                  </div>

                  <div class="input-wrapper sr-field sr-span-2">
                    <label :for="`sr-pa2-${index}`">Address line 2 <span class="sr-optional">(optional)</span></label>
                    <input :id="`sr-pa2-${index}`" type="text" placeholder="Unit, building, area" v-model="item.pickup_address_line_2">
                  </div>

                  <div class="input-wrapper sr-field" data-field="pickup_city" :class="{ 'sr-invalid': fieldErrors.pickup_city }">
                    <label :for="`sr-pcity-${index}`">Town / City <span class="sr-req" aria-hidden="true">*</span></label>
                    <input :id="`sr-pcity-${index}`" type="text" required placeholder="Town / City" v-model="item.pickup_city">
                    <field-error :messages="fieldErrors.pickup_city" />
                  </div>

                  <!--
                    The store is in this row's country, and in its county when
                    the row is for one county - the server holds it to that.
                  -->
                  <div v-if="rowCountyName(item)" class="input-wrapper sr-field" data-field="pickup_state" :class="{ 'sr-invalid': fieldErrors.pickup_state }">
                    <label class="block">County / State <span class="sr-req" aria-hidden="true">*</span></label>
                    <div class="sr-static">{{ rowCountyName(item) }}</div>
                    <field-error :messages="fieldErrors.pickup_state" />
                  </div>
                  <div v-else-if="Object.keys(pickupCountyOptions(item)).length" class="input-wrapper sr-field" data-field="pickup_state" :class="{ 'sr-invalid': fieldErrors.pickup_state }">
                    <label class="block">County / State <span class="sr-req" aria-hidden="true">*</span></label>
                    <dropdown
                      class="grow full-width"
                      :selected-key="pickupCountyKey(item)"
                      :searching="true"
                      :default-null="true"
                      :options="pickupCountyOptions(item)"
                      key-name="name"
                      @value="dropdownVisible('pickup-' + index, $event)"
                      @clicked="item.pickup_state = $event.key"
                    />
                    <field-error :messages="fieldErrors.pickup_state" />
                  </div>
                  <div v-else class="input-wrapper sr-field" data-field="pickup_state" :class="{ 'sr-invalid': fieldErrors.pickup_state }">
                    <label :for="`sr-pstate-${index}`">County / State <span class="sr-req" aria-hidden="true">*</span></label>
                    <input :id="`sr-pstate-${index}`" type="text" required placeholder="County / State" v-model="item.pickup_state">
                    <field-error :messages="fieldErrors.pickup_state" />
                  </div>

                  <div class="input-wrapper sr-field" data-field="pickup_zip" :class="{ 'sr-invalid': fieldErrors.pickup_zip }">
                    <label :for="`sr-pzip-${index}`">Eircode / Postcode <span class="sr-req" aria-hidden="true">*</span></label>
                    <input :id="`sr-pzip-${index}`" type="text" required placeholder="Eircode / Postcode" v-model="item.pickup_zip">
                    <field-error :messages="fieldErrors.pickup_zip" />
                  </div>

                  <div class="input-wrapper sr-field">
                    <label class="block">Country <span class="sr-req" aria-hidden="true">*</span></label>
                    <div class="sr-static">{{ countryName(item.country) || 'Choose a country above' }}</div>
                  </div>

                  <p class="sr-muted sr-span-2">
                    The store is in the destination country above. Products limited to other countries cannot be collected here.
                  </p>
                </div>

                <p v-else class="sr-muted">Collection is off: customers in this destination can only have their order posted.</p>
              </section>
            </div>
          </template>
        </div>

        <aside class="sr-aside">
          <div class="sr-card sr-info">
            <h5 class="sr-card-title">How shipping rules work</h5>
            <ul>
              <li>Each rule is <strong>one destination</strong>: a whole country, or one state/county of it.</li>
              <li>At checkout the rule covering the customer&rsquo;s address is used, and delivery is charged <strong>once per order</strong>.</li>
              <li>An exact county beats &ldquo;All states&rdquo;, which beats &ldquo;All countries&rdquo;. For a cheaper Dublin price, add a rule for Ireland &rsaquo; Dublin.</li>
              <li>Places already covered by another rule are not offered.</li>
              <li>Products are not linked to rules. To stop a product being sold somewhere, use <em>Where this product can be sold</em> on the product.</li>
            </ul>
          </div>
        </aside>
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

  export default {
    name: "categories",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        dropdownOpened: {},
        countries: null,
        stateList: null,
        // "IE|ALL"-style keys other rules already cover (shipping-rule/coverage).
        taken: [],
        result: {
          id: '',
          title: '',
          single_price: false,
          shipping_places: [{
            // Chosen by the admin: a default of "All countries" at €0 would
            // offer free delivery everywhere if saved without a second look.
            country: '',
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
      isPickup(place) {
        return parseInt(place?.pickup_point) === 1
      },
      // the pickup country is not asked for: the server takes the row's
      togglePickup(index, on) {
        this.result.shipping_places[index].pickup_point = on ? 1 : 0
      },
      countryName(code) {
        return code && code !== 'ALL' ? (this.countries?.[code]?.name || code) : ''
      },
      // the row's own county, when the row is for one county
      rowCountyName(place) {
        if (!place.country || place.country === 'ALL' || !place.state || place.state === 'ALL') {
          return ''
        }

        return this.getDataFromObject(this.stateList, `${place.country}.states.${place.state}.name`) || place.state
      },
      // every county of the row's country, keyed by the name that is stored
      pickupCountyOptions(place) {
        const all = (place.country && place.country !== 'ALL' &&
          this.getDataFromObject(this.stateList, `${place.country}.states`)) || {}
        const options = {}

        Object.keys(all).forEach(code => {
          const name = all[code]?.name

          if (code !== 'ALL' && name) {
            options[name] = { name }
          }
        })

        return options
      },
      // older rows may hold a code, or a county of another country
      pickupCountyKey(place) {
        const options = this.pickupCountyOptions(place)
        const given = (place.pickup_state || '').trim().toLowerCase()

        if (!given) return ''

        const byName = Object.keys(options).find(name => name.toLowerCase() === given)
        if (byName) return byName

        const all = this.getDataFromObject(this.stateList, `${place.country}.states`) || {}
        const code = Object.keys(all).find(c => c.toLowerCase() === given)

        return code && all[code]?.name ? all[code].name : ''
      },
      // "Republic of Ireland (whole country): €24.00, usually 5 working days."
      destinationSummary(place) {
        const all = place.country === 'ALL'
        const country = all
          ? 'Every country without its own rule'
          : (this.countries?.[place.country]?.name || place.country)
        const state = place.state && place.state !== 'ALL'
          ? (this.countries?.[place.country]?.states?.[place.state]?.name || place.state)
          : null
        const price = parseFloat(place.price || 0)
        const cost = price > 0 ? this.currencyIcon + price.toFixed(2) : 'free delivery'
        const days = parseInt(place.day_needed || 0)
        const where = state ? state + ', ' + country : country + (all ? '' : ' (whole country)')

        return where + ': ' + cost +
          (days > 0 ? ', usually ' + days + ' working ' + (days === 1 ? 'day' : 'days') + '.' : '.')
      },
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
      /*
       * Each country and state may be in only one row of one rule - the
       * checkout picks the row by address, so two rows for the same place
       * would be ambiguous and the save refuses them. The dropdowns only
       * offer what is still free, and a new row starts on the first free one.
       */
      coverageKey(country, state) {
        const c = String(country || '').toUpperCase()
        const s = String(state || '')

        return (c === 'ALL' || s === '' || s.toUpperCase() === 'ALL') ? `${c}|ALL` : `${c}|${s}`
      },
      usedKeys(exceptIndex) {
        const keys = new Set(this.taken)

        this.result.shipping_places.forEach((place, index) => {
          if (index !== exceptIndex && !place.deleted && place.country) {
            keys.add(this.coverageKey(place.country, place.state))
          }
        })

        return keys
      },
      freeStates(country, used) {
        const states = Object.keys(this.countries?.[country]?.states || {})
        const free = []

        if (!used.has(`${country}|ALL`)) free.push('ALL')

        if (country !== 'ALL') {
          states.forEach(s => { if (!used.has(`${country}|${s}`)) free.push(s) })
        }

        return free
      },
      countryOptionsFor(index) {
        const used = this.usedKeys(index)
        const current = this.result.shipping_places[index]?.country
        const options = {}

        Object.keys(this.countries || {}).forEach(code => {
          if (code === current || this.freeStates(code, used).length) {
            options[code] = this.countries[code]
          }
        })

        return options
      },
      stateOptionsFor(index) {
        const place = this.result.shipping_places[index]
        const all = this.getDataFromObject(this.stateList, `${place.country}.states`) || {}
        const used = this.usedKeys(index)
        const options = {}

        Object.keys(all).forEach(code => {
          if (code === place.state || !used.has(this.coverageKey(place.country, code))) {
            options[code] = all[code]
          }
        })

        return options
      },
      /*
       * The first place no row covers yet: "All countries", then a whole
       * country, and only when every country is taken as a whole, a single
       * state of one.
       */
      firstFreePlace(exceptIndex = -1) {
        const used = this.usedKeys(exceptIndex)
        const codes = Object.keys(this.countries || {})

        const whole = codes.find(code => !used.has(`${code}|ALL`))

        if (whole) {
          return {country: whole, state: whole === 'ALL' ? '' : 'ALL'}
        }

        for (const code of codes) {
          const free = this.freeStates(code, used)

          if (free.length) {
            return {country: code, state: free[0]}
          }
        }

        return null
      },
      addShippingPlace() {
        const free = this.firstFreePlace()

        if (!free) {
          this.setToastError('Every country and state is already covered by a shipping rule.')
          return
        }

        // Starts empty on purpose - see data(). The save refuses a row
        // with no country ("Row 2: choose a country.").
        this.result.shipping_places.push({
          country: '',
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

        this.generateStateList()
      },
      resultUpdated(result) {
        this.result = result
        this.generateStateList()
      },
      generateStateList() {
        let countryState = {}
        this.result.shipping_places.forEach((i) => {
          // A new row has no country until one is chosen.
          if (!i.country || !this.countries?.[i.country]) {
            return
          }

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
        // The dropdown's "--------------" placeholder is not a country.
        if (!evt?.key || evt.key === '0' || !this.countries?.[evt.key]) {
          return
        }

        this.result.shipping_places[index].country = evt.key
        /*
         * The whole country when that is free - it used to default to the
         * first county (Carlow for Ireland), so a row meant for all of
         * Ireland silently covered one county.
         */
        this.result.shipping_places[index].state = evt.key === 'ALL'
          ? ''
          : (this.freeStates(evt.key, this.usedKeys(index))[0] || 'ALL')
        this.generateStateList()
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('common', ['getById', 'getRequest']),
      ...mapActions('ui', ['setToastError']),
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

      try {
        const taken = await this.getRequest({
          params: {except: isNaN(this.$route?.params?.id) ? '' : this.$route.params.id},
          api: 'getShippingRuleCoverage'
        })

        this.taken = (taken || []).map(t => t.key)
      } catch (e) {
        this.taken = []
      }


    }
  }
</script>

<style scoped lang="stylus">
  /*
   * Country and State open their lists to the right: form.styl right-aligns
   * the list of any .input-wrapper:last-child, which slid it under the
   * sidebar (see form-last-child-dropdown-opens-left).
   */
  .form-wrapper .place-field ::v-deep .dropdown-inner
    left 0
    right auto

  .sr-layout
    display grid
    grid-template-columns minmax(0, 1fr) 300px
    gap 20px
    align-items start

  .sr-main
    display flex
    flex-direction column
    gap 20px
    min-width 0

  .sr-place
    display flex
    flex-direction column
    gap 20px
    position relative

    &.is-deleted .sr-card
      opacity .45
      pointer-events none

  .sr-card
    background var(--surface-color)
    border 1px solid var(--border-color)
    border-radius var(--radius-lg)
    padding 20px 22px

  .sr-card-head
    margin-bottom 16px

  .sr-card-head-row
    display flex
    align-items flex-start
    justify-content space-between
    gap 16px

  .sr-card-title
    margin 0 0 4px
    font-size 15px
    font-weight 600
    color var(--text-strong)

  .sr-help
    margin 0
    font-size 13px
    line-height 1.5
    color var(--text-muted)

  .sr-grid
    display grid
    gap 16px 18px

  .sr-grid-4
    grid-template-columns repeat(4, minmax(0, 1fr))

  .sr-grid-2
    grid-template-columns repeat(2, minmax(0, 1fr))

  .sr-span-2
    grid-column 1 / -1

  .sr-grid .input-wrapper.sr-field,
  .sr-card .input-wrapper.sr-field
    margin 0
    min-width 0

  .sr-field label
    display block
    margin-bottom 6px
    font-size 13px
    font-weight 500
    color var(--text-color)

  .sr-optional
    font-weight 400
    color var(--text-soft)

  // dropdown.styl caps every dropdown at 180px; here it fills its column
  .sr-field ::v-deep .custom-dropdown
    width 100%
    max-width none

  // Same height as the text fields beside it
  .sr-field ::v-deep .custom-dropdown > span
    width 100%
    box-sizing border-box
    height 40px
    display flex
    align-items center
    justify-content space-between

  .sr-req
    color #d93025
    font-weight 600

  // a refused save marks the field it was about (DataPage fieldErrors)
  .sr-invalid input,
  .sr-invalid .sr-static,
  .sr-invalid ::v-deep .custom-dropdown > span
    border-color var(--danger) !important

  .field-error
    display flex
    align-items flex-start
    gap 6px
    margin 6px 0 0
    font-size 12px
    line-height 1.5
    font-weight 500
    color var(--danger)

    i
      font-size 11px
      line-height 18px
      flex 0 0 auto

  .sr-static
    height 40px
    display flex
    align-items center
    padding 0 12px
    border 1px dashed var(--border-strong)
    border-radius var(--radius-md)
    font-size 13px
    color var(--text-muted)
    background var(--hover-color)

  // Currency before, unit after, inside the field
  .sr-affix
    position relative
    display flex
    align-items center

    input
      width 100%

    &.has-pre input
      padding-left 30px

    &.has-post input
      padding-right 104px

  .sr-affix-pre,
  .sr-affix-post
    position absolute
    font-size 13px
    color var(--text-muted)
    pointer-events none

  .sr-affix-pre
    left 12px

  .sr-affix-post
    right 12px

  .sr-summary
    margin 16px 0 0
    padding-top 14px
    border-top 1px solid var(--border-color)
    font-size 13px
    color var(--text-muted)

  .sr-muted
    margin 0
    font-size 13px
    color var(--text-soft)

  .sr-warn
    margin 0 0 16px
    padding 10px 12px
    border-radius var(--radius-md)
    background var(--warning-bg)
    color var(--warning-text)
    font-size 13px

  // On/off switch for the collection point
  .sr-switch
    display inline-flex
    align-items center
    gap 10px
    margin 0
    cursor pointer
    flex-shrink 0
    user-select none

    input
      position absolute
      opacity 0
      width 1px
      height 1px

    .sr-switch-track
      position relative
      width 40px
      height 22px
      border-radius 11px
      background var(--border-strong)
      transition background .15s

    .sr-switch-thumb
      position absolute
      top 3px
      left 3px
      width 16px
      height 16px
      border-radius 50%
      background #fff
      box-shadow 0 1px 2px rgba(17, 24, 39, .25)
      transition transform .15s

    input:checked + .sr-switch-track
      background var(--primary-color)

    input:checked + .sr-switch-track .sr-switch-thumb
      transform translateX(18px)

    input:focus + .sr-switch-track
      outline 2px solid var(--primary-color)
      outline-offset 2px

    .sr-switch-label
      min-width 24px
      font-size 13px
      font-weight 600
      color var(--text-color)

  .sr-deleted
    position absolute
    z-index 3
    top 50%
    left 50%
    transform translate(-50%, -50%)
    display flex
    align-items center
    gap 12px
    padding 12px 16px
    background var(--surface-color)
    border 1px solid var(--border-strong)
    border-radius var(--radius-md)
    box-shadow 0 4px 14px var(--shadow-color)
    font-size 13px

  .sr-remove
    flex-shrink 0

  .sr-aside
    position sticky
    top 12px

  .sr-info
    background var(--info-bg)
    border-color transparent

    ul
      margin 10px 0 0
      padding-left 18px

    li
      margin-bottom 8px
      font-size 13px
      line-height 1.55
      color var(--text-color)

      &:last-child
        margin-bottom 0

  @media (max-width: 1280px)
    .sr-layout
      grid-template-columns minmax(0, 1fr)

    .sr-aside
      position static
      order -1

  @media (max-width: 900px)
    .sr-grid-4
      grid-template-columns repeat(2, minmax(0, 1fr))

  @media (max-width: 560px)
    .sr-grid-4,
    .sr-grid-2
      grid-template-columns minmax(0, 1fr)

    .sr-card-head-row
      flex-direction column
</style>
