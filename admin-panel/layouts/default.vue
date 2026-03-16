<template>
  <div
    class="main"
    :class="`${$route.name} ${sidebarClass}`"
  >

    <div
      v-if="loading"
      class="spinner-wrapper layout-spinner"
    >
      <spinner
        :radius="100"
        color="primary"
      />
    </div>
    <Header />
    <Sidebar />
    <div
      class="content"
    >
      <p
        v-if="!$store.state.admin.activated"
        class="dashboard-notice info-msg mb-20 mb-sm-15"
      >
        {{ $t('dataPage.na') }}
        <nuxt-link class="link" to="/setting/miscellaneous">
          <b>{{ $t('dataPage.here') }}</b>
        </nuxt-link>
      </p>

      <p
        v-if="hasSetNotice && $store.state.admin.isSuperAdmin"
        class="dashboard-notice info-msg mb-20 mb-sm-15"
      >
        <b>{{ $t('setting.imp') }}</b>: {{ $t('setting.cClearMsg') }}
        <nuxt-link to="/setting/currency"><b>{{ $t('list.set') }}</b></nuxt-link> >
        <nuxt-link to="/setting/clear-cache"><b>{{ $t('setting.cClear') }}</b></nuxt-link>.

        <button
          class="close-btn"
          @click="closeNote"
        >
            <i class="icon close-icon"/>
          </button>
      </p>

      <nuxt
        v-if="$store.state.admin.permissions"
        :key="fullUrl"
        :nuxt-child-key="$route.path"
        />
    </div>

    <transition name="fade" mode="out-in">
      <toast-message
        v-if="toastMessageStatus"
        :is-error="toastError"
        @hide="hideToast"
        :message="toastMessage"
      />
    </transition>
  </div>
</template>

<script>

  import Header from '~/layouts/partials/Header'
  import Sidebar from '~/layouts/partials/Sidebar'
  import {mapGetters, mapActions} from 'vuex'
  import ToastMessage from '~/components/ToastMessage.vue'
  import Spinner from "~/components/Spinner"

  export default {
    name: "default",
    data() {
      return {
        loading: false
      }
    },
    components: {
      Sidebar,
      Header,
      ToastMessage,
      Spinner
    },
    watch:{
      '$route'(){
        this.setErrors()
      }
    },
    mixins: [],
    computed:{
      sidebarClass(){
        return this.sidebarOpen ? 'sidebar-opened' : 'sidebar-closed'
      },
      hasSetNotice(){
        return this.dashboardNotice && this.dashboardNotice === 'false' || false
      },
      fullUrl() {
        return this.$route.fullPath.split('#')[0]
      },
      ...mapGetters('ui', ['sidebarOpen', 'toastMessage', 'toastError', 'toastMessageStatus', 'dashboardNotice']),
      ...mapGetters('language', ['currentLanguage']),
      ...mapGetters('admin', ['profile']),
    },
    methods: {
      closeNote(){
        this.settingDashboardNotice(true)
      },
      ...mapActions('ui', ['hideToast', 'setErrors', 'settingDashboardNotice']),
      ...mapActions('admin', ['setProfile']),
      ...mapActions('common', ['getRequest']),
    },
    async mounted() {
      document.body.classList.add(this.currentLanguage?.direction || 'ltr')

      if(!this.profile){
        if(!process.env.apiBase.trim()) {
          this.$axios.defaults.baseURL = window.location.origin + '/'
        }
      }
    }
  }
</script>

<style scoped>

</style>
