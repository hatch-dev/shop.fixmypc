<template>
  <setting-layout
    active-route="aftership"
    class="mb-5"
  >
    <template v-slot:rightArea>
      <div class="as-page">

        <div class="as-head">
          <h4>{{ $t('setting.asTitle') }}</h4>
          <p>{{ $t('setting.asSub') }}</p>
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
          <div class="as-cards">

            <!-- API key -->
            <div
              class="as-card"
              :class="{'has-err': !!fieldError('api_key')}"
            >
              <div class="as-card-head">
                <span class="as-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
                <h5>{{ $t('setting.asKeyTitle') }}</h5>
              </div>

              <p
                class="as-state"
                :class="stateClass(info.api_key, form.clear_api_key)"
              >{{ stateText(info.api_key, form.clear_api_key) }}</p>

              <label
                class="as-label"
                for="as-api-key"
              >{{ info.api_key.set ? $t('setting.asKeyReplace') : $t('setting.asKeyEnter') }}</label>
              <div class="as-input">
                <input
                  id="as-api-key"
                  :type="showKey ? 'text' : 'password'"
                  v-model.trim="form.api_key"
                  autocomplete="off"
                  spellcheck="false"
                  :placeholder="info.api_key.set ? $t('setting.asKeepPlaceholder') : 'asat_...'"
                  :aria-invalid="!!fieldError('api_key')"
                  aria-describedby="as-api-key-hint"
                  @input="form.clear_api_key = false"
                >
                <button
                  type="button"
                  class="as-eye"
                  :aria-label="showKey ? $t('setting.asHide') : $t('setting.asShow')"
                  @click="showKey = !showKey"
                >
                  <i :class="showKey ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" aria-hidden="true"></i>
                </button>
              </div>
              <field-error :messages="fieldError('api_key')" />
              <p
                id="as-api-key-hint"
                class="as-hint"
              >{{ $t('setting.asKeyHint') }}</p>
              <button
                v-if="info.api_key.source === 'admin'"
                type="button"
                class="as-link"
                @click="useEnv('api_key')"
              >{{ form.clear_api_key ? $t('setting.asUndo') : $t('setting.asUseEnv') }}</button>
            </div>

            <!-- Webhook -->
            <div
              class="as-card"
              :class="{'has-err': !!fieldError('webhook_secret')}"
            >
              <div class="as-card-head">
                <span class="as-icon"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i></span>
                <h5>{{ $t('setting.asHookTitle') }}</h5>
              </div>

              <label
                class="as-label"
                for="as-webhook-url"
              >{{ $t('setting.asHookUrl') }}</label>
              <div class="as-input">
                <input
                  id="as-webhook-url"
                  type="text"
                  :value="info.webhook_url"
                  readonly
                  @focus="$event.target.select()"
                >
                <button
                  type="button"
                  class="as-eye"
                  :aria-label="$t('setting.asCopy')"
                  @click="copyUrl"
                >
                  <i :class="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'" aria-hidden="true"></i>
                </button>
              </div>

              <p
                class="as-state mt-15"
                :class="stateClass(info.webhook_secret, form.clear_webhook_secret)"
              >{{ stateText(info.webhook_secret, form.clear_webhook_secret) }}</p>

              <label
                class="as-label"
                for="as-webhook-secret"
              >{{ info.webhook_secret.set ? $t('setting.asSecretReplace') : $t('setting.asSecretEnter') }}</label>
              <div class="as-input">
                <input
                  id="as-webhook-secret"
                  :type="showSecret ? 'text' : 'password'"
                  v-model.trim="form.webhook_secret"
                  autocomplete="off"
                  spellcheck="false"
                  :placeholder="info.webhook_secret.set ? $t('setting.asKeepPlaceholder') : ''"
                  :aria-invalid="!!fieldError('webhook_secret')"
                  aria-describedby="as-webhook-hint"
                  @input="form.clear_webhook_secret = false"
                >
                <button
                  type="button"
                  class="as-eye"
                  :aria-label="showSecret ? $t('setting.asHide') : $t('setting.asShow')"
                  @click="showSecret = !showSecret"
                >
                  <i :class="showSecret ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" aria-hidden="true"></i>
                </button>
              </div>
              <field-error :messages="fieldError('webhook_secret')" />
              <p
                id="as-webhook-hint"
                class="as-hint"
              >{{ $t('setting.asHookHint') }}</p>
              <button
                v-if="info.webhook_secret.source === 'admin'"
                type="button"
                class="as-link"
                @click="useEnv('webhook_secret')"
              >{{ form.clear_webhook_secret ? $t('setting.asUndo') : $t('setting.asUseEnv') }}</button>
            </div>

            <!-- Delivery + checks -->
            <div
              class="as-card as-wide"
              :class="{'has-err': !!syncError}"
            >
              <div class="as-card-head">
                <span class="as-icon"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i></span>
                <h5>{{ $t('setting.asBehaviourTitle') }}</h5>
              </div>

              <div class="as-row">
                <div class="as-col">
                  <label class="as-switch">
                    <input
                      type="checkbox"
                      v-model="form.auto_deliver"
                    >
                    <span class="as-track" aria-hidden="true"></span>
                    <span class="as-switch-text">{{ $t('setting.asAutoDeliver') }}</span>
                  </label>
                  <p class="as-hint">{{ $t('setting.asAutoDeliverHint') }}</p>
                </div>

                <div class="as-col">
                  <label
                    class="as-label"
                    for="as-sync-minutes"
                  >{{ $t('setting.asSync') }}</label>
                  <div class="as-input as-num">
                    <input
                      id="as-sync-minutes"
                      type="number"
                      :min="info.min_sync_minutes"
                      :max="info.max_sync_minutes"
                      step="1"
                      inputmode="numeric"
                      v-model="form.sync_minutes"
                      :placeholder="$t('setting.asSyncAuto', {n: info.sync_minutes_auto})"
                      :aria-invalid="!!syncError"
                      aria-describedby="as-sync-hint"
                    >
                    <span>{{ $t('setting.cartMinutes') }}</span>
                  </div>
                  <field-error :messages="syncError" />
                  <p
                    id="as-sync-hint"
                    class="as-hint"
                  >{{ $t('setting.asSyncHint', {min: info.min_sync_minutes, max: info.max_sync_minutes}) }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Test connection -->
          <div class="as-test">
            <div class="as-test-head">
              <div>
                <b>{{ $t('setting.asTestTitle') }}</b>
                <span>{{ $t('setting.asTestSub') }}</span>
              </div>
              <button
                type="button"
                class="as-test-btn"
                :disabled="testing || !info.api_key.set"
                @click="testConnection"
              >
                <i
                  :class="testing ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-plug'"
                  aria-hidden="true"
                ></i>
                {{ testing ? $t('setting.asTesting') : $t('setting.asTest') }}
              </button>
            </div>

            <div
              v-if="test"
              class="as-test-result"
              :class="test.ok ? 'is-ok' : 'is-bad'"
              role="status"
            >
              <template v-if="test.ok">
                <p>
                  <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                  {{ $tc('setting.asTestOk', test.couriers.length, {count: test.couriers.length}) }}
                </p>
                <ul
                  v-if="test.couriers.length"
                  class="as-chips"
                >
                  <li
                    v-for="c in test.couriers"
                    :key="c.slug"
                  >{{ c.name }}<small v-if="repeatedName(c.name)"> · {{ c.slug }}</small></li>
                </ul>
                <p class="as-hint">{{ $t('setting.asCouriersHint') }}</p>
              </template>
              <p v-else>
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                {{ test.message }}
              </p>
            </div>
          </div>

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
  import {mapActions} from 'vuex'
  import SettingLayout from "~/components/partials/SettingLayout";
  import AjaxButton from "~/components/AjaxButton";
  import FieldError from "~/components/FieldError";
  import Spinner from "~/components/Spinner";
  import util from "~/mixin/util";

  const EMPTY_SECRET = {set: false, source: null, hint: null}

  /**
   * Settings > AfterShip. The API key and webhook secret are never sent to
   * the browser: the server answers whether each is set, where it comes from
   * (this page or the server .env) and its last four characters. A blank box
   * keeps what is saved.
   */
  export default {
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        info: {
          api_key: {...EMPTY_SECRET},
          webhook_secret: {...EMPTY_SECRET},
          auto_deliver: true,
          sync_minutes: null,
          sync_minutes_auto: 60,
          min_sync_minutes: 15,
          max_sync_minutes: 1440,
          webhook_url: ''
        },
        form: this.blankForm(),
        errors: {},
        showKey: false,
        showSecret: false,
        copied: false,
        loading: false,
        saving: false,
        testing: false,
        test: null
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
      syncError() {
        const raw = this.form.sync_minutes
        if (raw === '' || raw === null || raw === undefined) {
          return this.fieldError('sync_minutes')
        }
        const n = Number(raw)
        if (!Number.isInteger(n) || n < this.info.min_sync_minutes || n > this.info.max_sync_minutes) {
          return [this.$t('setting.asSyncErr', {min: this.info.min_sync_minutes, max: this.info.max_sync_minutes})]
        }
        return this.fieldError('sync_minutes')
      }
    },
    methods: {
      blankForm(info) {
        return {
          api_key: '',
          clear_api_key: false,
          webhook_secret: '',
          clear_webhook_secret: false,
          auto_deliver: info ? !!info.auto_deliver : true,
          sync_minutes: info && info.sync_minutes ? String(info.sync_minutes) : ''
        }
      },
      fill(info) {
        this.info = {...this.info, ...info}
        this.form = this.blankForm(info)
      },
      fieldError(name) {
        const list = this.errors && this.errors[name]
        return list && list.length ? list : null
      },
      stateText(secret, clearing) {
        if (clearing) {
          return this.$t('setting.asStateClearing')
        }
        if (!secret.set) {
          return this.$t('setting.asStateNone')
        }
        return this.$t(secret.source === 'admin' ? 'setting.asStateAdmin' : 'setting.asStateEnv', {hint: secret.hint})
      },
      stateClass(secret, clearing) {
        return clearing ? 'is-warn' : (secret.set ? 'is-ok' : 'is-missing')
      },
      useEnv(field) {
        const flag = 'clear_' + field
        this.form[flag] = !this.form[flag]
        if (this.form[flag]) {
          this.form[field] = ''
        }
      },
      async copyUrl() {
        try {
          await navigator.clipboard.writeText(this.info.webhook_url)
          this.copied = true
          setTimeout(() => { this.copied = false }, 1500)
        } catch (e) {
          document.getElementById('as-webhook-url').select()
        }
      },
      async save() {
        this.errors = {}
        if (this.syncError) {
          document.getElementById('as-sync-minutes').focus()
          return
        }

        this.saving = true
        try {
          const params = {
            ...this.form,
            auto_deliver: this.form.auto_deliver ? 1 : 0,
            clear_api_key: this.form.clear_api_key ? 1 : 0,
            clear_webhook_secret: this.form.clear_webhook_secret ? 1 : 0,
            sync_minutes: this.form.sync_minutes === '' ? null : Number(this.form.sync_minutes)
          }
          const data = await this.setRequest({params, api: 'setAftership'})

          // A save answers with the new description; a refusal with the 201 body.
          if (data && data.api_key && 'set' in data.api_key) {
            this.fill(data)
            this.test = null
          } else if (data && data.data && data.data.fields) {
            // Validation::validationResponse names the field each message belongs to.
            this.errors = data.data.fields
          }
        } catch (e) {
          return this.$nuxt.error(e)
        }
        this.saving = false
      },
      // FedEx, DHL and others list several services under one name: the slug tells them apart.
      repeatedName(name) {
        return this.test.couriers.filter(c => c.name === name).length > 1
      },
      async testConnection() {
        this.testing = true
        this.test = null
        try {
          const data = await this.setRequest({params: {}, api: 'testAftership'})
          if (data && data.ok) {
            this.test = {ok: true, couriers: data.couriers || []}
          } else {
            this.test = {ok: false, message: (data && data.message) || this.$t('setting.asTestFailed')}
          }
        } catch (e) {
          this.test = {ok: false, message: (e && e.message) || this.$t('setting.asTestFailed')}
        }
        this.testing = false
      },
      ...mapActions('common', ['getRequest', 'setRequest'])
    },
    async mounted() {
      this.loading = true
      try {
        this.fill(await this.getRequest({params: {}, api: 'getAftership'}))
      } catch (e) {
        return this.$nuxt.error(e)
      }
      this.loading = false
    }
  }
</script>

<style lang="stylus" scoped>
  .as-page
    max-width 920px
  .as-head
    margin-bottom 18px
    h4
      margin 0 0 4px
    p
      color var(--text-muted)
      font-size 14px
      line-height 1.5
  .as-label
    display block
    margin-bottom 6px
    font-size 13px
    font-weight 500
    color var(--text-color)
  .as-cards
    display grid
    grid-template-columns repeat(2, minmax(0, 1fr))
    gap 16px
    margin-bottom 16px
  .as-card
    padding 18px 20px
    border 1px solid var(--border-color)
    border-radius var(--radius-lg)
    background var(--surface-color)
    transition border-color .15s
    &.has-err
      border-color var(--danger-text)
    &.as-wide
      grid-column 1 / -1
  .as-card-head
    display flex
    align-items center
    gap 10px
    margin-bottom 14px
    h5
      margin 0
      font-size 15px
      font-weight 600
      color var(--text-strong)
  .as-icon
    display flex
    align-items center
    justify-content center
    flex-shrink 0
    width 34px
    height 34px
    border-radius var(--radius-md)
    background var(--primary-soft)
    color var(--primary-color)
  .as-state
    display inline-flex
    align-items center
    gap 6px
    margin 0 0 12px
    padding 3px 10px
    border-radius 999px
    font-size 12.5px
    font-weight 600
    &.is-ok
      background var(--success-bg, #e7f6ec)
      color var(--success-text, #157f3b)
    &.is-missing
      background var(--danger-bg)
      color var(--danger-text)
    &.is-warn
      background var(--warning-bg, #fff4e0)
      color var(--warning-text, #a15c00)
  // .as-card in front so it outranks base.styl's input and button rules.
  .as-card .as-input
    display flex
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
      font-size 14px
    input[readonly]
      color var(--text-muted)
      font-family monospace
      font-size 13px
    > span
      display flex
      align-items center
      padding 0 14px
      border-left 1px solid var(--border-color)
      background var(--hover-color)
      color var(--text-muted)
      font-size 13px
    &.as-num
      max-width 260px
  .as-card .as-input .as-eye
    display flex
    align-items center
    justify-content center
    width 42px
    height 40px
    line-height 40px
    padding 0
    border 0
    border-left 1px solid var(--border-color)
    border-radius 0
    background var(--hover-color) !important
    color var(--text-muted) !important
    &:hover
      color var(--primary-color) !important
  .as-card.has-err .as-input
    border-color var(--danger-text)
  .as-card .field-error
    display flex
    align-items flex-start
    gap 6px
    margin-top 8px
    font-size 12.5px
    line-height 1.5
    font-weight 500
    color var(--danger-text)
  .as-hint
    margin-top 8px
    font-size 12.5px
    line-height 1.5
    color var(--text-muted)
  .as-card .as-link
    height auto
    line-height 1.4
    margin-top 6px
    padding 0
    border 0
    background none !important
    color var(--primary-color) !important
    font-size 12.5px
    font-weight 600
    text-decoration underline
  .as-row
    display grid
    grid-template-columns repeat(2, minmax(0, 1fr))
    gap 24px
  .as-switch
    display inline-flex
    align-items center
    gap 10px
    cursor pointer
    input
      position absolute
      opacity 0
      width 1px
      height 1px
    .as-track
      position relative
      flex-shrink 0
      width 40px
      height 22px
      border-radius 999px
      background var(--border-strong)
      transition background .15s
      &::after
        content ''
        position absolute
        top 3px
        left 3px
        width 16px
        height 16px
        border-radius 50%
        background #fff
        transition transform .15s
    & input:checked + .as-track
      background var(--primary-color)
      &::after
        transform translateX(18px)
    & input:focus-visible + .as-track
      box-shadow 0 0 0 3px var(--primary-soft)
    .as-switch-text
      font-size 14px
      font-weight 600
      color var(--text-strong)
  .as-test
    margin-bottom 20px
    padding 16px 20px
    border 1px solid var(--border-color)
    border-radius var(--radius-lg)
    background var(--page-bg)
  .as-test-head
    display flex
    align-items center
    justify-content space-between
    gap 16px
    b
      display block
      font-size 14px
      color var(--text-strong)
    span
      font-size 12.5px
      color var(--text-muted)
  .as-test .as-test-btn
    display inline-flex
    align-items center
    gap 8px
    flex-shrink 0
    height 38px
    line-height 36px
    padding 0 16px
    border 1px solid var(--primary-color)
    border-radius var(--radius-md)
    background var(--surface-color) !important
    color var(--primary-color) !important
    font-size 13px
    font-weight 600
    &:disabled
      opacity .5
      cursor not-allowed
  .as-test-result
    margin-top 14px
    padding-top 14px
    border-top 1px solid var(--border-color)
    p
      display flex
      align-items flex-start
      gap 8px
      margin 0
      font-size 13.5px
      font-weight 500
    &.is-ok p:first-child
      color var(--success-text, #157f3b)
    &.is-bad p
      color var(--danger-text)
  .as-chips
    display flex
    flex-wrap wrap
    gap 6px
    margin 10px 0 0
    padding 0
    list-style none
    li
      padding 3px 10px
      border 1px solid var(--border-color)
      border-radius 999px
      background var(--surface-color)
      font-size 12.5px
  @media only screen and (max-width: 900px)
    .as-cards,
    .as-row
      grid-template-columns minmax(0, 1fr)
    .as-test-head
      flex-direction column
      align-items flex-start
</style>
