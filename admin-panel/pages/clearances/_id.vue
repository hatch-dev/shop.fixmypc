<template>
  <data-page
    class="detail-width clearance-form"
    ref="dataPage"
    set-api="setClearance"
    get-api="getClearance"
    route-name="clearances"
    empty-store-variable="allClearances"
    :name="$t('profile.clearance')"
    :validation-keys="['product']"
    :result="result"
    gate="clearance"
    @result="settingResult"
  >
    <template v-slot:form="{hasError}">

      <p class="form-hint mb-20">
        {{ $t('clearancePage.adminHint') }}
      </p>

      <!-- Product picker (single) -->
      <product-search
        :key="productSearchKey"
        ref="productSearch"
        @product-clicked="selectProduct"
      />

      <div
        class="error mb-15"
        v-if="!result.product && hasError"
      >
        {{ $t('category.req', { type: $t('fSale.product') }) }}
      </div>

      <!-- Selected product -->
      <div class="selected-product mb-20" v-if="result.product">
        <nuxt-link :to="`/products/${result.product.id}`" class="sp-thumb">
          <img
            :src="getThumbImageURL(result.product.image)"
            :alt="result.product.title"
            @error="onImgError"
          >
        </nuxt-link>
        <div class="sp-info">
          <nuxt-link :to="`/products/${result.product.id}`" class="sp-title link">
            {{ result.product.title }}
          </nuxt-link>
          <div class="sp-prices">
            <span class="sp-label">{{ $t('clearancePage.sellsFor') }}</span>

            <span class="sp-price">
              <price-format :price="effectiveBase" :decimals="2" />
              <template v-if="isVariablePriced">
                &ndash; <price-format :price="effectiveBaseMax" :decimals="2" />
              </template>
            </span>

            <!--
              The list price, and only when it is genuinely higher than what
              the product actually sells for. Struck through against an equal
              number reads as a discount that is not there.
            -->
            <span class="sp-was" v-if="listPriceIsHigher">
              <price-format :price="Number(result.product.selling)" :decimals="2" />
            </span>
          </div>
        </div>
        <button
          type="button"
          class="lite-btn delete-btn sp-remove"
          @click.prevent="removeProduct"
        >
          {{ $t('category.delete') }}
        </button>
      </div>

      <!-- Discount (applied to the product's price and every variation) -->
      <div class="dply-felx block-xs mlr--7-5 inputs align-start j-left">
        <div class="input-wrapper mlr-7-5">
          <label>{{ $t('clearancePage.discountType') }}</label>
          <select v-model="result.discount_type" class="discount-select">
            <option value="percentage">{{ $t('clearancePage.percentage') }}</option>
            <option value="fixed">{{ $t('clearancePage.fixed') }}</option>
          </select>
        </div>

        <div class="input-wrapper mlr-7-5">
          <label>
            {{ $t('clearancePage.discountValue') }}
            ({{ result.discount_type === 'percentage' ? '%' : currencyIcon }})
          </label>
          <input
            type="number"
            step="0.01"
            min="0"
            :max="result.discount_type === 'percentage' ? 100 : null"
            v-model.number="result.discount_value"
            placeholder="0"
          >
        </div>

        <div class="input-wrapper mlr-7-5">
          <label class="block">{{ $t('category.status') }}</label>
          <dropdown
            :selectedKey="`${result.status}`"
            :options="statusObj"
            @clicked="dropdownSelected"
          />
        </div>
      </div>

      <div class="error mb-15" v-if="discountError">
        {{ discountError }}
      </div>

      <!-- Live deal price preview -->
      <p class="form-hint mb-20" v-if="result.product">
        {{ $t('clearancePage.dealPriceLabel') }}:
        <strong>
          <template v-if="isVariablePriced">{{ $t('clearancePage.from') }} </template>{{ currencyIcon }}{{ discountedPreview }}
        </strong>
        <span class="strike">{{ currencyIcon }}{{ effectiveBase.toFixed(2) }}</span>

        <!--
          A flat amount comes off every variation equally, so on a product
          whose variations are priced apart it is a different share of each.
          The administrator cannot see that from a single figure.
        -->
        <span v-if="isVariablePriced" class="range-note">
          {{ $t('clearancePage.dearestVariant', {
            price: currencyIcon + discountedPreviewMax,
            was: currencyIcon + effectiveBaseMax.toFixed(2)
          }) }}
        </span>
      </p>

      <!-- Timer + sort order -->
      <div class="dply-felx block-xs mlr--7-5 inputs align-start j-left">
        <div class="input-wrapper mlr-7-5">
          <label>{{ $t('clearancePage.dealEnds') }}</label>
          <input
            type="datetime-local"
            v-model="result.end_time"
          >
        </div>

        <div class="input-wrapper mlr-7-5">
          <label>{{ $t('clearancePage.sortOrder') }}</label>
          <input
            type="number"
            min="0"
            v-model.number="result.sort_order"
            placeholder="0"
          >
        </div>
      </div>

      <!-- Title / subtitle overrides -->
      <div class="input-wrapper">
        <label>{{ $t('clearancePage.titleOverride') }}</label>
        <input
          type="text"
          v-model="result.title"
          :placeholder="result.product ? result.product.title : $t('index.title')"
        >
      </div>

      <div class="input-wrapper">
        <label>{{ $t('clearancePage.subtitleOverride') }}</label>
        <input
          type="text"
          v-model="result.subtitle"
          :placeholder="result.product ? result.product.short_heading : ''"
        >
      </div>

      <!-- Description -->
      <div class="input-wrapper">
        <label>{{ $t('clearancePage.description') }}</label>
        <textarea
          v-model="result.description"
          rows="3"
          :placeholder="$t('clearancePage.descriptionHint')"
        ></textarea>
      </div>

      <!-- Highlights -->
      <div class="input-wrapper">
        <label>{{ $t('clearancePage.highlights') }}</label>
        <textarea
          v-model="result.highlights"
          rows="4"
          :placeholder="$t('clearancePage.highlightsHint')"
        ></textarea>
      </div>

    </template>
  </data-page>
</template>

<script>
  import {mapGetters} from 'vuex'
  import DataPage from '~/components/partials/DataPage'
  import Dropdown from '~/components/Dropdown'
  import util from '~/mixin/util'
  import ProductSearch from "../../components/partials/ProductSearch"
  import PriceFormat from "../../components/partials/PriceFormat"

  export default {
    name: "clearance-edit",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        productSearchKey: 0,
        result: {
          id: '',
          product: null,
          title: '',
          subtitle: '',
          discount_type: 'percentage',
          discount_value: 0,
          description: '',
          highlights: '',
          end_time: '',
          status: 1,
          sort_order: 0,
          // Sent with the save so the timer is stored on the app clock, not
          // on whichever one this browser happens to be set to.
          time_zone: this.timeZone
        }
      }
    },
    mixins: [util],
    components: {
      DataPage,
      Dropdown,
      ProductSearch,
      PriceFormat
    },
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      // The product's own price, before variations are considered.
      productBase() {
        const offered = Number(this.result.product?.offered || 0)
        const selling = Number(this.result.product?.selling || 0)
        return offered > 0 ? offered : selling
      },
      /*
       * Every price a customer could actually pay for this product, which is
       * what the deal is applied to -- one per buyable variation, and the
       * product's own price for a variation that carries none.
       *
       * The same rule as Pricing::displayRange server-side. A variation is
       * buyable when it has stock or is on back-order.
       */
      buyablePrices() {
        const rows = this.result.product?.product_inventories || []

        const prices = rows
          .filter(row => Number(row.quantity) > 0 || Number(row.is_active) === 1)
          .map(row => Number(row.price) > 0 ? Number(row.price) : this.productBase)
          .filter(price => price > 0)

        return prices.length ? prices : (this.productBase > 0 ? [this.productBase] : [])
      },
      /*
       * The price the deal is measured against: the cheapest thing a customer
       * can buy, which is the figure the storefront prints.
       *
       * This read products.offered, which on a product whose variations carry
       * their own prices is a figure nobody pays. On the DELL Latitude 3520 it
       * previewed a EUR 586.49 deal against EUR 689.99 while the storefront
       * charged EUR 501.49 against EUR 589.99 -- the screen was quoting the
       * administrator a price that did not exist.
       */
      effectiveBase() {
        const prices = this.buyablePrices
        return prices.length ? Math.min(...prices) : 0
      },
      // The dearest, so a product priced across a range can say so.
      effectiveBaseMax() {
        const prices = this.buyablePrices
        return prices.length ? Math.max(...prices) : 0
      },
      // "From" when the variations are priced apart.
      isVariablePriced() {
        return this.effectiveBaseMax - this.effectiveBase > 0.005
      },
      /*
       * Is the catalogue list price genuinely above what this product sells
       * for? Only then is there a "was" worth striking through.
       */
      listPriceIsHigher() {
        return Number(this.result.product?.selling || 0) - this.effectiveBase > 0.005
      },
      // The dearest variation's deal price, for the range label.
      discountedPreviewMax() {
        return this.applyDiscount(this.effectiveBaseMax)
      },
      /*
       * What is wrong with the discount, if anything.
       *
       * The server refuses both of these now. Saying so here means the
       * administrator finds out while typing rather than on submit -- a 150%
       * deal used to save and then price the product at zero on the storefront.
       */
      discountError() {
        const value = Number(this.result.discount_value || 0)

        if (value < 0) {
          return this.$t('clearancePage.discountNegative')
        }

        if (this.result.discount_type === 'percentage' && value > 100) {
          return this.$t('clearancePage.discountOverHundred')
        }

        return ''
      },
      // Preview of the resulting deal price (discount applied to effective price).
      discountedPreview() {
        return this.applyDiscount(this.effectiveBase)
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      /*
       * One price with the deal taken off it, clamped the way the server
       * clamps: never below zero, never above what it started at.
       * Mirrors Pricing::clearanceUnitPrice.
       */
      applyDiscount(base) {
        base = Number(base || 0)

        if (base <= 0) return '0.00'

        const value = Number(this.result.discount_value || 0)

        if (value <= 0) return base.toFixed(2)

        const discounted = this.result.discount_type === 'percentage'
          ? base - (base * Math.min(value, 100) / 100)
          : base - value

        return Math.min(base, Math.max(discounted, 0)).toFixed(2)
      },
      // Map the API record into the form. `end_time` (stored as
      // "Y-m-d H:i:s") must become the "Y-m-dTH:i" datetime-local format.
      settingResult(evt) {
        let endTime = ''
        if (evt.end_time) {
          endTime = evt.end_time.replace(' ', 'T').substring(0, 16)
        }
        this.result = {
          ...evt,
          time_zone: this.timeZone,
          status: evt.status || 1,
          sort_order: evt.sort_order || 0,
          title: evt.title || '',
          subtitle: evt.subtitle || '',
          discount_type: evt.discount_type || 'percentage',
          discount_value: evt.discount_value == null ? 0 : Number(evt.discount_value),
          description: evt.description || '',
          highlights: evt.highlights || '',
          end_time: endTime
        }
      },
      selectProduct(product) {
        if (!product) return
        this.result.product = {
          id: product.id,
          title: product.title,
          short_heading: product.short_heading,
          image: product.image,
          offered: product.offered,
          selling: product.selling,
          /*
           * The variations, which the product search already returns. The
           * deal is priced against each variation's own price, so without
           * these the preview below is quoting a figure no customer pays.
           */
          product_inventories: product.product_inventories || []
        }
        this.productSearchKey++
      },
      removeProduct() {
        this.result.product = null
      },
      onImgError(e) {
        const fallback = this.getThumbImageURL(this.defaultImage)
        if (e.target.src !== fallback) {
          e.target.src = fallback
        }
      },
      dropdownSelected(data) {
        this.result.status = data.key
      }
    }
  }
</script>

<style scoped>
.form-hint {
  color: #6F6980;
  font-size: 13px;
}
.form-hint .range-note {
  display: block;
  margin-top: 4px;
  color: #6F6980;
}
.form-hint .strike {
  text-decoration: line-through;
  color: #9aa0ab;
  margin-left: 8px;
}
.discount-select {
  width: 100%;
}

/* Selected product card */
.selected-product {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 16px;
  border: 1px solid #e6e8ee;
  border-radius: 10px;
  background: #fff;
}
.selected-product .sp-thumb {
  flex: 0 0 auto;
  width: 56px;
  height: 56px;
  border-radius: 8px;
  overflow: hidden;
  background: #f4f4f9;
  display: flex;
  align-items: center;
  justify-content: center;
}
.selected-product .sp-thumb img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.selected-product .sp-info {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.selected-product .sp-title {
  font-weight: 600;
  color: #130E2B;
  text-decoration: none;
}
.selected-product .sp-prices {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
}
.selected-product .sp-label {
  color: #6F6980;
}
.selected-product .sp-price {
  color: #130E2B;
  font-weight: 600;
}
.selected-product .sp-was {
  color: #9aa0ab;
  text-decoration: line-through;
}
.selected-product .sp-remove {
  flex: 0 0 auto;
}
</style>

<!-- Non-scoped: stabilise the shared product-search dropdown on this page so
     it grows naturally instead of jumping (the fixed 300px / flex-end base
     style causes the dropdown to flicker as the spinner/list/footer swap). -->
<style>
.clearance-form .search-dropdown {
  justify-content: flex-start;
}
.clearance-form .search-dropdown .suggestion-body {
  min-height: 0;
}
.clearance-form .search-dropdown .suggestion-body ul {
  height: auto;
  max-height: 320px;
}
</style>
