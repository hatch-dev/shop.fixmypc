<template>
  <div class="container-fluid mtb-20 mtb-sm-15">

    <div class="flex error-wrapper reverse">

      <div class="img-wrap mb-sm-20 mlr-xs-auto">
        <img
          src="~/assets/images/error.png"
          alt="Error image"
          height="50"
          width="50"
        >
      </div>

      <div class="mr-50 mr-sm">

        <h1 class="big-title">{{ $t('category.sor') }}</h1>
        <h2 class=" mb-15">{{ message }}</h2>
        <div
          class="mb-30 mb-sm-15 centered"
        >
          <button
            class="outline-btn"
            @click.prevent="reload"
          >
            {{ $t('category.tryLoad') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>

  export default {
    layout (context) {
      if(!context.$auth.user) {
        return 'login-layout'
      }
      return 'default'
    },
    head() {
      return {
        title: this.message
      }
    },
    props: {
      error: {
        type: Object
      }
    },
    mixins: [],
    computed: {
      statusCode() {
        return this.error && this.error.statusCode || 500
      },
      message() {
        return this.error.message
      },
    },
    methods:{
      reload(){
        window.location.reload()
      }
    },
    mounted() {
    }
  }
</script>
<style lang="stylus" scoped>




</style>
