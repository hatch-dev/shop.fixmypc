<template>
  <div
    v-if="$can('profile', 'view')"
    class="tab-sidebar"
  >
    <h5 class="title bold">
      {{ $t('profile.ap') }}
    </h5>

    <div class="dply-felx">
      <ul class="left-area">
        <li v-for="(value, index) in tabs" :key="index"
            :class="{active : value.tabId === activeTab}"
            @click="tabSelect(value)"
        >
          {{ value.title }}
        </li>
      </ul>

      <div class="right-area">
        <form
          v-if="tabId[0] === activeTab"
          class="pos-rel"
          :class="{'has-error': hasError}"
          @submit.prevent="checkForm"
        >
          <error-formatter />

          <div class="input-wrapper">
            <label>
              {{ $t('user.name') }}
            </label>
            <input
              type="text"
              :placeholder="$t('user.name')"
              name="name"
              v-model="adminData.name"
            >
          </div>

          <div class="input-wrapper">
            <label>
              {{ $t('user.uName') }}
            </label>
            <input
              type="text"
              :placeholder="$t('user.uName')"
              name="username"
              v-model.trim="adminData.username"
              :class="{invalid: !adminData.username && hasError}"
            >
            <span
              class="error"
              v-if="!!!adminData.username && hasError"
            >
              {{ $t('category.req', { type: $t('user.uName')}) }}
            </span>
          </div>

          <div class="input-wrapper">
            <label>
              {{ $t('fSale.email') }}
            </label>
            <input
              type="text"
              :placeholder="$t('fSale.email')"
              v-model.trim="adminData.email"
              :class="{invalid: !adminData.email || isInvalidEmail}"
            >
            <span
              class="error"
              v-if="!!!adminData.email && hasError"
            >
              {{ $t('category.req', { type: $t('fSale.email')}) }}
            </span>
            <span
              class="error"
              v-else-if="isInvalidEmail && hasError"
            >
              {{ $t('user.isValid', { type: $t('fSale.email') }) }}
            </span>
          </div>

          <div class="input-wrapper">
            <label>
              {{ $t('user.pass') }}
            </label>
            <password-field
              :value="adminData.password"
              :is-invalid="!isValidLength(adminData.password)"
              @change="adminData.password = $event"
            />
            <span
              class="error"
              v-if="!!!adminData.password && hasError"
            >
             {{ $t('category.req', { type: $t('user.pass')}) }}
            </span>
            <span
              class="error"
              v-else-if="!isValidLength(adminData.password) && hasError"
            >
              {{ $t('user.inPass') }}
            </span>
          </div>

          <div class="oflow-hidden">
            <ajax-button
              v-if="$can('profile', 'edit')"
              class="primary-btn"
              :fetching-data="formSubmitting"
            />
          </div>
        </form>

        <form
          v-if="tabId[1] === activeTab"
          :class="{'has-error': hasError}"
          @submit.prevent="updatePassword"
        >
          <error-formatter />

          <div class="input-wrapper">
            <label>
              {{ $t('user.pass') }}
            </label>
            <password-field
              :value="password.password"
              :is-invalid="!isValidLength(password.password)"
              @change="password.password = $event"
            />
            <span
              class="error"
              v-if="!!!password.password && hasError"
            >
              {{ $t('category.req', { type: $t('user.pass')}) }}
            </span>
            <span
              class="error"
              v-else-if="!isValidLength(password.password) && hasError"
            >
              {{ $t('user.inPass') }}
            </span>
          </div>

          <div class="input-wrapper">
            <label>
              {{ $t('profile.np') }}
            </label>
            <password-field
              :value="password.new_password"
              :is-invalid="!isValidLength(password.new_password)"
              @change="password.new_password = $event"
            />
            <span
              class="error"
              v-if="!!!password.new_password && hasError"
            >
               {{ $t('category.req', { type: $t('profile.np')}) }}
            </span>
            <span
              class="error"
              v-else-if="!isValidLength(password.new_password) && hasError"
            >
              {{ $t('user.inPass') }}
            </span>
          </div>

          <div class="input-wrapper">
            <label>
              {{ $t('user.cPass') }}
            </label>
            <password-field
              :value="password.confirm_password"
              :is-invalid="!isValidLength(password.confirm_password)"
              @change="password.confirm_password = $event"
            />
            <span
              class="error"
              v-if="!!!password.confirm_password && hasError"
            >
               {{ $t('category.req', { type: $t('user.cPass')}) }}
            </span>
            <span
              class="error"
              v-else-if="!isValidLength(password.confirm_password) && hasError"
            >
              {{ $t('user.inPass') }}
            </span>
            <span
              class="error"
              v-else-if="(password.confirm_password !== password.new_password) && hasError"
            >
              {{ $t('user.nMatch') }}
            </span>
          </div>

          <div class="oflow-hidden">
            <ajax-button
              v-if="$can('profile', 'edit')"
              class="primary-btn"
              :fetching-data="formSubmitting"
            />
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from '~/components/AjaxButton'
  import validation from '~/mixin/validation'
  import Spinner from '~/components/Spinner'
  import ErrorFormatter from '~/components/ErrorFormatter'
  import PasswordField from '~/components/PasswordField'

  export default {
    name: "profile",
    middleware: ['common-middleware', 'auth'],
    data() {
      return {
        formSubmitting: false,
        hasError: false,
        adminData:{
          id: null,
          name: null,
          username: null,
          email: null,
          password: ''
        },
        password: {
          password: null,
          confirm_password: null,
          new_password: null,
        },
        tabId: ['profile-update', 'password-update'],
        activeTab: 'profile-update',
        tabs: [
          {
            title: this.$t('profile.up'),
            tabId: 'profile-update',
          },
          {
            title: this.$t('profile.cp'),
            tabId: 'password-update',
          },
        ]
      }
    },
    mixins: [validation],
    components: {
      ErrorFormatter,
      AjaxButton,
      Spinner,
      PasswordField
    },
    computed: {
      isInvalidEmail() {
        return (this.adminData.email && !this.isValidEmail(this.adminData.email))
      },
      ...mapGetters('admin', ['profile'])
    },
    methods:{
      tabSelect(val) {
        if(val.tabId !== this.$route.hash.replace('#','')){
          this.$router.push({
            hash: val.tabId
          })
        }
        this.hasError = false
        this.activeTab = val.tabId
      },
      async checkForm() {
        if (this.adminData.username && this.adminData.email && this.adminData.password) {
          this.formSubmitting = true

          try {
            const data = await this.updateProfile(this.adminData)
            if(data) {
              this.adminData = Object.assign({}, this.profile)
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }

          this.formSubmitting = false
        } else{
          this.hasError = true
        }
      },
      async updatePassword() {
        if ((this.password.password
          && this.password.new_password
          && this.password.confirm_password)
          && (this.password.new_password === this.password.confirm_password))
        {
          this.formSubmitting = true
          const data = await this.setPassword(this.password)
          if(data?.status === 200) {
            await this.$auth.logout()
            window.location.reload()
          }

          this.formSubmitting = false
        } else{
          this.hasError = true
        }
      },
      ...mapActions('ui', ['setErrors']),
      ...mapActions('admin', ['updateProfile', 'setPassword'])
    },
    mounted() {
      this.activeTab = this.$route.hash ? this.$route.hash.replace('#','') : this.tabs[0].tabId
      this.adminData = Object.assign({}, this.profile)
    }
  }
</script>

<style scoped>

</style>
