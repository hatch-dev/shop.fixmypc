<template>
  <div class="plugins-wrap">
    <div class="dply-felx gap-15 f-wrap j-left align-start">

      <plugin-item
        v-for="(item, index) in result"
        :key="index"
        :plugin="item"
        @fetching-data="activated"
      />

      <div
        class="file-wrapper"
        :class="{'has-error': hasError}"
      >
        <div
          :class="`file-input ${file && 'has-file'}`"
        >
          <input
            type="file"
            accept="zip,application/octet-stream,application/zip,application/x-zip,application/x-zip-compressed"
            @change="fileChanged"
            ref="pluginInput"
          >
          <div v-if="file" class="plus-wrap tick-wrap">
            <i
              class="icon tick-icon no-click"
            />
          </div>
          <div v-else class="plus-wrap">
            <i
              class="icon plus no-click"
            />
          </div>

          <h6 v-if="file">{{ file.name }}</h6>
        </div>

        <ajax-button
          class="outline-btn mb-10"
          type="button"
          :disabled="fileAdded"
          color="primary"
          :text="$t('ship.ap')"
          :fetching-data="fileUploading"
          @clicked="uploadPlugin"
        />

      </div>

    </div>
  </div>
</template>

<script>

  import util from "~/mixin/util"
  import FileUpload from "../FileUpload";
  import AjaxButton from "../AjaxButton";
  import validation from "../../mixin/validation";
  import {mapActions} from 'vuex'
  import PluginItem from "./PluginItem";

  export default {
    name: 'PluginsSetting',
    data() {
      return {
        result: null,
        file: null,
        loading: false,

        fileUploading: false,
        hasError: false,
        fileAdded: false
      }
    },
    props: {},
    mixins: [util, validation],
    components: {
      PluginItem,
      AjaxButton,
      FileUpload
    },
    computed: {
    },
    methods: {
      activated(){
        setTimeout(() => {
          window.location.reload(true);
        }, 200)
      },
      async fetchAllPlugins(){
        this.loading = true

        try {
          const data  = await this.getRequest({params: {}, api: 'allPlugins'})
          this.result = Object.assign({}, data)

        } catch (e) {
          return this.$nuxt.error(e)
        }

        this.loading = false
      },
      fileChanged(e){
        const file = e.target.files[0]
        if (file) {
          this.uploadMessage = this.isValidZip(file)
          if (!this.uploadMessage) {
            this.file = file
          }
        }
      },

      async uploadPlugin(){
        this.fileUploading = true

        const fd = new FormData()
        fd.append('file', this.file)

        const res = await this.setRequest({
          params: fd,
          api: 'uploadPlugin'
        })
        this.fileUploading = false

        if(res){
          this.fileAdded = true
          this.file = null
          this.activated()

        }
      },
      ...mapActions('common', ['setRequest', 'getRequest']),
    },
    created() {
    },
    async mounted() {
      await this.fetchAllPlugins()
    }
  }
</script>
