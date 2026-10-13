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
      },
      /* Money columns pass 2 so "135.7" renders as "135.70". */
      decimals: {
        type: [Number, String],
        default: 0
      }
    },
    mixins: [util],
    components: {},
    computed: {
      formattedPrice(){
        return this.priceFormat(
          this.currencyPosition,
          this.currencyIcon,
          this.price,
          this.setting,
          parseInt(this.decimals) || 0
        )
      },
      ...mapGetters('common', ['currencyIcon', 'setting', 'currencyPosition', 'currency'])
    },
    methods: {

    },
    mounted() {
    }
  }
</script>

