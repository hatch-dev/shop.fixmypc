<template>
  <div
    class="p-20 p-sm-15 pos-rel"
  >
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
      @change="uploadProductImages"
      ref="filesInput"
    >

    <error-formatter
      type="multiple_image"
    />
    <div
      class="product-images dply-felx mb-15 gap-15"
    >
      <p
        v-if="!productImages.length"
        class="mlr-5"
      >
        {{ $t('list.noData', { data: $t('admin.img') }) }}
      </p>

      <product-image
        v-for="(v, index) in productImages"
        :key="index"
        :product-image="v"
        @image-deleted="imageDeleted"
      />

    </div>

    <div
      v-if="$can('product', 'edit') || $can('product', 'create')"
    >
      <div
        v-if="mediaStorageData.URL === mediaStorage"
        class="input-wrapper">
        <label class="mb-10">

          {{ $t('list.addImg') }}
        </label>

        <div class="image-input">
          <input
            class="mb-10"
            type="text"
            placeholder="Image name"
            v-model="imageData"
          >

          <ajax-button
            class="primary-btn"
            type="button"
            :text="$t('setting.sv')"
            loading-text=""
            :fetching-data="uploading"
            @clicked="uploadProductImages(null, imageData)"
          />
        </div>
      </div>

      <ajax-button
        v-else
        @clicked="$refs.filesInput.click()"
        type="button"
        color="primary"
        class="outline-btn w-100 centered"
        text="Upload Images"
        loading-text="Uploading"
        :fetching-data="uploading"
      />
    </div>


  </div>
</template>

<script>
  import Spinner from '~/components/Spinner'
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import util from '~/mixin/util'
  import validation from "~/mixin/validation"
  import ErrorFormatter from '~/components/ErrorFormatter'
  import LazyImage from "~/components/LazyImage";
  import ImageInput from "~/components/ImageInput";
  import ProductImage from "~/components/partials/ProductImage";

  export default {
    name: 'ProductImages',
    data() {
      return {
        imageData: '',
        uploading: false,
        deleting: false,
        errors: null,
      }
    },
    props: {
      productImages: {
        type: Array,
        default() {
          return []
        }
      }
    },
    mixins: [util, validation],
    components: {
      ProductImage,
      ImageInput,
      LazyImage,
      AjaxButton,
      ErrorFormatter,
      Spinner
    },
    computed: {
      id(){
        return this.$route.params.id
      },
      ...mapGetters(['mediaStorage']),
    },
    methods: {
      imageDeleted(data) {
        this.$emit('result', data)
      },
      async uploadProductImages(event, name = null) {
        let params = {}

        if(event){
          const files = event.target.files

          const fd = new FormData()
          fd.append('id', this.id)
          fd.append('product_id', this.id)

          Object.values(files).forEach((item) => {
            fd.append('images[]', item)
            this.errors = this.isValidImage(item)
            if (this.errors){
              return
            }
          })

          this.setErrors({multiple_image : [this.errors]})

          params = fd
        } else {
          params['photo'] = name
          params['product_id'] = this.id
          params['id'] = this.id
        }

        if (!this.errors) {
          try {
            this.uploading = true
            const data = await this.setImageById({id: this.id, params: params, api: 'setProductImages'})
            if (data) {
              await this.$emit('result', data)

              this.imageData = ''
            }
            this.uploading = false
          }catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      ...mapActions('common', ['setImageById']),
      ...mapActions('ui', ['setErrors'])
    }
  }
</script>
