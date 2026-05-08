<template>
  <div v-if="sidebarPermissions" class="sidebar">
    <div class="sidebar_innr">
        <div class="logo">
            <nuxt-link to="/">
                <img
                :src="sidebarLogo ? getImageURL(sidebarLogo) : require('~/assets/images/fixmypc-icon.svg')"
                alt="Logo"
                />
            </nuxt-link>
            <span>FIXMYPC</span>
        </div>
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
                  <span class="toggle-icon">
                    {{ sectionOpen[group.index] ? '-' : '+' }}
                  </span>
                </div>

                <!-- Expand / Collapse Button below Dashboard -->
                <div v-if="gIndex === 0" class="expand-all-container">
                  <button
                    class="expand-all-btn"
                    @click.stop="toggleAllSections"
                    :title="allSectionsOpen ? 'Collapse All' : 'Expand All'"
                  >
                    <i 
                      class="fa-solid"
                      :class="allSectionsOpen ? 'fa-angles-up' : 'fa-angles-down'"
                    ></i>
                    {{ allSectionsOpen ? 'Collapse All' : 'Expand All' }}
                  </button>
                </div>

                <!-- Menu Items -->
                <transition name="collapse">
                  <div v-show="sectionOpen[group.index]" class="section-items">
                      <nuxt-link
                        v-for="(item, index) in group.items"
                        :key="`${gIndex}-${index}`"
                        v-if="hasPermission(item) || !item.gate"
                        :to="item.path ? `/${item.path}` : '/'"
                        class="menu-item"
                        :class="{ active: isCurrentRoute(item) }"
                        :title="item.title"
                        :aria-label="item.title"
                        >
                        <i :class="item.icon"></i>
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
</template>
<script>
  import {mapGetters, mapActions} from "vuex";
  import outsideClick from "~/directives/outside-click";
  import util from "~/mixin/util";

  export default {
    name: 'Sidebar',
    data() {
      return {
        sectionOpen: {},
        logo: require('~/assets/images/fixmypc-icon.svg'),
        sidebarsArr: [
        { path: '', title: this.$t('error.das'), icon: 'fa-solid fa-circle-exclamation', gate: 'dashboard' },
        { section: 'STORE' },
        { path: 'categories', title: this.$t('error.cat'), icon: 'fa-solid fa-layer-group', gate: 'category' },
        { path: 'products', title: this.$t('error.prod'), icon: 'fa-solid fa-box', gate: 'product' },
        { path: 'attributes', title: this.$t('list.attr'), icon: 'fa-solid fa-tags', gate: 'attribute' },
        { path: 'brands', title: this.$t('error.brands'), icon: 'fa-solid fa-copyright', gate: 'brand' },
        { path: 'tax-rules', title: this.$t('error.tr'), icon: 'fa-solid fa-receipt', gate: 'tax_rule' },
        { path: 'shipping-rules', title: this.$t('brand.shipRules'), icon: 'fa-solid fa-truck-fast', gate: 'shipping_rule' },
        { path: 'product-collections', title: this.$t('error.col'), icon: 'fa-solid fa-layer-group', gate: 'product_collection' },
        { path: 'bulk-product-editor', title: this.$t('error.bulkProduct'), icon: 'fa-solid fa-list-check', gate: 'product_bulk_update' },
        { path: 'rating-reviews', title: this.$t('error.rr'), icon: 'fa-solid fa-star', gate: 'rating_review' },

        { section: 'BUSINESS PRODUCT' },
        { path: 'business-products', title: 'Business Products', icon: 'fa-solid fa-briefcase', gate: 'business_product' },
        
        { section: 'LOYALTY' },
        { path: 'loyalty-groups', title: 'Loyalty Groups', icon: 'fa-solid fa-award', gate: 'loyalty_group' },

        { section: 'WALLET' },
        { path: 'wallet-overview', title: 'Wallet Overview', icon: 'fa-solid fa-wallet', gate: 'wallet_log' },

        { section: 'ORDERS' },
        { path: 'orders', title: this.$t('error.orders'), icon: 'fa-solid fa-cart-shopping', gate: 'order' },
        // { path: 'refunded', title: 'Refunded', icon: 'fa-solid fa-rotate-left' },
        // { path: 'cancelled', title: 'Cancelled', icon: 'fa-solid fa-ban' },
        // { path: 'incomplete', title: 'Incomplete', icon: 'fa-solid fa-file-circle-xmark' },

        { section: 'PROMOTIONS' },
        { path: 'upsell', title: this.$t('index.upsell'), icon: 'fa-solid fa-arrow-up-right-dots' },
        { path: 'crosssell', title: this.$t('index.crosssell'), icon: 'fa-solid fa-arrow-trend-up' },
        { path: 'flash-sales', title: this.$t('error.fs'), icon: 'fa-solid fa-bolt', gate: 'flash_sale' },
        { path: 'flash-discount', title: 'Flash Discount', icon: 'fa-solid fa-percent', gate: 'flash_discount' },
        { path: 'bundle-deals', title: this.$t('error.bd'), icon: 'fa-solid fa-gift', gate: 'bundle_deal' },
        { path: 'vouchers', title: this.$t('error.vou'), icon: 'fa-solid fa-ticket', gate: 'voucher' },
        { path: 'gift-vouchers', title: 'Gift Vouchers', icon: 'fa-solid fa-gift', gate: 'gift_voucher' },
        
        { section: 'MARKETING' },
        { path: 'subscription-email-formats', title: this.$t('error.ef'), icon: 'fa-solid fa-envelope-open-text', gate: 'subscription_email_format'},
        // { path: 'campaign', title: 'Manage Campaign', icon: 'fa-solid fa-bullhorn' },
        // { path: 'lists', title: 'Manage Lists', icon: 'fa-solid fa-address-book' },

        { section: 'PROCUREMENT' },
        { path: 'procurement/suppliers', title: this.$t('procurement.suppliers'), icon: 'fa-solid fa-truck', gate: 'supplier' },
        // { path: 'procurement/suppliers/add', title: 'New Requests', icon: 'fa-solid fa-file-circle-plus' },

        { section: 'USER MANAGEMENT' },
        { path: 'admins-vendors', title: this.$t('error.av'), icon: 'fa-solid fa-user-tie', gate: 'admin' },
        { path: 'registered-users', title: this.$t('profile.registered'), icon: 'fa-solid fa-users', gate: 'user' },
        { path: 'guest-users', title: this.$t('profile.guest'), icon: 'fa-solid fa-user', gate: 'user' },
        { path: 'subscribers', title: this.$t('error.subCrbs'), icon: 'fa-solid fa-user-plus', gate: 'subscriber' },
        { path: 'roles-permissions', title: this.$t('error.rp'), icon: 'fa-solid fa-key', gate: 'role' },

        { section: 'FINANCE' },
        { path: 'withdrawal-requests', title: this.$t('error.req'), icon: 'fa-solid fa-money-bill-transfer', gate: 'withdrawal_request' },
        { path: 'withdrawal-accounts', title: this.$t('error.acc'), icon: 'fa-solid fa-building-columns', gate: 'withdrawal_account' },


        { section: 'UI' },
        { path: 'templates', title: this.$t('index.templates'), icon: 'fa-solid fa-clone' },
        { path: 'pages', title: this.$t('error.pages'), icon: 'fa-solid fa-file-lines', gate: 'page' },
        { path: 'home-slider', title: this.$t('profile.hSlid'), icon: 'fa-solid fa-images', gate: 'home_slider' },
        { path: 'banners', title: this.$t('admin.banners'), icon: 'fa-solid fa-image', gate: 'banner' },
        { path: 'footer-links', title: this.$t('error.fl'), icon: 'fa-solid fa-link', gate: 'footer_link' },
        { path: 'header-links', title: this.$t('dataPage.hl'), icon: 'fa-solid fa-bars', gate: 'header_link' },
        { path: 'site-features', title: this.$t('title.sf'), icon: 'fa-solid fa-star-half-stroke', gate: 'home_slider' },
        { path: 'site-setting', title: this.$t('admin.site'), icon: 'fa-solid fa-sliders', gate: 'site_setting' },
        { path: 'custom-scripts', title: this.$t('title.cs'), icon: 'fa-solid fa-code', gate: 'site_setting' },

        // { section: 'REPORTS' },
        // { path: 'sales-report', title: 'Sales Report', icon: 'fa-solid fa-chart-line' },
        // { path: 'products-report', title: 'Products Report', icon: 'fa-solid fa-boxes-stacked' },
        // { path: 'tax-report', title: 'Tax Report', icon: 'fa-solid fa-receipt' },
        // { path: 'refund-report', title: 'Refund Report', icon: 'fa-solid fa-rotate-right' },
        // { path: 'traffic-report', title: 'Traffic Report', icon: 'fa-solid fa-chart-pie' },
        // { path: 'profit-loss', title: 'Profit Loss', icon: 'fa-solid fa-chart-simple' },

        { section: 'SETTINGS' },
        { path: 'bulk-upload', title: this.$t('title.bu'), icon: 'fa-solid fa-upload', gate: 'bulk_upload' },
        { path: 'store', title: this.$t('error.store'), icon: 'fa-solid fa-gear', gate: 'store' },
        { path: 'setting/currency', title: this.$t('list.set'), icon: 'fa-solid fa-coins', gate: 'setting' },
        ],
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
            return this.$auth?.user?.image
                ? this.getImageURL(this.$auth.user.image)
                : 'https://i.pravatar.cc/100'
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
                  this.$set(this.sectionOpen, index, true)
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
      ...mapGetters('admin', ['sidebarPermissions', 'isVendor']),
      ...mapGetters('ui', ['sidebarOpen']),
      ...mapGetters('site-setting', ['siteSetting']),
      ...mapGetters('setting', ['storeData'])
    },
    watch: {
    },
    methods: {
      toggleAllSections() {
        const shouldOpen = !this.allSectionsOpen
        Object.keys(this.sectionOpen).forEach((key, index) => {
          if (Number(key) === 0) return
          this.$set(this.sectionOpen, key, shouldOpen)
        })
      },
      toggleSection(index) {
        this.sectionOpen[index] = !this.sectionOpen[index]
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
          this.$set(this.sectionOpen, group.index, true)
        }
      })


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

.expand-all-btn {
  width: 100%;
  border-radius: 6px;
  padding: 6px 10px;
  display: flex;
  align-items: center;
  gap: 6px;
  border: 1px solid var(--border-color);
  background: var(--surface-color);
  color: var(--text-muted);
  font-size: 12px;
}

.expand-all-btn:hover {
  background: var(--hover-color);
  color: var(--primary-color);
}

.expand-all-container {
  padding: 6px 0 8px;
}
</style>
