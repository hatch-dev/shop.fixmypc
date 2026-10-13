<template>
  <header
    :class="{'dots-open': dotsOpen}"
  >
    <div class="logo-wrapper">
      <nuxt-link class="header-brand" to="/">
        <img
          :src="siteLogo ? getImageURL(siteLogo) : require('~/assets/images/fixmypc-icon.svg')"
          alt="FIXMYPC"
        >
        <span>FIXMYPC</span>
      </nuxt-link>
      <div class="header-actions">
        <button
            class="expand-btn"
            :class="{ active: sectionsExpanded }"
            @click="toggleAllSections"
            :title="sectionsExpanded ? 'Collapse all' : 'Expand all'"
        >
            <i
              class="fa-solid"
              :class="sectionsExpanded ? 'fa-down-left-and-up-right-to-center' : 'fa-up-right-and-down-left-from-center'"
            ></i>
        </button>
        <button
            class="toggle-menu-btn"
            :class="{ active: sidebarOpen }"
            :title="sidebarOpen ? 'Collapse menu' : 'Expand menu'"
            @click.prevent="leftMenuToggle"
        >
            <i
              class="fa-solid"
              :class="sidebarOpen ? 'fa-bars-staggered' : 'fa-bars'"
            ></i>
        </button>
      </div>

    </div>

    <div class="dply-felx grow right-wrap">
      <div class="dply-felx j-left pos-rel">

        <dropdown
          v-if="$can('language', 'view') && Object.keys(languages).length > 1"
          :selected-key="currentLanguage.code"
          :options="languages"
          class="lang-down"
          key-name="name"
          @clicked="selectedLanguage"
        />

        <user-messages
          v-if="$can('message', 'view')"
        />
        <button
          data-ignore="right-menu"
          @click.prevent="rightMenuToggle"
          class="dots-btn"
        >
          <i
            class="ignore-click icon more-dots"
          />
        </button>
        <div
          class="right-area"
          id="right-menu"
          v-outside-click="closeRightMenu"
        >
          <button
            v-if="$can('profile', 'view')"
            class="profile-btn"
            :title="$t('error.pro')"
            @click.prevent="goProfile"
          >
            <i
              class="icon profile"
            />
            {{ $t('error.pro') }}
          </button>
          <button
            class="logout-btn"
            :title="$t('error.log')"
            @click.prevent="loggingOut"
          >
            <i
              class="icon logout"
            />
            {{ $t('error.log') }}
          </button>
        </div>


      </div>
    </div>
  </header>
</template>

<script>

  import outsideClick from '~/directives/outside-click'
  import util from '~/mixin/util'
  import {mapGetters, mapActions} from 'vuex'
  import InlinePopOver from "~/components/InlinePopOver"
  import Spinner from "~/components/Spinner"
  import UserMessages from "~/components/partials/UserMessages"
  import Dropdown from "~/components/Dropdown";
  import ClearCacheBtn from "~/components/partials/ClearCacheBtn";

  export default {
    name: 'Header',
    data() {
      return {
        dotsOpen: false,
        sectionsExpanded: false
      }
    },
    mixins: [util],
    components: {ClearCacheBtn, Dropdown, UserMessages, Spinner, InlinePopOver},
    computed: {
      siteLogo() {
        return this.storeData?.image ?? this.siteSetting?.header_logo ?? this.defaultImage
      },
      ...mapGetters('site-setting', ['siteSetting']),
      ...mapGetters('setting', ['setting', 'storeData']),
      ...mapGetters('language', ['languages', 'currentLanguage']),
      ...mapGetters('ui', ['sidebarOpen']),
    },
    directives: {outsideClick},
    methods: {
      async selectedLanguage(data){
        document.cookie = 'currentLanguage=' + data.key + '; path=/; expires=' + 365 * 60 * 60 * 24
        location.reload()
      },
      async loggingOut() {
        try {
          this.clearSetting()
          await this.$auth.logout()
          // window.location.reload()
          this.settingDashboardNotice(false)
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      leftMenuToggle() {
        this.toggleSidebar()
      },
      toggleAllSections() {
        this.sectionsExpanded = !this.sectionsExpanded
        this.$root.$emit('toggle-all-sidebar-sections', this.sectionsExpanded)
      },
      rightMenuToggle() {
        this.hideSidebar()
        this.dotsOpen = !this.dotsOpen
      },
      closeRightMenu() {
        this.dotsOpen = false
      },
      goProfile() {
        this.$route.name !== 'profile' ? this.$router.push({path: '/profile'}) : false
        this.dotsOpen = false
      },
      ...mapActions('setting', ['clearSetting']),
      ...mapActions('ui', ['hideSidebar', 'toggleSidebar', 'settingDashboardNotice'])
    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/header.styl"
</style>
