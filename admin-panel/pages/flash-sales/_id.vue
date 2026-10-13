<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setFlashSale"
    get-api="getFlashSale"
    route-name="flash-sales"
    :name="$t('profile.fSale')"
    :validation-keys="['title', 'start_time', 'end_time']"
    :result="result"
    gate="flash_sale"
    :emit-before-submit="true"
    @before-submit="submitForm"
    @result="settingResult"
  >
    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          v-model="result.title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

      <div
        class="dply-felx block-xs mlr--7-5 inputs align-start j-left"
        :class="{'red-border': !!!result.start_time && hasError}"
      >
        <div
          class="input-wrapper mlr-7-5"
        >
          <label>{{ $t('prod.sTime') }}</label>
          <datetime
            format="DD-MM-YYYY H:i:s"
            width="200px"
            v-model="result.start_time"
            readonly
          />
          <span
            class="error"
            v-if="!!!result.start_time && hasError"
          >
            {{ $t('category.req', { type: $t('prod.sTime')}) }}
          </span>
        </div>

        <div
          class="input-wrapper mlr-7-5"
          :class="{'red-border': !!!result.end_time && hasError}"
        >
          <label>{{ $t('prod.eTime') }}</label>

          <datetime
            format="DD-MM-YYYY H:i:s"
            width="200px"
            v-model="result.end_time"
            readonly
          />
          <span
            class="error"
            v-if="!!!result.end_time && hasError"
          >
            {{ $t('category.req', { type: $t('prod.eTime')}) }}
          </span>
          <span
            class="error"
            v-else-if="!!result.start_time && !dateValidation"
          >
            {{ $t('prod.greater') }}
          </span>
        </div>

        <div
          class="input-wrapper mlr-7-5"
        >
            <label class="block">
              {{ $t('category.status') }}
            </label>

            <dropdown
              :selectedKey="`${result.status}`"
              :options="statusObj"
              @clicked="dropdownSelected"
            />
            <!--
              A lapsed sale is switched off for us, so extending the dates
              alone would not start it again -- and the only clue would be
              a dropdown reading Private, which is easy to scroll past.
            -->
            <span class="fs-note" v-if="wasAutoExpired">
              {{ $t('fSale.autoExpired') }}
            </span>
        </div>
      </div><!--dply-felx inputs-->

      <product-search
        ref="productSearch"
        :notes="pickerNotes"
        @list="loadPickerMeta"
        @product-clicked="addFlashProduct"
      />

      <h4>{{ $t('fSale.sProd') }}</h4>
      <div class="table-wrapper mb-20 mb-sm-15">
        <table class="mn-w-600x">
          <tr class="lite-bold">
            <th class="fs-product">{{ $t('index.title') }}</th>
            <th>{{ $t('brand.price') }}({{ currencyIcon }})</th>
            <th>{{ $t('fSale.discount') }}</th>
            <th>{{ $t('fSale.sPrice') }}({{ currencyIcon }})</th>
            <th>{{ $t('fSale.qtyLimit') }}</th>
            <th/>
          </tr>

          <tr
            v-for="(item, index) in result.products"
            :key="index"
            class="deletable"
            :class="{deleted: item.deleted}"
          >
            <td class="fs-product">
              <div>
                <nuxt-link
                  :to="`/products/${item.product.id}`"
                  class="dply-felx j-left"
                >
                <lazy-image
                  class="mr-20"
                  :data-src="getThumbImageURL(item.product.image)"
                  :alt="item.product.title"
                />

                  <h5 class="mx-w-400x">{{ item.product.title }}</h5>
                </nuxt-link>
              </div>

            </td>
            <td>
              <span v-if="hasTiers(item)" class="fs-range">
                {{ tierRangeLabel(item) }}
              </span>
              <price-format
                v-else
                :price="item.product.selling"
              />
            </td>
            <!--
              Enter the reduction OR the price; this fills in the other.
              Only the price is stored -- flash_sale_products has one
              column and the storefront derives the saving from it, so a
              stored percentage would be a second copy of the same fact,
              free to drift from the money actually charged.
            -->
            <td class="fs-cell">
              <div class="fs-field">
              <div class="fs-discount">
                <select
                  :disabled="item.deleted"
                  v-model="item.discount_type"
                >
                  <option value="percentage">%</option>
                  <option value="amount">{{ currencyIcon }}</option>
                  <!--
                    Not offered on a product whose variants are priced
                    differently: one absolute price across them is a
                    different reduction on each.
                  -->
                  <option v-if="!hasTiers(item)" value="fixed_price">
                    {{ $t('fSale.fixedPrice') }}
                  </option>
                </select>
                <!--
                  No native min/max. The limit depends on the type in the
                  select beside this, which the admin can change at any
                  moment -- so the browser ends up enforcing the previous
                  type's ceiling and showing its own wording for it
                  ("Value must be less than or equal to 99.99") over the
                  top of ours. priceError() knows which type is selected,
                  recomputes on every change, and is what gates the save,
                  so it is the only validator.
                -->
                <input
                  v-if="item.discount_type !== 'fixed_price'"
                  :disabled="item.deleted"
                  type="number"
                  step="0.01"
                  v-model="item.discount_value"
                  :class="{invalid: !item.deleted && !!priceError(item)}"
                  :placeholder="$t('fSale.discount')"
                  @change="valueChanged(index)"
                />

              </div>
              <span class="fs-msg fs-hint" v-if="!hasTiers(item) && savingLabel(item)">
                {{ savingLabel(item) }}
              </span>
              </div>
            </td>
            <td class="fs-cell fs-num">
              <div class="fs-field">
              <!--
                On a product whose variants are priced differently there
                is no single sale price to type: each variant comes down
                from its own. The resulting range is shown instead.
              -->
              <input
                v-if="item.discount_type === 'fixed_price'"
                :disabled="item.deleted"
                type="number"
                step="0.01"
                v-model="item.price"
                :class="{invalid: !item.deleted && !!priceError(item)}"
                :placeholder="$t('fSale.sPrice')"
                @change="valueChanged(index)"
              />
              <span v-else class="fs-range">
                {{ hasTiers(item) ? saleRangeLabel(item) : saleLabel(item) }}
              </span>
              <span
                class="fs-msg error"
                v-if="!item.deleted && !!priceError(item)"
              >
                {{ priceError(item) }}
              </span>
              </div>
            </td>
            <!--
              Blank is no limit. Once the allowance is gone the product
              goes back to its normal price rather than becoming
              unbuyable, so this never costs a sale.
            -->
            <td class="fs-cell fs-num">
              <div class="fs-field">
              <input
                :disabled="item.deleted"
                type="number"
                step="1"
                v-model="item.quantity_limit"
                :placeholder="$t('fSale.noLimit')"
                @change="valueChanged(index)"
              />
              <span class="fs-msg fs-hint" v-if="allowanceLabel(item)">
                {{ allowanceLabel(item) }}
              </span>
              </div>
            </td>
            <td
              class="undo-container fs-cell"
            >
              <button
                v-if="item.deleted"
                @click.prevent="undoDelete(index)"
                class="lite-btn"
              >
                {{ $t('fSale.undo') }}
              </button>
              <button
                v-else
                @click.prevent="deleteProduct(index)"
                class="lite-btn delete-btn"
              >
                {{ $t('category.delete') }}
              </button>
            </td>
          </tr>
        </table>
      </div>

    </template>
  </data-page>
</template>

<script>

  import datetime from 'vuejs-datetimepicker'
  import {mapGetters, mapActions} from 'vuex'
  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import Spinner from '~/components/Spinner'
  import util from '~/mixin/util'
  import {debounce} from 'debounce'
  import moment from 'moment-timezone'
  import ProductInventory from "../../components/partials/ProductInventory";
  import ProductSearch from "../../components/partials/ProductSearch";
  import LazyImage from "../../components/LazyImage";
  import PriceFormat from "../../components/partials/PriceFormat";

  export default {
    name: "flash-sale",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {

        formError: '',
        /* Keyed by product id; see ProductSearch's `notes` prop. */
        pickerNotes: {},
        pickerMeta: {},
        result: {
          id: '',
          title: '',
          status: 2,
          start_time: '',
          end_time: '',
          time_zone: this.timeZone,
          products: []
        }
      }
    },
    mixins: [util],
    components: {
      PriceFormat,
      LazyImage,
      ProductSearch,
      ProductInventory,
      DataPage,
      Dropdown,
      datetime,
      Spinner
    },
    watch: {
      searchedString: debounce(function () {
        this.fetchingData()
      }, 700)
    },
    computed: {
      /*
       * The picker hands these back as DD-MM-YYYY HH:mm:ss, which Date()
       * cannot parse -- both sides came out Invalid Date, the comparison
       * was false for every pair of dates, and the "end must be greater"
       * message showed whenever ANY required field was empty. moment is
       * already imported for exactly this format.
       */
      dateValidation() {
        const start = moment(this.result.start_time, 'DD-MM-YYYY HH:mm:ss', true)
        const end = moment(this.result.end_time, 'DD-MM-YYYY HH:mm:ss', true)

        if (!start.isValid() || !end.isValid()) return false

        return end.isAfter(start)
      },

      /*
       * Switched off because its window closed, rather than by hand.
       * Cannot be known for certain -- Private is Private -- but a sale
       * that is off AND finished is the case worth warning about, and
       * saying so costs nothing when the admin switched it off himself.
       */
      wasAutoExpired() {
        if (`${this.result.status}` !== '2') return false

        const end = moment(this.result.end_time, 'DD-MM-YYYY HH:mm:ss', true)

        return end.isValid() && end.isBefore(moment())
      },

      /* Rows still in the sale -- a deleted one has nothing to check. */
      liveProducts() {
        return (this.result.products || []).filter(i => !i.deleted)
      },

      productsValid() {
        return this.liveProducts.length > 0
          && this.liveProducts.every(i => !this.priceError(i))
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting']),
    },
    methods: {
      /*
       * The page's own checks, which nothing used to run.
       *
       * DataPage only tests that each validation-key is truthy, so the
       * end-before-start comparison and the per-product prices were shown
       * on screen and never gated the save -- the server was the only
       * thing stopping any of it, and for the prices it was not stopping
       * them either.
       */
      submitForm() {
        this.formError = ''

        if (this.result.start_time && this.result.end_time && !this.dateValidation) {
          this.formError = this.$t('prod.greater')
          return this.$store.dispatch('ui/setToastError', this.formError)
        }

        if (this.liveProducts.length === 0) {
          this.formError = this.$t('fSale.noProducts')
          return this.$store.dispatch('ui/setToastError', this.formError)
        }

        const bad = this.liveProducts.find(i => this.priceError(i))

        if (bad) {
          this.formError = this.priceError(bad)
          return this.$store.dispatch('ui/setToastError', this.formError)
        }

        /*
         * The server stores the shape, not just the resolved figure --
         * a percentage has to stay a percentage so it can be applied to
         * each variant's own price.
         */
        this.result.products.forEach(row => {
          if (!this.hasTiers(row) && !row.deleted) {
            row.discount_type = 'fixed_price'
          }
        })

        this.$refs.dataPage.checkForm()
      },

      /*
       * What one price becomes under this row's reduction.
       *
       * There is no longer a pair of inputs writing to each other. The
       * type says which one is the source of truth -- a typed absolute
       * price, or a reduction whose OUTCOME is displayed -- so the two
       * can no longer disagree. They did: the sale price was worked out
       * from products.selling while the range beside it was worked out
       * from the variant tiers, which is how 44.49% showed a saving of
       * EUR 400 next to a range of 388.01-554.54.
       */
      saleLabel(item) {
        const base = this.currentPrice(item)
        const value = parseFloat(item.discount_value)

        if (!base || isNaN(value) || value < 0) return '—'

        return `${this.currencyIcon}${this.reduced(item, base).toFixed(2)}`
      },

      /* "12 of 50 sold", once a row has a cap and has started selling. */
      allowanceLabel(item) {
        const limit = parseInt(item.quantity_limit, 10)

        if (!(limit > 0)) return ''

        return this.$t('fSale.sold', {sold: parseInt(item.quantity_sold, 10) || 0, limit})
      },

      /* "Saves 5.50 (55%)", from the two figures on the row. */
      savingLabel(item) {
        const base = this.currentPrice(item)
        const price = this.discountType(item) === 'fixed_price'
          ? parseFloat(item.price)
          : this.reduced(item, base)

        if (!base || isNaN(price) || !(price > 0) || price >= base) return ''

        const off = base - price
        const pct = Math.round((off / base) * 100)

        return this.$t('fSale.saves', {
          amount: `${this.currencyIcon}${off.toFixed(2)}`,
          percent: pct
        })
      },

      /*
       * Two different prices doing two different jobs. Collapsing them into
       * one "ceiling" is what made the arithmetic disagree with the screen:
       * a product listed at 899 and on offer at 699 took 251 off and showed
       * 448, because the subtraction was quietly using the offer.
       */

      /*
       * The form's window as UTC, which is what the server compares
       * against. The picker fields are local DD-MM-YYYY, so an unparsed
       * one is simply left out rather than sent as garbage.
       */
      windowParams(){
        const start = moment(this.result.start_time, 'DD-MM-YYYY HH:mm:ss', true)
        const end = moment(this.result.end_time, 'DD-MM-YYYY HH:mm:ss', true)

        if (!start.isValid() || !end.isValid()) return {}

        return {
          start: start.utc().format('YYYY-MM-DD HH:mm:ss'),
          end: end.utc().format('YYYY-MM-DD HH:mm:ss')
        }
      },

      /* The price tiers the server reported for this product. */
      tiers(item) {
        const tiers = item?.tiers?.tiers

        return Array.isArray(tiers) ? tiers.map(Number).filter(n => n > 0) : []
      },

      /*
       * The row's discount type, normalised.
       *
       * Everything that reads the type goes through here so the
       * display and the validation cannot disagree about what kind
       * of row this is -- a value they read differently showed a
       * sale range worked out as a percentage while the checks
       * passed it as an amount, with the browser enforcing the
       * ceiling of whichever type had been selected before.
       */
      discountType(item) {
        const type = item?.discount_type

        return ['percentage', 'amount', 'fixed_price'].indexOf(type) > -1
          ? type
          : 'percentage'
      },

      /* More than one price among the variants: no single sale price. */
      hasTiers(item) {
        return this.tiers(item).length > 1
      },

      cheapestTier(item) {
        const tiers = this.tiers(item)

        return tiers.length ? Math.min(...tiers) : this.currentPrice(item)
      },

      /* What one tier becomes under the reduction on this row. */
      reduced(item, base) {
        const value = parseFloat(item.discount_value)

        if (isNaN(value) || value < 0) return base

        const off = this.discountType(item) === 'amount'
          ? value
          : (base * Math.min(value, 100)) / 100

        return Math.round(Math.max(base - off, 0) * 100) / 100
      },

      /* "€699.00 – €999.00", the prices before the sale. */
      tierRangeLabel(item) {
        const tiers = this.tiers(item)

        if (!tiers.length) return ''

        return `${this.currencyIcon}${Math.min(...tiers).toFixed(2)} – `
          + `${this.currencyIcon}${Math.max(...tiers).toFixed(2)}`
      },

      /* And what they become. */
      saleRangeLabel(item) {
        const tiers = this.tiers(item)

        if (!tiers.length) return ''

        const out = tiers.map(t => this.reduced(item, t))

        return `${this.currencyIcon}${Math.min(...out).toFixed(2)} – `
          + `${this.currencyIcon}${Math.max(...out).toFixed(2)}`
      },

      /*
       * The list price -- what the Price column shows, and what a discount
       * is taken OFF.
       *
       * Also what the storefront measures its "% off" badge against:
       * Pricing::displayRange sets compare to products.selling, so the
       * saving quoted here is the saving the customer will be shown.
       */
      listPrice(item) {
        return parseFloat(item?.product?.selling) || 0
      },

      /*
       * What the customer pays TODAY -- the offer price when there is one.
       * A sale has to come in under this or it reduces nothing, which is
       * also the ceiling FlashSalesController::reviewPrice enforces
       * (Pricing::shelfPrice). Not the same number as the list price on
       * anything already discounted.
       */
      currentPrice(item) {
        const selling = parseFloat(item?.product?.selling) || 0
        const offered = parseFloat(item?.product?.offered) || 0

        return (offered > 0 ? offered : selling) || 0
      },

      /*
       * Empty when the row is fine, otherwise the reason it is not.
       *
       * On a product with tiers the reduction is judged against the
       * CHEAPEST -- clear that and every dearer tier clears too. On a
       * single-price product this is the old absolute-price check.
       */
      priceError(item) {
        if (this.hasTiers(item)) {
          const value = parseFloat(item.discount_value)
          const cheapest = this.cheapestTier(item)

          if (isNaN(value) || !(value > 0)) {
            return this.$t('fSale.discountAboveZero')
          }

          if (this.discountType(item) === 'percentage' && value >= 100) {
            return this.$t('fSale.discountUnderHundred')
          }

          if (this.discountType(item) === 'amount' && value >= cheapest) {
            return this.$t('fSale.discountUnderCheapest', {
              price: `${this.currencyIcon}${cheapest.toFixed(2)}`
            })
          }

          return ''
        }

        if (this.discountType(item) === 'fixed_price') {
          return this.absolutePriceError(item)
        }

        /*
         * A single-tier product set by percentage or amount: the same
         * rules as a tiered one, against its one price. It used to
         * fall through to the absolute check, which reads item.price
         * -- empty on these rows -- and answered "Must be above
         * zero" on a perfectly good 20% reduction.
         */
        const value = parseFloat(item.discount_value)
        const base = this.currentPrice(item)

        if (isNaN(value) || !(value > 0)) {
          return this.$t('fSale.discountAboveZero')
        }

        if (this.discountType(item) === 'percentage' && value >= 100) {
          return this.$t('fSale.discountUnderHundred')
        }

        if (this.discountType(item) === 'amount' && value >= base) {
          return this.$t('fSale.discountUnderCheapest', {
            price: `${this.currencyIcon}${base.toFixed(2)}`
          })
        }

        return ''
      },

      absolutePriceError(item) {
        const raw = item?.price

        if (raw === '' || raw === null || raw === undefined || isNaN(parseFloat(raw))) {
          return this.$t('fSale.priceAboveZero')
        }

        const price = parseFloat(raw)

        if (!(price > 0)) {
          return this.$t('fSale.priceAboveZero')
        }

        const current = this.currentPrice(item)

        if (current && price > current) {
          /*
           * Name the figure, and say where it comes from.
           *
           * On a product already discounted in the catalogue the number
           * being enforced is the OFFER price, which is not the one in
           * the Price column -- an iPhone listed at 899 and selling at
           * 699 refused a sale price of 871 with no hint as to why 699
           * was involved. When there is no offer the two are the same
           * number and saying "already reduced" would be nonsense, so
           * the plain wording is used instead.
           */
          const money = `${this.currencyIcon}${current.toFixed(2)}`

          return this.listPrice(item) > current
            ? this.$t('fSale.priceAboveOffer', {price: money})
            : this.$t('fSale.priceAboveRetail', {price: money})
        }

        return ''
      },

      settingResult(evt){
        evt['start_time'] = moment(moment.utc(evt['start_time'])).local().format('DD-MM-YYYY HH:mm:ss')
        evt['end_time'] = moment(moment.utc(evt['end_time'])).local().format('DD-MM-YYYY HH:mm:ss')

        /*
         * The row says how it was set up, so use it. This used to force
         * every row to 'percentage' with an empty value and then derive a
         * percentage back out of the price -- so a sale saved as "EUR 100
         * off" reopened as a percentage, and saving again stored it as
         * one. The shape only started coming back from the server when
         * flash_sale_products grew discount_type; this was written before
         * that and nothing updated it.
         */
        evt.products = (evt.products || []).map(item => ({
          ...item,
          discount_type: item.discount_type || 'fixed_price',
          discount_value: item.discount_type && item.discount_type !== 'fixed_price'
            ? item.discount_value
            : ''
        }))

        this.result = {...evt, time_zone: this.timeZone}
      },
      valueChanged(index){
        this.result.products[index] = {
          ...this.result.products[index],
          ...{updated: true}
        }
      },
      /*
       * "Single product" / "36 variants · 3 price tiers".
       *
       * The tier count is the part that matters, not the variant
       * count: a product with six variants all at one price takes an
       * absolute sale price perfectly well, and saying "1 price
       * tiers" at it was both wrong-sounding and misleading.
       */
      pickerNote(row){
        const variants = Number(row?.variants) || 0

        if (variants < 1) return this.$t('fSale.singleProduct')

        const label = variants === 1
          ? this.$t('fSale.variantOne')
          : this.$t('fSale.variantMany', {count: variants})

        const tiers = (row.tiers || []).length

        return tiers > 1
          ? this.$t('fSale.variantTiers', {variants: label, tiers})
          : this.$t('fSale.variantOnePrice', {variants: label})
      },

      /*
       * What the picker shows against each product: whether it is a
       * single product or a set of variants, and whether another flash
       * sale already holds it.
       *
       * Neither can be read off the product row the picker already has,
       * so both come from the server, a page of results at a time.
       */
      async loadPickerMeta(ids){
        if (!ids || !ids.length) return

        try {
          const meta = await this.getRequest({
            params: {
              ids: ids.join(','),
              exclude: this.result.id || '',
              /*
               * The window being written, so the picker answers the same
               * question the save will: does another sale run over these
               * dates? Without it the server falls back to "any sale that
               * has not finished", which over-blocks a product whose only
               * other sale is months away.
               */
              ...this.windowParams()
            },
            api: 'getFlashSaleProductMeta'
          })

          const notes = {...this.pickerNotes}
          const cache = {...this.pickerMeta}

          Object.keys(meta || {}).forEach(id => {
            const row = meta[id]
            const held = row.held_by

            cache[id] = row

            notes[id] = {
              note: this.pickerNote(row),
              /*
               * A product may be in one flash sale at a time -- the save
               * refuses a second -- so it is refused here too, where
               * there is room to say which sale has it.
               */
              blocked: !!held,
              blockedLabel: held
                ? this.$t(held.live ? 'fSale.inLiveSale' : 'fSale.inOtherSale',
                    {title: held.title})
                : ''
            }
          })

          this.pickerNotes = notes
          this.pickerMeta = cache
        } catch (e) {
          /*
           * A note is a convenience, so a failure here must not stop the
           * picker working -- but it must not be silent either. An empty
           * catch here hid a plain TypeError (getRequest was not mapped
           * into this page) and the picker simply showed no notes, with
           * nothing in the console to say why.
           */
          console.warn('[flash-sale] could not load picker notes:', e)
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err'))
        }
      },

      /*
       * The product payload the search returns says nothing about the
       * variants, and without the tiers the row cannot preview a
       * reduction or tell whether an absolute price is even valid. One
       * request per product added; an existing sale carries them already.
       */
      async loadTiers(index, productId){
        try {
          const tiers = await this.getById({
            id: productId, params: {}, api: 'getFlashSaleProductTiers'
          })

          this.$set(this.result.products, index, {
            ...this.result.products[index], tiers
          })
        } catch (e) {
          this.$store.dispatch('ui/setToastError',
            e?.message || this.$t('error.err'))
        }
      },

      addFlashProduct(product){
        if(this.result.products.findIndex((o) => {
          return o.product.id === product.id
        }) === -1){
          this.result.products.push({
            /*
             * Empty, not 0. Seeding the input with zero meant one
             * forgotten field saved a sale that gave the product away --
             * the basket and the order both charged 0.00 for it.
             */
            price: '',
            quantity_limit: '',
            discount_type: 'percentage',
            discount_value: '',
            product: {
              id: product.id,
              title: product.title,
              image: product.image,
              offered: product.offered,
              selling: product.selling
            }
          })

          const last = this.result.products.length - 1
          const cached = this.pickerMeta[String(product.id)]

          if (cached) {
            // Already fetched for the picker note; no second request.
            this.$set(this.result.products, last, {
              ...this.result.products[last], tiers: cached
            })
          } else {
            this.loadTiers(last, product.id)
          }
        }
        this.$refs.productSearch.autoSuggestionClose()
      },
      dropdownSelected(data) {
        this.result.status = data.key
      },
      deleteProduct(index){
        this.result.products[index] = {
          ...this.result.products[index],
          ...{deleted: true}
        }
        this.result = {...this.result, ...{products: this.result.products}}
      },
      undoDelete(index){
        this.result.products[index] = {
          ...this.result.products[index],
          ...{deleted: false}
        }
        this.result = {...this.result, ...{products: this.result.products}}
      },
      ...mapActions('common', ['getById', 'getRequest'] )
    },
    async mounted() {
    }
  }
</script>

<style scoped>
/*
 * One centre line across the row.
 *
 * Every cell here is middle-aligned, which is what the rest of the admin's
 * tables do -- the previous attempt top-aligned only the three input cells,
 * so on a row made tall by its 100px thumbnail the controls floated nine
 * pixels above the product title and the prices beside them.
 *
 * Middle alignment on its own is not enough, because a cell carrying a
 * validation message has taller content than one that does not, and half
 * that difference shifts its control. So the message is taken OUT of flow:
 * .fs-field is only ever as tall as the control inside it, whatever is
 * written underneath, and every control therefore centres on the same line
 * as the title.
 */
.fs-cell {
  vertical-align: middle;
  /* Room under the control for a message, without moving the control:
     padding stays symmetric, so the content still centres. */
  height: 84px;
}

.fs-field {
  position: relative;
  display: inline-block;
  vertical-align: middle;
}

/*
 * Out of flow, so it cannot change the height of the cell it sits in and
 * cannot push its own control off the row's centre line.
 */
.fs-cell .fs-msg {
  position: absolute;
  top: calc(100% + 4px);
  /* A little wider than the control it belongs to, so a message like
     "Must be below €699.00" sits on one line. It is out of flow, so
     the overhang costs the layout nothing. */
  left: -22px;
  right: -22px;
  margin: 0;
  font-size: 11.5px;
  line-height: 1.3;
  text-align: center;
}

/* The tier range, where a single figure cannot be shown. */
.fs-note {
  display: block;
  margin-top: 6px;
  max-width: 260px;
  font-size: 11.5px;
  line-height: 1.4;
  color: var(--warning-text, #7A5A00);
}

.fs-muted {
  color: var(--text-muted, #6B7280);
}

.fs-range {
  display: inline-block;
  font-size: 12.5px;
  line-height: 38px;
  white-space: nowrap;
}

.fs-cell .fs-hint {
  color: var(--success-text, #027A48);
}

/* A thumbnail sized for a picker row rather than a product page. The table's
   own rule puts every image in a cell at 100px, which is what made these
   rows tall enough for the misalignment to show. */
.fs-product img {
  width: 42px;
  height: 42px;
  border-radius: 7px;
  object-fit: cover;
  background: #F2F4F8;
}

/* Type and value read as one control, not two fields side by side. */
.fs-cell .fs-discount {
  display: flex;
  align-items: stretch;
  width: 150px;
}

.fs-cell .fs-discount select {
  flex: 0 1 auto;
  min-width: 52px;
  height: 38px;
  padding: 0 6px;
  font-size: 13px;
  color: inherit;
  background: var(--bg-color, #fff);
  border: 1px solid var(--border-strong, #D1D5DB);
  border-right: 0;
  border-radius: var(--radius-md, 8px) 0 0 var(--radius-md, 8px);
}

/*
 * On a Set price row the select is the only control in the group, so
 * it stops being the left half of a segmented pair and closes up.
 */
.fs-cell .fs-discount select:last-child {
  border-right: 1px solid var(--border-strong, #D1D5DB);
  border-radius: var(--radius-md, 8px);
}

.fs-cell .fs-discount input {
  flex: 1 1 auto;
  min-width: 0;
  padding: 0 10px;
  border-radius: 0 var(--radius-md, 8px) var(--radius-md, 8px) 0;
}

/* Sale price and Limit: one width, so the columns read as a pair. */
.fs-cell.fs-num input {
  width: 118px;
  padding: 0 10px;
}

/*
 * The controls are inline-level, so without this they sit on the cell's
 * text baseline and the 20px line-height strut drops them against each
 * other.
 */
.fs-cell input,
.fs-cell select,
.fs-cell button {
  vertical-align: middle;
}

/*
 * table.styl gives every button inside a cell `margin: 2px`, which offsets
 * the Delete button from the inputs beside it.
 */
.fs-cell button {
  margin-top: 0;
  margin-bottom: 0;
}

.fs-cell.undo-container {
  white-space: nowrap;
}

@media (max-width: 767px) {
  .fs-cell .fs-discount,
  .fs-cell.fs-num input {
    width: 120px;
  }
}
/*
 * The product column reads left-to-right from its thumbnail, so the
 * heading belongs over the START of the column, not the middle of it --
 * centred, "Title" sat 38px away from the name it labels. This is the
 * same treatment .ups-picker-table gives its product column, which is
 * the closest thing the admin has to a house style for a picker.
 *
 * !important only because `.lite-bold th` in table.styl hard-codes
 * `text-align: center !important` for every header row in the admin;
 * nothing weaker can reach it. Every other column here is a number or a
 * control and stays centred.
 */
.fs-product {
  text-align: left !important;
}
</style>
