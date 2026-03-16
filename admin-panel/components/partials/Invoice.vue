<template>
  <div>
    <client-only>
      <vue-html2pdf
        :show-layout="false"
        :float-layout="true"
        :enable-download="true"
        :preview-modal="false"
        :paginate-elements-by-height="1400"
        :filename="`Invoice-${order.order}`"
        :pdf-quality="3"
        :manual-pagination="false"
        pdf-format="a4"
        pdf-orientation="portrait"
        ref="html2Pdf"
      >
        <div
          slot="pdf-content"
          class="order-wrapper invoice-wrapper f-9"
        >

          <div class="sided p-30 align-start">
            <div class="mx-w-350x">
              <img
                class="h-25x w-auto mb-10"
                :src="`data:image/png;base64,${base64SiteLogo}`"
              >
              <h4
                class="fw-600 mtb-5"
              >
                {{ getDataFromObject(storeData, 'name') }}
              </h4>
              <p> {{generateAddress(setting)}}</p>
              <p>Phone: {{ getDataFromObject(setting, 'phone',  $t('prod.na')) }}</p>
            </div>

            <div>
              <h3 class="mb-5 bold">{{ $t('setting.inv') }}</h3>
              <ul class="mx-w-400x order-details lh-2">
                <li>
                  <span>{{ $t('fSale.orderUp') }}</span>
                  <span>#{{ order.order }}</span>
                </li>
                <li>
                  <span>{{ $t('setting.od') }}</span>
                  <span>{{ order.created }}</span>
                </li>
                <li
                  v-if="!isVendor"
                >
                  <span>{{ $t('setting.oa') }}</span>
                  <span>{{ priceFormatting(totalPrice) }}</span>
                </li>
              </ul>
            </div>
          </div>

          <div class="plr-30">
            <table class="mn-w-600x no-bg mt-0 shipping-table">
              <tr class="lite-bold">
                <th>{{ $t('fSale.shipTo') }}</th>
                <th>{{ $t('setting.om') }}</th>
              </tr>

              <tr>
                <td class="w-50">
                  <div class="mx-w-300x">
                    <b>{{ getDataFromObject(order, 'address.name',  $t('prod.na')) }}</b>
                    <span
                      v-if="getDataFromObject(order, 'address')"
                      class="block"
                    >
                      {{ generateAddress(getDataFromObject(order, 'address')) }}</span>
                    <span class="block">Email: {{ userEmail }}</span>
                    <span class="block">Phone: {{ getDataFromObject(order, 'address.phone',  $t('prod.na')) }}</span>
                  </div>
                </td>
                <td class="w-50">{{ paymentTypes[order.order_method] }}</td>
              </tr>
            </table><!--table-->

            <table class="mn-w-600x no-bg">
              <tr class="lite-bold">
                <th>{{ $t('index.title') }}</th>
                <th>{{ $t('fSale.dFee') }}</th>
                <th>{{ $t('fSale.qty') }}</th>
                <th>{{ $t('brand.price') }}</th>
                <th>{{ $t('fSale.total') }}</th>
              </tr>

              <tr v-for="(value, index) in order.ordered_products" :key="index">

                <td style="min-width: 250px">
                  {{ value.product.title }}
                  <span
                    style="display: block"
                  >
                    <span
                      v-for="i in generatingAttribute(value)"
                      class="mr-15"
                    >
                      <b class="mr-10">{{ i[0] }}:</b> {{ i[1] }}
                    </span>

                    <span
                      v-if="value.updated_inventory.sku" class="block mt-5"
                    >
                      SKU: {{ value.updated_inventory.sku }}
                    </span>

                  </span>
                </td>
                <!--<td>{{ shippingType[value.shipping_type] }}</td>-->
                <td>{{ priceFormatting(value.shipping_price) }}</td>
                <td>{{ value.quantity }}</td>

                <td>{{ priceFormatting(value.selling) }}</td>
                <td>{{ priceFormatting(value.selling * value.quantity) }}</td>
              </tr>
            </table><!--table-->
            <div
              v-if="!isVendor"
              class="dply-felx j-right">
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
                  <span>{{ priceFormatting(voucherPrice) }}</span>
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

            <table class="w-50 no-bg mt-0 shipping-table single-table">
              <tr class="lite-bold">
                <th>{{ $t('setting.notes') }}</th>
              </tr>

              <tr>
                <td class="w-50">
                  <p class="semi-bold italic mb-10">
                    {{ $t('setting.query') }}
                  </p>
                  <p>
                    {{ $t('setting.query') }}
                    {{ $t('setting.regard') }}: {{ getDataFromObject(setting, 'phone',  $t('prod.na')) }}
                  </p>
                </td>
              </tr>
            </table>
            <!--table-->
          </div>
        </div>
      </vue-html2pdf>
    </client-only>
    <div class="dply-felx j-right mt-20 mt-sm-15">
      <button
        class="plr-20 dply-felx outline-btn"
        @click.prevent="generatingPdf"
      >
        <i
          class="icon print-icon mr-10"
        />
        {{ $t('setting.pi') }}
      </button>
    </div>
  </div>
</template>

<script>
  import VueHtml2pdf from 'vue-html2pdf'
  import {mapGetters, mapActions} from 'vuex'
  import util from "~/mixin/util"

  export default {
    name: 'Invoice',
    data() {
      return {
      }
    },
    props: {
      order: {
        type: Object
      },
    },
    mixins: [util],
    directives: {},
    components: {VueHtml2pdf},
    watch: {},
    computed: {
      isFreeShipping() {
        return !(parseFloat(this.shippingPrice) > 0)
      },
      userEmail(){
        return this.order?.user?.email ?? this.order?.guest_user?.email ?? this.$t('prod.na')
      },
      currencyPosition() {
        return this.setting?.currency_position
      },
      totalPrice(){
        return this.order?.calculated.total_price
      },
      voucherPrice(){
        return this.order?.calculated.voucher_price
      },
      bundleOffer(){
        return this.order?.calculated.bundle_offer
      },
      shippingPrice(){
        return this.order?.calculated.shipping_price
      },
      taxPrice(){
        return this.order?.calculated.tax
      },
      subtotalPrice(){
        return this.order?.calculated.subtotal
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('admin', ['isVendor']),
      ...mapGetters('resource', ['countryList']),
      ...mapGetters('site-setting', ['siteSetting']),
      ...mapGetters('setting', ['setting', 'base64SiteLogo', 'storeData']),
    },
    methods: {
      generateAddress(obj) {
        if (!obj) {
          return this.$t('prod.na')
        }
        let addArr = []
        addArr.push(obj?.address_1)
        if (obj?.address_2) {
          addArr.push(obj?.address_2)
        }
        addArr.push(obj?.city + '-' + obj?.zip)
        if (this.countryList && this.countryList[obj?.country]) {
          const country = this.countryList[obj?.country]
          if (country.states[obj?.state]) {
            addArr.push(country.states[obj.state]?.name)
          }
          addArr.push(country?.name)
        }
        return addArr.join(', ')
      },
      generatingPdf() {
        this.$nextTick(() => {
          this.$refs.html2Pdf.generatePdf()
        })
      },
      generatingAttribute(attr) {
        return attr?.updated_inventory?.inventory_attributes?.map(i => {
          return [i?.attribute_value?.attribute?.title, i?.attribute_value?.title]
        })
      },
      ...mapActions('resource', ['setCountryList']),
      ...mapActions('common', ['getById']),
      ...mapActions('setting', ['setConvertImage']),
    },
    beforeCreate() {

    },
    destroyed() {

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



      if (!this.base64SiteLogo) {
        await this.setConvertImage(this.siteSetting?.email_logo)
      }
    }
  }
</script>

<style lang="stylus">
</style>

