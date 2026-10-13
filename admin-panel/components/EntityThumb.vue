<template>
  <span
    class="entity-thumb"
    :class="{ 'entity-thumb--rounded': rounded }"
    :style="boxStyle"
  >
    <img
      v-if="src"
      :key="src"
      :src="src"
      :alt="title || ''"
      class="entity-thumb__img"
      loading="lazy"
      decoding="async"
      @error="attempt++"
    >
    <span
      v-else
      class="entity-thumb__fallback"
      :style="{ background: tone.bg, color: tone.fg }"
      :title="title || ''"
    >{{ initials }}</span>
  </span>
</template>

<script>
  import util from '~/mixin/util'

  /**
   * Thumbnail for a category, brand or product.
   *
   * Not LazyImage: that component declares a `lazySrc` prop but every caller
   * passes `data-src`, so the URL only reaches the <img> as a fall-through
   * attribute, and its error path swaps in a default image that has to be
   * fetched over the network before anything is drawn. Around a fifth of the
   * catalogue has no image file on disk at all, so those rows sat as empty
   * grey boxes. Draw a coloured initial instead — it needs no request, it
   * cannot fail, and it still tells the rows apart at a glance.
   */
  export default {
    name: 'EntityThumb',
    mixins: [util],
    props: {
      image: {
        type: String,
        default: null
      },
      /**
       * A second filename to try when `image` fails to load. Part of this
       * catalogue references image files that are not on disk, and for some of
       * those products a gallery row still names one that is.
       */
      fallbackImage: {
        type: String,
        default: null
      },
      title: {
        type: String,
        default: ''
      },
      size: {
        type: String,
        default: '40px'
      },
      rounded: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        // How many of the candidate files have already failed to load.
        attempt: 0
      }
    },
    watch: {
      image() {
        this.attempt = 0
      },
      fallbackImage() {
        this.attempt = 0
      }
    },
    computed: {
      /** The files worth trying, in order, with duplicates removed. */
      candidates() {
        return [this.image, this.fallbackImage]
          .filter(Boolean)
          .filter((name, i, all) => all.indexOf(name) === i)
      },
      failed() {
        return this.attempt >= this.candidates.length
      },
      src() {
        const name = this.candidates[this.attempt]
        return name ? this.getImageURL(name) : null
      },
      boxStyle() {
        return { width: this.size, height: this.size }
      },
      initials() {
        const words = (this.title || '')
          .replace(/[^\p{L}\p{N} ]/gu, ' ')
          .split(' ')
          .filter(Boolean)

        if (!words.length) return '?'
        if (words.length === 1) return words[0].slice(0, 2).toUpperCase()
        return (words[0][0] + words[1][0]).toUpperCase()
      },
      // Stable colour per title, so the same brand keeps the same chip colour
      // on every render rather than flickering between loads.
      tone() {
        const palette = [
          { bg: '#EEF2FF', fg: '#3730A3' },
          { bg: '#ECFDF5', fg: '#047857' },
          { bg: '#FEF3C7', fg: '#92400E' },
          { bg: '#FCE7F3', fg: '#9D174D' },
          { bg: '#E0F2FE', fg: '#075985' },
          { bg: '#F3E8FF', fg: '#6B21A8' },
          { bg: '#FFE4E6', fg: '#9F1239' },
          { bg: '#F1F5F9', fg: '#334155' }
        ]

        const key = this.title || ''
        let hash = 0
        for (let i = 0; i < key.length; i++) {
          hash = (hash * 31 + key.charCodeAt(i)) % 100000
        }
        return palette[hash % palette.length]
      }
    }
  }
</script>

<style lang="stylus">
  .entity-thumb
    flex none
    display inline-flex
    align-items center
    justify-content center
    overflow hidden
    border-radius 8px
    background var(--hover-color)
    border 1px solid var(--border-color)

    &--rounded
      border-radius 50%

    .entity-thumb__img
      width 100%
      height 100%
      object-fit contain
      padding 3px
      background #FFFFFF

    .entity-thumb__fallback
      width 100%
      height 100%
      display flex
      align-items center
      justify-content center
      font-size 13px
      font-weight 700
      letter-spacing .02em
      user-select none
</style>
