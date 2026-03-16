<template>

      <div
        class="w-100 "
      >
        <error-formatter
          v-if="hasError"
          type="images_attribute"
        />


        <div class="img-wrapper">
          <lazy-image
            :data-src="getThumbImageURL(productImage.image)"
          />
          <button
            v-if="$can('product', 'edit') || $can('product', 'create')"
            @click.prevent="imageDelete(productImage.id)"
          >✖
          </button>
        </div>

        <div class="input-wrapper img-attr-wrap mt-15 dply-felx gap-10">
          <div
            :data-ignore="`image-attr-${productImage.id}`"
            @click.prevent="showAttributes = !showAttributes"
            class="custom-dropdown cp"
          >
            <span class="ignore-click">{{ $t('list.attr') }} ({{ attributes.length }})
              <i
                class="icon black ignore-click"
                :class="[{'arrow-up': showAttributes}, {'arrow-down': !showAttributes}]"
              />
            </span>
          </div>

          <ul v-if="showAttributes"
              class="multiple-wrap to-bottom"
              v-outside-click="closeAttrDrop"
              :id="`image-attr-${productImage.id}`"
          >
            <label
              v-for="(item, index) in allAttributeValues"
              :key="index"
              :label-for="`product-img-${item.id}`"
            >
              <input
                :id="`product-img-${item.id}`"
                type="checkbox"
                v-model="attributes"
                :value="item.id"
              >
              {{ item.title }}
            </label>
          </ul>


          <ajax-button
            type="button"
            name="save"
            class="primary-btn"
            :text="$t('setting.sv')"
            loading-text=""
            :fetching-data="formSubmitting"
            @clicked="updateAttributes"
          />


        </div>


      </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import util from '~/mixin/util'
  import LazyImage from "~/components/LazyImage";
  import outsideClick from '~/directives/outside-click.js'
  import AjaxButton from "../AjaxButton";
  import ErrorFormatter from "../ErrorFormatter";

  export default {
    name: 'ProductImage',
    data() {
      return {
        hasError: false,
        attributes: [],
        imageData: '',
        formSubmitting: false,
        uploading: false,
        showAttributes: false,
        deleting: false,
        errors: null,
      }
    },
    props: {
      productImage: {
        type: Object,
        default() {
          return {
            id: '',
            image: ''
          }
        }
      }
    },
    directives: {
      outsideClick
    },
    mixins: [util],
    components: {
      ErrorFormatter,
      AjaxButton,
      LazyImage
    },
    computed: {
      ...mapGetters('common', ['allAttributeValues'])
    },
    methods: {
      closeAttrDrop(){
        this.showAttributes = false
      },
      async updateAttributes(){
        this.hasError = false
        try {
          this.formSubmitting = true
          const data = await this.setRequest({
            params: {
              product_image_id: this.productImage.id,
              product_id: this.$route?.params?.id,
              values: this.attributes
            },
            api: 'setProductImageAttributes'
          })

          if(!data){
            this.hasError = true
          }

          this.formSubmitting = false
        }catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async imageDelete(id) {
        if (confirm(this.$t('admin.dltMsg'))) {

          try {
            this.deleting = true
            const data = await this.deleteData({params: id, api: 'deleteProductImage' })
            if (data) {
              this.$emit('image-deleted', data)
            }
            this.deleting = false
          }catch (e) {
            return this.$nuxt.error(e)
          }
        }
      },
      ...mapActions('common', ['deleteData', 'setRequest'])
    },
    mounted () {
      this.attributes = this.productImage?.attributes.map(i => { return i.attribute_value_id}) || []
    },
  }
</script>
