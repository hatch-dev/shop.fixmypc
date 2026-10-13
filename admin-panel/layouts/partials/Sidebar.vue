<template>
  <div v-if="sidebarPermissions">
    <!-- Mobile backdrop -->
    <div
      class="sidebar-overlay"
      @click="hideSidebar"
    ></div>
    <div class="sidebar">
    <div class="sidebar_innr">
        <div class="menu">
            <div
                v-for="(group, gIndex) in groupedSidebar"
                :key="gIndex"
                class="menu-section"
            >

                <!-- Section Title -->
                <div
                  v-if="group.title"
                  class="menu-section-title"
                  @click="toggleSection(group.index)"
                >
                  {{ group.title }}
                  <i
                    class="fa-solid fa-chevron-right toggle-icon"
                    :class="{open:sectionOpen[group.index]}"
                    ></i>
                </div>


                <!-- Menu Items -->
                <transition name="collapse">
                  <div v-show="!group.title || !sidebarOpen || sectionOpen[group.index]" class="section-items">
                      <nuxt-link
                        v-for="(item, index) in group.items"
                        :key="`${gIndex}-${index}`"
                        v-if="hasPermission(item) || !item.gate"
                        :to="item.path ? `/${item.path}` : '/'"
                        class="menu-item"
                        :class="{ active: isCurrentRoute(item) }"
                        :title="item.title"
                        :aria-label="item.title"
                        @click.native="onItemClick"
                        >
                        <span
                          v-if="resolvedIcon(item).svg"
                          class="menu-icon"
                          v-html="resolvedIcon(item).svg"
                        ></span>
                        <i v-else :class="resolvedIcon(item).class"></i>
                        <span class="menu-text">
                            {{ item.title }}
                        </span>
                      </nuxt-link>
                  </div>
                </transition>

            </div>
        </div>
    </div>
    <div class="user">
        <nuxt-link to="/profile"
            class="user-left"
            >
            <div class="user-avatar">
                <img :src="userAvatar" alt="User">
                <span class="status-dot"></span>
            </div>
            <div class="user-info">
                {{ userName }}
                <span>{{ userRole }}</span>
            </div>
        </nuxt-link>
        <button class="logout-icon" @click.prevent="loggingOut">
            <i class="fa-solid fa-right-from-bracket"></i>
        </button>
    </div>
    </div>
  </div>
</template>
<script>
  import {mapGetters, mapActions} from "vuex";
  import {buildSidebarMenu} from '~/mixin/sidebar-menu'
  import outsideClick from "~/directives/outside-click";
  import util from "~/mixin/util";

  export default {
    name: 'Sidebar',
    data() {
      return {
        sectionOpen: {},
        // Map of { menuKey: '<svg…>' } overrides uploaded from Settings → Sidebar Icons.
        sidebarIcons: {},
        logo: require('~/assets/images/fixmypc-icon.svg'),
        /*
         * The menu itself lives in mixin/sidebar-menu.js.
         *
         * It used to be written out again here, and the two copies drifted:
         * the shared one lost Trade Applications and Sign-in Banners, and this
         * one kept Upsell and Cross-sell long after those pages were deleted,
         * so the sidebar offered links to a 404. One source now.
         */
        sidebarsArr: buildSidebarMenu(this.$t.bind(this)),
      }
    },
    mixins: [util],
    directives: {outsideClick},
    components: {},
    computed: {
        allSectionsOpen() {
          const values = Object.entries(this.sectionOpen)
            .filter(([key]) => Number(key) !== 0)
            .map(([, value]) => value)

          return values.length && values.every(v => v === true)
        },
        userName() {
            return this.$auth?.user?.name ?? 'Admin'
        },
        userRole() {
            return this.$auth?.user?.role ?? 'Super Admin'
        },
        userAvatar() {
            const image = this.profile?.image || this.$auth?.user?.image
            return image
                ? this.getImageURL(image)
                : require('~/assets/images/avatar-placeholder.svg')
        },
        sidebarLogo() {
            return this.storeData?.image ?? this.siteSetting?.header_logo ?? require('~/assets/images/fixmypc-icon.svg')
        },
        groupedSidebar() {
          const groups = []
          let currentGroup = null
          let index = -1

          this.sidebarsArr.forEach(item => {
            if (item.section) {
              index++

              currentGroup = {
                title: item.section,
                items: [],
                index
              }

              groups.push(currentGroup)

            } else {
              if (!currentGroup) {
                index++

                if (this.sectionOpen[index] === undefined) {
                  this.$set(this.sectionOpen, index, false)
                }

                currentGroup = {
                  title: null,
                  items: [],
                  index
                }

                groups.push(currentGroup)
              }

              currentGroup.items.push(item)
            }
          })

          return groups
        },
        sidebarCollapsable(){
        const data = {}
        this.sidebarsArr.forEach(i => {
          if(!i.gate) {
            data[i.path] = i?.children?.map(j => { return j.gate })
          }
        })
        return data
      },
      ...mapGetters('admin', ['sidebarPermissions', 'isVendor', 'profile']),
      ...mapGetters('ui', ['sidebarOpen']),
      ...mapGetters('site-setting', ['siteSetting']),
      ...mapGetters('setting', ['storeData'])
    },
    watch: {
      '$route'() {
        this.openCurrentSection()
      }
    },
    methods: {
      toggleAllSections() {
        const shouldOpen = !this.allSectionsOpen
        this.setAllSections(shouldOpen)
      },
      setAllSections(open) {
        Object.keys(this.sectionOpen).forEach((key) => {
          if (Number(key) === 0) return
          this.$set(this.sectionOpen, key, open)
        })
      },
      toggleSection(index) {
        this.$set(this.sectionOpen, index, !this.sectionOpen[index])
      },
      onItemClick() {
        // Auto-close the sidebar after navigation on mobile so it does not cover the content.
        if (process.client && window.innerWidth < 768) {
          this.hideSidebar()
        }
      },
      openCurrentSection() {
        this.groupedSidebar.forEach(group => {
          if (!group.title) return
          const isActive = group.items.some(item => this.isCurrentRoute(item))
          if (isActive) {
            this.$set(this.sectionOpen, group.index, true)
          }
        })
      },
      async loggingOut() {
        try {
            this.clearSetting()
            await this.$auth.logout()
            this.settingDashboardNotice(false)
        } catch (e) {
            return this.$nuxt.error(e)
        }
      },
      checkBadge(item){
        return item?.badge || false
      },
      hasParentPermission(item){
        if(!item?.gate && !item.children){
          return true
        }
        if(!item?.gate) {
          // Checking if any child has permission
          return !!this.sidebarCollapsable[item.path]?.find(i => {
            return this.hasPermission({gate: i})
          })
        }
        return this.hasPermission(item)
      },
      hasPermission(item){
        return (this.sidebarPermissions &&
          (this.sidebarPermissions[`${item?.gate}.create`] !== undefined ||
            this.sidebarPermissions[`${item?.gate}.view`] !== undefined)
        )
      },
      isCurrentRoute(item) {
        const current = this.$route.path.replace(/\/$/, '')
        if (!item.path) {
          return current === '' || current === '/'
        }
        const target = `/${item.path}`.replace(/\/$/, '')
        return current === target || current.startsWith(target + '/')
      },
      // Resolve the icon to render for a menu item: a custom uploaded SVG (or the
      // built-in inline-SVG default) is rendered via v-html; otherwise the Font
      // Awesome class is bound to an <i>.
      resolvedIcon(item) {
        const key = item.path || 'dashboard'
        const custom = this.sidebarIcons && this.sidebarIcons[key]
        if (custom) {
          return {svg: custom}
        }
        if (typeof item.icon === 'string' && item.icon.trim().startsWith('<svg')) {
          return {svg: item.icon}
        }
        return {class: item.icon}
      },
      async fetchSidebarIcons() {
        try {
          const data = await this.getRequest({api: 'sidebarIconsFind'})
          this.sidebarIcons = data || {}
        } catch (e) {
          // Non-fatal: fall back to the default icons if the lookup fails.
          this.sidebarIcons = {}
        }
      },
      ...mapActions('common', ['getRequest']),
      ...mapActions('setting', ['clearSetting']),
      ...mapActions('ui', ['hideSidebar', 'toggleSidebar', 'settingDashboardNotice'])
    },
    mounted() {

      if (window.innerWidth < 778) {
        this.hideSidebar()
      }

      if(this.sidebarOpen){
        this.sidebarsArr.forEach((value, index) => {
          if (this.isCurrentRoute(value)) {
            return false
          } else {
            return value?.children?.forEach((v, i) => {
              if (this.isCurrentRoute(v)) {
                this.sidebarsArr[index].open = true
                this.sidebarsArr[index].childActive = true
                return false
              }
            })
          }
        })
      } else {
        this.sidebarsArr.forEach((value, index) => {
          if (this.isCurrentRoute(value)) {
            return false
          } else {
            return value?.children?.forEach((v, i) => {
              if (this.isCurrentRoute(v)) {
                this.sidebarsArr[index].childActive = true
                return false
              }
            })
          }
        })

      }

      this.groupedSidebar.forEach(group => {
        if (this.sectionOpen[group.index] === undefined) {
          this.$set(this.sectionOpen, group.index, false)
        }
      })

      // Open the section that contains the current route by default (matches design).
      this.openCurrentSection()

      this.$root.$on('toggle-all-sidebar-sections', (open) => {
          if (typeof open === 'boolean') {
            this.setAllSections(open)
          } else {
            this.toggleAllSections()
          }
      })

      this.fetchSidebarIcons()

      // The settings page emits this after a successful upload/reset so the
      // sidebar reflects icon changes without a full page reload.
      this.$root.$on('sidebar-icons-updated', (map) => {
        this.sidebarIcons = map || {}
      })


    },
    beforeDestroy() {
        this.$root.$off('toggle-all-sidebar-sections')
        this.$root.$off('sidebar-icons-updated')
    }
  }
</script>
<style lang="stylus">
  @import "~/assets/styles/sidebar.styl"
</style>
<style>
.collapse-enter-active,
.collapse-leave-active {
  transition: all 0.25s ease;
  overflow: hidden;
}

.collapse-enter-from,
.collapse-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
.section-left {
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Inline SVG menu icons mirror the sizing of the Font Awesome <i> icons. */
.menu-item .menu-icon {
  width: 20px;
  min-width: 20px;
  height: 20px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.menu-item .menu-icon svg {
  width: 16px;
  height: 16px;
  max-width: 100%;
  max-height: 100%;
}

.sidebar-opened .menu-item.active svg {
    fill: #333199;
}
.sidebar-opened .menu-item.active path {
    fill: #333199 !important;
}

</style>
