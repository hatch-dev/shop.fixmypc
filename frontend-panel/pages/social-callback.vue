<template>
  <client-only>
  <div class="container">
    <section class="home-section">
      <h3>{{ $t('razorpayCallback.wait') }}...</h3>
    </section>
  </div>
  </client-only>
</template>
<script>
  import global from '~/mixin/global'

  /*
   * Google / Facebook sign-in is switched off (UsersController::
   * handleProviderCallback), so nothing legitimate lands here any more.
   *
   * This page used to take ?token= from the address bar and sign the browser
   * in with it - which also meant a link carrying somebody else's token signed
   * a visitor into that account. It now ignores the query and sends them to
   * the sign-in screen. Whatever replaces social sign-in must not bring the
   * token back into the URL.
   */
  export default {
    middleware: ['common-middleware', 'non-logged-in'],
    layout: "social-login",
    mixins: [global],
    mounted() {
      this.$router.replace('/login')
    }
  }
</script>

<style>

</style>
