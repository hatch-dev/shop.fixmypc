<template>
  <div class="side-block" v-if="brands && brands.length">
    <h3>{{ $t('listingLayout.brands') }}</h3>

    <input
      class="brand-search"
      type="text"
      v-model="brandQuery"
      :placeholder="$t('header.searchHere')"
      :aria-label="$t('listingLayout.brands')"
    >

    <div class="scroll-list">
      <label
        class="f-row"
        v-for="value in filteredBrands"
        :key="value.id"
        :for="`brand-${value.id}`"
      >
        <input
          :id="`brand-${value.id}`"
          type="checkbox"
          v-model="brand"
          name="brand"
          :value="value.id"
          @change="brandChanged"
        >
        {{ value.title }}
      </label>

      <p v-if="!filteredBrands.length" class="no-match">
        {{ $t('listingLayout.noProductFound') }}
      </p>
    </div>
  </div>
</template>

<script>


    export default {
      name: "FilterBrand",
      props: {
        brands: {
          type: Array,
          default() {
            return []
          }
        },
      },
      data() {
        return {
          brand: [],
          brandExpanded: false,
          brandQuery: '',
        }
      },
      components: {

      },
      mixins: [],
      computed: {
        // Client side narrowing of the brand list that already came with the
        // listing payload — no extra request is made.
        filteredBrands() {
          const query = this.brandQuery.trim().toLowerCase()
          if (!query) {
            return this.brands || []
          }
          return (this.brands || []).filter(value => `${value.title || ''}`.toLowerCase().includes(query))
        },
      },
      mounted() {
        if (this.$route.query.brand) {
          this.brand = this.$route.query.brand.split(',')
          this.$emit('brand-change', this.brand)
        }
      },
      methods: {
        brandChanged() {
          this.$emit('brand-change', this.brand)
          let filtered = Object.assign({}, this.$route.query)
          if (this.brand.length) {
            filtered = {...filtered, ...{brand: this.brand.join(',')}}
          } else {
            delete filtered.brand
          }
          this.$emit('reset-route', filtered)
        },
      },
    }
</script>

<style scoped>

</style>
