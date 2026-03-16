<template>
  <list-page
    ref="listPage"
    list-api="getVendorOrders"
    route-name="orders"
    :name="$t('fSale.orderD')"
    gate="order"
  >
    <template
      v-slot:table-top="{orderOptions}"
    >
    <div />
    </template>
    <template v-slot:table="{list}">
      <tr class="lite-bold">
        <th>{{ $t('index.title') }}</th>
        <th>{{ $t('fSale.shipTo') }}</th>
        <th>{{ $t('fSale.dFee') }}({{ currencyIcon }})</th>
        <th>{{ $t('fSale.qty') }}</th>
        <th>{{ $t('fSale.attr') }}</th>
        <th>{{ $t('fSale.bOffer') }}({{ currencyIcon }})</th>
        <th>{{ $t('brand.price') }}({{ currencyIcon }})</th>
        <th>{{ $t('fSale.total') }}({{ currencyIcon }})</th>
        <th>{{ $t('category.created') }}</th>
      </tr>
      <tr
        v-for="(value, index) in list"
        :key="index"
      >
        <td>
          <nuxt-link
            class="dply-felx j-left link"
            :to="`/products/${value.product.id}`"
          >
            <img
              class="mr-20"
              :src="getThumbImageURL(value.product.image)"
              :alt="value.title"
            >
            <h5 class="mx-w-400x">{{ value.product.title }}</h5>
          </nuxt-link>
        </td>
        <td>{{ shippingType[value.shipping_type] }}</td>
        <td>{{ shippingPrice(value.shipping_place, value.shipping_type) }}</td>
        <td>{{ value.quantity }}</td>
        <td>
          <span>
            <span
              v-for="i in generatingAttribute(value)"
              class="mr-15"
            >
               <b class="mr-10">{{ i[0] }}:</b> {{ i[1] }}
            </span>
          </span>
        </td>
        <td>{{ (value.selling * value.bundle_offer) || 0 }}</td>
        <td>{{ value.selling }}</td>
        <td>{{ value.selling * value.quantity }}</td>
        <td>{{ value.created }}</td>
      </tr>
    </template>
  </list-page>
</template>

<script>
  import {mapGetters} from 'vuex'
  import util from '~/mixin/util'
  import ListPage from "~/components/partials/ListPage"
  export default {
    name: 'VendorOrders',
    data() {
      return {
      }
    },
    props: {},
    mixins: [util],
    components: {ListPage},
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      shippingPrice(shipping, type) {
        if (parseInt(type) === this.shippingTypeIn.location) {
          return parseFloat(shipping.price)
        } else if (parseInt(type) === this.shippingTypeIn.pickup) {
          return parseFloat(shipping.pickup_price)
        }
      },
      generatingAttribute(attr) {
        return attr?.updated_inventory?.inventory_attributes?.map(i => {
          return [i?.attribute_value?.attribute?.title, i?.attribute_value?.title]
        })
      },
    },
    mounted() {
    }
  }
</script>

