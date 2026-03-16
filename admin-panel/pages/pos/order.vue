<template>
  <div v-if="validLicence">


    <pos-ordered
      v-if="order"
      :order="order"
      @close="order = null"
    />

    <list-page
      ref="listPage"
      list-api="getPosOrders"
      delete-api="deletePosOrders"
      route-name="pos"
      :name="$t('fSale.orderD')"
      :order-options="orderByOrders"
      gate="pos"
      @list="itemList = $event"
    >
      <template
        v-slot:table-top="{orderOptions}"
      >
        <div class="dply-felx gap-10 j-left f-wrap mb-15">
          <table-sort
            :order-by-options="orderOptions"
          />

          <inline-pop-over
            :arrow="true"
            class="bulk-action mt-md-10"
            ref="bulkDelete"
          >
            <template
              v-slot:button
            >
              {{ $t('title.act') }}
            </template>
            <template
              v-slot:content
            >
              <button @click.prevent="deleteAll" class="outline-btn">
                {{ $t('category.delete') }}
              </button>
            </template>
          </inline-pop-over>
        </div>

      </template>

      <template v-slot:table="{list}">
        <tr class="lite-bold">
          <th>
            <input type="checkbox" @change="checkAll">
          </th>
          <th>{{ $t('fSale.orderUp') }}</th>
          <th>{{ $t('fSale.voucher') }}</th>
          <th>{{ $t('ship.cs') }}</th>
          <th>{{ $t('fSale.user') }}</th>
          <th>{{ $t('fSale.amount') }}({{ currencyIcon }})</th>
          <th>{{ $t('category.created') }}</th>
          <th />
        </tr>

        <tr
          v-for="(item, index) in list"
          :key="index"
        >
          <td>
            <input type="checkbox" :value="item.order.id" v-model="cbList">
          </td>
          <td>
            <nuxt-link
              class="dply-felx j-left link"
              :to="`/orders/${item.order.id}`"
            >
              #{{ item.order.order }}
            </nuxt-link>

          </td>

          <td>{{ voucherStr(item.order) }}</td>
          <td>
            <span class="ellipsis mx-w-150x">{{ customerStr(item.order) }}</span>
          </td>
          <td>
            <span class="ellipsis mx-w-150x">{{ userStr(item.order) }}</span>
          </td>

          <td class="mn-w-90x">{{ item.order.total_amount }}</td>
          <td class="mn-w-90x">{{ item.order.created }}</td>
          <td class="ptb-10">
            <button
              v-if="$can('pos', 'view')"
              @click.prevent="order = item.order"
              class="lite-btn"
            >
              {{ $t('fSale.view') }}</button>
            <button
              v-if="$can('pos', 'delete')"
              @click.prevent="$refs.listPage.deleteItem(item.order.id)"
              class="delete-btn lite-btn"
            >
              {{ $t('category.delete') }}</button>
          </td>
        </tr>
      </template>
    </list-page>
  </div>
</template>

<script>
  import util from '~/mixin/util'
  import {mapActions, mapGetters} from 'vuex'
  import Spinner from "../../components/Spinner";
  import routeParamHelper from "../../mixin/routeParamHelper";
  import InlinePopOver from "../../components/InlinePopOver";
  import ListPage from "../../components/partials/ListPage";
  import bulkDelete from "../../mixin/bulkDelete";
  import TableSort from "~/components/partials/TableSort"
  import PosOrdered from "../../components/partials/PosOrdered";

  export default {
    name: "posOrders",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        order: null,
        validLicence: false,
        loading: false,
        orderByOrders: {
          'payment_method': { title: this.$t('fSale.pMethod') },
          'admin_id': { title: this.$t('fSale.user') },
          'created_at': { title: this.$t('category.date') },
        },
      }
    },
    components: {
      PosOrdered,
      ListPage,
      InlinePopOver,
      Spinner,
      TableSort
    },
    mixins: [util,routeParamHelper, bulkDelete],
    computed: {
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      ...mapGetters('admin', ['posPublicKey']),
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      userStr(order) {
        return order?.pos_order?.admin?.name ?? order?.pos_order?.admin?.email ?? 'n/a'
      },
      customerStr(order) {
        return order?.user?.name ?? order?.user?.email ?? this.$t('ship.wc')
      },
      voucherStr(order) {
        if(order.voucher) {
          return `${order.offered} (${order.voucher})`
        }
        return this.$t('prod.na')
      },
      deleteAll() {
        this.$refs.bulkDelete.closePop()
        this.deleteBulk()
      },
      ...mapActions('common', ['getRequest'])
    },
    async mounted() {
      const domain = window.location.hostname

      if(this.posPublicKey){
        let licence = this.phpDecryption(this.posPublicKey)
        const licenceObj = JSON.parse(licence)
        const validLicenceItem = !licenceObj?.p || (licenceObj?.p && licenceObj?.p === 'pos-ishop')

        if(domain.includes('admin.ishop.cholobangla.com') || domain.includes('localhost') ||
          domain.includes("127.0.0.1") || (licence.includes(domain) && validLicenceItem)){
          this.validLicence = true
        }
      } else if(domain.includes('admin.ishop.cholobangla.com') || domain.includes('localhost') ||
        domain.includes("127.0.0.1")){

        this.validLicence = true
      }
    }
  }
</script>

<style scoped>

</style>
