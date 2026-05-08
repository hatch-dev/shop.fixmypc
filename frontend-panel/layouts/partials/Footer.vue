<template>
  <div class="footer-section">
    <div class="container-fluid">
      <subscription />
      <hr class="border-light opacity-25">
      <div class="row mt-4">
        <div class="col-lg-4 mb-4">
          <nuxt-link to="/">
            <img
              :src="imageURL({ image: site_setting.footer_logo })"
              class="mb-2 footer-image"
            >
          </nuxt-link>

          <h6 class="footer-menu-title">Fanatic Customer Service.</h6>
          <p class="text-success footer-open">Open 7 days</p>
          <p class="small text-light">
            {{ site_setting?.footer_description || 'We aim to get your device back within 24 hours.' }}
          </p>
        </div>
        <!-- Quick Links (About) -->
        <div class="col-lg-3 mb-4 footer-links">
          <h6 class="footer-menu-title mb-2">{{ $t('footer.about') }}</h6>

          <nuxt-link
            v-for="(item, i) in about"
            :key="i"
            :to="pageLink(item)"
          >
            {{ item.title }}
          </nuxt-link>
        </div>

        <!-- Services -->
        <div class="col-lg-3 mb-4 footer-links">
          <h6 class="footer-menu-title mb-2">{{ $t('footer.services') }}</h6>

          <nuxt-link
            v-for="(item, i) in services"
            :key="i"
            :to="pageLink(item)"
          >
            {{ item.title }}
          </nuxt-link>
        </div>

        <div class="col-lg-2 mb-4">

          <!-- Payment -->
          <h6 class="footer-menu-title mb-2">{{ $t('footer.payment') }}</h6>
          <div class="d-flex gap-2 flex-wrap">
            <a
              v-for="(item, i) in payment"
              :key="i"
              :href="item.link"
              target="_blank"
            >
              <lazy-image
                :data-src="imageURL(item)"
                :alt="item.title"
                width="40"
                class="payment-img-fluid"
              />
            </a>
          </div>

          <!-- Social -->
          <h6 class="mt-3 footer-menu-title mb-2">{{ $t('footer.social') }}</h6>
          <div class="social-icons d-flex gap-2">
            <a
              v-for="(item, i) in social"
              :key="i"
              :href="item.link"
              target="_blank"
              class="social-icon"
            >
              <lazy-image
                :data-src="imageURL(item)"
                :alt="item.title"
                width="24"
                class="social-img-fluid"
              />
            </a>
          </div>
        </div>
      </div>
      <div class="footer-bottom d-flex justify-content-center flex-wrap">
        <div>© 2026 FixMyPc. All Rights Reserved</div>
        <div class="ms-3">Designed by exyctech</div>
      </div>
    </div>
  </div>

</template>

<script>
  import util from '~/mixin/util'
  import {mapGetters} from 'vuex'
  import LazyImage from "../../components/LazyImage";
  import Subscription from "../../components/Subscription";
  import TreeNode from "../../components/TreeNode";
  import FooterTreeNode from "../../components/FooterTreeNode";
  export default {
    data() {
      return {
      }
    },
    mixins: [util],
    components: {FooterTreeNode, TreeNode, Subscription, LazyImage},
    computed: {
      ...mapGetters('common', ['site_setting', 'categories', 'services', 'about', 'payment', 'social'])
    },
  }
</script>
<style scoped>
.footer-section {
  background: #3b33a5;
  color: #fff;
  padding-top: 30px;
}

.footer-links a {
  display: block;
  color: #ddd;
  text-decoration: none;
  margin-bottom: 6px;
  font-size: 16px;
  font-weight: 500;
  line-height: 22px;
}

.footer-links a:hover {
  color: #fff;
}

/* Social Icons */
.social-icons i {
  width: 35px;
  height: 35px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(255,255,255,0.1);
  margin-right: 8px;
  cursor: pointer;
}

.social-icons i:hover {
  background: #22c55e;
}

/* Bottom */
.footer-bottom {
  border-top: 1px solid rgba(255,255,255,0.2);
  margin-top: 30px;
  padding: 15px 0;
  font-size: 14px;
}

.footer-menu-title{
  color: #fff;
  font-size: 24px;
  font-weight: 600;
}

.footer-open {
    color: #05B942;
    font-weight: 700;
    font-size: 26px;
    text-decoration: underline;
}

.payment-img-fluid{
  width: 35px;
}

a.social-icon {
    border: 1px solid #fff;
    border-radius: 100%;
    padding: 4px 9px 4px 9px;
}

img.lazy-img.social-img-fluid {
    width: 15px;
    height: 16px;
    object-fit: contain;
}

a.social-icon:hover {
    background-color: #05B942;
    border-color: #05B942;
}

@media (max-width: 767px) {

  .footer-menu-title{
    font-size: 20px ;
  }

  .footer-open{
    font-size: 20px;
  }

  .footer-image{
    width: 150px;
  }
}
</style>
