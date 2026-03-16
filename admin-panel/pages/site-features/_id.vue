<template>
  <data-page
    ref="dataPage"
    set-api="setSiteFeature"
    get-api="getSiteFeature"
    set-image-api="uploadSiteFeatureImage"
    route-name="site-features"
    :name="$t('title.sf')"
    :validation-keys="['detail']"
    :file-keys="['id', 'detail']"
    :result="result"
    gate="home_slider"
    @result="result = $event"
  >
    <template v-slot:form="{hasError}">
      <div
        v-if="loading"
        class="spinner-wrapper"
      >
        <spinner
          :radius="70"
          color="primary"
        />
      </div>


      <WYSIWYGEditor
        class="mb-20"
        :title="$t('prod.desc')"
        :description="result.detail"
        @change="result.detail = $event"
        @file="editorDescriptionFile"
      />

      <div class="input-wrapper">
        <div class="dply-felx j-left mb-20 mb-sm-15">
          <span class="mr-15">
            {{ $t('category.status') }}
          </span>

          <dropdown
            :selectedKey="`${result.status}`"
            :options="statusObj"
            @clicked="dropdownSelected"
          />
        </div>
      </div>


    </template>
  </data-page>
</template>

<script>

  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import Dropdown from "~/components/Dropdown"
  import {mapGetters, mapActions} from 'vuex'
  import WYSIWYGEditor from "../../components/WYSIWYGEditor";
  import Spinner from "../../components/Spinner";

  export default {
    name: "features",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        result: {
          id: '',
          image: this.defaultImage,
          status: '',
          detail: '',
        }
      }
    },
    mixins: [util],
    components: {
      Spinner,
      WYSIWYGEditor,
      Dropdown,
      DataPage
    },
    computed: {
      ...mapGetters('language', ['currentLanguage']),
    },
    methods: {
      editorDescriptionFile({deleted, file, Editor, cursorLocation, resetUploader}){
        this.editorFile({deleted, file, Editor, cursorLocation, resetUploader})
      },
      async editorFile({deleted, file, Editor, cursorLocation, resetUploader}){
        if(!deleted){

          this.loading = true
          try {
            const fd = new FormData()
            if (!this.result.id) {
              fd.append('site_feature', JSON.stringify(this.result))
            } else {
              fd.append('detail', this.result.detail)
              fd.append('site_feature_id', this.result.id)
            }
            fd.append('photo', file)
            const data = await this.setRequest({params: fd, api: 'setFeatureWysiwygImage'})

            if(data){
              if (!this.result.id) {
                await this.$router.push({path: `/site-features/${data.site_feature_id}`})
              } else {
                Editor.insertEmbed(cursorLocation, "image", data.url);
                resetUploader();
              }
            }

          }catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
        }else{
          this.loading = true
          try {
            await this.deleteData({params: this.getImageName(file), api: 'deleteFeatureWysiwygImage'})
          }catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
        }
      },

      dropdownSelected(data) {
        this.result.status = data.key
      },

      ...mapActions('common', ['setRequest', 'deleteData'])
    },
    async mounted() {
    }
  }
</script>

<style scoped>

</style>
