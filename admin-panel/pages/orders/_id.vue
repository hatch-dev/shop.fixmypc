<template>
  <div class="detail-width">

    <error-formatter
    />

    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
      />
    </div>

    <p
      v-if="orderCancelled"
      class="info-msg danger-msg order-wrapper mb-15"
    >
      {{ $t('fSale.can', { type: $t('fSale.order') }) }}
    </p>

    <p
      v-if="refunded"
      class="info-msg success-msg order-wrapper mb-15"
    >
      {{ $t('fSale.ref', { type: $t('fSale.order') }) }}
    </p>

    <cancellation
      v-if="openCancellationMessage"
      v-outside-click="outsideClick"
      @close="openCancellationMessage = false"
    />
    <div
      v-if="result"
      class="order-wrapper tab-sidebar"
    >
      <div
        class="title"
      >
        <div
          class="dply-felx d-block-sm"
        >
          <h4
            class="mb-sm-10"
          >
            {{ $t('fSale.oDetail') }}
          </h4>
          <template
            v-if="!isVendor"
          >
            <div
              v-if="!orderCancelled"
              class="dply-felx j-left f-wrap mlr--5 mtb-sm--5"
            >
              <dropdown
                class="mlr-5 mtb-sm-5"
                :selected-key="result.status"
                :options="orderStatus"
                @clicked="selectedStatus"
              />

              <ajax-button
                name="save-edit"
                class="primary-btn mlr-5 mtb-sm-5"
                :text="$t('profile.us')"
                :loading-text="$t('profile.updatn')"
                :fetching-data="formSubmitting"
                type="button"
                @clicked="updateStatus"
              />
            </div>

            <div
              class="dply-felx mlr--7-5"
              v-if="cancellationMessage"
            >
              <ajax-button
                name="cancellation-find"
                class="primary-btn mlr-7-5"
                :text="$t('fSale.cReason')"
                type="button"
                @clicked="openCancellationMessage = true"
              />
              <ajax-button
                v-if="!isCashOnDelivery && paymentDone"
                class="primary-btn mlr-7-5"
                type="button"
                :loading-tex="$t('fSale.refunding')"
                :disabled="refunded"
                :fetching-data="refunding"
                :text="refunded ? $t('fSale.refUp') : $t('fSale.refund')"
                @clicked="refund"
              />
            </div>
          </template>

        </div>
      </div>

      <div class="form-wrapper">
        <div
          class="dply-felx align-end block-sm mb-30 mb-sm-15 mlr--7-5"
        >
          <p class="mx-w-400x mb-sm-10 mlr-7-5">
            <b>{{ getDataFromObject(result, 'address.name', $t('prod.na')) }}</b>
            <span
              v-if="getDataFromObject(result, 'address')"
              class="block"
            >
              {{ generateAddress(getDataFromObject(result, 'address')) }}
            </span>
            <span class="block">{{ $t('fSale.email') }}: {{ userEmail }}</span>
            <span class="block">{{ $t('fSale.phone') }}: {{ getDataFromObject(result, 'address.phone', $t('prod.na')) }}</span>
          </p>
          <ul class="mx-w-400x order-details lh-2 mlr-7-5">
            <li>
              <span>{{ $t('fSale.orderUp') }}</span>
              <span>#{{ result.order }}</span>
            </li>
            <li>
              <span>{{ $t('fSale.sStatus') }}</span>
              <span>{{ getDataFromObject(orderStatus[result.status], 'title') }}</span>
            </li>
            <li class="mtb-10">
              <span>{{ $t('fSale.pMethod') }}</span>
              <span v-if="isVendor">{{ paymentTypes[result.order_method] }}</span>
              <span v-else>
                <dropdown
                  :selected-key="result.order_method"
                  :options="paymentTypesDrop"
                  :default-null="true"
                  @clicked="orderMethodChanged"
                />
              </span>
            </li>
            <li class="mtb-10">
              <span>{{ $t('fSale.pStatus') }}</span>
              <span v-if="isVendor">
                {{ parseInt(result.payment_done) === status.PUBLIC ? $t('fSale.paid') : $t('fSale.unpaid') }}
              </span>
              <span v-else>
                <dropdown
                  :selected-key="result.payment_done"
                  :options="paymentStatus"
                  @changed="selectedPaymentStatus"
                />
              </span>
            </li>
            <li>
              <span>{{ $t('category.date') }}</span>
              <span>{{ result.created }}</span>
            </li>
            <li v-if="!isVendor">
              <span>{{ $t('fSale.amount') }}</span>
              <span>{{ priceFormatting(totalPrice) }}</span>
            </li>

            <template v-if="isBackPayment">
              <li>
                <span>{{ $t('title.ti') }}</span>
                <span>{{ result.trans_id }}</span>
              </li>
            </template>
          </ul>
        </div>

        <div class="table-wrapper">
          <table class="mn-w-600x no-bg">
            <tr class="lite-bold">
              <th>{{ $t('index.title') }}</th>
              <th>{{ $t('fSale.shipTo') }}</th>
              <th>{{ $t('fSale.dFee') }}</th>
              <th>{{ $t('fSale.qty') }}</th>
              <th>{{ $t('fSale.bOffer') }}({{ currencyIcon }})</th>
              <th>{{ $t('brand.price') }}({{ currencyIcon }})</th>
              <th>{{ $t('fSale.total') }}({{ currencyIcon }})</th>
            </tr>

            <ordered-product
              v-for="(value, index) in result.ordered_products"
              :key="index"
              :ordered-product="value"
            />

          </table><!--table-->

        </div>

        <div v-if="!isVendor" class="dply-felx j-right mt-20 mt-sm-15">
          <ul class="mx-w-400x order-details order-price">
            <li>
              <span>{{ $t('fSale.sTotal') }}</span>
              <span>{{ priceFormatting(subtotalPrice) }}</span>
            </li>
            <li>
              <span>{{ $t('fSale.sCost') }}</span>
              <span
                v-if="isFreeShipping"
              >
                {{ $t('ship.fre') }}
              </span>
              <span
                v-else
              >{{ priceFormatting(shippingPrice) }}</span>
            </li>
            <li v-if="bundleOffer">
              <span>{{ $t('fSale.bOffer') }}</span>
              <span>{{ priceFormatting(bundleOffer) }}</span>
            </li>
            <li v-if="voucherPrice">
              <span>{{ $t('fSale.voucher') }}</span>
              <span>{{priceFormatting(voucherPricen)}}</span>
            </li>
            <li
              v-if="taxPrice"
            >
              <span>{{ $t('fSale.tax') }}</span>
              <span>{{ priceFormatting(taxPrice) }}</span>
            </li>
            <li>
              <span>{{ $t('fSale.total') }}</span>
              <span>{{ priceFormatting(totalPrice) }}</span>
            </li>
          </ul>
        </div>

        <invoice
          v-if="storeData"
          :order="result"
        />

      </div>
    </div>
  </div>
</template>

<script>

  import Invoice from '~/components/partials/Invoice'
  import Cancellation from '~/components/partials/Cancellation'
  import AjaxButton from '~/components/AjaxButton'
  import Spinner from '~/components/Spinner'
  import Dropdown from '~/components/Dropdown'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import outsideClick from '~/directives/outside-click'
  import LazyImage from "~/components/LazyImage"
  import OrderedProduct from "../../components/partials/OrderedProduct";
  import ErrorFormatter from "../../components/ErrorFormatter";

  export default {
    name: "order",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        statusChanged: false,
        refunding: false,
        formSubmitting: false,
        openCancellationMessage: false,
        logoBase64: null,
        result: null,
        loading: false
      }
    },
    directives: {outsideClick},
    mixins: [util],
    components: {
      ErrorFormatter,
      OrderedProduct,
      LazyImage,
      Dropdown,
      AjaxButton,
      Invoice,
      Cancellation,
      Spinner
    },
    computed: {
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      userEmail(){
        return this.result?.user?.email ?? this.result?.guest_user?.email ?? this.$t('prod.na')
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      refunded(){
        return parseInt(this.result?.cancellation?.refunded) === this.status.PUBLIC || false
      },
      paymentDone(){
        return parseInt(this.result?.payment_done) === this.status.PUBLIC
      },
      isBackPayment(){
        return parseInt(this.result?.order_method) === this.orderMethodsIn.BANK
      },
      isCashOnDelivery(){
        return parseInt(this.result?.order_method) === this.orderMethodsIn.CASH_ON_DELIVERY
      },
      cancellationMessage(){
        return this.result?.cancellation || null
      },
      orderCancelled(){
        return (parseInt(this.result?.cancelled) === this.status.PUBLIC)
      },
      totalPrice(){
        return this.result?.calculated.total_price
      },
      voucherPrice(){
        return this.result?.calculated.voucher_price
      },
      bundleOffer(){
        return this.result?.calculated.bundle_offer
      },
      shippingPrice(){
        return this.result?.calculated.shipping_price
      },
      taxPrice(){
        return this.result?.calculated.tax
      },
      subtotalPrice(){
        return this.result?.calculated.subtotal
      },
      id() {
        return this.$route?.params?.id
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('admin', ['isVendor']),
      ...mapGetters('resource', ['countryList']),
      ...mapGetters('setting', ['storeData', 'setting'])
    },
    methods: {
      async orderMethodChanged(evt){
        this.loading = true
        try {
          const data = await this.setRequest({
            params: {
              order_method: evt.key,
              id: this.id
            },
            api: 'paymentMethod'
          })

          if(data?.order_method) {
            this.result.order_method = data.order_method
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      },
      async refund(){
        this.refunding = true
        try {
          const data = await this.getById({id: this.result?.cancellation?.id, params: {}, api: 'setOrderRefund'})
          if(data){
            this.result = {...this.result, ...{cancellation: {...this.result.cancellation, ...{refunded: data.refunded}}}}
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.refunding = false
      },
      outsideClick() {
        this.openCancellationMessage = false
      },
      async selectedPaymentStatus(evt){
          this.loading = true
          try {
            const data = await this.setRequest({
              params: {
                payment_done: evt.key,
                id: this.id
              },
              api: 'paymentStatus'
            })

            if(data?.payment_done) {
              this.result.payment_done = data.payment_done
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
      },
      selectedStatus(evt){
        this.statusChanged = true
        this.result = { ...this.result, ...{ status: evt.key } }
      },
      async updateStatus(){
        if(!this.statusChanged){
          return false
        }
        this.statusChanged = false
        this.formSubmitting = true
        try {
          await this.setRequest({
            params: {
              status: this.result.status,
              id: this.id
            },
            api: 'updateOrderStatus'
          })
        } catch (e) {
          return this.$nuxt.error(e)
        }

        this.formSubmitting = false

        try {
          if(this.orderStatusIn.DELIVERED === parseInt(this.result.status)) {
            await this.getById({
              id: this.id,
              params: { },
              api: 'sendStatusUpdateEmail'
            })
          }
        } catch (e) {
          console.error(e)
        }
      },
      generateAddress(obj) {
        if(!obj){
          return this.$t('fSale.noAddr')
        }
        let addArr = []
        addArr.push(obj?.address_1)
        if (obj?.address_2) {
          addArr.push(obj?.address_2)
        }
        addArr.push(obj?.city + '-' + obj?.zip)

        if (this.countryList[obj?.country]) {
          const country = this.countryList[obj?.country]

          if (country.states[obj?.state]) {
            addArr.push(country.states[obj.state]?.name)
          }
          addArr.push(country?.name)
        }
        return  addArr.join(', ')
      },
      async fetchingData() {
        try {
          this.loading = true
          this.result= Object.assign({}, await this.getById({id: this.id, params: {time_zone: this.timeZone}, api: 'getOrder'}))
          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('setting', ['getSetting']),
      ...mapActions('common', ['setRequest', 'getById', 'setById'])
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

      if (!this.storeData) {
        this.loading = true
        try {
          await this.getSetting()
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      }
      await this.fetchingData()
    }
  }
</script>
