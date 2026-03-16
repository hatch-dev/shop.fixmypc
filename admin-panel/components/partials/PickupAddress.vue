<template>
  <div>
    <div class="input-wrapper dply-felx j-left">
      <label class="mb-0">
        <input
          type="checkbox"
          v-model="result.pickup_point"
          @change="pickupPoint($event)"
        >
        {{ $t('brand.pickPoint') }}
      </label>
      <button
        type="button"
        class="toggle-arrow"
        @click.prevent="showForm = !showForm"
      >
        <i class="icon black arrow-down"/>
      </button>
    </div>


    <div v-if="showForm" class="mlr-15">
      <div class="dply-felx inputs">
        <div
          class="input-wrapper "
        >
          <label>{{ $t('brand.pickPrice') }}({{ currencyIcon }})</label>
          <input
            type="number"
            step="any"
            :placeholder="$t('brand.price')"
            v-model="result.pickup_price"
            @change="valueChanged"
          >
        </div>

        <div class="input-wrapper">
          <label>{{ $t('fSale.phone') }}</label>
          <input
            type="text"
            :placeholder="$t('fSale.phone')"
            v-model="result.pickup_phone"
            @change="valueChanged"
          >
        </div>
      </div>

      <div class="input-wrapper ">
        <label>{{ $t('list.addr') }}</label>
        <input
          type="text"
          :placeholder="`${$t('setting.adr')} 1`"
          v-model="result.pickup_address_line_1"
          @change="valueChanged"
        >
      </div>

      <div class="input-wrapper ">
        <input
          type="text"
          :placeholder="`${$t('setting.adr')} 2`"
          v-model="result.pickup_address_line_2"
          @change="valueChanged"
        >
      </div>

      <div class="dply-felx inputs">
        <div
          class="input-wrapper"
        >
          <label>{{ $t('setting.city') }}</label>
          <input
            type="text"
            :placeholder="$t('setting.city')"
            v-model="result.pickup_city"
            @change="valueChanged"
          >
        </div>

        <div class="input-wrapper">
          <label>{{ $t('setting.state') }}</label>
          <input
            type="text"
            :placeholder="$t('setting.state')"
            v-model="result.pickup_state"
            @change="valueChanged"
          >
        </div>
      </div>

      <div class="dply-felx inputs">
        <div class="input-wrapper">
          <label>{{ $t('setting.zip') }}</label>
          <input
            type="text"
            :placeholder="$t('setting.zip')"
            v-model="result.pickup_zip"
            @change="valueChanged"
          >
        </div>

        <div class="input-wrapper">
          <label>{{ $t('brand.country') }}</label>
          <input
            type="text"
            :placeholder="$t('brand.country')"
            v-model="result.pickup_country"
            @change="valueChanged"
          >
        </div>
      </div>
    </div>
  </div>


</template>

<script>
  import util from "~/mixin/util"
  import ListPage from "~/components/partials/ListPage"
  import TableSort from "~/components/partials/TableSort"
  import VendorOrders from "~/components/partials/VendorOrders"
  import {mapGetters, mapActions} from 'vuex'
  import bulkDelete from "~/mixin/bulkDelete";
  import InlinePopOver from "~/components/InlinePopOver";
  import Invoice from "../../components/partials/Invoice";

  export default {
    name: "PickupAddress",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          pickup_point: false,
          pickup_price: 0,
          pickup_phone: '',
          pickup_address_line_1: '',
          pickup_address_line_2: '',
          pickup_zip: '',
          pickup_state: '',
          pickup_city: '',
          pickup_country: ''
        },
        showForm: false
      }
    },
    mixins: [],
    watch:{
      shippingPlace(){

        this.result = {...this.result, ...this.shippingPlace}
      }
    },
    components: {},
    props: {
      shippingPlace: {
        type: Object,
        default() {
          return {
            pickup_point: false,
            pickup_price: 0,
            pickup_phone: '',
            pickup_address_line_1: '',
            pickup_address_line_2: '',
            pickup_zip: '',
            pickup_state: '',
            pickup_city: '',
            pickup_country: ''
          }
        }
      },
    },
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      valueChanged(){
        this.$emit('result', {
          pickup_point: this.result.pickup_point,
          pickup_price: this.result.pickup_price,
          pickup_phone: this.result.pickup_phone,
          pickup_address_line_1: this.result.pickup_address_line_1,
          pickup_address_line_2: this.result.pickup_address_line_2,
          pickup_zip: this.result.pickup_zip,
          pickup_state: this.result.pickup_state,
          pickup_city: this.result.pickup_city,
          pickup_country: this.result.pickup_country,
        })
      },
      pickupPoint(evt) {
        this.showForm = evt.target.checked
        this.valueChanged()
      }
    },
    mounted() {
      this.result = {...this.result, ...this.shippingPlace}
    }
  }
</script>

<style scoped>

</style>
