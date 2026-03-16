<template>

  <div v-if="validLicence" class="tab-sidebar">
    <h4 class="title">{{ $t('ship.ps') }}</h4>

    <div class="form-wrapper">

      <form

        @submit.prevent="updateSetting"
      >
        <error-formatter/>

        <error-formatter
          type="image"
        />

        <div
          v-if="gettingData"
          class="spinner-wrapper"
        >
          <spinner
            :radius="60"
            color="primary"
            class="mr-15"
          />
        </div>

        <div v-if="!gettingData" class="input-wrapper b-b pb-15">
          <label class="mb-15">
            {{ $t('setting.sLogo') }}
          </label>

          <div
            v-if="!gate || (gate && $can(gate, 'edit'))"
            class=""
          >
            <file-upload
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

        <div>
          <div class="input-wrapper">

            <label>{{ $t('ship.wdt') }}</label>
            <input
              type="text"
              :placeholder="$t('ship.wdt')"
              v-model="result.width"
            >
          </div>

          <div class="input-wrapper">

            <label>{{ $t('list.addr') }}</label>
            <textarea
              :placeholder="$t('list.addr')"
              v-model="result.address"
            ></textarea>
          </div>

          <div class="input-wrapper">

            <label>{{ $t('ship.ht') }}</label>
            <textarea
              :placeholder="$t('ship.ht')"
              v-model="result.header_text"
            ></textarea>
          </div>

          <div class="input-wrapper">

            <label>{{ $t('ship.ft') }}</label>
            <textarea
              :placeholder="$t('ship.ft')"
              v-model="result.footer_text"
            ></textarea>
          </div>

          <label
            v-if="$store.state.admin.isSuperAdmin"
            class="input-wrapper block">
            <input type="checkbox" v-model="result.is_default">
            {{ $t('ship.md') }}
          </label>
        </div>

        <div class="dply-felx j-right">

          <ajax-button
            v-if="!gate || (gate && $can(gate, 'delete'))"
            class="delete-btn"
            type="button"
            :text="$t('category.delete')"
            :fetching-data="deletingData"
            @clicked="deleteItem"
          />

          <ajax-button
            v-if="!gate || (gate && $can(gate, 'edit'))"
            class="primary-btn"
            :text="$t('setting.sv')"
            :fetching-data="updatingData"
          />
        </div>

      </form>


    </div>
  </div>
</template>

<script>

  import util from "~/mixin/util"
  import Spinner from "~/components/Spinner"
  import DataPage from "../../components/partials/DataPage";
  import AjaxButton from "../../components/AjaxButton";
  import FileUpload from "../../components/FileUpload";
  import ErrorFormatter from "../../components/ErrorFormatter";
  import {mapActions, mapGetters} from 'vuex'

  export default {
    name: "configuration",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        validLicence: false,
        deletingData: false,
        updatingData: false,
        gettingData: false,
        fileUploading: false,
        gate: 'pos_setting',
        result: {
          id: '',
          width: '',
          image: '',
          address: '',
          footer_text: '',
          header_text: '',
          is_default: false
        }
      }
    },
    mixins: [util],
    components: {
      ErrorFormatter,
      FileUpload,
      AjaxButton,
      DataPage,
      Spinner,
    },
    computed: {
      ...mapGetters('admin', ['posPublicKey']),
    },
    methods: {
      async updateSetting(){
          this.updatingData = true
          try {
            const data = await this.setRequest({params: this.result, api: 'setPosSetting'})

            if (data && data?.status !== 201) {
              this.reloadPage()
            }
          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.updatingData = false
      },
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
          const data = await this.setRequest({params: params, api: 'setPosSettingImage'})

          if (data && data?.status !== 201) {
            this.reloadPage()
          }

        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.fileUploading = false
      },
      async deleteItem() {
        if (confirm(this.$t('admin.dltMsg'))) {
          try {
            this.deletingData = true
            await this.deleteData({params: "", api: 'deletePosSetting' })
            this.deletingData = false
            this.reloadPage()
          }catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      ...mapActions('common', ['setRequest', 'getRequest', 'deleteData'])
    },
    async mounted() {
      const domain = window.location.hostname

      if(this.posPublicKey){
        let licence = this.phpDecryption(this.posPublicKey)
        const licenceObj = JSON.parse(licence)
        const validLicenceItem = !licenceObj?.p || (licenceObj?.p && licenceObj?.p === 'pos-ishop')

        if(domain.includes('admin.ishop.cholobangla.com') || domain.includes('localhost') ||
          domain.includes("127.0.0.1") || (licence.includes(domain) && validLicenceItem)){
          this.validLicence = true
        }
      } else if(domain.includes('admin.ishop.cholobangla.com') || domain.includes('localhost') ||
        domain.includes("127.0.0.1")){
        this.validLicence = true
      }

      this.gettingData = true
      try {
        const data = await this.getRequest({params: {}, api: 'getPosSetting'})

        if (data) {
          this.result = data
        }

      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.gettingData = false
    }
  }
</script>

<style scoped>

</style>
