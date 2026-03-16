<template>
  <span class="qty-nav">
    <button
      aria-label="subtract"
      @click.prevent="qty(-1)"
    >
      -
    </button>
    <input
      type="number"
      :readonly="readonly"
      v-model="qtyVal"
      :max="max"
      :min="1"
      @change="onChange"
    >
    <button
      aria-label="add"
      @click.prevent="qty(1)"
    >
      +
    </button>
  </span>
</template>

<script>
  export default {
    name: 'QuantityNav',
    data() {
      return {
        qtyVal: 1,
      }
    },
    props: {
      readonly: {
        type: Boolean,
        default: false
      },
      productInventory: {
        type: Array,
        default: []
      },
      quantity: {
        type: Number,
        default: 1
      },
      max: {
        type: Number,
        default: 100
      }
    },
    watch:{
      quantity(value){
        this.qtyVal = value
      }
    },
    components: {},
    mixins: [],
    computed: {
    },
    methods: {
      onChange(){
        this.$emit('value-changed', this.qtyVal)
      },
      qty(direction = 0) {
        if(!Object.keys(this.productInventory).length) {
          return

        } else if (this.qtyVal + direction > this.max) {
          this.qtyVal = this.max

        } else if (this.qtyVal + direction === 0) {
          this.qtyVal = 1

        } else {
          this.qtyVal = parseInt(this.qtyVal) + parseInt(direction)
        }
        this.onChange()
      },
    },
    mounted() {
      this.qtyVal = this.quantity
    }
  }
</script>
