<template>
  <div
    v-if="sidebarPermissions"
    class="sidebar"
  >
    <div
      v-if="childrenOpened"
      class="child-layer"
      @click="closeChild"
    />
    <ul class="sb sb-2">



      <li
        v-for="(value, index) in sidebarsArr"
        :key="index"
        :class="[{'child-open': value.open}, {'child-active': value.childActive}, {active: isCurrentRoute(value)}]"
      >


        <nuxt-link
          v-if="hasParentPermission(value)"
          :event="value.children ? '' : 'click'"
          :to="`/${value.path}`"
          :title="value.title"
          @click.native="clicked(value)"
        >
          <i
            class="icon"
            :class="value.icon"
          />
          <span class="dply-felx grow">
            <span class="dply-felx grow">
              {{ value.title }}
              <span v-if="checkBadge(value)" class="plugin-badge">{{ $t('ship.pls') }}</span>
            </span>

            <i
              v-if="value.children"
              class="icon arrow-down"
            />
          </span>
        </nuxt-link>

        <ul
          v-if="value.children && value.open"
          class="child"
        >
          <li
            v-for="(child, i) in value.children"
            :key="i"
            :class="{active: isCurrentRoute(child)}"
          >
            <nuxt-link
              v-if="hasPermission(child)"
              :event="child.children ? '' : 'click'"
              :to="`/${child.path}`"
              @click.native="clicked(child)"
              :title="child.title"
            >
              <i
                class="icon"
                :class="child.icon"
              />
              <span class="">{{ child.title }}</span>
            </nuxt-link>
          </li>
        </ul>
      </li>

    </ul>

    <button
      class="minimize-btn centered-flex"
      @click.prevent="toggleSidebar"
    />

    <transition name="fade" mode="out-in">
      <div
        v-if="sidebarOpen"
        class="layer"
        @click.prevent="hideSidebar"
      />
    </transition>

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
        sidebarsArr: [
          {
            path: '',
            title: this.$t('error.das'),
            icon: 'dashboard',
            gate: 'dashboard'
          },
          {
            path: 'product',
            title: this.$t('fSale.product'),
            icon: 'products',
            open: false,
            children: [
              {
                path: 'categories',
                title: this.$t('error.cat'),
                icon: 'categories',
                gate: 'category'
              },
              {
                path: 'brands',
                title: this.$t('error.brands'),
                icon: 'brands',
                gate: 'brand'
              },
              {
                path: 'attributes',
                title: this.$t('list.attr'),
                icon: 'attributes',
                gate: 'attribute'
              },
              {
                path: 'tax-rules',
                title: this.$t('error.tr'),
                icon: 'tax-rules',
                gate: 'tax_rule'
              },
              {
                path: 'shipping-rules',
                title: this.$t('brand.shipRules'),
                icon: 'shipping-rules',
                gate: 'shipping_rule'
              },
              {
                path: 'product-collections',
                title: this.$t('error.col'),
                icon: 'product-collections',
                gate: 'product_collection'
              },
              {
                path: 'bundle-deals',
                title: this.$t('error.bd'),
                icon: 'bundle-deals',
                gate: 'bundle_deal'
              },
              {
                path: 'vouchers',
                title: this.$t('error.vou'),
                icon: 'vouchers',
                gate: 'voucher'
              },
              {
                path: 'products',
                title: this.$t('error.prod'),
                icon: 'products',
                form: ['product', 'ratingReviews'],
                gate: 'product'
              },
              {
                path: 'bulk-product-editor',
                title: this.$t('error.bulkProduct'),
                icon: 'products',
                gate: 'product_bulk_update',
              }
            ]
          },
          {
            path: 'procurement',
            title: this.$t('procurement.title'),
            icon: 'shopping-cart',
            open: false,
            children: [
              {
                path: 'procurement/suppliers',
                title: this.$t('procurement.suppliers'),
                icon: 'users',
                gate: 'supplier'
              }
            ]
          },
          {
            path: "templates",
            title: this.$t("index.templates"),
            icon: "ui"
         },
         {
            path: "crosssell",
            title: this.$t("index.crosssell"),
            icon: "ui",
            form: ["flash-sales"],
            open: !1
         },
         {
            path: "upsell",
            title: this.$t("index.upsell"),
            icon: "ui",
            form: ["crosssell-flash-sales"],
            open: !1
         },
          {
            path: 'flash-sales',
            title: this.$t('error.fs'),
            icon: 'flash-sales',
            form: ['flash-sales'],
            gate: 'flash_sale'
          },
          {
            path: 'pos',
            title: this.$t('ship.pos'),
            icon: 'pos',
            open: false,
            gate: 'pos',
            badge: !!process?.env?.isDemo,
            children: [
              {
                path: 'pos/manager',
                title: this.$t('ship.pm'),
                icon: 'pos-manager',
                gate: 'pos'
              },
              {
                path: 'pos/order',
                title: this.$t('ship.po'),
                icon: 'orders',
                gate: 'pos'
              },
              {
                path: 'pos/configuration',
                title: this.$t('ship.pc'),
                icon: 'setting',
                gate: 'pos_setting'
              }
            ]
          },
          {
            path: 'orders',
            title: this.$t('error.orders'),
            icon: 'orders',
            form: ['order'],
            gate: 'order'
          },
          {
            path: 'rating-reviews',
            title: this.$t('error.rr'),
            icon: 'rating-reviews',
            form: ['rating-reviews'],
            gate: 'rating_review'
          },

          {
            path: 'users',
            title: this.$t('error.users'),
            icon: 'users',
            open: false,
            gate: 'user',
            children: [
              {
                path: 'registered-users',
                title: this.$t('profile.registered'),
                icon: 'registered',
                gate: 'user'
              },
              {
                path: 'guest-users',
                title: this.$t('profile.guest'),
                icon: 'guest',
                gate: 'user'
              }
            ]
          },


         /* {
            path: 'users',
            title: this.$t('error.users'),
            icon: 'users',
            form: ['user'],
            gate: 'user'
          },*/
          {
            path: 'subscription',
            title: this.$t('error.subs'),
            icon: 'subscription',
            open: false,
            children: [
              {
                path: 'subscribers',
                title: this.$t('error.subCrbs'),
                icon: 'subscribers',
                form: ['subscriber'],
                gate: 'subscriber'
              },
              {
                path: 'subscription-email-formats',
                title: this.$t('error.ef'),
                icon: 'subscription-email-formats',
                form: ['subscription-email-format'],
                gate: 'subscription_email_format'
              },
            ]
          },
          {
            path: 'bulk-upload',
            title: this.$t('title.bu'),
            icon: 'bulk-upload',
            form: ['bulk-upload'],
            gate: 'bulk_upload'
          },
          {
            path: 'roles-permissions',
            title: this.$t('error.rp'),
            icon: 'roles-permissions',
            form: ['roles-permissions'],
            gate: 'role'
          },
          {
            path: 'admins-vendors',
            title: this.$t('error.av'),
            icon: 'admins-vendors',
            form: ['admins-vendors'],
            gate: 'admin'
          },
          {
            path: 'withdrawal',
            title: this.$t('error.wth'),
            icon: 'withdrawal',
            open: false,
            children: [
              {
                path: 'withdrawal-requests',
                title: this.$t('error.req'),
                icon: 'withdrawal-requests',
                gate: 'withdrawal_request'
              },
              {
                path: 'withdrawal-accounts',
                title: this.$t('error.acc'),
                icon: 'withdrawal-accounts',
                gate: 'withdrawal_account'
              }
            ]
          },
          {
            path: 'ui',
            title: 'UI',
            icon: 'ui',
            open: false,
            children: [
              {
                path: 'pages',
                title: this.$t('error.pages'),
                icon: 'pages',
                form: ['customPage'],
                gate: 'page'
              },
              {
                path: 'home-slider',
                title: this.$t('profile.hSlid'),
                icon: 'home-slider',
                gate: 'home_slider'
              },
              {
                path: 'banners',
                title: this.$t('admin.banners'),
                icon: 'banners',
                gate: 'banner'
              },
              {
                path: 'footer-links',
                title: this.$t('error.fl'),
                icon: 'footer',
                gate: 'footer_link'
              },
              {
                path: 'header-links',
                title: this.$t('dataPage.hl'),
                icon: 'header-links',
                gate: 'header_link'
              },
              {
                path: 'site-features',
                title: this.$t('title.sf'),
                icon: 'site-features',
                gate: 'home_slider'
              },
              {
                path: 'site-setting',
                title: this.$t('admin.site'),
                icon: 'site-setting',
                gate: 'site_setting'
              },
              {
                path: 'custom-scripts',
                title: this.$t('title.cs'),
                icon: 'custom-scripts',
                gate: 'site_setting'
              },
            ]
          },
          {
            path: 'store',
            title: this.$t('error.store'),
            icon: 'store',
            gate: 'store'
          },
          {
            path: 'setting/currency',
            active: 'setting',
            title: this.$t('list.set'),
            icon: 'setting',
            gate: 'setting'
          }
        ],
        childrenOpened: false
      }
    },
    mixins: [util],
    directives: {outsideClick},
    components: {},
    computed: {
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
      ...mapGetters('ui', ['sidebarOpen'])
    },
    watch: {
      sidebarOpen() {
        this.closeChild()
      }
    },
    methods: {
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
      isCurrentRoute(val) {
        let check = val.path
        if(val?.active){
          check = val?.active
        }
        return `${this.$route.path}/`.includes(`/${check}/`)
      },
      closeChild() {
        const findI = this.sidebarsArr.findIndex((o) => {
          return o.open === true
        })

        if (findI > -1) {
          this.sidebarsArr[findI].open = this.childrenOpened = false
        }
      },
      clicked(value) {
        if (value.children) {
          if (!this.sidebarOpen) {
            const findI = this.sidebarsArr.findIndex((o) => {
              return o.open === true
            })
            if (findI > -1 && this.sidebarsArr[findI].path !== value.path) {
              this.sidebarsArr[findI].open = this.childrenOpened = false
            }
          }
          value.open = !value.open
          this.childrenOpened = !this.sidebarOpen && value.open
        } else {
          if (!this.sidebarOpen) {
            this.closeChild()
          }

          this.reRenderActiveChild()
          if (window.innerWidth < 992) {
            this.hideSidebar()
          }
        }
      },
      reRenderActiveChild() {
        const sidbearI = this.sidebarsArr.findIndex((o) => {
          return o.childActive === true
        })
        if (sidbearI > -1) {
          this.sidebarsArr.splice(sidbearI, 1, {...this.sidebarsArr[sidbearI], ...{childActive: false}})
        }
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
      },
      ...mapActions('ui', ['toggleSidebar', 'hideSidebar'])
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


    }
  }
</script>

<style lang="stylus">
  @import "~/assets/styles/sidebar_bk.styl"
</style>
