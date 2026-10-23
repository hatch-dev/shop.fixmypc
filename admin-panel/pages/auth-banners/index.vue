<template>

  <div class="ab-page">

    <p class="ab-crumb">{{ $t('pages.authBanners.crumb') }}</p>

    <div class="ab-head">

      <div>
        <h2 class="ab-title">{{ $t('pages.authBanners.title') }}</h2>
        <p class="ab-lede">
          {{ $t('pages.authBanners.lede') }}
        </p>
      </div>

      <button
        v-if="$can('banner', 'create')"
        class="button primary-btn"
        @click="openEditor(null)"
      >
        + {{ $t('pages.authBanners.newBanner') }}
      </button>

    </div>

    <!-- ── live preview ── -->
    <div
      ref="previewWrap"
      class="ab-preview-wrap"
    >

      <h3 class="ab-preview-title">{{ $t('pages.authBanners.livePreview') }}</h3>

      <p class="ab-preview-sub">
        {{ $t('pages.authBanners.previewSub') }}
      </p>

      <div class="ab-mock">
        <auth-banner-preview
          :banner="previewed || {}"
          :image-src="previewed && previewed.image ? getImageURL(previewed.image) : ''"
          :slide-count="liveCount"
        />
      </div>

    </div>

    <spinner v-if="loading" />

    <!-- ── empty ── -->
    <div
      v-else-if="!banners.length"
      class="ab-empty"
    >
      <b>{{ $t('pages.authBanners.noBanners') }}</b>
      <span>
        {{ $t('pages.authBanners.noBannersSub') }}
      </span>
    </div>

    <!-- ── card grid ── -->
    <div
      v-else
      class="ab-grid"
    >

      <article
        v-for="(banner, index) in banners"
        :key="banner.id"
        class="ab-card"
      >

        <div class="ab-card-thumb">

          <img
            v-if="banner.image"
            :src="getThumbImageURL(banner.image)"
            alt=""
          >

          <span
            v-else
            class="ab-noimg"
          >
            {{ $t('pages.authBanners.noImage') }}
          </span>

        </div>

        <div class="ab-card-body">

          <h3>{{ banner.title || banner.name || $t('pages.authBanners.untitledBanner') }}</h3>

          <p v-if="banner.sub">
            {{ banner.sub }}
          </p>

          <div class="ab-meta">

            <span
              class="ab-tag"
              :class="bannerState(banner).cls"
            >
              {{ bannerState(banner).label }}
            </span>

            <span class="ab-tag">
              {{ placementLabels[banner.placement] || banner.placement }}
            </span>

            <span class="ab-tag">
              {{ $t('pages.authBanners.position', { n: index + 1 }) }}
            </span>

          </div>

        </div>

        <div class="ab-card-acts">

          <button
            v-if="$can('banner', 'edit')"
            class="lite-btn"
            @click="openEditor(banner)"
          >
            {{ $t('category.edit') }}
          </button>

          <button
            class="lite-btn"
            @click="previewBanner(banner)"
          >
            {{ $t('pages.authBanners.preview') }}
          </button>

          <button
            v-if="$can('banner', 'edit')"
            class="lite-btn ab-icon-btn"
            :disabled="index === 0 || reordering"
            :aria-label="$t('pages.authBanners.moveUp')"
            @click="move(index, index - 1)"
          >
            &uarr;
          </button>

          <button
            v-if="$can('banner', 'edit')"
            class="lite-btn ab-icon-btn"
            :disabled="index === banners.length - 1 || reordering"
            :aria-label="$t('pages.authBanners.moveDown')"
            @click="move(index, index + 1)"
          >
            &darr;
          </button>

          <button
            v-if="$can('banner', 'delete')"
            class="lite-btn ab-danger-btn"
            @click="removeBanner(banner)"
          >
            {{ $t('category.delete') }}
          </button>

          <label
            v-if="$can('banner', 'edit')"
            class="ab-switch"
          >
            <input
              type="checkbox"
              :checked="banner.status === 1"
              :disabled="toggling === banner.id"
              @change="toggleStatus(banner)"
            >
            <span class="ab-track" />
          </label>

        </div>

      </article>

    </div>

    <!-- ══ builder ══ -->
    <div
      class="ab-scrim"
      :class="{ on: open }"
    />

    <div
      class="ab-modal"
      :class="{ on: open }"
      role="dialog"
      aria-modal="true"
      :aria-hidden="open ? 'false' : 'true'"
      aria-labelledby="ab-fly-title"
    >

      <div class="ab-modal-head">

        <div>
          <h2 id="ab-fly-title">
            {{ editing ? $t('pages.authBanners.editBanner') : $t('pages.authBanners.newBanner') }}
          </h2>
          <p class="ab-modal-sub">
            {{ editing
              ? (editing.name || $t('pages.authBanners.untitledBanner'))
              : $t('pages.authBanners.modalSub') }}
          </p>
        </div>

        <div class="ab-modal-head-acts">

          <label class="ab-switch ab-switch-inline">
            <input
              v-model="form.status"
              type="checkbox"
              :true-value="1"
              :false-value="0"
            >
            <span class="ab-track" />
            <span class="ab-switch-text">{{ $t('pages.authBanners.live') }}</span>
          </label>

          <button
            class="ab-x"
            :aria-label="$t('pages.authBanners.closeBuilder')"
            @click="closeEditor"
          >
            &times;
          </button>

        </div>

      </div>

      <div class="ab-modal-body">

        <!-- ── left: the builder ── -->
        <form
          class="ab-modal-form"
          @submit.prevent="save"
        >

          <section class="ab-fs">

            <h3>{{ $t('pages.authBanners.content') }}</h3>

            <div class="input-wrapper">
              <label for="ab-title">
                {{ $t('pages.authBanners.headline') }}
                <span class="ab-req">*</span>
              </label>
              <input
                id="ab-title"
                v-model="form.title"
                type="text"
                maxlength="70"
                :placeholder="$t('pages.authBanners.headlinePlaceholder')"
                :class="{ invalid: !form.title && hasError }"
              >
              <p class="ab-hint">
                <span>{{ $t('pages.authBanners.headlineHint') }}</span>
                <span class="ab-counter">{{ (form.title || '').length }}/70</span>
              </p>
              <span
                v-if="!form.title && hasError"
                class="error"
              >
                {{ $t('category.req', { type: $t('pages.authBanners.headline') }) }}
              </span>
            </div>

            <div class="input-wrapper">
              <label for="ab-eyebrow">{{ $t('pages.authBanners.eyebrow') }}</label>
              <input
                id="ab-eyebrow"
                v-model="form.eyebrow"
                type="text"
                maxlength="28"
                :placeholder="$t('pages.authBanners.eyebrowPlaceholder')"
              >
              <p class="ab-hint">
                <span>{{ $t('pages.authBanners.eyebrowHint') }}</span>
                <span class="ab-counter">{{ (form.eyebrow || '').length }}/28</span>
              </p>
            </div>

            <div class="input-wrapper">
              <label for="ab-sub">{{ $t('pages.authBanners.supportingLine') }}</label>
              <textarea
                id="ab-sub"
                v-model="form.sub"
                maxlength="180"
                :placeholder="$t('pages.authBanners.subPlaceholder')"
              />
              <p class="ab-hint">
                <span>{{ $t('pages.authBanners.subHint') }}</span>
                <span class="ab-counter">{{ (form.sub || '').length }}/180</span>
              </p>
            </div>

          </section>

          <section class="ab-fs">

            <h3>{{ $t('pages.authBanners.button') }}</h3>

            <div class="ab-two">

              <div class="input-wrapper">
                <label for="ab-cta">{{ $t('pages.authBanners.buttonLabel') }}</label>
                <input
                  id="ab-cta"
                  v-model="form.cta"
                  type="text"
                  maxlength="26"
                  :placeholder="$t('pages.authBanners.ctaPlaceholder')"
                >
              </div>

              <div class="input-wrapper">
                <label for="ab-href">{{ $t('pages.authBanners.linksTo') }}</label>
                <url-picker
                  v-model="form.href"
                  :options="linkOptions"
                  :placeholder="$t('pages.authBanners.hrefPlaceholder')"
                />
                <p class="ab-hint">
                  <span>{{ $t('pages.authBanners.hrefHint') }}</span>
                </p>
              </div>

            </div>

            <label class="ab-check">
              <input
                v-model="form.href_new_tab"
                type="checkbox"
                :true-value="1"
                :false-value="0"
              >
              <span>{{ $t('pages.authBanners.newTab') }}</span>
            </label>

            <p class="ab-hint">
              <span>{{ $t('pages.authBanners.noButtonHint') }}</span>
            </p>

          </section>

          <section class="ab-fs">

            <h3>{{ $t('pages.authBanners.artwork') }}</h3>

            <div class="input-wrapper">

              <label>{{ $t('admin.img') }}</label>

              <div
                v-if="!imagePreview"
                class="ab-drop"
                :class="{ over: dragging }"
                @click="$refs.file.click()"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
              >
                <b>{{ $t('pages.authBanners.dropImage') }}</b>
                <span>{{ $t('pages.authBanners.imageSpec') }}</span>
              </div>

              <div
                v-else
                class="ab-shot"
              >
                <img
                  :src="imagePreview"
                  :alt="$t('pages.authBanners.bannerArtwork')"
                >
                <div class="ab-shot-acts">
                  <button
                    type="button"
                    class="lite-btn"
                    @click="$refs.file.click()"
                  >
                    {{ $t('pages.authBanners.replace') }}
                  </button>
                  <button
                    type="button"
                    class="lite-btn ab-danger-btn"
                    @click="clearImage"
                  >
                    {{ $t('pages.authBanners.remove') }}
                  </button>
                </div>
              </div>

              <input
                ref="file"
                type="file"
                accept="image/*"
                hidden
                @change="onFile"
              >

              <p class="ab-hint">
                <span>
                  {{ $t('pages.authBanners.artworkHint') }}
                </span>
              </p>

            </div>

            <div class="input-wrapper">

              <label>{{ $t('pages.authBanners.placeholderColours') }}</label>

              <div class="ab-colors">

                <span class="ab-color">
                  <input
                    v-model="bgFrom"
                    type="color"
                    :aria-label="$t('pages.authBanners.gradientStart')"
                  >
                  <input
                    v-model="bgFrom"
                    type="text"
                    maxlength="7"
                    spellcheck="false"
                  >
                </span>

                <span class="ab-color">
                  <input
                    v-model="bgTo"
                    type="color"
                    :aria-label="$t('pages.authBanners.gradientEnd')"
                  >
                  <input
                    v-model="bgTo"
                    type="text"
                    maxlength="7"
                    spellcheck="false"
                  >
                </span>

                <button
                  type="button"
                  class="lite-btn"
                  @click="resetColours"
                >
                  {{ $t('pages.authBanners.reset') }}
                </button>

              </div>

              <p class="ab-hint">
                <span>{{ $t('pages.authBanners.coloursHint') }}</span>
              </p>

            </div>

          </section>

          <section class="ab-fs">

            <h3>{{ $t('pages.authBanners.layout') }}</h3>

            <div class="input-wrapper">

              <label>{{ $t('pages.authBanners.textColour') }}</label>

              <div class="ab-colors">

                <span class="ab-color">
                  <input
                    v-model="textColor"
                    type="color"
                    :aria-label="$t('pages.authBanners.textColour')"
                  >
                  <input
                    v-model="textColor"
                    type="text"
                    maxlength="7"
                    spellcheck="false"
                  >
                </span>

                <button
                  type="button"
                  class="lite-btn"
                  @click="resetTextColor"
                >
                  {{ $t('pages.authBanners.reset') }}
                </button>

              </div>

              <p class="ab-hint">
                <span>
                  {{ $t('pages.authBanners.textColourHint') }}
                </span>
              </p>

            </div>

          </section>

          <section class="ab-fs">

            <h3>{{ $t('pages.authBanners.whereWhen') }}</h3>

            <div class="input-wrapper">
              <label for="ab-placement">{{ $t('pages.authBanners.showsOn') }}</label>
              <select
                id="ab-placement"
                v-model="form.placement"
              >
                <option value="auth">{{ placementLabels.auth }}</option>
                <option value="login">{{ placementLabels.login }}</option>
                <option value="signup">{{ placementLabels.signup }}</option>
                <option value="forgot">{{ placementLabels.forgot }}</option>
                <option value="reset">{{ placementLabels.reset }}</option>
              </select>
            </div>

            <div class="ab-two">

              <div class="input-wrapper">
                <label for="ab-start">{{ $t('pages.authBanners.startsLabel') }}</label>
                <input
                  id="ab-start"
                  v-model="form.starts_at"
                  type="date"
                >
              </div>

              <div class="input-wrapper">
                <label for="ab-end">{{ $t('pages.authBanners.endsLabel') }}</label>
                <input
                  id="ab-end"
                  v-model="form.ends_at"
                  type="date"
                  :class="{ invalid: invalidDates }"
                >
              </div>

            </div>

            <span
              v-if="invalidDates"
              class="error"
            >
              {{ $t('pages.authBanners.invalidDates') }}
            </span>

            <p class="ab-hint">
              <span>
                {{ $t('pages.authBanners.datesHint') }}
              </span>
            </p>

            <div class="input-wrapper ab-spaced">
              <label for="ab-name">{{ $t('pages.authBanners.internalName') }}</label>
              <input
                id="ab-name"
                v-model="form.name"
                type="text"
                :placeholder="$t('pages.authBanners.namePlaceholder')"
              >
              <p class="ab-hint">
                <span>{{ $t('pages.authBanners.nameHint') }}</span>
              </p>
            </div>

          </section>

        </form>

        <!-- ── right: live preview ── -->
        <aside class="ab-modal-preview">

          <div class="ab-mp-bar">

            <span class="ab-mp-label">{{ $t('pages.authBanners.livePreview') }}</span>

            <button
              v-if="imagePreview"
              type="button"
              class="lite-btn ab-mp-reset"
              :title="$t('pages.authBanners.resetImageTitle')"
              @click="refitImage"
            >
              {{ $t('pages.authBanners.resetImage') }}
            </button>

            <div class="ab-mp-tog">
              <button
                type="button"
                :class="{ on: device === 'desktop' }"
                @click="device = 'desktop'"
              >
                {{ $t('pages.authBanners.desktop') }}
              </button>
              <button
                type="button"
                :class="{ on: device === 'mobile' }"
                @click="device = 'mobile'"
              >
                {{ $t('pages.authBanners.mobile') }}
              </button>
            </div>

          </div>

          <auth-banner-preview
            ref="builderPreview"
            :banner="form"
            :image-src="imagePreview"
            :device="device"
            :slide-count="editingLiveCount"
            editable
            @position="onPlace"
            @resize="onStretch"
            @natural="onNatural"
          />

          <p class="ab-mp-hint">
            {{ $t('pages.authBanners.builderHint') }}
          </p>

        </aside>

      </div>

      <div class="ab-modal-foot">

        <button
          v-if="editing && $can('banner', 'delete')"
          class="lite-btn ab-danger-btn"
          @click="removeFromEditor"
        >
          {{ $t('pages.authBanners.deleteBanner') }}
        </button>

        <span class="ab-modal-foot-state">{{ saveState }}</span>

        <button
          class="lite-btn"
          @click="closeEditor"
        >
          {{ $t('pages.authBanners.cancel') }}
        </button>

        <button
          class="button primary-btn"
          :disabled="saving"
          @click="save"
        >
          {{ saving ? $t('pages.authBanners.saving') + '...' : $t('pages.authBanners.saveBanner') }}
        </button>

      </div>

    </div>

  </div>

</template>

<script>

  import {mapActions} from 'vuex'
  import util from '~/mixin/util'
  import Spinner from "~/components/Spinner"
  import AuthBannerPreview from "~/components/AuthBannerPreview"
  import UrlPicker from "~/components/UrlPicker"

  const DEFAULT_BG_FROM = '#2D2DA0'
  const DEFAULT_BG_TO = '#7A7AE0'
  const DEFAULT_TEXT_COLOR = '#FFFFFF'

  const EMPTY_BANNER = {
    name: '',
    eyebrow: '',
    title: '',
    sub: '',
    cta: '',
    href: '',
    href_new_tab: 0,
    image: '',
    image_x: 50,
    image_y: 50,
    image_w: 100,
    image_h: 100,
    bg_from: DEFAULT_BG_FROM,
    bg_to: DEFAULT_BG_TO,
    text_color: DEFAULT_TEXT_COLOR,
    placement: 'auth',
    starts_at: '',
    ends_at: '',
    status: 1
  }

  export default {

    name: "auth-banners",

    middleware: ['common-middleware', 'auth'],

    components: {Spinner, AuthBannerPreview, UrlPicker},

    mixins: [util],

    data() {
      return {
        banners: [],
        linkOptions: [],
        previewed: null,
        loading: true,
        reordering: false,
        toggling: null,
        placementLabels: {
          auth: this.$t('pages.authBanners.placementAuth'),
          login: this.$t('pages.authBanners.placementLogin'),
          signup: this.$t('pages.authBanners.placementSignup'),
          forgot: this.$t('pages.authBanners.placementForgot'),
          reset: this.$t('pages.authBanners.placementReset')
        },
        // ── builder ──
        open: false,
        editing: null,
        form: {...EMPTY_BANNER},
        photo: null,
        localPreview: '',
        dragging: false,
        awaitingFit: false,
        hasError: false,
        saving: false,
        device: 'desktop'
      }
    },

    computed: {

      /** The pending upload if there is one, otherwise the saved artwork. */
      imagePreview() {

        if (this.localPreview) {
          return this.localPreview
        }

        return this.form.image ? this.getImageURL(this.form.image) : ''
      },

      bgFrom: {
        get() {
          return this.form.bg_from || DEFAULT_BG_FROM
        },
        set(value) {
          this.form.bg_from = value
        }
      },

      bgTo: {
        get() {
          return this.form.bg_to || DEFAULT_BG_TO
        },
        set(value) {
          this.form.bg_to = value
        }
      },

      textColor: {
        get() {
          return this.form.text_color || DEFAULT_TEXT_COLOR
        },
        set(value) {
          this.form.text_color = value
        }
      },

      invalidDates() {
        return !!(this.form.starts_at
          && this.form.ends_at
          && this.form.starts_at > this.form.ends_at)
      },

      /** Banners a customer would actually be shown right now. */
      liveCount() {
        return this.banners.filter(i => this.bannerState(i).cls === 'live').length
      },

      /**
       * The same count from inside the builder, where the banner being edited
       * may not be saved yet or may be about to change state.
       */
      editingLiveCount() {

        const others = this.banners
          .filter(i => !this.editing || i.id !== this.editing.id)
          .filter(i => this.bannerState(i).cls === 'live')
          .length

        return others + (this.bannerState(this.form).cls === 'live' ? 1 : 0)
      },

      /**
       * The line in the builder footer telling the admin what saving will do.
       */
      saveState() {

        if (this.form.status !== 1) {
          return this.$t('pages.authBanners.stateOff')
        }

        const today = this.today()

        if (this.form.starts_at && this.form.starts_at > today) {
          return this.$t('pages.authBanners.stateScheduled', { date: this.form.starts_at })
        }

        if (this.form.ends_at && this.form.ends_at < today) {
          return this.$t('pages.authBanners.stateEnded')
        }

        return this.$t('pages.authBanners.stateLive')
      }
    },

    methods: {

      today() {
        return new Date().toISOString().slice(0, 10)
      },

      toast(message) {
        this.$store.dispatch('ui/setToastMessage', message)
      },

      toastError(e) {
        this.$store.dispatch('ui/setToastError', e?.message || this.$t('error.err'))
      },

      bannerState(banner) {

        if (banner.status !== 1) {
          return {cls: 'off', label: this.$t('pages.authBanners.off')}
        }

        const today = this.today()

        if (banner.starts_at && banner.starts_at > today) {
          return {cls: 'sched', label: this.$t('pages.authBanners.startsOn', { date: banner.starts_at })}
        }

        if (banner.ends_at && banner.ends_at < today) {
          return {cls: 'off', label: this.$t('pages.authBanners.endedOn', { date: banner.ends_at })}
        }

        return {cls: 'live', label: this.$t('pages.authBanners.live')}
      },

      previewBanner(banner) {

        this.previewed = banner
        this.toast(this.$t('pages.authBanners.previewing', { name: banner.name || banner.title }))

        this.$nextTick(() => {
          if (this.$refs.previewWrap) {
            this.$refs.previewWrap.scrollIntoView({behavior: 'smooth', block: 'center'})
          }
        })
      },

      /* ── builder ── */

      openEditor(banner) {

        this.releasePreview()

        this.editing = banner || null
        this.form = banner
          ? {
            ...EMPTY_BANNER,
            ...banner,
            // Date inputs need "" rather than null to stay empty.
            starts_at: banner.starts_at || '',
            ends_at: banner.ends_at || ''
          }
          : {...EMPTY_BANNER}

        this.photo = null
        this.awaitingFit = false
        this.hasError = false
        this.dragging = false
        this.device = 'desktop'
        this.open = true

        document.body.style.overflow = 'hidden'
      },

      closeEditor() {
        this.open = false
        this.editing = null
        this.releasePreview()
        this.photo = null
        this.hasError = false
        document.body.style.overflow = ''
      },

      onEscape(e) {
        if (e.key === 'Escape' && this.open) {
          this.closeEditor()
        }
      },

      resetColours() {
        this.form.bg_from = DEFAULT_BG_FROM
        this.form.bg_to = DEFAULT_BG_TO
      },

      onPlace({x, y}) {
        this.form.image_x = x
        this.form.image_y = y
      },

      /*
       * Re-centre and re-fit. Setting the size back to 100 first makes the
       * image reload-free auto-fit path re-run through onNatural.
       */
      refitImage() {
        this.awaitingFit = true
        this.$refs.builderPreview.refit()
      },

      onStretch({w, h}) {

        if (w !== undefined) {
          this.form.image_w = w
        }

        if (h !== undefined) {
          this.form.image_h = h
        }
      },

      /*
       * Only a freshly chosen file gets auto-fitted. A saved banner keeps the
       * placement it was given, even when it happens to be the defaults.
       */
      onNatural({w, h}) {

        if (!this.awaitingFit) {
          return
        }

        this.awaitingFit = false
        this.form.image_x = 50
        this.form.image_y = 50
        this.form.image_w = w
        this.form.image_h = h
      },

      resetTextColor() {
        this.form.text_color = DEFAULT_TEXT_COLOR
      },

      /* ── artwork ── */

      releasePreview() {

        if (this.localPreview) {
          URL.revokeObjectURL(this.localPreview)
          this.localPreview = ''
        }
      },

      onFile(e) {
        this.setFile(e.target.files && e.target.files[0])
      },

      onDrop(e) {
        this.dragging = false
        this.setFile(e.dataTransfer.files && e.dataTransfer.files[0])
      },

      setFile(file) {

        if (!file) {
          return
        }

        if (!/^image\//.test(file.type)) {
          this.$store.dispatch('ui/setToastError', this.$t('pages.authBanners.notImage'))
          return
        }

        if (file.size > 2 * 1024 * 1024) {
          this.$store.dispatch('ui/setToastError', this.$t('pages.authBanners.tooLarge'))
          return
        }

        this.releasePreview()
        this.awaitingFit = true
        this.photo = file
        this.localPreview = URL.createObjectURL(file)
        this.toast(this.$t('pages.authBanners.imageReady'))
      },

      clearImage() {

        this.releasePreview()
        this.photo = null
        this.form.image = ''

        if (this.$refs.file) {
          this.$refs.file.value = ''
        }
      },

      /* ── persistence ── */

      async save() {

        this.hasError = true

        if (!this.form.title || this.invalidDates) {
          return
        }

        try {
          this.saving = true

          const saved = await this.setById({
            id: this.editing ? this.editing.id : '',
            api: 'setAuthBanner',
            params: {...this.form}
          })

          if (!saved) {
            return
          }

          // Artwork goes up separately so the record always exists first.
          if (this.photo) {

            const fd = new FormData()
            fd.append('photo', this.photo)

            await this.setImageById({
              id: saved.id,
              api: 'uploadAuthBanner',
              params: fd
            })
          }

          const wasEditing = !!this.editing

          this.closeEditor()
          await this.getBanners()

          this.toast(wasEditing ? this.$t('pages.authBanners.saved') : this.$t('pages.authBanners.created'))

        } catch (e) {
          this.toastError(e)
        } finally {
          this.saving = false
        }
      },

      async removeFromEditor() {

        const banner = this.editing

        if (!banner) {
          return
        }

        if (!confirm(this.$t('admin.dltMsg'))) {
          return
        }

        try {
          await this.deleteData({params: banner.id, api: 'deleteAuthBanner'})

          if (this.previewed && this.previewed.id === banner.id) {
            this.previewed = null
          }

          this.closeEditor()
          await this.getBanners()
          this.toast(this.$t('pages.authBanners.deleted'))

        } catch (e) {
          this.toastError(e)
        }
      },

      async toggleStatus(banner) {

        try {
          this.toggling = banner.id

          const updated = await this.setById({
            id: banner.id,
            api: 'setAuthBanner',
            params: {
              ...banner,
              status: banner.status === 1 ? 0 : 1
            }
          })

          if (updated) {
            this.$set(this.banners, this.banners.indexOf(banner), updated)

            if (this.previewed && this.previewed.id === updated.id) {
              this.previewed = updated
            }

            this.toast(updated.status === 1 ? this.$t('pages.authBanners.nowLive') : this.$t('pages.authBanners.turnedOff'))
          }

        } catch (e) {
          this.toastError(e)
        } finally {
          this.toggling = null
        }
      },

      async move(from, to) {

        const reordered = this.banners.slice()
        reordered.splice(to, 0, reordered.splice(from, 1)[0])
        this.banners = reordered

        try {
          this.reordering = true

          await this.setRequest({
            api: 'reorderAuthBanners',
            params: {ids: reordered.map(i => i.id)}
          })

          this.toast(to < from ? this.$t('pages.authBanners.movedUp') : this.$t('pages.authBanners.movedDown'))

        } catch (e) {
          this.toastError(e)
          await this.getBanners()
        } finally {
          this.reordering = false
        }
      },

      async removeBanner(banner) {

        if (!confirm(this.$t('admin.dltMsg'))) {
          return
        }

        try {
          await this.deleteData({params: banner.id, api: 'deleteAuthBanner'})

          if (this.previewed && this.previewed.id === banner.id) {
            this.previewed = null
          }

          await this.getBanners()
          this.toast(this.$t('pages.authBanners.deleted'))

        } catch (e) {
          this.toastError(e)
        }
      },

      async getLinkOptions() {

        try {
          this.linkOptions = await this.getRequest({params: {}, api: 'getAuthBannerLinks'}) || []

        } catch (e) {
          /*
           * A missing page list must not stop the page loading — the field
           * still accepts a typed path — but it should never fail silently:
           * that made an unrestarted dev server (jsconfig.json is a static
           * webpack import, so HMR does not pick up new API keys) look like
           * an empty list rather than a broken request.
           */
          this.linkOptions = []
          this.toastError(e)
        }
      },

      async getBanners() {

        try {
          this.loading = true

          this.banners = await this.getRequest({params: {}, api: 'getAuthBanners'}) || []

          const stillThere = this.previewed
            && this.banners.find(i => i.id === this.previewed.id)

          this.previewed = stillThere || this.banners[0] || null

        } catch (e) {
          this.toastError(e)
        } finally {
          this.loading = false
        }
      },

      ...mapActions('common', ['getRequest', 'setRequest', 'setById', 'setImageById', 'deleteData'])
    },

    mounted() {

      document.addEventListener('keydown', this.onEscape)

      if (this.$can('banner', 'view')) {
        this.getBanners()
        this.getLinkOptions()
      } else {
        this.loading = false
      }
    },

    beforeDestroy() {
      document.removeEventListener('keydown', this.onEscape)
      this.releasePreview()
      document.body.style.overflow = ''
    }
  }
</script>

<style lang="stylus">

$ink = #16174A
$brand = #2D2DA0
$brand-50 = #F1F1FC
$go = #00A24A
$wash = #F7F7FD
$line = #E4E5F3
$line-strong = #CFD1E8
$muted = #6B7191
$danger = #C8324B
$amber = #B26A00

.ab-page
  padding 4px 0 40px

.ab-crumb
  font-size 12.5px
  color $muted
  margin 0 0 12px

.ab-head
  display flex
  align-items flex-start
  justify-content space-between
  gap 20px
  flex-wrap wrap
  margin-bottom 22px

.ab-title
  font-size 24px
  margin 0 0 4px
  letter-spacing -0.02em
  color $ink

.ab-lede
  color $muted
  margin 0
  max-width 66ch
  font-size 14px

/* ── page-level preview ── */
.ab-preview-wrap
  background #fff
  border 1px solid $line
  border-radius 16px
  padding 20px
  margin-bottom 26px

.ab-preview-title
  font-size 16px
  margin 0 0 4px
  color $ink

.ab-preview-sub
  font-size 13px
  color $muted
  margin 0 0 16px

.ab-mock
  border 1px solid $line
  border-radius 12px
  overflow hidden

/* ── cards ── */
.ab-grid
  display grid
  grid-template-columns repeat(auto-fill, minmax(320px, 1fr))
  gap 16px

.ab-card
  background #fff
  border 1px solid $line
  border-radius 16px
  overflow hidden
  display flex
  flex-direction column

.ab-card-thumb
  height 132px
  position relative
  background linear-gradient(135deg, #2D2DA0, #6E6EDB)
  display grid
  place-items center
  /*
   * base.styl gives every img `width:100%` with no height, so a portrait or
   * square thumbnail would stand 380px tall and bleed over the whole card.
   * The rule below fixes the size; this clips it whatever happens to that rule.
   */
  overflow hidden

  img
    width auto
    height 132px
    object-fit cover
    display block

.ab-noimg
  color rgba(255, 255, 255, 0.9)
  font-size 11px
  font-weight 700
  letter-spacing 0.1em
  text-transform uppercase
  border 1px dashed rgba(255, 255, 255, 0.6)
  padding 8px 12px
  border-radius 8px
  text-align center

.ab-card-body
  padding 15px 16px 16px
  flex 1
  display flex
  flex-direction column
  gap 6px

  h3
    margin 0
    font-size 15.5px
    letter-spacing -0.01em
    color $ink

  p
    margin 0
    font-size 13px
    color $muted
    line-height 1.45

.ab-meta
  display flex
  flex-wrap wrap
  gap 6px
  margin-top 8px

.ab-tag
  font-size 11px
  font-weight 700
  padding 4px 9px
  border-radius 999px
  background $brand-50
  color $brand

  &.live
    background #EAF9F0
    color #0B6B3A

  &.off
    background #F1F2F7
    color $muted

  &.sched
    background #FFF6E6
    color $amber

.ab-card-acts
  display flex
  align-items center
  gap 8px
  padding 12px 16px
  border-top 1px solid $line
  background #FCFCFE
  flex-wrap wrap

.ab-icon-btn
  min-width 34px

.ab-danger-btn
  color $danger
  border-color #EFC3CC

.ab-switch
  display inline-flex
  align-items center
  margin-left auto
  cursor pointer

  /*
   * payment.styl styles every input[type=checkbox] globally (20px box, 2px
   * border, opacity .5) at the same specificity a bare `input` selector would
   * have, so the attribute has to be repeated here to actually hide the box.
   */
  input[type="checkbox"]
    width 0
    height 0
    min-width 0
    padding 0 !important
    margin 0
    border 0
    opacity 0
    position absolute
    appearance none
    -webkit-appearance none

.ab-switch-inline
  margin-left 0
  gap 9px

.ab-switch-text
  color $ink
  font-weight 600
  font-size 13.5px

.ab-track
  width 38px
  height 21px
  border-radius 999px
  background $line-strong
  position relative
  transition 0.18s
  flex-shrink 0

  &:after
    content ""
    position absolute
    top 2.5px
    left 2.5px
    width 16px
    height 16px
    border-radius 50%
    background #fff
    transition 0.18s

.ab-switch input[type="checkbox"]:checked + .ab-track
  background $go

  &:after
    transform translateX(17px)

.ab-empty
  background #fff
  border 1px dashed $line-strong
  border-radius 16px
  padding 40px
  text-align center
  color $muted

  b
    display block
    color $ink
    font-size 15px
    margin-bottom 6px

/* ══ builder ══ */
.ab-scrim
  position fixed
  inset 0
  background rgba(22, 23, 74, 0.34)
  opacity 0
  pointer-events none
  transition opacity 0.2s
  z-index 60

  &.on
    opacity 1
    pointer-events auto

.ab-modal
  position fixed
  inset 0
  background #fff
  z-index 70
  display flex
  flex-direction column
  opacity 0
  pointer-events none
  transform scale(0.99)
  transition opacity 0.2s ease, transform 0.2s ease

  &.on
    opacity 1
    pointer-events auto
    transform none

.ab-modal-head
  display flex
  align-items center
  justify-content space-between
  gap 16px
  padding 16px 26px
  border-bottom 1px solid $line
  background #fff
  flex-shrink 0

  h2
    margin 0
    font-size 19px
    letter-spacing -0.01em
    color $ink

.ab-modal-sub
  margin 2px 0 0
  font-size 13px
  color $muted

.ab-modal-head-acts
  display flex
  align-items center
  gap 18px

.ab-x
  background none
  border 0
  cursor pointer
  width 38px
  height 38px
  padding 0
  border-radius 10px
  color $muted
  font-size 22px
  line-height 1

  &:hover
    background $brand-50
    color $brand

.ab-modal-body
  flex 1
  overflow auto
  display grid
  grid-template-columns minmax(0, 1fr) minmax(0, 1fr)
  gap 0
  align-items start

.ab-modal-form
  padding 22px 34px 44px
  max-width 700px
  width 100%
  justify-self end
  border-right 1px solid $line

/*
 * The admin base sheet styles inputs and textareas but leaves <select> to the
 * browser, and only spaces labels inside a <form>. Both are set out here so
 * the builder's controls line up whatever wraps them.
 */
.ab-modal-form
  .input-wrapper
    margin-bottom 16px

  label
    display block
    font-size 13px
    font-weight 600
    color $ink
    margin-bottom 6px

  label.ab-check
    display inline-flex
    align-items center
    gap 8px
    font-weight 400
    margin-bottom 0
    cursor pointer

  select
    width 100%
    height 42px
    padding 0 34px 0 13px
    border 1px solid $line-strong
    border-radius 8px
    background #fff url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236B7191' d='M1 1l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 13px center
    color $ink
    font-size 14px
    font-family inherit
    appearance none
    -webkit-appearance none
    cursor pointer

    &:focus
      border-color $brand

  textarea
    min-height 88px
    font-family inherit
    font-size 14px
    line-height 1.5

  input[type="date"]
    padding-right 10px

.ab-fs
  padding 0 0 22px
  margin-bottom 22px
  border-bottom 1px solid $line

  &:last-child
    border-bottom 0
    margin-bottom 0

  h3
    font-size 11px
    letter-spacing 0.12em
    text-transform uppercase
    color $muted
    margin 0 0 16px
    font-weight 800

.ab-two
  display grid
  grid-template-columns 1fr 1fr
  gap 14px

.ab-req
  color $danger

/* Hint and counter share one row, so the counter can't drift onto a neighbour. */
.ab-hint
  display flex
  align-items baseline
  justify-content space-between
  gap 12px
  font-size 12.5px
  color $muted
  margin 6px 0 0
  line-height 1.45

.ab-counter
  flex-shrink 0
  font-variant-numeric tabular-nums

.ab-check
  display inline-flex
  align-items center
  gap 8px
  cursor pointer
  font-size 13px
  color $ink
  margin-bottom 0

  /* Same global-checkbox override as .ab-switch, but this box stays visible. */
  input[type="checkbox"]
    width 17px
    height 17px
    min-width 17px
    margin 0
    padding 0 !important
    border 1.5px solid $line-strong
    border-radius 4px
    background #fff
    opacity 1
    cursor pointer
    appearance none
    -webkit-appearance none
    position relative

    &:checked
      background $brand
      border-color $brand

    &:checked:before
      content ""
      position absolute
      left 5px
      top 1.5px
      width 4px
      height 9px
      border solid #fff
      border-width 0 2px 2px 0
      transform rotate(45deg)
      margin 0

.ab-spaced
  margin-top 16px

.ab-drop
  border 1px dashed $line-strong
  border-radius 12px
  padding 26px
  text-align center
  cursor pointer
  background $wash
  transition 0.15s

  &:hover,
  &.over
    border-color $brand
    background $brand-50

  b
    display block
    font-size 14px
    color $ink
    margin-bottom 5px

  span
    font-size 12.5px
    color $muted

.ab-shot
  display flex
  align-items flex-start
  gap 12px
  flex-wrap wrap

  img
    width 150px
    height 190px
    object-fit cover
    border-radius 12px
    border 1px solid $line

.ab-shot-acts
  display flex
  flex-direction column
  gap 8px

.ab-colors
  display flex
  align-items center
  gap 10px
  flex-wrap wrap

.ab-color
  display inline-flex
  align-items center
  gap 6px

  input[type="color"]
    width 44px
    height 42px
    padding 3px
    border 1px solid $line-strong
    border-radius 8px
    background #fff
    cursor pointer

  input[type="text"]
    width 104px
    text-transform uppercase

/* ── builder preview ── */
.ab-modal-preview
  padding 22px 26px 40px
  background $wash
  position sticky
  top 0
  align-self start
  min-width 0

.ab-mp-bar
  display flex
  align-items center
  gap 12px
  margin-bottom 12px

.ab-mp-reset
  margin-left 4px

.ab-mp-label
  font-size 11px
  letter-spacing 0.12em
  text-transform uppercase
  color $muted
  font-weight 800

.ab-mp-tog
  margin-left auto
  display flex
  border 1px solid $line-strong
  border-radius 999px
  overflow hidden
  background #fff

  button
    width auto
    border 0
    background none
    padding 0 16px
    height 34px
    line-height 34px
    font-size 12.5px
    font-weight 700
    color $muted
    cursor pointer
    border-radius 0

    &.on
      background $brand
      color #fff

.ab-mp-hint
  font-size 12.5px
  color $muted
  margin 12px 0 0
  line-height 1.45

.ab-modal-foot
  display flex
  align-items center
  gap 10px
  padding 14px 26px
  border-top 1px solid $line
  background #fff
  flex-shrink 0

.ab-modal-foot-state
  margin-left auto
  font-size 12.5px
  color $muted
  font-weight 600

@media (max-width 900px)

  .ab-modal-body
    grid-template-columns 1fr

  .ab-modal-form
    max-width none
    justify-self stretch
    border-right 0
    padding 18px 16px 26px
    order 2

  .ab-modal-preview
    position static
    order 1
    padding 16px

  .ab-modal-head,
  .ab-modal-foot
    padding 12px 16px

@media (max-width 640px)

  .ab-two
    grid-template-columns 1fr

</style>
