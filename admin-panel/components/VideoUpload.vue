<template>
  <div
    class="file-wrapper"
    :class="{'has-error': uploadMessage}"
  >
    <div
      class="file-input"
      :class="{'pt-0': !isImage}"
    >
      <input
        type="file"
        accept="video/*"
        @change="fileChanged"
        ref="fileInput"
      >
      <img
        v-if="isImage"
        :src="getImageURL(imageName)"
        :alt="imageTitle"
      >

      <div v-else>
        <video
          v-if="videoName"
          controls
          autostart="0"
        >
          <source
            :src="getVideoURL(videoName)"
            type="video/mp4">
        </video>
        <div v-else>
          {{ $t('prod.noVid') }}
        </div>
      </div>

      <video
        style="height: 0; width: 0; opacity: 0"
        id="video" controls autoplay
      >
        <source
          id="source"
          type="video/mp4"
        >
      </video>

    </div>

    <span
      class="error mb-10"
      v-if="uploadMessage"
    >
      {{ uploadMessage }}
    </span>

    <ajax-button
      class="outline-btn"
      type="button"
      :text="btnText"
      color="primary"
      :loading-text="$t('error.up')"
      :fetching-data="fileUploading"
      @clicked="$refs.fileInput.click()"
    />
  </div>

</template>

<script>

  import AjaxButton from '~/components/AjaxButton'
  import util from '~/mixin/util'
  import validation from '~/mixin/validation'

  export default {
    name: 'VideoUpload',
    data() {
      return {
        scaleFactor: '',
        uploadMessage: null,
      }
    },
    mixins: [util, validation],
    components: {
      AjaxButton
    },
    props: {
      type: {
        type: String,
        default: 'image'
      },
      fileUploading: {
        type: Boolean,
        default: false,
      },
      video: {
        type: String,
        default: '',
      },
      image: {
        type: String,
        default: '',
      },
      imageTitle: {
        type: String,
        default: '',
      },
      btnText: {
        type: String,
        default: 'Upload',
      }
    },
    computed: {
      isImage() {
        return this.type === 'image'
      },
      errors() {
        return this.ERRORS && this.ERRORS[this.type]
      },
      videoName() {
        return this.video || ''
      },
      imageName() {
        return this.image ?? this.defaultImage
      }
    },
    methods: {
      dataURLtoFile(dataurl, filename) {
        var arr = dataurl.split(','), mime = arr[0].match(/:(.*?);/)[1],
          bstr = atob(arr[1]), n = bstr.length, u8arr = new Uint8Array(n);
        while (n--) {
          u8arr[n] = bstr.charCodeAt(n);
        }
        return new File([u8arr], filename, {type: mime});
      },
      capture(vid) {
        const video = document.getElementById('video');
        const source = document.getElementById('source');
        source.src = URL.createObjectURL(vid);
        video.load();
        let loaded = false;

        const self = this
        video.addEventListener("loadeddata", function () {
          if(loaded) {
            return false
          }
          loaded = true;

          let w = this.videoWidth
          let h = this.videoHeight

          let canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          let ctx = canvas.getContext('2d');
          ctx.drawImage(this, 0, 0, w, h);
          let dataURI = canvas.toDataURL('image/jpeg');

          // let a =
          let boj = {
            canvas: canvas,
            file: self.dataURLtoFile(dataURI, `${+new Date()}.jpg`),
            base64: dataURI
          }

          self.$emit('file-upload', {video: vid, thumb: boj.file})
        });

      },
      fileChanged(event) {
        const file = event.target.files[0]
        if (file) {
          this.uploadMessage = this.isValidImage(file, this.maxVideoSize, false)
          if (!this.uploadMessage) {
            this.capture(file)
          }
        }
      },
    }

  }
</script>

<style lang="stylus">

</style>
