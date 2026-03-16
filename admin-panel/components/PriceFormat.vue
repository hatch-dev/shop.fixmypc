<template>
  <span>{{formattedPrice}}</span>
</template>

<script>

  import {mapGetters} from "vuex"
  import util from "~/mixin/util"
  export default {
    name: 'PriceFormat',
    data() {
      return {

      }
    },
    props: {
      price: {
        default: 0,
        required: true
      }
    },
    mixins: [util],
    components: {},
    computed: {
      currencyPosition() {
        return this.setting?.currency_position
      },
      currencyIcon() {
        return this.setting?.currency_icon || '$'
      },
      formattedPrice(){
        return this.priceFormat({type: 1, price: this.price, icon: this.setting?.currency_icon})
        //return this.priceFormat(this.setting?.currency_position, this.setting?.currency_icon, this.price, this.setting)
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {

    },
    mounted() {
    }
  }
</script>

