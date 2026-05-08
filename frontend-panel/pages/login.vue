<template>
  <div>
    <form class="text-start" @submit.prevent="formSubmit">
      <ul v-if="errors.length" class="mb-3 text-danger">
        <li>{{ $t('forgotPassword.errorOccurred') }}</li>
        <li v-for="(value, index) in errors" :key="index">
          {{ value }}
        </li>
      </ul>

      <div class="mb-3">
        <label class="label-heading">
          {{ $t('addressPopup.email') }}
        </label>
        <div class="input-group custom-input mt-2">
          <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
          <input
            type="email"
            v-model.trim="email"
            class="form-control"
            :class="{ 'is-invalid': !emailValid && hasFormError }"
            :placeholder="$t('contact.your', { type: $t('contact.email') })"
          >
        </div>
        <small class="text-danger" v-if="!email && hasFormError">
          {{ $t('addressPopup.isRequired', {type: $t('addressPopup.email')}) }}
        </small>
        <small class="text-danger" v-else-if="invalidEmail && hasFormError">
          {{ $t('contact.invalidEmail') }}
        </small>
      </div>

        <div class="mb-2">
          <label class="label-heading">
            {{ $t('profile.password') }}
          </label>
          <password-field
            :value="password"
            @change="password = $event"
          />
          <small class="text-danger" v-if="!password && hasFormError">
            {{ $t('addressPopup.isRequired', {type: $t('profile.password') }) }}
          </small>

          <small class="text-danger" v-else-if="invalidPassword && hasFormError">
            {{ $t('profile.invalidLength') }}
          </small>
        </div>
        <div class="d-flex justify-content-between mb-3 pt-3">
          <div class="form-check remember-container">
            <input class="remember-checkbox" type="checkbox">
            <label class="form-check-label ms-2">
              Remember me
            </label>
          </div>
          <nuxt-link to="/forgot-password" class="forgot_password">
            {{ $t('login.forgotPassword') }}?
          </nuxt-link>
        </div>
        <ajax-button
          class="btn btn-sign w-100 mb-2"
          :fetching-data="formSubmitting"
          :loading-text="$t('login.loggingIn')"
        >
          Sign In →
        </ajax-button>
    </form>
    <p class="no-account mx-4 mb-3 mt-3">
      {{ $t('forgotPassword.noAccount') }}
      <nuxt-link to="/register" class="forgot_password">
          {{ $t('forgotPassword.createAccount') }}
      </nuxt-link>
    </p>
    <button class="btn w-100 guest-btn" @click="continueAsGuest"><i class="fa-solid fa-user-plus"></i><span class="ms-2">Continue as Guest</span></button>
  </div>
  
  <!-- <form @submit.prevent="formSubmit">
    <ul
      class="error-list mb-15"
      v-if="errors.length"
    >
      <li class="mb-10">
        {{ $t('forgotPassword.errorOccurred') }}
      </li>
      <li
        v-for="(value, index) in errors"
        :key="index"
      >
        {{ value }}
      </li>
    </ul>

    <div
      class="input-wrap"
      :class="{invalid: !emailValid && hasFormError}"
    >
      <label>
        {{ $t('addressPopup.email') }}
      </label>
      <div class="icon-input">
        <i
          class="icon email-icon"
        />
        <input
          type="text"
          :placeholder="$t('contact.your', { type: $t('contact.email') })"
          v-model.trim="email"
        >
      </div>
      <span
        class="error"
        v-if="!email && hasFormError"
      >
        {{ $t('addressPopup.isRequired', {type: $t('addressPopup.email')}) }}
      </span>
      <span
        class="error"
        v-else-if="invalidEmail && hasFormError"
      >
        {{ $t('contact.invalidEmail') }}
      </span>
    </div>

    <div
      class="input-wrap"
      :class="{invalid: !passwordValid && hasFormError}"
    >
      <label>{{ $t('profile.password') }}</label>
      <password-field
        :value="password"
        @change="password = $event"
      />
      <span
        class="error"
        v-if="!password && hasFormError"
      >
        {{ $t('addressPopup.isRequired', {type: $t('profile.password') }) }}
      </span>
      <span
        class="error"
        v-else-if="invalidPassword && hasFormError"
      >
        {{ $t('profile.invalidLength') }}
      </span>
    </div>

    <div class="no-space flex sided">
      <nuxt-link
        to="/forgot-password"
        class="link color-lite"
      >
        {{ $t('login.forgotPassword') }}
      </nuxt-link>

      <ajax-button
        class="primary-btn plr-30 plr-sm-15"
        :fetching-data="formSubmitting"
        :loading-text="$t('login.loggingIn')"
      />
    </div>

    <div class="mt-20 mt-sm-15 mb-10">
      {{ $t('forgotPassword.noAccount') }}
      <nuxt-link
        to="/register"
        class="mlr-10 link bold color-primary"
      >
        {{ $t('forgotPassword.createAccount') }}
      </nuxt-link>
    </div>
  </form> -->
</template>
<script>

  import {mapActions} from 'vuex'
  import validation from '~/mixin/validation'
  import util from '~/mixin/util'
  import AjaxButton from '~/components/AjaxButton'
  import PasswordField from '~/components/PasswordField'
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware', 'non-logged-in'],
    layout: "empty",
    data() {
      return {
        email: '',
        password: '',
        hasFormError: false,
        errors: [],
        formSubmitting: false
      }
    },
    components: {
      AjaxButton,
      PasswordField
    },
    mixins: [validation, util, global],
    computed: {
      invalidEmail() {
        return !this.isValidEmail(this.email)
      },
      emailValid() {
        return this.email && !this.invalidEmail
      },
      invalidPassword() {
        return !this.isValidLength(this.password)
      },
      passwordValid() {
        return this.password && !this.invalidPassword
      }
    },
    methods: {
      continueAsGuest() {
        this.$router.push('/')
      },
      async formSubmit() {
        if (this.email && this.password && !this.invalidPassword) {
          this.formSubmitting = true
          try {

            if(!process.env.apiBase.trim()){
              this.$axios.defaults.baseURL = window.location.origin + '/'
            }

            const {data} = await this.$auth.loginWith('local',
              {
                data: {
                  user_token: await this.getUserToken(),
                  password: this.password,
                  email: this.email
                }
              })

            if (data?.status === 200) {
              this.hasFormError = false
              this.setProfile(data.data?.user)
              this.errors = []
            } else {
              this.errors = data?.data?.form
            }

          } catch (e) {
            return this.$nuxt.error(e)
          }
          this.formSubmitting = false

        } else {
          this.hasFormError = true
        }
      },
      ...mapActions('user', ['setProfile', 'getUserToken']),
    },
    mounted(){
    },
  }
</script>

<style scoped>
label.label-heading {
    font-size: 14px;
    color: #130E2B;
    font-weight: 500;
}

 .btn-sign {
    background: #33319A;
    color: #fff;
    border-radius: 10px;
    padding: 15px 0px;
    font-size: 16px;
    border: 1px solid #33319A;
    font-weight: 500;
}
.btn-sign:hover {
    border-color: #33319A;
}

 .guest-btn {
    background: #F7F7FA;
    border-radius: 8px;
    border: 1px solid #D7D7E0;
    font-size: 16px;
    color: #130E2B;
    font-weight: 400;
}
.guest-btn:hover{
    background-color: #33319A;
    color:#fff;
}

p.no-account {
    font-size: 14px;
    color: #130E2B;
    font-weight: 400;
}

  input.form-control.login-input {
    border: 1px solid #E3E3EF;
    border-radius: 10px;
    padding: 12px 10px;
    margin-top: 10px;
    color: #6B7280;
    font-size: 14px;
}
a.forgot_password {
    text-decoration: none;
    color: #33319A;
    font-size: 14px;
    font-weight: 400;
}

p.an-account.mt-4 {
    color: #6B7280;
    font-weight: 400;
    font-size: 12px;
}

 span.input-group-text {
      background-color: transparent;
      padding: 10px 16px;
    }


.remember-checkbox {
    width: 15px !important;
    height: 15px !important;
    position: relative;
    top: 2px;
}

.remember-container {
    padding-left: 0px;
}
</style>
