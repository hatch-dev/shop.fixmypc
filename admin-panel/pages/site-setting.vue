<template>
  <data-page
    ref="dataPage"
    set-api="setSiteSetting"
    route-name="site-setting"
    :name="$t('title.site')"
    gate="site_setting"
    :validation-keys="['site_name', 'meta_title', 'meta_description', 'copyright_text']"
    :result="result"
    @result="result = $event"
  >
    <template v-slot:form="{hasError}">

      <div
        v-if="loading"
        class="spinner-wrapper"
      >
        <spinner
          :radius="60"
          color="primary"
          class="mr-15"
        />
      </div>


      <div class="dply-felx f-wrap gap-10">

        <div class="input-wrapper b-b pb-15">
          <label class="mb-15">
            {{ $t('admin.headLogo') }}<span class="block">{{ $t('admin.sug') }}</span>
          </label>

          <image-input
            v-if="mediaStorageData.URL === mediaStorage"
            :saving="fileUploading.header_logo"
            :image="result.header_logo"
            @image-change="uploadFile('header_logo', null, $event)"
          />

          <file-upload
            v-else
            class="logo-upload upload-block"
            :image="result.header_logo"
            :file-uploading="fileUploading.header_logo"
            :btn-text="$t('profile.cngLogo', {type: $t('title.headLogo')})"
            @file-upload="uploadFile('header_logo', $event)"
          />
        </div>

        <div class="input-wrapper b-b pb-15">
          <label class="mb-15">
            {{ $t('admin.fLogo') }}<span class="block">{{ $t('admin.sug') }}</span>
          </label>

          <image-input
            v-if="mediaStorageData.URL === mediaStorage"
            :saving="fileUploading.footer_logo"
            :image="result.footer_logo"
            @image-change="uploadFile('footer_logo', null, $event)"
          />

          <file-upload
            v-else
            class="logo-upload upload-block"
            :image="result.footer_logo"
            :file-uploading="fileUploading.footer_logo"
            :btn-text="$t('profile.cngLogo', {type: $t('title.fLogo')})"
            @file-upload="uploadFile('footer_logo', $event)"
          />
        </div>

        <div class="input-wrapper b-b pb-15">
          <label class="mb-15">
            {{ $t('admin.lEmail') }}<span class="block">{{ $t('admin.sug') }}</span>
          </label>

          <image-input
            v-if="mediaStorageData.URL === mediaStorage"
            :saving="fileUploading.email_logo"
            :image="result.email_logo"
            @image-change="uploadFile('email_logo', null, $event)"
          />

          <file-upload
            v-else
            class="logo-upload upload-block"
            :image="result.email_logo"
            :file-uploading="fileUploading.email_logo"
            :btn-text="$t('profile.cngLogo', {type: $t('profile.emailLogo')})"
            @file-upload="uploadFile('email_logo', $event)"
          />
        </div>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('admin.sName') }}</label>
        <input
          type="text"
          :placeholder="$t('admin.sName')"
          v-model="result.site_name"
          :class="{invalid: !!!result.site_name && hasError}"
        >
        <span
          class="error"
          v-if="!!!result.site_name && hasError"
        >
          {{ $t('category.req', { type: $t('admin.sName')}) }}
        </span>
      </div>

      <div class="input-wrapper">
        <label>{{ $t('admin.sUrl') }}</label>
        <input
          type="text"
          :placeholder="$t('admin.sUrl')"
          v-model="result.site_url"
          :class="{invalid: !!!result.site_url && hasError}"
        >
      </div>

      <div class="input-wrapper">
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


      <div class="input-wrapper">
        <label>{{ $t('admin.cpyr') }}</label>
        <input
          :placeholder="$t('admin.cpyr')"
          v-model="result.copyright_text"
          :class="{invalid: !!!result.copyright_text && hasError}"
        />
      </div>


      <div class="dply-felx f-wrap gap-10">
        <div class="input-wrapper grow">
          <label>{{ $t('admin.pColr') }}</label>
          <input
            type="text"
            :placeholder="$t('admin.pColr')"
            v-model="result.primary_color"
          >
        </div>


        <div class="input-wrapper grow">
          <label>{{ $t('admin.pHvr') }}</label>
          <input
            type="text"
            :placeholder="$t('admin.pHvr')"
            v-model="result.primary_hover_color"
          >
        </div>
      </div>


      <div class="input-wrapper">
        <label>{{ $t('admin.styl') }}</label>
        <textarea
          :placeholder="$t('admin.styl')"
          v-model="result.styling"
        />

      </div>



    </template>
  </data-page>

</template>

<script>

  import DataPage from "~/components/partials/DataPage";
  import FileUpload from "~/components/FileUpload";
  import Spinner from "../components/Spinner";
  import ImageInput from "~/components/ImageInput";
  import util from "~/mixin/util";
  import {mapGetters, mapActions} from 'vuex'
  export default {
    name: "setting",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        fileUploading: {
          'header_logo': false,
          'footer_logo': false,
          'email_logo': false,
        },
        result: {
          id: '',
          site_name: '',
          site_url: '',
          meta_description: '',
          meta_keywords: '',
          meta_title: '',
          copyright_text: '',
          header_logo: '',
          footer_logo: '',
          email_logo: '',
          primary_color: '',
          primary_hover_color: '',
          styling: '',
        }
      }
    },
    props: {},
    mixins: [util],
    components: {
      ImageInput,
      Spinner,
      FileUpload,
      DataPage
    },
    computed: {
      ...mapGetters(['mediaStorage']),
    },
    methods: {

      async fetchingData() {
        try {
          this.loading = true

          const data = await this.getRequest({api: 'getSiteSetting'})

          if(data){
            this.result = data
          }

          this.loading = false
          } catch (e) {
            return this.$nuxt.error(e)
          }
        },
      async uploadFile(type, file, name = null){

        this.fileUploading[type] = true
        try {

          let params = {}
          if(file) {
            const fd = new FormData()
            fd.append('id', this.result.id)
            fd.append('photo', file)
            fd.append('type', type)
            params = fd
          } else {

            params['type'] = type
            params['id'] = this.result.id
            params['photo'] = name
          }

          const data = await this.setRequest({params: params, api: 'siteSettingUpload'})

          if(data){
            this.result = data
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
          this.fileUploading[type] = false
      },
      ...mapActions('common', ['setRequest', 'getRequest'])
    },

    async mounted() {
      await this.fetchingData()



    }
  }
</script>


