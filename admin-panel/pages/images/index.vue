<template>

  <div>
    <h5 class="mb-20">{{ $t('list.show', { from: 1, to: imageCount, total: imageCount })}}</h5>


    <div
      v-if="loading"
      class="spinner-wrapper"
    >
      <spinner
        :radius="100"
        color="primary"
        class="mr-15"
      />
    </div>

    <div v-else class="image-container">
      <div
        v-for="(data, index) in thumbs"
        :key="index"
        class="card"
      >

        <lazy-image
          class="mr-20"
          :data-src="getImageURL(data)"
          :alt="thumbToMain(data)"
        />
        {{ thumbToMain(data) }}
        <button
          class=""
          @click.prevent="deleteImage(index)">
          ✖
        </button>


      </div>
    </div>
  </div>

</template>

<script>

  import util from '~/mixin/util'
  import {mapActions} from 'vuex'
  import LazyImage from "../../components/LazyImage";
  import Spinner from "../../components/Spinner";

  export default {
    name: "images",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        imageList: []
      }

    },
    directives: {},
    components: {
      Spinner,
      LazyImage

    },
    mixins: [util],
    computed: {
      imageCount(){
        return this.thumbs.length

      },
      thumbs(){
        return this.imageList?.filter((str) => str.startsWith("thumb-"));
      }
    },
    methods: {
      async deleteImage(index){
        if (confirm(this.$t('admin.dltMsg'))) {
          try {

            this.loading = true

            await this.deleteData({params: this.thumbToMain(this.thumbs[index]), api: 'imgDelete' })

            await this.fetchingData()

            this.loading = false

          }catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },

      thumbToMain(image) {

        image = image.replace('thumb-', '')

        return image
      },
      async fetchingData(){

        this.loading = true
        this.imageList = await this.getRequest({
          params: { },
          api: 'imgAll'
        })

        this.loading = false

      },
      ...mapActions('common', ['deleteData', 'getRequest'] )
    },
    async mounted() {
     await this.fetchingData()
    }
  }
</script>
