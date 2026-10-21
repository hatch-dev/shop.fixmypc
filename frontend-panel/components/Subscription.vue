<template>
  <div class="row align-items-center subscription-panel">
    <div class="col-lg-6">
      <h4 class="subscription-text">{{ $t('ui.subscription.title') }}</h4>
      <p class="small text-light subscription-description">
        {{ $t('ui.subscription.description') }}
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
            <span v-if="formSubmitting">{{ $t('ui.subscription.loading') }}</span>
            <span v-else>
              {{ $t('ui.subscription.subscribe') }} <i class="bi bi-arrow-right"></i>
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
              // The monthly letter, which is a different consent from the
              // clearance page's 'notify me about deals' box.
              params: {email: this.email, source: 'newsletter'},
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
/* newsletter call to action, sat on the footer's indigo */
.subscription-panel {
  background: rgba(255, 255, 255, .06);
  border: 1px solid rgba(255, 255, 255, .12);
  border-radius: 18px;
  margin: 0 0 8px;
  padding: 26px 24px;
}

.subscription-text {
  color: #fff;
  font-size: 24px;
  font-weight: 700;
  letter-spacing: -.01em;
  line-height: 1.25;
  margin-bottom: 8px;
}

.subscription-description {
  color: rgba(255, 255, 255, .7) !important;
  font-size: 13.5px;
  font-weight: 400;
  line-height: 1.6;
  margin: 0;
  max-width: 60ch;
}

/* one continuous pill: field on the left, action on the right */
.newsletter-box {
  align-items: center;
  background: rgba(0, 0, 0, .22);
  border: 1px solid rgba(255, 255, 255, .18);
  border-radius: 999px;
  padding: 6px;
  transition: border-color .15s ease;
}

.newsletter-box:focus-within {
  border-color: rgba(255, 255, 255, .45);
}

.newsletter-box > div {
  flex-wrap: nowrap !important;
  gap: 6px !important;
}

.newsletter-box input {
  background: transparent;
  border: none;
  color: #fff;
  flex: 1 1 auto;
  font-size: 14px;
  height: 42px;
  line-height: 42px;
  min-width: 0;
  padding: 0 16px;
}

.newsletter-box input:focus {
  background: transparent;
  border: none;
  box-shadow: none;
  color: #fff;
  outline: none;
}

.newsletter-box input::placeholder {
  color: rgba(255, 255, 255, .55);
}

.newsletter-box button {
  background: #05B942;
  border: none;
  border-radius: 999px;
  color: #fff;
  flex: 0 0 auto;
  font-size: 14px;
  font-weight: 700;
  height: 42px;
  line-height: 42px;
  padding: 0 22px;
  transition: background .15s ease;
  white-space: nowrap;
}

.newsletter-box button:hover:not(:disabled) {
  background: #049937;
}

.newsletter-box button:disabled {
  opacity: .65;
}

@media (max-width: 991px) {
  .newsletter-box {
    margin-top: 18px;
  }
}

@media (max-width: 767px) {
  .subscription-panel {
    padding: 20px 16px;
  }

  .subscription-text {
    font-size: 20px;
  }

  /* the pill only works while field and button share a line. Once they stack,
     the wrapper becomes a tall stadium box around them - so dissolve it and
     let each control be a full width pill of its own. */
  .newsletter-box {
    background: none;
    border: 0;
    border-radius: 0;
    padding: 0;
  }

  .newsletter-box:focus-within {
    border: 0;
  }

  .newsletter-box > div {
    flex-wrap: wrap !important;
    gap: 10px !important;
  }

  .newsletter-box input {
    background: rgba(0, 0, 0, .22);
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 999px;
    flex: 1 1 100%;
    height: 48px;
    line-height: 48px;
    padding: 0 18px;
    width: 100%;
  }

  .newsletter-box input:focus {
    border-color: rgba(255, 255, 255, .45);
  }

  .newsletter-box button {
    flex: 1 1 100%;
    height: 48px;
    line-height: 48px;
    width: 100%;
  }
}
</style>

