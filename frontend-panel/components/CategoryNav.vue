<template>
  <div class="cat-nav" v-if="categories && categories.length">
    <div class="cat-nav-track">
      <button
        v-for="cat in visibleCategories"
        :key="cat.id"
        type="button"
        class="cat-nav-item"
        :class="{ 'is-on': isOn(cat), 'is-open': megaSlug === cat.slug }"
        :title="cat.title"
        :aria-expanded="megaSlug === cat.slug ? 'true' : 'false'"
        @click.prevent.stop="$emit('toggle-mega', cat)"
      >
        <span class="cat-nav-icon">
          <img
            :src="getImageURL(cat.image)"
            :alt="cat.title"
            width="20"
            height="20"
            loading="lazy"
          >
        </span>
        <span class="cat-nav-label">{{ cat.title }}</span>
        <span class="cat-nav-caret">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
          </svg>
        </span>
      </button>
    </div>

    <button
      v-if="overflowCategories.length"
      type="button"
      class="cat-nav-item cat-nav-more"
      :class="{ 'is-on': megaSlug === '__more', 'is-open': megaSlug === '__more' }"
      :aria-expanded="megaSlug === '__more' ? 'true' : 'false'"
      @click.prevent.stop="$emit('toggle-mega', { slug: '__more' })"
    >
      <span class="cat-nav-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="3.2" />
          <path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3" />
        </svg>
      </span>
      <span class="cat-nav-label">{{ moreLabel }}</span>
      <span class="cat-nav-caret">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
          stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="m6 9 6 6 6-6" />
        </svg>
      </span>
    </button>
  </div>
</template>

<script>
  import util from '~/mixin/util'

  export default {
    name: 'CategoryNav',
    mixins: [util],
    props: {
      categories: {
        type: Array,
        default() {
          return []
        },
      },
      // slug of the category whose mega panel is currently open
      megaSlug: {
        type: String,
        default: '',
      },
      // how many tabs to show before the rest fold into "More" (0 = all)
      limit: {
        type: Number,
        default: 0,
      },
      moreLabel: {
        type: String,
        default: 'More',
      },
    },
    computed: {
      visibleCategories() {
        const all = this.categories || []
        return this.limit > 0 ? all.slice(0, this.limit) : all
      },
      overflowCategories() {
        const all = this.categories || []
        return this.limit > 0 ? all.slice(this.limit) : []
      },
    },
    methods: {
      isActive(cat) {
        return this.$route?.path === `/all/${cat.slug}`
      },
      // while a panel is open it owns the underline, so the tab for the
      // page you are on does not light up a second one
      isOn(cat) {
        return this.megaSlug ? this.megaSlug === cat.slug : this.isActive(cat)
      },
    },
  }
</script>

<style scoped>
  /* Scoped to this component - the global `.nav-links a` rules in
     header.styl do not reach these links. */
  .cat-nav {
    align-items: stretch;
    display: flex;
    max-width: 100%;
    min-width: 0;
  }

  .cat-nav-track {
    align-items: stretch;
    display: flex;
    flex: 1 1 auto;
    flex-wrap: nowrap;
    gap: 2px;
    min-width: 0;
    /* no arrows: on a narrow window the row simply scrolls, and every
       category is still reachable through the "show all" tab beside it */
    -ms-overflow-style: none;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
  }

  .cat-nav-track::-webkit-scrollbar {
    display: none;
  }

  .cat-nav-item {
    align-items: center;
    background: none;
    border: 0;
    border-radius: 0;
    color: #3A3C6E;
    cursor: pointer;
    font-family: inherit;
    height: auto;
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 14px;
    font-weight: 600;
    gap: 8px;
    line-height: 1;
    margin: 0;
    padding: 0 14px;
    position: relative;
    text-decoration: none;
    transition: color .15s ease;
    white-space: nowrap;
  }

  /* the underline is always laid out, so nothing shifts when it lights up */
  .cat-nav-item::after {
    background: transparent;
    border-radius: 3px 3px 0 0;
    bottom: 0;
    content: "";
    height: 3px;
    left: 12px;
    position: absolute;
    right: 12px;
    transition: background .15s ease;
  }

  .cat-nav-item:hover {
    color: #33319A;
  }

  .cat-nav-item.is-on {
    color: #33319A;
  }

  .cat-nav-item.is-on::after {
    background: #33319A;
  }

  .cat-nav-icon {
    align-items: center;
    background: none;
    border-radius: 0;
    display: flex;
    flex-shrink: 0;
    height: 18px;
    justify-content: center;
    opacity: .85;
    overflow: hidden;
    width: 18px;
  }

  .cat-nav-icon img {
    height: 18px;
    object-fit: contain;
    width: 18px;
  }

  .cat-nav-more {
    flex: 0 0 auto;
  }

  .cat-nav-caret {
    align-items: center;
    display: flex;
    flex-shrink: 0;
    height: 100%;
    margin-left: -2px;
    opacity: .5;
    padding: 0 2px;
    transition: transform .18s ease, opacity .15s ease;
  }

  .cat-nav-caret:hover {
    opacity: 1;
  }

  .cat-nav-item.is-open .cat-nav-caret {
    opacity: 1;
    transform: rotate(180deg);
  }

  .fx-nav-row .cat-nav,
  .fx-nav-row .cat-nav-track {
    height: 100%;
  }

  /* the slide-out mobile menu stacks these vertically, where a flat tab has
     nothing to sit on - keep the chip treatment there */
  .mobile-menu-cs .cat-nav {
    display: block;
  }

  .mobile-menu-cs .cat-nav-track {
    flex-wrap: wrap;
    gap: 8px;
    overflow: visible;
  }

  .mobile-menu-cs .cat-nav-item {
    background: #F5F6FC;
    border: 1px solid #E7E8F4;
    border-radius: 100px;
    padding: 6px 15px 6px 6px;
  }

  .mobile-menu-cs .cat-nav-item::after {
    content: none;
  }

  .mobile-menu-cs .cat-nav-item.is-on {
    background: #33319A;
    border-color: #33319A;
    color: #fff;
  }

  .mobile-menu-cs .cat-nav-icon {
    background: #fff;
    border-radius: 50%;
    height: 28px;
    width: 28px;
  }

  @media only screen and (max-width: 992px) {
    .cat-nav-item {
      font-size: 13px;
      padding: 0 10px;
    }

    .cat-nav-icon,
    .cat-nav-icon img {
      height: 16px;
      width: 16px;
    }
  }
</style>
