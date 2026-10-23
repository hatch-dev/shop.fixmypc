<template>
    <div class="onboarding-page">
        <div class="header">
            <h2>{{ $t('pages.onboarding.title') }}</h2>
        </div>
        <div class="empty-box">
            <button
            class="lite-btn"
            @click="createOnboarding"
            >
            {{ $t('pages.onboarding.createOnboarding') }}
            </button>
        </div>
    </div>
</template>

<script>
import util from '~/mixin/util'

export default {

  name: "onboarding",

  middleware: ['common-middleware', 'auth'],

  mixins: [util],

  data() {
    return {
      onboarding: null
    }
  },

  methods: {

    createOnboarding() {
      this.$router.push('/onboarding/create')
    },

    editOnboarding() {
      this.$router.push(`/onboarding/${this.onboarding.id}`)
    },

    async getOnboarding() {

      try {
        const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
        const {data} = await this.$axios.get(`${baseUrl}api/admin/onboarding`)

        if(data.data) {
            this.onboarding = data.data

            this.$router.push(
                `/onboarding/${data.data.id}`
            )

            } else {
                this.onboarding = null
            }

      } catch (e) {
        this.onboarding = null
        console.log(e)
      }
    }
  },

  mounted() {
    this.getOnboarding()
  }
}
</script>

<style lang="stylus">

.onboarding-page
  padding 20px

.header
  margin-bottom 20px

.onboarding-card
  width 250px

.onboarding-card img
  width 100%
  border-radius 8px

.empty-box
  width 250px
  height 250px
  border 2px dashed #d1d5db
  display flex
  justify-content center
  align-items center
  border-radius 8px

</style>