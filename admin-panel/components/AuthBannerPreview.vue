<template>

  <!--
    A true-to-scale replica of the shop sign-in screen. The frame is laid out
    at the real viewport size and then scaled down, so every measurement here
    is copied verbatim from frontend-panel (layouts/empty.vue and
    components/AuthSidebarSlider.vue) rather than eyeballed. Change one there,
    change it here.
  -->
  <div
    ref="fit"
    class="abp-fit"
    :style="{ height: `${Math.round(height * scale)}px` }"
  >

    <div
      class="abp-shell"
      :class="device"
      :style="{
        width: `${width}px`,
        height: `${height}px`,
        transform: `scale(${scale})`
      }"
    >

      <!-- ── left: the banner stage ── -->
      <aside
        ref="stage"
        class="abp-stage"
      >

        <div class="abp-stage-media">

          <!--
            The placeholder is the backdrop, not an either/or: artwork that
            does not fill the stage lets the chosen colours show around it.
          -->
          <div
            class="abp-stage-ph"
            :style="placeholderStyle"
          />

          <img
            v-if="imageSrc"
            class="abp-stage-img"
            :class="{ draggable: editable, dragging: dragging === 'move' }"
            :src="imageSrc"
            :style="imageStyle"
            alt=""
            draggable="false"
            @load="onImageLoad"
            @mousedown="startMove"
          >

        </div>

        <!-- Stretch handles, sitting on the edges of the image's own frame. -->
        <div
          v-if="editable && imageSrc"
          class="abp-handles"
          :style="imageStyle"
        >
          <span
            class="abp-handle e"
            :title="$t('cmp.AuthBannerPreview.stretchWidth')"
            @mousedown="startResize($event, 'x')"
          />
          <span
            class="abp-handle s"
            :title="$t('cmp.AuthBannerPreview.stretchHeight')"
            @mousedown="startResize($event, 'y')"
          />
          <span
            class="abp-handle se"
            :title="$t('cmp.AuthBannerPreview.stretchBoth')"
            @mousedown="startResize($event, 'xy')"
          />
        </div>

        <div class="abp-slide-scrim" />

        <div
          class="abp-slide"
          :style="{ color: textColor }"
        >

          <span
            v-if="banner.eyebrow"
            class="abp-slide-eyebrow"
          >
            {{ banner.eyebrow }}
          </span>

          <h2 class="abp-slide-title">
            {{ banner.title || $t('cmp.AuthBannerPreview.headlinePlaceholder') }}
          </h2>

          <p
            v-if="banner.sub"
            class="abp-slide-sub"
          >
            {{ banner.sub }}
          </p>

          <span
            v-if="banner.cta"
            class="abp-slide-cta"
          >
            {{ banner.cta }}
          </span>

        </div>

        <!-- The real stage only draws ticks once there is something to rotate. -->
        <div
          v-if="slideCount > 1"
          class="abp-stage-nav"
        >
          <span
            v-for="n in slideCount"
            :key="n"
            class="abp-tick"
            :class="{ on: n === 1 }"
          />
        </div>

      </aside>

      <!-- ── right: the sign-in form ── -->
      <main class="abp-panel">

        <div class="abp-panel-inner">

          <div class="abp-brand">
            <span class="abp-brand-logo">FixMyPC</span>
          </div>

          <h1 class="abp-h1">{{ $t('cmp.AuthBannerPreview.signInTitle') }}</h1>

          <p class="abp-lede">{{ $t('cmp.AuthBannerPreview.lede') }}</p>

          <div class="abp-group">
            <span class="abp-label">{{ $t('cmp.AuthBannerPreview.emailAddress') }}</span>
            <span class="abp-input" />
          </div>

          <div class="abp-group">
            <span class="abp-label">{{ $t('cmp.AuthBannerPreview.password') }}</span>
            <span class="abp-input" />
          </div>

          <span class="abp-btn">{{ $t('cmp.AuthBannerPreview.signIn') }}</span>

          <p class="abp-foot">
            {{ $t('cmp.AuthBannerPreview.newTo') }}
            <span class="abp-foot-link">{{ $t('cmp.AuthBannerPreview.createAccount') }}</span>
          </p>

          <div class="abp-powered">
            <span>{{ $t('cmp.AuthBannerPreview.poweredBy') }}</span>
            <span class="abp-powered-logo">excytech</span>
          </div>

        </div>

      </main>

    </div>

  </div>

</template>

<script>

  /* The two viewports the toggle switches between, at real pixel sizes. */
  const FRAMES = {
    desktop: {width: 1280, height: 800, maxHeight: 470},
    mobile: {width: 390, height: 780, maxHeight: 520}
  }

  const DEFAULT_BG_FROM = '#2D2DA0'
  const DEFAULT_BG_TO = '#7A7AE0'
  const DEFAULT_TEXT_COLOR = '#FFFFFF'

  const MIN_SIZE = 10
  const MAX_SIZE = 400

  /** A saved banner sends these as numbers, an untouched form as undefined. */
  const num = (value, fallback) =>
    (value === null || value === undefined || value === '') ? fallback : Number(value)

  const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

  export default {

    name: "AuthBannerPreview",

    props: {

      banner: {
        type: Object,
        default: () => ({})
      },

      /** Already-resolved src: a saved image URL or a pending object URL. */
      imageSrc: {
        type: String,
        default: ''
      },

      device: {
        type: String,
        default: 'desktop'
      },

      /** How many banners will be rotating alongside this one. */
      slideCount: {
        type: Number,
        default: 1
      },

      /** Turns on drag-to-move and the stretch handles. */
      editable: {
        type: Boolean,
        default: false
      }
    },

    data() {
      return {
        scale: 0.35,
        dragging: null,
        // Natural fit of the loaded artwork, so it can be re-applied on demand.
        lastFit: null
      }
    },

    computed: {

      frame() {
        return FRAMES[this.device] || FRAMES.desktop
      },

      width() {
        return this.frame.width
      },

      height() {
        return this.frame.height
      },

      textColor() {
        return this.banner.text_color || DEFAULT_TEXT_COLOR
      },

      placeholderStyle() {

        const from = this.banner.bg_from || DEFAULT_BG_FROM
        const to = this.banner.bg_to || DEFAULT_BG_TO

        return {backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)`}
      },

      /**
       * The artwork is a box whose centre sits at image_x / image_y and whose
       * size is a percentage of the stage. `fill` rather than `cover`, so the
       * whole picture is always visible and the handles genuinely stretch it.
       */
      imageStyle() {

        const b = this.banner

        return {
          left: `${num(b.image_x, 50)}%`,
          top: `${num(b.image_y, 50)}%`,
          width: `${num(b.image_w, 100)}%`,
          height: `${num(b.image_h, 100)}%`
        }
      }
    },

    watch: {
      device() {
        this.$nextTick(this.fit)
      }
    },

    methods: {

      /** Unscaled size of the stage, which every percentage is relative to. */
      stageBox() {

        const stage = this.$refs.stage

        return stage
          ? {w: stage.offsetWidth, h: stage.offsetHeight}
          : {w: this.width, h: this.height}
      },

      /**
       * A newly chosen image should land whole and undistorted, so report the
       * box that letterboxes it inside the stage. The parent decides whether
       * to apply it — an already-placed banner keeps what was saved.
       */
      onImageLoad(e) {

        if (!this.editable) {
          return
        }

        const img = e.target
        const stage = this.stageBox()

        if (!img.naturalWidth || !img.naturalHeight || !stage.w || !stage.h) {
          return
        }

        const stageRatio = stage.w / stage.h
        const imageRatio = img.naturalWidth / img.naturalHeight

        this.lastFit = imageRatio > stageRatio
          ? {w: 100, h: Math.round(100 * stageRatio / imageRatio)}
          : {w: Math.round(100 * imageRatio / stageRatio), h: 100}

        this.$emit('natural', this.lastFit)
      },

      /** Re-apply the natural fit without needing the image to reload. */
      refit() {
        this.$emit('natural', this.lastFit || {w: 100, h: 100})
      },

      /** Drag the artwork itself to move it around the stage. */
      startMove(e) {

        if (!this.editable || !this.imageSrc) {
          return
        }

        const b = this.banner
        const from = {x: num(b.image_x, 50), y: num(b.image_y, 50)}

        this.track(e, 'move', (dx, dy, stage) => {
          this.$emit('position', {
            x: Math.round(clamp(from.x + dx / stage.w * 100, 0, 100)),
            y: Math.round(clamp(from.y + dy / stage.h * 100, 0, 100))
          })
        })
      },

      /**
       * Pull an edge to stretch. The box is centred on its own position, so
       * an edge moving out by one pixel grows the box by two.
       */
      startResize(e, axis) {

        const b = this.banner
        const from = {w: num(b.image_w, 100), h: num(b.image_h, 100)}

        this.track(e, axis, (dx, dy, stage) => {

          const next = {}

          if (axis.indexOf('x') !== -1) {
            next.w = Math.round(clamp(from.w + dx * 2 / stage.w * 100, MIN_SIZE, MAX_SIZE))
          }

          if (axis.indexOf('y') !== -1) {
            next.h = Math.round(clamp(from.h + dy * 2 / stage.h * 100, MIN_SIZE, MAX_SIZE))
          }

          this.$emit('resize', next)
        })
      },

      /**
       * Shared pointer bookkeeping. Deltas are divided by the preview's scale
       * so a drag tracks the cursor 1:1 despite the frame being shrunk.
       */
      track(e, kind, onMove) {

        e.preventDefault()
        e.stopPropagation()

        const startX = e.clientX
        const startY = e.clientY
        const stage = this.stageBox()

        const move = (m) => onMove(
          (m.clientX - startX) / this.scale,
          (m.clientY - startY) / this.scale,
          stage
        )

        const up = () => {
          this.dragging = null
          window.removeEventListener('mousemove', move)
          window.removeEventListener('mouseup', up)
        }

        this.dragging = kind
        window.addEventListener('mousemove', move)
        window.addEventListener('mouseup', up)
      },

      /**
       * Shrink the real-size frame until it fits the column it sits in, and
       * never taller than the panel can spare.
       */
      fit() {

        const box = this.$refs.fit

        if (!box || !box.clientWidth) {
          return
        }

        this.scale = Math.min(
          box.clientWidth / this.width,
          this.frame.maxHeight / this.height
        )
      }
    },

    mounted() {
      this.fit()
      window.addEventListener('resize', this.fit)
    },

    beforeDestroy() {
      window.removeEventListener('resize', this.fit)
    }
  }
</script>

<style lang="stylus">

/*
 * Every value below is lifted from frontend-panel. The wrapper scales the
 * whole thing, so nothing here is adjusted for the admin's smaller canvas.
 */

.abp-fit
  width 100%
  overflow hidden

.abp-shell
  transform-origin top left
  display grid
  grid-template-columns minmax(0, 1.05fr) minmax(0, 1fr)
  background #FFFFFF
  border-radius 0
  overflow hidden

  &.mobile
    grid-template-columns 1fr
    grid-template-rows 280px minmax(0, 1fr)

/* ── stage ── */
.abp-stage
  position relative
  overflow hidden
  background #F7F7FD
  isolation isolate
  display flex
  flex-direction column
  justify-content flex-end
  align-items flex-start
  text-align left
  padding 44px

.abp-shell.mobile .abp-stage
  padding 24px 22px 56px

.abp-stage-media
  position absolute
  inset 0
  z-index -2
  overflow hidden

  img
    position absolute
    transform translate(-50%, -50%)
    /* Never cropped: the picture fills exactly the box the handles set. */
    object-fit fill
    display block

  img.draggable
    cursor grab
    user-select none

  img.dragging
    cursor grabbing

/* ── stretch handles ── */
.abp-handles
  position absolute
  transform translate(-50%, -50%)
  z-index 4
  pointer-events none
  outline 2px dashed rgba(255, 255, 255, 0.85)
  outline-offset -1px

.abp-handle
  position absolute
  pointer-events auto
  background #fff
  border 2px solid #2D2DA0
  border-radius 4px
  box-shadow 0 2px 8px rgba(22, 23, 74, 0.35)

  &.e
    width 14px
    height 34px
    right -7px
    top 50%
    margin-top -17px
    cursor ew-resize

  &.s
    width 34px
    height 14px
    bottom -7px
    left 50%
    margin-left -17px
    cursor ns-resize

  &.se
    width 16px
    height 16px
    right -8px
    bottom -8px
    border-radius 50%
    cursor nwse-resize

.abp-stage-ph
  position absolute
  inset 0
  background linear-gradient(135deg, #2D2DA0 0%, #4A4ACD 48%, #7A7AE0 100%)

.abp-slide-scrim
  position absolute
  inset 0
  z-index -1
  background linear-gradient(180deg, rgba(12, 13, 45, 0.1) 0%, rgba(12, 13, 45, 0.3) 45%, rgba(12, 13, 45, 0.82) 100%)

.abp-slide
  max-width 520px

.abp-slide-eyebrow
  display inline-flex
  align-items center
  gap 8px
  font-size 11px
  font-weight 700
  letter-spacing 0.13em
  text-transform uppercase
  color inherit
  margin-bottom 14px
  background rgba(255, 255, 255, 0.16)
  border 1px solid currentColor
  padding 6px 12px
  border-radius 999px

.abp-slide-title
  font-size 38px
  line-height 1.1
  font-weight 800
  letter-spacing -0.02em
  margin 0 0 12px
  color inherit

.abp-shell.mobile .abp-slide-title
  font-size 22px
  margin 0 0 10px

.abp-slide-sub
  font-size 16px
  color inherit
  opacity 0.85
  margin 0 0 22px
  max-width 44ch

.abp-shell.mobile .abp-slide-sub
  display none

.abp-slide-cta
  display inline-flex
  align-items center
  gap 8px
  background #fff
  color #16174A
  font-weight 700
  font-size 14px
  padding 12px 20px
  border-radius 999px
  box-shadow 0 8px 28px rgba(22, 23, 74, 0.1)

.abp-shell.mobile .abp-slide-cta
  padding 10px 16px
  font-size 13px

.abp-shell.mobile .abp-slide-eyebrow
  font-size 10px
  padding 5px 10px
  margin-bottom 10px

.abp-stage-nav
  position absolute
  left 44px
  bottom 36px
  z-index 3
  display flex
  gap 8px

.abp-shell.mobile .abp-stage-nav
  left 22px
  bottom 20px

.abp-tick
  width 34px
  height 4px
  border-radius 999px
  background rgba(255, 255, 255, 0.34)

  &.on
    background #fff

/* ── form panel ── */
.abp-panel
  display flex
  flex-direction column
  padding 40px 48px
  overflow hidden

.abp-shell.mobile .abp-panel
  padding 28px 24px 48px

.abp-panel-inner
  width 100%
  max-width 430px
  margin auto
  padding 24px 0

.abp-shell.mobile .abp-panel-inner
  max-width 520px
  padding 0 0 8px

.abp-brand
  display flex
  align-items center
  margin-bottom 30px

.abp-brand-logo
  font-size 22px
  font-weight 800
  letter-spacing -0.02em
  color #2D2DA0

.abp-h1
  font-size 27px
  line-height 1.2
  letter-spacing -0.02em
  font-weight 800
  color #16174A
  margin 0 0 8px

.abp-shell.mobile .abp-h1
  font-size 23px

.abp-lede
  color #6B7191
  margin 0 0 26px
  font-size 14.5px

.abp-group
  margin-bottom 16px

.abp-label
  display block
  font-size 13.5px
  font-weight 600
  color #16174A
  margin-bottom 7px

.abp-input
  display block
  width 100%
  height 49px
  background #FFFFFF
  border 1px solid #CFD1E8
  border-radius 14px

.abp-btn
  display flex
  align-items center
  justify-content center
  width 100%
  line-height 1.5
  padding 15px 20px
  background-color #00A24A
  color #FFFFFF
  font-size 15.5px
  font-weight 700
  border-radius 999px

.abp-foot
  text-align center
  font-size 13.5px
  color #6B7191
  margin 22px 0 0

.abp-foot-link
  color #2D2DA0
  font-weight 600

.abp-powered
  display flex
  align-items center
  justify-content center
  gap 8px
  color #9AA0BC
  font-size 12.5px
  margin-top 34px

.abp-powered-logo
  display flex
  align-items center
  height 20px
  font-weight 700

</style>
