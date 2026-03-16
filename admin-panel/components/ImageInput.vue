<template>
  <div class="image-url-wrap file-wrapper">
    <div class="file-input">
      <img
        :src="getImageURL(imageName)"
      />
    </div>

    <div class="input-wrapper">
      <label>{{ $t('error.fn') }}</label>

      <div class="image-input">

        <input
          type="text"
          :placeholder="$t('error.fn')"
          v-model="imageData"
        >

        <ajax-button
          class="primary-btn"
          type="button"
          :text="$t('setting.sv')"
          loading-text=""
          :fetching-data="saving"
          @clicked="$emit('image-change', imageData)"
        />
      </div>
    </div>
  </div>
</template>
<script>

  import AjaxButton from "~/components/AjaxButton"
  import util from "~/mixin/util"

  export default {
    name: 'ImageInput',
    data() {
      return {
        imageData: null,
      }
    },
    mixins: [util],
    components: {
      AjaxButton
    },
    props: {
      saving: {
        type: Boolean,
        default: false,
      },
      image: {
        type: String,
        default: '',
      },
    },
    watch: {
      image(value) {
        this.imageData = value
      }
    },
    computed: {
      imageName() {
        if(this.image?.trim()){
          return this.image
        }
        return this.defaultImage
      }
    },
    methods: {
    },
    mounted() {
      this.imageData = this.image
    }

  }
</script>

<style lang="stylus">

</style>
