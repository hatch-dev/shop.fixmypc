<template>
  <div class="side-block">
    <h3>
      {{ $t('listingLayout.availability') }}
      <button
        type="button"
        :disabled="!stock && !offer"
        aria-label="submit"
        @click.prevent="clearAll"
      >
        {{ $t('listingLayout.clear') }}
      </button>
    </h3>

    <label class="f-row" for="filter-in-stock">
      <input
        id="filter-in-stock"
        type="checkbox"
        v-model="stock"
        @change="availabilityChanged"
      >
      {{ $t('listingLayout.inStock') }}
    </label>

    <label class="f-row" for="filter-on-offer">
      <input
        id="filter-on-offer"
        type="checkbox"
        v-model="offer"
        @change="availabilityChanged"
      >
      {{ $t('listingLayout.onOffer') }}
    </label>
  </div>
</template>

<script>

    export default {
      name: "FilterAvailability",
      props: {

      },
      data() {
        return {
          stock: this.$route.query.stock === 'true',
          offer: this.$route.query.offer === 'true',
        }
      },
      components: {

      },
      mixins: [],
      computed: {

      },
      mounted() {
      },
      methods: {
        clearAvailability() {
          this.stock = false
          this.offer = false
        },
        clearAll() {
          this.clearAvailability()
          this.availabilityChanged()
        },
        availabilityChanged() {
          let filtered = Object.assign({}, this.$route.query)

          if (this.stock) {
            filtered = {...filtered, ...{stock: 'true'}}
          } else {
            delete filtered.stock
          }

          if (this.offer) {
            filtered = {...filtered, ...{offer: 'true'}}
          } else {
            delete filtered.offer
          }

          this.$emit('reset-route', filtered)
        },
      },
    }
</script>

<style scoped>

</style>
