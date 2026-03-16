<template>
  <div class="mx-w-600x mlr-auto">

    <div class="mb-20 card p-20">
      <h4>{{ $t('title.epd') }}</h4>
      <p class="info-msg mtb-15">{{ $t('title.csvDn') }}</p>
      <ajax-button
        class="primary-btn"
        type="button"
        :text="$t('title.ex')"
        loading-text=""
        :fetching-data="exporting"
        @clicked="exportData"
      />
    </div>


    <error-formatter/>

    <div class="mb-20 card p-20 file-wrap"
         :class="{'has-error': uploadMessage}"
    >
      <h4>{{ $t('title.fti') }}</h4>
      <p class="info-msg mtb-15">{{ $t('title.csvUp') }}</p>
      <p class="info-msg mb-15">{{ $t('title.upHelp') }} {{ $t('title.lngHelp') }}</p>

      <input
        class="mb-15 file-input"
        type="file"
        accept=".xlsx, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        ref="fileInput"
        @change="fileChanged"
      >

      <span
        v-if="uploadMessage"
        class="error mb-15"
      >
        {{ uploadMessage }}
      </span>

      <ajax-button
        class="primary-btn"
        type="button"
        :text="$t('title.im')"
        loading-text=""
        :fetching-data="importing"
        @clicked="importData"
      />
    </div>


    <div class="mb-20 card p-20">
      <div class="dply-felx gap-15 sided">

        <h4>{{ $t('title.bulkImg') }}</h4>

        <nuxt-link to="/images" target="_blank" class="link">{{ $t('title.vi') }}</nuxt-link>
      </div>
      <p class="info-msg mtb-15">{{ $t('title.imgMsg') }}</p>

      <error-formatter
        type="multiple_image"
      />

      <input
        class="mb-15 file-input"
        type="file"
        accept="image/*"
        ref="fileInput"
        multiple
        @change="imageChanged"
      >
      <ajax-button
        class="primary-btn"
        type="button"
        :text="$t('title.bulkImgBtn')"
        loading-text=""
        :fetching-data="uploading"
        @clicked="bulkImage"
      />
    </div>

  </div>
</template>

<script>


  import AjaxButton from "~/components/AjaxButton";
  import FileUpload from "~/components/FileUpload";
  import {mapActions} from 'vuex'
  import validation from "~/mixin/validation"
  import ErrorFormatter from "~/components/ErrorFormatter";

  export default {
    name: "bulk-upload",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        hasError: null,
        params: null,
        uploadMessage: null,
        file: null,
        uploading: false,
        importing: false,
        exporting: false,
      }
    },
    components: {
      ErrorFormatter,
      FileUpload,
      AjaxButton
    },
    mixins: [validation],
    computed: {},
    methods: {
      async exportData() {
        this.exporting = true
        try {
          await this.downloadRequest({params: {}, api: 'bulkExport'})
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.exporting = false
      },
      async imageChanged(event) {
        this.setErrors()
        const files = event.target.files
        let errors = [];
        const fd = new FormData()
        Object.values(files).forEach((item) => {

          const error = this.isValidImage(item)
          if(error){
            errors.push(error)
            return false
          } else{
            fd.append('images[]', item)
          }

        })



        if(!errors.length){
          this.params = fd
        } else {

          this.setErrors({multiple_image: errors})
        }




      },
      fileChanged(event) {

        const file = event.target.files[0]
        if (file) {
          this.uploadMessage = this.isValidExcel(file)
          if (!this.uploadMessage) {
            this.file = file
          }
        }
      },
      async bulkImage() {
        if (this.params) {
          this.setErrors()

          try {
            this.uploading = true

            await this.setRequest({
              params: this.params,
              api: 'imgUpload'
            })

            this.params = null

            this.uploading = false
          } catch (e) {
            return this.$nuxt.error(e)
          }
        }

      },
      async importData() {


        if (this.file) {
          this.setErrors()

          const fd = new FormData()
          fd.append('file', this.file)
          this.importing = true
          try {
            const data = await this.setRequest({params: fd, api: 'bulkImport'})

            this.file = null

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.importing = false
        }
      },
      ...mapActions('common', ['downloadRequest', 'setRequest']),
      ...mapActions('ui', ['setErrors'])
    },
    mounted() {
    }
  }
</script>

<style scoped>

</style>
