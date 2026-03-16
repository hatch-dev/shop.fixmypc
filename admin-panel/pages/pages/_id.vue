<template>
  <data-page
    ref="dataPage"
    set-api="setPage"
    get-api="getPage"
    route-name="pages"
    gate="page"
    empty-store-variable="allPages"
    :name="$t('dataPage.page')"
    :validation-keys="['title', 'slug',  'meta_title', 'meta_description']"
    :result="result"
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
      <div class="input-wrapper">
        <label>{{ $t('index.title') }}</label>
        <input
          type="text"
          :placeholder="$t('index.title')"
          v-model="result.title"
          @change="slugChange"
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
        <label>{{ $t('category.slug') }}</label>
        <input
          type="text"
          :placeholder="$t('category.slug')"
          v-model="result.slug"
          :class="{invalid: !!!result.slug && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.slug && hasError"
        >
          {{ $t('category.req', { type: $t('category.slug')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <div class="flex-v-centered mb j-left">
          <span class="mr-15">{{ $t('admin.pageComp') }}</span>
          <dropdown
            :selectedKey="result.page_from_component"
            :options="featuredObj"
            @clicked="pageFromComponentSelected"
          />
        </div>
      </div>

      <div
        v-if="isPageFromComponent"
        class="input-wrapper">
        <div class="flex-v-centered mb j-left">
          <span class="mr-15">{{ $t('admin.comp') }}</span>
          <dropdown
            :selectedKey="result.description"
            :options="pageComponent"
            @clicked="result.description = $event.key"
          />
        </div>
      </div>

      <WYSIWYGEditor
        v-else
        :title="$t('prod.desc')"
        :description="result.description"
        @change="result.description = $event"
        @file="editorDescriptionFile"
      />


      <div class="input-wrapper mt-15">
        <label>{{ $t('category.mTitle') }}</label>
        <input
          type="text"
          :placeholder="$t('category.mTitle')"
          v-model="result.meta_title"
          :class="{invalid: !!!result.meta_title && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.meta_title && hasError"
        >
          {{ $t('category.req', { type: $t('category.mTitle')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('category.mDesc') }}</label>
        <textarea
          :placeholder="$t('category.mDesc')"
          v-model="result.meta_description"
          :class="{invalid: !!!result.meta_description && hasError}"
        />
        <span
          class="error"
          v-if="!!!result.meta_description && hasError"
        >
          {{ $t('category.req', { type: $t('category.mDesc')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('ship.mk') }} ({{ $t('ship.csk') }})</label>
        <textarea
          :placeholder="$t('ship.mk')"
          v-model="result.meta_keywords"
          :class="{invalid: !!!result.meta_keywords && hasError}"
        />
        <span
          class="error"
          v-if="!!!result.meta_keywords && hasError"
        >
                {{ $t('category.req', { type: $t('ship.mk')}) }}
              </span>
      </div>

    </template>
  </data-page>
</template>

<script>

  import {mapGetters} from 'vuex'
  import DataPage from "~/components/partials/DataPage"
  import util from "~/mixin/util"
  import WYSIWYGEditor from '~/components/WYSIWYGEditor'
  import {mapActions} from 'vuex'
  import Spinner from "~/components/Spinner"
  import Dropdown from "~/components/Dropdown"

  export default {
    name: "brands",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        oldPageDescription: '',
        result: {
          id: '',
          title: '',
          slug: '',
          meta_title: '',
          description: '',
          meta_description: '',
          meta_keywords: '',
          page_from_component: ''
        }
      }
    },
    mixins: [util],
    components: {
      Dropdown,
      Spinner,
      DataPage,
      WYSIWYGEditor
    },
    computed: {
      isPageFromComponent(){
        return parseInt(this.result.page_from_component) !== this.status.PRIVATE
      },
      ...mapGetters('language', ['currentLanguage']),
    },
    methods: {
      pageFromComponentSelected(data) {
        this.result.page_from_component = data.key

        if(this.isPageFromComponent){
          this.oldPageDescription = this.result.description
          this.result.description = Object.keys(this.pageComponent)[0]
        } else if(this.oldPageDescription) {
          this.result.description = this.oldPageDescription
        }
      },
      editorDescriptionFile({deleted, file, Editor, cursorLocation, resetUploader}){
        this.editorFile({deleted, file, Editor, cursorLocation, resetUploader})
      },
      async editorFile({deleted, file, Editor, cursorLocation, resetUploader}){
        if(!deleted){

          this.loading = true
          try {
            const fd = new FormData()
            if (!this.result.id) {
              fd.append('page', JSON.stringify(this.result))
            } else {
              fd.append('description', this.result.description)
              fd.append('page_id', this.result.id)
            }
            fd.append('photo', file)
            const data = await this.setRequest({params: fd, api: 'setPageWysiwygImage'})

            if(data){
              if (!this.result.id) {
                await this.$router.push({path: `/pages/${data.page_id}`})
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
            await this.deleteData({params: this.getImageName(file), api: 'deletePageWysiwygImage'})
          }catch (e) {
            return this.$nuxt.error(e)
          }
          this.loading = false
        }
      },
      ...mapActions('common', ['setRequest', 'deleteData'])
    },
    async mounted() {
    }
  }
</script>
