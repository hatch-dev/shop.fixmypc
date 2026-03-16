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
    computed:{
      ...mapGetters('ui', ['toastMessage', 'toastError', 'toastMessageStatus']),
    },
    methods: {
      ...mapActions('ui', ['hideToast']),
    }
  }
</script>
