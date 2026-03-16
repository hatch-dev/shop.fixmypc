<template>
  <span>{{formattedPrice}}</span>
</template>

<script>

  import {mapGetters} from "vuex";
  import util from "~/mixin/util"
  export default {
    name: 'PriceFormat',
    data() {
      return {

      }
    },
    props: {
      price: {
        type: Number,
        default: 0,
        required: true
      }
    },
    mixins: [util],
    components: {},
    computed: {
      formattedPrice(){
        if(parseInt(this.setting.currency_position) === this.currencyPositionsIn.PRE) {
          return this.setting.currency_icon + this.price
        }
        return this.price + this.setting.currency_icon
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {

    },
    mounted() {
    }
  }
</script>

