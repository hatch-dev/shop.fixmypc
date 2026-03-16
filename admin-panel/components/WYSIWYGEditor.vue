<template>
  <div
    class="wysiwyg-wrapper"
    :class="[{'full-screen': fullScreen}, {'focused': focused}]"
  >
    <div class="dply-felx mb-15">
      <label class="mb-0">{{title}}</label>
      <div class="custom-toolbar">
        <button
          class="outline-btn"
          @click.prevent="editAsHtml =! editAsHtml"
        >
          {{ $t('error.ea', { type: !editAsHtml ? $t('profile.html') : $t('profile.wysi') }) }}
        </button>
        <button
          class="outline-btn"
          @click.prevent="fullScreenToggle"
        >
          {{ $t('error.scrn', { type: !fullScreen ? $t('error.full') : $t('error.nrm')}) }}
        </button>
        <button
          class="ml-10 close-btn"
          v-if="fullScreen"
          @click.prevent="fullScreenToggle"
        >
          <i
            class="icon close-icon"
          />
        </button>
      </div>
    </div>

    <p
      v-if="editAsHtml"
      class="info-msg mb-20 mb-sm-15"
    >
      {{ $t('error.st') }}: h1, h2...h6, p, strong, em, u, s, blockquote, pre, ul, li, ol, br, a, img, video.
      {{ $t('error.mkSr') }}
    </p>

    <vue-editor
      v-if="!editAsHtml"
      useCustomImageHandler
      v-model="productDescription"
      @focus="focused = true"
      @blur="focused = false"
      @text-change="descriptionChange"
      @image-added="handleImageAdded"
      @image-removed="handleImageRemoved"
    />

    <textarea
      v-else
      v-model="productDescription"
      @change="descriptionChange"
    />
  </div>
</template>

<script>
  import {VueEditor} from "vue2-editor";
  import util from "~/mixin/util";
  import DOMPurify from 'dompurify'

  export default {
    name: 'WYSIWYGEditor',
    data() {
      return {
        acceptedTags: ['iframe'],
        productDescription: '',
        editAsHtml: false,
        fullScreen: false,
        focused: false
      }
    },
    props: {
      title: {
        type: String,
        default() {
          return this.$t('prod.desc')
        }
      },
      type: {
        type: Number,
        required: false
      },
      description: {
        type: String,
        default: ''
      }
    },
    mixins: [util],
    components: {
      VueEditor
    },
    watch: {
      description(v) {
        this.productDescription = DOMPurify.sanitize(v, {ADD_TAGS: this.acceptedTags})
      }
    },
    computed: {},
    methods: {
      descriptionChange() {
        this.$emit('change', DOMPurify.sanitize(this.productDescription, {ADD_TAGS: this.acceptedTags}))
      },
      fullScreenToggle() {
        this.fullScreen = !this.fullScreen
      },
      handleImageRemoved: function (file, Editor, cursorLocation, resetUploader) {
        this.handleDescriptionImage(true, file, Editor, cursorLocation, resetUploader)
      },
      handleImageAdded: function (file, Editor, cursorLocation, resetUploader) {
        this.handleDescriptionImage(false, file, Editor, cursorLocation, resetUploader)
      },
      handleDescriptionImage(deleted, file, Editor, cursorLocation, resetUploader) {
        this.$emit('file', {deleted, file, Editor, cursorLocation, resetUploader})
      },
    },
    beforeCreate() {
    },
    destroyed() {
    },
    created() {
    },
    mounted() {
      this.productDescription = DOMPurify.sanitize(this.description)
    }
  }
</script>

<style lang="stylus">
  .ql-snow .ql-editor img
    width auto


  .custom-toolbar
    display flex
    gap 15px
    align-items center

  .ql-editor
    max-height 400px

    li
      line-height 1.9
      display block

  .focused
    .ql-container
      border-color #444 !important

    .ql-toolbar.ql-snow
      border-color #444 !important
      border-bottom 1px solid #ccc

  .ql-toolbar.ql-snow + .ql-container.ql-snow
    border-radius 0 0 5px 5px

  .ql-toolbar.ql-snow
    border-radius 5px 5px 0 0

  .has-error
    .whysigwyg-error
      .ql-container
        border-color red !important

      .ql-toolbar.ql-snow
        border-color red !important

  .full-screen
    padding 30px
    position fixed
    top 0
    bottom 0
    left 0
    right 0
    z-index 10
    display flex
    flex-direction column
    background #fff

    .ql-editor
      max-height 4000px

    .quillWrapper
      display flex
      flex-direction column
      flex-grow 1

      .ql-container
        flex-grow 1

    textarea
      flex-grow 1

  //RESPONSIVE
  @media only screen and (max-width: 768px)
    .wysiwyg-wrapper
      .dply-felx
        display block
        margin 10px 0

        .custom-toolbar
          button
            margin-top 10px
</style>

