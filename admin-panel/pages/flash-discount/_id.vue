<template>
  <data-page
    ref="dataPage"
    set-api="setFlashDiscount"
    get-api="getFlashDiscount"
    empty-store-variable="allFlashDiscounts"
    route-name="flash-discount"
    :name="$t('pages.flashDiscount.listName')"
    gate="flash_discount"
    :validation-keys="['type', 'value', 'start_time', 'end_time']"
    :result="result"
    @result="result = $event"
  >

    <!--
      hasError is the form's own refusal, raised by DataPage when a field named
      in validation-keys is empty. It used to be destructured here and then
      ignored, so pressing Save on an incomplete form did nothing at all and
      said nothing about why -- the button read as broken.
    -->
    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.typeRequired') }}</label>
        <select v-model="result.type">
          <option value="percentage">{{ $t('pages.flashDiscount.percentage') }}</option>
          <option value="fixed">{{ $t('pages.flashDiscount.fixed') }}</option>
        </select>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.valueRequired') }}</label>
        <input
          type="number"
          min="0"
          step="0.01"
          :max="result.type === 'percentage' ? 100 : null"
          v-model="result.value"
          :class="{invalid: (!result.value && hasError) || !!valueError}"
        >
        <span class="error" v-if="!result.value && hasError">
          {{ $t('pages.flashDiscount.valueIsRequired') }}
        </span>
        <span class="error" v-else-if="valueError">
          {{ valueError }}
        </span>
        <span class="field-hint">
          {{ result.type === 'percentage'
            ? $t('pages.flashDiscount.hintPercent')
            : $t('pages.flashDiscount.hintFixed') }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.minCartValue') }}</label>
        <input type="number" min="0" step="0.01" v-model="result.min_cart_value">
        <span class="field-hint">{{ $t('pages.flashDiscount.hintNoMinimum') }}</span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.maxDiscount') }}</label>
        <input
          type="number"
          min="0"
          step="0.01"
          v-model="result.max_discount"
          :class="{invalid: !!capError}"
        >
        <span class="error" v-if="capError">{{ capError }}</span>

        <!--
          This field used to start at 0, and the checkout read a cap of 0 as a
          ceiling of zero: a promotion saved without touching it paid out
          nothing, while the basket banner still advertised the full discount.
          It starts empty now, and empty means no ceiling all the way through.
        -->
        <span class="field-hint">{{ $t('pages.flashDiscount.hintNoLimit') }}</span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.startTimeRequired') }}</label>
        <input
          type="datetime-local"
          step="1"
          v-model="result.start_time"
          :class="{invalid: !result.start_time && hasError}"
        >
        <span class="error" v-if="!result.start_time && hasError">
          {{ $t('pages.flashDiscount.startTimeIsRequired') }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.endTimeRequired') }}</label>
        <input
          type="datetime-local"
          step="1"
          v-model="result.end_time"
          :class="{invalid: (!result.end_time && hasError) || !!windowError}"
        >
        <span class="error" v-if="!result.end_time && hasError">
          {{ $t('pages.flashDiscount.endTimeIsRequired') }}
        </span>
        <span class="error" v-else-if="windowError">{{ windowError }}</span>
        <span class="field-hint">{{ $t('pages.flashDiscount.hintAfterStart') }}</span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('pages.flashDiscount.status') }}</label>
        <input type="checkbox" v-model="result.is_active"> {{ $t('pages.flashDiscount.active') }}
        <span class="field-hint">
          {{ $t('pages.flashDiscount.hintOneLive') }}
        </span>
      </div>

    </template>
  </data-page>
</template>

<script>
import DataPage from "~/components/partials/DataPage";
import util from "~/mixin/util";

export default {
  middleware: ['common-middleware', 'auth'],
  components: { DataPage },
  mixins: [util],
  data() {
    return {
      result: {
        id: '',
        type: 'percentage',
        value: '',
        // Empty, not 0. Both columns are nullable and "not set" is not "zero".
        min_cart_value: '',
        max_discount: '',
        start_time: '',
        end_time: '',
        is_active: true
      }
    }
  },
  computed: {
    /*
     * The rules the server enforces, restated so the form can say so before
     * a round trip rather than after one. The server is still the authority --
     * Validation::flashDiscount checks all of this again, plus the overlap,
     * which the browser cannot know about.
     */
    valueError() {
      const value = Number(this.result.value)

      if (this.result.value === '' || this.result.value === null) return ''
      if (isNaN(value)) return this.$t('pages.flashDiscount.errValueNumber')
      if (value <= 0) return this.$t('pages.flashDiscount.errValuePositive')

      if (this.result.type === 'percentage' && value > 100) {
        return this.$t('pages.flashDiscount.errPercentMax')
      }

      return ''
    },
    capError() {
      const cap = Number(this.result.max_discount)

      if (this.result.max_discount === '' || this.result.max_discount === null) return ''
      if (isNaN(cap) || cap < 0) return this.$t('pages.flashDiscount.errCapNegative')

      // A fixed discount capped below its own value pays out the cap, which is
      // never what was meant.
      if (this.result.type === 'fixed' && cap > 0 && cap < Number(this.result.value)) {
        return this.$t('pages.flashDiscount.errCapBelowValue')
      }

      return ''
    },
    windowError() {
      if (!this.result.start_time || !this.result.end_time) return ''

      return new Date(this.result.end_time) <= new Date(this.result.start_time)
        ? this.$t('pages.flashDiscount.errEndAfterStart')
        : ''
    }
  }
}
</script>

<style scoped>
.field-hint {
  display: block;
  margin-top: 4px;
  opacity: 0.7;
}
</style>
