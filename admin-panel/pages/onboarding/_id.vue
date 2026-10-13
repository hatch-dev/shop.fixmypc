<template>

  <div class="onboarding-form">

    <h2 class="mb-20">
      Onboarding
    </h2>

    <div class="input-wrapper">

      <label>Upload Images</label>

      <input
        type="file"
        multiple
        accept="image/*"
        @change="handleImages"
      >

    </div>

    <div class="preview-wrapper">

      <div
        v-for="(img, index) in images"
        :key="index"
        class="preview-card"
      >

        <img
          :src="img.preview || getImageURL(img)"
          alt=""
        >

        <button
          class="delete-btn"
          @click="removeImage(index)"
        >
          Remove
        </button>

      </div>

    </div>

    <button
      class="lite-btn mt-20"
      @click="saveOnboarding"
    >
      Save
    </button>

  </div>

</template>

<script>

import util from '~/mixin/util'

export default {

  name: "onboarding-form",

  middleware: ['common-middleware', 'auth'],

  mixins: [util],

  data() {
    return {
      images: []
    }
  },

  methods: {

    handleImages(e) {

      const files = Array.from(e.target.files)

      files.forEach(file => {

        this.images.push(
            Object.assign(file, {
                preview: URL.createObjectURL(file)
            })
        )
      })
    },

    removeImage(index) {
      this.images.splice(index, 1)
    },

    async saveOnboarding() {
        try {
            const formData = new FormData()
            this.images.forEach((file, index) => {
                // Existing image
                if(typeof file === 'string') {
                    formData.append(
                        `existing_images[${index}]`,
                        file
                    )
                } else {
                    // New uploaded file
                    formData.append(
                        `images[${index}]`,
                        file
                    )
                }
            })

            const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
            await this.$axios.post(
                `${baseUrl}api/admin/onboarding`,
                formData,
                {
                    headers: {
                    'Content-Type': 'multipart/form-data'
                    }
                }
            )

            this.$router.push('/onboarding')

        } catch (e) {
            console.log(e)
        }
    },
    async getOnboarding() {

      try {

        const id = this.$route.params.id

        if(id !== 'create') {
          const baseUrl = process.env.apiBase || 'https://shop.fixmypc.ie/'
          const {data} = await this.$axios.get(`${baseUrl}api/admin/onboarding/${id}`)

          this.images = data.data.images || []
        }

      } catch (e) {
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

.onboarding-form
  padding 20px

.preview-wrapper
  display flex
  gap 20px
  flex-wrap wrap
  margin-top 20px

.preview-card
  width 180px

.preview-card img
  width 100%
  height 180px
  object-fit contain
  border-radius 8px

.delete-btn
  margin-top 10px
  width 100%

</style>