<template>
  <div class="row align-items-center mb-4">
    <div class="col-lg-6">
      <h4 class="subscription-text">Free Monthly Newsletter</h4>
      <p class="small text-light subscription-description">
        Subscribe to our newsletter for all the latest news and cool tips and tricks to keep your mobile devices safe & secure. We promise we never spam, and you can unsubscribe easily.
      </p>
    </div>
    <div class="col-lg-6">
      <form class="newsletter-box"  @submit.prevent="formSubmit">
        <div class="d-flex gap-2 flex-wrap">
          <input
            type="email"
            v-model="email"
            :placeholder="$t('contact.your', { type: $t('contact.email') })"
            class="form-control"
          />

          <button
            type="submit"
            class="btn btn-primary"
            :disabled="formSubmitting"
          >
            <span v-if="formSubmitting">Loading...</span>
            <span v-else>
              Subscribe <i class="bi bi-arrow-right"></i>
            </span>
          </button>
        </div>
      </form>
      <div v-if="messageSent" class="alert alert-success d-flex align-items-center">
          <i class="bi bi-check-circle me-2"></i>
          <span>{{ $t('home.subscribeSuccessMsg') }}</span>
        </div>
      <div v-if="hasFormError" class="mt-2">
        <span
          class="text-danger d-block"
          v-for="(value, index) in errors"
          :key="index"
        >
          {{ value }}
        </span>

        <span class="text-danger d-block" v-if="!email">
          {{ $t('addressPopup.isRequired', { type: $t('addressPopup.email') }) }}
        </span>

        <span class="text-danger d-block" v-else-if="invalidEmail">
          {{ $t('contact.invalidEmail') }}
        </span>
      </div>
    </div>
  </div>
</template>

<script>
  import validation from '~/mixin/validation'
  import { mapActions } from 'vuex'
  export default {
    name: 'Subscription',
    data() {
      return {
        errors: [],
        formSubmitting: false,
        email: '',
        hasFormError: false,
        messageSent: false
      }
    },
    components: {

    },
    props: {
    },
    mixins: [validation],
    computed: {
      invalidEmail() {
        return !this.isValidEmail(this.email)
      },
    },
    methods:{
      async formSubmit() {
        if (this.email && !this.invalidEmail) {
          this.formSubmitting = true
          try {
            const data = await this.postRequest({
              params: {email: this.email},
              api: 'emailSubscription'
            })

            if (data?.status === 200) {
              this.messageSent = true
              this.hasFormError = false
            } else {
              this.hasFormError = true
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
      ...mapActions('common', ['postRequest'])
    },
  }
</script>

<style scoped>
.footer-section {
  background: #3b33a5;
  color: #fff;
  padding-top: 50px;
}

.newsletter-box {
  background: rgba(0,0,0,0.15);
  border-radius: 50px;
  padding: 8px;
  align-items: center;
}

.newsletter-box input {
  border: none;
  background: transparent;
  color: #fff;
  padding: 10px 15px;
  flex: 1;
}

.newsletter-box input::placeholder {
  color: #ddd;
}

.newsletter-box button {
  background: #22c55e;
  border: none;
  border-radius: 30px;
  padding: 0px 20px;
  color: #fff;
}

.subscription-text {
    font-size: 36px;
    font-weight: 700;
    color: #fff;
}

.subscription-description {
    font-size: 14px;
    font-weight: 400;
    color: #C7C7EC !important;
    line-height: 1.4em;
}

@media (max-width: 767px) {
  .subscription-text {
      font-size: 25px !important;
  }

  .newsletter-box{
    margin-top: 20px;
  }
}
</style>

