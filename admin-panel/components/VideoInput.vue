<template>
  <div class="image-url-wrap file-wrapper">
    <h5>{{ $t('error.vti') }}</h5>
    <div class="file-input">
      <img :src="thumbImageName">
    </div>

    <h5>{{ $t('error.vid') }}</h5>
    <div class="file-input">
      <video ref="videoRef" src="" id="video-container" width="100%" controls></video>
    </div>

    <div class="input-wrapper">
      <label>{{ $t('error.vtn') }}</label>

        <input
          type="text"
          :placeholder="$t('error.vtn')"
          v-model="videoThumbData"
        >
    </div>

    <div class="input-wrapper">
      <label>{{ $t('error.vn') }}</label>

      <div class="image-input">

        <input
          type="text"
          :placeholder="$t('error.vn')"
          v-model="videoData"
        >

        <ajax-button
          class="primary-btn"
          type="button"
          :text="$t('setting.sv')"
          loading-text=""
          :fetching-data="saving"
          @clicked="$emit('image-change', {
            video: videoData,
            thumb: videoThumbData,
          })"
        />
      </div>
    </div>
  </div>
</template>
<script>

  import AjaxButton from "~/components/AjaxButton"
  import util from "~/mixin/util"

  export default {
    name: 'VideoInput',
    data() {
      return {
        videoThumbData: null,
        videoData: null,
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
      videoThumb: {
        type: String,
        default: '',
      },
      video: {
        type: String,
        default: '',
      }
    },
    watch: {
      videoThumb(value) {
        this.videoThumbData = value
      },
      video(value) {
        this.$refs.videoRef.src = value
        this.$refs.videoRef.play()
        this.videoData = value
      }
    },
    computed: {
      thumbImageName() {
        if(this.videoThumbData?.trim()){
          return this.videoThumb
        }
        return this.defaultImage
      }
    },
    methods: {
    },
    mounted() {
      this.videoData = this.video
      this.videoThumbData = this.videoThumb
    }

  }
</script>

<style lang="stylus">

</style>
