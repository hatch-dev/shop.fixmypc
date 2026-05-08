<template>
  <header :class="{ 'no-banner': (topBannerLoaded && isTopBannerClosed) || !isPublic }">
    <banner v-if="!isTopBannerClosed" class="top-banner" :banner="topBanner" @close="topBannerClosed" />

    <div class="header">
      <div class="container-fluid mobile-menu-flex-set">
        <div class="row align-items-center">
          <div class="col-lg-2 col-6">
            <div class="logo">
              <nuxt-link to="/" class="logo">
                <img :src="imageURL({ 'image': site_setting.header_logo })" :alt="$t('footer.siteLogo')">
              </nuxt-link>
            </div>
          </div>
          <div class="col-lg-10 col-6 d-flex justify-content-end d-lg-none">
            <button class="toggle-btn" @click="mobileMenu = !mobileMenu">
              <i :class="mobileMenu ? 'fa fa-times' : 'fa fa-bars'"></i>
            </button>
          </div>
          <div class="col-lg-10 d-none d-lg-block">
            <div class="header-right">
              <div class="d-flex align-items-center gap-3 mb-2">
                <select class="header-serach">
                  <option>All Categories</option>
                  <option v-for="cat in categories" :key="cat.id" :value="cat.slug">
                    {{ cat.title }}
                  </option>
                </select>
                <form class="search-wrap" @submit.prevent="search">
                  <input @focus="openSearchPopup" @blur="blurSearchInput" type="text" placeholder="Search here..."
                    v-model="searchedText">
                  <button aria-label="submit" type="submit" class="search-btn">
                    <i class="icon search-icon" />
                  </button>
                  <search-popup v-if="searchPopup" :searched-text="searchedText" @close="closeSearchPopup" />
                </form>
                <div class="lang-dropdown">
                  <select @change="changeLang($event)">
                    <option v-for="lang in Object.values(languages)" :key="lang.code" :value="lang.code"
                      :selected="lang.code === currentLanguage.code">
                      {{ lang.name }}
                    </option>
                  </select>
                </div>
                <nuxt-link to="/cart" class="icon-box">
                  <i class="fa-solid fa-cart-shopping"></i>
                  <span v-if="cartCount" class="cart-badge">
                    {{ cartCount }}
                  </span>
                </nuxt-link>
                <nuxt-link to="/user/wishlists" class="icon-box">
                  <i class="fa-regular fa-heart"></i>
                </nuxt-link>
                <div class="icon-box user-menu" @click.stop="toggleUserMenu" v-outside-click="closeUserMenu">
                  <i class="fa-regular fa-circle-user"></i>
                  <div class="dropdown-menu" v-show="userMenuOpen">
                    <!-- NOT LOGGED IN -->
                    <template v-if="!isLoggedIn">
                      <nuxt-link v-if="sellerSignUp" to="/seller/register">
                        {{ $t('date.bav') }}
                      </nuxt-link>
                      <nuxt-link to="/login">
                        {{ $t('header.login') }}
                      </nuxt-link>
                      <nuxt-link to="/register">
                        {{ $t('header.register') }}
                      </nuxt-link>
                      <div class="divider"></div>
                      <nuxt-link to="/track-order">
                        Track Order
                      </nuxt-link>

                      <nuxt-link to="/page/faq">
                        FAQ
                      </nuxt-link>

                      <nuxt-link to="/page/help">
                        Help
                      </nuxt-link>

                      <nuxt-link to="/page/contact">
                        Contact Us
                      </nuxt-link>
                    </template>
                    <!-- LOGGED IN -->
                    <template v-else>

                      <nuxt-link to="/user/profile">
                        {{ $t('header.profile') }}
                      </nuxt-link>

                      <nuxt-link to="/user/orders">
                        {{ $t('header.orders') }}
                      </nuxt-link>

                      <nuxt-link to="/user/wishlists">
                        {{ $t('header.wishList') }}
                      </nuxt-link>

                      <nuxt-link to="/user/compared">
                        {{ $t('header.comparedList') }}
                      </nuxt-link>

                      <nuxt-link to="/user/vouchers">
                        {{ $t('header.vouchers') }}
                      </nuxt-link>

                      <button class="clear-btn" @click="loggingOut">
                        {{ $t('header.logout') }}
                      </button>

                    </template>
                  </div>
                </div>
              </div>
              <div class="d-flex justify-content-end gap-3 flex-wrap">
                <div class="nav-links">
                  <nuxt-link v-for="cat in (categories || []).slice(0, 6)" :key="cat.id" :to="`/all/${cat.slug}`">
                    {{ cat.title }}
                  </nuxt-link>
                  <span class="badge-hot text-center"><i class="fa-solid fa-fire"></i> Hot Deals</span>
                  <span class="badge-green"><i class="fa-solid fa-bag-shopping"></i><span
                      class="nw_img">Clearance</span></span>
                </div>
                <div class="small-info">
                  <a :href="`mailto:${email}`" class="contact-link">
                    <i class="fa-regular fa-envelope"></i>
                    <span class="contact">{{ email }}</span>
                  </a>

                  &nbsp; | &nbsp;

                  <a :href="`tel:${phone}`" class="contact-link">
                    <i class="fa-solid fa-phone"></i>
                    <span class="contact">{{ phone }}</span>
                  </a>
                </div>
              </div>
            </div>
          </div>

        </div>
        <div v-if="mobileMenu" class="d-lg-none mobile-menu-cs">
          <select class="header-serach mt-3 mb-2">
            <option>All Categories</option>
            <option v-for="cat in categories" :key="cat.id" :value="cat.slug">
              {{ cat.title }}
            </option>
          </select>
          <form class="search-wrap mb-2" @submit.prevent="search">
            <input @focus="openSearchPopup" @blur="blurSearchInput" type="text" placeholder="Search here..."
              v-model="searchedText">
            <button aria-label="submit" type="submit" class="search-btn">
              <i class="icon search-icon" />
            </button>
            <search-popup v-if="searchPopup" :searched-text="searchedText" @close="closeSearchPopup" />
          </form>
          <div class="lang-dropdown mb-2">
            <select @change="changeLang($event)">
              <option v-for="lang in Object.values(languages)" :key="lang.code" :value="lang.code"
                :selected="lang.code === currentLanguage.code">
                {{ lang.name }}
              </option>
            </select>
          </div>
          <div class="d-flex gap-3 mb-2">
            <nuxt-link to="/cart" class="icon-box">
              <i class="fa-solid fa-cart-shopping"></i>
              <span v-if="cartCount" class="cart-badge">
                {{ cartCount }}
              </span>
            </nuxt-link>
            <nuxt-link to="/user/wishlists" class="icon-box">
              <i class="fa-regular fa-heart"></i>
            </nuxt-link>
            <div class="icon-box user-menu" @click.stop="toggleUserMenu" v-outside-click="closeUserMenu">
              <i class="fa-regular fa-circle-user"></i>
              <div class="dropdown-menu" v-show="userMenuOpen">
                <!-- NOT LOGGED IN -->
                <template v-if="!isLoggedIn">
                  <nuxt-link v-if="sellerSignUp" to="/seller/register">
                    {{ $t('date.bav') }}
                  </nuxt-link>
                  <nuxt-link to="/login">
                    {{ $t('header.login') }}
                  </nuxt-link>
                  <nuxt-link to="/register">
                    {{ $t('header.register') }}
                  </nuxt-link>
                  <div class="divider"></div>
                  <nuxt-link to="/track-order">
                    Track Order
                  </nuxt-link>

                  <nuxt-link to="/page/faq">
                    FAQ
                  </nuxt-link>

                  <nuxt-link to="/page/help">
                    Help
                  </nuxt-link>

                  <nuxt-link to="/page/contact">
                    Contact Us
                  </nuxt-link>
                </template>
                <!-- LOGGED IN -->
                <template v-else>

                  <nuxt-link to="/user/profile">
                    {{ $t('header.profile') }}
                  </nuxt-link>

                  <nuxt-link to="/user/orders">
                    {{ $t('header.orders') }}
                  </nuxt-link>

                  <nuxt-link to="/user/wishlists">
                    {{ $t('header.wishList') }}
                  </nuxt-link>

                  <nuxt-link to="/user/compared">
                    {{ $t('header.comparedList') }}
                  </nuxt-link>

                  <nuxt-link to="/user/vouchers">
                    {{ $t('header.vouchers') }}
                  </nuxt-link>

                  <button class="clear-btn" @click="loggingOut">
                    {{ $t('header.logout') }}
                  </button>

                </template>
              </div>
            </div>
          </div>
          <div>
            <div class="nav-links">
              <nuxt-link v-for="cat in (categories || []).slice(0, 6)" :key="cat.id" :to="`/all/${cat.slug}`">
                {{ cat.title }}
              </nuxt-link>
              <span class="badge-hot text-center"><i class="fa-solid fa-fire me-2"></i> Hot Deals</span>
              <span class="badge-green"><i class="fa-solid fa-bag-shopping me-2"></i><span
                  class="nw_img">Clearance</span></span>
            </div>
            <div class="small-info mt-4">
              <a :href="`mailto:${email}`" class="contact-link">
                <i class="fa-regular fa-envelope"></i>
                <span class="contact">{{ email }}</span>
              </a>

              &nbsp; | &nbsp;

              <a :href="`tel:${phone}`" class="contact-link">
                <i class="fa-solid fa-phone"></i>
                <span class="contact">{{ phone }}</span>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
<script>
import outsideClick from '~/directives/outside-click'
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'
import SearchPopup from "../../components/SearchPopup";
import Banner from "../../components/Banner";
import Dropdown from "../../components/Dropdown";

export default {
  data() {
  
    return {
      mobileMenu: false,
      userMenuOpen: false,
      headerSticky: false,
      topBannerLoaded: false,
      isTopBannerClosed: true,
      dropdown: false,
      searchPopup: false,
      searchFocused: false,
      searchedText: ''
    }
  },
  computed: {
    isXSmallerDevice() {
      return window.innerWidth <= 576
    },
    headerLeft() {
      return this.headerLinks?.left || []
    },
    headerRight() {
      return this.headerLinks?.right || []
    },
    isPublic() {
      return parseInt(this.topBanner?.status) === this.status.PUBLIC
    },
    isLoggedIn() {
      return this.$auth?.loggedIn || false
    },
    cartCountCom() {
      return this.$auth?.user?.cart_count
    },
    username() {
      return this.$auth?.user?.name?.split(' ')[0]
    },
    email() {
      return this.setting?.email
    },
    phone() {
      return this.setting?.phone
    },
    sellerSignUp() {
      return this.setting?.vendor_registration
    },
    ...mapGetters('home', ['categories', 'featured_categories']),
    ...mapGetters('language', ['languages', 'currentLanguage']),
    ...mapGetters('common', ['site_setting', 'setting', 'topBanner', 'headerLinks']),
    ...mapGetters('listing', ['searched']),
    ...mapGetters('cart', ['cartCount'])
  },
  watch: {
    cartCountCom(value) {
      this.setCartCount(value)
    },
    '$route'() {
      this.setQFromRoute()
      this.closeDropdown()
      document.body.classList.remove('mobile-menu-open')
      this.mobileMenu = false
    },
    searchedText() {
      if (!this.searchPopup && this.searchFocused) {
        this.emptySearchedSuggestion()
        this.openSearchPopup()
      }
    },
    mobileMenu(val) {
      if (process.client) {
        if (val) {
          document.body.classList.add('mobile-menu-open')
        } else {
          document.body.classList.remove('mobile-menu-open')
        }
      }
    }

  },
  directives: { outsideClick },
  components: { Dropdown, Banner, SearchPopup },
  mixins: [util],
  methods: {
    toggleUserMenu() {
      this.userMenuOpen = !this.userMenuOpen
    },
    closeUserMenu() {
      this.userMenuOpen = false
    },
    changeLang(e) {
      this.selectedLanguage({ key: e.target.value })
    },
    handleIntersection(entries) {
      entries.forEach((entry) => {
        this.headerSticky = !entry.isIntersecting
      });
    },
    async selectedLanguage(data) {
      const d = new Date()
      d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000))
      document.cookie = `currentLanguage=${data.key}; path=/; expires=${d.toUTCString()}`
      location.reload()
    },
    topBannerClosed() {
      localStorage.setItem('topBannerClosed', true)
      this.isTopBannerClosed = true
    },
    openSearchPopup() {
      if (this.searchedText.length > 0) {
        this.searchPopup = true
      }
      this.searchFocused = true
    },
    blurSearchInput() {
      this.searchFocused = false
      this.closeSearchPopup()
    },
    closeSearchPopup() {
      setTimeout(() => {
        this.searchPopup = false
      }, 100)
    },
    setQFromRoute() {
      this.searchedText = this.$route?.query?.q || ''
    },
    search() {
      if (this.searchedText && (this.searchedText !== this.searched || this.$route.name !== 'search')) {
        this.$router.push({ path: '/search', query: { q: this.searchedText } })
        this.updateSearch(this.searchedText)
      }
    },
    async loggingOut() {
      try {
        await this.$auth.logout()
        this.closeDropdown()
        //this.emptyCartProduct()
      } catch (e) {
        return this.$nuxt.error(e)
      }
    },
    closeDropdown() {
      this.dropdown = false
    },
    ...mapActions('language', ['setDefaultLanguage', 'getLangData']),
    ...mapActions('cart', ['emptyCartProduct', 'setCartCount']),
    ...mapActions('listing', ['updateSearch', 'emptySearchedSuggestion']),
  },
  deactivated() {
  },
  activated() {

  },
  mounted() {

    this.setQFromRoute()
    this.updateSearch(this.searchedText)
    if (this.cartCountCom) {
      this.setCartCount(this.cartCountCom)
    }

    const self = this
    this.$nextTick(() => {
      if (localStorage.getItem('topBannerClosed') !== null) {
        self.isTopBannerClosed = localStorage.getItem('topBannerClosed')
        self.topBannerLoaded = true
      } else {
        self.isTopBannerClosed = false
        self.topBannerLoaded = true
      }
    })


    let rootMargin = '0px 0px 0px 0px'
    if (this.isXSmallerDevice) {
      rootMargin = '40px 0px 0px 0px'
    }


    this.observer = new IntersectionObserver(this.handleIntersection, {
      root: null, // Use the viewport as the root
      rootMargin: rootMargin,
      threshold: 0, // Trigger when 50% of the target is visible
    });

    // Start observing the target element
    if (this.$refs.headerWrapper) {
      this.observer.observe(this.$refs.headerWrapper);
    }
  }
  
}

</script>
<style scoped>
.contact-link {
  text-decoration: none;
  color: inherit;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.contact-link:hover {
  color: #5a4bff;
}

.user-menu {
  position: relative;
  cursor: pointer;
  display: flex;
  align-items: center;
}

.user-menu:hover .dropdown-menu {
  display: block;
}

.dropdown-menu {
  display: none;
  position: absolute;
  top: 100%;
  right: 0;
  margin-top: 0;
  background: #fff;
  border-radius: 8px;
  padding: 10px;
  min-width: 150px;
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
  z-index: 999;
}

.dropdown-menu a {
  display: block;
  padding: 8px 10px;
  text-decoration: none;
  color: #333;
  font-size: 14px;
}

.dropdown-menu a:hover {
  background: #f5f5f5;
}

.lang-dropdown select {
  border: 1px solid #e3e3ef;
  border-radius: 50px;
  padding: 9px 16px;
}

.toggle-btn {
  background: none;
  border: none;
  font-size: 22px;
}

select.header-serach {
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;

  padding: 9px 50px 9px 16px;
  border-radius: 50px;

  background: url("data:image/svg+xml;utf8,<svg fill='%23333' height='16' viewBox='0 0 20 20' width='16' xmlns='http://www.w3.org/2000/svg'><path d='M5 7l5 5 5-5z'/></svg>") no-repeat right 18px center;

  background-size: 14px;
}

select.header-serach:focus {
  outline: none !important;
  box-shadow: none !important;
}

.header {
  background: #fff;
  padding: 10px 0;
  position: relative;
}

.logo img {
  height: 65px;
}

/* Right Section */
.header-right {
  display: flex;
  flex-direction: column;
  align-items: center;
}

/* Search */
.search-wrap {
  background: #fff;
  border-radius: 50px;
  padding: 5px 10px;
  display: flex;
  align-items: center;
  width: 650px;
  border: 1px solid #E3E3EF;

}

.search-wrap select,
.search-wrap input {
  border: none;
  outline: none;
  font-size: 14px;
}

.search-wrap input {
  flex: 1;
}

.search-btn {
  background: #5a4bff;
  border: none;
  border-radius: 50%;
  color: #fff;
  height: 34px;
  width: 36px;
  padding: 6px;
}

/* Icons */
.icon-box {
  width: 38px;
  height: 38px;
  background: #e9e9f3;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0px 15px;
}

/* Nav */
.nav-links a {
  margin-right: 15px;
  text-decoration: none;
  color: #333;
  font-size: 14px;
}

.badge-hot {
  background: #33319A;
  color: #fff;
  padding: 5px 15px;
  border-radius: 100px;
  font-size: 14px;
  font-weight: 500;
}

.badge-green {
  background: #05B942;
  color: #fff;
  padding: 5px 15px;
  border-radius: 100px;
  font-size: 14px;
  font-weight: 500;
}

.small-info {
  font-size: 13px;
  color: #666;
  margin: 5px 0px;
}

select.header-serach {
  border: 1px solid #E3E3EF;
  border-radius: 50px;
  padding: 9px 16px;
  /* margin-left: -124px; */
  width: 39%;
  color: #75748F;
}

.nav-links a {
  color: #130E2B;
  font-weight: 500;
  font-size: 14px;
}

span.contact {
  color: #130E2B;
  font-size: 12px;
  font-weight: 500;
}

span.nw_img {
  margin-left: 5px;
}

.lang-list {
  white-space: nowrap;
  color: #130E2B;
  font-size: 16px;
  font-weight: 500;
  cursor: pointer;
}

.lang-list:hover {
  color: #33319A;
}

/* MOBILE + TABLET MENU */
@media (max-width: 991px) {
  #mobileMenu {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    width: 100%;
    background: #fff;
    padding: 15px;
    z-index: 999;
    border-top: 1px solid #ddd;
  }

  #mobileMenu.active {
    display: block;
  }

  .search-wrap {
    width: auto;
  }

  select.header-serach {
    width: 100%;
  }

  /* ✅ ADD THIS PART ONLY */
  .nav-links {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .nav-links a {
    display: block;
    width: 100%;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
  }

  .badge-hot,
  .badge-green {
    display: inline-block;
    margin-top: 5px;
    text-align: center;
    padding: 10px 0px;
  }

}
</style>
