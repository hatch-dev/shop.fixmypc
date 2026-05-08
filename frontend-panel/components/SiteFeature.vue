<template>
    <div class="col-lg-3 col-md-6">
      <div class="feature-card" :class="colorClass.bg">
        <div class="icon-box" :class="colorClass.icon">
          <lazy-image
            :data-src="getImageURL(image)"
            :title="$t('date.fi')"
            :alt="$t('date.fi')"
          />
        </div>
        <div class="mt-4">
          <div class="detail" v-html="detail"></div>
        </div>
      </div>
    </div>
</template>

<script>
  import util from '~/mixin/util'
  import LazyImage from "./LazyImage";
  export default {
    name: 'SiteFeature',
    data() {
      return {
        colorVariants: [
          { bg: 'bg-blue', icon: 'icon-blue' },
          { bg: 'bg-pink', icon: 'icon-pink' },
          { bg: 'bg-green', icon: 'icon-green' },
          { bg: 'bg-purple', icon: 'icon-purple' }
        ]
      }
    },
    watch: {


    },
    props: {
      siteFeature: {
        type: Object,
        default() {
          return null
        }
      },
      index: {
        type: Number,
        default: 0
      }
    },
    components: {
      LazyImage

    },
    computed: {
      image(){
        return this.siteFeature?.image
      },
      detail(){
        return this.siteFeature?.detail
      },
      colorClass(){
        return this.colorVariants[this.index % 4]
      }
    },
    mixins: [util],
    methods: {

    },
    created() {
    },
    async mounted() {
    }


  }
</script>
<style scoped>
.feature-card {
  border-radius: 15px;
  padding: 25px;
}

.feature-card:hover {
  transform: translateY(-5px);
}

.icon-box {
  width: 50px;
  height: 50px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 20px;
}

.bg-blue { background-color: #E2E9FF; }
.bg-pink { background-color: #FFDEE4; }
.bg-green { background-color: #CFF5E1; }
.bg-purple { background-color: #EAE5FF; }


.icon-blue { background-color: #333199; }
.icon-pink { background-color: #FF5374; }
.icon-green { background-color: #41C683; }
.icon-purple { background-color: #846EE5; }
</style>

