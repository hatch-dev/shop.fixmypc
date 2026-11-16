<template>
    <div
      class="flex gap-2 start mt-15 mt-sm social-share"
    >
    <span
      class="mr-10 color-lite hide-sm"
    >
      {{ $t('socialShare.share') }}:
    </span>
      <button
        type="button"
        class="share-link-btn"
        :disabled="sharing"
        :aria-label="$t('socialShare.shareLink')"
        @click="shareLink"
      >
        <svg class="share-link-icon" width="30" height="30" viewBox="0 0 30 30" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <circle cx="15" cy="15" r="15" fill="currentColor"/>
        <path d="M13.6 16.4a3.2 3.2 0 0 0 4.5 0l2.6-2.6a3.2 3.2 0 0 0-4.5-4.5l-1 1" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M16.4 13.6a3.2 3.2 0 0 0-4.5 0l-2.6 2.6a3.2 3.2 0 0 0 4.5 4.5l1-1" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>

        <span>
        {{ $t('socialShare.shareLink') }}
      </span>
      </button>
    </div>
</template>

<script>
  import {mapActions} from 'vuex'
  import util from '~/mixin/util'

  export default {
    data() {
      return {
        sharing: false
      }
    },
    props: {
      product: {
        type: Object
      }
    },
    components: {},
    mixins: [util],
    computed: {
      // The product's own page, not the current route: this component is also
      // shown in the image pop-up, which can open outside the product page.
      productURL() {
        const baseUrl = window.location.origin
        if (this.product?.slug && this.product?.id) {
          return baseUrl + this.productLink(this.product)
        }
        return this.$route ? baseUrl + this.$route.path : baseUrl
      },
      shareTitle() {
        return this.product?.title || this.product?.meta_title || document.title
      }
    },
    methods: {
      async shareLink() {
        if (this.sharing) return
        this.sharing = true
        try {
          if (navigator.share) {
            try {
              await navigator.share({title: this.shareTitle, url: this.productURL})
              return
            } catch (e) {
              // Closing the share sheet is not an error.
              if (e?.name === 'AbortError') return
              // Anything else (e.g. sharing blocked): copy the link instead.
            }
          }
          if (await this.copyToClipboard(this.productURL)) {
            this.setToastMessage(this.$t('socialShare.linkCopied'))
          } else {
            this.setToastError(this.$t('socialShare.copyFailed'))
          }
        } finally {
          this.sharing = false
        }
      },
      async copyToClipboard(text) {
        try {
          await navigator.clipboard.writeText(text)
          return true
        } catch (e) {
          // Older browsers and non-https origins have no async clipboard.
          const t = document.createElement('textarea')
          t.value = text
          t.setAttribute('readonly', '')
          t.style.position = 'fixed'
          t.style.opacity = '0'
          document.body.appendChild(t)
          t.select()
          let copied = false
          try {
            copied = document.execCommand('copy')
          } catch (err) {
            copied = false
          }
          t.remove()
          return copied
        }
      },
      ...mapActions('common', ['setToastMessage', 'setToastError'])
    },
    mounted() {
    }
  }
</script>

<style>

</style>
