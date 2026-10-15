<template>
  <setting-layout
    active-route="cart-reservation"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <div class="cr-page">

        <div class="cr-head">
          <h4>{{ $t('setting.cartRes') }}</h4>
          <p>{{ $t('setting.cartResSub') }}</p>
        </div>

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

        <form
          v-else
          novalidate
          @submit.prevent="save"
        >
          <section
            class="cr-timeline"
            :class="{'is-invalid': !valid}"
            aria-live="polite"
          >
            <p class="cr-label">{{ $t('setting.cartTimeline') }}</p>

            <div class="cr-track">
              <span
                class="cr-gap"
                :style="{left: 0, width: remindAt + '%'}"
              >{{ duration(result.cart_remind_minutes) }}</span>
              <span
                class="cr-gap"
                :style="{left: remindAt + '%', width: (100 - remindAt) + '%'}"
              >{{ $t('setting.cartLater', {time: duration(result.cart_expire_minutes - result.cart_remind_minutes)}) }}</span>

              <div class="cr-line">
                <div class="cr-fill" :style="{width: remindAt + '%'}"></div>
              </div>

              <span class="cr-dot start" style="left: 0"></span>
              <span class="cr-dot remind" :style="{left: remindAt + '%'}"></span>
              <span class="cr-dot end" style="left: 100%"></span>
            </div>

            <div class="cr-steps">
              <div class="cr-step start">
                <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
                <div>
                  <b>{{ $t('setting.cartStepAdded') }}</b>
                  <span>{{ duration(0) }}</span>
                </div>
              </div>
              <div
                class="cr-step remind"
                :style="{left: remindAt + '%'}"
              >
                <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                <div>
                  <b>{{ $t('setting.cartStepRemind') }}</b>
                  <span>{{ valid ? duration(result.cart_remind_minutes) : '–' }}</span>
                </div>
              </div>
              <div class="cr-step end">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                <div>
                  <b>{{ $t('setting.cartStepEmpty') }}</b>
                  <span>{{ valid ? duration(result.cart_expire_minutes) : '–' }}</span>
                </div>
              </div>
            </div>
          </section>

          <div class="cr-cards">
            <div
              class="cr-card"
              :class="{'has-err': !!remindError}"
            >
              <div class="cr-card-head">
                <span class="cr-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
                <h5>{{ $t('setting.cartRemindTitle') }}</h5>
              </div>

              <label
                class="cr-label"
                for="cart-remind-minutes"
              >{{ $t('setting.cartRemind') }}</label>
              <div class="cr-input">
                <input
                  id="cart-remind-minutes"
                  type="number"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  v-model.number="result.cart_remind_minutes"
                  :aria-invalid="!!remindError"
                  aria-describedby="cart-remind-hint"
                >
                <span>{{ $t('setting.cartMinutes') }}</span>
              </div>
              <field-error :messages="remindError ? [remindError] : null" />
              <p
                id="cart-remind-hint"
                class="cr-hint"
              >{{ $t('setting.cartRemindHint') }}</p>
            </div>

            <div
              class="cr-card"
              :class="{'has-err': !!expireError}"
            >
              <div class="cr-card-head">
                <span class="cr-icon"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></span>
                <h5>{{ $t('setting.cartExpireTitle') }}</h5>
              </div>

              <label
                class="cr-label"
                for="cart-expire-minutes"
              >{{ $t('setting.cartExpire') }}</label>
              <div class="cr-input">
                <input
                  id="cart-expire-minutes"
                  type="number"
                  min="2"
                  :max="maxMinutes"
                  step="1"
                  inputmode="numeric"
                  v-model.number="result.cart_expire_minutes"
                  :aria-invalid="!!expireError"
                  aria-describedby="cart-expire-hint"
                >
                <span>{{ $t('setting.cartMinutes') }}</span>
              </div>
              <field-error :messages="expireError ? [expireError] : null" />
              <p
                id="cart-expire-hint"
                class="cr-hint"
              >{{ $t('setting.cartExpireHint') }}</p>
            </div>
          </div>

          <p class="cr-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>{{ $t('setting.cartNote') }}</span>
          </p>

          <ajax-button
            class="primary-btn"
            :text="$t('setting.sv')"
            :fetching-data="saving"
          />
        </form>

      </div>
    </template>
  </setting-layout>
</template>

<script>
  import {mapActions, mapGetters} from 'vuex'
  import SettingLayout from "~/components/partials/SettingLayout";
  import AjaxButton from "~/components/AjaxButton";
  import FieldError from "~/components/FieldError";
  import Spinner from "~/components/Spinner";
  import util from "~/mixin/util";

  // Same limit as CartHold::MAX_EXPIRE_MINUTES - the server refuses more.
  const MAX_MINUTES = 1440

  /**
   * When a reserved basket gets its reminder email and when it is emptied
   * and its stock restored. Both count from the last add (CartHold).
   */
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        result: {
          cart_remind_minutes: 5,
          cart_expire_minutes: 20
        },
        maxMinutes: MAX_MINUTES,
        loading: false,
        saving: false
      }
    },
    mixins: [util],
    components: {
      Spinner,
      FieldError,
      AjaxButton,
      SettingLayout
    },
    computed: {
      remindError() {
        const remind = this.result.cart_remind_minutes
        if (!Number.isInteger(remind) || remind < 1) {
          return this.$t('setting.cartWholeErr')
        }
        if (Number.isInteger(this.result.cart_expire_minutes) && remind >= this.result.cart_expire_minutes) {
          return this.$t('setting.cartRemindErr')
        }
        return null
      },
      expireError() {
        const expire = this.result.cart_expire_minutes
        if (!Number.isInteger(expire) || expire < 2) {
          return this.$t('setting.cartWholeErr')
        }
        if (expire > MAX_MINUTES) {
          return this.$t('setting.cartMaxErr', {max: MAX_MINUTES})
        }
        return null
      },
      valid() {
        return !this.remindError && !this.expireError
      },
      // Where the reminder sits on the timeline, in %. Kept off the ends so
      // its label never sits on top of the first or last one.
      remindAt() {
        if (!this.valid) {
          return 50
        }
        const at = this.result.cart_remind_minutes / this.result.cart_expire_minutes * 100
        return Math.min(Math.max(Math.round(at), 25), 75)
      },
      ...mapGetters('setting', ['setting'])
    },
    methods: {
      duration(minutes) {
        if (!Number.isInteger(minutes) || minutes < 0) {
          return '–'
        }
        if (minutes < 60) {
          return this.$t('setting.cartMin', {n: minutes})
        }
        const h = Math.floor(minutes / 60)
        const m = minutes % 60
        return m ? this.$t('setting.cartHourMin', {h, m}) : this.$t('setting.cartHour', {h})
      },
      fill(setting) {
        // A settings row from before the migration has neither column.
        this.result = {
          cart_remind_minutes: parseInt(setting?.cart_remind_minutes) || 5,
          cart_expire_minutes: parseInt(setting?.cart_expire_minutes) || 20
        }
      },
      async save() {
        this.setErrors()
        if (!this.valid) {
          const first = this.$el.querySelector('[aria-invalid="true"]')
          first && first.focus()
          return
        }

        this.saving = true
        try {
          const data = await this.setRequest({params: this.result, api: 'cartReservation'})

          // A refusal resolves with the 201 body, a save with the saved values.
          if (data && data.cart_expire_minutes) {
            this.$store.commit('setting/SET_SETTING', data)
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.saving = false
      },
      ...mapActions('setting', ['getSetting']),
      ...mapActions('ui', ['setErrors']),
      ...mapActions('common', ['setRequest'])
    },
    async mounted() {
      if (!this.setting) {
        this.loading = true
        try {
          await this.getSetting()
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.loading = false
      }
      this.fill(this.setting)
    }
  }
</script>

<style lang="stylus" scoped>
  .cr-page
    max-width 920px

  .cr-head
    margin-bottom 18px

    h4
      margin 0 0 4px

    p
      color var(--text-muted)
      font-size 14px
      line-height 1.5

  .cr-label
    display block
    margin-bottom 6px
    font-size 13px
    font-weight 500
    color var(--text-color)

  // ------------------------------------------------------------- timeline

  .cr-timeline
    padding 16px 20px 18px
    border 1px solid var(--border-color)
    border-radius var(--radius-lg)
    background var(--page-bg)

    > .cr-label
      margin-bottom 4px
      color var(--text-muted)
      font-size 12px
      font-weight 600
      letter-spacing .04em
      text-transform uppercase

  .cr-track,
  .cr-steps
    position relative
    margin 0 14px

  .cr-track
    height 40px

  .cr-gap
    position absolute
    top 2px
    text-align center
    font-size 12px
    color var(--text-muted)
    white-space nowrap

  .cr-line
    position absolute
    top 28px
    left 0
    right 0
    height 4px
    border-radius 2px
    background var(--border-strong)

  .cr-fill
    height 100%
    border-radius 2px
    background var(--primary-color)
    transition width .2s

  .cr-dot
    position absolute
    top 30px
    width 14px
    height 14px
    border-radius 50%
    border 3px solid var(--primary-color)
    background var(--surface-color)
    transform translate(-50%, -50%)
    transition left .2s

    &.end
      border-color var(--danger-text)

  .cr-steps
    height 44px
    margin-top 8px

  .cr-step
    position absolute
    top 0
    display flex
    align-items flex-start
    gap 8px
    transition left .2s

    &.start
      left -14px

    &.remind
      transform translateX(-50%)

    &.end
      right -14px
      flex-direction row-reverse
      text-align right

    i
      display flex
      align-items center
      justify-content center
      flex-shrink 0
      width 28px
      height 28px
      border-radius 50%
      background var(--primary-soft)
      color var(--primary-color)
      font-size 12px

    &.end i
      background var(--danger-bg)
      color var(--danger-text)

    b
      display block
      font-size 13px
      font-weight 600
      color var(--text-strong)
      white-space nowrap

    span
      font-size 12px
      color var(--text-muted)

  .is-invalid
    .cr-fill,
    .cr-gap
      opacity 0

  // ---------------------------------------------------------------- cards

  .cr-cards
    display grid
    grid-template-columns repeat(2, minmax(0, 1fr))
    gap 16px
    margin 20px 0 16px

  .cr-card
    padding 18px 20px
    border 1px solid var(--border-color)
    border-radius var(--radius-lg)
    background var(--surface-color)
    transition border-color .15s

    &.has-err
      border-color var(--danger-text)

  .cr-card-head
    display flex
    align-items center
    gap 10px
    margin-bottom 16px

    h5
      margin 0
      font-size 15px
      font-weight 600
      color var(--text-strong)

  .cr-icon
    display flex
    align-items center
    justify-content center
    flex-shrink 0
    width 34px
    height 34px
    border-radius var(--radius-md)
    background var(--primary-soft)
    color var(--primary-color)

  // .cr-card in front so it outranks base.styl's input rules, scoped or not.
  .cr-card .cr-input
    display flex
    max-width 240px
    border 1px solid var(--border-strong)
    border-radius var(--radius-md)
    background var(--bg-color)
    overflow hidden
    transition border-color .15s, box-shadow .15s

    &:focus-within
      border-color var(--primary-color)
      box-shadow 0 0 0 3px var(--primary-soft)

    input,
    input:focus,
    input:active
      flex 1
      min-width 0
      height 40px
      line-height 38px
      padding 0 12px
      border 0
      border-radius 0
      box-shadow none
      background transparent
      font-size 15px
      font-weight 600

    span
      display flex
      align-items center
      padding 0 14px
      border-left 1px solid var(--border-color)
      background var(--hover-color)
      color var(--text-muted)
      font-size 13px

  .cr-card.has-err .cr-input
    border-color var(--danger-text)

    &:focus-within
      box-shadow 0 0 0 3px var(--danger-bg)

  // FieldError has no styles of its own; each page gives them.
  .cr-card .field-error
    display flex
    align-items flex-start
    gap 6px
    margin-top 8px
    font-size 12.5px
    line-height 1.5
    font-weight 500
    color var(--danger-text)

  .cr-hint
    margin-top 10px
    font-size 12.5px
    line-height 1.5
    color var(--text-muted)

  .cr-note
    display flex
    align-items flex-start
    gap 8px
    margin-bottom 18px
    font-size 13px
    line-height 1.5
    color var(--text-muted)

    i
      margin-top 3px
      color var(--info-text)

  @media only screen and (max-width: 900px)
    .cr-cards
      grid-template-columns minmax(0, 1fr)

  // Too narrow for labels along the line: list the three steps instead.
  @media only screen and (max-width: 600px)
    .cr-gap
      display none

    .cr-track
      height 24px

    .cr-line
      top 12px

    .cr-dot
      top 14px

    .cr-steps
      display flex
      flex-direction column
      gap 10px
      height auto
      margin 12px 0 0

    .cr-step,
    .cr-step.start,
    .cr-step.remind,
    .cr-step.end
      position static
      flex-direction row
      text-align left
      transform none
</style>
