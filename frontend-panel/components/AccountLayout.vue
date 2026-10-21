<template>
  <client-only>
    <div class="container-fluid mtb-20 mtb-sm-15 account-section">

      <!-- Mobile menu trigger -->
      <button
        v-if="isLoggedIn"
        ref="drawerToggle"
        type="button"
        class="account-menu-toggle"
        aria-controls="account-drawer"
        :aria-expanded="drawerOpen ? 'true' : 'false'"
        @click="drawerOpen = true"
      >
        <span><i class="fa-solid fa-bars"></i> {{ $t('ui.accountLayout.accountMenu') }}</span>
        <i class="fa-solid fa-chevron-right"></i>
      </button>

      <div class="row g-4">
        <!-- Sidebar (becomes a right drawer on mobile) -->
        <div
          v-if="isLoggedIn"
          id="account-drawer"
          class="col-lg-3 col-md-4 account-sidebar-col"
          :class="{ open: drawerOpen }"
        >
          <div class="account-sidebar">
            <button
              ref="drawerClose"
              type="button"
              class="account-drawer-close"
              :aria-label="$t('ui.accountLayout.closeMenu')"
              @click="drawerOpen = false"
            >
              <i class="fa-solid fa-xmark"></i>
            </button>

            <!--
              This block used to fall straight through to "Guest User" and
              "guest@example.com" whenever the profile had not arrived yet, so
              a signed-in customer watched their own sidebar introduce them as
              a guest and then correct itself a moment later. Nobody who gets
              this far is a guest: the sidebar only renders when isLoggedIn.

              It now shimmers until a name exists, and user() reads the auth
              user as well, which is usually present immediately and closes the
              gap without any shimmer at all.
            -->
            <div class="account-user text-center">
              <template v-if="profileReady">
                <img :src="userImage" class="account-avatar" :alt="user.name">
                <h6 class="account-name">{{ user.name }}</h6>
                <p class="account-email">{{ user.email }}</p>
              </template>

              <template v-else>
                <span class="account-sk account-sk-avatar" aria-hidden="true"></span>
                <span class="account-sk account-sk-name" aria-hidden="true"></span>
                <span class="account-sk account-sk-email" aria-hidden="true"></span>
                <span class="sr-only">{{ $t('ui.accountLayout.loading') }}</span>
              </template>
            </div>

            <nav class="account-menu">
              <nuxt-link
                v-for="item in menu"
                :key="item.route"
                :to="item.link"
                class="account-menu-item"
                :class="{ active: activeRoute === item.route }"
                @click.native="onMenuClick(item.link)"
              >
                <span class="account-menu-label">
                  <i :class="item.icon"></i>
                  {{ item.label }}
                </span>
                <span
                  v-if="item.route === 'orders' && ordersCount"
                  class="account-menu-badge"
                >
                  {{ ordersCount }}
                </span>
              </nuxt-link>

              <div class="account-menu-divider"></div>

              <a href="#" class="account-menu-item account-logout" @click.prevent="logout">
                <span class="account-menu-label">
                  <i class="fa-solid fa-right-from-bracket"></i>
                  {{ $t('ui.accountLayout.logout') }}
                </span>
              </a>
            </nav>
          </div>
        </div>

        <!-- Drawer backdrop (mobile) -->
        <div
          v-if="isLoggedIn"
          class="account-drawer-backdrop"
          :class="{ show: drawerOpen }"
          @click="drawerOpen = false"
        ></div>

        <!-- Main Content -->
        <div class="account-content-col" :class="isLoggedIn ? 'col-lg-9 col-md-8' : 'col-12'">
          <slot name="rightArea" />
        </div>
      </div>
    </div>
  </client-only>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  export default {
    name: 'AccountLayout',
    data() {
      return {
        drawerOpen: false,
        menu: [
          { label: this.$t('ui.accountLayout.menu.dashboard'), route: 'dashboard', link: '/user/dashboard', icon: 'fa-solid fa-table-columns' },
          { label: this.$t('ui.accountLayout.menu.profile'), route: 'profile', link: '/user/profile', icon: 'fa-solid fa-user' },
          { label: this.$t('ui.accountLayout.menu.addressBook'), route: 'addresses', link: '/user/addresses', icon: 'fa-solid fa-address-book' },
          { label: this.$t('ui.accountLayout.menu.orders'), route: 'orders', link: '/user/orders', icon: 'fa-solid fa-bag-shopping' },
          { label: this.$t('ui.accountLayout.menu.wishlist'), route: 'wishlists', link: '/user/wishlists', icon: 'fa-solid fa-heart' },
          { label: this.$t('ui.accountLayout.menu.recentlyViewed'), route: 'recently-viewed', link: '/user/recently-viewed', icon: 'fa-solid fa-clock-rotate-left' },
          { label: this.$t('ui.accountLayout.menu.vouchers'), route: 'vouchers', link: '/user/vouchers', icon: 'fa-solid fa-ticket' },
          { label: this.$t('ui.accountLayout.menu.saversClub'), route: 'savers-club', link: '/user/savers-club', icon: 'fa-solid fa-piggy-bank' },
        ]
      }
    },
    mixins: [util],
    props: {
      activeRoute:{
        type: String
      }
    },
    computed: {
      ...mapGetters('user', ['profile']),

      // A guest reaches this layout through the wishlist, which no longer
      // requires signing in. The account navigation has nothing to offer them.
      isLoggedIn() {
        return !!this.$auth?.loggedIn
      },

      /*
       * The store profile when it is there, otherwise the auth user, which is
       * populated as soon as the session is restored. Falling back to {} was
       * what produced the "Guest User" flash.
       */
      user() {
        return this.profile || this.$auth?.user || {}
      },

      // A name is what the sidebar actually displays, so that is what it waits for.
      profileReady() {
        return !!this.user?.name
      },
      ordersCount() {
        return Number(this.profile?.orders_count || 0)
      },
      userImage() {
        if (this.user?.image) {

          const path = this.user.image
          if ( path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
            return path
          }
          return this.getImageURL(this.user.image)
        }

        /*
         * background=random keyed off nothing meant the same customer got a
         * different colour on each page load. Seeded from the name, so their
         * avatar is at least stable.
         */
        return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(this.user.name || '')
          + '&background=33319A&color=fff&size=150'
      }
    },
    watch: {
      /*
       * While the drawer is open the page behind it must not scroll (it
       * did, under the backdrop), Esc closes it, and focus goes into it and
       * back to the "Account Menu" button when it closes.
       */
      drawerOpen(open) {
        if (!process.client) return

        document.documentElement.style.overflow = open ? 'hidden' : ''

        if (open) {
          document.addEventListener('keydown', this.onDrawerKey)
          this.$nextTick(() => this.$refs.drawerClose?.focus({preventScroll: true}))
        } else {
          document.removeEventListener('keydown', this.onDrawerKey)
          this.$refs.drawerToggle?.focus({preventScroll: true})
        }
      },
      // Any navigation, including tapping the page already open.
      '$route.fullPath'() {
        this.drawerOpen = false
      }
    },
    mounted() {
    },
    beforeDestroy() {
      if (process.client) {
        document.documentElement.style.overflow = ''
        document.removeEventListener('keydown', this.onDrawerKey)
      }
    },
    methods: {
      onDrawerKey(e) {
        if (e.key === 'Escape') this.drawerOpen = false
      },
      goingNext(url){
        const clicked = url.split('/')
        this.$emit(`clicked-${clicked[clicked.length-1]}`)
      },
      onMenuClick(url){
        this.drawerOpen = false
        this.goingNext(url)
      },
      ...mapActions('user', ['logout'])
    },
  }
</script>

<style scoped>
.account-section {
  font-family: 'Inter', 'Noto Sans', sans-serif;
  position: relative;
}

/* ---- Sidebar card ---- */
.account-sidebar {
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 16px;
  padding: 24px 20px;
  position: relative;
}

/* ---- Mobile toggle button (hidden on desktop) ---- */
.account-menu-toggle {
  display: none;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border: 1px solid #E3E3EF;
  border-radius: 12px;
  padding: 12px 16px;
  margin-bottom: 16px;
  font-size: 15px;
  font-weight: 600;
  color: #130E2B;
  cursor: pointer;
}
.account-menu-toggle i { color: #33319A; }

/* Close (X) inside drawer — hidden on desktop */
.account-drawer-close {
  display: none;
  position: absolute;
  top: 12px;
  right: 12px;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: none;
  background: #F4F4FA;
  color: #130E2B;
  font-size: 16px;
  line-height: 1;
  padding: 0;
  cursor: pointer;
  z-index: 2;
}

.account-drawer-close:hover {
  background: #E9E9F5 !important;
}

/* Backdrop */
.account-drawer-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(19, 14, 43, .45);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity .25s ease, visibility .25s ease;
  z-index: 1049;
}

.account-user {
  padding-bottom: 20px;
}

.account-avatar {
  width: 104px;
  height: 104px;
  border-radius: 50%;
  object-fit: cover;
  margin: 0 auto 14px;
  display: block;
}

.account-name {
  color: #130E2B;
  font-weight: 600;
  font-size: 20px;
  margin: 0 0 4px;
}

.account-email {
  font-size: 14px;
  font-weight: 400;
  color: #6B7280;
  margin: 0;
  word-break: break-word;
}

/* ---- Profile placeholder ---- */

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/*
 * Sized to match the real avatar, name and email exactly, so the sidebar does
 * not resize under the cursor when the profile lands.
 */
.account-sk {
  display: block;
  border-radius: 6px;
  background: linear-gradient(90deg, #EEEFF5 25%, #F7F8FC 37%, #EEEFF5 63%);
  background-size: 400% 100%;
  animation: account-sk-shimmer 1.4s ease infinite;
}

.account-sk-avatar {
  width: 104px;
  height: 104px;
  border-radius: 50%;
  margin: 0 auto 14px;
}

.account-sk-name {
  height: 20px;
  width: 60%;
  margin: 0 auto 8px;
}

.account-sk-email {
  height: 14px;
  width: 80%;
  margin: 0 auto;
}

@keyframes account-sk-shimmer {
  0%   { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}

@media (prefers-reduced-motion: reduce) {
  .account-sk { animation: none; }
}

/* ---- Menu ---- */
.account-menu {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.account-menu-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 14px;
  border-radius: 10px;
  color: #4B5563;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: background .2s, color .2s;
}

.account-menu-label {
  display: flex;
  align-items: center;
  gap: 12px;
}

.account-menu-label i {
  width: 18px;
  text-align: center;
  font-size: 15px;
  color: inherit;
}

.account-menu-item:hover {
  background: #F4F4FA;
  color: #33319A;
}

.account-menu-item.active {
  background: #33319A;
  color: #fff;
  font-weight: 600;
}

.account-menu-item.active:hover {
  background: #2b2986;
  color: #fff;
}

.account-menu-badge {
  background: #EEF0F6;
  color: #6B7280;
  font-size: 12px;
  font-weight: 600;
  min-width: 22px;
  height: 22px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 7px;
}

.account-menu-item.active .account-menu-badge {
  background: rgba(255, 255, 255, .22);
  color: #fff;
}

.account-menu-divider {
  height: 1px;
  background: #ECECF3;
  margin: 8px 4px;
}

.account-logout {
  color: #E5484D;
}

.account-logout:hover {
  background: #FDECEC;
  color: #E5484D;
}

/* ---- Mobile: sidebar becomes a right-side drawer ---- */
@media (max-width: 991.98px) {
  .account-menu-toggle {
    display: flex;
  }

  .account-drawer-close {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /*
   * margin-top: 0 matters. Both of these are children of .row.g-4, and
   * Bootstrap gives every .row > * a margin-top of the gutter (24px), which
   * pushed the fixed drawer and its backdrop 24px down and left the promo
   * banner showing, undimmed, above them.
   */
  .account-sidebar-col {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    margin: 0 !important;
    width: min(340px, 88vw);
    max-width: none;
    height: 100%;
    z-index: 1050;
    padding: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    background: #fff;
    border-radius: 20px 0 0 20px;
    box-shadow: -12px 0 40px rgba(19, 14, 43, .22);
    transform: translateX(100%);
    visibility: hidden;
    transition: transform .3s cubic-bezier(.22, .61, .36, 1), visibility 0s linear .3s;
  }

  .account-sidebar-col.open {
    visibility: visible;
    transition: transform .3s cubic-bezier(.22, .61, .36, 1), visibility 0s;
  }

  .account-drawer-backdrop {
    margin: 0 !important;
  }

  .account-sidebar-col.open {
    transform: translateX(0);
  }

  /*
   * The sidebar has left the row to become a drawer, but col-md-8 still
   * held the content to two thirds, so 768-991px tablets showed a third of
   * the screen empty on every account page.
   */
  .account-content-col {
    flex: 0 0 100%;
    max-width: 100%;
  }

  .account-sidebar {
    border: none;
    border-radius: 0;
    padding: 20px 14px calc(16px + env(safe-area-inset-bottom));
    min-height: 100%;
    display: flex;
    flex-direction: column;
  }

  .account-drawer-close {
    top: 18px;
    right: 14px;
    width: 36px;
    height: 36px;
  }

  /*
   * A compact header: avatar beside the name rather than a 104px avatar
   * above it, which took a third of the drawer and pushed the menu off a
   * short phone.
   */
  .account-user {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr);
    grid-template-rows: auto auto;
    column-gap: 12px;
    align-items: center;
    /* the element carries Bootstrap .text-center, which is !important */
    text-align: left !important;
    padding: 2px 48px 16px 4px;
    margin-bottom: 10px;
    border-bottom: 1px solid #ECECF3;
  }

  .account-avatar,
  .account-sk-avatar {
    grid-row: 1 / span 2;
    width: 52px;
    height: 52px;
    margin: 0;
  }

  .account-name {
    align-self: end;
    font-size: 16px;
    margin: 0 0 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .account-email {
    align-self: start;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: normal;
  }

  .account-sk-name { width: 70%; height: 16px; margin: 0 0 6px; align-self: end; }
  .account-sk-email { width: 90%; height: 12px; margin: 0; align-self: start; }

  .account-menu { flex: 1 1 auto; gap: 2px; }

  /* 48px rows: a comfortable thumb target */
  .account-menu-item {
    min-height: 48px;
    padding: 0 14px;
    font-size: 15px;
    border-radius: 12px;
  }

  .account-menu-label { gap: 14px; }
  .account-menu-label i { font-size: 16px; width: 20px; }

  .account-menu-divider { margin: 10px 4px; }

  .account-drawer-backdrop {
    display: block;
  }
  .account-drawer-backdrop.show {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
  }
}
</style>
