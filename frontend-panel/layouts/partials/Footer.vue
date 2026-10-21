<template>
  <div class="footer-section">
    <div class="container-fluid">
      <subscription />

      <div class="row footer-grid">
        <!-- Brand -->
        <div class="col-lg-4 col-12 footer-col footer-col--brand">
          <nuxt-link to="/" class="footer-brand">
            <img
              :src="imageURL({ image: site_setting.footer_logo })"
              class="footer-image"
              :alt="$t('footer.siteLogo')"
            >
          </nuxt-link>

          <h6 class="footer-brand-title">{{ $t('ui.footerUi.brandTitle') }}</h6>

          <p class="footer-open">
            <span class="footer-open-dot" aria-hidden="true"></span>
            {{ $t('ui.footerUi.open7Days') }}
          </p>

          <p class="footer-desc">
            {{ site_setting?.footer_description || $t('ui.footerUi.defaultDescription') }}
          </p>

        </div>

        <!-- About -->
        <div class="col-lg-2 col-4 footer-col footer-col--about footer-links">
          <h6 class="footer-menu-title">{{ $t('footer.about') }}</h6>

          <nuxt-link
            v-for="(item, i) in about"
            :key="i"
            :to="pageLink(item)"
          >
            {{ item.title }}
          </nuxt-link>
        </div>

        <!-- Services -->
        <div class="col-lg-2 col-4 footer-col footer-col--services footer-links">
          <h6 class="footer-menu-title">{{ $t('footer.services') }}</h6>

          <nuxt-link
            v-for="(item, i) in services"
            :key="i"
            :to="pageLink(item)"
          >
            {{ item.title }}
          </nuxt-link>
        </div>

        <!-- Contact + social -->
        <div class="col-lg-4 col-4 footer-col footer-col--contact">
          <h6 class="footer-menu-title">{{ $t('header.contact') }}</h6>

          <ul class="footer-contact">
            <li v-if="setting?.email">
              <a :href="`mailto:${setting.email}`" :title="setting.email">
                <span class="footer-contact-ico"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
                <span class="footer-contact-value">{{ setting.email }}</span>
                <span class="footer-contact-label">{{ $t('footerContact.email') }}</span>
              </a>
            </li>
            <li v-if="setting?.phone">
              <a :href="`tel:${setting.phone}`" :title="setting.phone">
                <span class="footer-contact-ico"><i class="fa-solid fa-phone" aria-hidden="true"></i></span>
                <span class="footer-contact-value">{{ setting.phone }}</span>
                <span class="footer-contact-label">{{ $t('footerContact.call') }}</span>
              </a>
            </li>
            <li v-if="setting?.whatsapp">
              <a :href="`https://wa.me/${setting.whatsapp}`" target="_blank" rel="noopener" :title="setting.whatsapp">
                <span class="footer-contact-ico"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></span>
                <span class="footer-contact-value">{{ setting.whatsapp }}</span>
                <span class="footer-contact-label">{{ $t('footerContact.whatsapp') }}</span>
              </a>
            </li>
          </ul>

          <div class="social-icons" v-if="social && social.length">
            <a
              v-for="(item, i) in social"
              :key="i"
              :href="item.link"
              target="_blank"
              rel="noopener"
              class="social-icon"
              :title="item.title"
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

      <!-- Copyright, payment marks and credit share one rule -->
      <div class="footer-bottom">
        <div class="footer-copy">{{ $t('ui.footerUi.copyright', { year: 2026, brand: 'FixMyPc' }) }}</div>

        <div class="payment-icons" v-if="payment && payment.length">
          <a
            v-for="(item, i) in payment"
            :key="i"
            :href="item.link"
            target="_blank"
            rel="noopener"
            class="payment-chip"
            :title="item.title"
          >
            <lazy-image
              :data-src="imageURL(item)"
              :alt="item.title"
              width="40"
              class="payment-img-fluid"
            />
          </a>
        </div>

        <div class="footer-credit">{{ $t('ui.footerUi.designedBy', { name: 'exyctech' }) }}</div>
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
      ...mapGetters('common', ['site_setting', 'setting', 'categories', 'services', 'about', 'payment', 'social'])
    },
  }
</script>
<style scoped>
.footer-section {
  background: #2A2878;
  color: #fff;
  padding-top: 34px;
}

.footer-grid {
  margin-top: 34px;
}

.footer-col {
  margin-bottom: 30px;
}

/* ─────────── brand ─────────── */
.footer-brand {
  display: inline-block;
  margin-bottom: 14px;
}

.footer-image {
  height: 52px;
  object-fit: contain;
  width: auto;
}

.footer-brand-title {
  color: #fff;
  font-size: 17px;
  font-weight: 700;
  margin-bottom: 10px;
}

/* status pill rather than a line of oversized text */
.footer-open {
  align-items: center;
  background: rgba(5, 185, 66, .16);
  border: 1px solid rgba(5, 185, 66, .45);
  border-radius: 999px;
  color: #6DE89B;
  display: inline-flex;
  font-size: 13px;
  font-weight: 700;
  gap: 8px;
  line-height: 1.4;
  margin-bottom: 14px;
  padding: 5px 13px;
}

.footer-open-dot {
  background: #05B942;
  border-radius: 50%;
  box-shadow: 0 0 0 3px rgba(5, 185, 66, .25);
  flex: 0 0 auto;
  height: 7px;
  width: 7px;
}

.footer-desc {
  color: rgba(255, 255, 255, .7);
  font-size: 13.5px;
  line-height: 1.65;
  margin: 0;
  max-width: 42ch;
}

/* ─────────── section headings ─────────── */
.footer-menu-title {
  color: rgba(255, 255, 255, .55);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: .12em;
  margin-bottom: 16px;
  text-transform: uppercase;
}

/* ─────────── link lists ─────────── */
.footer-links a {
  color: rgba(255, 255, 255, .72);
  display: block;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.5;
  padding: 6px 0;
  text-decoration: none;
  transition: color .15s ease, transform .15s ease;
  width: fit-content;
}

.footer-links a:hover {
  color: #fff;
  transform: translateX(3px);
}

/* ─────────── contact ─────────── */
.footer-contact {
  display: grid;
  gap: 10px;
  list-style: none;
  margin: 0;
  padding: 0;
}

/* the address shows wherever there is room for it */
.footer-contact-label {
  display: none;
}

.footer-contact a {
  align-items: center;
  color: rgba(255, 255, 255, .82);
  display: inline-flex;
  font-size: 14px;
  font-weight: 500;
  gap: 12px;
  text-decoration: none;
  transition: color .15s ease;
  width: fit-content;
}

.footer-contact a:hover {
  color: #fff;
}

.footer-contact-ico {
  align-items: center;
  background: rgba(255, 255, 255, .08);
  border: 1px solid rgba(255, 255, 255, .12);
  border-radius: 10px;
  color: #6DE89B;
  display: flex;
  flex: 0 0 auto;
  font-size: 13px;
  height: 34px;
  justify-content: center;
  transition: background .15s ease, border-color .15s ease;
  width: 34px;
}

.footer-contact a:hover .footer-contact-ico {
  background: rgba(5, 185, 66, .18);
  border-color: rgba(5, 185, 66, .45);
}

/* ─────────── social ─────────── */
.social-icons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 20px;
}

a.social-icon {
  align-items: center;
  border: 1px solid rgba(255, 255, 255, .35);
  border-radius: 50%;
  display: flex;
  height: 38px;
  justify-content: center;
  padding: 0;
  transition: background .15s ease, border-color .15s ease, transform .15s ease;
  width: 38px;
}

a.social-icon:hover {
  background-color: #05B942;
  border-color: #05B942;
  transform: translateY(-2px);
}

img.lazy-img.social-img-fluid {
  height: 16px;
  object-fit: contain;
  width: 16px;
}

/* ─────────── bottom bar ─────────── */
.footer-bottom {
  align-items: center;
  border-top: 1px solid rgba(255, 255, 255, .14);
  color: rgba(255, 255, 255, .6);
  display: flex;
  flex-wrap: wrap;
  font-size: 13px;
  gap: 14px 22px;
  justify-content: space-between;
  margin-top: 6px;
  padding: 20px 0;
}

/* payment marks belong beside the copyright, where people look for them */
/*
 * Six chips at roughly 66px plus gaps is 422px, which does not fit a 360px
 * phone -- and with nowrap it did not try to. It pushed the whole document
 * 31px wider than the screen, so every page of the site scrolled sideways.
 * They wrap onto a second line instead.
 */
.payment-icons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  order: 2;
}

.payment-chip {
  align-items: center;
  background: #fff;
  border-radius: 8px;
  display: flex;
  flex: 0 0 auto;
  height: 30px;
  justify-content: center;
  min-width: 0;
  padding: 0 8px;
  transition: transform .15s ease, box-shadow .15s ease;
}

.payment-chip:hover {
  box-shadow: 0 6px 16px rgba(0, 0, 0, .22);
  transform: translateY(-2px);
}

.payment-img-fluid {
  height: 16px;
  max-width: 100%;
  object-fit: contain;
  width: auto;
}

.footer-copy {
  order: 1;
}

.footer-credit {
  order: 3;
}

/* ─────────── responsive ─────────── */
@media (max-width: 991px) {
  /* three link columns share the row; the contact column is too narrow for
     an address, so each channel shows its name and the value moves to the
     link title. */
  .footer-contact-value {
    display: none;
  }

  .footer-contact-label {
    display: inline;
  }

  .footer-contact {
    gap: 2px;
  }

  .footer-contact a {
    font-size: 14px;
    gap: 9px;
    padding: 6px 0;
  }

  /* no room for the tile at this width - the glyph alone carries it */
  .footer-contact-ico {
    background: none;
    border: 0;
    height: auto;
    width: 16px;
  }

  /* small rounded chips. flex: 1 1 0 with a max width lets the four of them
     divide whatever the column has, so they always stay on one line. */
  .social-icons {
    flex-wrap: nowrap;
    gap: 5px;
    margin-top: 16px;
  }

  a.social-icon {
    aspect-ratio: 1;
    border: 1px solid rgba(255, 255, 255, .28);
    border-radius: 8px;
    flex: 1 1 0;
    height: auto;
    max-width: 26px;
    min-width: 0;
    padding: 0;
    width: auto;
  }

  a.social-icon:hover {
    transform: none;
  }

  img.lazy-img.social-img-fluid {
    height: 13px;
    max-width: 100%;
    width: 13px;
  }

  /* the brand block was four stacked items using a lot of height for little.
     The logo and the opening-hours pill share the first line instead, and a
     hairline separates the block from the link columns. */
  .footer-col--brand {
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    column-gap: 14px;
    display: grid;
    grid-template-columns: auto 1fr;
    padding-bottom: 20px;
  }

  .footer-brand {
    grid-column: 1;
    grid-row: 1;
    margin-bottom: 0;
  }

  .footer-col--brand .footer-open {
    grid-column: 2;
    grid-row: 1;
    justify-self: start;
    margin-bottom: 0;
  }

  .footer-brand-title {
    font-size: 15px;
    grid-column: 1 / -1;
    grid-row: 2;
    margin: 14px 0 6px;
  }

  .footer-col--brand .footer-desc {
    grid-column: 1 / -1;
    grid-row: 3;
    font-size: 13px;
  }

  .footer-image {
    height: 40px;
  }

  .footer-grid {
    margin-top: 26px;
  }

  .footer-col {
    margin-bottom: 24px;
  }

  .footer-menu-title {
    margin-bottom: 12px;
  }

  /* copyright and credit pair up, payment marks take their own line */
  .footer-bottom {
    justify-content: center;
    text-align: center;
  }

  .payment-icons {
    flex-basis: 100%;
    justify-content: center;
    order: 1;
  }

  .footer-copy {
    order: 2;
  }

  .footer-credit {
    order: 3;
  }
}

@media (max-width: 767px) {
  .footer-section {
    padding-top: 22px;
  }

  .footer-grid {
    margin-top: 22px;
  }

  .footer-image {
    height: 44px;
  }

  .footer-desc {
    max-width: none;
  }

  .footer-links a {
    font-size: 13.5px;
  }

  .footer-contact a {
    font-size: 13.5px;
  }

  .payment-chip {
    height: 28px;
    padding: 0 6px;
  }

  .footer-bottom {
    gap: 12px;
    padding: 18px 0;
  }
}
</style>
