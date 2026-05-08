<template>
  <div
    class="row g-4 mt-4 mb-4"
  >

    <site-feature
      v-for="(value, i) in siteFeatures"
      :key="i"
      :site-feature="value"
      :index="i"
    />

  </div>

</template>

<script>
  import SiteFeature from "./SiteFeature";
  export default {
    name: 'StaticSection',
    data() {
      return {

      }
    },
    watch: {


    },
    props: {
      siteFeatures: {
        type: Array,
        default: []
      }
    },
    components: {
      SiteFeature

    },
    computed: {

    },
    mixins: [],
    methods: {

    },
    created() {
    },
    async mounted() {
    }


  }
</script>

