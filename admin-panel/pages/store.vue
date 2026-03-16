<template>
  <div class="tab-sidebar">
    <h4 class="title">{{ $t('setting.store') }}</h4>

    <div class="form-wrapper">

      <form

        @submit.prevent="updateStore"
      >
        <error-formatter/>

        <error-formatter
          type="image"
        />

        <div
          v-if="gettingStore"
          class="spinner-wrapper"
        >
          <spinner
            :radius="60"
            color="primary"
            class="mr-15"
          />
        </div>

        <div v-if="!gettingStore" class="input-wrapper b-b pb-15">
          <label class="mb-15">
            {{ $t('setting.sLogo') }}
          </label>

          <div
            v-if="!gate || (gate && $can(gate, 'edit'))"
            class=""
          >
            <image-input
              v-if="mediaStorageData.URL === mediaStorage"
              :saving="fileUploading"
              :image="result.image"
              @image-change="uploadFile(null, $event)"
            />

            <file-upload
              v-else
              class="logo-upload upload-block"
              :image="result.image"
              :file-uploading="fileUploading"
              :btn-text="$t('setting.cLogo')"
              @file-upload="uploadFile"
            />
          </div>

          <img
            v-else
            :src="getImageURL(result.image)"
          >
        </div>

        <div :class="{'has-error': hasError}">
          <div class="input-wrapper">
            <label>
              {{ $t('setting.sName') }}
            </label>
            <input
              type="text"
              @change="slugChange($event, 'name')"
              :placeholder="$t('setting.sName')"
              v-model="result.name"
              :class="{invalid: !result.name && hasError}"
            >
            <span
              class="error"
              v-if="!result.name && hasError"
            >
              {{ $t('category.req', { type: $t('setting.sName')}) }}
            </span>
          </div>

          <div class="input-wrapper">
            <label>
              {{ $t('category.slug') }}
            </label>
            <input
              type="text"
              :placeholder="$t('category.slug')"
              v-model="result.slug"
              :class="{invalid: !result.slug && hasError}"
            >
            <span
              class="error"
              v-if="!result.slug && hasError"
            >
              {{ $t('category.req', { type: $t('category.slug')}) }}
            </span>
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




          <whats-app-setting
            :store-data="result"
          />
        </div>

        <div class="dply-felx j-right">
          <ajax-button
            v-if="!gate || (gate && $can(gate, 'edit'))"
            class="primary-btn"
            :text="$t('setting.sv')"
            :fetching-data="updatingStore"
          />
        </div>

      </form>


    </div>
  </div>

</template>

<script>

  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import validation from "~/mixin/validation"
  import FileUpload from "~/components/FileUpload"
  import Spinner from '~/components/Spinner'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import util from "~/mixin/util"
  import ImageInput from "../components/ImageInput";
  import WhatsAppSetting from "../components/partials/WhataAppSetting";

  export default {
    name: 'Store',
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        gate: 'store',
        hasError: false,
        gettingStore: false,
        updatingStore: false,
        fileUploading: false,
        result: {
          id: '',
          image: '',
          name: '',
          slug: '',
          meta_description: '',
          meta_title: '',
        }
      }
    },
    props: {},
    mixins: [util, validation],
    components: {
      WhatsAppSetting,
      ImageInput,
      AjaxButton,
      FileUpload,
      Spinner,
      ErrorFormatter
    },
    computed: {
      ...mapGetters(['mediaStorage']),
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('site-setting', ['siteSetting']),
    },
    methods: {
      async uploadFile(file, name = null) {
        this.fileUploading = true


        let params = {}
        if (file) {
          const fd = new FormData()
          fd.append('photo', file)
          params = fd
        } else {
          params['photo'] = name
        }


        try {
          const data = await this.setRequest({params: params, api: 'setStoreLogo'})

          if (data) {
            this.result = data
            this.updateStoreData(data)
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.fileUploading = false
      },
      async updateStore() {

        if (this.result.name && this.result.slug) {
          this.hasError = false

          this.updatingStore = true
          try {
            const data = await this.setRequest({params: this.result, api: 'setStore'})
            if (data) {
              this.result = data
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.updatingStore = false
        } else {
          this.hasError = true
        }
      },
      ...mapActions('setting', ['updateStoreData']),
      ...mapActions('common', ['getRequest', 'setRequest'])
    },
    created() {
    },
    async mounted() {
      this.gettingStore = true
      try {
        const data = await this.getRequest({params: {}, api: 'getStore'})

        if (data) {
          this.result = data
        }

      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.gettingStore = false

    }
  }
</script>
