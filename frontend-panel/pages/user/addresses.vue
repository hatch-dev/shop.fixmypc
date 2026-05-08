<template>
  <account-layout
    active-route="addresses"
    @clicked-addresses="loadData"
    class="mb-5"
  >
    <template v-slot:rightArea>
        <div>
          <user-account-address
            ref="userAddress"
            @editing="editing"
          />
        </div>
    </template>
  </account-layout>
</template>
<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from '~/components/LazyImage'
  import AddressPopup from '~/components/AddressPopup'
  import UserAddress from '~/components/UserAddress'
  import UserAccountAddress from '~/components/UserAccountAddress'
  import AccountLayout from '~/components/AccountLayout'

  import addressHelper from '~/mixin/addressHelper'
  import productHelper from '~/mixin/productHelper'

  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: this.$t('date.addr'),
        meta: []
      }
    },
    data() {
      return {
        editingAddress: null,
        addressPopup: false,
        deactivate: true
      }
    },
    watch: {
    },
    components: {
      LazyImage,
      AddressPopup,
      AccountLayout,
      UserAddress,
      UserAccountAddress
    },
    mixins: [util, productHelper, addressHelper, global],
    computed: {
      ...mapGetters('common', ['currencyIcon', 'setting'])
    },
    methods: {
      loadData() {
        this.$refs.userAddress.loadData()
      },
      closingPopup() {
        this.addressPopup = false
      },
      adding() {
        this.addressPopup = true
        this.editingAddress = null
      },
      editing(value) {
        this.addressPopup = true
        this.editingAddress = value
      },
      ...mapActions('common', ['fetchLocation', 'setToastMessage', 'setToastError']),
    },
    async mounted() {
    },
  }
</script>

<style>

</style>
