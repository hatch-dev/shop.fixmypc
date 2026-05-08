<template>
  <client-only>
    <div class="container-fluid mtb-20 mtb-sm-15">
      <div class="row g-4">
        <!-- Sidebar -->
        <div class="col-md-3">
            <div class="sidebar text-center">
                <img
                  :src="userImage"
                  class="profile-img mb-3"
                >
                <h6 class="mb-0 username">{{ user.name || 'Guest User' }}</h6>
                <p class="email-text">{{ user.email || 'guest@example.com' }}</p>

                <div class="menu mt-4 text-start">
                    <nuxt-link
                        v-for="item in menu"
                        :key="item.route"
                        :to="item.link"
                        :class="{ active: activeRoute === item.route }"
                        @click="goingNext(item.link)"
                    >
                        <i :class="item.icon" class="me-2"></i>
                        {{ item.label }}
                    </nuxt-link>
                    <a href="#" class="text-danger" @click.prevent="logout">
                      <i class="fa fa-sign-out-alt me-2"></i> Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <slot
              name="rightArea"
            />
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
        menu: [
          { label: 'Dashboard', route: 'dashboard', link: '/user/dashboard', icon: 'fa fa-user' },
          { label: 'Profile', route: 'profile', link: '/user/profile', icon: 'fa fa-user' },
          { label: 'Address Book', route: 'addresses', link: '/user/addresses', icon: 'fa fa-map-marker' },
          { label: 'My Orders', route: 'orders', link: '/user/orders', icon: 'fa fa-box' },
          { label: 'My Wishlist', route: 'wishlists', link: '/user/wishlists', icon: 'fa fa-heart' },
          { label: 'Recently Viewed', route: 'recently-viewed', link: '/user/recently-viewed', icon: 'fa fa-eye' },
          { label: 'Compared', route: 'compared', link: '/user/compared', icon: 'fa fa-exchange-alt' },
          { label: 'Vouchers', route: 'vouchers', link: '/user/vouchers', icon: 'fa fa-ticket' },
          { label: 'Following', route: 'following', link: '/user/following', icon: 'fa fa-store' },
          { label: 'Savers Club', route: 'savers-club', link: '/user/savers-club', icon: 'fa fa-users' },
        ]
      }
    },
    mixins: [util],
    watch: {},
    props: {
      activeRoute:{
        type: String
      }
    },
    computed: {
      ...mapGetters('user', ['profile']),

      user() {
        return this.profile || {}
      },
      userImage() {
        if (this.user?.image) {

          const path = this.user.image
          if ( path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
            return path
          }
          return this.getImageURL(this.user.image)
        }

        return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(this.user.name || 'Guest User') + '&background=random&color=fff&size=150'
      }
    },
    mounted() {
    },
    methods: {
      goingNext(url){
        const clicked = url.split('/')
        this.$emit(`clicked-${clicked[clicked.length-1]}`)
      },
      logout() {
        this.$auth.logout()
      }
    },
  }
</script>

<style scoped>
.sidebar {
    background: #fff;
    border-radius: 13px;
    padding: 25px;
    height: 100%;
    border: 1px solid #E3E3EF;
}
.profile-img {
    width: 150px;
    height: auto;
    border-radius: 100%;
    object-fit: cover;
}
h6.username {
    color: #130E2B;
    font-weight: 500;
    font-size: 24px;
}
p.email-text {
    font-size: 16px;
    font-weight: 400;
    color: #6B7280;
}
.menu a {
    display: flex;
    align-items: center;
    padding: 10px 15px;
    border-radius: 8px;
    color: #6B7280;
    text-decoration: none;
    margin-bottom: 8px;
    font-size: 14px;
}
.menu a.active {
    background: #33319A;
    color: #fff;
    font-weight: 400;
    font-size: 14px;
}
.menu a:hover {
    background: #05B942;
    color: #fff;
}

</style>
