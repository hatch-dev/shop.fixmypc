<template>
  <header
    :class="{'dots-open': dotsOpen}"
  >
    <div class="dply-felx logo-wrapper">
      <button
        @click.prevent="leftMenuToggle"
        class="dply-felx j-center toggle-menu-btn"
      >
        <i
          class="ignore-click icon menu-icon mr-0"
        />
      </button>
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
          <!-- <button
            v-if="$can('profile', 'view')"
            @click.prevent="goProfile"
          >
            <i
              class="icon profile"
            />
            {{ $t('error.pro') }}
          </button> -->
          <!-- <button
            @click.prevent="loggingOut"
          >
            <i
              class="icon logout"
            />
            {{ $t('error.log') }}
          </button> -->
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
        dotsOpen: false
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
