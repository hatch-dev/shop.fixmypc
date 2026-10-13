<template>
  <nav class="m-tabs" aria-label="Primary">
    <nuxt-link to="/" class="m-tab" :class="{ on: isHome }">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 10.5 12 3l9 7.5" />
        <path d="M5.5 9.5V20a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V9.5" />
      </svg>
      <span>{{ $t('tabs.home') }}</span>
    </nuxt-link>

    <nuxt-link to="/categories" class="m-tab" :class="{ on: isShop }">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3" y="3" width="7" height="7" rx="1.8" /><rect x="14" y="3" width="7" height="7" rx="1.8" />
        <rect x="3" y="14" width="7" height="7" rx="1.8" /><rect x="14" y="14" width="7" height="7" rx="1.8" />
      </svg>
      <span>{{ $t('tabs.shop') }}</span>
    </nuxt-link>

    <button type="button" class="m-tab" @click.prevent="$emit('open-search')">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
      </svg>
      <span>{{ $t('tabs.search') }}</span>
    </button>

    <nuxt-link to="/cart" class="m-tab" :class="{ on: isCart }">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 7h16l-1.4 11.2a2 2 0 0 1-2 1.8H7.4a2 2 0 0 1-2-1.8Z" />
        <path d="M9 7V5.5a3 3 0 0 1 6 0V7" />
      </svg>
      <span>{{ $t('tabs.basket') }}</span>
      <span v-if="cartCount" class="n">{{ cartCount }}</span>
    </nuxt-link>

    <nuxt-link :to="accountLink" class="m-tab" :class="{ on: isAccount }">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="8" r="3.4" /><path d="M5 20a7 7 0 0 1 14 0" />
      </svg>
      <span>{{ accountLabel }}</span>
    </nuxt-link>
  </nav>
</template>

<script>
  import { mapGetters } from 'vuex'

  export default {
    name: 'MobileTabBar',
    computed: {
      isHome() {
        return this.$route.path === '/' || /^\/(en|fr|ar|tr|hi)$/.test(this.$route.path)
      },
      isShop() {
        return this.$route.path.startsWith('/categories') || this.$route.path.startsWith('/all/')
      },
      isCart() {
        return this.$route.path.startsWith('/cart')
      },
      isAccount() {
        return this.$route.path.startsWith('/user') || this.$route.path.startsWith('/login')
      },
      accountLink() {
        return this.$auth?.loggedIn ? '/user/dashboard' : '/login'
      },
      /*
       * The shopper's first name when signed in, so it is obvious whose
       * basket and account this phone is showing; "Account" otherwise.
       */
      accountLabel() {
        const first = this.$auth?.loggedIn ? this.$auth?.user?.name?.split(' ')[0] : null

        return first || this.$t('tabs.account')
      },
      ...mapGetters('cart', ['cartCount']),
    },
  }
</script>

<style scoped>
/* Bottom navigation, phone and small tablet only. The desktop header keeps
   every one of these destinations, so this is an extra route to them - not a
   replacement for anything. */
.m-tabs {
  align-items: stretch;
  background: rgba(255, 255, 255, .98);
  -webkit-backdrop-filter: blur(12px);
  backdrop-filter: blur(12px);
  border-top: 1px solid #E8E9F5;
  bottom: 0;
  display: none;
  left: 0;
  padding: 6px 4px calc(6px + env(safe-area-inset-bottom));
  position: fixed;
  right: 0;
  z-index: 45;
}

.m-tab {
  align-items: center;
  background: none;
  border: 0;
  color: #6B7191;
  display: flex;
  flex: 1;
  flex-direction: column;
  font-size: 10.5px;
  font-weight: 700;
  gap: 3px;
  height: auto;
  justify-content: center;
  line-height: 1.2;
  padding: 4px 2px;
  position: relative;
  text-align: center;
  text-decoration: none;
}

.m-tab.on {
  color: #33319A;
}

.m-tab span:not(.n) {
  display: block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.m-tab .n {
  align-items: center;
  background: #05B942;
  border-radius: 999px;
  color: #fff;
  display: flex;
  font-size: 9px;
  font-weight: 800;
  height: 15px;
  justify-content: center;
  min-width: 15px;
  padding: 0 3px;
  position: absolute;
  right: 22%;
  top: -2px;
}

@media (max-width: 991px) {
  .m-tabs {
    display: flex;
  }
}
</style>
