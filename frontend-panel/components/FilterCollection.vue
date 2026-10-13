<template>
  <div class="side-block" v-if="collections && collections.length">
    <h3>{{ $t('listingLayout.collections') }}</h3>

    <label
      class="f-row"
      v-for="(value, key) in collections"
      :key="key"
      :for="`collection-${value.id}`"
    >
      <input
        :id="`collection-${value.id}`"
        type="checkbox"
        v-model="collection"
        :value="value.id"
        @change="collectionChanged"
      >
      {{ value.title }}
    </label>
  </div>
</template>

<script>


    export default {
      name: "FilterCollection",
      props: {
        collections: {
          type: Array,
          default() {
            return []
          }
        },
      },
      data() {
        return {
          collection: []
        }
      },
      components: {

      },
      mixins: [],
      computed: {

      },
      mounted() {
        if (this.$route.query.collection) {
          this.collection = this.$route.query.collection.split(',')
        }
      },
      methods: {
        collectionChanged() {
          let filtered = Object.assign({}, this.$route.query)
          if (this.collection.length) {
            filtered = {...filtered, ...{collection: this.collection.join(',')}}
          } else {
            delete filtered.collection
          }
          this.$emit('reset-route', filtered)
        },
      },
    }
</script>

<style scoped>

</style>
