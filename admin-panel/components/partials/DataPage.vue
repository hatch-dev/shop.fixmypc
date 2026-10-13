<template>
  <div
    ref="formWrapper"
    class="tab-sidebar"
    :class="{ 'edit-page': structured }"
  >

    <!-- ===== Structured (reference-style) layout ===== -->
    <form
      v-if="structured"
      class="edit-form"
      @submit.prevent="formSubmit"
      :class="{'has-error': hasError}"
    >
      <div class="edit-topbar">
        <div class="edit-topbar__left">
          <button
            type="button"
            class="edit-back"
            @click="goBack"
          >
            <span class="edit-back__icon" aria-hidden="true">&#8592;</span>
            <span>{{ $te('setting.back') ? $t('setting.back') : 'Back' }}</span>
          </button>
          <span class="edit-topbar__sep"></span>
          <h4 class="edit-title">{{ $t('profile.form', {name: name}) }}</h4>
        </div>

        <div
          v-if="!loadError && (!gate || $can(gate, 'edit') || $can(gate, 'create'))"
          class="edit-topbar__actions"
        >
          <ajax-button
            name="save-edit"
            class="lite-btn"
            :text="$t('list.svn')"
            :fetching-data="formSubmitting && !redirect"
          />
          <ajax-button
            name="save"
            class="primary-btn"
            :text="$t('setting.sv')"
            :fetching-data="formSubmitting && redirect"
          />
        </div>
      </div>

      <div class="form-wrapper edit-body">
        <error-formatter :messages="bannerMessages" />
        <error-formatter type="image" />

        <div
          v-if="loading"
          class="spinner-wrapper"
        >
          <spinner
            :radius="60"
            color="primary"
            class="mr-15"
          />
        </div>

        <div v-if="loadError" class="danger-msg mb-15" role="alert">
          <b>{{ $t('error.err') || 'Unable to load this record' }}</b>
          <p>{{ loadError }}</p>
          <button type="button" class="outline-btn xs mt-10" @click.prevent="fetchingData">
            {{ $t('util.retry') || 'Retry' }}
          </button>
        </div>

        <div v-if="!loading && !loadError">
          <div
            v-if="fileKeys.length && (!gate || $can(gate, 'edit') || $can(gate, 'create'))"
          >
            <image-input
              v-if="mediaStorageData.URL === mediaStorage"
              class="mb-20 mb-sm-15"
              :saving="fileUploading"
              :image="result.image"
              @image-change="uploadFile(null, $event)"
            />
            <file-upload
              v-else
              :image="result.image"
              :file-uploading="fileUploading"
              only-icon="upload-icon"
              class="mb-20 mb-sm-15"
              @file-upload="uploadFile"
            />
          </div>

          <img
            v-else-if="fileKeys.length && $can(gate, 'view')"
            class="mx-w-300x"
            :src="getImageURL(result.image)"
          >
        </div>

        <div v-if="!loadError" class="edit-columns">
          <div class="edit-main">
            <slot
              name="form"
              v-bind:hasError="hasError"
              v-bind:fieldErrors="fieldErrors"
            />
          </div>
          <aside v-if="hasSidebar" class="edit-aside">
            <slot name="sidebar" />
          </aside>
        </div>
      </div>
    </form>

    <!-- ===== Original layout (opt-out via :structured="false") ===== -->
    <template v-else>
      <h4
        v-if="!topActions || loadError"
        class="title"
      >{{ $t('profile.form', {name: name}) }}</h4>

      <div class="form-wrapper">
        <error-formatter
          :messages="bannerMessages"
        />
        <error-formatter
          type="image"
        />

        <div
          v-if="loading"
          class="spinner-wrapper"
        >
          <spinner
            :radius="60"
            color="primary"
            class="mr-15"
          />
        </div>

        <div v-if="loadError" class="danger-msg mb-15" role="alert">
          <b>{{ $t('error.err') || 'Unable to load this record' }}</b>
          <p>{{ loadError }}</p>
          <button class="outline-btn xs mt-10" @click.prevent="fetchingData">
            {{ $t('util.retry') || 'Retry' }}
          </button>
        </div>

        <div v-if="!loading && !loadError">
          <div
            v-if="fileKeys.length && (!gate || $can(gate, 'edit') || $can(gate, 'create'))"
          >
            <image-input
              v-if="mediaStorageData.URL === mediaStorage"
              class="mb-20 mb-sm-15"
              :saving="fileUploading"
              :image="result.image"
              @image-change="uploadFile(null, $event)"
            />
            <file-upload
              v-else
              :image="result.image"
              :file-uploading="fileUploading"
              only-icon="upload-icon"
              class="mb-20 mb-sm-15"
              @file-upload="uploadFile"
            />
          </div>

          <img
            v-else-if="fileKeys.length && $can(gate, 'view')"
            class="mx-w-300x"
            :src="getImageURL(result.image)"
          >
        </div>

        <form
          v-if="!loadError"
          @submit.prevent="formSubmit"
          :class="{'has-error': hasError}"
        >
          <!-- Sticky action bar: on long forms the footer buttons are a full
               page away, so `top-actions` pins them to the top-right instead. -->
          <div
            v-if="topActions"
            class="plain-topbar"
          >
            <div class="plain-topbar__left">
              <slot name="top-title">
                <h4 class="plain-topbar__title">{{ $t('profile.form', {name: name}) }}</h4>
              </slot>
            </div>

            <div
              v-if="!gate || $can(gate, 'edit') || $can(gate, 'create')"
              class="plain-topbar__actions"
            >
              <ajax-button
                name="save-edit"
                class="lite-btn"
                :text="$t('list.svn')"
                :fetching-data="formSubmitting && !redirect"
              />
              <ajax-button
                name="save"
                class="primary-btn"
                :text="$t('setting.sv')"
                :fetching-data="formSubmitting && redirect"
              />
            </div>
          </div>

          <slot
            name="form"
            v-bind:hasError="hasError"
            v-bind:fieldErrors="fieldErrors"
          />

          <div
            v-if="!topActions && (!gate || $can(gate, 'edit') || $can(gate, 'create'))"
            class="dply-felx j-right single-btn"
          >
            <ajax-button
              name="save-edit"
              class="primary-btn"
              :text="$t('list.svn')"
              :fetching-data="formSubmitting  && !redirect"
            />
            <ajax-button
              name="save"
              class="primary-btn"
              :text="$t('setting.sv')"
              :fetching-data="formSubmitting && redirect"
            />
          </div>

        </form>
      </div>
    </template>

  </div>
</template>

<script>

  import AjaxButton from '~/components/AjaxButton'
  import util from "~/mixin/util"
  import FileUpload from "~/components/FileUpload"
  import Spinner from '~/components/Spinner'
  import Dropdown from '~/components/Dropdown'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import {mapGetters, mapActions} from 'vuex'
  import ImageInput from "../ImageInput";

  export default {
    name: "DataPage",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        loading: false,
        redirect: false,
        formSubmitting: false,
        fileUploading: false,
        hasError: false,
        loadError: '',
        /*
         * Refusals the form itself produced, before anything was sent: one
         * entry per `validationKeys` field left empty. Same shape as the
         * server's `fields`, so the slot reads both the same way.
         */
        clientErrors: {},
        /*
         * The names the form actually renders a message for, read from the
         * DOM (`data-field="<name>"`) after each refusal. A message whose
         * field is anchored belongs next to that control; anything else has
         * nowhere to go and stays in the summary box at the top.
         */
        anchoredFields: [],
        /*
         * Fields the admin has edited since the refusal. Their message is no
         * longer about what is on screen, so it goes as soon as they type.
         */
        clearedFields: [],
        errorSnapshot: {}
      }
    },
    props: {
      result: {
        type: Object,
        default() {
          return null
        }
      },
      emitBeforeSubmit: {
        type: Boolean,
        default: false
      },
      structured: {
        type: Boolean,
        default: true
      },
      // Pins Save & edit / Save to a sticky bar at the top of the card
      // instead of the footer, for forms too long to scroll comfortably.
      topActions: {
        type: Boolean,
        default: false
      },
      name: {
        type: String,
        default: ''
      },
      gate: {
        type: String,
        default: null
      },
      hash: {
        type: String,
        default: ''
      },
      setApi: {
        type: String,
        default: ''
      },
      setImageApi: {
        type: String,
        default: ''
      },
      getApi: {
        type: String,
        default: ''
      },
      emptyStoreVariable: {
        type: String,
        default: null
      },
      routeName: {
        type: String,
        default: ''
      },
      validationKeys: {
        type: Array,
        default: []
      },
      /*
       * What to call a `validationKeys` field in the message put against it.
       * Without an entry the key is humanised ("meta_title" -> "Meta title").
       */
      validationLabels: {
        type: Object,
        default() {
          return {}
        }
      },
      fileKeys: {
        type: Array,
        default() {
          return []
        }
      }
    },
    mixins: [util],
    components: {
      ImageInput,
      Spinner,
      AjaxButton,
      ErrorFormatter,
      FileUpload,
      Dropdown
    },
    computed: {
      id() {
        return !this.isAdding ? this.$route?.params?.id : ''
      },
      isAdding() {
        return isNaN(this.$route?.params?.id)
      },
      hasSidebar() {
        return !!(this.$slots.sidebar || (this.$scopedSlots && this.$scopedSlots.sidebar))
      },
      /**
       * Every refusal that names a field, keyed by that field.
       *
       * The API has always sent this alongside the flat list (`fields` in the
       * validation response), and nothing read it — so a form could only show
       * the lot in one box at the top and leave the admin to work out which
       * control each line was about.
       */
      fieldErrors() {
        const merged = {}

        Object.keys(this.clientErrors).forEach(name => {
          merged[name] = this.clientErrors[name]
        })

        const fromServer = this.errors && this.errors.fields ? this.errors.fields : {}

        Object.keys(fromServer).forEach(name => {
          const messages = [].concat(fromServer[name] || [])
          merged[name] = (merged[name] || []).concat(messages)
        })

        this.clearedFields.forEach(name => {
          delete merged[name]
        })

        return merged
      },
      /**
       * What is left for the summary box: the messages with no control of
       * their own on this page. Anything shown inline is dropped, so the
       * admin reads each problem once, where they can act on it.
       */
      bannerMessages() {
        const flat = this.errors && this.errors.form ? [].concat(this.errors.form) : []

        const shownInline = new Set()

        Object.keys(this.fieldErrors).forEach(name => {
          if (this.anchoredFields.indexOf(name) > -1) {
            this.fieldErrors[name].forEach(message => shownInline.add(message))
          }
        })

        const left = flat.filter(message => !shownInline.has(message))

        /*
         * A field message the flat list never carried (Validation::fieldError
         * answers with both, but a refusal scoped to one field need not) and
         * that no control claims would otherwise be invisible.
         */
        Object.keys(this.fieldErrors).forEach(name => {
          if (this.anchoredFields.indexOf(name) > -1) return

          this.fieldErrors[name].forEach(message => {
            if (left.indexOf(message) === -1) left.push(message)
          })
        })

        return left
      },
      ...mapGetters('ui', ['errors']),
      ...mapGetters(['mediaStorage']),
    },
    watch: {
      errors() {
        this.clearedFields = []
        this.$nextTick(() => {
          this.collectAnchors()
          this.focusFirstError()
        })
      },
      result: {
        deep: true,
        handler(value) {
          if (!value) return

          Object.keys(this.fieldErrors).forEach(name => {
            if (!(name in this.errorSnapshot)) return
            if (value[name] === this.errorSnapshot[name]) return
            if (this.clearedFields.indexOf(name) > -1) return

            this.clearedFields.push(name)
            delete this.clientErrors[name]
          })
        }
      }
    },
    methods: {
      /** Turn "meta_title" into "Meta title" for a generated message. */
      humanise(name) {
        const words = String(name).replace(/[_-]+/g, ' ').trim()
        return words.charAt(0).toUpperCase() + words.slice(1)
      },
      /** Which field names this form has a place to show a message for. */
      collectAnchors() {
        if (!this.$el || !this.$el.querySelectorAll) {
          this.anchoredFields = []
          return
        }

        this.anchoredFields = Array.prototype.map
          .call(this.$el.querySelectorAll('[data-field]'), node => node.getAttribute('data-field'))
          .filter(name => !!name)
      },
      /**
       * Put the admin in front of the first problem.
       *
       * A refused save used to scroll the whole page to the top and leave
       * them to find the control the message was about - on a form this long
       * the field could be several screens down, and nothing marked it.
       */
      focusFirstError() {
        const names = Object.keys(this.fieldErrors)

        if (!names.length) {
          return
        }

        let target = null

        if (this.$el && this.$el.querySelectorAll) {
          // DOM order, so "first" means first on the page, not first in the
          // order the validator happened to report them.
          const anchors = Array.prototype.slice.call(this.$el.querySelectorAll('[data-field]'))
          target = anchors.find(node => names.indexOf(node.getAttribute('data-field')) > -1) || null
        }

        if (!target) {
          if (this.$refs.formWrapper && this.$refs.formWrapper.scrollIntoView) {
            this.$refs.formWrapper.scrollIntoView({behavior: 'smooth', block: 'start'})
          }
          return
        }

        target.scrollIntoView({behavior: 'smooth', block: 'center'})

        const selector = 'input:not([type=hidden]), select, textarea, [contenteditable=true]'

        // The anchor is either the control itself or the block around it.
        const control = target.matches(selector) ? target : target.querySelector(selector)

        if (control && control.focus) {
          // After the scroll, or the browser jumps a second time.
          setTimeout(() => control.focus({preventScroll: true}), 300)
        }
      },
      goBack() {
        if (this.routeName) {
          this.$router.push(`/${this.routeName}`)
        } else {
          this.$router.back()
        }
      },
      async uploadFile(file, name = null) {
        try {

          let params = {}
          if (file) {
            const fd = new FormData()

            this.fileKeys.forEach(i => {
              fd.append(i, this.result[i])
            })
            fd.append('photo', file)

            params = fd
          } else {
            this.fileKeys.forEach(i => {
              params[i] = this.result[i]
            })
            params['photo'] = name
          }

          this.fileUploading = true

          const data = await this.setImageById({id: this.id, params: params, api: this.setImageApi})
          if (data && (!this.gate || this.$can(this.gate, 'view'))) {
            await this.$emit('result', Object.assign({}, data))
            await this.$router.push({path: `/${this.routeName}/${this.result.id}`})
          }

          this.fileUploading = false
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      redirectingEnable(buttonType) {
        this.redirect = buttonType === 'save'
      },
      formSubmit() {
        if (this.emitBeforeSubmit) {
          this.$emit('before-submit')
        } else {
          this.checkForm()
        }
      },
      async checkForm() {
        /*
         * Remember what each field held when it was refused, so the message
         * can be taken down the moment the admin changes that field.
         */
        this.errorSnapshot = Object.assign({}, this.result)
        this.clearedFields = []

        const missing = this.validationKeys.filter(key => !this.result[key])

        if (missing.length) {
          const generated = {}

          missing.forEach(key => {
            const label = this.validationLabels[key] || this.humanise(key)
            generated[key] = [this.$te('error.required')
              ? this.$t('error.required', {field: label})
              : `${label} is required.`]
          })

          this.clientErrors = generated
          this.hasError = true

          this.$nextTick(() => {
            this.collectAnchors()
            this.focusFirstError()
          })

          return false
        }

        this.clientErrors = {}

        this.redirectingEnable(event.submitter.name)
        this.formSubmitting = true
        try {
          delete this.result.created_at
          delete this.result.updated_at

          const data = await this.setById({id: this.id, params: this.result, api: this.setApi})
          if (data) {

            this.emptyAllList(this.emptyStoreVariable)

            await this.$emit('result', Object.assign({}, data))

            if (!this.gate || this.$can(this.gate, 'view') && this.result?.id) {
              await this.$router.push({
                path: `/${this.routeName}${this.redirect ? '' : '/' + this.result?.id}`,
                hash: this.hash
              })
            }
            await this.$emit('on-success', Object.assign({}, data))

          } else {
            /*
             * Refused. `ui/errors` has just changed, which the watcher picks
             * up to anchor each message and move to the first one; the call
             * here covers the case where the same refusal comes back twice
             * and the getter therefore never changes.
             */
            this.$nextTick(() => {
              this.collectAnchors()
              this.focusFirstError()
            })
          }
        } catch (e) {
          this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err') || 'Unable to save')
        } finally {
          this.formSubmitting = false
        }

      },
      async fetchingData() {
        try {
          this.loading = true
          this.loadError = ''
          this.$emit('result',
            Object.assign({}, await this.getById({id: this.id, params: {time_zone: this.timeZone}, api: this.getApi})))
        } catch (e) {
          this.loadError = e?.message || this.$t('error.err') || 'Unable to load this record'
          this.$store.dispatch('ui/setToastError', this.loadError)
        } finally {
          this.loading = false
        }
      },
      ...mapActions('common', ['getById', 'setById', 'setImageById', 'emptyAllList'])
    },
    async mounted() {

      /*
       * Before the first refusal, so the summary box is never briefly drawn
       * with messages that a control on the page is about to claim.
       */
      this.$nextTick(this.collectAnchors)

      if (!this.isAdding) {
        this.$nextTick(() => {
          if (!this.gate || this.$can(this.gate, 'view')) {
            this.fetchingData()
          }
        })
      }
    }
  }
</script>

<style scoped>

</style>
