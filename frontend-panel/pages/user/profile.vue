<template>
  <account-layout
    class="user-profile-wrapper"
    active-route="profile"
    :class="{'email-login': !loggedInWithEmail}"
  >
    <template v-slot:rightArea>

      <div
        class="spinner-wrapper flex"
        v-if="fetchingProfileData"
      >
        <spinner
          :radius="100"
        />
      </div>

      <div v-else>
        <form @submit.prevent="updateUserProfile">
          <div class="profile-header d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0">Profile Settings</h5>
                <small class="text-muted">Manage your account details and security preferences.</small>
            </div>
            <ajax-button
              class="btn-save-chnages"
              :fetching-data="profileSubmitting"
              text="Save Changes"
            />
          </div>

          <div class="Frequently-card-box p-0">
            <div class="section-header">
                Profile Information
            </div>
            <div class="p-4">
                <div class="row g-4 align-items-center">

                    <!-- Avatar -->
                    <div class="col-md-3 text-center">
                        <img :src="userImage" class="profile-big mb-2">
                        <div>
                          <input type="file" accept="image/*" @change="handleImageUpload" hidden ref="fileInput">
                          <a href="#" class="chnage-avatar" @click.prevent="$refs.fileInput.click()">
                            Change Avatar
                          </a>
                        </div>
                    </div>

                    <!-- Form -->
                    <div class="col-md-9">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label-tittle">First Name</label>
                                <input type="text" class="form-control custom-input" :class="{ 'is-invalid': !firstName && hasProfileError }" v-model="firstName">
                                <span
                                  class="text-danger small"
                                  v-if="!firstName && hasProfileError"
                                >
                                  First name is required
                                </span>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label-tittle">Last Name</label>
                                <input type="text" class="form-control custom-input" v-model="lastName">
                            </div>

                            <div class="col-md-6 position-relative">
                                <label class="form-label-tittle">Email Address</label>
                                <input type="email" class="form-control custom-input pe-5"
                                  :value="email" disabled>
                                <span class="input-icon-password"><i class="fa-solid fa-lock"
                                        style="color: rgb(0, 0, 0);"></i></span>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label-tittle">Phone Number</label>
                                <input type="text" class="form-control custom-input" :class="{ 'is-invalid': !phone && hasProfileError }" v-model="phone">
                                <span
                                  class="text-danger small"
                                  v-if="!phone && hasProfileError"
                                >
                                  Phone is required
                                </span>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
          </div>
        </form>

         <!----Security & Password -->
        <div class="Frequently-card-box p-0 mt-4">

            <!-- Header -->
            <div class="section-header d-flex justify-content-between align-items-center">
                <span>Security & Password</span>
                <p class="text-muted d-flex align-items-center gap-1">
                    <i class="fa-solid fa-shield-halved me-2" style="color: rgb(0, 0, 0);"></i> Account is
                    secure
                </p>
            </div>

            <div class="p-4">
                <div class="row g-4">

                    <!-- LEFT: Password Form -->
                    <div class="col-md-6">

                      <form @submit.prevent="updatePassword">
                        <div class="mb-3">
                          <label class="form-label-tittle">Current Password</label>
                          <password-field
                            :value="currentPassword"
                            @change="currentPassword = $event"
                          />
                          <span
                            class="text-danger small"
                            v-if="!currentPassword && hasPasswordError"
                          >
                            Current password is required
                          </span>
                        </div>

                        <div class="mb-3">
                          <label class="form-label-tittle">New Password</label>
                          <password-field
                            :value="newPassword"
                            @change="newPassword = $event"
                          />
                          <span
                            class="text-danger small"
                            v-if="!newPassword && hasPasswordError"
                          >
                            Password is required
                          </span>
                          <span
                            class="text-danger small"
                            v-else-if="invalidPassword && hasPasswordError"
                          >
                            Password must be valid length
                          </span>
                        </div>

                        <div class="mb-3">
                          <label class="form-label-tittle">Confirm New Password</label>
                          <password-field
                            :value="confirmPassword"
                            @change="confirmPassword = $event"
                          />
                          <span
                            class="text-danger small"
                            v-if="!confirmPassword && hasPasswordError"
                          >
                            Confirm password required
                          </span>

                          <span
                            class="text-danger small"
                            v-else-if="confirmPassword !== newPassword && hasPasswordError"
                          >
                            Passwords do not match
                          </span>
                        </div>

                        <ajax-button
                          class="btn btn-primary"
                          :fetching-data="passwordSubmitting"
                          loading-text="Update Password"
                          text="Update Password"
                        />
                      </form>

                    </div>

                    <!-- RIGHT: 2FA -->
                    <div class="col-md-6">
                        <div class="twofa-box">

                            <h6 class="mb-2">Two-Factor Authentication</h6>
                            <p class="small text-muted mb-3">
                                Add an extra layer of security to your account by enabling two-factor
                                authentication (2FA).
                            </p>

                            <div class="twofa-item d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle me-3"><i class="fa-solid fa-mobile-screen"
                                            style="color: rgb(0, 0, 0);"></i></div>
                                    <div>
                                        <strong>SMS Authentication</strong><br>
                                        <span class="small text-muted">Not enabled</span>
                                    </div>
                                </div>
                                <button class="btn btn-light btn-sm">Enable</button>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

        </div>

        <!-----Active Session-->
        <div class="container py-4 mt-3">

            <!-- ACTIVE SESSIONS -->
            <div class="Frequently-card-box ">
                <div class="section-header d-flex justify-content-between">
                    <span>Active Sessions</span>
                    <small><i class="fa-solid fa-shield-halved me-2" style="color: rgb(0, 0, 0);"></i> Account
                        is secure</small>
                </div>

                <div class="p-4">

                    <p class="small text-muted">These are the devices that are currently logged into your
                        account.</p>

                    <div class="session-item mb-3">
                        <div class="d-flex align-items-center">
                            <div class="me-4"><i class="fa-solid fa-desktop fs-2"
                                    style="color: rgb(107, 114, 128);"></i></div>
                            <div>
                                <strong>MacBook Pro (This Device)</strong><br>
                                <small><span class="text-primary">Active now</span> • Dublin • Chrome</small>
                            </div>
                        </div>
                    </div>

                    <div class="session-item mb-3 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="me-4"><i class="fa-solid fa-mobile-screen fs-2"
                                    style="color: rgb(107, 114, 128);"></i></div>
                            <div>
                                <strong>iPhone 15</strong><br>
                                <small>2 hours ago • Safari</small>
                            </div>
                        </div>
                        <span class="text-danger small">Sign Out</span>
                    </div>

                    <div class="session-item d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="me-4"><i class="fa-solid fa-laptop fs-2"
                                    style="color: rgb(107, 114, 128);"></i></div>
                            <div>
                                <strong>Windows PC</strong><br>
                                <small>3 days ago • Edge</small>
                            </div>
                        </div>
                        <span class="text-danger small">Sign Out</span>
                    </div>

                </div>
            </div>

        </div>

        <!-----------------Preferences -->
        <div class="Frequently-card-box p-0 mb-4">

            <div class="section-header">
                Preferences
            </div>

            <div class="p-4">
                <div class="row g-4">

                    <!-- Marketing Emails -->
                    <div class="col-md-6 d-flex align-items-start">
                        <input class="form-check-input pref-check me-3" type="checkbox">
                        <div>
                            <strong>Marketing Emails</strong><br>
                            <span class="small text-muted">
                                Receive emails about new products, features, and discount codes.
                            </span>
                        </div>
                    </div>

                    <!-- Order Notifications -->
                    <div class="col-md-6 d-flex align-items-start">
                        <input class="form-check-input pref-check me-3" type="checkbox">
                        <div>
                            <strong>Order Notifications</strong><br>
                            <span class="small text-muted">
                                Receive SMS or email updates about your order status.
                            </span>
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <!-- --------------------DELETE ACCOUNT ----------------->
        <div class="delete-box d-flex justify-content-between align-items-center">
            <div>
                <strong class="text-danger">Delete Account</strong><br>
                <span class="small text-muted">
                    Once you delete your account, there is no going back. Please be certain.
                </span>
            </div>
            <ajax-button
              class="btn-del-account"
              type="button"
              :fetching-data="deletingAccount"
              @clicked="deleteAccount"
              text="Delete Account"
            />
        </div>
      </div>
    </template>
  </account-layout>
</template>

<script>

  import util from '~/mixin/util'
  import validation from '~/mixin/validation'
  import AccountLayout from '~/components/AccountLayout'
  import Spinner from '~/components/Spinner'
  import {mapGetters, mapActions} from 'vuex'
  import AjaxButton from "~/components/AjaxButton";
  import PasswordField from "~/components/PasswordField";
  import global from '~/mixin/global'

  export default {
    middleware: ['common-middleware', 'auth'],
    head() {
      return {
        title: 'Profile',
        meta: []
      }
    },
    data() {
      return {
        image: null,
        selectedImageFile: null,
        name: '',
        email: '',
        phone: '',
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
        hasProfileError: false,
        deletingAccount: false,
        hasPasswordError: false,
        profileSubmitting: false,
        passwordSubmitting: false,
        fetchingProfileData: false
      }
    },
    components: {
      PasswordField,
      AjaxButton,
      AccountLayout,
      Spinner
    },

    watch: {
      profile(value) {
        if (this.profile) {
          this.email = value?.email
          this.name = value?.name
          this.phone = value?.phone
          this.image = value?.image
        }
      },
    },
    mixins: [util, validation, global],
    computed: {
      userImage() {
        if (this?.image) {
          const path = this.image
          if ( path.startsWith('http://') || path.startsWith('https://') || path.startsWith('blob:')) {
            return path
          }
          return this.getImageURL(this.image)
        }

        return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(this?.name || 'Guest User') + '&background=random&color=fff&size=150'
      },
      firstName: {
        get() {
          return this.name?.split(' ')[0] || ''
        },
        set(val) {
          this.name = val + ' ' + (this.lastName || '')
        }
      },

      lastName: {
        get() {
          return this.name?.split(' ')[1] || ''
        },
        set(val) {
          this.name = (this.firstName || '') + ' ' + val
        }
      },
      loggedInWithGoogle() {
        return this.profile && this.profile?.google_id
      },
      loggedInWithFacebook() {
        return this.profile && this.profile?.facebook_id
      },
      loggedInWithEmail() {
        return this.profile && !this.profile?.facebook_id && !this.profile?.google_id
      },
      loggedInWith() {
        if (this.profile) {
          if (this.loggedInWithGoogle) {
            return this.$t('profile.google')
          } else if (this.loggedInWithFacebook) {
            return this.$t('profile.facebook')
          } else {
            return this.$t('addressPopup.email')
          }
        }
      },
      invalidPassword() {
        return !this.isValidLength(this.newPassword)
      },
      passwordValid() {
        return this.newPassword && !this.invalidPassword
      },
      ...mapGetters('user', ['profile'])
    },
    methods: {
      handleImageUpload(e) {
        const file = e.target.files[0]
        if (!file) return

        this.selectedImageFile = file
        this.image = URL.createObjectURL(file)
      },
      async deleteAccount() {

        if (confirm(this.$t('cartProductTile.deleteAlert'))) {
          this.deletingAccount = true

          const data = await this.deleteRequest({
            api: 'deleteAccount',
            requiredToken: true,
            lang: this.langCode,
          })
          this.deletingAccount = false


          if(data?.status === 200){
            this.setToastMessage(data.message)
            this.$auth.logout()
          }else {
            this.setToastError(data.data.form.join(', '))
          }
          this.deletingAccount = 0
        }

      },

      async updatePassword() {

        if (this.currentPassword && this.newPassword && (this.newPassword === this.confirmPassword)) {
          this.passwordSubmitting = true
          const data = await this.updateUserPassword({
            current_password: this.currentPassword,
            new_password: this.newPassword
          })
          if (data?.status === 201) {
            this.setToastError(data.data.form.join(', '))
          } else if (data?.status === 200) {
            this.loggingOut()
            this.setToastMessage(data.message)
          }
          this.passwordSubmitting = false
        } else {
          this.hasPasswordError = true
        }
      },
      async loggingOut() {
        try {
          this.$auth.logout()
          this.emptyCartProduct()
        } catch (e) {
          return this.$nuxt.error(e)
        }
      },
      async updateUserProfile() {
        if (this.name) {
          this.profileSubmitting = true
          const formData = new FormData()
          formData.append('name', this.name)
          formData.append('phone', this.phone)
          if (this.selectedImageFile) {
            formData.append('image', this.selectedImageFile)
          }
          const data = await this.updateProfile(formData)
          this.profileSubmitting = false
          if (data?.status === 201) {
            this.setToastError(data.data.form.join(', '))

          } else if (data?.status === 200) {
            const updatedUser = {...this.$auth.user}
            updatedUser.name = data.data.name
            updatedUser.phone = data.data.phone 
            updatedUser.image = data.data.image 
            this.$auth.setUser(updatedUser)
            this.setToastMessage(data.message)
          } else if (data?.status !== 200) {
            this.hasError(data)
          }
        } else {
          this.hasProfileError = true
        }
      },
      ...mapActions('cart', ['emptyCartProduct']),
      ...mapActions('common', ['setToastMessage', 'setToastError', 'deleteRequest']),
      ...mapActions('user', ['updateProfile', 'updateUserPassword'])
    },
    async mounted() {
      if (this.profile) {
        this.email = this.profile?.email
        this.name = this.profile?.name
        this.phone = this.profile?.phone
      }
    },
  }
</script>
<style scoped>
.section-header {
    background: #F3F3FA;
    padding: 12px 20px;
    border-top-left-radius: 15px;
    border-top-right-radius: 15px;
    font-weight: 400;
    font-size: 18px;
    color: #130E2B;
}
.Frequently-card-box {
    background-color: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
}
button.btn-save-chnages {
    background-color: #33319A;
    color: #fff;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 400;
    padding: 0px 10px;
    border: none;
}
button.btn-save-chnages:hover {
    background-color: #05B942;
    color: #fff;
}
/* Big avatar */
.profile-big {
    width: 150px;
    border-radius: 50%;
    object-fit: cover;
}
a.chnage-avatar {
    font-size: 14px;
    color: #33319A;
    font-weight: 500;
    text-decoration: none;
}

/* Inputs */
input.custom-input {
    border: 1px solid #E3E3EF;
    padding: 13px 15px;
    font-size: 14px;
    color: #130E2B;
    font-weight: 400;
}
input.custom-input:focus  {
    outline: none !important;
      box-shadow: none !important;
}

/* Lock icon inside input */
.input-icon-password {
    position: absolute;
    right: 23px;
    top: 42px;
    font-size: 14px;
    color: #000000;
}

/* Labels */
.form-label-tittle {
    font-size: 14px;
    color: #130E2B;
    margin-bottom: 8px;
    font-weight: 500;
}

.security-item {
    background: #F3F5FC;
    padding: 12px 15px;
    border-radius: 10px;
    border: 1px solid #E3E3EF;
}
button.shipping-update {
    border: 1px solid #E3E3EF;
    border-radius: 4px;
    background-color: #fff;
    font-weight: 400;
    font-size: 12px;
    color: #171717;
    padding: 5px 10px;
}
button.shipping-update:hover {
    background-color:#05B942;
    color: #fff;
  
}
p.shipping-address {
    font-size: 14px;
    font-weight: 400;
    color: #130E2B;
}
span.user-mob {
    color: #6B7280;
}
a.update-password {
    color: #33319A;
    font-weight: 500;
    font-size: 14px;
    text-decoration: none;
}

.twofa-box {
    border: 1px solid #E3E3EF;
    background-color: #F7F7FA;
    border-radius: 14px;
    padding: 20px 20px 60px 20px;
}
.twofa-item {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 10px;
    padding: 12px 15px;
}
.session-item {
    background: #fff;
    border: 1px solid #E3E3EF;
    border-radius: 14px;
    padding: 20px 20px;
    cursor: pointer;
    transition: 0.2s;
}

.session-item.active-session {
    border: 1.5px solid #4b49ac;
    background: #f5f6ff;
}

/* Buttons */
.btn-primary {
    background: #4b49ac;
    border: none;
}
/*-------------------*/
/*--------Preferences-----------*/
.pref-check {
    width: 16px;
    height: 16px;
    accent-color: #4b49ac;
    cursor: pointer;
}
input.pref-check {
    border-color: #33319A;
    width: 20px;
    height: 20px;
}
input.pref-check:focus{
     outline: none !important;
      box-shadow: none !important;
}
/*-------------------------*/
/* Delete box */
.delete-box {
    background: #FDEAEA;
    border: 1px solid #D32F2F4D;
    border-radius: 14px;
    padding: 21px 20px;
}


button.btn-del-account {
    border: 1px solid #D32F2F4D;
    background-color: #fff;
    border-radius: 8px;
    padding: 0px 10px 0px 10px;
    color: #D32F2F;
    font-weight: 400;
    font-size:12px;
}
button.btn-del-account:hover {
    background-color:#05B942;
    color: #fff;
}

</style>
