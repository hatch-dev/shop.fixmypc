<template>
  <data-page
    class="detail-width"
    ref="dataPage"
    set-api="setTemplate"
    get-api="getTemplate"
    route-name="templates"
    :name="$t('index.template')"
    :validation-keys="['title']"
    :result="result"
    @result="settingResult"
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
      
      <!-- Name Input Field -->
      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          v-model="result.title"
          :class="{invalid: !!!result.title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.title && hasError"
        >
          {{ $t('category.req', { type: $t('index.title')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>Type</label>

        <select v-model="result.type" class="type-select" :disabled="!!result.id">
          <option value="custom">Custom</option>
          <option value="system">System</option>
        </select>
      </div>

      <!-- WYSIWYG Editor for Description -->
      <WYSIWYGEditor
        class="mb-20"
        :title="$t('prod.desc')"
        :description="result.content"
        @change="result.content = $event"
        @file="editorDescriptionFile"
      />
    
    </template>
  </data-page>
</template>

<script>
  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import {mapActions} from 'vuex'
  import WYSIWYGEditor from "../../components/WYSIWYGEditor";
  import Spinner from "../../components/Spinner";

  export default {
    name: "templates",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        result: {
          id: '',
          title: '',
          content: '',
          type: 'custom'
        }
      }
    },
    mixins: [util],
    components: {
      Spinner,
      WYSIWYGEditor,
      DataPage
    },
    methods: {
      settingResult(evt){
        this.result = {...evt}
      },
      
     /* editorDescriptionFile({deleted, file, Editor, cursorLocation, resetUploader}){
        this.editorFile({deleted, file, Editor, cursorLocation, resetUploader})
      },
      
      async editorFile({deleted, file, Editor, cursorLocation, resetUploader}){
        if(!deleted){
          this.loading = true
          try {
            const fd = new FormData()
            if (!this.result.id) {
              fd.append('template', JSON.stringify(this.result))
            } else {
              fd.append('content', this.result.content)
              fd.append('template_id', this.result.id)
            }
            fd.append('photo', file)
            const data = await this.setRequest({params: fd, api: 'setTemplateWysiwygImage'})
            if(data){
              if (!this.result.id) {
                await this.$router.push({path: `/templates/${data.template_id}`})
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
            await this.deleteData({params: this.getImageName(file), api: 'deleteTemplateWysiwygImage'})
          }catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
        }
      }, */
      
      ...mapActions('common', ['setRequest', 'deleteData'])
    }
  }
</script>

<style scoped>
.input-wrapper {
  margin-bottom: 20px;
}

label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
}

input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

input.invalid {
  border-color: #ff3860;
}

.error {
  color: #ff3860;
  font-size: 12px;
  margin-top: 5px;
  display: block;
}

.spinner-wrapper {
  display: flex;
  justify-content: center;
  margin: 20px 0;
}

.mb-20 {
  margin-bottom: 20px;
}
.file-wrapper{
  display:none !important;
}
select {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 14px;
  background: #fff;
  cursor: pointer;
  transition: 0.2s ease;
}

select:focus {
  border-color: #2563eb;
  outline: none;
}

.type-select {
  font-weight: 500;
}
</style>