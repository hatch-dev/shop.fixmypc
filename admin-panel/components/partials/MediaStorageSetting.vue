<template>
  <form
    :class="{'has-error': hasError}"
    @submit.prevent="updateData"
  >

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

    <div class=" ">

      <div
        class="input-wrapper single-line"
      >
        <label>
          {{ $t('list.ms') }}
        </label>

        <dropdown
          :options="mediaStorages"
          :selected-key="result.mediaStorage"
          @clicked="changeMediaStorage"
        />
      </div>

      <div
        class="input-wrapper single-line"
      >
        <label>
          {{ $t('list.tp') }}
        </label>
        <input
          type="text"
          placeholder="eg. thumb-"
          v-model="result.thumbPrefix"
        >
      </div>

      <div
        class="input-wrapper single-line"
      >
        <label>
          {{ $t('list.di') }}
        </label>
        <input
          type="text"
          placeholder="eg. default-image.webp"
          v-model="result.defaultImage"
        >
      </div>

      <div
        v-if="result.mediaStorage === 'URL'"
        class="input-wrapper single-line"
      >
        <label>
          {{ $t('list.cu') }}
        </label>
        <input
          type="text"
          :placeholder="$t('list.cu')"
          v-model="result.cdnUrl"
        >
      </div>

      <template v-else-if="result.mediaStorage === 'GCS'">

        <div
          class="input-wrapper single-line"
        >
          <label>
            {{ $t('list.cu') }}
          </label>
          <input
            type="text"
            :placeholder="$t('list.cu')"
            v-model="result.cdnUrl"
          >
        </div>

        <div
          class="input-wrapper single-line"
        >
          <label>
            {{ $t('dataPage.gcpi') }}
          </label>
          <input
            type="password"
            :placeholder="$t('dataPage.gcpi')"
            v-model="result.gcProjectId"
          >
        </div>

        <div
          class="input-wrapper single-line"
        >
          <label>
            {{ $t('dataPage.gcsb') }}
          </label>
          <input
            type="password"
            :placeholder="$t('dataPage.gcsb')"
            v-model="result.gcStorageBucket"
          >
        </div>

        <div
          class="input-wrapper single-line"
        >
          <label>
            {{ $t('dataPage.gcspp') }}
          </label>
          <input
            type="password"
            :placeholder="$t('dataPage.gcspp')"
            v-model="result.gcStoragePathPrefix"
          >
        </div>
      </template>
    </div>

    <div class="oflow-hidden">
      <ajax-button
        v-if="$can('setting', 'edit')"
        class="primary-btn"
        :fetching-data="updating"
      />
    </div>

  </form>

</template>

<script>

  import AjaxButton from '~/components/AjaxButton'
  import validation from "~/mixin/validation"
  import Spinner from '~/components/Spinner'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import util from "~/mixin/util"
  import {mapActions} from 'vuex'
  import Dropdown from "../Dropdown";

  export default {
    name: 'MediaStorageSetting',
    data() {
      return {
        gettingData: false,
        updating: false,
        loading: false,
        hasError: false,
        result: {
          mediaStorage: null,
        }
      }
    },
    props: {},
    mixins: [util],
    components: {
      Dropdown,
      AjaxButton,
      Spinner,
      ErrorFormatter
    },
    computed: {},
    methods: {
      changeMediaStorage(data){
        this.result.mediaStorage = data.key
      },
      async fetchingData() {
        try {
          this.loading = true

          const data = await this.getRequest({api: 'mediaStorageFind'})

          if(data){
            this.result = data
          }

          this.loading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async updateData() {
        if (this.result.mediaStorage) {
          this.updating = true

          try {
            const data = await this.setRequest({params: this.result, api: 'mediaStorageAction'})

            if(data){
              this.result = data

              setTimeout(()=>{
                window.location.reload()
              }, 1000)
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.updating = false

        } else {
          this.hasError = true
        }
      },
      ...mapActions('common', ['setRequest', 'getRequest'])
    },
    created() {
    },
    async mounted() {
      await this.fetchingData()
    }
  }
</script>
<style lang="stylus">

</style>
