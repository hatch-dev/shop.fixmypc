<template>
  <header :class="{ 'no-banner': (topBannerLoaded && isTopBannerClosed) || !isPublic }">
    <!-- <banner v-if="!isTopBannerClosed" class="top-banner" :banner="topBanner" @close="topBannerClosed" /> -->
    <TopPromoBanner />
    <div class="header">
      <div class="container-fluid">
        <div class="row align-items-center">
          <div class="col-lg-2 col-4">
            <div class="logo">
              <nuxt-link to="/" class="logo" :title="$t('footer.siteLogo')">
                <img :src="imageURL({ 'image': site_setting.header_logo })" :alt="$t('footer.siteLogo')">
                <span class="logo-text">FixMyPC<br>Shop</span>
              </nuxt-link>
            </div>
          </div>
          <div class="col-8 d-flex justify-content-end align-items-center gap-2 d-lg-none">
            <button
              type="button"
              class="m-menu-btn"
              :aria-label="$t('header.search')"
              @click.prevent="openMobileSearch"
            >
              <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
              </svg>
            </button>
            <nuxt-link to="/cart" class="m-menu-btn m-cart-btn" :aria-label="$t('header.cart')">
              <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 7h16l-1.4 11.2a2 2 0 0 1-2 1.8H7.4a2 2 0 0 1-2-1.8Z" />
                <path d="M9 7V5.5a3 3 0 0 1 6 0V7" />
              </svg>
              <span v-if="cartCount > 0" class="m-badge">{{ cartCount }}</span>
            </nuxt-link>
            <button
              type="button"
              class="m-menu-btn"
              :aria-label="$t('searchPopup.categories')"
              :aria-expanded="mobileMenu ? 'true' : 'false'"
              @click="mobileMenu = !mobileMenu"
            >
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16" />
              </svg>
            </button>
          </div>
          <div class="col-lg-10 d-none d-lg-block">
            <div class="header-right">
              <div class="d-flex align-items-center gap-3 mb-2 fx-head-top">
                <form class="search-wrap fx-header-search-wrap" @submit.prevent="search">
                  <div class="fx-scope">
                    <button
                      type="button"
                      class="fx-search-scope"
                      :aria-label="$t('searchOverlay.within')"
                      :aria-expanded="scopeMenu ? 'true' : 'false'"
                      @click.prevent.stop="scopeMenu = !scopeMenu"
                    >
                      <span class="fx-scope-label">{{ scopeLabel }}</span>
                      <svg width="11" height="11" viewBox="0 0 12 7" fill="none" stroke="#6B7191" stroke-width="1.6"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M.75.75 5.75 5.75 10.75.75" />
                      </svg>
                    </button>

                    <div v-if="scopeMenu" class="fx-scope-menu" @click.stop>
                      <button
                        type="button"
                        class="fx-scope-row"
                        :class="{ on: !selectedCategory }"
                        @click.prevent="pickScope('')"
                      >
                        <span class="fx-scope-ico">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1.8" /><rect x="14" y="3" width="7" height="7" rx="1.8" />
                            <rect x="3" y="14" width="7" height="7" rx="1.8" /><rect x="14" y="14" width="7" height="7" rx="1.8" />
                          </svg>
                        </span>
                        {{ $t('listingLayout.allProducts') }}
                        <svg v-if="!selectedCategory" class="fx-scope-tick" width="16" height="16" viewBox="0 0 24 24"
                          fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                          <path d="m5 12.5 4.5 4.5L19 7.5" />
                        </svg>
                      </button>

                      <button
                        v-for="cat in categories"
                        :key="cat.id"
                        type="button"
                        class="fx-scope-row"
                        :class="{ on: selectedCategory === cat.slug }"
                        @click.prevent="pickScope(cat.slug)"
                      >
                        <span class="fx-scope-ico">
                          <img :src="getImageURL(cat.image)" :alt="cat.title" width="16" height="16" loading="lazy">
                        </span>
                        {{ cat.title }}
                        <svg v-if="selectedCategory === cat.slug" class="fx-scope-tick" width="16" height="16"
                          viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                          stroke-linejoin="round">
                          <path d="m5 12.5 4.5 4.5L19 7.5" />
                        </svg>
                        <small v-else>{{ cat.products_count || 0 }}</small>
                      </button>
                    </div>
                  </div>
                  <input ref="desktopSearchInput" @focus="openSearchPopup"  @blur="blurSearchInput"  type="text" placeholder="Search parts, devices and repairs…"
                    v-model="searchedText">
                  <button aria-label="submit" type="submit" class="search-btn fx-header-search">
                    <svg width="17" height="17" viewBox="0 0 17 17" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path
                        d="M15.75 15.75L12.125 12.125M14.0833 7.41667C14.0833 11.0986 11.0986 14.0833 7.41667 14.0833C3.73477 14.0833 0.75 11.0986 0.75 7.41667C0.75 3.73477 3.73477 0.75 7.41667 0.75C11.0986 0.75 14.0833 3.73477 14.0833 7.41667Z"
                        stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </button>
                  <search-popup v-if="searchPopup" :searched-text="searchedText" @close="closeSearchPopup" />
                </form>
                <div
                  class="icon-box fx-header-icon-item lang-menu"
                  role="button"
                  tabindex="0"
                  :aria-label="currentLanguage.name"
                  :aria-expanded="langMenuOpen ? 'true' : 'false'"
                  @click.stop="toggleLangMenu"
                  @keydown.enter.prevent="toggleLangMenu"
                >
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#130E2B" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9.25" />
                    <path d="M2.75 12h18.5" />
                    <path d="M12 2.75c2.4 2.55 3.75 5.84 3.75 9.25S14.4 18.7 12 21.25c-2.4-2.55-3.75-5.84-3.75-9.25S9.6 5.3 12 2.75Z" />
                    <path d="M4.9 6.6c2 1.15 4.48 1.78 7.1 1.78s5.1-.63 7.1-1.78M4.9 17.4c2-1.15 4.48-1.78 7.1-1.78s5.1.63 7.1 1.78" />
                  </svg>
                  <div class="dropdown-menu" :style="{ display: langMenuOpen ? 'block' : 'none' }">
                    <button
                      v-for="lang in Object.values(languages)"
                      :key="lang.code"
                      type="button"
                      class="lang-option"
                      :class="{ active: lang.code === currentLanguage.code }"
                      @click.stop="selectLang(lang)"
                    >
                      {{ lang.name }}
                    </button>
                  </div>
                </div>
                <nuxt-link to="/cart" class="icon-box fx-header-icon-item">
                  <svg width="26" height="26" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path
                      d="M2.16675 2.16663H4.05175C5.22175 2.16663 6.14258 3.17413 6.04508 4.33329L5.14591 15.1233C4.99425 16.8891 6.39174 18.4058 8.16841 18.4058H19.7059C21.2659 18.4058 22.6309 17.1275 22.7501 15.5783L23.3351 7.4533C23.4651 5.65496 22.1001 4.19245 20.2909 4.19245H6.30509"
                      stroke="#130E2B" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round"
                      stroke-linejoin="round" />
                    <path
                      d="M17.6042 23.8333C18.3521 23.8333 18.9583 23.2271 18.9583 22.4792C18.9583 21.7313 18.3521 21.125 17.6042 21.125C16.8563 21.125 16.25 21.7313 16.25 22.4792C16.25 23.2271 16.8563 23.8333 17.6042 23.8333Z"
                      stroke="#130E2B" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round"
                      stroke-linejoin="round" />
                    <path
                      d="M8.93742 23.8333C9.6853 23.8333 10.2916 23.2271 10.2916 22.4792C10.2916 21.7313 9.6853 21.125 8.93742 21.125C8.18953 21.125 7.58325 21.7313 7.58325 22.4792C7.58325 23.2271 8.18953 23.8333 8.93742 23.8333Z"
                      stroke="#130E2B" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round"
                      stroke-linejoin="round" />
                    <path d="M9.75 8.66663H22.75" stroke="#130E2B" stroke-width="1.5" stroke-miterlimit="10"
                      stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <span v-if="cartCount > 0" class="cart-badge">
                    {{ cartCount }}
                  </span>
                </nuxt-link>
                <nuxt-link to="/user/wishlists" class="icon-box fx-header-icon-item">
                  <svg width="24" height="22" viewBox="0 0 24 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path
                      d="M17.625 0.75C20.7637 0.75 23.2499 3.41448 23.25 7.17969C23.25 9.16883 22.491 10.871 21.0576 12.667C19.6053 14.4867 17.5128 16.3408 14.9082 18.6455C14.0321 19.4208 13.0358 20.3017 12 21.2422C10.9644 20.302 9.96829 19.4212 9.09277 18.6465C6.48788 16.3415 4.39482 14.4868 2.94238 12.667C1.50893 10.871 0.75 9.16882 0.75 7.17969C0.750087 3.41448 3.23629 0.75 6.375 0.75C7.49992 0.75 8.5296 1.116 9.4541 1.8584C10.3537 2.5808 10.9679 3.51557 11.3369 4.21582L12 5.47461L12.6631 4.21582C13.0321 3.51559 13.6463 2.58079 14.5459 1.8584C15.4704 1.116 16.5001 0.75 17.625 0.75Z"
                      stroke="#130E2B" stroke-width="1.5" />
                  </svg>
                </nuxt-link>
                <!--
                  Signed in, the icon carries the shopper's first name. It
                  looked the same either way, so a browser still signed in
                  from weeks ago showed that account's basket to someone who
                  believed they were browsing as a guest.
                -->
                <div
                  class="icon-box user-menu fx-header-icon-item"
                  :class="{ 'is-signed-in': isLoggedIn }"
                  :title="signedInTitle"
                  @click.stop="toggleUserMenu"
                >
                  <svg width="26" height="26" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path
                      d="M13 2.375C16.1756 2.375 18.75 4.94936 18.75 8.125C18.75 11.3006 16.1756 13.875 13 13.875C9.82436 13.875 7.25 11.3006 7.25 8.125C7.25 4.94936 9.82436 2.375 13 2.375Z"
                      stroke="#130E2B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    <path
                      d="M9.75 13.75H16.25C20.3231 13.75 23.625 17.0519 23.625 21.125C23.625 22.5057 22.5057 23.625 21.125 23.625H4.875C3.49429 23.625 2.375 22.5057 2.375 21.125C2.375 17.0519 5.6769 13.75 9.75 13.75Z"
                      stroke="#130E2B" stroke-width="1.5" />
                  </svg>
                  <span v-if="isLoggedIn" class="signed-in-name">{{ username ? $t('header.hi', { name: username }) : $t('header.account') }}</span>
                  <div class="dropdown-menu" :style="{ display: userMenuOpen ? 'block' : 'none' }">
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
                      <div class="signed-in-as">
                        {{ $t('header.signedInAs') }}
                        <strong>{{ accountEmail || greetingName }}</strong>
                      </div>
                      <div class="divider"></div>

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

                      <button type="button" class="clear-btn dropdown-logout" @click="loggingOut">
                        {{ $t('header.logout') }}
                      </button>

                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
         </div>
          <div 
           class="mobile-menu-cs-overly"
           :class="{ active: mobileMenu }"
          >
           <div
          class="d-lg-none mobile-menu-cs"
          :class="{ active: mobileMenu }"
          >
          <div class="mobile-menu-header">
            <nuxt-link to="/" class="m-drawer-brand" @click.native="mobileMenu = false">
              <img
                :src="imageURL({ image: site_setting.header_logo })"
                :alt="$t('footer.siteLogo')"
              >
              <span class="logo-text">FixMyPC<br>Shop</span>
            </nuxt-link>
            <div class="m-close-and-lang">
                <button
                    class="mobile-close"
                    @click="mobileMenu = false"
                  >
               <i class="fa fa-times"></i>
              </button>
            </div>
        </div>
         <div class="mobile-menu-item-scroll">
            <div class="nav-links">
              <div class="dr-cap">{{ $t('header.hotDeals') }}</div>
              <div class="dr-deals">
                <button type="button" class="dr-deal dr-deal-hot" @click.prevent="navigateBadge('hot')">
                  <i class="fa-solid fa-fire" aria-hidden="true"></i>
                  <span>{{ $t('header.hotDeals') }}</span>
                </button>
                <button type="button" class="dr-deal dr-deal-clear" @click.prevent="navigateBadge('clearance')">
                  <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                  <span>{{ $t('product.clearance') }}</span>
                </button>
              </div>

              <div class="dr-cap">{{ $t('searchPopup.categories') }}</div>

              <div class="dr-list">
                <div v-for="cat in categories" :key="cat.id" class="dr-item">
                  <div class="dr-row" :class="{ open: drawerCat === cat.slug }">
                    <nuxt-link class="dr-row-link" :to="`/all/${cat.slug}`" @click.native="mobileMenu = false">
                      <span class="dr-ico">
                        <img :src="getImageURL(cat.image)" :alt="cat.title" width="18" height="18" loading="lazy">
                      </span>
                      {{ cat.title }}
                    </nuxt-link>
                    <span class="dr-n">{{ cat.products_count || 0 }}</span>
                    <span class="dr-cv-slot">
                    <button
                      v-if="cat.child && cat.child.length"
                      type="button"
                      class="dr-cv"
                      :aria-label="cat.title"
                      :aria-expanded="drawerCat === cat.slug ? 'true' : 'false'"
                      @click.prevent="toggleDrawerCat(cat)"
                    >
                      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                      </svg>
                    </button>
                    </span>
                  </div>

                  <div class="dr-kids" :class="{ on: drawerCat === cat.slug }" v-if="cat.child && cat.child.length">
                    <nuxt-link
                      v-for="kid in cat.child"
                      :key="kid.id"
                      class="dr-kid"
                      :to="`/all/${kid.slug}`"
                      @click.native="mobileMenu = false"
                    >
                      {{ kid.title }}
                      <span class="dr-n">{{ kid.products_count || 0 }}</span>
                    </nuxt-link>
                    <nuxt-link class="dr-kid dr-kid-all" :to="`/all/${cat.slug}`" @click.native="mobileMenu = false">
                      {{ $t('listingLayout.allProducts') }}
                    </nuxt-link>
                  </div>
                </div>
              </div>

              <div class="dr-cap">{{ $t('header.help') }}</div>
              <div class="dr-list">
                <nuxt-link class="dr-link" to="/page/faq" @click.native="mobileMenu = false">
                  <span class="dr-ico"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
                  {{ $t('header.faq') }}
                  <svg class="dr-go" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 6 6 6-6 6" />
                  </svg>
                </nuxt-link>
                <nuxt-link class="dr-link" to="/page/help" @click.native="mobileMenu = false">
                  <span class="dr-ico"><i class="fa-solid fa-life-ring" aria-hidden="true"></i></span>
                  {{ $t('header.help') }}
                  <svg class="dr-go" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 6 6 6-6 6" />
                  </svg>
                </nuxt-link>
                <nuxt-link class="dr-link" to="/page/contact" @click.native="mobileMenu = false">
                  <span class="dr-ico"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
                  {{ $t('header.contact') }}
                  <svg class="dr-go" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 6 6 6-6 6" />
                  </svg>
                </nuxt-link>
              </div>
            </div>
            <div class="small-info mt-4">
              <a :href="`mailto:${email}`" class="contact-link">
                <svg width="15" height="13" viewBox="0 0 15 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path
                    d="M10.5 11.8333H3.83333C1.83333 11.8333 0.5 10.8333 0.5 8.5V3.83333C0.5 1.5 1.83333 0.5 3.83333 0.5H10.5C12.5 0.5 13.8333 1.5 13.8333 3.83333V8.5C13.8333 10.8333 12.5 11.8333 10.5 11.8333Z"
                    stroke="#130E2B" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round" />
                  <path
                    d="M10.5002 4.16663L8.41349 5.83329C7.72683 6.37996 6.60016 6.37996 5.91349 5.83329L3.8335 4.16663"
                    stroke="#130E2B" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span class="contact">{{ email }}</span>
              </a>

            <span class="small-info-separate-line">&nbsp; | &nbsp;</span>

              <a :href="`tel:${phone}`" class="contact-link">
                <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path
                    d="M13.8133 11.3867C13.8133 11.6267 13.76 11.8733 13.6467 12.1133C13.5333 12.3533 13.3867 12.58 13.1933 12.7933C12.8667 13.1533 12.5067 13.4133 12.1 13.58C11.7 13.7467 11.2667 13.8333 10.8 13.8333C10.12 13.8333 9.39333 13.6733 8.62667 13.3467C7.86 13.02 7.09333 12.58 6.33333 12.0267C5.56667 11.4667 4.84 10.8467 4.14667 10.16C3.46 9.46667 2.84 8.74 2.28667 7.98C1.74 7.22 1.3 6.46 0.98 5.70667C0.66 4.94667 0.5 4.22 0.5 3.52667C0.5 3.07333 0.58 2.64 0.74 2.24C0.9 1.83333 1.15333 1.46 1.50667 1.12667C1.93333 0.706667 2.4 0.5 2.89333 0.5C3.08 0.5 3.26667 0.54 3.43333 0.62C3.60667 0.7 3.76 0.82 3.88 0.993333L5.42667 3.17333C5.54667 3.34 5.63333 3.49333 5.69333 3.64C5.75333 3.78 5.78667 3.92 5.78667 4.04667C5.78667 4.20667 5.74 4.36667 5.64667 4.52C5.56 4.67333 5.43333 4.83333 5.27333 4.99333L4.76667 5.52C4.69333 5.59333 4.66 5.68 4.66 5.78667C4.66 5.84 4.66667 5.88667 4.68 5.94C4.7 5.99333 4.72 6.03333 4.73333 6.07333C4.85333 6.29333 5.06 6.58 5.35333 6.92667C5.65333 7.27333 5.97333 7.62667 6.32 7.98C6.68 8.33333 7.02667 8.66 7.38 8.96C7.72667 9.25333 8.01333 9.45333 8.24 9.57333C8.27333 9.58667 8.31333 9.60667 8.36 9.62667C8.41333 9.64667 8.46667 9.65333 8.52667 9.65333C8.64 9.65333 8.72667 9.61333 8.8 9.54L9.30667 9.04C9.47333 8.87333 9.63333 8.74667 9.78667 8.66667C9.94 8.57333 10.0933 8.52667 10.26 8.52667C10.3867 8.52667 10.52 8.55333 10.6667 8.61333C10.8133 8.67333 10.9667 8.76 11.1333 8.87333L13.34 10.44C13.5133 10.56 13.6333 10.7 13.7067 10.8667C13.7733 11.0333 13.8133 11.2 13.8133 11.3867Z"
                    stroke="#130E2B" stroke-miterlimit="10" />
                </svg>
                <span class="contact">{{ phone }}</span>
              </a>

             <span class="small-info-separate-line">&nbsp; | &nbsp;</span>

              <a :href="`https://wa.me/${whatsapp}`" target="_blank" class="contact-link">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path
                    d="M20.52 3.48A11.79 11.79 0 0 0 12.05 0C5.5 0 .18 5.32.18 11.87c0 2.09.55 4.14 1.59 5.94L0 24l6.38-1.67a11.8 11.8 0 0 0 5.67 1.45h.01c6.55 0 11.87-5.32 11.87-11.87 0-3.17-1.24-6.15-3.41-8.43ZM12.06 21.7h-.01a9.8 9.8 0 0 1-5-1.37l-.36-.21-3.79.99 1.01-3.69-.23-.38a9.78 9.78 0 0 1-1.5-5.18c0-5.41 4.4-9.81 9.82-9.81 2.62 0 5.08 1.02 6.93 2.88a9.75 9.75 0 0 1 2.88 6.93c0 5.41-4.4 9.81-9.81 9.81Zm5.39-7.36c-.29-.14-1.72-.85-1.99-.95-.27-.1-.46-.14-.66.14-.19.29-.75.95-.91 1.15-.17.19-.34.22-.63.07-.29-.14-1.2-.44-2.28-1.39-.84-.75-1.4-1.67-1.56-1.95-.17-.29-.02-.44.12-.58.13-.13.29-.34.44-.51.14-.17.19-.29.29-.48.1-.19.05-.36-.02-.51-.07-.14-.66-1.58-.9-2.17-.24-.58-.48-.5-.66-.51h-.56c-.19 0-.51.07-.78.36-.27.29-1.02 1-1.02 2.43 0 1.43 1.05 2.81 1.19 3 .14.19 2.05 3.13 4.96 4.39.69.29 1.23.46 1.65.59.69.22 1.32.19 1.81.12.55-.08 1.72-.7 1.96-1.37.24-.67.24-1.24.17-1.37-.07-.12-.26-.19-.55-.34Z"
                    fill="#25D366" />
                </svg>

                <span class="contact">{{ whatsapp }}</span>
              </a>

              <span class="small-info-separate-line">&nbsp; | &nbsp;</span>

              <button
                type="button"
                class="contact-link m-lang-btn"
                :aria-label="$t('header.language')"
                :aria-expanded="langSheet ? 'true' : 'false'"
                @click.prevent="langSheet = true"
              >
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#130E2B" stroke-width="1.4"
                  stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="9.25" />
                  <path d="M2.75 12h18.5" />
                  <path d="M12 2.75c2.4 2.55 3.75 5.84 3.75 9.25S14.4 18.7 12 21.25c-2.4-2.55-3.75-5.84-3.75-9.25S9.6 5.3 12 2.75Z" />
                  <path d="M4.9 6.6c2 1.15 4.48 1.78 7.1 1.78s5.1-.63 7.1-1.78M4.9 17.4c2-1.15 4.48-1.78 7.1-1.78s5.1.63 7.1 1.78" />
                </svg>
              </button>
            </div>
          </div>

          <transition name="m-fade">
            <div v-if="langSheet" class="m-sheet-scrim" @click="langSheet = false" />
          </transition>

          <transition name="m-sheet">
            <div v-if="langSheet" class="m-sheet" role="dialog" aria-modal="true">
              <div class="m-sheet-h">
                <span class="m-sheet-grab" aria-hidden="true" />
                <b>{{ $t('header.language') }}</b>
                <button type="button" class="m-ov-clear" :aria-label="$t('listingLayout.close')" @click.prevent="langSheet = false">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                    stroke-linecap="round">
                    <path d="M6 6l12 12M18 6L6 18" />
                  </svg>
                </button>
              </div>

              <div class="m-sheet-b">
                <button
                  v-for="lang in Object.values(languages)"
                  :key="lang.code"
                  type="button"
                  class="m-scope-row"
                  :class="{ on: lang.code === currentLanguage.code }"
                  @click.prevent="selectLang(lang)"
                >
                  {{ lang.name }}
                  <svg v-if="lang.code === currentLanguage.code" class="m-tick" width="16" height="16"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="m5 12.5 4.5 4.5L19 7.5" />
                  </svg>
                </button>
              </div>
            </div>
          </transition>
        </div>
          </div>
       </div>
    <div class="fx-nav d-none d-lg-block">
      <div class="container-fluid fx-nav-row">
                <div class="nav-links nav-links--row">
                  <category-nav :categories="categories" :mega-slug="megaSlug" :limit="navTabLimit" :more-label="$t('featured.showAll')" class="nav-cat-carousel" @toggle-mega="toggleMega" />
                  <span class="badge-hot text-center" @click="navigateBadge('hot')">
                    <svg width="14" height="19" viewBox="0 0 14 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path
                        d="M0.761419 12.0462C0.761419 15.2712 3.36975 17.8796 6.59475 17.8796C9.81975 17.8796 12.4281 15.2712 12.4281 12.0462C12.4281 9.64622 11.9614 8.37122 11.9614 8.37122L10.3698 10.0629C10.3698 10.0629 11.2531 6.49622 9.05309 3.83789C7.78642 2.30456 6.38642 1.21289 6.38642 1.21289C6.38642 1.21289 5.61142 3.22122 4.72809 4.70456C4.01142 5.89622 3.59475 6.75456 3.31975 8.54622C2.81975 8.01289 2.41975 7.27956 2.06975 6.45456C2.06975 6.45456 0.561419 8.97122 0.769753 12.0379L0.761419 12.0462Z"
                        stroke="white" stroke-width="1.5" />
                    </svg>
                    Hot Deals
                  </span>
                  <span class="badge-green" @click="navigateBadge('clearance')">
                    <svg width="16" height="19" viewBox="0 0 16 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path
                        d="M3.44167 15.5C4.125 14.7667 5.16667 14.825 5.76667 15.625L6.60833 16.75C7.28333 17.6417 8.375 17.6417 9.05 16.75L9.89167 15.625C10.4917 14.825 11.5333 14.7667 12.2167 15.5C13.7 17.0833 14.9083 16.5583 14.9083 14.3417V4.95C14.9167 1.59167 14.1333 0.75 10.9833 0.75H4.68333C1.53333 0.75 0.75 1.59167 0.75 4.95V14.3333C0.75 16.5583 1.96667 17.075 3.44167 15.5Z"
                        stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M4.5 4.91675H11.1667" stroke="white" stroke-width="1.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                      <path d="M5.33325 8.25H10.3333" stroke="white" stroke-width="1.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                    </svg>
                    <span class="nw_img">Clearance</span>
                  </span>
                </div>
                <div class="small-info">
                  <a :href="`mailto:${email}`" class="contact-link">
                    <svg width="15" height="13" viewBox="0 0 15 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path
                        d="M10.5 11.8333H3.83333C1.83333 11.8333 0.5 10.8333 0.5 8.5V3.83333C0.5 1.5 1.83333 0.5 3.83333 0.5H10.5C12.5 0.5 13.8333 1.5 13.8333 3.83333V8.5C13.8333 10.8333 12.5 11.8333 10.5 11.8333Z"
                        stroke="#130E2B" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round" />
                      <path
                        d="M10.5002 4.16663L8.41349 5.83329C7.72683 6.37996 6.60016 6.37996 5.91349 5.83329L3.8335 4.16663"
                        stroke="#130E2B" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="contact">{{ email }}</span>
                  </a>

                 <span class="small-info-separate-line">&nbsp; | &nbsp;</span>

                  <a :href="`tel:${phone}`" class="contact-link">
                    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path
                        d="M13.8133 11.3867C13.8133 11.6267 13.76 11.8733 13.6467 12.1133C13.5333 12.3533 13.3867 12.58 13.1933 12.7933C12.8667 13.1533 12.5067 13.4133 12.1 13.58C11.7 13.7467 11.2667 13.8333 10.8 13.8333C10.12 13.8333 9.39333 13.6733 8.62667 13.3467C7.86 13.02 7.09333 12.58 6.33333 12.0267C5.56667 11.4667 4.84 10.8467 4.14667 10.16C3.46 9.46667 2.84 8.74 2.28667 7.98C1.74 7.22 1.3 6.46 0.98 5.70667C0.66 4.94667 0.5 4.22 0.5 3.52667C0.5 3.07333 0.58 2.64 0.74 2.24C0.9 1.83333 1.15333 1.46 1.50667 1.12667C1.93333 0.706667 2.4 0.5 2.89333 0.5C3.08 0.5 3.26667 0.54 3.43333 0.62C3.60667 0.7 3.76 0.82 3.88 0.993333L5.42667 3.17333C5.54667 3.34 5.63333 3.49333 5.69333 3.64C5.75333 3.78 5.78667 3.92 5.78667 4.04667C5.78667 4.20667 5.74 4.36667 5.64667 4.52C5.56 4.67333 5.43333 4.83333 5.27333 4.99333L4.76667 5.52C4.69333 5.59333 4.66 5.68 4.66 5.78667C4.66 5.84 4.66667 5.88667 4.68 5.94C4.7 5.99333 4.72 6.03333 4.73333 6.07333C4.85333 6.29333 5.06 6.58 5.35333 6.92667C5.65333 7.27333 5.97333 7.62667 6.32 7.98C6.68 8.33333 7.02667 8.66 7.38 8.96C7.72667 9.25333 8.01333 9.45333 8.24 9.57333C8.27333 9.58667 8.31333 9.60667 8.36 9.62667C8.41333 9.64667 8.46667 9.65333 8.52667 9.65333C8.64 9.65333 8.72667 9.61333 8.8 9.54L9.30667 9.04C9.47333 8.87333 9.63333 8.74667 9.78667 8.66667C9.94 8.57333 10.0933 8.52667 10.26 8.52667C10.3867 8.52667 10.52 8.55333 10.6667 8.61333C10.8133 8.67333 10.9667 8.76 11.1333 8.87333L13.34 10.44C13.5133 10.56 13.6333 10.7 13.7067 10.8667C13.7733 11.0333 13.8133 11.2 13.8133 11.3867Z"
                        stroke="#130E2B" stroke-miterlimit="10" />
                    </svg>
                    <span class="contact">{{ phone }}</span>
                  </a>

                  <span class="small-info-separate-line">&nbsp; | &nbsp;</span>

                  <a :href="`https://wa.me/${whatsapp}`" target="_blank" class="contact-link">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path
                        d="M20.52 3.48A11.79 11.79 0 0 0 12.05 0C5.5 0 .18 5.32.18 11.87c0 2.09.55 4.14 1.59 5.94L0 24l6.38-1.67a11.8 11.8 0 0 0 5.67 1.45h.01c6.55 0 11.87-5.32 11.87-11.87 0-3.17-1.24-6.15-3.41-8.43ZM12.06 21.7h-.01a9.8 9.8 0 0 1-5-1.37l-.36-.21-3.79.99 1.01-3.69-.23-.38a9.78 9.78 0 0 1-1.5-5.18c0-5.41 4.4-9.81 9.82-9.81 2.62 0 5.08 1.02 6.93 2.88a9.75 9.75 0 0 1 2.88 6.93c0 5.41-4.4 9.81-9.81 9.81Zm5.39-7.36c-.29-.14-1.72-.85-1.99-.95-.27-.1-.46-.14-.66.14-.19.29-.75.95-.91 1.15-.17.19-.34.22-.63.07-.29-.14-1.2-.44-2.28-1.39-.84-.75-1.4-1.67-1.56-1.95-.17-.29-.02-.44.12-.58.13-.13.29-.34.44-.51.14-.17.19-.29.29-.48.1-.19.05-.36-.02-.51-.07-.14-.66-1.58-.9-2.17-.24-.58-.48-.5-.66-.51h-.56c-.19 0-.51.07-.78.36-.27.29-1.02 1-1.02 2.43 0 1.43 1.05 2.81 1.19 3 .14.19 2.05 3.13 4.96 4.39.69.29 1.23.46 1.65.59.69.22 1.32.19 1.81.12.55-.08 1.72-.7 1.96-1.37.24-.67.24-1.24.17-1.37-.07-.12-.26-.19-.55-.34Z"
                        fill="#25D366" />
                    </svg>

                    <span class="contact">{{ whatsapp }}</span>
                  </a>
                </div>
      </div>

      <div class="fx-mega" v-if="megaCategory" @click.stop>
        <div class="container-fluid fx-mega-in" :class="{ 'is-more': isMoreMega }">
          <div class="fx-mega-cols">
            <h4>{{ megaCategory.title }}</h4>
            <div class="fx-mega-list">
              <nuxt-link
                v-if="!isMoreMega"
                class="mega-link"
                :to="`/all/${megaCategory.slug}`"
                @click.native="closeMega"
              >
                <span class="nm">All {{ (megaCategory.title || '').toLowerCase() }}</span>
                <span class="n">{{ megaCategory.products_count || 0 }}</span>
              </nuxt-link>
              <nuxt-link
                v-for="kid in (megaCategory.child || [])"
                :key="kid.id"
                class="mega-link"
                :to="`/all/${kid.slug}`"
                :title="kid.title"
                @click.native="closeMega"
              >
                <span class="nm">{{ kid.title }}</span>
                <span class="n">{{ kid.products_count || 0 }}</span>
              </nuxt-link>
            </div>
          </div>

          <div class="mega-card" v-if="!isMoreMega">
            <div class="mega-card-head">
              <span class="mega-card-thumb" v-if="megaCategory.image">
                <img :src="getImageURL(megaCategory.image)" :alt="megaCategory.title" width="28" height="28">
              </span>
              <div>
                <b>{{ megaCategory.title }}</b>
                <span class="mega-card-count">
                  {{ megaCategory.products_count || 0 }} products
                </span>
              </div>
            </div>


            <nuxt-link class="mega-cta" :to="`/all/${megaCategory.slug}`" @click.native="closeMega">
              {{ $t('listingLayout.allProducts') }}
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M13 6l6 6-6 6" />
              </svg>
            </nuxt-link>
          </div>
        </div>
      </div>
    </div>
    </div>

    <transition name="m-ov">
      <div v-if="mobileSearch" class="m-overlay" role="dialog" aria-modal="true">
        <div class="m-ov-head">
          <button type="button" class="m-ov-back" :aria-label="$t('listingLayout.close')" @click.prevent="closeMobileSearch">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="m15 18-6-6 6-6" />
            </svg>
          </button>

          <form class="m-ov-field" @submit.prevent="submitMobileSearch">
            <button
              type="button"
              class="m-ov-scope"
              :aria-label="$t('searchOverlay.within')"
              :aria-expanded="scopeSheet ? 'true' : 'false'"
              @click.prevent="scopeSheet = true"
            >
              <span class="m-ov-scope-label">{{ scopeLabel }}</span>
              <svg width="10" height="10" viewBox="0 0 12 7" fill="none" stroke="#6B7191" stroke-width="1.6"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M.75.75 5.75 5.75 10.75.75" />
              </svg>
            </button>
            <input
              ref="mobileSearchInput"
              type="text"
              v-model="searchedText"
              :placeholder="$t('header.searchHere')"
              :aria-label="$t('header.search')"
            >
            <button
              v-if="searchedText"
              type="button"
              class="m-ov-clear"
              :aria-label="$t('listingLayout.clear')"
              @click.prevent="searchedText = ''"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                stroke-linecap="round">
                <path d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
          </form>
        </div>

        <transition name="m-fade">
          <div v-if="scopeSheet" class="m-sheet-scrim" @click="scopeSheet = false" />
        </transition>

        <transition name="m-sheet">
          <div v-if="scopeSheet" class="m-sheet" role="dialog" aria-modal="true">
            <div class="m-sheet-h">
              <span class="m-sheet-grab" aria-hidden="true" />
              <b>{{ $t('searchOverlay.within') }}</b>
              <button type="button" class="m-ov-clear" :aria-label="$t('listingLayout.close')" @click.prevent="scopeSheet = false">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                  stroke-linecap="round">
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </button>
            </div>

            <div class="m-sheet-b">
              <button
                type="button"
                class="m-scope-row"
                :class="{ on: !selectedCategory }"
                @click.prevent="pickScope('')"
              >
                <span class="m-sug-ico">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1.8" /><rect x="14" y="3" width="7" height="7" rx="1.8" />
                    <rect x="3" y="14" width="7" height="7" rx="1.8" /><rect x="14" y="14" width="7" height="7" rx="1.8" />
                  </svg>
                </span>
                {{ $t('listingLayout.allProducts') }}
                <svg v-if="!selectedCategory" class="m-tick" width="17" height="17" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                  <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
              </button>

              <button
                v-for="cat in categories"
                :key="cat.id"
                type="button"
                class="m-scope-row"
                :class="{ on: selectedCategory === cat.slug }"
                @click.prevent="pickScope(cat.slug)"
              >
                <span class="m-sug-ico">
                  <img :src="getImageURL(cat.image)" :alt="cat.title" width="18" height="18" loading="lazy">
                </span>
                {{ cat.title }}
                <svg v-if="selectedCategory === cat.slug" class="m-tick" width="17" height="17" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                  <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
                <small v-else>{{ cat.products_count || 0 }}</small>
              </button>
            </div>
          </div>
        </transition>

        <div class="m-ov-body">
          <search-popup v-if="searchedText" :searched-text="searchedText" @close="closeMobileSearch" />

          <template v-else>
            <template v-if="recentSearches.length">
              <div class="dr-cap">{{ $t('searchOverlay.recent') }}</div>
              <button
                v-for="(term, i) in recentSearches"
                :key="`recent-${i}`"
                type="button"
                class="m-sug"
                @click.prevent="runRecentSearch(term)"
              >
                <span class="m-sug-ico">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3.5 2" />
                  </svg>
                </span>
                {{ term }}
              </button>
            </template>

            <div class="dr-cap">{{ $t('searchOverlay.popular') }}</div>
            <nuxt-link
              v-for="cat in popularCategories"
              :key="cat.id"
              class="m-sug"
              :to="`/all/${cat.slug}`"
              @click.native="closeMobileSearch"
            >
              <span class="m-sug-ico">
                <img :src="getImageURL(cat.image)" :alt="cat.title" width="18" height="18" loading="lazy">
              </span>
              {{ cat.title }}
              <small>{{ cat.products_count || 0 }} {{ $t('searchOverlay.items') }}</small>
            </nuxt-link>
          </template>
        </div>
      </div>
    </transition>
  </header>
</template>
<script>
import outsideClick from '~/directives/outside-click'
import TopPromoBanner from '../../components/TopPromoBanner.vue';
import util from '~/mixin/util'
import { mapGetters, mapActions } from 'vuex'
import Banner from "../../components/Banner";
import Dropdown from "../../components/Dropdown";
import CategoryNav from "../../components/CategoryNav";

export default {
  data() {

    return {
      mobileMenu: false,
      userMenuOpen: false,
      langMenuOpen: false,
      langSheet: false,
      drawerCat: '',
      mobileSearch: false,
      recentSearches: [],
      scopeSheet: false,
      scopeMenu: false,
      headerSticky: false,
      topBannerLoaded: false,
      isTopBannerClosed: true,
      dropdown: false,
      searchPopup: false,
      searchFocused: false,
      searchedText: '',
      selectedCategory: '',
      megaSlug: '',
      navTabLimit: 5,
    }
  },
  computed: {
    // nav_categories comes back on every request; home.categories is only
    // populated by a visit to the home page, so it is the fallback
    categories() {
      const nav = this.navCategories
      return (nav && nav.length) ? nav : (this.homeCategories || [])
    },
    scopeLabel() {
      if (!this.selectedCategory) {
        return this.$t('listingLayout.allProducts')
      }
      const match = (this.categories || []).find(cat => cat.slug === this.selectedCategory)
      return match ? match.title : this.$t('listingLayout.allProducts')
    },
    popularCategories() {
      return (this.categories || []).slice(0, 6)
    },
    megaCategory() {
      if (!this.megaSlug) {
        return null
      }
      if (this.megaSlug === '__more') {
        return {
          slug: '__more',
          title: this.$t('featured.showAll'),
          child: (this.categories || []).slice(this.navTabLimit),
        }
      }
      return (this.categories || []).find(cat => cat.slug === this.megaSlug) || null
    },
    // "More" lists whole categories, a category lists its own children
    isMoreMega() {
      return this.megaSlug === '__more'
    },
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
    /** First name for the header greeting; "Account" when the profile has none. */
    greetingName() {
      return this.username || this.$t('header.account')
    },
    accountEmail() {
      return this.$auth?.user?.email || ''
    },
    signedInTitle() {
      return this.isLoggedIn
        ? `${this.$t('header.signedInAs')} ${this.accountEmail || this.greetingName}`
        : null
    },
    email() {
      return this.setting?.email
    },
    phone() {
      return this.setting?.phone
    },
    whatsapp() {
      return this.setting?.whatsapp
    },
    sellerSignUp() {
      return this.setting?.vendor_registration
    },
    ...mapGetters('home', { homeCategories: 'categories' }),
    ...mapGetters('home', ['featured_categories']),
    ...mapGetters('language', ['languages', 'currentLanguage']),
    ...mapGetters('common', ['site_setting', 'setting', 'topBanner', 'headerLinks', 'navCategories']),
    ...mapGetters('listing', ['searched']),
    ...mapGetters('cart', ['cartCount'])
  },
  watch: {
    '$route.fullPath'() {
      this.megaSlug = ''
      this.closeMobileSearch()
    },
    '$route.path'(value) {
      if (value !== '/search') {
        this.searchedText = ''
        this.selectedCategory = ''
      }
    },
    
   mobileMenu(val) {
    if (val) {
      document.body.style.overflow = 'hidden'
    } else {
      document.body.style.overflow = ''
     }

     },

  '$route.path'(value) {
    if (value !== '/search') {
      this.searchedText = ''
      this.selectedCategory = ''
    }
  },
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
    drawerOpen(val){
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
  /*
   * The header is in the default layout, so whatever it imports is on every
   * route. The search panel is only ever mounted behind a v-if - nobody has
   * it open on arrival - so it is fetched when it is first opened instead of
   * riding along with every page load.
   */
  components: {
    Dropdown,
    Banner,
    CategoryNav,
    SearchPopup: () => import('~/components/SearchPopup')
  },
  mixins: [util],
  methods: {
    navigateBadge(type) {

      let query = {}

      if (type === 'hot') {
        query.badge = 'hot'
        this.$router.push({
          path: '/_main/products',
          query
        })
      }

      if (type === 'clearance') {
        this.$router.push({
          path: '/clearance',
        })
      }

      
    },
    toggleUserMenu() {
      this.userMenuOpen = !this.userMenuOpen
      this.langMenuOpen = false
    },
    closeUserMenu() {
      this.userMenuOpen = false
    },
    toggleLangMenu() {
      this.langMenuOpen = !this.langMenuOpen
      this.userMenuOpen = false
    },
    closeLangMenu() {
      this.langMenuOpen = false
    },
    openMobileMenu() {
      this.mobileMenu = true
    },
    // recent terms are a per-device convenience, so they live in localStorage
    loadRecentSearches() {
      if (!process.client) {
        return []
      }
      try {
        const raw = localStorage.getItem('recentSearches')
        const list = raw ? JSON.parse(raw) : []
        return Array.isArray(list) ? list.slice(0, 6) : []
      } catch (e) {
        return []
      }
    },
    rememberSearch(term) {
      const value = `${term || ''}`.trim()
      if (!value || !process.client) {
        return
      }
      try {
        const list = this.loadRecentSearches()
          .filter(item => item.toLowerCase() !== value.toLowerCase())
        list.unshift(value)
        localStorage.setItem('recentSearches', JSON.stringify(list.slice(0, 6)))
      } catch (e) {
        // storage can be unavailable in private mode - never block the search
      }
    },
    pickScope(slug) {
      this.selectedCategory = slug
      this.scopeSheet = false
      this.scopeMenu = false
      this.$nextTick(() => {
        const field = this.$refs.mobileSearchInput || this.$refs.desktopSearchInput
        field?.focus()
      })
    },
    closeScopeMenu() {
      this.scopeMenu = false
    },
    runRecentSearch(term) {
      this.searchedText = term
      this.submitMobileSearch()
    },
    openMobileSearch() {
      this.mobileSearch = true
      this.mobileMenu = false
      this.recentSearches = this.loadRecentSearches()
      if (process.client) {
        document.body.classList.add('no-scroll')
      }
      // wait for the transition to mount the field before focusing it
      this.$nextTick(() => {
        setTimeout(() => this.$refs.mobileSearchInput?.focus(), 120)
      })
    },
    closeMobileSearch() {
      this.mobileSearch = false
      this.scopeSheet = false
      if (process.client) {
        document.body.classList.remove('no-scroll')
      }
    },
    submitMobileSearch() {
      if (!this.searchedText) {
        return
      }
      this.closeMobileSearch()
      this.search()
    },
    // one open branch at a time in the drawer, same rule as the sidebar tree
    toggleDrawerCat(cat) {
      this.drawerCat = this.drawerCat === cat.slug ? '' : cat.slug
    },
    selectLang(lang) {
      this.langMenuOpen = false
      this.langSheet = false
      this.selectedLanguage({ key: lang.code })
    },
    // a second click on the same tab closes the panel again
    toggleMega(cat) {
      this.megaSlug = this.megaSlug === cat.slug ? '' : cat.slug
    },
    closeMega() {
      this.megaSlug = ''
    },
    onMegaKeydown(e) {
      if (e.key === 'Escape') {
        this.closeMega()
        this.closeScopeMenu()
      }
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
      if (!this.searchedText) {
        return
      }

      this.rememberSearch(this.searchedText)

      const query = {
        q: this.searchedText
      }

      if (this.selectedCategory) {
        query.category =
          this.selectedCategory
      }

      this.$router.push({
        path: '/search',
        query
      })

      this.updateSearch(
        this.searchedText
      )
    },
    async loggingOut() {
      try {
        await this.logout()
        this.closeDropdown()
        // The basket is emptied by plugins/cart-identity.js, which watches the
        // signed-in flag — there is more than one way to end a session, and
        // only some of them come through this button.
      } catch (e) {
        return this.$nuxt.error(e)
      }
    },
    closeDropdown() {
      this.dropdown = false
    },
    ...mapActions('user', ['logout']),
    ...mapActions('language', ['setDefaultLanguage', 'getLangData']),
    ...mapActions('cart', ['emptyCartProduct', 'setCartCount']),
    ...mapActions('listing', ['updateSearch', 'emptySearchedSuggestion']),
  },
  deactivated() {
  },
  activated() {

  },
  mounted() {

    document.addEventListener('click', this.closeUserMenu)
    document.addEventListener('click', this.closeLangMenu)
    document.addEventListener('click', this.closeMega)
    document.addEventListener('click', this.closeScopeMenu)
    document.addEventListener('keydown', this.onMegaKeydown)

    this.setQFromRoute()
    this.updateSearch(this.searchedText)

    /*
     * Only as a first guess.
     *
     * $auth.user is whatever the sign-in response said and is never refetched
     * (the user endpoint is off), so its cart_count is frozen at the moment the
     * session was created. The store's count comes from the basket the server
     * last returned, which is newer whenever there is one — and seeding over it
     * on every page load was what put a stale badge back in the header after a
     * refresh.
     */
    if (!this.cartCount && this.cartCountCom) {
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
  },
  beforeDestroy() {
    document.removeEventListener('click', this.closeUserMenu)
    document.removeEventListener('click', this.closeLangMenu)
    document.removeEventListener('click', this.closeMega)
    document.removeEventListener('click', this.closeScopeMenu)
    document.removeEventListener('keydown', this.onMegaKeydown)
  }

}

</script>
<style scoped>
.contact-link {
  text-decoration: none;
  color: #130E2B;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.user-menu {
  position: relative;
  overflow: visible;
  cursor: pointer;
  display: flex;
  align-items: center;
}
.dropdown-menu {
  position: absolute;
  top: 100%;
  right: 0;
  display: none;
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

/*
 * Logout is a <button>, the rest of the menu are links: without this it took
 * the global button metrics (50px tall, larger type, no padding) and sat out
 * of line with the items above it. Everything here mirrors .dropdown-menu a.
 */
.dropdown-menu .dropdown-logout {
  display: block;
  width: 100%;
  height: auto;
  margin: 0;
  padding: 8px 10px;
  border: 0;
  border-radius: 0;
  background: none;
  color: #333;
  font: inherit;
  font-size: 14px;
  line-height: inherit;
  text-align: left;
  cursor: pointer;
}

/* The global button hover outranks anything short of !important. */
.dropdown-menu .dropdown-logout:hover {
  background: #f5f5f5 !important;
  color: #333 !important;
}

/* ══════════ mobile chrome (max-width: 991px) ══════════ */
/* Two calm rows instead of a logo fighting five icon buttons: identity and
   menu on top, a real search field underneath. Cart, saved items and the
   account live in the bottom tab bar, within thumb reach. */
.m-menu-btn {
  align-items: center;
  background: none;
  border: 1px solid #E4E5F3;
  border-radius: 12px;
  color: #130E2B;
  display: flex;
  height: 42px;
  justify-content: center;
  padding: 0;
  transition: background .15s, border-color .15s, color .15s;
  width: 42px;
}

.m-menu-btn:active {
  background: #F1F1FC;
  border-color: #33319A;
  color: #33319A;
}

.m-cart-btn {
  position: relative;
  text-decoration: none;
}

.m-badge {
  align-items: center;
  background: #05B942;
  border: 2px solid #fff;
  border-radius: 999px;
  color: #fff;
  display: flex;
  font-size: 9.5px;
  font-weight: 800;
  height: 18px;
  justify-content: center;
  line-height: 1;
  min-width: 18px;
  padding: 0 4px;
  position: absolute;
  right: -6px;
  top: -6px;
}

/* full screen search, opened from the header icon */
.m-overlay {
  background: #fff;
  bottom: 0;
  display: flex;
  flex-direction: column;
  left: 0;
  position: fixed;
  right: 0;
  top: 0;
  z-index: 1000000;
}

.m-ov-head {
  align-items: center;
  border-bottom: 1px solid #E8E9F5;
  display: flex;
  flex: 0 0 auto;
  gap: 8px;
  padding: 10px 12px;
}

.m-ov-back {
  align-items: center;
  background: none;
  border: 0;
  border-radius: 12px;
  color: #130E2B;
  display: flex;
  flex: 0 0 auto;
  height: 42px;
  justify-content: center;
  padding: 0;
  width: 42px;
}

.m-ov-back:active {
  background: #F1F1FC;
  color: #33319A;
}

.m-ov-field {
  align-items: stretch;
  background: #fff;
  border: 1px solid #CFD1E8;
  border-radius: 999px;
  display: flex;
  flex: 1 1 auto;
  gap: 0;
  height: 44px;
  min-width: 0;
  overflow: hidden;
  padding: 0;
}

/* scope the search to one category. A native <select> renders as a raw OS
   list on a phone, so this is a button that opens a proper sheet. */
.m-ov-scope {
  align-items: center;
  background: #F7F7FD;
  border: 0;
  border-radius: 0;
  border-right: 1px solid #E4E5F3;
  color: #3A3C6E;
  display: flex;
  flex: 0 0 auto;
  font-size: 12.5px;
  font-weight: 600;
  gap: 7px;
  height: auto;
  max-width: 124px;
  padding: 0 12px 0 14px;
}

.m-ov-scope:active {
  background: #F1F1FC;
  color: #33319A;
}

.m-ov-scope-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.m-sheet-scrim {
  background: rgba(22, 23, 74, .42);
  bottom: 0;
  left: 0;
  position: absolute;
  right: 0;
  top: 0;
  z-index: 2;
}

.m-sheet {
  background: #fff;
  border-radius: 22px 22px 0 0;
  bottom: 0;
  box-shadow: 0 -8px 30px rgba(22, 23, 74, .18);
  display: flex;
  flex-direction: column;
  left: 0;
  max-height: 78%;
  position: absolute;
  right: 0;
  z-index: 3;
}

.m-sheet-h {
  align-items: center;
  border-bottom: 1px solid #E8E9F5;
  display: flex;
  flex: 0 0 auto;
  gap: 12px;
  padding: 18px 16px 12px;
  position: relative;
}

.m-sheet-h b {
  color: #130E2B;
  flex: 1;
  font-size: 15px;
}

.m-sheet-grab {
  background: #CFD1E8;
  border-radius: 999px;
  height: 4px;
  left: 50%;
  position: absolute;
  top: 7px;
  transform: translateX(-50%);
  width: 38px;
}

.m-sheet-b {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 6px 10px calc(14px + env(safe-area-inset-bottom));
}

.m-scope-row {
  align-items: center;
  background: none;
  border: 0;
  border-radius: 12px;
  color: #130E2B;
  display: flex;
  font-size: 14.5px;
  font-weight: 500;
  gap: 12px;
  height: auto;
  line-height: 1.4;
  padding: 12px 12px;
  text-align: left;
  width: 100%;
}

.m-scope-row:active {
  background: #F7F7FD;
}

.m-scope-row.on {
  background: #F1F1FC;
  color: #33319A;
  font-weight: 700;
}

.m-scope-row small {
  color: #6B7191;
  font-size: 12px;
  margin-left: auto;
}

.m-tick {
  color: #33319A;
  flex: 0 0 auto;
  margin-left: auto;
}

.m-sheet-enter-active,
.m-sheet-leave-active {
  transition: transform .26s ease;
}

.m-sheet-enter,
.m-sheet-leave-to {
  transform: translateY(100%);
}

.m-fade-enter-active,
.m-fade-leave-active {
  transition: opacity .22s ease;
}

.m-fade-enter,
.m-fade-leave-to {
  opacity: 0;
}

.m-ov-field:focus-within {
  border-color: #33319A;
  box-shadow: 0 0 0 3px rgba(51, 49, 154, .12);
}

.m-ov-field input {
  background: transparent;
  border: 0;
  border-radius: 0;
  color: #130E2B;
  flex: 1 1 auto;
  font-size: 15px;
  height: 100%;
  line-height: 1.4;
  min-width: 0;
  padding: 0 4px 0 14px;
}

.m-ov-field input:focus {
  border: 0;
  box-shadow: none;
  outline: none;
}

.m-ov-field input::placeholder {
  color: #6B7191;
}

.m-ov-clear {
  align-self: center;
  margin-right: 6px;
  align-items: center;
  background: #F1F1FC;
  border: 0;
  border-radius: 50%;
  color: #3A3C6E;
  display: flex;
  flex: 0 0 auto;
  height: 30px;
  justify-content: center;
  padding: 0;
  width: 30px;
}

.m-ov-body {
  flex: 1 1 auto;
  overflow-y: auto;
  padding: 8px 14px calc(20px + env(safe-area-inset-bottom));
  position: relative;
}

/* the shared results panel is a floating dropdown on desktop - inside the
   overlay it becomes the page */
.m-ov-body >>> .search-popup,
.m-ov-body >>> .search-popup .popup-inner {
  border: 0;
  border-radius: 0;
  box-shadow: none;
  left: auto;
  max-height: none;
  position: static;
  top: auto;
  transform: none;
  width: 100%;
}

.m-ov-body >>> .search-popup .pop-over-content {
  padding: 6px 0;
}

/* one row treatment for recent terms and categories alike */
.m-sug {
  align-items: center;
  background: none;
  border: 0;
  border-bottom: 1px solid #F1F1F7;
  border-radius: 0;
  color: #130E2B;
  display: flex;
  font-size: 14.5px;
  font-weight: 500;
  gap: 12px;
  height: auto;
  line-height: 1.4;
  padding: 13px 4px;
  text-align: left;
  text-decoration: none;
  width: 100%;
}

.m-sug-ico {
  align-items: center;
  color: #6B7191;
  display: flex;
  flex: 0 0 auto;
  height: 20px;
  justify-content: center;
  width: 20px;
}

.m-sug-ico img {
  height: 18px;
  object-fit: contain;
  width: 18px;
}

.m-sug:active {
  background: #F7F7FD;
}

.m-sug small {
  color: #6B7191;
  font-size: 12px;
  font-weight: 500;
  margin-left: auto;
  padding-left: 10px;
  white-space: nowrap;
}

.m-ov-enter-active,
.m-ov-leave-active {
  transition: opacity .18s ease;
}

.m-ov-enter,
.m-ov-leave-to {
  opacity: 0;
}

@media (max-width: 991px) {
  .header {
    padding: 8px 0 10px;
  }

  .logo img {
    height: 40px;
  }


  .logo-text {
    font-size: 14px;
  }
}

/* ══════════ mobile drawer (max-width: 991px) ══════════ */
/* logo + wordmark, matching the sticky header above it */
.m-drawer-brand {
  align-items: center;
  display: flex;
  gap: 10px;
  min-width: 0;
  text-decoration: none;
}

.m-drawer-brand .logo-text {
  display: block;
  font-size: 14px;
}

.dr-cap {
  color: #6B7191;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .11em;
  padding: 18px 10px 6px;
  text-transform: uppercase;
}

.dr-cap:first-child {
  padding-top: 6px;
}

.dr-list {
  display: block;
}

.dr-item {
  border-bottom: 1px solid #F3F3F9;
  display: block;
}

.dr-item:last-child {
  border-bottom: 0;
}

.dr-row {
  align-items: center;
  border-radius: 12px;
  display: flex;
  gap: 4px;
}

.dr-row:active,
.dr-row.open {
  background: #F1F1FC;
}

.dr-row-link {
  align-items: center;
  color: #130E2B;
  display: flex;
  flex: 1 1 auto;
  font-size: 14.5px;
  font-weight: 600;
  gap: 12px;
  line-height: 1.4;
  margin: 0;
  min-width: 0;
  padding: 10px 4px 10px 8px;
  text-decoration: none;
  width: auto;
}

.dr-ico {
  align-items: center;
  background: #F1F1FC;
  border-radius: 10px;
  display: flex;
  flex: 0 0 auto;
  height: 32px;
  justify-content: center;
  overflow: hidden;
  width: 32px;
}

.dr-ico img {
  height: 18px;
  object-fit: contain;
  width: 18px;
}

.dr-n {
  background: #F7F7FD;
  border-radius: 999px;
  color: #6B7191;
  flex: 0 0 auto;
  font-size: 11.5px;
  font-weight: 700;
  margin-left: auto;
  min-width: 30px;
  padding: 3px 8px;
  text-align: center;
}

.dr-cv-slot {
  align-items: center;
  display: flex;
  flex: 0 0 auto;
  justify-content: center;
  width: 44px;
}

.dr-cv {
  align-items: center;
  background: none;
  border: 0;
  color: #6B7191;
  display: flex;
  height: 44px;
  justify-content: center;
  padding: 0;
  transition: transform .2s ease;
  width: 100%;
}

.dr-row.open .dr-cv {
  color: #33319A;
  transform: rotate(180deg);
}

.dr-kids {
  border-left: 1px solid #E8E9F5;
  display: none;
  margin: 0 0 6px 26px;
  padding-left: 10px;
  padding-right: 44px;
}

.dr-kids.on {
  display: block;
}

.dr-kid {
  align-items: center;
  border: 0;
  border-radius: 9px;
  color: #3A3C6E;
  display: flex;
  font-size: 13.5px;
  gap: 8px;
  line-height: 1.4;
  margin: 0;
  padding: 9px 10px;
  text-decoration: none;
  width: auto;
}

.dr-kid:active {
  background: #F1F1FC;
}

.dr-kid-all {
  color: #33319A;
  font-weight: 700;
}

/* account and help rows share the rhythm of the category rows above */
.dr-link {
  align-items: center;
  border-bottom: 1px solid #F3F3F9;
  color: #130E2B;
  display: flex;
  font-size: 14.5px;
  font-weight: 600;
  gap: 12px;
  line-height: 1.4;
  margin: 0;
  padding: 11px 8px;
  text-decoration: none;
  width: auto;
}

.dr-link:last-child {
  border-bottom: 0;
}

.dr-link:active {
  background: #F1F1FC;
}

.dr-link .dr-ico i {
  color: #33319A;
  font-size: 14px;
}

.dr-go {
  color: #C9CBDF;
  flex: 0 0 auto;
  margin-left: auto;
}

.m-lang-btn {
  align-items: center;
  background: none;
  border: 0;
  color: #130E2B;
  display: inline-flex;
  height: auto;
  justify-content: center;
  line-height: 0;
  padding: 4px;
}

/* deals get equal weight as two full width cards rather than loose pills */
.dr-deals {
  display: grid;
  gap: 10px;
  grid-template-columns: 1fr 1fr;
  padding: 2px 2px 4px;
}

.dr-deal {
  align-items: center;
  border: 0;
  border-radius: 14px;
  color: #fff;
  display: flex;
  flex-direction: column;
  font-size: 13px;
  font-weight: 700;
  gap: 7px;
  height: auto;
  justify-content: center;
  line-height: 1.3;
  padding: 15px 10px;
  text-align: center;
  transition: transform .15s ease;
}

.dr-deal:active {
  transform: scale(.97);
}

.dr-deal i {
  font-size: 17px;
}

.dr-deal-hot {
  background: #33319A;
}

.dr-deal-clear {
  background: #05B942;
}

.lang-select {
  border: none;
  outline: none;
  font-size: 16px;
  font-weight: 500;
}

/* language picker: same icon button as cart/wishlist, with a menu under it */
.lang-menu {
  cursor: pointer;
  overflow: visible;
  position: relative;
}

.lang-menu .dropdown-menu {
  min-width: 160px;
}

.lang-option {
  background: none;
  border: 0;
  border-radius: 6px;
  color: #333;
  cursor: pointer;
  display: block;
  font-size: 14px;
  height: auto;
  line-height: 1.4;
  padding: 8px 10px;
  text-align: left;
  width: 100%;
}

.lang-option:hover {
  background: #f5f5f5;
}

.lang-option.active {
  background: #F1F1FC;
  color: #33319A;
  font-weight: 700;
}

.toggle-btn {
  background: none;
  border: none;
  font-size: 22px;
}

header {
  background: #fff;
  position: sticky;
  top: 0;
  z-index: 50;
}

.header {
  background: #fff;
  padding: 0;
  position: relative;
}

@media (max-width: 991px) {
  .header {
    padding: 10px 0;
  }
}

.fx-head-top {
  padding: 12px 0;
  gap: 18px !important;
  margin-bottom: 0 !important;
  width: 100%;
  justify-content: flex-end;
}

.logo {
  align-items: center;
  display: flex;
  gap: 10px;
  text-decoration: none;
}

.logo img {
  height: 48px;
  width: auto;
}


.logo-text {
  color: #130E2B;
  font-size: 15.5px;
  font-weight: 800;
  letter-spacing: -.01em;
  line-height: 1.15;
  white-space: nowrap;
}

/* only the mid range is tight: below 992px the mobile row has room again */
@media (min-width: 992px) and (max-width: 1199px) {
  .logo-text {
    display: none;
  }
}

/* Right Section */
.header-right {
  display: flex;
  flex-direction: column;
  align-items: center;
  align-items: flex-end;
}

.fx-header-icon-item {
  width: 42px;
  height: 42px;
  background: none;
  color: #3A3C6E;
  border-radius: 12px;
  transition: background .15s, color .15s;
  border-radius: 12px;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}

/*
 * Signed in: the icon box widens to carry "Hi, <name>". Ellipsised so a long
 * name cannot push the header icons out of line.
 */
.fx-header-icon-item.is-signed-in {
  width: auto;
  max-width: 180px;
  padding: 0 10px 0 8px;
  gap: 6px;
}

.signed-in-name {
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
  color: #3A3C6E;
}

.signed-in-as {
  /* Wide enough for a typical address on one line; longer ones still wrap. */
  min-width: 220px;
  padding: 6px 10px 8px;
  font-size: 12px;
  line-height: 1.4;
  color: #6B7191;
  overflow-wrap: anywhere;
  cursor: default;
}

.signed-in-as strong {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #130E2B;
}

.fx-header-icon-item:hover {
  background: #F1F1FC;
  color: #33319A;
}

.fx-header-icon-item svg {
  width: 22px;
  height: 22px;
}

.cart-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  background: #05B942;
  font-size: 10px;
  font-weight: 800;
  min-width: 17px;
  padding: 0 4px;
  width: auto;
  height: 17px;
  display: flex;
  justify-content: center;
  align-items: center;
  border-radius: 100%;
  color: #fff;
  line-height: 0;
}

/* Nav */
/* .header-right is a column flex with align-items: flex-end, so its rows are
   content-sized. Pin this row to the column width, otherwise the carousel's
   full track width leaks out to the left over the logo. */
/* full width bar under the search row, as in the reference header */
.fx-nav {
  border-top: 1px solid #E4E5F3;
  background: #fff;
  position: relative;
}

/* mega menu — anchored to the bar so it spans the full width */
.fx-mega {
  position: absolute;
  left: 0;
  right: 0;
  top: 100%;
  z-index: 39;
  background: #fff;
  border-top: 1px solid #E4E5F3;
  box-shadow: 0 10px 30px rgba(22, 23, 74, .1);
}

.fx-mega-in {
  display: flex;
  align-items: flex-start;
  gap: 40px;
  padding-top: 18px;
  padding-bottom: 20px;
}

.fx-mega-cols {
  flex: 1 1 auto;
  min-width: 0;
}

.fx-mega-in .mega-card {
  flex: 0 0 264px;
  margin-left: auto;
}

.fx-mega-in.is-more .fx-mega-list {
  max-width: none;
}

.fx-mega-cols h4 {
  margin: 0 0 8px;
  font-size: 11px;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #6B7191;
  font-weight: 800;
}

/* fixed width columns packed left, so label and count stay close together */
.fx-mega-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 232px));
  justify-content: start;
  gap: 2px 18px;
  max-width: 1000px;
}

.mega-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 9px;
  border-radius: 9px;
  font-size: 13.5px;
  color: #3A3C6E;
  text-decoration: none;
}

.mega-link:hover {
  background: #F1F1FC;
  color: #33319A;
}

.mega-link .n {
  margin-left: auto;
  font-size: 11.5px;
  font-weight: 700;
  color: #6B7191;
}

.mega-link:hover .n {
  color: #33319A;
}

.mega-card {
  background: #F7F7FD;
  border: 1px solid #E4E5F3;
  border-radius: 16px;
  padding: 18px;
}

.mega-card-head {
  display: flex;
  align-items: center;
  gap: 11px;
  margin-bottom: 14px;
}

.mega-card-thumb {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 42px;
  height: 42px;
  border-radius: 12px;
  background: #fff;
  border: 1px solid #E4E5F3;
}

.mega-card-thumb img {
  width: 24px;
  height: 24px;
  object-fit: contain;
}

.mega-card b {
  display: block;
  margin-bottom: 1px;
  font-size: 15px;
  line-height: 1.3;
  color: #130E2B;
}

.mega-card-count {
  display: block;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #6B7191;
}


.mega-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: #33319A;
  color: #fff;
  border-radius: 999px;
  padding: 10px 18px;
  font-size: 13.5px;
  font-weight: 700;
  text-decoration: none;
  transition: background .15s;
}

.mega-cta:hover {
  background: rgb(22, 21, 95);
}

@media (max-width: 1080px) {
  .fx-mega-in {
    flex-wrap: wrap;
  }

  .fx-mega-in .mega-card {
    flex: 1 1 100%;
    margin-left: 0;
  }
}

.fx-nav-row {
  display: flex;
  align-items: stretch;
  gap: 12px;
  min-height: 52px;
}

.nav-row-wrap {
  min-width: 0;
  width: 100%;
}

.nav-links--row {
  align-items: center;
  display: flex;
  flex: 1 1 0;
  gap: 8px;
  min-width: 0;
}

.nav-cat-carousel {
  flex: 1 1 0;
  min-width: 0;
}

/* the tab strip fills the bar so the active underline meets its bottom edge */
.fx-nav-row .nav-cat-carousel {
  align-self: stretch;
}

.nav-links--row .badge-hot,
.nav-links--row .badge-green {
  flex: 0 0 auto;
  white-space: nowrap;
}

.nav-links a {
  margin-right: 30px;
  text-decoration: none;
  color: #130E2B;
  font-size: 16px;
}

.badge-hot {
  background: #33319A;
  color: #fff;
  padding: 8px 15px;
  border-radius: 999px;
  font-size: 13.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  cursor: pointer;
}

.badge-green {
  background: #05B942;
  color: #fff;
  padding: 8px 15px;
  border-radius: 999px;
  font-size: 13.5px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  cursor: pointer;
}

.small-info {
  display: flex;
  align-items: center;
  flex: 0 0 auto;
  margin-left: 6px;
  font-size: 13px;
  color: #6B7191;
  line-height: 1.4;
}

.small-info .contact-link:hover .contact {
  color: #33319A;
}

.contact {
  color: #6B7191;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.4;
  transition: color .15s;
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

/* Search Css Start Form Here  */
.fx-header-search-wrap {
  position: relative;
}

.fx-header-search-wrap {
  position: relative;
  border: 1px solid #CFD1E8;
  border-radius: 999px;
  background: #fff;
  display: flex;
  align-items: stretch;
  flex: 1 1 auto;
  min-width: 0;
  max-width: 760px;
  margin-right: auto;
}

/* Category scope inside the pill. A native <select> can only render the OS
   list, so this is a button with an anchored panel - same rows and ticks as
   the sheet the phone gets. */
.fx-scope {
  align-items: stretch;
  display: flex;
  flex: 0 0 auto;
  position: relative;
}

.fx-search-scope {
  align-items: center;
  background: #F7F7FD;
  border: 0;
  border-radius: 999px 0 0 999px;
  border-right: 1px solid #E4E5F3;
  color: #3A3C6E;
  cursor: pointer;
  display: flex;
  font-size: 13.5px;
  gap: 8px;
  height: auto;
  max-width: 190px;
  padding: 0 14px 0 16px;
  transition: background .15s, color .15s;
}

.fx-search-scope:hover {
  background: #F1F1FC;
  color: #33319A;
}

.fx-scope-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fx-scope-menu {
  background: #fff;
  border: 1px solid #E4E5F3;
  border-radius: 14px;
  box-shadow: 0 16px 40px rgba(22, 23, 74, .16);
  left: 0;
  margin-top: 8px;
  max-height: 340px;
  min-width: 244px;
  overflow-y: auto;
  padding: 6px;
  position: absolute;
  top: 100%;
  z-index: 41;
}

.fx-scope-row {
  align-items: center;
  background: none;
  border: 0;
  border-radius: 9px;
  color: #3A3C6E;
  cursor: pointer;
  display: flex;
  font-size: 13.5px;
  gap: 10px;
  height: auto;
  line-height: 1.4;
  padding: 9px 10px;
  text-align: left;
  width: 100%;
}

.fx-scope-row:hover {
  background: #F7F7FD;
  color: #130E2B;
}

.fx-scope-row.on {
  background: #F1F1FC;
  color: #33319A;
  font-weight: 700;
}

.fx-scope-ico {
  align-items: center;
  color: #6B7191;
  display: flex;
  flex: 0 0 auto;
  height: 18px;
  justify-content: center;
  width: 18px;
}

.fx-scope-row.on .fx-scope-ico {
  color: #33319A;
}

.fx-scope-ico img {
  height: 16px;
  object-fit: contain;
  width: 16px;
}

.fx-scope-row small {
  color: #6B7191;
  font-size: 11.5px;
  font-weight: 700;
  margin-left: auto;
}

.fx-scope-tick {
  color: #33319A;
  flex: 0 0 auto;
  margin-left: auto;
}

.fx-header-search-wrap input {
  flex: 1 1 auto;
  min-width: 0;
  width: auto;
  height: auto;
  line-height: 1.4;
  border: none;
  border-radius: 0;
  outline: none;
  background: transparent;
  padding: 12px 16px;
  font-size: 14.5px;
  font-weight: 400;
  color: #130E2B;
}

.fx-header-search-wrap input::placeholder {
  color: #73739A;
  opacity: 1;
}

.fx-header-search-wrap input:focus {
  outline: none;
  border: none;
  box-shadow: none;
}

.fx-header-search {
  position: static;
  transform: none;
  flex: 0 0 auto;
  width: 52px;
  height: auto;
  line-height: 0;
  padding: 0;
  border: none;
  border-radius: 0 999px 999px 0;
  background: #33319A;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background .15s;
}

.fx-header-search:hover {
  background: rgb(22, 21, 95);
}

.fx-catgory-serach {
  padding: 16px 50px 16px 24px;
  border: 1px solid #E3E3EF;
  border-radius: 50px;
  width: 300px;
  color: #75748F;
  font-size: 14px;
  background-image: url("data:image/svg+xml,%3Csvg width='12' height='7' viewBox='0 0 12 7' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0.75 0.75L5.75 5.75L10.75 0.75' stroke='%2375748F' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 24px center;
  background-size: 15px;
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
}

.fx-catgory-serach:focus {
  outline: none;
  border: 1px solid #E3E3EF;
  box-shadow: none;
}

.fx-header-search svg {
  width: 15px;
  height: 15px;
}

/* IE */
.fx-catgory-serach::-ms-expand {
  display: none;
}

.fx-vertical-center {
  align-items: center !important;
}

@media(max-width:991px) {

  .fx-header-search-wrap input {
    font-size: 22px;
    padding-left: 32px;
    padding-right: 110px;
  }

  .fx-header-search {
    width: 62px;
    height: 62px;
  }

}
/* MOBILE + TABLET MENU */
@media (max-width: 991px) {
  #mobileMenu {
    display: block;          
    position: fixed;
    top: 0;
    left: -100%;           
    width: 100%;
    height: 100vh;         
    background: #fff;
    z-index: 99999;
    overflow-y: auto;
    transition: all .35s ease;
    padding: 20px;
  }
  #mobileMenu.active {
    left: 0;          
  }
  .search-wrap {
    width: auto;
  }
  .nav-links {
    display: block;
    margin-top: 4px;
  }
  .badge-hot,
  .badge-green {
    display: inline-block;
    text-align: center;
    padding: 10px 0px;
  }

}
@media (max-width: 1035px) {
  .fx-catgory-serach{
    width: auto;
  }
  .fx-header-search-wrap{
    width: max(300px, min(25vw, 569px)); 
  }
}
@media(max-width:576px) {
  .fx-header-search-wrap input {
    font-size: 18px;
    padding-left: 22px;
    padding-right: 86px;
  }
    .fx-catgory-serach{
     width:100%; 
    }
    .fx-header-search{
        width: 45px;
        height: 45px;
        right: 4px;
        top: 27px;
    }
  .fx-header-icon-item svg{
    width: 18px;
    height: 18px;
  }
  .contact{
    display: none;
  }
  .contact-link svg {
    width: 25px;
    height: 25px;
  }
    .mobile-menu-open .mobile-menu-cs .small-info {
    display: flex;
    align-items: center;
    justify-content: center;
    }
    .small-info-separate-line {
      width: 1px;
      background: #494949;
      height: 20px;
      margin: 0 15px;
      display: flex;
      color: #494949;
      font-size: 0;
    }
    .fx-header-search-wrap{
      width: 100%;
    }
}
@media (max-width:991px){
  .mobile-menu-cs{
      position: fixed;
      inset: 0;
      width: 100%;
      max-width: 100%;
      height: 100dvh;
      background: #fff;
      z-index: 999999;
      transform: translateX(-100%);
      transition: transform .35s ease;
      display: flex;
      flex-direction: column;
      overflow: hidden;
  }
    .mobile-menu-cs-overly{
      content:"";
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.45);
      backdrop-filter:blur(3px);
      opacity:0;
      visibility:hidden;
      transition:.3s;
      z-index:999;
   }  

  .mobile-menu-cs-overly.active{
      opacity:1;
      visibility:visible;
   }

  .fmpc-inputs-for-mobile {
      padding: 0 20px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin-top: 10px;
  }
  .mobile-menu-cs.active{
      transform: translateX(0);
  }

  .mobile-menu-header{
    flex: 0 0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:16px 20px;
    border-bottom:1px solid #eee;
    background:#fff;
    position:sticky;
    top:0;
    z-index:10;
 }

.mobile-menu-header img{
    height:42px;
    width:auto;
}

.mobile-close{
    border:none;
    background:none;
    font-size:22px;
    cursor:pointer;
}

.mobile-menu-cs .nav-links{
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  overscroll-behavior: contain;
  padding: 10px 20px 110px;
  min-height: 0;
  -webkit-overflow-scrolling: touch;
}

.small-info {
    display: flex;
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 20px;
    align-items: center;
    justify-content: center;
    background: #f8f7ff;
}
.mobile-menu-header .m-close-and-lang {
    display: flex;
    align-items: center;
    gap: 15px;
}
.m-close-and-lang .lang-dropdown {
    margin: 0 !important;
}
.m-close-and-lang .lang-select{
  font-size: 18px;
  font-weight: 500
}
.fmpc-inputs-for-mobile select {
    margin: 0 !important;
}
.fmpc-inputs-for-mobile form {
    margin: 0 !important;
}
  .mobile-menu-item-scroll{
    flex: 1;
    min-height: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }


.mobile-menu-item-scroll::-webkit-scrollbar {
  width: 4px;
}

.mobile-menu-item-scroll::-webkit-scrollbar-thumb {
  background: #33319a;
  border-radius: 10px;
}

.mobile-menu-item-scroll::-webkit-scrollbar-track {
  background: transparent;
}
}

</style>
