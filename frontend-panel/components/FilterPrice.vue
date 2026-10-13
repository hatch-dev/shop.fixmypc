<template>
  <div class="side-block">
    <h3>
      {{ $t('listingLayout.price') }}
      <button
        type="button"
        aria-label="submit"
        :disabled="!maxPrice && !minPrice"
        @click.prevent="anyPrice"
      >
        {{ $t('listingLayout.anyPrice') }}
      </button>
    </h3>

    <div class="price-row">
      <label class="cur">
        <span>{{ currencyIcon }}</span>
        <input
          type="number"
          :placeholder="$t('listingLayout.min')"
          :aria-label="$t('listingLayout.min')"
          v-model="minPrice"
        >
      </label>

      <label class="cur">
        <span>{{ currencyIcon }}</span>
        <input
          type="number"
          :placeholder="$t('listingLayout.max')"
          :aria-label="$t('listingLayout.max')"
          v-model="maxPrice"
        >
      </label>

      <button
        class="btn btn-ink btn-sm"
        @click.prevent="filterPrice"
        aria-label="submit"
      >
        {{ $t('listingLayout.go') }}
      </button>
    </div>
  </div><!--side-block-->
</template>

<script>

  import {mapGetters} from 'vuex'

    export default {
      name: "FilterPrice",
      props: {

      },
      data() {
        return {
          minPrice: this.$route.query.min || '',
          maxPrice: this.$route.query.max || '',
        }
      },
      components: {

      },
      mixins: [],
      computed: {
        ...mapGetters('common', ['currencyIcon', 'setting']),
      },
      mounted() {
      },
      methods: {
        anyPrice() {
          this.clearPrice()
          this.filterPrice()
        },
        clearPrice() {
          this.minPrice = ''
          this.maxPrice = ''
        },

        filterPrice() {
          let filtered = Object.assign({}, this.$route.query)
          if (parseInt(this.maxPrice) > 0) {
            filtered = {...filtered, ...{max: this.maxPrice}}
          } else {
            delete filtered.max
          }
          if (parseInt(this.minPrice) > 0) {
            filtered = {...filtered, ...{min: this.minPrice}}
          } else {
            delete filtered.min
          }
          this.$emit('reset-route', filtered)
        },
      },
    }
</script>

<style scoped>

</style>
