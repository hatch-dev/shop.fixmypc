<template>
  <div class="login-container">
    <img
      src="~/assets/images/login-bg.png"
    />
    <Nuxt />
    <transition name="fade" mode="out-in">
      <toast-message
        v-if="toastMessageStatus"
        :is-error="toastError"
        :message="toastMessage"
        @hide="hideToast"
      />
    </transition>
  </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import ToastMessage from '~/components/ToastMessage.vue'
  export default {
    name: "login-layout",
    components: {
      ToastMessage
    },
    watch:{
    },
    head() {
      // The page language drives CSS text-transform: a Turkish "i" only uppercases to "İ" under lang="tr"
      return { htmlAttrs: { lang: this.$i18n?.locale || 'en' } }
    },
    computed:{
      ...mapGetters('ui', ['toastMessage', 'toastError', 'toastMessageStatus']),
    },
    methods: {
      ...mapActions('ui', ['hideToast']),
    }
  }
</script>
