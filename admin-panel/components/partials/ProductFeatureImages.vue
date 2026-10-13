<template>
  <div class="p-20 p-sm-15 pos-rel">

    <div
      v-if="deleting"
      class="spinner-wrapper"
    >
      <spinner
        :radius="60"
        color="primary"
        class="mr-15"
      />
    </div>

    <input
      class="d-none"
      type="file"
      accept="image/*"
      multiple
      @change="uploadFeatureImages"
      ref="filesInput"
    >

    <error-formatter type="feature_image" />

    <div class="product-images dply-felx mb-15 gap-15">

      <p
        v-if="!featureImages.length"
        class="mlr-5"
      >
        No Feature Images
      </p>

      <div
        v-for="(v, index) in featureImages"
        :key="index"
        class="feature-image-box"
      >
        <img
          :src="getImageURL(v.image)"
          class="feature-image"
        >
      </div>
    </div>

    <div
      v-if="$can('product', 'edit') || $can('product', 'create')"
    >

      <ajax-button
        @clicked="$refs.filesInput.click()"
        type="button"
        color="primary"
        class="outline-btn w-100 centered"
        text="Upload Feature Images"
        loading-text="Uploading"
        :fetching-data="uploading"
      />
    </div>

  </div>
</template>

<script>
import Spinner from '~/components/Spinner'
import AjaxButton from '~/components/AjaxButton'
import ErrorFormatter from '~/components/ErrorFormatter'

import { mapActions } from 'vuex'

import util from '~/mixin/util'
import validation from "~/mixin/validation"

export default {

  name: 'ProductFeatureImages',

  props: {
    featureImages: {
      type: Array,
      default() {
        return []
      }
    }
  },

  data() {
    return {
      uploading: false,
      deleting: false,
      errors: null,
    }
  },

  mixins: [util, validation],

  components: {
    Spinner,
    AjaxButton,
    ErrorFormatter
  },

  computed: {
    id() {
      return this.$route.params.id
    }
  },

  methods: {

    deleteFeatureImage(index) {

      const images = [...this.featureImages]

      images.splice(index, 1)

      this.$emit('result', images)
    },

    async uploadFeatureImages(event) {

      const files = event.target.files

      const fd = new FormData()

      fd.append('id', this.id)
      fd.append('product_id', this.id)

      Object.values(files).forEach((item) => {

        fd.append('images[]', item)

        this.errors = this.isValidImage(item)

        if (this.errors) {
          return
        }
      })

      this.setErrors({
        feature_image: [this.errors]
      })

      if (!this.errors) {

        try {

          this.uploading = true

          const data = await this.setImageById({
            id: this.id,
            params: fd,
            api: 'setProductFeatureImages'
          })

          if (data) {

            this.$emit('result', data)
          }

          this.uploading = false

        } catch (e) {

          return this.$nuxt.error(e)
        }
      }
    },

    ...mapActions('common', ['setImageById']),
    ...mapActions('ui', ['setErrors'])
  }
}
</script>

<style scoped>
.feature-image-box{
  width:100%;
  border:1px solid #e5e7eb;
  border-radius:12px;
  padding:10px;
  background:#fff;
}

.feature-image{
  width:100%;
  height:auto;
  object-fit:contain;
  border-radius:10px;
}
</style>