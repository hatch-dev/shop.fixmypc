<template>
  <span class="quantity-area ">
    <button
      aria-label="subtract"
      @click.prevent="qty(-1)"
      :disabled="max === 0 || value <= 1"
    >
      -
    </button>
    <span class="no-control">{{ qtyComputed }}</span>
    <button
      aria-label="add"
      @click.prevent="qty(1)"
      :disabled="max === 0 || value >= max"
    >
      +
    </button>
  </span>
</template>

<script>
  import {mapActions} from 'vuex'

  export default {
    props: {
      productInventory: {
        type: Object,
        default: ()=>{
          return {}
        }
      },
      value: {
        type: Number,
        default: 1
      },
      max: {
        type: Number,
        default: 1
      }
    },
    computed: {
      qtyComputed() {
        return this.value
      }
    },
    methods: {
      qty(direction) {

        if (!Object.keys(this.productInventory).length) {
          this.setToastError(this.$t('detailRight.requiredAttributes'))
          return
        }

        if (this.max === 0) {
          return
        }

        const newQty = this.value + direction

        if (newQty > this.max) {
          this.setToastError(this.$t('quantityNav.maximumExceeds'))
          return
        }

        if (newQty < 1) {
          return
        }

        this.$emit('input', newQty)
        this.$emit('value-changed', {
          direction: direction
        })
      },
      ...mapActions('common', ['setToastError']),
    },
    activated() {
      this.qtyVal = this.quantity
    },
    mounted() {
      this.qtyVal = this.quantity
    }
  }
</script>

<style>

</style>
