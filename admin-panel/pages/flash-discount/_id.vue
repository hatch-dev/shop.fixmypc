<template>
  <data-page
    ref="dataPage"
    set-api="setFlashDiscount"
    get-api="getFlashDiscount"
    empty-store-variable="allFlashDiscounts"
    route-name="flash-discount"
    name="Flash Discount"
    gate="flash_discount"
    :validation-keys="['type', 'value', 'start_time', 'end_time']"
    :result="result"
    @result="result = $event"
  >

    <template v-slot:form="{hasError}">

      <div class="input-wrapper">
        <label>Type</label>
        <select v-model="result.type">
          <option value="fixed">Fixed</option>
          <option value="percentage">Percentage</option>
        </select>
      </div>

      <div class="input-wrapper">
        <label>Value</label>
        <input type="number" v-model="result.value">
      </div>

      <div class="input-wrapper">
        <label>Min Cart Value</label>
        <input type="number" v-model="result.min_cart_value">
      </div>

      <div class="input-wrapper">
        <label>Max Discount</label>
        <input type="number" v-model="result.max_discount">
      </div>

      <div class="input-wrapper">
        <label>Start Time</label>
        <input type="datetime-local" step="1" v-model="result.start_time">
      </div>

      <div class="input-wrapper">
        <label>End Time</label>
        <input type="datetime-local" step="1" v-model="result.end_time">
      </div>

      <div class="input-wrapper">
        <label>Status</label>
        <input type="checkbox" v-model="result.is_active"> Active
      </div>

    </template>
  </data-page>
</template>

<script>
import DataPage from "~/components/partials/DataPage";
import util from "~/mixin/util";

export default {
  components: { DataPage },
  mixins: [util],
  data() {
    return {
      result: {
        id: '',
        type: 'fixed',
        value: 0,
        min_cart_value: 0,
        max_discount: 0,
        start_time: '',
        end_time: '',
        is_active: true
      }
    }
  }
}
</script>