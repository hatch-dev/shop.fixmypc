<template>
  <h5 class="toast-message" :class="{'error-toast': isError}">
    <span v-if="isError">{{ $t('error.err') }}!!!</span>
    {{message}}</h5>
</template>

<script>
  export default {
    name: 'ToastMessage',
    data() {
      return {
        timing: 4000
      }
    },
    props: {
      message: {
        type: String,
        default: ''
      },
      isError: {
        type: Boolean,
        default: false
      },
    },
    directives: {},
    computed: {},
    methods: {},
    mounted() {
      const self = this
      setInterval(function () {
        self.$emit('hide')
      }, this.timing)
    }
  }
</script>

<style lang="stylus">
  @import '~/assets/styles/toast-message.styl'
</style>
